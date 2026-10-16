<?php

namespace Brigada\Hq;

use Brigada\Hq\Jobs\DeliveryFailed;
use Brigada\Hq\Jobs\SendBatch;
use Brigada\Hq\Support\EventBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Hq
{
    public const VERSION = '0.1.1';

    public const SDK_NAME = 'brigada/hq-sdk';

    private bool $busy = false;

    public function enabled(): bool
    {
        return (bool) config('hq.enabled') && filled(config('hq.key'));
    }

    /** Called from the exception handler. Never throws and never reports itself. */
    public function report(Throwable $e): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        try {
            if (! $this->enabled() || $this->ignored($e)) {
                return;
            }
            $repeats = $this->dedupe($e);
            if ($repeats === false) {
                return;
            }
            $this->dispatch([EventBuilder::error($e, $repeats ?: null)]);
        } catch (Throwable) {
            // a failure to report must never break the app
        } finally {
            $this->busy = false;
        }
    }

    /** Sends one event now and returns HQ's answer, for the commands. Ignores enabled, ignore and de-duplication. */
    public function sendNow(array $event): Delivery
    {
        return app(Transport::class)->send([$event]);
    }

    public function ignored(Throwable $e): bool
    {
        if ($e instanceof DeliveryFailed || str_contains($e->getMessage(), SendBatch::class)) {
            return true;
        }
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return true;
        }
        foreach ((array) config('hq.ignore', []) as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same exception (class, file, line) within the window is counted, not sent again.
     * Returns false to suppress, else the number of repeats suppressed since the last send (0 = none).
     */
    public function dedupe(Throwable $e): int|false
    {
        try {
            $minutes = (int) config('hq.dedupe_minutes', 5);
            if ($minutes <= 0) {
                return 0;
            }
            $fp = sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
            $seen = 'hq:seen:'.$fp;
            $count = 'hq:repeats:'.$fp;
            if (! Cache::add($seen, 1, $minutes * 60)) {
                Cache::add($count, 0, 3600);
                Cache::increment($count);

                return false;
            }

            return (int) Cache::pull($count, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param list<array<string, mixed>> $events */
    public function dispatch(array $events): void
    {
        if (config('hq.queue', true)) {
            $job = new SendBatch($events);
            if ($name = config('hq.queue_name')) {
                $job->onQueue($name);
            }
            dispatch($job);

            return;
        }
        app()->terminating(function () use ($events): void {
            try {
                $delivery = app(Transport::class)->send($events);
                if ($delivery->refused()) {
                    self::warnOnce('Brigada HQ refused the key (HTTP '.$delivery->status.'). Check BRIGADA_HQ_KEY; errors are not being reported.');
                }
            } catch (Throwable) {
            }
        });
    }

    /** One warning per hour, so a wrong key does not fill the log. */
    public static function warnOnce(string $message): void
    {
        try {
            if (Cache::add('hq:warned:'.sha1($message), 1, 3600)) {
                Log::warning($message);
            }
        } catch (Throwable) {
        }
    }
}
