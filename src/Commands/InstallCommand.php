<?php

namespace Brigada\Hq\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'hq:install';

    protected $description = 'Publish the Brigada HQ config and show the .env lines to add';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'hq-config']);
        $this->newLine();
        $this->line('Add these to .env (the key is in HQ: Project > Errors > Connect an app):');
        $this->newLine();
        $this->line('  BRIGADA_HQ_KEY=bhq_sk_...');
        $this->line('  # BRIGADA_HQ_URL=https://hq.brigada.dev');
        $this->line('  # BRIGADA_HQ_ENV='.config('app.env'));
        $this->line('  # BRIGADA_HQ_RELEASE=<git sha or version>');
        $this->line('  # BRIGADA_HQ_SEND_LOCAL=true   (report from local/testing too)');
        $this->newLine();

        $key = (string) config('hq.key');
        if ($key === '') {
            $this->warn('BRIGADA_HQ_KEY is not set yet.');
        } elseif (! preg_match('/^bhq_sk_[A-Za-z0-9_-]{43}$/', $key)) {
            $this->error('BRIGADA_HQ_KEY does not look like a secret key (bhq_sk_ and 43 characters). A public key (bhq_pk_) belongs in a browser, not here.');

            return self::FAILURE;
        } else {
            $this->info('Key format looks right.');
        }
        $this->line('Then run: php artisan hq:test');

        return self::SUCCESS;
    }
}
