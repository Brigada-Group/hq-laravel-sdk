<?php

namespace Brigada\Hq;

use Brigada\Hq\Commands\HeartbeatCommand;
use Brigada\Hq\Commands\InstallCommand;
use Brigada\Hq\Commands\TestCommand;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->mergeConfigFrom(__DIR__.'/../config/hq.php', 'hq');
        $this->app->singleton(Hq::class);
        $this->app->singleton(Transport::class);
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
