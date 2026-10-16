<?php

namespace Brigada\Hq\Support;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Builds protocol v1 events (camelCase; frames innermost first). */
final class EventBuilder
{
    public const MAX_FRAMES = 100;

    /** @return array<string, mixed> */
    public static function base(string $type): array
    {
        $release = config('hq.release') ?: Release::fromGit(base_path());
        $event = [
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => (string) (config('hq.environment') ?: config('app.env', 'production')),
            'runtime' => 'php',
            'runtimeVersion' => PHP_VERSION,
            'framework' => 'laravel',
            'frameworkVersion' => mb_substr((string) app()->version(), 0, 40),
        ];
        if ($release) {
            $event['release'] = mb_substr((string) $release, 0, 100);
        }
        $host = gethostname();
        if ($host) {
            $event['server'] = mb_substr($host, 0, 255);
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public static function heartbeat(): array
    {
        return self::base('heartbeat');
    }

    /** @return array<string, mixed> */
    public static function error(Throwable $e, ?int $repeats = null): array
    {
        $event = self::base('error');
        $event['error'] = [
            'class' => mb_substr(get_class($e), 0, 255),
            'message' => mb_substr(Redactor::text($e->getMessage()), 0, 4000),
            'frames' => self::frames($e),
            'handled' => false,
        ];

        if ($request = self::request($e)) {
            $event['request'] = $request;
        }
        if (($user = self::userId()) !== null) {
            $event['user'] = ['id' => $user];
        }
        $event['traceId'] = self::traceId();
        if ($repeats !== null && $repeats > 0) {
            $event['tags'] = ['repeats' => (string) $repeats];
        }

        return $event;
    }

    /**
     * Innermost first. Frame 0 is the throw site; frame k is where the function of frame k-1 was called from, and its function
     * is the one that contains that call.
     *
     * @return list<array<string, mixed>>
     */
    public static function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        $base = rtrim(base_path(), '/');
        $frames = [];
        $n = count($trace);
        for ($k = 0; $k <= $n && count($frames) < self::MAX_FRAMES; $k++) {
            $file = $k === 0 ? $e->getFile() : ($trace[$k - 1]['file'] ?? null);
            $line = $k === 0 ? $e->getLine() : ($trace[$k - 1]['line'] ?? null);
            $fn = null;
            if ($k < $n) {
                $t = $trace[$k];
                $fn = isset($t['class']) ? $t['class'].'::'.$t['function'] : $t['function'];
            }
            $frame = [];
            if (is_string($file) && $file !== '') {
                $frame['file'] = mb_substr(Redactor::relativePath($file, $base), 0, 500);
                $frame['inApp'] = str_starts_with($file, $base.'/') && ! str_contains($file, '/vendor/');
            }
            if (is_int($line) && $line >= 0) {
                $frame['line'] = $line;
            }
            if ($fn !== null) {
                $frame['function'] = mb_substr(Redactor::text($fn, $base), 0, 255);
            }
            if ($frame !== []) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /** @return array<string, mixed>|null */
    private static function request(Throwable $e): ?array
    {
        try {
            if (app()->runningInConsole() || ! app()->bound('request')) {
                return null;
            }
            $request = app('request');
            $req = [
                'method' => mb_substr($request->method(), 0, 10),
                'url' => mb_substr(Redactor::text(Redactor::stripUrl($request->url())), 0, 2048),
            ];
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'uri')) {
                $req['route'] = mb_substr((string) $route->uri(), 0, 255);
            }
            $req['status'] = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return $req;
        } catch (Throwable) {
            return null;
        }
    }

    private static function userId(): string|int|null
    {
        try {
            if (app()->runningInConsole()) {
                return null;
            }
            $id = auth()->id();
            if (is_int($id)) {
                return $id;
            }
            if (is_string($id) && $id !== '') {
                return mb_substr($id, 0, 64);
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function traceId(): string
    {
        try {
            if (app()->bound('request')) {
                $request = app('request');
                foreach (['X-Request-Id', 'X-Correlation-Id', 'X-Trace-Id', 'CF-Ray'] as $h) {
                    $v = $request->headers->get($h);
                    if (is_string($v) && $v !== '') {
                        return mb_substr($v, 0, 128);
                    }
                }
            }
        } catch (Throwable) {
        }

        return bin2hex(random_bytes(16));
    }
}
