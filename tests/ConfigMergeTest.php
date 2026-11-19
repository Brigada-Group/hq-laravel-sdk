<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Hq;
use Brigada\Hq\HqServiceProvider;
use Brigada\Hq\Support\SecretPath;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The package's defaults are merged under "hq" and nothing else. config/hq.php once assigned a variable named $key, and
 * Laravel's mergeConfigFrom($path, $key) requires the file inside its own scope: the file overwrote $key, so with no
 * BRIGADA_HQ_KEY the whole app config was merged under "hq" (hq.queue became the queue config, truthy, and every error took the
 * queued path), and with a key the app's published config/hq.php was replaced by the package defaults.
 *
 * No hq.* is set here before the provider registers: this is an app as it is installed.
 */
class ConfigMergeTest extends Orchestra
{
    /** @var array<string, string|null> */
    private array $env = [];

    protected function getPackageProviders($app): array
    {
        return [HqServiceProvider::class];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (array_keys($this->env) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->env = [];
    }

    private function setEnv(string $name, string $value): void
    {
        $this->env[$name] = $value;
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public function test_without_a_key_only_the_package_defaults_are_merged_under_hq(): void
    {
        $hq = config('hq');

        $this->assertIsArray($hq);
        $this->assertSame([], array_values(array_intersect(array_keys($hq), ['app', 'cache', 'database', 'queue_connections', 'view'])), 'the app config leaked into hq.*');
        $this->assertArrayNotHasKey('app', $hq);
        $this->assertFalse(config('hq.queue'), 'hq.queue must be the boolean default, not the app queue config');
        $this->assertNull(config('hq.key'));
        $this->assertFalse(config('hq.enabled'));
        $this->assertSame('https://hq.brigada.dev', config('hq.url'));
        $this->assertSame(SecretPath::DEFAULT_NAMES, config('hq.secret_route_parameters'));
        $this->assertSame(array_keys(require dirname(__DIR__).'/config/hq.php'), array_keys($hq));
        $this->assertFalse($this->app->make(Hq::class)->enabled());
    }

    public function test_the_apps_own_hq_config_wins_over_the_package_defaults(): void
    {
        // An app that published config/hq.php, with a key in its environment; the provider registers after the app config is loaded.
        $this->setEnv('BRIGADA_HQ_KEY', 'bhq_sk_fromenv');
        config()->set('hq', ['url' => 'https://hq.example.test', 'queue' => false, 'key' => 'bhq_sk_published']);
        $this->app->register(HqServiceProvider::class, force: true);

        $this->assertSame('https://hq.example.test', config('hq.url'));
        $this->assertSame('bhq_sk_published', config('hq.key'));
        $this->assertFalse(config('hq.queue'));
        $this->assertSame(5, config('hq.timeout'), 'a default the app did not set still comes from the package');
        $this->assertArrayNotHasKey('app', config('hq'));
    }

    public function test_the_config_file_does_not_use_variables_the_merge_scope_uses(): void
    {
        // Token by token: also catches foreach (... as $key), [$key] = ..., list($key).
        $source = (string) file_get_contents(dirname(__DIR__).'/config/hq.php');

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $this->assertNotContains($token[1], ['$key', '$path', '$config'], $token[1].' is used in config/hq.php');
            }
        }
    }

    public function test_the_defaults_file_is_required_in_a_scope_of_its_own(): void
    {
        // A defaults file that assigns $key, $path and $config: the provider must still put exactly its array under "hq".
        $appName = config('app.name');
        config()->offsetUnset('hq');
        $this->app->register(new class($this->app) extends HqServiceProvider
        {
            protected function defaultConfigPath(): string
            {
                return __DIR__.'/fixtures/assigns-merge-variables.php';
            }
        }, force: true);

        $this->assertSame(['a' => 1], config('hq'));
        $this->assertSame($appName, config('app.name'));
    }

    public function test_a_cached_config_is_left_as_it_is(): void
    {
        config()->set('hq', ['url' => 'https://cached.test']);
        $this->app->instance('config_loaded_from_cache', true);
        $this->app->register(HqServiceProvider::class, force: true);

        $this->assertSame(['url' => 'https://cached.test'], config('hq'));
    }
}
