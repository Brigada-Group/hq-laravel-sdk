<?php

namespace Brigada\Hq\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * The message of a database error, safe to send. Laravel's QueryException message inlines every binding into the SQL (a remember
 * token, an invite code, a request body inserted as is) and, from Laravel 12, names the database host, port and name. A name list
 * cannot see a value by its position, so the message is rebuilt from its parts: the driver's own message, the connection name and
 * the SQL as written, with a ? for each binding. The values a driver echoes back in its message are masked too. A wrapper whose message
 * repeats a wrapped QueryException's (a ViewException from a Blade or Livewire view) gets the safe one in its place. Any other
 * exception's message is returned unchanged (the Redactor runs on it next).
 */
final class QueryMessage
{
    /**
     * Driver messages that quote the value they refused: [pattern, replacement]. The same list as the JS SDK's ECHOES
     * (packages/hq-sdk/src/core/query-message.ts). Every pattern is linear (final review H2, M3, M4): a run either stops at a character
     * that cannot repeat it, is bounded ({1,64}), or always matches (a fallback to the end), so no start is scanned twice. A value cut off
     * by ECHO_LIMIT is masked to the cut (fail closed).
     */
    private const ECHOES = [
        // PostgreSQL: DETAIL:  Key (email, code)=(a@b.c, XYZ) already exists. A column may be an expression with its own parentheses
        // (a unique index on lower(code): Key (lower(code::text))=(...)), one level deep. Without a verb this knows: to the end of its line.
        ['~(\bKey \((?:[^()\n]|\([^()\n]*\))*\)=\()(?:[^\n]*?(\) (?:already exists|is not present in table|is not present|is still referenced|conflicts with))|[^\n]*)~', '$1'.Redactor::MASK.'$2'],
        // MySQL: Duplicate entry 'INV55' for key 'invites_code_unique' (cut off before its closing quote: to the end)
        ["~(\\bDuplicate entry ')(?:[^']|'')*(' for key|\\z)~", '$1'.Redactor::MASK.'$2'],
        // PostgreSQL: Failing row contains (5, null, TOKEN). A text value may hold newlines, so the row runs to the next field line
        // (HINT:  ...), a stack frame line, or the end of the message.
        ["~(\\bFailing row contains \\()(?s:.*?)(\\)\\.?)?(?=\\n(?:[A-Z]+:  | {4}at )|\\z)~", '$1'.Redactor::MASK.'$2'],
        // SQL Server: The duplicate key value is (TOK9). The same rule.
        ["~(\\bduplicate key value is \\()(?s:.*?)(\\)\\.?)?(?=\\n(?:[A-Z]+:  | {4}at )|\\z)~i", '$1'.Redactor::MASK.'$2'],
        // PostgreSQL: invalid input syntax for type uuid: "MISTYPED" (the type name is at most 64 characters)
        ['~(invalid input (?:syntax|value) for (?:type )?[\w ]{1,64}: )"[^"\n]*"?~', '$1'.Redactor::MASK],
        // MySQL: Incorrect integer value: 'TOKEN' for column ..., Truncated incorrect DOUBLE value: 'TOKEN'
        ["~(\\b(?:Incorrect|Truncated incorrect) [\\w ]{1,64} value: )'(?:[^']|'')*'?~", '$1'.Redactor::MASK],
    ];

    /**
     * How much of a driver message the patterns read, in bytes: more than is ever sent (4000 characters, at most 4 bytes each). The rest
     * becomes [redacted] before any pattern runs, so a 2 MB value Postgres echoes back costs a bounded amount (final review H2).
     */
    public const ECHO_LIMIT = 16384;

    /** How deep the chain of previous exceptions is searched for a QueryException. */
    private const MAX_DEPTH = 10;

    public static function of(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return self::rebuilt($e);
        }

        // A wrapper repeats the message of the QueryException it wraps: Blade and Livewire's ViewException is "<that message> (View:
        // ...)", and so is each enclosing view's. Wherever a wrapped QueryException's message appears, it is replaced by the safe one.
        $message = $e->getMessage();
        $depth = 0;
        for ($p = $e->getPrevious(); $p !== null && $depth++ < self::MAX_DEPTH; $p = $p->getPrevious()) {
            if ($p instanceof QueryException) {
                $raw = $p->getMessage();
                if ($raw !== '' && str_contains($message, $raw)) {
                    $message = str_replace($raw, self::rebuilt($p), $message);
                }
            }
        }

        return $message;
    }

    private static function rebuilt(QueryException $e): string
    {
        $message = $e->getMessage();
        if (! str_contains($message, 'SQL: ')) {
            return $message;
        }
        $previous = $e->getPrevious();
        $driver = $previous instanceof Throwable ? $previous->getMessage() : '';
        try {
            return self::maskEchoes($driver).' (Connection: '.$e->getConnectionName().', SQL: '.$e->getSql().')';
        } catch (Throwable) {
            return self::maskEchoes($driver);
        }
    }

    /** The driver's message with the values it echoes masked; [redacted] whole when a pattern cannot run. */
    public static function maskEchoes(string $message): string
    {
        if (strlen($message) > self::ECHO_LIMIT) {
            $message = mb_strcut($message, 0, self::ECHO_LIMIT, 'UTF-8').Redactor::MASK;
        }
        foreach (self::ECHOES as [$pattern, $replacement]) {
            $message = preg_replace($pattern, $replacement, $message);
            if ($message === null) {
                return Redactor::MASK;
            }
        }

        return $message;
    }
}
