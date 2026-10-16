<?php

namespace Brigada\Hq\Commands;

use Brigada\Hq\Hq;
use Brigada\Hq\Support\EventBuilder;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'hq:test';

    protected $description = 'Throw a test exception and report it to Brigada HQ now';

    public function handle(Hq $hq): int
    {
        if (blank(config('hq.key'))) {
            $this->error('BRIGADA_HQ_KEY is not set. Run php artisan hq:install.');

            return self::FAILURE;
        }

        try {
            throw new \RuntimeException('Brigada HQ test exception: safe to ignore or close');
        } catch (\Throwable $e) {
            $delivery = $hq->sendNow(EventBuilder::error($e));
        }

        if ($delivery->ok()) {
            $this->info('Sent, check the Errors tab.');
            if (! $hq->enabled()) {
                $this->line('Note: reporting is off in this environment ('.config('app.env').'). Set BRIGADA_HQ_SEND_LOCAL=true to report from here.');
            }

            return self::SUCCESS;
        }
        $this->error(match (true) {
            $delivery->refused() => 'HQ refused the key (HTTP '.$delivery->status.'). Check BRIGADA_HQ_KEY.',
            $delivery->status === 429 => 'HQ is rate limiting this key (HTTP 429). Try again in a minute.',
            $delivery->status === 0 => 'Could not reach HQ at '.config('hq.url').'.',
            default => 'HQ answered HTTP '.$delivery->status.'.',
        });

        return self::FAILURE;
    }
}
