<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\DataCore\Mapping\Heuristics\ColumnProfile;
use App\Services\DataCore\Mapping\Heuristics\ColumnProfiler;
use App\Services\Imports\Parsing\ValueParser;
use Throwable;

/**
 * Muestras estadísticas ACOTADAS y de solo lectura (V2.5 §4). Reglas:
 *  - Solo se piden columnas que el Auto Mapper ya eligió como candidatas y que NO tienen nombre de dato personal (esa decisión es de EntityProposer).
 *  - Cada consulta la genera Predictily con identificadores del catálogo entrecomillados (IdentifierQuoter) y PASA POR SqlQueryGuard como cualquier
 *    otra consulta de solo lectura; se ejecuta en la transacción de solo lectura de la conexión con el tiempo máximo de sentencia del conector.
 *  - Confirmación de tipo: se leen unas pocas filas (`sampling.pattern_rows`) de las columnas de texto candidatas a fecha/número/booleano, se calculan
 *    proporciones EN MEMORIA y los valores se descartan. Nunca se devuelve ni se registra un valor.
 *  - Vocabulario: de una columna de tipo/estado de documento se piden los valores distintos con su conteo (GROUP BY); si hay más de `distinct_cap`, no es
 *    un vocabulario y no se devuelve ninguno. Los valores pasan por SampleMasker. En tablas grandes (> `distinct_scan_max_rows` filas estimadas) solo se
 *    examina el inicio de la tabla y el perfil se marca como parcial.
 *  - Un fallo de una muestra (permiso, tiempo, tipo) no detiene la propuesta: esa columna queda sin perfil. En PostgreSQL cada muestra va en un SAVEPOINT
 *    para que un error no aborte la transacción de solo lectura.
 */
final class ColumnSampler implements ColumnProfiler
{
    private bool $aborted = false;

    public function __construct(private readonly SqlSourceConnection $connection)
    {
        $this->lowerStatementTimeout();
    }

    public function profile(DiscoveredTable $table, array $patternColumns, array $categoricalColumns): array
    {
        if (! (bool) config('mapping.sampling.enabled', true) || $this->aborted) {
            return [];
        }
        $profiles = [];
        if ($patternColumns !== []) {
            foreach ($this->patternProfiles($table, $patternColumns) as $name => $profile) {
                $profiles[$name] = $profile;
            }
        }
        foreach ($categoricalColumns as $name) {
            $profile = $this->distinctProfile($table, $name);
            if ($profile !== null) {
                $profiles[$name] = isset($profiles[$name]) ? $this->merge($profiles[$name], $profile) : $profile;
            }
        }

        return $profiles;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, ColumnProfile>
     */
    private function patternProfiles(DiscoveredTable $table, array $columns): array
    {
        $dialect = $this->connection->dialect()->name();
        try {
            $select = [];
            foreach (array_values($columns) as $i => $name) {
                $select[] = 't0.'.IdentifierQuoter::quote($dialect, $name).' AS c'.$i;
            }
            $sql = 'SELECT '.implode(', ', $select).' FROM '.IdentifierQuoter::table($dialect, $table->schema, $table->name).' t0';
            $rows = $this->run($sql, (int) config('mapping.sampling.pattern_rows', 200));
        } catch (Throwable) {
            return [];
        }
        $parser = new ValueParser('UTC');
        $profiles = [];
        foreach (array_values($columns) as $i => $name) {
            $total = count($rows);
            $nonNull = 0;
            $counts = ['date' => 0, 'number' => 0, 'boolean' => 0, 'email' => 0, 'phone' => 0, 'free' => 0];
            foreach ($rows as $row) {
                $value = $row['c'.$i] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $nonNull++;
                $text = is_scalar($value) ? trim((string) $value) : '';
                if (preg_match('/[-\/.:]/', $text) === 1 && $parser->dateTime($text) !== null) {
                    $counts['date']++;
                }
                if ($parser->number($text) !== null) {
                    $counts['number']++;
                }
                if (in_array(strtolower($text), ['0', '1', 's', 'n', 'si', 'no', 'true', 'false', 'y', 'yes', 't', 'f'], true)) {
                    $counts['boolean']++;
                }
                if (preg_match('/[@\x{FF20}\x{FE6B}]/u', $text) === 1) {
                    $counts['email']++;
                }
                // Teléfono: empieza por «+» o «(», o son grupos de dígitos separados por espacios, puntos o guiones con 7+ dígitos (un número simple podría ser un código: no cuenta).
                if (preg_match_all('/\d/', $text) >= 7 && (preg_match('/^[+(]/', $text) === 1 || preg_match('/^\d{2,4}([\s.\-]\d{2,4}){1,3}$/', $text) === 1)) {
                    $counts['phone']++;
                }
                if (preg_match_all('/\s+/', $text) >= 2 || mb_strlen($text) > 40) {
                    $counts['free']++;
                }
            }
            // Las proporciones se calculan sobre los valores no nulos; los valores se descartan aquí.
            $ratio = fn (string $k): float => $nonNull === 0 ? 0.0 : $counts[$k] / $nonNull;
            $profiles[$name] = new ColumnProfile($name, $total, $total === 0 ? 0.0 : $nonNull / $total, $ratio('date'), $ratio('number'), $ratio('boolean'), emailRatio: $ratio('email'), phoneRatio: $ratio('phone'), freeTextRatio: $ratio('free'));
        }

        return $profiles;
    }

    private function distinctProfile(DiscoveredTable $table, string $name): ?ColumnProfile
    {
        $dialect = $this->connection->dialect()->name();
        $cap = (int) config('mapping.sampling.distinct_cap', 25);
        $complete = ($table->estimatedRows ?? 0) <= (int) config('mapping.sampling.distinct_scan_max_rows', 500_000);
        try {
            $column = IdentifierQuoter::quote($dialect, $name);
            $from = IdentifierQuoter::table($dialect, $table->schema, $table->name);
            if ($complete) {
                $sql = "SELECT t0.{$column} AS v, COUNT(*) AS n FROM {$from} t0 GROUP BY t0.{$column}";
            } else {
                $prefix = (int) config('mapping.sampling.prefix_rows', 50_000);
                $inner = $dialect === 'sqlsrv' ? "SELECT TOP ({$prefix}) t0.{$column} AS v FROM {$from} t0" : "SELECT t0.{$column} AS v FROM {$from} t0 LIMIT {$prefix}";
                $sql = "SELECT s.v AS v, COUNT(*) AS n FROM ({$inner}) s GROUP BY s.v";
            }
            $rows = $this->run($sql, $cap + 1);
        } catch (Throwable) {
            return new ColumnProfile($name, 0, 0.0, note: 'No se pudo leer una muestra de esta columna (permiso, tiempo o tipo).');
        }
        if (count($rows) > $cap) {
            return new ColumnProfile($name, 0, 1.0, distinct: null, note: 'Tiene más de '.$cap.' valores distintos: no es un vocabulario de tipos o estados.');
        }
        $minCount = max(1, (int) config('mapping.sampling.min_value_count', 5));
        $values = [];
        $total = 0;
        $nulls = 0;
        $suppressed = 0;
        foreach ($rows as $row) {
            $count = (int) ($row['n'] ?? 0);
            $total += $count;
            $raw = $row['v'] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $nulls += $count;

                continue;
            }
            $text = is_scalar($raw) ? trim((string) $raw) : '';
            $known = $text !== '' && (ValueParser::documentType($text) !== null || ValueParser::documentStatus($text) !== null);
            $value = SampleMasker::value($raw, null, $known);
            if ($value === null) {
                return new ColumnProfile($name, $total, 1.0, distinct: null, note: 'Algunos valores parecen datos personales o texto libre: la columna no se trata como vocabulario y no se devuelve ningún valor.');
            }
            if ($count < $minCount) {
                $suppressed++;   // un valor que aparece en tan pocas filas no se devuelve: puede ser un dato suelto; la persona lo resuelve al revisar

                continue;
            }
            $values[] = ['value' => $value, 'count' => $count];
        }
        usort($values, fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['value'], $b['value']));

        return new ColumnProfile($name, $total, $total === 0 ? 0.0 : ($total - $nulls) / $total, distinct: $values, distinctComplete: $complete, suppressedRare: $suppressed);
    }

    private function merge(ColumnProfile $pattern, ColumnProfile $distinct): ColumnProfile
    {
        return new ColumnProfile($pattern->column, max($pattern->sampledRows, $distinct->sampledRows), $pattern->nonNullRatio, $pattern->dateRatio, $pattern->numericRatio, $pattern->booleanRatio,
            $distinct->distinct, $distinct->distinctComplete, $distinct->note, $pattern->emailRatio, $pattern->phoneRatio, $pattern->freeTextRatio, $distinct->suppressedRare);
    }

    /**
     * Baja el tiempo máximo de cada sentencia de muestra por debajo del del conector (`mapping.sampling.statement_timeout_seconds`): una muestra lenta se omite, no retiene la petición.
     * Sentencias fijas de sesión (no pasan por el guard: no llevan texto del usuario). Si el motor no las admite se ignora: queda el límite del conector.
     */
    private function lowerStatementTimeout(): void
    {
        $seconds = max(1, (int) config('mapping.sampling.statement_timeout_seconds', 5));
        $pdo = $this->connection->pdo();
        try {
            match ($this->connection->dialect()->name()) {
                'pgsql' => $this->postgresTimeout($pdo, $seconds * 1000),
                'sqlsrv' => defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), $seconds) : null,
                default => $this->mysqlTimeout($pdo, $seconds),
            };
        } catch (Throwable) {
            // sin cambio: rige el tiempo máximo del conector
        }
    }

    /** Nunca sube el límite del conector: queda el menor entre el del conector y el de las muestras. */
    private function postgresTimeout(\PDO $pdo, int $milliseconds): void
    {
        $current = (int) $pdo->query("SELECT (extract(epoch FROM current_setting('statement_timeout')::interval) * 1000)::bigint")->fetchColumn();
        $pdo->exec('SET LOCAL statement_timeout = '.($current > 0 ? min($current, $milliseconds) : $milliseconds));
    }

    private function mysqlTimeout(\PDO $pdo, int $seconds): void
    {
        try {
            $pdo->exec('SET SESSION max_execution_time = '.($seconds * 1000));   // MySQL
        } catch (Throwable) {
            $pdo->exec('SET SESSION max_statement_time = '.$seconds);   // MariaDB
        }
    }

    /**
     * Ejecuta una consulta de muestra: validada con SqlQueryGuard y, en PostgreSQL, dentro de un SAVEPOINT.
     *
     * @return list<array<string, mixed>>
     */
    private function run(string $sql, int $limit): array
    {
        $validated = SqlQueryGuard::validate($sql, $this->connection->dialect()->name());
        $pdo = $this->connection->pdo();
        $savepoint = $this->connection->dialect()->name() === 'pgsql';
        if ($savepoint) {
            $pdo->exec('SAVEPOINT predictily_sample');
        }
        try {
            $rows = $this->connection->preview($validated, null, $limit);
            if ($savepoint) {
                $pdo->exec('RELEASE SAVEPOINT predictily_sample');
            }

            return $rows;
        } catch (Throwable $e) {
            if ($savepoint) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT predictily_sample');
                } catch (Throwable) {
                    $this->aborted = true; // la transacción quedó inservible: no se intenta más muestreo
                }
            }
            throw $e;
        }
    }
}
