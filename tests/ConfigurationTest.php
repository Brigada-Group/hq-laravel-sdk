<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Hq;
use Brigada\Hq\HqServiceProvider;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;

// Do not inherit our usual TestCase: it sets hq.* after registration and hides config-loading bugs.
class ConfigurationTest extends Orchestra
{
    private array $originalEnvironment = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Hq::$console = null;
            $repository = Env::getRepository();
            foreach ($this->originalEnvironment as $name => $value) {
                $value === null ? $repository->clear($name) : $repository->set($name, $value);
            }
        }
    }

    public static function secretKeys(): array
    {
        return [
            'missing secret' => [null],
            'empty secret' => [''],
            'present isolated secret' => ['sdk-configuration-test-only'],
        ];
    }

    #[DataProvider('secretKeys')]
    public function test_false_environment_flags_survive_provider_registration(?string $key): void
    {
        $queueConfig = config('queue');
        $hq = $this->registerHq($key);

        $this->assertSame($key, config('hq.key'));
        $this->assertFalse(config('hq.queue'));
        $this->assertFalse(config('hq.browser'));
        $this->assertSame($queueConfig, config('queue'));
        $this->assertArrayNotHasKey('app', config('hq'));
        $this->assertArrayNotHasKey('hq', config('hq'));
        $this->assertSame($key !== null && $key !== '', $hq->enabled());
        $this->assertFalse($hq->browserEnabled());
        $this->assertSame('', (string) $hq->scriptTag());
        $this->get('/_brigada-hq/browser.js')->assertNotFound();

        $hq->report(new \RuntimeException('isolated environment configuration test'));

        Http::assertSentCount($key === null || $key === '' ? 0 : 1);
        Queue::assertNothingPushed();
    }

    #[DataProvider('secretKeys')]
    public function test_published_config_overrides_are_merged_in_the_hq_namespace(?string $key): void
    {
        // Published app config must win over package defaults, including conflicting env defaults.
        $hq = $this->registerHq($key, [
            'BRIGADA_HQ_QUEUE' => 'true',
            'BRIGADA_HQ_BROWSER' => 'true',
        ], [
            'queue' => false,
            'browser' => false,
            'timeout' => 2,
        ]);

        $this->assertFalse(config('hq.queue'));
        $this->assertFalse(config('hq.browser'));
        $this->assertSame(2, config('hq.timeout'));
        $this->assertArrayNotHasKey('app', config('hq'));
        $this->assertArrayNotHasKey('hq', config('hq'));
        $this->assertFalse($hq->browserEnabled());

        $hq->report(new \RuntimeException('isolated published configuration test'));

        Http::assertSentCount($key === null || $key === '' ? 0 : 1);
        Queue::assertNothingPushed();
    }

    private function registerHq(?string $key, array $environment = [], array $overrides = []): Hq
    {
        $repository = Env::getRepository();
        foreach (array_replace([
            'APP_ENV' => 'production',
            'BRIGADA_HQ_KEY' => $key,
            'BRIGADA_HQ_ENABLED' => null,
            'BRIGADA_HQ_SEND_LOCAL' => 'false',
            'BRIGADA_HQ_URL' => 'https://hq.invalid',
            'BRIGADA_HQ_RELEASE' => 'isolated-test',
            'BRIGADA_HQ_QUEUE' => 'false',
            'BRIGADA_HQ_BROWSER' => 'false',
            // A public key makes browser reporting default on, so false must really be honored.
            'BRIGADA_HQ_PUBLIC_KEY' => 'bhq_pk_'.str_repeat('a', 43),
            'BRIGADA_HQ_HEARTBEAT' => 'false',
        ], $environment) as $name => $value) {
            $this->originalEnvironment[$name] = $repository->get($name);
            $value === null ? $repository->clear($name) : $repository->set($name, $value);
        }

        Http::preventStrayRequests();
        Http::fake(['https://hq.invalid/*' => Http::response(['accepted' => 1], 202)]);
        Queue::fake();
        Hq::$console = true;
        config(['cache.default' => 'array', 'hq' => $overrides]);

        $this->app->register(HqServiceProvider::class);

        return $this->app->make(Hq::class);
    }
}
