<?php

namespace Brigada\Hq\Jobs;

use Brigada\Hq\Hq;
use Brigada\Hq\Transport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Delivers one batch. 5xx and network errors: 3 tries, 5 s then 30 s. 429: released for Retry-After. 401/403: warned once, dropped. */
class SendBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** @param list<array<string, mixed>> $events */
    public function __construct(public array $events) {}

    public function handle(Transport $transport): void
    {
        $delivery = $transport->send($this->events);

        if ($delivery->ok()) {
            return;
        }
        if ($delivery->refused()) {
            Hq::warnOnce('Brigada HQ refused the key (HTTP '.$delivery->status.'). Check BRIGADA_HQ_KEY; errors are not being reported.');

            return;
        }
        if ($delivery->status === 429) {
            if ($this->job && $this->attempts() < $this->tries) {
                $this->release($delivery->retryAfter ?? 30);

                return;
            }

            return;
        }
        if ($delivery->retryable()) {
            throw new DeliveryFailed('Brigada HQ answered '.$delivery->status);
        }
        // 400/413 and the like: the batch itself is wrong, a retry would repeat it.
        Hq::warnOnce('Brigada HQ rejected a batch (HTTP '.$delivery->status.').');
    }
}
