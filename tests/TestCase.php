<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\HqServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KEY = 'bhq_sk_0123456789012345678901234567890123456789abc';

    protected function getPackageProviders($app): array
    {
        return [HqServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hq.key', self::KEY);
        $app['config']->set('hq.url', 'https://hq.test');
        $app['config']->set('hq.enabled', true);
        $app['config']->set('hq.queue', false);
        $app['config']->set('hq.environment', 'production');
        $app['config']->set('hq.release', 'abc1234');
        $app['config']->set('cache.default', 'array');
    }

    /** Pretend this is a web request, not the CLI, so the request and the user are captured. */
    protected function actAsWeb(): void
    {
        $r = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $r->setValue($this->app, false);
    }

    protected function boom(string $message = 'it broke'): \Throwable
    {
        try {
            $this->thrower($message);
        } catch (\Throwable $e) {
            return $e;
        }
        throw new \LogicException('unreachable');
    }

    protected function thrower(string $message): void
    {
        throw new \RuntimeException($message);
    }
}
