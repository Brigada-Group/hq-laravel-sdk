<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Support\ConfigCheck;
use Illuminate\Support\Facades\Http;

/**
 * From 0.3.0 a published config/hq.php is used (before, it was ignored whenever BRIGADA_HQ_KEY was set). A file published on
 * 0.1.0/0.1.1 turns queued sending on, and with no worker every error waits unsent. hq:test and hq:install say so; exit codes do not
 * change.
 */
class ConfigCheckTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__.'/fixtures/'.$name;
    }

    public function test_hq_test_warns_when_errors_go_through_a_queue(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        config(['hq.queue' => true, 'queue.default' => 'database']);

        $this->artisan('hq:test')->expectsOutputToContain('BRIGADA_HQ_QUEUE')->assertSuccessful();
    }

    public function test_hq_test_warns_on_failure_too(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        config(['hq.queue' => true, 'queue.default' => 'redis']);

        $this->artisan('hq:test')->expectsOutputToContain('BRIGADA_HQ_QUEUE')->assertFailed();
    }

    public function test_no_queue_warning_with_the_sync_driver_or_the_queue_off(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        config(['hq.queue' => true, 'queue.default' => 'sync']);
        $this->artisan('hq:test')->doesntExpectOutputToContain('BRIGADA_HQ_QUEUE')->assertSuccessful();

        config(['hq.queue' => false, 'queue.default' => 'database']);
        $this->artisan('hq:test')->doesntExpectOutputToContain('BRIGADA_HQ_QUEUE')->assertSuccessful();
    }

    public function test_a_file_published_by_0_1_1_is_recognised(): void
    {
        config(['hq.queue' => false]);

        $this->assertStringContainsString('--force', implode("\n", ConfigCheck::warnings($this->fixture('hq-0.1.1.php'))));
        $this->assertSame([], ConfigCheck::warnings($this->fixture('hq-0.2.0.php')));
        $this->assertSame([], ConfigCheck::warnings($this->fixture('does-not-exist.php')));
    }

    /**
     * Final review M2: hq:test and hq:install are the only places that warned, and an upgrade runs neither. The first error queued under
     * a config/hq.php published by 0.1.0/0.1.1 (queue on by its old default) logs one warning, once a day.
     *
     * @param  callable(): void  $body
     */
    private function withPublishedConfig(string $fixture, callable $body): void
    {
        $path = config_path('hq.php');
        $had = is_file($path);
        $before = $had ? file_get_contents($path) : null;
        $flag = new \ReflectionProperty(\Brigada\Hq\Hq::class, 'queueUpgradeChecked');
        try {
            copy($this->fixture($fixture), $path);
            $flag->setValue(null, false);
            $body();
        } finally {
            $flag->setValue(null, false);
            $had ? file_put_contents($path, $before) : @unlink($path);
        }
    }

    public function test_the_first_error_queued_under_a_0_1_x_config_logs_one_warning(): void
    {
        $this->withPublishedConfig('hq-0.1.1.php', function (): void {
            \Illuminate\Support\Facades\Queue::fake();
            $log = \Illuminate\Support\Facades\Log::spy();
            config(['hq.queue' => true, 'queue.default' => 'database']);
            $hq = $this->app->make(\Brigada\Hq\Hq::class);

            $hq->report(new \RuntimeException('first'));
            $hq->report(new \LogicException('second'));

            \Illuminate\Support\Facades\Queue::assertPushed(\Brigada\Hq\Jobs\SendBatch::class, 2); // still queued as configured
            $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'BRIGADA_HQ_QUEUE=false') && str_contains($m, '--force') && str_contains($m, 'database'));
        });
    }

    public function test_the_warning_is_once_a_day_across_processes(): void
    {
        $this->withPublishedConfig('hq-0.1.1.php', function (): void {
            \Illuminate\Support\Facades\Queue::fake();
            $log = \Illuminate\Support\Facades\Log::spy();
            config(['hq.queue' => true, 'queue.default' => 'redis']);
            $hq = $this->app->make(\Brigada\Hq\Hq::class);
            $hq->report(new \RuntimeException('first'));
            // another PHP process (a new request): the per-process flag is gone, the cache still remembers
            (new \ReflectionProperty(\Brigada\Hq\Hq::class, 'queueUpgradeChecked'))->setValue(null, false);
            $hq->report(new \LogicException('second'));
            $log->shouldHaveReceived('warning')->once();
        });
    }

    public function test_no_runtime_warning_for_a_current_config_the_sync_driver_or_the_queue_off(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $log = \Illuminate\Support\Facades\Log::spy();
        foreach ([['hq-0.2.0.php', true, 'database'], ['hq-0.1.1.php', true, 'sync'], ['hq-0.1.1.php', false, 'database']] as $i => [$fixture, $queue, $connection]) {
            $this->withPublishedConfig($fixture, function () use ($queue, $connection, $i): void {
                config(['hq.queue' => $queue, 'queue.default' => $connection, 'hq.dedupe_minutes' => 0]);
                $this->app->make(\Brigada\Hq\Hq::class)->report(new \RuntimeException('x'.$i));
            });
        }
        $log->shouldNotHaveReceived('warning');
    }

    public function test_hq_install_keeps_an_existing_config_and_says_so(): void
    {
        $path = config_path('hq.php');
        $had = is_file($path);
        $before = $had ? file_get_contents($path) : null;
        try {
            copy($this->fixture('hq-0.1.1.php'), $path);

            $this->artisan('hq:install')
                ->expectsOutputToContain('kept your existing config/hq.php')
                ->expectsOutputToContain('--force');

            $this->assertSame(file_get_contents($this->fixture('hq-0.1.1.php')), file_get_contents($path));
        } finally {
            $had ? file_put_contents($path, $before) : @unlink($path);
        }
    }
}
