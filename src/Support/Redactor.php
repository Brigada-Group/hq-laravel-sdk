<?php

namespace Brigada\Hq\Support;

/**
 * Redaction, from Guardian's StackTraceSanitizer: the base path is stripped, Bearer tokens and the values after
 * password/token/secret/signature/otp/api_key/authorization/credential keys become [redacted], so do e-mail addresses, and URLs
 * lose their query string and fragment. HQ scrubs again on arrival; this is the first line.
 *
 * The key list and the quoting it accepts are the same as the JS SDK's (packages/hq-sdk/src/core/redact.ts). It fails closed: when a
 * pattern cannot run (the PCRE backtrack or JIT stack limit, bad UTF-8), the whole text becomes [redacted] instead of going out as is.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    private const URL = '~\b(?:https?|wss?)://[^\s"\'<>)\]]+~i';

    // The user and password of any URL (postgres://app:pw@db, redis://:pw@cache), whatever the scheme and the host.
    private const USERINFO = '~\b([a-z][a-z0-9+.\-]{0,31}://)[^\s/@"\'<>]+@~i';

    private const SECRET_KEYS = 'password|passwd|pwd|token|secret|signature|otp|api[_-]?key|apikey|authorization|credential';

    // A key may be quoted ("token", `password` in MySQL, [token] in SQL Server). A quoted value runs to its closing quote, or to
    // the end of its line when the quote is never closed.
    private const KEY_QUOTE = '["\'`\]]?';

    // Between the key and its value: ':' or '=' (key=value, JSON, YAML) or '=>' (print_r, var_export, a Node Map's inspect). '=>' is tried
    // first: [:=] alone took its '=' and masked only the '>', so the value went out (final review H1). Linear: a fixed token between
    // two runs of spaces. The same string as the JS SDK's KEY_SEPARATOR (a contract test reads it).
    private const KEY_SEPARATOR = '\s*(?:=>|[:=])\s*';

    // A value already [redacted] is taken whole (not masked again as "[redacted]]"). A ? followed by a delimiter is a query placeholder
    // (Laravel's SQL as written), not a value.
    private const VALUE = '\[redacted\](?![^\s,;&)}\]"\'])|"[^"\n]*(?:"|(?=\n)|\z)|\'[^\'\n]*(?:\'|(?=\n)|\z)|(?:Bearer|Basic)\s+[^\s,;&)}\]]+|(?!\?[\s,;&)}\]])[^\s,;&)}\]"\']+';

    private const BEARER = '~\bBearer\s+[A-Za-z0-9._\~+/=-]+~i';

    // The local part is bounded at 64 (its RFC limit): unbounded, the pattern is quadratic on a long run of letters and digits. A
    // longer run before the "@" is taken whole by extending the match to the left.
    private const EMAIL = '~[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}~';

    private const LOCAL_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._%+-';

    public static function text(string $text, ?string $basePath = null): string
    {
        if ($text === '') {
            return '';
        }

        $base = $basePath ?? self::basePath();
        if ($base !== '' && $base !== '/') {
            // Only where the base path starts a path: not inside a URL (https://shop.test/app/x), a longer word (/application) or a
            // longer path. ':' is not a boundary on purpose, so a PHP 8.4 closure name {closure:/app/routes/web.php:12} is still stripped.
            $text = preg_replace('~(?<![\w.\-/\]])'.preg_quote($base, '~').'(?:/|(?![\w.\-]))~', '', $text);
            if ($text === null) {
                return self::MASK;
            }
        }

        $keyPattern = '~((?:'.self::SECRET_KEYS.')s?'.self::KEY_QUOTE.self::KEY_SEPARATOR.')('.self::VALUE.')~i';

        $text = preg_replace(self::USERINFO, '$1'.self::MASK.'@', $text);
        $text = $text === null ? null : preg_replace_callback(self::URL, fn (array $m) => self::stripUrl($m[0]), $text);
        $text = $text === null ? null : preg_replace($keyPattern, '$1'.self::MASK, $text);
        $text = $text === null ? null : preg_replace(self::BEARER, 'Bearer '.self::MASK, $text);
        $text = $text === null ? null : self::emails($text);

        return $text ?? self::MASK;
    }

    /** E-mail addresses become [redacted], each match extended to the left over the rest of its local part. Null when PCRE fails. */
    private static function emails(string $text): ?string
    {
        if (preg_match_all(self::EMAIL, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $out = '';
        $last = 0;
        foreach ($matches[0] as [$found, $at]) {
            $start = $at;
            while ($start > $last && str_contains(self::LOCAL_CHARS, $text[$start - 1])) {
                $start--;
            }
            $out .= substr($text, $last, $start - $last).self::MASK;
            $last = $at + strlen($found);
        }

        return $out.substr($text, $last);
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
