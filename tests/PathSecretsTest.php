<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Hq;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The URL is sent without its query string but with its path, and a path can hold a secret as a route parameter: a password
 * reset token, an invite or join code, an e-mail verification hash. Those parameters are masked in the sent URL; the route
 * pattern is sent as it is. When masking fails, the URL is left out rather than sent.
 */
class PathSecretsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['accepted' => 1, 'dropped' => 0], 202)]);
        $this->actAsWeb();
        $this->app['config']->set('hq.dedupe_minutes', 0);
    }

    /** Reports an error during $url, routed through the routes registered so far, and returns the request part of the event. */
    private function requestPartFor(string $url, ?RoutingRoute $route = null): array
    {
        $request = Request::create($url, 'GET');
        $route ??= Route::getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $this->app->make(Hq::class)->report(new \RuntimeException('boom '.$url));
        $this->app->terminate();

        $recorded = Http::recorded()->values()->all();
        $this->assertNotEmpty($recorded, 'nothing was sent');

        return end($recorded)[0]->data()['events'][0]['request'];
    }

    public function test_a_password_reset_token_in_the_path_is_masked_and_the_route_kept(): void
    {
        Route::get('password/reset/{token}', fn () => 'x');

        $req = $this->requestPartFor('https://shop.test/password/reset/RESET0123456789');

        $this->assertSame('https://shop.test/password/reset/[redacted]', $req['url']);
        $this->assertSame('password/reset/{token}', $req['route']);
        $this->assertSame('GET', $req['method']);
        $this->assertSame(500, $req['status']);
    }

    public function test_only_the_secret_parameters_are_masked(): void
    {
        Route::get('email/verify/{id}/{hash}', fn () => 'x');
        Route::get('schools/{school}/join/{joinCode}', fn () => 'x');
        Route::get('orders/{order}', fn () => 'x');

        $this->assertSame('https://shop.test/email/verify/7/[redacted]', $this->requestPartFor('https://shop.test/email/verify/7/HASH99abc')['url']);
        $this->assertSame('https://shop.test/schools/12/join/[redacted]', $this->requestPartFor('https://shop.test/schools/12/join/JOIN77')['url']);
        $this->assertSame('https://shop.test/orders/42', $this->requestPartFor('https://shop.test/orders/42')['url']);
    }

    public function test_a_parameter_bound_by_a_secret_field_is_masked(): void
    {
        // Laravel drops the binding field from the URI: uri() is invite/{invitation}, and only bindingFieldFor() still says "token".
        Route::get('invite/{invitation:token}', fn () => 'x');
        Route::get('posts/{post:slug}', fn () => 'x');

        $this->assertSame('https://shop.test/invite/[redacted]', $this->requestPartFor('https://shop.test/invite/TOK123')['url']);
        $this->assertSame('https://shop.test/posts/hello-world', $this->requestPartFor('https://shop.test/posts/hello-world')['url']);
    }

    public function test_each_default_secret_name_is_masked_whatever_its_case(): void
    {
        foreach (['token', 'code', 'hash', 'signature', 'secret', 'key', 'password'] as $i => $name) {
            Route::get("d{$i}/{".strtoupper($name).'}', fn () => 'x');

            $req = $this->requestPartFor("https://shop.test/d{$i}/VALUE{$i}xyz");

            $this->assertSame("https://shop.test/d{$i}/[redacted]", $req['url'], $name);
        }
    }

    public function test_an_optional_secret_parameter_is_masked_when_present_and_absent_when_not(): void
    {
        Route::get('invite/{code?}', fn () => 'x');

        $this->assertSame('https://shop.test/invite/[redacted]', $this->requestPartFor('https://shop.test/invite/INVITE55')['url']);
        $this->assertSame('https://shop.test/invite', $this->requestPartFor('https://shop.test/invite')['url']);
    }

    public function test_a_secret_parameter_that_spans_slashes_is_masked_whole(): void
    {
        Route::get('share/{token}/{rest}', fn () => 'x')->where('token', '.*');

        $req = $this->requestPartFor('https://shop.test/share/abc/def/tail');

        $this->assertStringNotContainsString('abc', $req['url']);
        $this->assertStringNotContainsString('def', $req['url']);
        $this->assertSame('https://shop.test/share/[redacted]/tail', $req['url']);
    }

    public function test_the_list_of_secret_names_is_configurable(): void
    {
        $this->app['config']->set('hq.secret_route_parameters', ['order']);
        Route::get('orders/{order}', fn () => 'x');
        Route::get('password/reset/{token}', fn () => 'x');

        $this->assertSame('https://shop.test/orders/[redacted]', $this->requestPartFor('https://shop.test/orders/42')['url']);
        $this->assertSame('https://shop.test/password/reset/RESET1', $this->requestPartFor('https://shop.test/password/reset/RESET1')['url']);
    }

    public function test_a_published_config_without_the_list_still_masks_the_defaults(): void
    {
        $this->app['config']->set('hq.secret_route_parameters', null);
        Route::get('password/reset/{token}', fn () => 'x');

        $this->assertSame('https://shop.test/password/reset/[redacted]', $this->requestPartFor('https://shop.test/password/reset/RESET2')['url']);
    }

    public function test_a_secret_in_the_domain_leaves_the_url_out(): void
    {
        Route::domain('{key}.shop.test')->get('dashboard/{token}', fn () => 'x');

        $req = $this->requestPartFor('https://TENANTKEY9.shop.test/dashboard/TOKEN4');

        $this->assertArrayNotHasKey('url', $req);
        $this->assertSame('dashboard/{token}', $req['route']);
    }

    public function test_when_masking_fails_the_url_is_left_out_and_the_rest_is_sent(): void
    {
        $route = new class(['GET'], 'password/reset/{token}/{user}', fn () => 'x') extends RoutingRoute
        {
            public function originalParameter($name, $default = null)
            {
                throw new \RuntimeException('no parameters here');
            }
        };

        $req = $this->requestPartFor('https://shop.test/password/reset/RESET3/9', $route);

        $this->assertArrayNotHasKey('url', $req);
        $this->assertSame('password/reset/{token}/{user}', $req['route']);
        $this->assertSame('GET', $req['method']);
        $this->assertStringNotContainsString('RESET3', json_encode(Http::recorded()->values()->all()[0][0]->data()['events'][0]['request']));
    }

    /** Reports an error during $url before routing ran: the request has no route resolver, as in a global middleware. */
    private function requestPartBeforeRouting(string $url): array
    {
        $request = Request::create($url, 'GET');
        $this->app->instance('request', $request);

        $this->app->make(Hq::class)->report(new \RuntimeException('boom before routing'));
        $this->app->terminate();

        $recorded = Http::recorded()->values()->all();
        $this->assertNotEmpty($recorded, 'nothing was sent');

        return end($recorded)[0]->data()['events'][0]['request'];
    }

    public function test_a_fallback_route_masks_the_whole_path(): void
    {
        Route::fallback(fn () => 'spa');

        $req = $this->requestPartFor('https://shop.test/reset-password/TOK123abc');

        $this->assertSame('https://shop.test/[redacted]', $req['url']);
        $this->assertSame('{fallbackPlaceholder}', $req['route']);
        $this->assertStringNotContainsString('TOK123abc', (string) json_encode($req));
    }

    public function test_a_catch_all_shell_masks_the_whole_path(): void
    {
        Route::get('{any}', fn () => 'spa')->where('any', '.*');

        $req = $this->requestPartFor('https://shop.test/invite/INV777/accept');

        $this->assertSame('https://shop.test/[redacted]', $req['url']);
        $this->assertStringNotContainsString('INV777', (string) json_encode($req));
    }

    public function test_a_constrained_single_segment_parameter_is_kept(): void
    {
        Route::get('orders/{order}', fn () => 'x')->where('order', '[0-9]+');

        $this->assertSame('https://shop.test/orders/42', $this->requestPartFor('https://shop.test/orders/42')['url']);
    }

    public function test_a_global_pattern_that_spans_is_masked(): void
    {
        Route::pattern('path', '.*');
        Route::get('files/{path}', fn () => 'x');

        $req = $this->requestPartFor('https://shop.test/files/a/SECRETFILE.pdf');

        $this->assertSame('https://shop.test/files/[redacted]', $req['url']);
        $this->assertStringNotContainsString('SECRETFILE', (string) json_encode($req));
    }

    public function test_an_error_before_routing_masks_against_the_matching_route(): void
    {
        Route::get('password/reset/{token}', fn () => 'x');

        $req = $this->requestPartBeforeRouting('https://shop.test/password/reset/PREROUTE1');

        $this->assertSame('https://shop.test/password/reset/[redacted]', $req['url']);
        $this->assertArrayNotHasKey('route', $req);
        $this->assertStringNotContainsString('PREROUTE1', (string) json_encode($req));
    }

    public function test_an_error_before_routing_on_an_unknown_path_sends_the_root(): void
    {
        Route::get('known', fn () => 'x');

        $req = $this->requestPartBeforeRouting('https://shop.test/reset-password/PREROUTE2');

        $this->assertSame('https://shop.test', $req['url']);
        $this->assertStringNotContainsString('PREROUTE2', (string) json_encode($req));
    }

    public function test_a_route_match_that_throws_sends_the_root(): void
    {
        Route::setRoutes(new class extends \Illuminate\Routing\RouteCollection
        {
            public function match(Request $request)
            {
                throw new \RuntimeException('the router is broken');
            }
        });

        $req = $this->requestPartBeforeRouting('https://shop.test/password/reset/PREROUTE3');

        $this->assertSame('https://shop.test', $req['url']);
        $this->assertSame('GET', $req['method']);
        $this->assertStringNotContainsString('PREROUTE3', (string) json_encode($req));
    }

    public function test_a_parameter_whose_value_holds_a_slash_is_masked_whole(): void
    {
        // a where() the probe does not catch (upper case only), whose value still holds a "/"
        Route::get('files/{name}', fn () => 'x')->where('name', '[A-Z]+/[A-Z]+');

        $req = $this->requestPartFor('https://shop.test/files/AB/SECRETNAME');

        $this->assertSame('https://shop.test/files/[redacted]', $req['url']);
        $this->assertStringNotContainsString('SECRETNAME', (string) json_encode($req));
    }

    public function test_a_fallback_route_masks_a_single_segment_too(): void
    {
        Route::fallback(fn () => 'spa');

        $req = $this->requestPartFor('https://shop.test/SINGLETOK9');

        $this->assertSame('https://shop.test/[redacted]', $req['url']);
    }
}
