<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (revisión adversarial): concurrencia REAL, con procesos `artisan` y una segunda conexión a PostgreSQL. Los datos se CONFIRMAN (no hay transacción de prueba, que un
 * proceso hijo no vería) y se borran en `tearDown`; la prueba solo corre contra una base cuyo nombre contiene «test». Es determinista: no depende de pausas fijas, espera a que
 * PostgreSQL muestre al hijo bloqueado (`pg_locks`) antes de soltar el candado.
 *
 *  - Candado consultivo por empresa (`pg_advisory_xact_lock(5301, empresa)`): con otra conexión reteniéndolo, la medición NO avanza; al soltarlo, termina y mide todo.
 *  - Bloqueo de filas (`SELECT … FOR UPDATE` de las recomendaciones del grupo): con otra conexión reteniendo una fila, la medición pasó el candado y espera la fila.
 *  - Varios procesos a la vez: ni duplican resultados ni cuentan dos veces a un cliente en el análisis.
 */
final class OutcomeConcurrencyTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    /** Sin transacción de prueba: el proceso hijo y la segunda conexión deben ver los datos confirmados. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        if (! str_contains((string) DB::connection()->getDatabaseName(), 'test')) {
            $this->markTestSkipped('La prueba de concurrencia confirma y borra datos: solo corre contra una base de pruebas (nombre con «test»).');
        }
        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (str_contains((string) DB::connection()->getDatabaseName(), 'test')) {
            $this->wipe();
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        DB::statement('TRUNCATE tenants, users RESTART IDENTITY CASCADE');
    }

    private function pdo(): PDO
    {
        $cfg = config('database.connections.pgsql');

        return new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']), $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function spawn(int $tenantId): array
    {
        $cfg = config('database.connections.pgsql');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => (string) $cfg['database'],
            'DB_USERNAME' => (string) $cfg['username'], 'DB_PASSWORD' => (string) $cfg['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=1024M', 'artisan', 'predictily:outcomes-measure', '--tenant='.$tenantId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes];
    }

    /** @param resource $proc */
    private function running($proc): bool
    {
        return (bool) proc_get_status($proc)['running'];
    }

    /**
     * Espera a que la condición se cumpla (sondeo de 100 ms, hasta `$seconds`).
     *
     * @param  callable(): bool  $condition
     */
    private function waitFor(callable $condition, int $seconds, string $what): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(100000);
        }
        $this->fail("Tiempo agotado esperando: {$what}");
    }

    /**
     * Espera a que el hijo termine y devuelve [código de salida, salida estándar].
     *
     * @param  array{0: resource, 1: array<int, resource>}  $child
     * @return array{0: int, 1: string}
     */
    private function finish(array $child, int $seconds = 90): array
    {
        [$proc, $pipes] = $child;
        $this->waitFor(fn () => ! $this->running($proc), $seconds, 'que el proceso hijo termine');
        $code = (int) proc_get_status($proc)['exitcode'];
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);

        return [$code, $out.$err];
    }

    /** @return array{0: array<string, mixed>, 1: int} el mundo y cuántas recomendaciones hay */
    private function buildWorld(): array
    {
        $w = $this->outcomeWorld(50);
        $p1 = $w['cat']['products']['p1'];
        $customers = $this->newCustomers($w['tenant'], 36);
        $n = 0;
        foreach ([0, 1, 2] as $cohort) {
            foreach (array_slice($customers, $cohort * 12, 12) as $i => $customerId) {
                $rec = $this->contactFor($w, $customerId, 'p1', ['as_of' => $this->day(45 + $cohort)]);
                $this->assignExperiment($w['tenant'], $rec, $i % 2 === 0 ? 'treatment' : 'control');
                if ($i % 3 === 0) {
                    $this->purchase($w['tenant'], $customerId, $p1, $this->day(30), 2, 8.0, 6.0);
                }
                $n++;
            }
        }
        $this->contactFor($w, $customers[0], 'p1', ['as_of' => $this->day(44)]);   // un cliente repetido en otra cohorte: debe contarse una sola vez
        $n++;

        return [$w, $n];
    }

    public function test_the_company_advisory_lock_held_by_another_connection_blocks_the_measurement_until_it_is_released(): void
    {
        [$w, $n] = $this->buildWorld();
        $tenantId = (int) $w['tenant']->id;
        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->exec("SELECT pg_advisory_xact_lock(5301, {$tenantId})");

        $child = $this->spawn($tenantId);
        $this->waitFor(fn () => (int) DB::selectOne("SELECT count(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")->c > 0, 40, 'que PostgreSQL muestre al hijo esperando el candado consultivo');

        $this->assertTrue($this->running($child[0]), 'el hijo sigue bloqueado mientras otra conexión retiene el candado');
        $this->assertSame(0, DB::table('decision_outcomes')->count(), 'y no midió nada');
        $holder->commit();

        [$code, $out] = $this->finish($child);
        $this->assertSame(0, $code, $out);
        $this->assertSame($n, DB::table('decision_outcomes')->count(), 'al soltar el candado, mide todo');
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")->c);
    }

    public function test_a_row_lock_on_a_recommendation_held_by_another_connection_makes_the_measurement_wait_for_that_row(): void
    {
        [$w, $n] = $this->buildWorld();
        $tenantId = (int) $w['tenant']->id;
        // Una fila del PRIMER grupo que mide el comando (el de corte más antiguo): cada grupo se mide y confirma por separado, así que el hijo se bloquea antes de escribir nada.
        $recId = (int) DB::table('decision_recommendations')->where('tenant_id', $tenantId)->orderBy('as_of')->orderBy('id')->value('id');
        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->exec("SELECT id FROM decision_recommendations WHERE id = {$recId} FOR UPDATE");

        $child = $this->spawn($tenantId);
        $this->waitFor(fn () => (int) DB::selectOne("SELECT count(*) AS c FROM pg_locks WHERE NOT granted AND locktype IN ('transactionid', 'tuple')")->c > 0, 40, 'que PostgreSQL muestre al hijo esperando la fila bloqueada');

        $this->assertTrue($this->running($child[0]), 'el hijo espera la fila retenida');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND granted AND classid = 5301 AND objid = {$tenantId}")->c, 'el hijo ya tiene el candado consultivo de la empresa');
        $this->assertSame(0, DB::table('decision_outcomes')->count(), 'y no escribió ningún resultado a medias');
        $holder->commit();

        [$code, $out] = $this->finish($child);
        $this->assertSame(0, $code, $out);
        $this->assertSame($n, DB::table('decision_outcomes')->count());
    }

    public function test_a_recommendation_measured_by_another_process_while_this_one_waits_for_its_row_is_skipped_after_the_lock(): void
    {
        [$w, $n] = $this->buildWorld();
        $tenantId = (int) $w['tenant']->id;
        $recId = (int) DB::table('decision_recommendations')->where('tenant_id', $tenantId)->orderBy('as_of')->orderBy('id')->value('id');
        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->exec("SELECT id FROM decision_recommendations WHERE id = {$recId} FOR UPDATE");

        $child = $this->spawn($tenantId);
        $this->waitFor(fn () => (int) DB::selectOne("SELECT count(*) AS c FROM pg_locks WHERE NOT granted AND locktype IN ('transactionid', 'tuple')")->c > 0, 40, 'que el hijo espere la fila bloqueada');
        // «Otro proceso» midió esta recomendación mientras el hijo esperaba su fila: escribe su resultado y confirma.
        $insert = $holder->prepare("INSERT INTO decision_outcomes (tenant_id, recommendation_id, action_kind, window_from, window_to, observed_event, classification, method, evidence, measured_at)
            VALUES (?, ?, 'not_contacted', ?, ?, 'no_purchase', 'observed', 'none', ?, now())");
        $insert->execute([$tenantId, $recId, $this->day(50), $this->day(20), json_encode(['por' => 'otro proceso'])]);
        $holder->commit();

        [$code, $out] = $this->finish($child);
        $this->assertSame(0, $code, $out);
        $this->assertSame($n, DB::table('decision_outcomes')->count(), 'cada recomendación tiene un solo resultado');
        $this->assertStringNotContainsString('ya medidas por otro proceso', $out, 'la comprobación POSTERIOR al bloqueo la descartó: no llegó a intentar escribirla');
        $this->assertSame('otro proceso', json_decode((string) DB::table('decision_outcomes')->where('recommendation_id', $recId)->value('evidence'), true)['por'], 'lo que escribió el otro proceso queda intacto');
    }

    public function test_several_processes_at_once_neither_duplicate_results_nor_count_a_customer_twice(): void
    {
        [$w, $n] = $this->buildWorld();
        $tenantId = (int) $w['tenant']->id;

        $children = [$this->spawn($tenantId), $this->spawn($tenantId), $this->spawn($tenantId)];
        foreach ($children as $child) {
            [$code, $out] = $this->finish($child, 120);
            $this->assertSame(0, $code, $out);
        }

        $this->assertSame($n, DB::table('decision_outcomes')->count());
        $this->assertSame(0, DB::table('decision_outcomes')->select('recommendation_id')->groupBy('recommendation_id')->havingRaw('count(*) > 1')->get()->count(), 'ni un resultado repetido');
        $counted = DB::table('decision_outcomes as o')->join('decision_recommendations as r', 'r.id', '=', 'o.recommendation_id')->whereRaw("o.evidence->'experiment'->>'itt_counted' = 'true'");
        $this->assertSame((clone $counted)->count(), (clone $counted)->distinct()->count('r.customer_id'), 'cada cliente entra una sola vez al análisis');
        $this->assertSame(36, (clone $counted)->count());
    }
}
