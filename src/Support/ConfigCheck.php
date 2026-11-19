<?php

namespace Brigada\Hq\Support;

use Throwable;

/**
 * Settings that can lose errors without a word, printed by hq:test and hq:install. From 0.3.0 a published config/hq.php is used;
 * before, it was ignored whenever BRIGADA_HQ_KEY was set, so an old file can start to apply on upgrade. Informational only: never
 * changes an exit code.
 */
final class ConfigCheck
{
    /** A file published by 0.1.0 or 0.1.1 queued by default. */
    private const OLD_QUEUE_DEFAULT = '~env\(\s*[\'"]BRIGADA_HQ_QUEUE[\'"]\s*,\s*true~';

    /** @return list<string> */
    public static function warnings(?string $publishedPath = null): array
    {
        $warnings = [];
        try {
            $connection = config('queue.default');
            if (config('hq.queue') && $connection !== 'sync') {
                $warnings[] = 'hq.queue is on: errors go through the '.(is_string($connection) ? $connection : 'default').' queue and need a running worker. Set BRIGADA_HQ_QUEUE=false unless one always runs.';
            }
            if (self::publishedWithOldQueueDefault($publishedPath)) {
                $warnings[] = 'config/hq.php was published by 0.1.0/0.1.1 and is used from 0.3.0 on: compare it with vendor/brigada/hq-sdk/config/hq.php, or re-publish it with php artisan vendor:publish --tag=hq-config --force and re-apply your edits.';
            }
        } catch (Throwable) {
            // a check must never break a command
        }

        return $warnings;
    }

    /** True when the app's config/hq.php (or $path) was published by 0.1.0/0.1.1, whose queue default is true. Never throws. */
    public static function publishedWithOldQueueDefault(?string $path = null): bool
    {
        try {
            $path ??= config_path('hq.php');

            return is_file($path) && preg_match(self::OLD_QUEUE_DEFAULT, (string) file_get_contents($path)) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
