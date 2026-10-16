<?php

namespace Brigada\Hq\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'hq:install';

    protected $description = 'Publish the Brigada HQ config and show the .env lines and the Blade line to add';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'hq-config']);
        $this->newLine();
        $this->line('Add these to .env (the keys are in HQ: Project > Errors > Connect an app):');
        $this->newLine();
        $this->line('  BRIGADA_HQ_KEY=bhq_sk_...            # server errors (secret: never in a page)');
        $this->line('  BRIGADA_HQ_PUBLIC_KEY=bhq_pk_...     # browser errors (optional; safe in a page)');
        $this->line('  # BRIGADA_HQ_URL=https://hq.brigada.dev');
        $this->line('  # BRIGADA_HQ_ENV='.config('app.env'));
        $this->line('  # BRIGADA_HQ_RELEASE=<git sha or version>');
        $this->line('  # BRIGADA_HQ_BROWSER=false            (turn the browser script off)');
        $this->line('  # BRIGADA_HQ_SEND_LOCAL=true          (report from local/testing too)');
        $this->newLine();

        $this->line('For browser errors add this one line inside <head> of '.$this->layoutHint().':');
        $this->newLine();
        $this->line('  @brigadaHqScript');
        $this->line('  (an Inertia app that builds its root view another way: {!! \Brigada\Hq\Facades\Hq::scriptTag() !!})');
        $this->newLine();

        $failed = false;
        $key = (string) config('hq.key');
        if ($key === '') {
            $this->warn('BRIGADA_HQ_KEY is not set yet.');
        } elseif (! preg_match('/^bhq_sk_[A-Za-z0-9_-]{43}$/', $key)) {
            $this->error('BRIGADA_HQ_KEY does not look like a secret key (bhq_sk_ and 43 characters). A public key (bhq_pk_) belongs in a browser, not here.');
            $failed = true;
        } else {
            $this->info('Key format looks right.');
        }

        $public = (string) config('hq.public_key');
        if ($public === '') {
            $this->line('BRIGADA_HQ_PUBLIC_KEY is not set: the browser script stays off.');
        } elseif (! str_starts_with($public, 'bhq_pk_')) {
            $this->error('BRIGADA_HQ_PUBLIC_KEY must start with bhq_pk_ (the public key from HQ). Never put a secret key (bhq_sk_) here: it would be printed into every page. The script stays off until this is fixed.');
            $failed = true;
        } else {
            $this->info('Public key format looks right.');
        }

        $this->line('Then run: php artisan hq:test');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function layoutHint(): string
    {
        foreach (['app', 'layouts/app', 'layouts/main', 'welcome'] as $view) {
            if (is_file(resource_path('views/'.$view.'.blade.php'))) {
                return 'resources/views/'.$view.'.blade.php'.($view === 'app' ? ' (the Inertia root view)' : '');
            }
        }

        return 'your main layout';
    }
}
