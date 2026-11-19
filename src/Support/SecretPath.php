<?php

namespace Brigada\Hq\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * The request URL to send, without its query string and with the route parameters that look like a secret masked: a path can
 * carry a password reset token, an invite or join code, an e-mail verification hash. The route pattern is sent as it is.
 *
 * The rules are the same as the JS SDK's (packages/hq-sdk/src/core/route-mask.ts): a parameter whose name contains one of the
 * names (or whose implicit binding field does: {invitation:token}) is masked; a parameter that can span several segments (its
 * where() allows "/", as Route::fallback's does, or its value holds one) is masked whole whatever its name, because the server
 * cannot know what an SPA shell or a catch-all carries; when the route is not known yet (an error before routing) it is matched
 * again, and failing that only the root is sent.
 */
final class SecretPath
{
    /** A parameter is a secret when its name contains one of these, ignoring case (joinCode, reset_token, apiKey). */
    public const DEFAULT_NAMES = ['token', 'code', 'hash', 'signature', 'secret', 'key', 'password'];

    private const PLACEHOLDER = '/\{([^}\/?]+)\??\}/';

    /** The URL to send, or null when it cannot be made safe: better no URL than one with a secret in it. */
    public static function url(Request $request): ?string
    {
        try {
            $route = self::routeFor($request);
            if (! $route instanceof Route) {
                return rtrim($request->root(), '/');
            }
            $uri = $route->uri();
            $names = self::names();
            // A parameter bound by a secret field ({invitation:token}) is a secret too: Laravel keeps the field out of uri().
            $secret = array_values(array_filter(
                $route->parameterNames(),
                fn (string $name): bool => self::isSecret($name, $names) || self::isSecret((string) $route->bindingFieldFor($name), $names),
            ));
            preg_match_all(self::PLACEHOLDER, $uri, $placeholders);
            foreach ($placeholders[1] as $name) {
                if (! in_array($name, $secret, true) && self::spansSegments($route, $name)) {
                    $secret[] = $name;
                }
            }
            if ($secret === []) {
                return Redactor::stripUrl($request->url());
            }

            foreach ($secret as $name) {
                // A secret outside the path (a domain parameter) would stay in the URL: send none.
                if (! in_array($name, $placeholders[1], true)) {
                    return null;
                }
            }

            $path = preg_replace_callback(
                self::PLACEHOLDER,
                function (array $m) use ($route, $secret): string {
                    $value = (string) $route->originalParameter($m[1], '');
                    if ($value === '') {
                        return '';
                    }

                    return in_array($m[1], $secret, true) ? Redactor::MASK : str_replace('%2F', '/', rawurlencode($value));
                },
                $uri,
            );
            if (! is_string($path)) {
                return null;
            }
            // An absent optional parameter (always the last) leaves a trailing slash behind.
            $path = trim($path, '/');

            return rtrim($request->root(), '/').($path === '' ? '' : '/'.$path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The request's route, or, for an error thrown before routing (a global middleware), the route it would have matched. Null
     * when none matches or matching fails.
     */
    private static function routeFor(Request $request): ?Route
    {
        $route = $request->route();
        if ($route instanceof Route) {
            return $route;
        }
        try {
            $matched = app('router')->getRoutes()->match($request);

            return $matched instanceof Route ? $matched : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True when the parameter can hold several path segments: its where() pattern (or a global Route::pattern(), which the router
     * copies into it) matches a value with a "/" (tested the way Symfony compiles it; a pattern that does not compile counts), or
     * its value holds one.
     */
    private static function spansSegments(Route $route, string $name): bool
    {
        $pattern = $route->wheres[$name] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            foreach (['a/b', '1/2', 'a1/b2'] as $probe) {
                $hit = @preg_match('{^(?:'.$pattern.')$}sDu', $probe);
                if ($hit === false || $hit === 1) {
                    return true;
                }
            }
        }

        return str_contains((string) $route->originalParameter($name, ''), '/');
    }

    /** @param list<string> $names */
    public static function isSecret(string $parameter, array $names): bool
    {
        $parameter = strtolower($parameter);
        foreach ($names as $name) {
            if ($name !== '' && str_contains($parameter, strtolower($name))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function names(): array
    {
        $names = config('hq.secret_route_parameters');
        if (! is_array($names)) {
            return self::DEFAULT_NAMES;
        }

        return array_values(array_filter($names, 'is_string'));
    }
}
