<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Hq;
use Brigada\Hq\Jobs\DeliveryFailed;
use Brigada\Hq\Jobs\SendBatch;
use Brigada\Hq\Transport;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HqTest extends TestCase
{
    private function reportAndFlush(\Throwable $e): void
    {
        $this->app->make(Hq::class)->report($e);
        $this->app->terminate();
    }

    /** @return array<string, mixed> */
    private function sentBatch(int $n = 0): array
    {
        $recorded = Http::recorded()->values()->all();
        $this->assertNotEmpty($recorded, 'nothing was sent');

        return ($n < 0 ? end($recorded) : $recorded[$n])[0]->data();
    }

    public function test_payload_matches_protocol_v1(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->app->setBasePath(dirname(__DIR__));

        $this->reportAndFlush($this->boom('it broke'));

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $r) {
            return $r->url() === 'https://hq.test/api/ingest/v1/events'
                && $r->hasHeader('Authorization', 'Bearer '.TestCase::KEY)
                && str_starts_with($r->header('User-Agent')[0], 'brigada-hq-sdk/')
                && $r->method() === 'POST';
        });

        $batch = $this->sentBatch();
        $this->assertSame(['name' => 'brigada/hq-sdk', 'version' => Hq::VERSION], $batch['sdk']);
        $this->assertCount(1, $batch['events']);
        $ev = $batch['events'][0];
        $this->assertSame('error', $ev['type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $ev['timestamp']);
        $this->assertSame('production', $ev['environment']);
        $this->assertSame('abc1234', $ev['release']);
        $this->assertSame('php', $ev['runtime']);
        $this->assertSame(PHP_VERSION, $ev['runtimeVersion']);
        $this->assertSame('laravel', $ev['framework']);
        $this->assertSame($this->app->version(), $ev['frameworkVersion']);
        $this->assertSame(gethostname(), $ev['server']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ev['traceId']);
        $this->assertSame('RuntimeException', $ev['error']['class']);
        $this->assertSame('it broke', $ev['error']['message']);
        $this->assertFalse($ev['error']['handled']);
        foreach (array_keys($ev) as $k) {
            $this->assertStringNotContainsString('_', $k, 'camelCase keys only');
        }

        // innermost first: the throw site, then its caller, relative paths, inApp for our code and not for vendor/
        $frames = $ev['error']['frames'];
        $this->assertSame('tests/TestCase.php', $frames[0]['file']);
        $this->assertSame('Brigada\Hq\Tests\TestCase::thrower', $frames[0]['function']);
        $this->assertTrue($frames[0]['inApp']);
        $this->assertSame('Brigada\Hq\Tests\TestCase::boom', $frames[1]['function']);
        $this->assertSame('tests/TestCase.php', $frames[1]['file']);
        $inVendor = array_values(array_filter($frames, fn ($f) => str_starts_with($f['file'] ?? '', 'vendor/')));
        $this->assertNotEmpty($inVendor);
        $this->assertFalse($inVendor[0]['inApp']);
        $this->assertArrayNotHasKey('request', $ev, 'console: no request');
    }

    public function test_request_user_route_and_query_string(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->actAsWeb();
        Route::get('orders/{order}', fn () => 'x');
        $request = Request::create('https://shop.test/orders/42?token=abc&email=a@b.co', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => 'req-123']);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'secret@person.test', 'name' => 'Sam Secret']));

        $this->reportAndFlush(new \RuntimeException('boom'));

        $ev = $this->sentBatch()['events'][0];
        $this->assertSame(['method' => 'GET', 'url' => 'https://shop.test/orders/42', 'route' => 'orders/{order}', 'status' => 500], $ev['request']);
        $this->assertSame(['id' => 7], $ev['user']);
        $this->assertSame('req-123', $ev['traceId']);
        $json = json_encode($ev);
        $this->assertStringNotContainsString('secret@person.test', $json);
        $this->assertStringNotContainsString('Sam Secret', $json);
        $this->assertStringNotContainsString('token=abc', $json);
    }

    public function test_http_exception_status_5xx_is_reported_with_its_status(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->actAsWeb();
        $this->app->instance('request', Request::create('https://shop.test/x', 'POST'));

        $this->reportAndFlush(new HttpException(503, 'down'));

        $this->assertSame(503, $this->sentBatch()['events'][0]['request']['status']);
    }

    public function test_redaction(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $msg = 'Failed for jane.doe@example.com with Authorization: Bearer abc.def.ghi password=hunter2 api_key: "k-123" at '.base_path('app/Foo.php').' https://x.test/p?secret=1#frag';

        $this->reportAndFlush(new \RuntimeException($msg));

        $m = $this->sentBatch()['events'][0]['error']['message'];
        $this->assertStringNotContainsString('jane.doe@example.com', $m);
        $this->assertStringNotContainsString('abc.def.ghi', $m);
        $this->assertStringNotContainsString('hunter2', $m);
        $this->assertStringNotContainsString('k-123', $m);
        $this->assertStringNotContainsString('?secret', $m);
        $this->assertStringNotContainsString(base_path(), $m);
        $this->assertStringContainsString('[redacted]', $m);
        $this->assertStringContainsString('app/Foo.php', $m);
        $this->assertStringContainsString('https://x.test/p', $m);
    }

    public function test_ignored_exceptions_are_not_sent(): void
    {
        Http::fake();
        $hq = $this->app->make(Hq::class);
        foreach ([
            new NotFoundHttpException(),
            new HttpException(419, 'expired'),
            ValidationException::withMessages(['a' => 'b']),
            new AuthenticationException(),
            new \Illuminate\Auth\Access\AuthorizationException(),
            new \Illuminate\Session\TokenMismatchException(),
            new ModelNotFoundException(),
        ] as $e) {
            $hq->report($e);
        }
        $this->app->terminate();
        Http::assertNothingSent();
    }

    public function test_disabled_or_without_key_sends_nothing(): void
    {
        Http::fake();
        config(['hq.enabled' => false]);
        $this->reportAndFlush(new \RuntimeException('a'));
        config(['hq.enabled' => true, 'hq.key' => null]);
        $this->reportAndFlush(new \RuntimeException('b'));
        Http::assertNothingSent();
    }

    public function test_same_exception_within_five_minutes_is_counted_then_rides_as_repeats(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $send = function () {
            // one throw site, so class, file and line are equal
            try {
                throw new \RuntimeException('same');
            } catch (\Throwable $e) {
                return $e;
            }
        };
        $hq = $this->app->make(Hq::class);
        $this->assertSame(0, $hq->dedupe($send()));
        $this->assertFalse($hq->dedupe($send()));
        $this->assertFalse($hq->dedupe($send()));
        // the window passes
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(0, $hq->dedupe($send()));

        \Illuminate\Support\Facades\Cache::flush();
        $hq->report($send());
        $hq->report($send());
        $hq->report($send());
        $this->app->terminate();
        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('tags', $this->sentBatch()['events'][0]);
        // expire only the seen key: the repeat count (2) rides on the next send
        $e = $send();
        \Illuminate\Support\Facades\Cache::forget('hq:seen:'.sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine()));
        $hq->report($send());
        $this->app->terminate();
        Http::assertSentCount(3); // terminate() re-runs earlier callbacks in a test; the new one is the last
        $this->assertSame(['repeats' => '2'], $this->sentBatch(-1)['events'][0]['tags']);
    }

    public function test_queue_mode_dispatches_a_job_and_sync_mode_sends_after_the_response(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        Queue::fake();
        config(['hq.queue' => true]);
        $this->app->make(Hq::class)->report(new \RuntimeException('queued'));
        Queue::assertPushed(SendBatch::class, fn ($job) => $job->events[0]['error']['message'] === 'queued' && $job->tries === 3 && $job->backoff === [5, 30]);
        Http::assertNothingSent();

        config(['hq.queue' => false]);
        $this->app->make(Hq::class)->report(new \RuntimeException('sync'));
        Http::assertNothingSent(); // not before the response is done
        $this->app->terminate();
        Http::assertSentCount(1);
        Queue::assertPushedTimes(SendBatch::class, 1);
    }

    public function test_handler_reportable_hook_reports_through_the_exception_handler(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('via handler'));
        $this->app->terminate();
        $this->assertSame('via handler', $this->sentBatch()['events'][0]['error']['message']);
    }

    public function test_job_succeeds_on_202(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        (new SendBatch([['type' => 'heartbeat']]))->handle($this->app->make(Transport::class));
        Http::assertSentCount(1);
    }

    public function test_job_401_stops_without_retrying_and_warns_once(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 401)]);
        $log = \Illuminate\Support\Facades\Log::spy();
        $job = new SendBatch([['type' => 'heartbeat']]);
        $job->handle($this->app->make(Transport::class));
        $job->handle($this->app->make(Transport::class));
        Http::assertSentCount(2);
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_job_429_releases_with_retry_after(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '12'])]);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(12);
        $job = new SendBatch([['type' => 'heartbeat']]);
        $job->setJob($queueJob);
        $job->handle($this->app->make(Transport::class));
        $this->addToAssertionCount(1);
    }

    public function test_job_500_throws_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $job = new SendBatch([['type' => 'heartbeat']]);
        $this->expectException(DeliveryFailed::class);
        $job->handle($this->app->make(Transport::class));
    }

    public function test_delivery_failure_is_never_reported_back(): void
    {
        Http::fake();
        $this->assertTrue($this->app->make(Hq::class)->ignored(new DeliveryFailed('x')));
    }

    public function test_never_throws_when_hq_is_down(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $this->reportAndFlush(new \RuntimeException('hq down'));
        $delivery = $this->app->make(Transport::class)->send([['type' => 'heartbeat']]);
        $this->assertSame(0, $delivery->status);
        $this->assertTrue($delivery->retryable());
    }

    public function test_never_throws_when_building_the_event_fails(): void
    {
        Http::fake();
        config(['hq.queue' => true]);
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('queue is down'));
        $this->app->make(Hq::class)->report(new \RuntimeException('x'));
        $this->addToAssertionCount(1);
    }

    public function test_heartbeat_payload_and_command(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->artisan('hq:heartbeat')->expectsOutput('Heartbeat sent.')->assertExitCode(0);
        $ev = $this->sentBatch()['events'][0];
        $this->assertSame('heartbeat', $ev['type']);
        $this->assertSame('php', $ev['runtime']);
        $this->assertSame('production', $ev['environment']);
        $this->assertArrayNotHasKey('error', $ev);
    }

    public function test_test_command_reports_synchronously(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->artisan('hq:test')->expectsOutput('Sent, check the Errors tab.')->assertExitCode(0);
        $this->assertStringContainsString('Brigada HQ test exception', $this->sentBatch()['events'][0]['error']['message']);
    }

    public function test_test_command_fails_on_a_refused_key(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $this->artisan('hq:test')->assertExitCode(1);
    }

    public function test_install_checks_the_key_format(): void
    {
        config(['hq.key' => 'bhq_pk_'.str_repeat('a', 43)]);
        $this->artisan('hq:install')->assertExitCode(1);
        config(['hq.key' => 'bhq_sk_'.str_repeat('a', 43)]);
        $this->artisan('hq:install')->expectsOutput('Key format looks right.')->assertExitCode(0);
    }

    public function test_heartbeat_is_scheduled_every_five_minutes(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = array_filter($schedule->events(), fn ($e) => str_contains($e->command, 'hq:heartbeat'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', array_values($events)[0]->expression);
    }
}
