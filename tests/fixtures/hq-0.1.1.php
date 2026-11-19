<?php

/**
 * Brigada HQ error reporting. Publish with `php artisan hq:install`.
 */

$env = env('APP_ENV', 'production');
$key = env('BRIGADA_HQ_KEY');
$local = in_array($env, ['local', 'testing'], true);

return [
    // The source's secret key from HQ (Project -> Errors -> Connect an app): bhq_sk_...
    'key' => $key,

    // Where HQ lives. Leave the default unless Brigada tells you otherwise.
    'url' => env('BRIGADA_HQ_URL', 'https://hq.brigada.dev'),

    // The environment name on every event. HQ opens tickets for the source's ticket environments (default: production).
    'environment' => env('BRIGADA_HQ_ENV', $env),

    // A git sha or version. Default: the sha of HEAD read from .git (null when there is no .git, as in most Docker images:
    // set BRIGADA_HQ_RELEASE in the build).
    'release' => env('BRIGADA_HQ_RELEASE'),

    // Reporting is on when a key is set and the app is not running locally or in tests.
    // BRIGADA_HQ_SEND_LOCAL=true sends from local/testing too; BRIGADA_HQ_ENABLED forces it either way.
    'enabled' => filter_var(
        env('BRIGADA_HQ_ENABLED', filled($key) && (! $local || filter_var(env('BRIGADA_HQ_SEND_LOCAL', false), FILTER_VALIDATE_BOOL))),
        FILTER_VALIDATE_BOOL
    ),

    // true: a queued job on the default connection (retries, never slows the response).
    // false: sent synchronously after the response (app()->terminating).
    'queue' => filter_var(env('BRIGADA_HQ_QUEUE', true), FILTER_VALIDATE_BOOL),
    'queue_name' => env('BRIGADA_HQ_QUEUE_NAME'),

    // Not reported. HttpException with a status below 500 is always skipped as well.
    'ignore' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Validation\ValidationException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Session\TokenMismatchException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ],

    // The same exception within this many minutes is counted, not sent again (the count rides on the next one as tags.repeats).
    'dedupe_minutes' => 5,

    // A heartbeat every five minutes through the scheduler (php artisan schedule:run must be running), so HQ can list apps that stopped reporting.
    'heartbeat' => filter_var(env('BRIGADA_HQ_HEARTBEAT', true), FILTER_VALIDATE_BOOL),

    // Seconds before a request to HQ gives up.
    'timeout' => 5,
];
