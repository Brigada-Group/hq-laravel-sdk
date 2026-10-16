# brigada/hq-sdk

Report your Laravel app's errors to [Brigada HQ](https://hq.brigada.dev). A new bug opens one ticket for the project's responsible person; the same bug only counts. Laravel 11, 12 and 13, PHP 8.2+.

This package is a fork of **Guardian**, written by Dren (`Brigada-Group/guardian`). The exception capture, the stack-trace redaction, the 5-minute de-duplication, the queued sending and the install command all come from there. It is trimmed to errors and a heartbeat and speaks HQ's own ingest protocol (v1); Discord, the dashboard, the other monitors and the hub client are gone. Thank you, Dren.

## Install

```
composer require brigada/hq-sdk
php artisan hq:install
```

In HQ, open the project, Errors, Connect an app, and copy the secret key (`bhq_sk_...`, shown once). Put it in `.env`:

```
BRIGADA_HQ_KEY=bhq_sk_...
```

Check it: `php artisan hq:test` throws a test exception and prints `Sent, check the Errors tab.`

The package registers itself (Laravel auto-discovery) and hooks the exception handler's `reportable()`, so HTTP requests, queue jobs and artisan commands are all covered. Nothing else to wire up.

## Options (`config/hq.php`, mostly env)

| Env | Default | |
|---|---|---|
| `BRIGADA_HQ_KEY` | none | The source's secret key. No key, nothing is sent. |
| `BRIGADA_HQ_URL` | `https://hq.brigada.dev` | |
| `BRIGADA_HQ_ENV` | `APP_ENV` | HQ opens tickets for the source's ticket environments (default `production`). |
| `BRIGADA_HQ_RELEASE` | git sha of `HEAD` | Read from `.git`; set it in Docker builds without `.git`. A closed ticket reopens as "came back in <release>". |
| `BRIGADA_HQ_SEND_LOCAL` | false | Off in `local` and `testing` unless true. `BRIGADA_HQ_ENABLED` forces it either way. |
| `BRIGADA_HQ_QUEUE` | false | No queue worker needed by default: a web request sends after the response has gone to the visitor; artisan, the scheduler and queue workers send at once (5-second timeout). `true` sends through a queued job with retries, only for apps whose workers always run. |
| `BRIGADA_HQ_HEARTBEAT` | true | `hq:heartbeat` every 5 minutes through the scheduler (needs `schedule:run`). HQ lists apps that stopped reporting. |
| config `ignore` | validation, auth, 404, model-not-found, ... | Plus every HTTP exception with a status below 500. |
| config `dedupe_minutes` | 5 | The same exception (class, file, line) is counted, not resent; the count rides on the next one as `tags.repeats`. |

Delivery: 5-second timeout, `202` is success; with `BRIGADA_HQ_QUEUE=true`, `429` is released back to the queue for `Retry-After` and `5xx` and network errors retry 3 times (5 s, 30 s); `401/403` log one warning an hour and stop. Reporting never throws into your app and never reports its own failures.

## Commands

- `php artisan hq:install` publishes the config, prints the `.env` lines and checks the key format.
- `php artisan hq:test` reports a test exception now and prints HQ's answer.
- `php artisan hq:heartbeat` sends one heartbeat (scheduled for you).

## What is sent, and what is not

Sent: the exception class and message, the stack (file relative to the app root, line, `Class::method`, and whether it is your code or `vendor/`), the environment, release, server name, PHP and Laravel versions, and for a web request the method, the URL **without its query string**, the route pattern (`orders/{order}`) and the status. The user is the **id only** (`auth()->id()`).

Never sent: e-mail addresses or names, request bodies, headers, cookies, IP addresses, or the query string. The message is redacted before it leaves: the base path is stripped, `Bearer` tokens and values after `password`, `token`, `secret`, `api_key`, `authorization` or `credential` become `[redacted]`, and so do e-mail addresses. HQ scrubs again on arrival.

## Migrating from Guardian

Remove `brigada/guardian` (pause queue workers and run `php artisan guardian:purge-queue-jobs` first, as Guardian's install notes say), or just set its hub off, then follow Install above. Until you switch, HQ still accepts Guardian's old hub API, so nothing breaks in the meantime. Do not run both: you would report every exception twice.

## Tests

```
composer install && vendor/bin/phpunit
```

MIT licensed.
