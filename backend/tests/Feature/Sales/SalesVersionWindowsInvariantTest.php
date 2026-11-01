<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\SalesBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * INVARIANTE de las ventanas de conocimiento (ADR-V25-23): para un documento, en TODO corte hay 0 o 1 versión visible, y una vez
 * pasada la última escritura exactamente 1 y es la vigente. Sin esto un documento se contaría dos veces en un corte as-of.
 *
 * Defecto HIGH de la revisión adversarial: backfill (qty 2) → incremental (qty 5) → backfill (qty 7) dejaba visibles la revisión 0
 * (2) y la 2 (7) entre la fecha de venta y la ingestión incremental, porque la re-carga backfill solo cerraba la ventana de la
 * versión inmediata. La causa: las revisiones archivadas MÁS ANTIGUAS con ventana no vacía se solapaban con la nueva, que es
 * conocida desde el inicio del día local de su `sale_date`.
 *
 * Cobertura: (1) los escenarios del defecto con ventanas y cortes explícitos; (2) la enumeración exhaustiva de las 336 secuencias
 * de 2 a 4 escrituras (backfill | incremental) con dos fechas de venta, en una zona sin horario de verano (Guayaquil) y en otra con
 * horario de verano y offset positivo (Madrid, con el cambio de hora el 29-mar-2026 dentro de las escrituras y de las ventanas).
 * La enumeración recorre el árbol de secuencias en profundidad con puntos de guardado (SAVEPOINT): cada escritura se hace una vez
 * por nodo, no una vez por secuencia, y la prueba tarda unos segundos.
 */
final class SalesVersionWindowsInvariantTest extends TestCase
{
    use InteractsWithTenants, ReadsSalesVersions, RefreshDatabase;

    private const T0 = '2026-06-10 12:00:00';

    private const T1 = '2026-06-12 15:30:00';

    private const T2 = '2026-06-15 09:45:00';

    /** Inicio del día local (Guayaquil, UTC-5) de las fechas de venta 2026-05-04 y 2026-05-06, en UTC. */
    private const DAY_START = '2026-05-04 05:00:00';

    private const DAY_START_MAY6 = '2026-05-06 05:00:00';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * Una escritura de F-1 por el servicio de lote (la misma ruta que POST /sales/batch). La cantidad identifica la versión.
     *
     * @return array<string, mixed>
     */
    private function write(Tenant $tenant, string $mode, string $issuedAt, float $qty): array
    {
        $document = ['document_number' => 'F-1', 'issued_at' => $issuedAt, 'branch_code' => '001',
            'items' => [['product_sku' => 'A', 'product_name' => 'A', 'quantity' => $qty, 'unit_price' => 5]]];
        $result = $this->withinTenant($tenant, fn () => app(SalesBatchService::class)->ingest($tenant, [$document], null, [], $mode));
        $this->assertSame([], $result['errors'], 'la escritura de la prueba debe ser válida');

        return $result;
    }

    /** Cantidades de la ÚNICA versión visible de F-1 en cada corte (revisión => cantidad de la línea 1). @return array<string, array<int, float>> */
    private function quantitiesAt(array $cutoffs): array
    {
        $out = [];
        foreach ($cutoffs as $cutoff) {
            $out[$cutoff] = array_map(fn (array $lines) => $lines[1], $this->visibleAt($this->tenant, $cutoff)['F-1'] ?? []);
        }

        return $out;
    }

    // ───────────────────────── escenarios del defecto, con ventanas explícitas ─────────────────────────

    public function test_backfill_incremental_backfill_leaves_exactly_one_visible_version_at_every_cutoff(): void
    {
        $this->travelTo(self::T0);
        $this->write($this->tenant, 'backfill', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 5);
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'backfill', '2026-05-04T10:00:00-05:00', 7);

        // TODA la cadena archivada queda colapsada (ventana vacía); solo la vigente tiene ventana.
        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame(self::DAY_START, $views[0]['known_from']);
        $this->assertSame(self::DAY_START, $views[0]['known_until'], 'la histórica que la incremental había cerrado en T1 también se colapsa');
        $this->assertSame(self::T1, $views[1]['known_from']);
        $this->assertSame(self::T1, $views[1]['known_until']);
        $this->assertSame([self::DAY_START, null], [$views[2]['known_from'], $views[2]['known_until']]);
        $this->assertTrue($views[2]['current']);

        // Antes del defecto los cortes entre la fecha de venta y T1 veían la revisión 0 (2) Y la 2 (7).
        $cutoffs = ['2026-05-03', '2026-05-04', '2026-05-20', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12', '2026-06-13', '2026-06-15', '2026-06-20'];
        $expected = array_fill_keys($cutoffs, [2 => 7.0]);
        $expected['2026-05-03'] = [];
        $this->assertSame($expected, $this->quantitiesAt($cutoffs));
    }

    public function test_incremental_incremental_backfill_collapses_both_closed_incremental_windows(): void
    {
        $this->travelTo(self::T0);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 5);
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'backfill', '2026-05-04T10:00:00-05:00', 7);

        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame([self::T0, self::T0], [$views[0]['known_from'], $views[0]['known_until']], 'V0 era (T0, T1] y se colapsa en T0');
        $this->assertSame([self::T1, self::T1], [$views[1]['known_from'], $views[1]['known_until']]);
        $this->assertSame([self::DAY_START, null], [$views[2]['known_from'], $views[2]['known_until']]);

        $cutoffs = ['2026-05-03', '2026-05-04', '2026-06-10', '2026-06-11', '2026-06-12', '2026-06-14', '2026-06-20'];
        $expected = array_fill_keys($cutoffs, [2 => 7.0]);
        $expected['2026-05-03'] = [];
        $this->assertSame($expected, $this->quantitiesAt($cutoffs));
    }

    public function test_backfill_reload_that_moves_the_sale_date_after_incremental_revisions_stays_disjoint(): void
    {
        $this->travelTo(self::T0);
        $this->write($this->tenant, 'backfill', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 5);
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'backfill', '2026-05-06T10:00:00-05:00', 7); // la fecha de venta se mueve al 6

        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame($views[0]['known_from'], $views[0]['known_until']);
        $this->assertSame($views[1]['known_from'], $views[1]['known_until']);
        $this->assertSame([self::DAY_START_MAY6, null], [$views[2]['known_from'], $views[2]['known_until']]);

        // La nueva versión es conocida desde SU día de negocio; antes de él el documento no se ve (la cadena colapsada no reaparece).
        $this->assertSame([
            '2026-05-04' => [], '2026-05-05' => [], '2026-05-06' => [2 => 7.0], '2026-06-10' => [2 => 7.0], '2026-06-12' => [2 => 7.0], '2026-06-20' => [2 => 7.0],
        ], $this->quantitiesAt(['2026-05-04', '2026-05-05', '2026-05-06', '2026-06-10', '2026-06-12', '2026-06-20']));
    }

    public function test_backfill_incremental_incremental_keeps_back_to_back_windows(): void
    {
        $this->travelTo(self::T0);
        $this->write($this->tenant, 'backfill', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 5);
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 7);

        // Sin re-carga histórica nada se colapsa: la historia incremental se conserva con ventanas contiguas (T1, T2] y (T2, ∞).
        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame([self::DAY_START, self::T1], [$views[0]['known_from'], $views[0]['known_until']]);
        $this->assertSame([self::T1, self::T2], [$views[1]['known_from'], $views[1]['known_until']]);
        $this->assertSame([self::T2, null], [$views[2]['known_from'], $views[2]['known_until']]);
        $this->assertSame([
            '2026-05-03' => [], '2026-05-04' => [0 => 2.0], '2026-06-11' => [0 => 2.0], '2026-06-12' => [1 => 5.0], '2026-06-14' => [1 => 5.0], '2026-06-15' => [2 => 7.0], '2026-06-20' => [2 => 7.0],
        ], $this->quantitiesAt(['2026-05-03', '2026-05-04', '2026-06-11', '2026-06-12', '2026-06-14', '2026-06-15', '2026-06-20']));
    }

    public function test_an_incremental_write_with_a_clock_behind_the_history_does_not_overlap_older_windows(): void
    {
        // Defensa ante desfases de reloj entre servidores: la revisión 0 terminó en T3 (su ventana (T2, T3] es NO vacía) y una
        // escritura incremental posterior llega con el reloj en T1 < T2. Sin recortar las revisiones más antiguas, la revisión 0
        // se solaparía con la nueva ventana (T1, ∞) en los cortes de esas fechas.
        $t3 = '2026-06-18 20:00:00';
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo($t3);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 5);
        $this->assertSame(['2026-06-15 09:45:00', $t3], [$this->versionsView($this->tenant, 'F-1')[0]['known_from'], $this->versionsView($this->tenant, 'F-1')[0]['known_until']]);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 9);

        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame($views[0]['known_from'], $views[0]['known_until'], 'la revisión 0 se recorta al instante de la escritura atrasada: ventana vacía');
        $this->assertSame($views[1]['known_from'], $views[1]['known_until']);
        foreach (['2026-06-10', '2026-06-12', '2026-06-14', '2026-06-16', '2026-06-18', '2026-06-20'] as $cutoff) {
            $this->assertLessThanOrEqual(1, count($this->visibleAt($this->tenant, $cutoff)['F-1'] ?? []), "a lo sumo una versión visible al corte {$cutoff}");
        }
    }

    public function test_windows_never_invert_after_any_write(): void
    {
        $this->travelTo(self::T0);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 2);
        $this->travelTo(self::T1);
        $this->write($this->tenant, 'backfill', '2026-05-06T10:00:00-05:00', 5);
        $this->travelTo(self::T2);
        $this->write($this->tenant, 'incremental', '2026-05-04T10:00:00-05:00', 7);

        $this->assertSame(0, DB::table('sales_line_versions')->whereNotNull('known_until')->whereColumn('known_until', '<', 'known_from')->count());
    }

    // ───────────────────────── enumeración exhaustiva de secuencias ─────────────────────────

    /** @return iterable<string, array{0: string, 1: list<string>, 2: list<string>, 3: string, 4: int, 5: string}> */
    public static function scenarios(): iterable
    {
        // Guayaquil: UTC-5 sin horario de verano. Escrituras de junio sobre ventas de mayo.
        yield 'America/Guayaquil (UTC-5, sin horario de verano)' => [
            'America/Guayaquil',
            ['2026-05-04T10:00:00-05:00', '2026-05-06T10:00:00-05:00'],
            [self::T0, self::T1, self::T2, '2026-06-18 20:00:00'],
            '2026-05-01', 62, '2026-06-19',
        ];
        // Madrid: UTC+1 en invierno y UTC+2 en verano; el cambio de hora ocurre el 29-mar-2026 a las 01:00 UTC. Las escrituras lo
        // atraviesan (00:10 y 00:50 UTC en horario de invierno; 01:10 y 23:30 UTC en horario de verano) y el día 29 dura 23 horas.
        yield 'Europe/Madrid (UTC+1/+2, cambio de hora el 29-mar-2026)' => [
            'Europe/Madrid',
            ['2026-03-28T10:00:00+01:00', '2026-03-30T10:00:00+02:00'],
            ['2026-03-29 00:10:00', '2026-03-29 00:50:00', '2026-03-29 01:10:00', '2026-03-29 23:30:00'],
            '2026-03-25', 12, '2026-03-31',
        ];
    }

    /**
     * @param  list<string>  $issuedAt  dos fechas de emisión (dos fechas de venta locales distintas)
     * @param  list<string>  $times  instante (UTC) de la 1.ª, 2.ª, 3.ª y 4.ª escritura
     * @param  string  $settledFrom  primer corte en que ya pasó la última escritura posible: exactamente una versión visible
     */
    #[DataProvider('scenarios')]
    public function test_every_sequence_of_two_to_four_writes_keeps_zero_or_one_visible_version_per_cutoff(string $timezone, array $issuedAt, array $times, string $fromCutoff, int $days, string $settledFrom): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(tenantAttributes: ['timezone' => $timezone]);
        $this->createBranch($tenant, '001');
        $this->actingAsTenantUser($owner, $tenant);

        $started = microtime(true);
        $sequences = 0;
        $violations = [];
        $this->explore($tenant, [], $issuedAt, $times, $fromCutoff, $days, $settledFrom, $sequences, $violations);

        $this->assertSame(336, $sequences, '16 + 64 + 256 secuencias de 2, 3 y 4 escrituras');
        $this->assertSame([], array_slice($violations, 0, 15), count($violations).' de '.$sequences.' secuencias violan la invariante (primeras 15)');
        $this->assertLessThan(15.0, microtime(true) - $started, 'la enumeración debe acotarse a unos segundos');
    }

    /**
     * Recorre en profundidad el árbol de secuencias (modo × fecha de emisión por escritura). Cada nodo es UNA escritura hecha bajo un
     * SAVEPOINT que se deshace al volver; en cada nodo de profundidad >= 2 se comprueba la invariante.
     *
     * @param  list<array{0: string, 1: string}>  $prefix  [modo, fecha de emisión] de las escrituras ya hechas
     * @param  list<string>  $issuedAt
     * @param  list<string>  $times
     * @param  list<string>  $violations
     */
    private function explore(Tenant $tenant, array $prefix, array $issuedAt, array $times, string $fromCutoff, int $days, string $settledFrom, int &$sequences, array &$violations): void
    {
        $depth = count($prefix);
        if ($depth === count($times)) {
            return;
        }
        foreach (['backfill', 'incremental'] as $mode) {
            foreach ($issuedAt as $issued) {
                DB::beginTransaction();
                $this->travelTo($times[$depth]);
                $this->write($tenant, $mode, $issued, (float) ($depth + 2));
                $sequence = [...$prefix, [$mode, $issued]];

                if (count($sequence) >= 2) {
                    $sequences++;
                    $problems = $this->invariantViolations($tenant, $fromCutoff, $days, $settledFrom, count($sequence) - 1);
                    if ($problems !== []) {
                        $violations[] = implode(' → ', array_map(fn (array $w) => substr($w[0], 0, 1).'@'.substr($w[1], 0, 10), $sequence)).' :: '.implode(', ', array_slice($problems, 0, 3));
                    }
                }
                $this->explore($tenant, $sequence, $issuedAt, $times, $fromCutoff, $days, $settledFrom, $sequences, $violations);
                DB::rollBack();
            }
        }
    }

    /**
     * @return list<string> descripción de cada violación de la invariante en el estado actual de F-1
     */
    private function invariantViolations(Tenant $tenant, string $fromCutoff, int $days, string $settledFrom, int $currentRevision): array
    {
        $problems = [];
        $sale = $this->saleRow($tenant, 'F-1');
        if ((int) $sale->current_revision !== $currentRevision) {
            $problems[] = "revisión vigente {$sale->current_revision} != {$currentRevision}";
        }
        if (DB::table('sales_line_versions')->where('tenant_id', $tenant->id)->whereNotNull('known_until')->whereColumn('known_until', '<', 'known_from')->exists()) {
            $problems[] = 'ventana invertida (known_until < known_from)';
        }
        $byCutoff = $this->visibleRevisionsByCutoff($tenant, $fromCutoff, $days);
        for ($i = 0; $i <= $days; $i++) {
            $cutoff = date('Y-m-d', strtotime($fromCutoff.' +'.$i.' days'));
            $revisions = $byCutoff[$cutoff]['F-1'] ?? null;
            if ($revisions !== null && str_contains($revisions, ',')) {
                $problems[] = "{$cutoff}: versiones visibles {$revisions}";
            }
            if ($cutoff >= $settledFrom && $revisions !== (string) $currentRevision) {
                $problems[] = "{$cutoff}: debe verse solo la vigente ({$currentRevision}) y se ve ".($revisions ?? 'ninguna');
            }
        }

        return $problems;
    }

    // ───────────────────────── horario de verano y offset positivo (Europe/Madrid) ─────────────────────────

    public function test_dst_company_with_a_positive_offset_has_correct_backfill_and_incremental_windows(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(tenantAttributes: ['timezone' => 'Europe/Madrid']);
        $this->createBranch($tenant, '001');
        $this->actingAsTenantUser($owner, $tenant);
        $march28 = '2026-03-28T10:00:00+01:00'; // UTC+1: el inicio de su día local es el 27-mar 23:00 UTC
        $march30 = '2026-03-30T10:00:00+02:00'; // UTC+2 (horario de verano): el inicio de su día local es el 29-mar 22:00 UTC

        // 1) Carga histórica justo antes del cambio de hora (00:10 UTC, todavía UTC+1).
        $this->travelTo('2026-03-29 00:10:00');
        $this->write($tenant, 'backfill', $march28, 2);
        $sale = $this->saleRow($tenant, 'F-1');
        $this->assertSame('2026-03-28', $sale->sale_date);
        $this->assertNull($sale->version_known_from);
        $this->assertSame(
            [0 => ['current' => true, 'known_from' => '2026-03-27 23:00:00', 'known_until' => null, 'status' => 'completed', 'lines' => [1 => 2.0]]],
            $this->versionsView($tenant, 'F-1'),
            'backfill: conocido desde el inicio del día local en UTC+1',
        );

        // 2) Corrección incremental DESPUÉS del cambio de hora (01:10 UTC = 03:10 local, UTC+2).
        $this->travelTo('2026-03-29 01:10:00');
        $this->write($tenant, 'incremental', $march28, 5);
        $this->assertSame([
            0 => ['current' => false, 'known_from' => '2026-03-27 23:00:00', 'known_until' => '2026-03-29 01:10:00', 'status' => 'completed', 'lines' => [1 => 2.0]],
            1 => ['current' => true, 'known_from' => '2026-03-29 01:10:00', 'known_until' => null, 'status' => 'completed', 'lines' => [1 => 5.0]],
        ], $this->versionsView($tenant, 'F-1'));

        // Cortes: el 27 aún no existe (el inicio de su día local es el 27 a las 23:00 UTC: no es estrictamente anterior); el 28 ve la
        // histórica; el 29 (día de 23 horas, medianoche local del 30 = 29-mar 22:00 UTC) ya ve la corrección y no la histórica.
        $at = fn (string $cutoff) => array_map(fn (array $lines) => $lines[1], $this->visibleAt($tenant, $cutoff)['F-1'] ?? []);
        $this->assertSame([], $at('2026-03-27'));
        $this->assertSame([0 => 2.0], $at('2026-03-28'));
        $this->assertSame([1 => 5.0], $at('2026-03-29'));
        $this->assertSame([1 => 5.0], $at('2026-03-30'));

        // 3) Re-carga histórica posterior que además mueve la fecha de venta al 30-mar (UTC+2): colapsa TODA la cadena.
        $this->travelTo('2026-03-30 10:00:00');
        $this->write($tenant, 'backfill', $march30, 7);
        $views = $this->versionsView($tenant, 'F-1');
        $this->assertSame(['2026-03-27 23:00:00', '2026-03-27 23:00:00'], [$views[0]['known_from'], $views[0]['known_until']]);
        $this->assertSame(['2026-03-29 01:10:00', '2026-03-29 01:10:00'], [$views[1]['known_from'], $views[1]['known_until']]);
        $this->assertSame(['2026-03-29 22:00:00', null], [$views[2]['known_from'], $views[2]['known_until']], 'el inicio del día local del 30-mar es el 29-mar 22:00 UTC (UTC+2)');
        $this->assertSame([], $at('2026-03-28'));
        $this->assertSame([], $at('2026-03-29'), 'la medianoche local del 30 (22:00 UTC) no es estrictamente posterior al known_from de la nueva versión');
        $this->assertSame([2 => 7.0], $at('2026-03-30'));
        $this->assertSame([2 => 7.0], $at('2026-04-02'));
    }
}
