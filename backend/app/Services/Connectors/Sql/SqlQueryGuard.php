<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Política de solo lectura para las consultas autorizadas de una fuente SQL (ADR-V2-13).
 *
 * Acepta exactamente UNA sentencia SELECT (o WITH ... SELECT), sin escritura, sin DDL, sin DCL, sin transacciones,
 * sin procedimientos, sin `SELECT ... INTO`, sin bloqueos de filas y con un único parámetro nombrado: `:since`.
 * Analiza léxicamente (cadenas, identificadores entrecomillados y comentarios se aíslan antes de buscar palabras
 * peligrosas) y devuelve el texto SIN comentarios: lo que se ejecuta es lo que se analizó.
 *
 * Es una defensa en profundidad, NO sustituye a un usuario de base de datos con permisos de solo lectura:
 * la conexión además abre una transacción READ ONLY (PostgreSQL, MySQL/MariaDB). SQL Server no tiene ese modo;
 * allí el usuario debe pertenecer solo a db_datareader (la prueba de conexión avisa si puede escribir).
 *
 * Reglas que evitan ambigüedades entre el analizador y el motor de la base de datos:
 *  - se rechaza la barra invertida en cualquier posición (los escapes `\'` difieren entre motores);
 *  - se rechazan `$` (dólar-quoting y parámetros posicionales), `?`, `@` y `#` (salvo comentario en MySQL);
 *  - se rechazan los comentarios ejecutables de MySQL/MariaDB (`/*!`, `/*M!`).
 */
final class SqlQueryGuard
{
    public const MAX_LENGTH = 20_000;

    private const SINCE_PARAM = 'since';

    /** Palabras que nunca pueden aparecer fuera de cadenas e identificadores entrecomillados. */
    private const FORBIDDEN_WORDS = [
        'insert', 'update', 'delete', 'merge', 'upsert', 'drop', 'alter', 'create', 'truncate', 'rename',
        'grant', 'revoke', 'call', 'exec', 'execute', 'copy', 'do', 'set', 'reset', 'begin', 'commit', 'rollback',
        'savepoint', 'vacuum', 'analyze', 'reindex', 'notify', 'listen', 'unlisten', 'prepare', 'deallocate',
        'declare', 'lock', 'unlock', 'kill', 'shutdown', 'backup', 'restore', 'waitfor', 'bulk', 'dbcc', 'into',
        'share', 'load', 'handler', 'use', 'outfile', 'dumpfile',
    ];

    /** SQL Server admite sentencias apiladas sin `;`: se vetan también estas. */
    private const SQLSERVER_EXTRA_WORDS = [
        'deny', 'disable', 'enable', 'setuser', 'revert', 'checkpoint', 'reconfigure', 'print', 'raiserror', 'throw',
        'goto', 'while', 'readtext', 'writetext', 'updatetext', 'openrowset', 'openquery', 'opendatasource', 'openxml',
    ];

    /** Funciones con efectos laterales, acceso a archivos/red o ejecución de texto como SQL. */
    private const FORBIDDEN_FUNCTIONS = [
        'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks', 'is_free_lock', 'load_file',
        'sys_exec', 'sys_eval', 'set_config', 'nextval', 'setval', 'dblink', 'dblink_exec', 'dblink_connect',
        'query_to_xml', 'table_to_xml', 'schema_to_xml', 'database_to_xml', 'cursor_to_xml', 'query_to_xml_and_xmlschema',
        'lo_import', 'lo_export', 'lo_create', 'lo_unlink', 'lo_put', 'lo_from_bytea', 'lo_open', 'lo_creat',
    ];

    /** Prefijos de funciones/objetos de sistema (el prefijo basta, con o sin paréntesis). */
    private const FORBIDDEN_PREFIXES = ['pg_', 'xp_', 'sp_', 'fn_'];

    /** Catálogos de sistema: credenciales, definiciones y metadatos de otros esquemas. */
    private const SYSTEM_NAMES = [
        'information_schema', 'performance_schema', 'sysobjects', 'syscolumns', 'sysusers', 'syslogins', 'sql_logins',
        'sysdatabases', 'sysprocesses', 'syscomments',
    ];

    /** `sys.tabla`, `mysql.user`, `master.dbo.x` ... */
    private const SYSTEM_QUALIFIERS = ['sys', 'mysql', 'master', 'msdb', 'tempdb', 'model'];

    /**
     * @param  string  $dialect  pgsql | mysql | sqlsrv
     *
     * @throws SqlQueryRejected
     */
    public static function validate(string $sql, string $dialect): ValidatedQuery
    {
        if (trim($sql) === '') {
            throw new SqlQueryRejected('La consulta está vacía.', 'empty');
        }
        if (strlen($sql) > self::MAX_LENGTH) {
            throw new SqlQueryRejected('La consulta supera los '.self::MAX_LENGTH.' caracteres permitidos.', 'too_long');
        }
        if (! mb_check_encoding($sql, 'UTF-8')) {
            throw new SqlQueryRejected('La consulta debe estar en UTF-8.', 'encoding');
        }
        if (str_contains($sql, "\0")) {
            throw new SqlQueryRejected('La consulta contiene caracteres no permitidos.', 'invalid_character');
        }
        if (str_contains($sql, '\\')) {
            throw new SqlQueryRejected('No se permite la barra invertida en la consulta (los escapes difieren entre motores de base de datos).', 'backslash');
        }

        $tokens = self::tokenize($sql, $dialect);

        // Descarta los ';' finales (con sus espacios/comentarios); cualquier otro ';' es una segunda sentencia.
        $end = count($tokens);
        while ($end > 0) {
            $last = $tokens[$end - 1];
            if ($last['t'] === 'ws' || $last['t'] === 'comment' || ($last['t'] === 'symbol' && $last['v'] === ';')) {
                $end--;

                continue;
            }
            break;
        }
        $tokens = array_slice($tokens, 0, $end);

        $significant = array_values(array_filter($tokens, fn (array $t) => $t['t'] !== 'ws' && $t['t'] !== 'comment'));
        if ($significant === []) {
            throw new SqlQueryRejected('La consulta está vacía.', 'empty');
        }
        foreach ($significant as $token) {
            if ($token['t'] === 'symbol' && $token['v'] === ';') {
                throw new SqlQueryRejected('Solo se permite una sentencia: se detectó más de una (";").', 'multi_statement');
            }
        }
        $first = $significant[0];
        if ($first['t'] !== 'word' || ! in_array(strtolower($first['v']), ['select', 'with'], true)) {
            throw new SqlQueryRejected('Solo se permiten consultas SELECT (o WITH ... SELECT).', 'not_select');
        }

        $depth = 0;
        $usesSince = false;
        $forbidden = $dialect === 'sqlsrv' ? [...self::FORBIDDEN_WORDS, ...self::SQLSERVER_EXTRA_WORDS] : self::FORBIDDEN_WORDS;
        foreach ($significant as $i => $token) {
            $next = $significant[$i + 1] ?? null;
            if ($token['t'] === 'symbol') {
                if ($token['v'] === '(') {
                    $depth++;
                } elseif ($token['v'] === ')' && --$depth < 0) {
                    throw new SqlQueryRejected('Paréntesis desbalanceados en la consulta.', 'unbalanced');
                }

                continue;
            }
            if ($token['t'] === 'param') {
                if ($token['name'] !== self::SINCE_PARAM) {
                    throw new SqlQueryRejected("Parámetro no permitido :{$token['name']}. El único parámetro disponible es :since.", 'parameter');
                }
                $usesSince = true;

                continue;
            }
            if ($token['t'] === 'ident') {
                self::assertNotSystemObject(strtolower($token['name']), $next);

                continue;
            }
            if ($token['t'] !== 'word') {
                continue;
            }
            $word = strtolower($token['v']);
            if (in_array($word, $forbidden, true)) {
                throw new SqlQueryRejected('La consulta contiene la palabra «'.strtoupper($word).'», no permitida en consultas de solo lectura.', 'forbidden_keyword');
            }
            $isCall = $next !== null && $next['t'] === 'symbol' && $next['v'] === '(';
            if ($word === 'replace' && ! $isCall) {
                throw new SqlQueryRejected('«REPLACE» solo se permite como función REPLACE(...).', 'forbidden_keyword');
            }
            if ($isCall && in_array($word, self::FORBIDDEN_FUNCTIONS, true)) {
                throw new SqlQueryRejected("La función {$word}() no está permitida en consultas de solo lectura.", 'forbidden_function');
            }
            self::assertNotSystemObject($word, $next);
        }
        if ($depth !== 0) {
            throw new SqlQueryRejected('Paréntesis desbalanceados en la consulta.', 'unbalanced');
        }

        // Texto sin comentarios; :since queda como marcador.
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = match ($token['t']) {
                'comment' => ' ',
                'param' => null,
                default => $token['v'],
            };
        }

        return new ValidatedQuery(self::compact($parts), $usesSince);
    }

    /** @param array{t: string, v: string}|null $next */
    private static function assertNotSystemObject(string $name, ?array $next): void
    {
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                throw new SqlQueryRejected("Se rechaza el objeto de sistema «{$name}».", 'system_object');
            }
        }
        if (in_array($name, self::SYSTEM_NAMES, true)) {
            throw new SqlQueryRejected("Se rechaza el catálogo de sistema «{$name}».", 'system_object');
        }
        if (in_array($name, self::SYSTEM_QUALIFIERS, true) && $next !== null && $next['t'] === 'symbol' && $next['v'] === '.') {
            throw new SqlQueryRejected("Se rechaza el esquema de sistema «{$name}».", 'system_object');
        }
    }

    /**
     * @param  list<string|null>  $parts
     * @return list<string|null>
     */
    private static function compact(array $parts): array
    {
        $result = [];
        foreach ($parts as $part) {
            if (is_string($part) && $result !== [] && is_string($result[array_key_last($result)])) {
                $result[array_key_last($result)] .= $part;
            } else {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * @return list<array{t: string, v: string, name?: string}> t = ws | comment | word | ident | string | number | param | symbol
     *
     * @throws SqlQueryRejected
     */
    private static function tokenize(string $sql, string $dialect): array
    {
        $tokens = [];
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (ctype_space($c)) {
                $j = $i;
                while ($j < $n && ctype_space($sql[$j])) {
                    $j++;
                }
                $tokens[] = ['t' => 'ws', 'v' => substr($sql, $i, $j - $i)];
                $i = $j;
            } elseif ($c === '-' && $next === '-') {
                $j = $i;
                while ($j < $n && $sql[$j] !== "\n" && $sql[$j] !== "\r") {
                    $j++;
                }
                $tokens[] = ['t' => 'comment', 'v' => substr($sql, $i, $j - $i)];
                $i = $j;
            } elseif ($c === '#' && $dialect === 'mysql') {
                $j = $i;
                while ($j < $n && $sql[$j] !== "\n" && $sql[$j] !== "\r") {
                    $j++;
                }
                $tokens[] = ['t' => 'comment', 'v' => substr($sql, $i, $j - $i)];
                $i = $j;
            } elseif ($c === '/' && $next === '*') {
                if (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!') {
                    throw new SqlQueryRejected('No se permiten comentarios ejecutables (/*! ... */).', 'executable_comment');
                }
                $j = $i + 2;
                $depth = 1;
                $nested = $dialect !== 'mysql'; // PostgreSQL y SQL Server anidan comentarios de bloque
                while ($j < $n && $depth > 0) {
                    if ($sql[$j] === '*' && ($sql[$j + 1] ?? '') === '/') {
                        $depth--;
                        $j += 2;
                    } elseif ($nested && $sql[$j] === '/' && ($sql[$j + 1] ?? '') === '*') {
                        $depth++;
                        $j += 2;
                    } else {
                        $j++;
                    }
                }
                if ($depth > 0) {
                    throw new SqlQueryRejected('Comentario de bloque sin cerrar.', 'unterminated');
                }
                $tokens[] = ['t' => 'comment', 'v' => substr($sql, $i, $j - $i)];
                $i = $j;
            } elseif ($c === "'") {
                $j = self::skipQuoted($sql, $i, "'");
                $tokens[] = ['t' => 'string', 'v' => substr($sql, $i, $j - $i)];
                $i = $j;
            } elseif ($c === '"') {
                $j = self::skipQuoted($sql, $i, '"');
                $tokens[] = ['t' => 'ident', 'v' => substr($sql, $i, $j - $i), 'name' => substr($sql, $i + 1, $j - $i - 2)];
                $i = $j;
            } elseif ($c === '`' && $dialect === 'mysql') {
                $j = self::skipQuoted($sql, $i, '`');
                $tokens[] = ['t' => 'ident', 'v' => substr($sql, $i, $j - $i), 'name' => substr($sql, $i + 1, $j - $i - 2)];
                $i = $j;
            } elseif ($c === '[' && $dialect === 'sqlsrv') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === ']') {
                        if (($sql[$j + 1] ?? '') === ']') {
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                if ($j >= $n) {
                    throw new SqlQueryRejected('Identificador entre corchetes sin cerrar.', 'unterminated');
                }
                $tokens[] = ['t' => 'ident', 'v' => substr($sql, $i, $j - $i + 1), 'name' => substr($sql, $i + 1, $j - $i - 1)];
                $i = $j + 1;
            } elseif (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
                preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $sql, $m, 0, $i);
                $tokens[] = ['t' => 'number', 'v' => $m[0]];
                $i += strlen($m[0]);
            } elseif (preg_match('/\G[\p{L}_][\p{L}\p{N}_$]*/u', $sql, $m, 0, $i) === 1) {
                $tokens[] = ['t' => 'word', 'v' => $m[0]];
                $i += strlen($m[0]);
            } elseif ($c === ':') {
                if ($next === ':') {
                    $tokens[] = ['t' => 'symbol', 'v' => '::'];
                    $i += 2;
                } elseif (preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m, 0, $i) === 1) {
                    $tokens[] = ['t' => 'param', 'v' => $m[0], 'name' => $m[1]];
                    $i += strlen($m[0]);
                } else {
                    throw new SqlQueryRejected('Uso no permitido de ":" en la consulta.', 'invalid_character');
                }
            } elseif (str_contains('(),.;*+-/%=<>!|&^~', $c)) {
                $tokens[] = ['t' => 'symbol', 'v' => $c];
                $i++;
            } else {
                throw new SqlQueryRejected('Carácter no permitido en la consulta: «'.$c.'».', 'invalid_character');
            }
        }

        return $tokens;
    }

    /** Devuelve la posición siguiente al cierre de una cadena/identificador entrecomillado con duplicación del delimitador como escape. */
    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $n = strlen($sql);
        $j = $start + 1;
        while ($j < $n) {
            if ($sql[$j] === $quote) {
                if (($sql[$j + 1] ?? '') === $quote) {
                    $j += 2;

                    continue;
                }

                return $j + 1;
            }
            $j++;
        }

        throw new SqlQueryRejected('Comillas sin cerrar en la consulta.', 'unterminated');
    }
}
