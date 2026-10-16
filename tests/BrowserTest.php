<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Facades\Hq as HqFacade;
use Brigada\Hq\Hq;
use Illuminate\Support\Facades\Blade;

class BrowserTest extends TestCase
{
    public const PK = 'bhq_pk_0123456789012345678901234567890123456789abc';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('hq.public_key', self::PK);
        $app['config']->set('hq.browser', true);
        $app['config']->set('hq.environment', 'production');
        $app['config']->set('hq.release', 'rel<1>"x');
        // Testbench runs as "testing": the script is not rendered there unless send_local is on, so most tests run as production.
        $app['config']->set('app.env', 'production');
        $app['env'] = 'production';
    }

    public function test_route_serves_the_file_with_type_and_cache_headers_and_no_cookie(): void
    {
        $res = $this->get('/_brigada-hq/browser.js?v='.Hq::VERSION);
        $res->assertOk();
        $this->assertStringContainsString('application/javascript', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $res->headers->get('Cache-Control'));
        $this->assertSame([], $res->headers->getCookies());
        $this->assertNull($res->headers->get('Set-Cookie'));
        $this->assertStringContainsString('BrigadaHq', $res->getContent());
        $this->assertSame('/_brigada-hq/browser.js', route('brigada-hq.browser', [], false));
    }

    public function test_route_is_404_when_the_browser_part_is_off_or_there_is_no_public_key(): void
    {
        config(['hq.browser' => false]);
        $this->get('/_brigada-hq/browser.js')->assertNotFound();
        config(['hq.browser' => true, 'hq.public_key' => null]);
        $this->get('/_brigada-hq/browser.js')->assertNotFound();
        config(['hq.public_key' => 'bhq_sk_'.str_repeat('a', 43)]);
        $this->get('/_brigada-hq/browser.js')->assertNotFound();
    }

    public function test_the_shipped_file_is_the_generated_iife(): void
    {
        $js = file_get_contents(__DIR__.'/../resources/js/hq-browser.js');
        $this->assertStringContainsString('BrigadaHq', $js);
        $this->assertStringContainsString('data-key', $js);
    }

    public function test_directive_renders_the_tag_with_escaped_attributes(): void
    {
        $html = Blade::render('@brigadaHqScript');
        $this->assertStringContainsString('<script src="/_brigada-hq/browser.js?v='.Hq::VERSION.'"', $html);
        $this->assertStringContainsString('data-key="'.self::PK.'"', $html);
        $this->assertStringContainsString('data-url="https://hq.test"', $html);
        $this->assertStringContainsString('data-environment="production"', $html);
        $this->assertStringContainsString('data-release="rel&lt;1&gt;&quot;x"', $html);
        $this->assertStringContainsString(' defer></script>', $html);
        $this->assertStringNotContainsString('rel<1>', $html);
        $this->assertStringNotContainsString('nonce', $html);
    }

    public function test_directive_renders_nothing_when_disabled_without_key_or_with_a_secret_key(): void
    {
        config(['hq.browser' => false]);
        $this->assertSame('', trim(Blade::render('@brigadaHqScript')));
        config(['hq.browser' => true, 'hq.public_key' => '']);
        $this->assertSame('', trim(Blade::render('@brigadaHqScript')));
        config(['hq.public_key' => 'bhq_sk_'.str_repeat('a', 43)]);
        $this->assertSame('', trim(Blade::render('@brigadaHqScript')));
    }

    public function test_directive_renders_nothing_in_local_and_testing_unless_send_local(): void
    {
        foreach (['local', 'testing'] as $env) {
            $this->app['env'] = $env;
            config(['hq.send_local' => false]);
            $this->assertSame('', trim(Blade::render('@brigadaHqScript')), $env);
            config(['hq.send_local' => true]);
            $this->assertStringContainsString('<script src=', Blade::render('@brigadaHqScript'), $env);
        }
    }

    public function test_nonce_is_added_when_vite_has_one(): void
    {
        \Illuminate\Support\Facades\Vite::useCspNonce('abc123');
        $this->assertStringContainsString(' nonce="abc123"', (string) HqFacade::scriptTag());
    }

    public function test_facade_returns_the_same_tag(): void
    {
        $this->assertSame(Blade::render('@brigadaHqScript'), (string) HqFacade::scriptTag());
        config(['hq.browser' => false]);
        $this->assertSame('', (string) HqFacade::scriptTag());
        $this->assertFalse(HqFacade::browserEnabled());
    }

    public function test_install_prints_the_env_line_and_the_blade_line_and_checks_the_public_key(): void
    {
        $this->artisan('hq:install')
            ->expectsOutputToContain('BRIGADA_HQ_PUBLIC_KEY=bhq_pk_...')
            ->expectsOutputToContain('@brigadaHqScript')
            ->expectsOutputToContain('Public key format looks right.')
            ->assertExitCode(0);
        config(['hq.public_key' => 'bhq_sk_'.str_repeat('a', 43)]);
        $this->artisan('hq:install')->assertExitCode(1);
        config(['hq.public_key' => null]);
        $this->artisan('hq:install')->expectsOutputToContain('stays off')->assertExitCode(0);
    }
}
