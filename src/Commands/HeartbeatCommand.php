<?php

namespace Brigada\Hq\Commands;

use Brigada\Hq\Hq;
use Brigada\Hq\Support\EventBuilder;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'hq:heartbeat';

    protected $description = 'Tell Brigada HQ this app is alive (scheduled every five minutes)';

    public function handle(Hq $hq): int
    {
        if (! $hq->enabled()) {
            $this->line('Brigada HQ reporting is off here; no heartbeat sent.');

            return self::SUCCESS;
        }
        $delivery = $hq->sendNow(EventBuilder::heartbeat());
        if ($delivery->ok()) {
            $this->info('Heartbeat sent.');

            return self::SUCCESS;
        }
        if ($delivery->refused()) {
            Hq::warnOnce('Brigada HQ refused the key (HTTP '.$delivery->status.'). Check BRIGADA_HQ_KEY.');
        }
        $this->error('Heartbeat failed (HTTP '.$delivery->status.').');

        return self::FAILURE;
    }
}
