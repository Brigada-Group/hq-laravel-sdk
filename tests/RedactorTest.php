<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Hq;
use Brigada\Hq\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The base path (base_path(), often a short '/app' in Docker, nixpacks or Railway) is stripped from messages and function names, but
 * only where it starts a path: never inside a URL or a longer word, and never from the request URL.
 */
class RedactorTest extends TestCase
{
    public function test_the_base_path_is_stripped_only_at_a_path_boundary(): void
    {
        $this->assertSame('https://shop.test/app/settings', Redactor::text('https://shop.test/app/settings', '/app'));
        $this->assertSame('https://shop.test/approvals/5', Redactor::text('https://shop.test/approvals/5', '/app'));
        $this->assertSame('No query results for /application', Redactor::text('No query results for /application', '/app'));
        $this->assertSame('copy of my.app/x', Redactor::text('copy of my.app/x', '/app'));
    }

    public function test_the_base_path_is_still_stripped_where_it_starts_a_path(): void
    {
        $this->assertSame('at app/Foo.php:12', Redactor::text('at /app/app/Foo.php:12', '/app'));
        $this->assertSame('file_get_contents(storage/x)', Redactor::text('file_get_contents(/app/storage/x)', '/app'));
        // PHP 8.4 closure names: ':' is deliberately not a boundary, so these are still stripped
        $this->assertSame('{closure:routes/web.php:12}', Redactor::text('{closure:/app/routes/web.php:12}', '/app'));
        $this->assertSame('Directory  is not writable', Redactor::text('Directory /app is not writable', '/app'));
        $this->assertSame('in releases/x.php', Redactor::text('in /srv/site/releases/20261119/releases/x.php', '/srv/site/releases/20261119'));
    }

    /** => is one separator: [:=] alone took its '=' and masked only the '>', so the value went out (final review H1). */
    public function test_key_arrow_value_is_redacted_as_print_r_and_var_export_write_it(): void
    {
        $printR = print_r(['password' => 'hunter2', 'api_key' => 'sk_live_9', 'user' => 'ana'], true);
        $this->assertStringContainsString('[password] => hunter2', $printR);
        $out = Redactor::text($printR, '');
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('sk_live_9', $out);
        $this->assertStringContainsString('[password] => [redacted]', $out);
        $this->assertStringContainsString('[user] => ana', $out);

        $export = var_export(['token' => 'TOK', 'secret' => 'S2'], true);
        $this->assertSame("array (\n  'token' => [redacted],\n  'secret' => [redacted],\n)", Redactor::text($export, ''));

        // a Node Map's inspect, as the JS SDK's twin test has it
        $this->assertSame("Map(2) { 'token' => [redacted], 'user' => 'ana' }", Redactor::text("Map(2) { 'token' => 'TOKSECRET', 'user' => 'ana' }", ''));
        $this->assertSame('token=>[redacted] password =>  [redacted]', Redactor::text('token=>abc password =>  hunter2', ''));
    }

    public function test_a_value_already_redacted_stays_as_it_is(): void
    {
        $this->assertSame('where `remember_token` = [redacted] limit 1', Redactor::text('where `remember_token` = [redacted] limit 1', ''));
        $this->assertSame('{"token":[redacted],"x":1}', Redactor::text('{"token":[redacted],"x":1}', ''));
    }

    public function test_credentials_in_a_connection_string_are_masked(): void
    {
        $this->assertSame('connect failed: postgres://[redacted]@db:5432/app', Redactor::text('connect failed: postgres://app:hunter2pw@db:5432/app', ''));
        $this->assertSame('redis://[redacted]@cache:6379', Redactor::text('redis://:pw@cache:6379', ''));
        $this->assertSame('mysql://[redacted]@mysql/app and amqp://[redacted]@rabbitmq:5672', Redactor::text('mysql://root:s3cret@mysql/app and amqp://guest:guestpw@rabbitmq:5672', ''));
        $this->assertSame('clone https://[redacted]@github.com/x failed', Redactor::text('clone https://u:pass@github.com/x failed', ''));
        $this->assertSame('postgres://[redacted]@db.internal.example.com/app', Redactor::text('postgres://app:password@db.internal.example.com/app', ''));
        $this->assertSame('GET https://a.test/p/u@x failed', Redactor::text('GET https://a.test/p/u@x failed', ''));
    }

    public function test_the_request_url_is_never_base_stripped(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->actAsWeb();
        $this->app['config']->set('hq.dedupe_minutes', 0);
        $this->app->setBasePath('/app');
        Route::get('app/settings', fn () => 'x');
        $request = Request::create('https://shop.test/app/settings', 'GET');
        $route = Route::getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $this->app->make(Hq::class)->report(new \RuntimeException('boom'));
        $this->app->terminate();

        $recorded = Http::recorded()->values()->all();
        $this->assertSame('https://shop.test/app/settings', end($recorded)[0]->data()['events'][0]['request']['url']);
    }
}
