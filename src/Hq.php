<?php

namespace Brigada\Hq;

use Brigada\Hq\Jobs\DeliveryFailed;
use Brigada\Hq\Jobs\SendBatch;
use Brigada\Hq\Support\EventBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Hq
{
    public const VERSION = '0.2.0';

    public const SDK_NAME = 'brigada/hq-sdk';

    private bool $busy = false;

    public function enabled(): bool
    {
        return (bool) config('hq.enabled') && filled(config('hq.key'));
    }

    /** The browser part is on: switched on in config and a public key is set. The route serves the script when this is true. */
    public function browserEnabled(): bool
    {
        return (bool) config('hq.browser') && $this->publicKey() !== null;
    }

    /** The configured public key, or null unless it is a bhq_pk_ key: a secret key must never reach a page. */
    public function publicKey(): ?string
    {
        $key = config('hq.public_key');

        return is_string($key) && str_starts_with($key, 'bhq_pk_') ? $key : null;
    }

    /**
     * The <script> tag for the page, or an empty string when the browser part is off, no public key is set, or the app runs in
     * local/testing without BRIGADA_HQ_SEND_LOCAL. Use as @brigadaHqScript, or {{ Hq::scriptTag() }} in an Inertia root view.
     */
    public function scriptTag(): HtmlString
    {
        try {
            if (! $this->browserEnabled()) {
                return new HtmlString('');
            }
            if (app()->environment(['local', 'testing']) && ! config('hq.send_local')) {
                return new HtmlString('');
            }
            $attrs = [
                'src' => route('brigada-hq.browser', ['v' => self::VERSION], false),
                'data-key' => $this->publicKey(),
                'data-url' => config('hq.url'),
                'data-environment' => config('hq.environment'),
                'data-release' => config('hq.release') ?: \Brigada\Hq\Support\Release::fromGit(base_path()),
            ];
            $nonce = null;
            $vite = \Illuminate\Foundation\Vite::class;
            if (class_exists($vite) && method_exists($vite, 'cspNonce')) {
                $nonce = app($vite)->cspNonce();
            }
            if (is_string($nonce) && $nonce !== '') {
                $attrs['nonce'] = $nonce;
            }
            $html = '';
            foreach ($attrs as $name => $value) {
                if (is_string($value) && $value !== '') {
                    $html .= ' '.$name.'="'.e($value).'"';
                }
            }

            return new HtmlString('<script'.$html.' defer></script>');
        } catch (Throwable) {
            return new HtmlString('');
        }
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

    /** Tests only: force the web (false) or console (true) path; null = ask Laravel. */
    public static ?bool $console = null;

    /** @param list<array<string, mixed>> $events */
    public function dispatch(array $events): void
    {
        // Opt-in only (BRIGADA_HQ_QUEUE=true): an app without a running queue worker would hold its errors in the queue forever.
        if (config('hq.queue', false)) {
            $job = new SendBatch($events);
            if ($name = config('hq.queue_name')) {
                $job->onQueue($name);
            }
            dispatch($job);

            return;
        }
        $send = function () use ($events): void {
            try {
                $delivery = app(Transport::class)->send($events);
                if ($delivery->refused()) {
                    self::warnOnce('Brigada HQ refused the key (HTTP '.$delivery->status.'). Check BRIGADA_HQ_KEY; errors are not being reported.');
                }
            } catch (Throwable) {
            }
        };
        // Console (artisan, the scheduler, queue workers): send now. A worker can run for days, so "when the app terminates" would be never.
        if (self::$console ?? app()->runningInConsole()) {
            $send();

            return;
        }
        // A web request: after the response is sent (PHP-FPM has already flushed it to the visitor), so it costs the visitor nothing.
        app()->terminating($send);
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
