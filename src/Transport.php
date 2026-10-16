<?php

namespace Brigada\Hq;

use Illuminate\Support\Facades\Http;
use Throwable;

/** POST {url}/api/ingest/v1/events with the secret key as a Bearer token. Never throws. */
final class Transport
{
    public const PATH = '/api/ingest/v1/events';

    /** @param list<array<string, mixed>> $events */
    public function send(array $events): Delivery
    {
        try {
            $key = (string) config('hq.key');
            if ($key === '' || $events === []) {
                return new Delivery(0, error: 'no key or no events');
            }
            $url = rtrim((string) config('hq.url', 'https://hq.brigada.dev'), '/').self::PATH;
            $response = Http::withToken($key)
                ->withHeaders(['User-Agent' => 'brigada-hq-sdk/'.Hq::VERSION])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('hq.timeout', 5))
                ->connectTimeout((int) config('hq.timeout', 5))
                ->post($url, ['sdk' => ['name' => Hq::SDK_NAME, 'version' => Hq::VERSION], 'events' => array_values($events)]);

            $retryAfter = $response->header('Retry-After');
            $json = $response->successful() ? ($response->json() ?? []) : [];

            return new Delivery(
                $response->status(),
                is_numeric($retryAfter) ? max(0, min(60, (int) $retryAfter)) : null,
                (int) ($json['accepted'] ?? 0),
                (int) ($json['dropped'] ?? 0),
            );
        } catch (Throwable $e) {
            return new Delivery(0, error: get_class($e));
        }
    }
}
