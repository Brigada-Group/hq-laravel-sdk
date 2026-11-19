<?php

namespace Brigada\Hq;

use Brigada\Hq\Commands\HeartbeatCommand;
use Brigada\Hq\Commands\InstallCommand;
use Brigada\Hq\Commands\TestCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Brigada\Hq\Http\BrowserScriptController;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

class HqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeDefaultConfig();
        $this->app->singleton(Hq::class);
        $this->app->singleton(Transport::class);
    }

    /**
     * The package defaults under "hq", the app's own config/hq.php winning. Not mergeConfigFrom(): it requires the file inside its
     * own scope, where a variable the file assigns (such as $key) replaces its argument; with $key overwritten the whole app config
     * once ended up under "hq" (and hq.queue became truthy). The file is required in a scope of its own here.
     */
    private function mergeDefaultConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }
        $config = $this->app->make('config');
        $published = $config->get('hq', []);
        $config->set('hq', array_merge($this->defaults(), is_array($published) ? $published : []));
    }

    /** The package's defaults file. A method so a test can point it at a file that assigns the merge scope's variables. */
    protected function defaultConfigPath(): string
    {
        return __DIR__.'/../config/hq.php';
    }

    /**
     * The defaults, required in an empty scope: the closure takes the path through func_get_arg, so not even a $path variable is in
     * scope for the file to overwrite.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (static function (): array {
            return require func_get_arg(0);
        })($this->defaultConfigPath());
    }

    public function boot(): void
    {
        // Registered outside the `web` group: no session, no cookie, no CSRF. A closure-free controller keeps route:cache working.
        Route::get('/_brigada-hq/browser.js', BrowserScriptController::class)->name('brigada-hq.browser');
        Blade::directive('brigadaHqScript', fn () => '<?php echo app(\Brigada\Hq\Hq::class)->scriptTag(); ?>');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hq.php' => config_path('hq.php')], 'hq-config');
            $this->commands([InstallCommand::class, TestCommand::class, HeartbeatCommand::class]);
        }

        // HTTP, queue jobs and console all end up in the exception handler's report().
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e): void {
                    try {
                        $this->app->make(Hq::class)->report($e);
                    } catch (Throwable) {
                    }
                });
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('hq.heartbeat') && app(Hq::class)->enabled()) {
                $schedule->command('hq:heartbeat')->everyFiveMinutes()->runInBackground();
            }
        });
    }
}
