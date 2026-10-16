<?php

namespace Brigada\Hq\Http;

use Brigada\Hq\Hq;
use Illuminate\Http\Response;

/** Serves the generated browser script (resources/js/hq-browser.js, built from @brigada/hq-sdk). No session, no cookie. */
class BrowserScriptController
{
    public function __invoke(Hq $hq): Response
    {
        $file = __DIR__.'/../../resources/js/hq-browser.js';
        if (! $hq->browserEnabled() || ! is_file($file)) {
            abort(404);
        }

        // The ?v= query makes the URL change with every package version, so the file can be cached for a year.
        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
