<?php

declare(strict_types=1);

namespace Tests\Unit\DataCore;

use App\Services\Connectors\Sql\SqlHostPolicy;
use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Política de solo lectura de las consultas autorizadas (ADR-V2-13): una sola sentencia SELECT, sin escritura ni efectos. */
final class SqlQueryGuardTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function acceptedQueries(): array
    {
        return [
            'select simple' => ['pgsql', 'SELECT a, b FROM t WHERE x >= :since'],
            'punto y coma final' => ['pgsql', 'SELECT 1 FROM t;'],
            'varios punto y coma finales y comentario' => ['pgsql', "SELECT 1 FROM t ; ; -- fin\n"],
            'cte' => ['pgsql', 'WITH x AS (SELECT id FROM t) SELECT * FROM x'],
            'cadena con palabras peligrosas' => ['pgsql', "SELECT 'DROP TABLE t; --' AS nota, \"update\" FROM t"],
            'identificador reservado entrecomillado' => ['pgsql', 'SELECT "delete", "set" FROM "do"'],
            'comentario con palabras peligrosas' => ['pgsql', "SELECT 1 /* DELETE FROM t; */ FROM t -- UPDATE x\n"],
            'replace como función' => ['pgsql', "SELECT replace(a, 'x', 'y') FROM t"],
            'cast de postgres' => ['pgsql', 'SELECT a::date FROM t WHERE b::int > 3'],
            'comentario anidado de postgres' => ['pgsql', '/* x /* anidado */ drop table t */ SELECT 1'],
            'mysql con backticks' => ['mysql', 'SELECT `update`, `set` FROM `t` WHERE f >= :since'],
            'mysql con comentario #' => ['mysql', "SELECT 1 # nota; DROP TABLE t\n FROM t"],
            'sql server con corchetes y offset' => ['sqlsrv', 'SELECT [update], b FROM [dbo].[t] ORDER BY 1 OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY'],
            'palabra que contiene una reservada' => ['pgsql', 'SELECT created_at, updated_at, deleted_by, settings FROM t'],
        ];
    }

    #[DataProvider('acceptedQueries')]
    public function test_accepts_read_only_queries(string $dialect, string $sql): void
    {
        $validated = SqlQueryGuard::validate($sql, $dialect);
        $this->assertNotSame('', $validated->render('0'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function rejectedQueries(): array
    {
        return [
            'vacía' => ['pgsql', '   ', 'empty'],
            'update' => ['pgsql', 'UPDATE t SET a = 1', 'not_select'],
            'delete' => ['pgsql', 'DELETE FROM t', 'not_select'],
            'insert' => ['pgsql', 'INSERT INTO t VALUES (1)', 'not_select'],
            'ddl' => ['pgsql', 'CREATE TABLE x AS SELECT 1', 'not_select'],
            'otra sentencia' => ['pgsql', 'SELECT 1; DROP TABLE t', 'multi_statement'],
            'otra sentencia tras comentario' => ['pgsql', "SELECT 1 -- x\n; DELETE FROM t", 'multi_statement'],
            'cte de escritura' => ['pgsql', 'WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d', 'forbidden_keyword'],
            'cte con insert' => ['pgsql', 'WITH i AS (INSERT INTO t VALUES (1) RETURNING *) SELECT * FROM i', 'forbidden_keyword'],
            'select into' => ['pgsql', 'SELECT * INTO nueva FROM t', 'forbidden_keyword'],
            'select into outfile (mysql)' => ['mysql', "SELECT * FROM t INTO OUTFILE '/tmp/x'", 'forbidden_keyword'],
            'bloqueo de filas' => ['pgsql', 'SELECT * FROM t FOR UPDATE', 'forbidden_keyword'],
            'bloqueo compartido' => ['pgsql', 'SELECT * FROM t FOR SHARE', 'forbidden_keyword'],
            'lock in share mode' => ['mysql', 'SELECT * FROM t LOCK IN SHARE MODE', 'forbidden_keyword'],
            'sql server apilado sin punto y coma' => ['sqlsrv', 'SELECT 1 DROP TABLE t', 'forbidden_keyword'],
            'sql server exec' => ['sqlsrv', "SELECT 1 EXEC xp_cmdshell 'dir'", 'forbidden_keyword'],
            'sql server waitfor' => ['sqlsrv', "SELECT 1 WAITFOR DELAY '00:00:10'", 'forbidden_keyword'],
            'sql server openrowset' => ['sqlsrv', "SELECT * FROM OPENROWSET('SQLNCLI', 'x', 'SELECT 1')", 'forbidden_keyword'],
            'función de reposo' => ['pgsql', 'SELECT pg_sleep(30)', 'system_object'],
            'sleep mysql' => ['mysql', 'SELECT SLEEP(30)', 'forbidden_function'],
            'lectura de archivo' => ['pgsql', "SELECT pg_read_file('/etc/passwd')", 'system_object'],
            'load_file' => ['mysql', "SELECT LOAD_FILE('/etc/passwd')", 'forbidden_function'],
            'catálogo de credenciales' => ['pgsql', 'SELECT * FROM pg_shadow', 'system_object'],
            'catálogo entrecomillado' => ['pgsql', 'SELECT * FROM "pg_shadow"', 'system_object'],
            'information_schema' => ['pgsql', 'SELECT * FROM information_schema.tables', 'system_object'],
            'esquema sys' => ['sqlsrv', 'SELECT * FROM sys.sql_logins', 'system_object'],
            'mysql.user' => ['mysql', 'SELECT * FROM mysql.user', 'system_object'],
            'dblink' => ['pgsql', "SELECT * FROM dblink('host=x', 'DELETE FROM t')", 'forbidden_function'],
            'nextval' => ['pgsql', "SELECT nextval('s')", 'forbidden_function'],
            'set_config' => ['pgsql', "SELECT set_config('x', 'y', false)", 'forbidden_function'],
            'escape con barra invertida' => ['pgsql', "SELECT 'a\\' ; DROP TABLE t; --'", 'backslash'],
            'dollar quoting' => ['pgsql', 'SELECT $$ ; DROP TABLE t $$', 'invalid_character'],
            'parámetro posicional' => ['pgsql', 'SELECT * FROM t WHERE a = $1', 'invalid_character'],
            'parámetro interrogante' => ['mysql', 'SELECT * FROM t WHERE a = ?', 'invalid_character'],
            'variable de usuario' => ['mysql', 'SELECT @a := 1', 'invalid_character'],
            'comentario ejecutable mysql' => ['mysql', 'SELECT /*!50000 1 */ 1', 'executable_comment'],
            'otro parámetro nombrado' => ['pgsql', 'SELECT * FROM t WHERE a = :otro', 'parameter'],
            'cadena sin cerrar' => ['pgsql', "SELECT 'abc", 'unterminated'],
            'comentario sin cerrar' => ['pgsql', 'SELECT 1 /* abc', 'unterminated'],
            'paréntesis que rompe el envoltorio' => ['pgsql', 'SELECT 1) t UNION ALL SELECT secreto FROM otra WHERE (1=1', 'unbalanced'],
            'tabla temporal sql server' => ['sqlsrv', 'SELECT * FROM #tmp', 'invalid_character'],
            'demasiado larga' => ['pgsql', 'SELECT '.str_repeat('1,', SqlQueryGuard::MAX_LENGTH).'1', 'too_long'],
        ];
    }

    #[DataProvider('rejectedQueries')]
    public function test_rejects_everything_that_is_not_a_single_read_only_select(string $dialect, string $sql, string $reason): void
    {
        try {
            SqlQueryGuard::validate($sql, $dialect);
            $this->fail('La consulta debió rechazarse: '.mb_substr($sql, 0, 80));
        } catch (SqlQueryRejected $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_what_is_executed_is_what_was_analyzed(): void
    {
        $validated = SqlQueryGuard::validate("SELECT a /* DELETE FROM t */, 'x;y' AS s FROM t WHERE f >= :since -- DROP\n;", 'pgsql');
        $this->assertTrue($validated->usesSince);
        $sql = $validated->render("'2026-01-01 00:00:00'");
        $this->assertStringNotContainsString('DELETE', $sql);
        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringContainsString("'x;y'", $sql, 'las cadenas se conservan intactas');
        $this->assertStringContainsString("f >= '2026-01-01 00:00:00'", $sql);
        $this->assertStringEndsNotWith(';', $sql);
    }

    public function test_since_is_required_only_when_the_query_uses_it(): void
    {
        $this->assertSame('SELECT 1', SqlQueryGuard::validate('SELECT 1', 'pgsql')->render());
        $this->expectException(SqlQueryRejected::class);
        SqlQueryGuard::validate('SELECT 1 FROM t WHERE a > :since', 'pgsql')->render();
    }

    public function test_host_policy_blocks_cloud_metadata_and_optionally_private_networks(): void
    {
        $this->expectException(SqlSourceException::class);
        SqlHostPolicy::assertAllowed('169.254.169.254');
    }

    public function test_host_policy_can_deny_private_networks_and_enforce_an_allow_list(): void
    {
        config(['connectors.sql.deny_private_networks' => true]);
        try {
            SqlHostPolicy::assertAllowed('10.0.0.5');
            $this->fail('Debía rechazar la red privada.');
        } catch (SqlSourceException) {
            $this->addToAssertionCount(1);
        }
        config(['connectors.sql.deny_private_networks' => false, 'connectors.sql.allowed_hosts' => ['erp.cliente.example']]);
        $this->expectException(SqlSourceException::class);
        SqlHostPolicy::assertAllowed('otro.example');
    }

    public function test_error_messages_never_carry_credentials(): void
    {
        $e = new \PDOException('connection failed: password=secreto123 user=u clave secreto123');
        $message = SqlSourceConnection::safeMessage($e, ['secreto123', 'u']);
        $this->assertStringNotContainsString('secreto123', $message);
    }
}
