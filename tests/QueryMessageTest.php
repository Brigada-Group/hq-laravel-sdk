<?php

namespace Brigada\Hq\Tests;

use Brigada\Hq\Support\EventBuilder;
use Brigada\Hq\Support\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\View\ViewException;
use PDOException;

/**
 * A QueryException's message inlines the query's bindings (a remember token, an invite code, a request body inserted as is) and
 * names the database host. The message sent is rebuilt from its parts with the SQL as written (? for each binding), and the values
 * a driver echoes back in its own error text are masked. Free text is redacted fail-closed.
 */
class QueryMessageTest extends TestCase
{
    private function messageOf(\Throwable $e): string
    {
        return EventBuilder::error($e)['error']['message'];
    }

    private function queryException(string $sql, array $bindings, string $driverMessage, array $details = []): QueryException
    {
        return new QueryException('mysql', $sql, $bindings, new PDOException($driverMessage), $details);
    }

    public function test_bindings_are_not_sent_and_the_sql_keeps_its_placeholders(): void
    {
        $m = $this->messageOf($this->queryException('select * from `users` where `remember_token` = ? limit 1', ['abcDEF123'], 'SQLSTATE[HY000]: General error'));

        $this->assertStringNotContainsString('abcDEF123', $m);
        $this->assertStringContainsString('where `remember_token` = ? limit 1', $m);
        $this->assertStringContainsString('SQLSTATE[HY000]: General error', $m);
        $this->assertStringContainsString('Connection: mysql', $m);
    }

    public function test_positional_bindings_of_an_insert_are_not_sent(): void
    {
        $m = $this->messageOf($this->queryException('insert into `invites` (`token`, `user_id`) values (?, ?)', ['zzTOK', 5], 'SQLSTATE[HY000]: General error'));

        $this->assertStringNotContainsString('zzTOK', $m);
        $this->assertStringContainsString('values (?, ?)', $m);
    }

    public function test_a_binding_that_looks_like_sql_is_not_sent(): void
    {
        $m = $this->messageOf($this->queryException('select * from `t` where `a` = ? and `b` = ?', ['q?SQL: x', 'LEAK7'], 'SQLSTATE[HY000]: General error'));

        $this->assertStringNotContainsString('LEAK7', $m);
        $this->assertStringNotContainsString('q?SQL', $m);
    }

    public function test_the_database_host_port_and_name_are_not_sent(): void
    {
        $e = $this->queryException('select 1', [], 'SQLSTATE[HY000] [2002] Connection refused', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306, 'database' => 'shopdb']);

        $m = $this->messageOf($e);

        $this->assertStringNotContainsString('10.0.0.5', $m);
        $this->assertStringNotContainsString('shopdb', $m);
        $this->assertStringContainsString('Connection refused', $m);
    }

    public function test_postgres_key_values_are_masked_and_the_verb_kept(): void
    {
        $m = $this->messageOf($this->queryException('insert into "invites" ("email", "code") values (?, ?)', ['a@b.c', 'XYZ'], "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"invites_email_code_unique\"\nDETAIL:  Key (email, code)=(a@b.c, XYZ987) already exists."));

        $this->assertStringNotContainsString('XYZ987', $m);
        $this->assertStringNotContainsString('a@b.c', $m);
        $this->assertStringContainsString('Key (email, code)=([redacted]) already exists', $m);
    }

    public function test_a_postgres_key_without_a_known_verb_is_masked_to_the_end_of_its_line(): void
    {
        $m = $this->messageOf($this->queryException('select 1', [], "SQLSTATE[23503]: ERROR\nDETAIL:  Key (code)=(SECRETK) is something new\nnext line"));

        $this->assertStringNotContainsString('SECRETK', $m);
        $this->assertStringContainsString('next line', $m);
    }

    public function test_mysql_duplicate_entry_is_masked_and_the_key_name_kept(): void
    {
        $m = $this->messageOf($this->queryException('insert into `invites` (`code`) values (?)', ['INV55'], "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'INV55' for key 'invites_code_unique'"));

        $this->assertStringNotContainsString('INV55', $m);
        $this->assertStringContainsString("Duplicate entry '[redacted]' for key 'invites_code_unique'", $m);
    }

    public function test_failing_row_and_sql_server_duplicate_key_values_are_masked(): void
    {
        $pg = $this->messageOf($this->queryException('insert into "t" values (?, ?, ?)', [5, null, 'TOKEN123'], 'SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "b"
DETAIL:  Failing row contains (5, null, TOKEN123).'));
        $sqlsrv = $this->messageOf($this->queryException('insert into [t] values (?)', ['TOK9'], "SQLSTATE[23000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Violation of UNIQUE KEY constraint 'u'. The duplicate key value is (TOK9)."));

        $this->assertStringNotContainsString('TOKEN123', $pg);
        $this->assertStringContainsString('Failing row contains ([redacted])', $pg);
        $this->assertStringNotContainsString('TOK9', $sqlsrv);
    }

    public function test_a_bad_value_echoed_by_postgres_or_mysql_is_masked(): void
    {
        $uuid = $this->messageOf($this->queryException('select * from "invites" where "code" = ?', ['MISTYPED42'], 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "MISTYPED42"'));
        $int = $this->messageOf($this->queryException('update `t` set `n` = ?', ['TOKEN77'], "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'TOKEN77' for column 'n' at row 1"));
        $dbl = $this->messageOf($this->queryException('select * from `t` where `n` = ?', ['TOKEN88'], "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'TOKEN88'"));

        $this->assertStringNotContainsString('MISTYPED42', $uuid);
        $this->assertStringContainsString('invalid input syntax for type uuid: [redacted]', $uuid);
        $this->assertStringNotContainsString('TOKEN77', $int);
        $this->assertStringContainsString("for column 'n'", $int);
        $this->assertStringNotContainsString('TOKEN88', $dbl);
    }

    public function test_a_query_that_fails_inside_a_blade_or_livewire_view_is_masked_too(): void
    {
        // CompilerEngine::handleViewException wraps it: new ViewException($e->getMessage().' (View: ...)', 0, 1, $file, $line, $e)
        $q = $this->queryException('select * from `invites` where `code` = ? limit 1', ['INV55XYZ'], 'SQLSTATE[HY000] [2002] Connection timed out', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306, 'database' => 'shopdb']);
        $view = new ViewException($q->getMessage().' (View: /var/www/resources/views/invites/show.blade.php)', 0, 1, __FILE__, __LINE__, $q);
        $outer = new ViewException($view->getMessage().' (View: /var/www/resources/views/layouts/app.blade.php)', 0, 1, __FILE__, __LINE__, $view);

        foreach ([$view, $outer] as $e) {
            $m = $this->messageOf($e);
            $this->assertStringNotContainsString('INV55XYZ', $m);
            $this->assertStringNotContainsString('10.0.0.5', $m);
            $this->assertStringNotContainsString('shopdb', $m);
            $this->assertStringContainsString('where `code` = ? limit 1', $m);
            $this->assertStringContainsString('(View: ', $m);
        }
    }

    public function test_a_wrapper_that_repeats_the_query_message_inside_its_own_is_masked(): void
    {
        $q = $this->queryException('update `users` set `remember_token` = ?', ['abcDEF123'], "SQLSTATE[23000]: Duplicate entry 'abcDEF123' for key 'u'");

        $m = $this->messageOf(new \RuntimeException('Import failed: '.$q->getMessage(), 0, $q));

        $this->assertStringNotContainsString('abcDEF123', $m);
        $this->assertStringStartsWith('Import failed: ', $m);
    }

    public function test_any_other_exception_message_is_redacted_exactly_as_before(): void
    {
        $text = 'failed for /users/42 token=abc and ann@example.com';

        $this->assertSame(Redactor::text($text), $this->messageOf(new \RuntimeException($text)));
    }

    public function test_a_custom_query_exception_message_without_sql_is_left_to_the_redactor(): void
    {
        $e = new class('mysql', 'select 1', [], new PDOException('x')) extends QueryException
        {
            public function __construct(...$args)
            {
                parent::__construct(...$args);
                $this->message = 'Custom failure token=abc';
            }
        };

        $this->assertSame('Custom failure token=[redacted]', $this->messageOf($e));
    }

    public function test_backtick_and_bracket_quoted_keys_are_masked(): void
    {
        $this->assertSame('where `password` = [redacted]', Redactor::text('where `password` = x'));
        $this->assertSame('where [token] = [redacted]', Redactor::text('where [token] = abc'));
        $this->assertSame('signature=[redacted] otp: [redacted]', Redactor::text('signature=abc otp: 123456'));
    }

    public function test_the_free_text_key_list_does_not_mask_ordinary_words(): void
    {
        $this->assertSame('Exit Code: 1(General error)', Redactor::text('Exit Code: 1(General error)'));
        $this->assertSame('footprint shipping otpless', Redactor::text('footprint shipping otpless'));
    }

    public function test_an_unterminated_quoted_value_is_masked_to_the_end_of_its_line(): void
    {
        $this->assertSame('invalid token: [redacted]', Redactor::text('invalid token: "abc'));
        $this->assertSame("bad token=[redacted]\nnext line stays", Redactor::text("bad token=\"x\nnext line stays"));
    }

    public function test_a_long_local_part_is_masked_whole(): void
    {
        $this->assertSame('[redacted]', Redactor::text(str_repeat('a', 100).'@example.com'));
        $this->assertSame('id 12345 [redacted] ok', Redactor::text('id 12345 '.str_repeat('b', 70).'@x.io ok'));
    }

    /** Final review H2: Postgres echoes a crafted value raw, and 'invalid input syntax for ' repeated made the pattern quadratic. */
    public function test_a_2_mb_crafted_driver_message_is_masked_within_100_ms(): void
    {
        $crafted = [
            // the posted value starts with a quote, so the echo's own quotes close at once and the rest is the repeated phrase
            'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type bigint: ""'.str_repeat('invalid input syntax for ', 84_000).'"',
            "SQLSTATE[HY000]: 1366 Incorrect integer value: 'x'".str_repeat('Incorrect integer ', 120_000)."' for column 'id' at row 1",
            'SQLSTATE[23505]: DETAIL:  '.str_repeat('Key (a)=(', 230_000),
            'SQLSTATE[23505]: DETAIL:  '.str_repeat('Key (lower(', 190_000),
            'SQLSTATE[23514]: DETAIL:  '.str_repeat('Failing row contains (', 95_000),
            "SQLSTATE[23000]: 1062 ".str_repeat("Duplicate entry '", 120_000),
        ];
        foreach ($crafted as $driver) {
            $this->assertGreaterThan(2_000_000, strlen($driver));
            $e = $this->queryException('select * from "t" where "id" = ?', ['x'], $driver);
            $t0 = hrtime(true);
            $m = $this->messageOf($e);
            $ms = (hrtime(true) - $t0) / 1e6;
            $this->assertLessThan(100, $ms, substr($driver, 0, 60).': '.round($ms).' ms');
            $this->assertLessThanOrEqual(4000, mb_strlen($m));
        }
    }

    public function test_only_the_head_of_a_long_driver_message_is_kept_and_the_rest_is_masked(): void
    {
        $driver = 'SQLSTATE[HY000]: General error: '.str_repeat('x', 20_000).' TAILSECRET';
        $out = \Brigada\Hq\Support\QueryMessage::maskEchoes($driver);
        $this->assertTrue(str_ends_with($out, Redactor::MASK), 'the tail is masked');
        $this->assertFalse(str_contains($out, 'TAILSECRET'), 'nothing past the limit is kept');
        $this->assertSame(\Brigada\Hq\Support\QueryMessage::ECHO_LIMIT + strlen(Redactor::MASK), strlen($out));
        // a cut never splits a multi-byte character
        $this->assertTrue(mb_check_encoding(\Brigada\Hq\Support\QueryMessage::maskEchoes(str_repeat('ë', 20_000)), 'UTF-8'));
    }

    /** Final review M3: a value with a newline in it ended the old pattern's line before its ')'. */
    public function test_a_failing_row_with_a_newline_in_a_value_is_masked(): void
    {
        $m = $this->messageOf($this->queryException('insert into "notes" values (?, ?)', [5, "line1\nline2 TOKSECRET"], "SQLSTATE[23514]: Check violation: 7 ERROR:  new row violates check constraint \"c\"\nDETAIL:  Failing row contains (5, line1\nline2 TOKSECRET, null)."));

        $this->assertStringNotContainsString('TOKSECRET', $m);
        $this->assertStringNotContainsString('line2', $m);
        $this->assertStringContainsString('Failing row contains ([redacted]).', $m);
        $this->assertStringContainsString('SQL: insert into "notes" values (?, ?)', $m);

        // a header line after it is kept; a row cut off before its ')' is masked to the end
        $this->assertSame("DETAIL:  Failing row contains ([redacted]).\nHINT:  see docs", \Brigada\Hq\Support\QueryMessage::maskEchoes("DETAIL:  Failing row contains (5, a\nb TOK).\nHINT:  see docs"));
        $this->assertSame('DETAIL:  Failing row contains ([redacted]', \Brigada\Hq\Support\QueryMessage::maskEchoes("DETAIL:  Failing row contains (5, a\nb TOK"));
        $this->assertSame("The duplicate key value is ([redacted]).", \Brigada\Hq\Support\QueryMessage::maskEchoes("The duplicate key value is (a\nTOK9)."));
    }

    /** Final review M4: a unique index on an expression names it with its own parentheses. */
    public function test_a_unique_violation_on_an_expression_index_is_masked(): void
    {
        $m = $this->messageOf($this->queryException('insert into "invites" ("code") values (?)', ['INV55SECRET'], "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"invites_lower_code_unique\"\nDETAIL:  Key (lower(code::text))=(inv55secret) already exists."));

        $this->assertStringNotContainsString('inv55secret', $m);
        $this->assertStringContainsString('Key (lower(code::text))=([redacted]) already exists.', $m);
        $this->assertSame('Key (lower(a), b)=([redacted]', \Brigada\Hq\Support\QueryMessage::maskEchoes('Key (lower(a), b)=(X, Y) is new'));
    }

    public function test_when_a_pattern_fails_the_whole_text_is_masked(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '2');
            $this->assertSame(Redactor::MASK, Redactor::text('user '.str_repeat('a', 5000).' mail ann@example.com password=hunter2'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }
}
