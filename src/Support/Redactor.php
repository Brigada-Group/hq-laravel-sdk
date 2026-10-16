<?php

namespace Brigada\Hq\Support;

/**
 * Redaction, from Guardian's StackTraceSanitizer: the base path is stripped, Bearer tokens and the values after
 * password/token/secret/api_key/authorization/credential keys become [redacted], so do e-mail addresses, and URLs lose
 * their query string and fragment. HQ scrubs again on arrival; this is the first line.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    private const URL = '~\b(?:https?|wss?)://[^\s"\'<>)\]]+~i';

    private const SECRET_KEYS = 'password|passwd|pwd|token|secret|api[_-]?key|apikey|authorization|credential';

    private const VALUE = '"[^"]*"|\'[^\']*\'|(?:Bearer|Basic)\s+[^\s,;&)}\]]+|[^\s,;&)}\]"\']+';

    private const BEARER = '~\bBearer\s+[A-Za-z0-9._\~+/=-]+~i';

    private const EMAIL = '~[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}~';

    public static function text(string $text, ?string $basePath = null): string
    {
        if ($text === '') {
            return '';
        }

        $base = $basePath ?? self::basePath();
        if ($base !== '' && $base !== '/') {
            $text = str_replace([$base.'/', $base], '', $text);
        }

        $keyPattern = '~((?:'.self::SECRET_KEYS.')s?["\']?\s*[:=]\s*)('.self::VALUE.')~i';

        $text = preg_replace_callback(self::URL, fn (array $m) => self::stripUrl($m[0]), $text) ?? $text;
        $text = preg_replace($keyPattern, '$1'.self::MASK, $text) ?? $text;
        $text = preg_replace(self::BEARER, 'Bearer '.self::MASK, $text) ?? $text;
        $text = preg_replace(self::EMAIL, self::MASK, $text) ?? $text;

        return $text;
    }

    /** Drops the query string and the fragment. */
    public static function stripUrl(string $url): string
    {
        return preg_replace('~[?#].*$~s', '', $url) ?? $url;
    }

    /** A file path relative to the app root. */
    public static function relativePath(string $file, ?string $basePath = null): string
    {
        $base = $basePath ?? self::basePath();

        return ($base !== '' && str_starts_with($file, $base.'/')) ? substr($file, strlen($base) + 1) : $file;
    }

    private static function basePath(): string
    {
        try {
            return rtrim(base_path(), '/');
        } catch (\Throwable) {
            return '';
        }
    }
}
