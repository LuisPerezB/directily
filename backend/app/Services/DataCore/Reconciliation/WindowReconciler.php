<?php

declare(strict_types=1);

namespace App\Services\DataCore\Reconciliation;

use App\Models\BronzeObject;
use App\Models\ReconciliationReport;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reconciliación por ventana fuente → Bronze → Silver → Gold (ADR-V25-04 y ADR-V25-24; V2.5 §3).
 *
 * Para una ventana de fechas de negocio [from, to] de las ventas de UNA empresa compara cuatro capas y escribe un
 * `reconciliation_reports` con scope `window`:
 *
 * - **source**: totales que el ORIGEN declaró en las cargas (informes por carga, scope `import`/`sync`, con `layers.source`).
 *   Solo cuentan las cargas cuyo rango de fechas (`window_from`..`window_to`) cae por completo dentro de la ventana, tomando el
 *   informe más reciente de cada carga. Si no hay informes por carga, la capa queda «no disponible» con la razón: NUNCA se
 *   inventa una cifra. Cargas que cruzan el límite de la ventana o se solapan entre sí no se pueden sumar: la capa se informa pero
 *   no se compara (`comparable = false`, con la razón).
 * - **bronze**: objetos Bronze de la ventana = los referenciados por los documentos de la ventana (versión vigente y revisiones) o
 *   por las cargas seleccionadas. Cuenta objetos, bytes y filas declaradas por la captura.
 * - **silver**: versiones VIGENTES de las líneas en la vista `sales_line_versions` (una por documento: invariante de W1; no se
 *   reimplementa) y los documentos de `sales` de la ventana. `units` y `net_amount` son de las VENTAS EFECTIVAS —la misma base que usa
 *   la reconciliación por carga de W2-A—: documentos `completed`; `units` suma las líneas que no son devolución y `net_amount` es el
 *   importe neto firmado (las devoluciones y notas de crédito restan). Los anulados y pendientes quedan fuera de ambas.
 * - **gold**: `data_snapshots.row_counts.sales` de la última ejecución de demanda COMPLETADA cuyo rango de datos llega al fin de la
 *   ventana. Gold cuenta celdas sucursal × producto × día de TODA la historia hasta el corte de esa ejecución, así que se compara
 *   con las celdas de Silver al mismo corte (no con la ventana); se acepta la lectura «al corte» (reconstrucción histórica) o «al
 *   día de la ejecución» (ejecución vigente, que conoce lo recibido hasta hoy: `known_until = hoy` en el motor).
 *
 * Estado: `balanced` (SE COMPARARON fuente → Silver, el linaje Bronze → Silver y Silver → Gold, y no hay diferencias), `explained`
 * (todas explicadas por las propias cargas o por una causa documentada, o alguna capa no se pudo comparar) o `unexplained` (alguna
 * sin explicación). Nunca es `balanced` sin haber comparado: una capa que no está disponible o no es comparable (cargas solapadas o
 * cortadas por la ventana, sin ejecución que cubra la ventana) queda como discrepancia visible `layer_not_compared` y como
 * `compared = false` en la capa; con una capa sin comparar, el informe solo puede ser `explained` o `unexplained`.
 */
final class WindowReconciler
{
    public const ENTITIES = ['sales'];

    /** Métricas comparables fuente → Silver (mismos nombres en ambas capas). */
    private const SOURCE_METRICS = ['documents', 'lines', 'voided', 'credit_notes', 'units', 'net_amount'];

    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function reconcile(Tenant $tenant, string $entity, string $from, string $to): ReconciliationReport
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw new InvalidArgumentException("Entidad no soportada: {$entity}. Solo 'sales' está implementada; las demás entidades están DISEÑADAS.");
        }
        $start = $this->date($from, 'from');
        $end = $this->date($to, 'to');
        if ($end->lt($start)) {
            throw new InvalidArgumentException('La fecha final es anterior a la inicial.');
        }
        $max = (int) config('bronze.reconcile_max_window_days', 366);
        if ($start->diffInDays($end) + 1 > $max) {
            throw new InvalidArgumentException("La ventana no puede superar {$max} días.");
        }

        return $this->context->runAs($tenant, function () use ($tenant, $start, $end): ReconciliationReport {
            $f = $start->toDateString();
            $t = $end->toDateString();
            $loads = $this->loadReports($f, $t);
            $source = $this->sourceLayer($loads);
            $silver = $this->silverLayer($tenant, $f, $t);
            $bronze = $this->bronzeLayer($tenant, $f, $t, $loads['inside']);
            $gold = $this->goldLayer($tenant, $f, $t);
            $source['compared'] = ($source['available'] ?? false) === true && ($source['comparable'] ?? false) === true;
            $gold['compared'] = ($gold['available'] ?? false) === true;
            $bronze['compared'] = ($bronze['available'] ?? false) === true;
            $silver['compared'] = true;

            $discrepancies = [
                ...$this->compareSource($source, $silver, $loads['inside'], $bronze),
                ...$this->compareLineage($silver),
                ...$this->compareGold($gold),
                ...$this->notCompared($source, $gold),
            ];

            $status = $discrepancies === [] ? 'balanced' : (count(array_filter($discrepancies, fn ($d) => ! $d['explained'])) === 0 ? 'explained' : 'unexplained');

            $report = ReconciliationReport::query()->create([
                'scope' => 'window',
                'entity' => 'sales',
                'window_from' => $f,
                'window_to' => $t,
                'layers' => ['source' => $source, 'bronze' => $bronze, 'silver' => $silver, 'gold' => $gold],
                'discrepancies' => $discrepancies,
                'status' => $status,
            ]);
            $this->audit->log('reconciliation.window', $report, ['entity' => 'sales', 'window_from' => $f, 'window_to' => $t, 'status' => $status, 'discrepancies' => count($discrepancies)], $tenant->id);

            return $report->refresh();
        });
    }

    // ───────────────────────── capa source ─────────────────────────

    /**
     * Informes por carga (scope import/sync) de ventas, el más reciente de cada carga, clasificados respecto de la ventana.
     *
     * Dos cargas del MISMO original (mismo `bronze_object_id`: el mismo archivo enviado dos veces) declaran las mismas cifras: se cuenta
     * solo la más reciente y las demás se informan en `duplicates`.
     *
     * @return array{inside: list<array<string, mixed>>, partial: list<int>, unplaced: int, without_declared: list<int>, duplicates: list<int>}
     */
    private function loadReports(string $from, string $to): array
    {
        $rows = ReconciliationReport::query()->whereIn('scope', ['import', 'sync'])->where('entity', 'sales')
            ->orderByDesc('id')->limit(5000)->get(['id', 'scope', 'import_job_id', 'source_sync_id', 'bronze_object_id', 'window_from', 'window_to', 'layers', 'discrepancies', 'status']);
        $latest = [];
        foreach ($rows as $r) {
            $key = $r->scope.':'.($r->scope === 'import' ? ($r->import_job_id ?? 'r'.$r->id) : ($r->source_sync_id ?? 'r'.$r->id));
            $latest[$key] ??= $r;   // ordenado por id descendente: el primero es el más reciente
        }
        $inside = [];
        $partial = [];
        $unplaced = 0;
        $withoutDeclared = [];
        $duplicates = [];
        $seenObjects = [];
        foreach ($latest as $r) {
            if ($r->bronze_object_id !== null) {
                if (isset($seenObjects[(int) $r->bronze_object_id])) {
                    $duplicates[] = (int) $r->id;

                    continue;
                }
                $seenObjects[(int) $r->bronze_object_id] = true;
            }
            if ($r->window_from === null || $r->window_to === null) {
                $unplaced++;

                continue;
            }
            $lf = $r->window_from->toDateString();
            $lt = $r->window_to->toDateString();
            if ($lt < $from || $lf > $to) {
                continue;   // fuera de la ventana
            }
            if ($lf < $from || $lt > $to) {
                $partial[] = (int) $r->id;

                continue;
            }
            $declared = $r->layers['source'] ?? null;
            if (! is_array($declared) || ! $this->hasDeclaredTotals($declared)) {
                $withoutDeclared[] = (int) $r->id;

                continue;
            }
            $inside[] = ['id' => (int) $r->id, 'from' => $lf, 'to' => $lt, 'source' => $declared, 'bronze_object_id' => $r->bronze_object_id,
                'status' => $r->status, 'discrepancies' => (array) ($r->discrepancies ?? []), 'silver' => (array) ($r->layers['silver'] ?? [])];
        }

        return ['inside' => $inside, 'partial' => $partial, 'unplaced' => $unplaced, 'without_declared' => $withoutDeclared, 'duplicates' => $duplicates];
    }

    /** @param array<string, mixed> $declared */
    private function hasDeclaredTotals(array $declared): bool
    {
        foreach (['rows', 'documents', 'lines', 'units', 'net_amount'] as $key) {
            if (isset($declared[$key]) && is_numeric($declared[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{inside: list<array<string, mixed>>, partial: list<int>, unplaced: int, without_declared: list<int>, duplicates: list<int>}  $loads
     * @return array<string, mixed>
     */
    private function sourceLayer(array $loads): array
    {
        if ($loads['inside'] === []) {
            $reason = 'No hay informes de reconciliación por carga (scope import o sync) con totales declarados por el origen cuyo rango de fechas quede dentro de la ventana; la capa source se alimenta de los informes por carga que escribe la captura de W2-A. No se inventan cifras.';
            $extra = [];
            if ($loads['partial'] !== []) {
                $extra[] = count($loads['partial']).' carga(s) cruzan el límite de la ventana';
            }
            if ($loads['unplaced'] > 0) {
                $extra[] = $loads['unplaced'].' informe(s) sin rango de fechas';
            }
            if ($loads['without_declared'] !== []) {
                $extra[] = count($loads['without_declared']).' informe(s) sin totales declarados por el origen';
            }

            return ['available' => false, 'reason' => $reason.($extra !== [] ? ' Descartados: '.implode('; ', $extra).'.' : ''), 'report_ids' => []];
        }
        $totals = array_fill_keys(['rows', 'documents', 'lines', 'units', 'net_amount', 'voided', 'credit_notes', 'duplicates', 'quarantined'], 0.0);
        $present = [];
        foreach ($loads['inside'] as $load) {
            foreach ($totals as $key => $_) {
                if (isset($load['source'][$key]) && is_numeric($load['source'][$key])) {
                    $totals[$key] += (float) $load['source'][$key];
                    $present[$key] = true;
                }
            }
        }
        $layer = ['available' => true, 'loads' => count($loads['inside']), 'report_ids' => array_column($loads['inside'], 'id')];
        foreach ($totals as $key => $value) {
            if (isset($present[$key])) {
                $layer[$key] = in_array($key, ['net_amount'], true) ? round($value, 4) : (int) round($value);
            }
        }
        $reasons = [];
        if ($loads['partial'] !== []) {
            $reasons[] = count($loads['partial']).' carga(s) cruzan el límite de la ventana (informes '.implode(', ', $loads['partial']).') y no se pueden prorratear';
        }
        if ($this->overlap($loads['inside'])) {
            $reasons[] = 'hay cargas cuyos rangos de fechas se solapan: los totales declarados no son aditivos';
        }
        $layer['comparable'] = $reasons === [];
        if ($reasons !== []) {
            $layer['reason'] = 'Los totales de origen no son comparables con la ventana: '.implode('; ', $reasons).'.';
        }
        if ($loads['without_declared'] !== []) {
            $layer['reports_without_declared_totals'] = $loads['without_declared'];
        }
        if ($loads['duplicates'] !== []) {
            $layer['duplicate_loads_ignored'] = $loads['duplicates'];
        }

        return $layer;
    }

    /** @param list<array<string, mixed>> $loads */
    private function overlap(array $loads): bool
    {
        usort($loads, fn ($a, $b) => strcmp($a['from'], $b['from']));
        for ($i = 1; $i < count($loads); $i++) {
            if ($loads[$i]['from'] <= $loads[$i - 1]['to']) {
                return true;
            }
        }

        return false;
    }

    // ───────────────────────── capa silver ─────────────────────────

    /** @return array<string, mixed> */
    private function silverLayer(Tenant $tenant, string $from, string $to): array
    {
        $firstCapture = DB::table('bronze_objects')->where('tenant_id', $tenant->id)->where('entity', 'sales')->min('received_at');
        $afterCapture = $firstCapture === null ? 'FALSE' : 'COALESCE(first_seen_at, created_at) >= ?::timestamptz';
        $bindings = $firstCapture === null ? [$tenant->id, $from, $to] : [$firstCapture, $tenant->id, $from, $to];
        $docs = DB::selectOne(
            "SELECT COUNT(*) AS documents,
                    COUNT(*) FILTER (WHERE status = 'voided') AS voided,
                    COUNT(*) FILTER (WHERE document_type = 'credit_note') AS credit_notes,
                    COUNT(*) FILTER (WHERE bronze_object_id IS NULL) AS without_bronze,
                    COUNT(*) FILTER (WHERE bronze_object_id IS NULL AND {$afterCapture}) AS without_bronze_after_capture
             FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?",
            $bindings,
        );
        $lines = DB::selectOne(
            "SELECT COUNT(*) AS lines,
                    COALESCE(SUM(quantity) FILTER (WHERE status = 'completed' AND NOT is_return), 0) AS units,
                    COALESCE(SUM(net_amount) FILTER (WHERE status = 'completed' AND NOT is_return), 0) AS sales_net_amount,
                    COALESCE(SUM(net_amount) FILTER (WHERE status = 'completed' AND is_return), 0) AS returns_net_amount
             FROM sales_line_versions WHERE tenant_id = ? AND is_current AND sale_date BETWEEN ? AND ?",
            [$tenant->id, $from, $to],
        );

        return [
            'available' => true,
            'source_of_truth' => 'sales_line_versions (versiones vigentes) y sales',
            'documents' => (int) $docs->documents,
            'voided' => (int) $docs->voided,
            'credit_notes' => (int) $docs->credit_notes,
            'lines' => (int) $lines->lines,
            'units' => round((float) $lines->units, 4),
            'net_amount' => round((float) $lines->sales_net_amount - (float) $lines->returns_net_amount, 4),
            'sales_net_amount' => round((float) $lines->sales_net_amount, 4),
            'returns_net_amount' => round((float) $lines->returns_net_amount, 4),
            'documents_without_bronze' => (int) $docs->without_bronze,
            'documents_without_bronze_after_capture' => (int) $docs->without_bronze_after_capture,
            'first_bronze_capture_at' => $firstCapture !== null ? CarbonImmutable::parse($firstCapture)->toIso8601String() : null,
        ];
    }

    // ───────────────────────── capa bronze ─────────────────────────

    /**
     * @param  list<array<string, mixed>>  $loads
     * @return array<string, mixed>
     */
    private function bronzeLayer(Tenant $tenant, string $from, string $to, array $loads): array
    {
        $ids = DB::table('sales')->where('tenant_id', $tenant->id)->whereBetween('sale_date', [$from, $to])->whereNotNull('bronze_object_id')->distinct()->pluck('bronze_object_id')
            ->merge(DB::table('sales_document_revisions')->where('tenant_id', $tenant->id)->whereBetween('sale_date', [$from, $to])->whereNotNull('bronze_object_id')->distinct()->pluck('bronze_object_id'))
            ->merge(array_filter(array_column($loads, 'bronze_object_id')))->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return ['available' => false, 'reason' => 'Ningún documento de la ventana ni ninguna carga de la ventana referencia un objeto Bronze (datos cargados antes de la captura Bronze o por una ruta sin captura).'];
        }
        // Filas del original: `row_count` o, si la captura solo dejó `metadata.rows_read` (como W2-A en archivos subidos), ese valor.
        $objects = BronzeObject::query()->whereIn('id', $ids->all())->get(['id', 'row_count', 'size_bytes', 'status', 'metadata'])
            ->each(fn (BronzeObject $o) => $o->setAttribute('known_rows', $o->row_count ?? (isset($o->metadata['rows_read']) && is_numeric($o->metadata['rows_read']) ? (int) $o->metadata['rows_read'] : null)));
        $withRows = $objects->filter(fn ($o) => $o->known_rows !== null);
        // Filas de los originales de las cargas seleccionadas (lo único comparable con `source.rows`): solo si TODAS las cargas tienen
        // objeto y cada objeto declara sus filas; con una suma parcial nunca se muestra una cifra.
        $loadObjectIds = collect($loads)->pluck('bronze_object_id')->filter()->map(fn ($v) => (int) $v)->unique();
        $loadObjects = $objects->whereIn('id', $loadObjectIds->all());
        $loadsComplete = $loads !== [] && $loadObjectIds->count() === count($loads) && $loadObjects->count() === $loadObjectIds->count()
            && $loadObjects->every(fn ($o) => $o->known_rows !== null);

        return [
            'available' => true,
            'objects' => $objects->count(),
            'bytes' => (int) $objects->sum('size_bytes'),
            // Con objetos sin número de filas la suma sería parcial: sin cifra (null) en lugar de un número que parece total.
            'rows' => $objects->count() === $withRows->count() ? (int) $withRows->sum('known_rows') : null,
            'objects_without_row_count' => $objects->count() - $withRows->count(),
            'loads_rows' => $loadsComplete ? (int) $loadObjects->sum('known_rows') : null,
            'purged' => $objects->where('status', 'purged')->count(),
            'object_ids' => $objects->pluck('id')->take(100)->values()->all(),
        ];
    }

    // ───────────────────────── capa gold ─────────────────────────

    /** @return array<string, mixed> */
    private function goldLayer(Tenant $tenant, string $from, string $to): array
    {
        $run = DB::table('forecast_runs')->where('tenant_id', $tenant->id)->where('status', 'completed')->whereNotNull('data_snapshot_id')
            ->where('data_to', '>=', $to)->where('data_from', '<=', $to)->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'version', 'data_snapshot_id', 'data_from', 'data_to']);
        if ($run === null) {
            return ['available' => false, 'reason' => 'No hay una ejecución de demanda completada, con huella de datos, cuyo rango de datos llegue al fin de la ventana.'];
        }
        $snapshot = DB::table('data_snapshots')->where('tenant_id', $tenant->id)->where('id', $run->data_snapshot_id)->first(['id', 'core', 'as_of', 'row_counts', 'created_at']);
        $counts = $snapshot !== null ? json_decode((string) $snapshot->row_counts, true) : null;
        if ($snapshot === null || ! is_array($counts) || ! isset($counts['sales']) || ! is_numeric($counts['sales'])) {
            return ['available' => false, 'reason' => 'La huella de datos de la última ejecución que cubre la ventana no registra row_counts de ventas.', 'forecast_run_id' => (int) $run->id];
        }
        $zone = $tenant->timezone ?: 'UTC';
        $cutoff = (string) $snapshot->as_of;
        $runDay = CarbonImmutable::parse((string) $snapshot->created_at)->setTimezone($zone)->toDateString();
        $atCutoff = $this->silverCells($tenant, $cutoff, $cutoff);
        $atRun = $runDay > $cutoff ? $this->silverCells($tenant, $cutoff, $runDay) : $atCutoff;

        return [
            'available' => true,
            'forecast_run_id' => (int) $run->id,
            'forecast_run_version' => (int) $run->version,
            'data_snapshot_id' => (int) $snapshot->id,
            'snapshot_as_of' => $cutoff,
            'snapshot_created_on' => $runDay,
            'rows' => (int) $counts['sales'],
            'unit' => 'celdas sucursal × producto × día de ventas válidas, de toda la historia hasta el corte de la ejecución',
            'silver_cells_known_at_cutoff' => $atCutoff,
            'silver_cells_known_at_run' => $atRun,
            'window_note' => 'Gold se compara por ejecución completa (historia hasta su corte), no por ventana: la ventana solo elige qué ejecución la cubre.',
        ];
    }

    /**
     * Celdas sucursal × producto × día de ventas válidas con fecha ≤ `$cutoff` tal como se conocían al terminar el día `$knowledge`:
     * la misma regla de visibilidad de la vista `sales_line_versions` y del motor (`engine/predictily_engine/data/asof.py`):
     * `max(sale_date, día_local(known_from)) ≤ knowledge` y `known_until` abierta o `día_local(known_until) − 1 ≥ knowledge`.
     */
    private function silverCells(Tenant $tenant, string $cutoff, string $knowledge): int
    {
        $zone = $tenant->timezone ?: 'UTC';
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM (
                SELECT v.branch_id, v.product_id, v.sale_date
                FROM sales_line_versions v
                WHERE v.tenant_id = ? AND v.status = 'completed' AND v.document_type IN ('invoice','sales_note','other') AND NOT v.is_return
                  AND v.sale_date <= ?::date
                  AND GREATEST(v.sale_date, CAST(v.known_from AT TIME ZONE CAST(? AS text) AS date)) <= ?::date
                  AND (v.known_until IS NULL OR (CAST(v.known_until AT TIME ZONE CAST(? AS text) AS date) - 1) >= ?::date)
                GROUP BY v.branch_id, v.product_id, v.sale_date
             ) cells",
            [$tenant->id, $cutoff, $zone, $knowledge, $zone, $knowledge],
        );

        return (int) $row->n;
    }

    // ───────────────────────── comparaciones ─────────────────────────

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $silver
     * @param  list<array<string, mixed>>  $loads
     * @param  array<string, mixed>  $bronze
     * @return list<array<string, mixed>>
     */
    private function compareSource(array $source, array $silver, array $loads, array $bronze): array
    {
        if (($source['available'] ?? false) !== true || ($source['comparable'] ?? false) !== true) {
            return [];
        }
        // Lo que las propias cargas ya explicaron. Entradas con el formato de W2-A (`explained`, `difference`, `unexplained`): la parte
        // explicada es `difference − unexplained` (la suma de sus categorías). Entradas sin esos campos: solo cuentan si el informe de la
        // carga está `explained` y la entrada trae una explicación.
        $explained = [];
        foreach ($loads as $load) {
            foreach ($load['discrepancies'] as $d) {
                if (($d['from'] ?? null) !== 'source' || ($d['to'] ?? null) !== 'silver' || ! in_array($d['metric'] ?? null, self::SOURCE_METRICS, true)
                    || ! isset($d['expected'], $d['observed']) || ! is_numeric($d['expected']) || ! is_numeric($d['observed'])) {
                    continue;
                }
                $difference = (float) $d['expected'] - (float) $d['observed'];
                if (array_key_exists('explained', $d)) {
                    $part = isset($d['difference'], $d['unexplained']) && is_numeric($d['difference']) && is_numeric($d['unexplained'])
                        ? (float) $d['difference'] - (float) $d['unexplained']
                        : ($d['explained'] === true ? $difference : 0.0);
                } else {
                    $part = $load['status'] === 'explained' && trim((string) ($d['explanation'] ?? '')) !== '' ? $difference : 0.0;
                }
                $explained[$d['metric']] = ($explained[$d['metric']] ?? 0.0) + $part;
            }
        }
        // Cada carga redondea sus importes a centavos: la tolerancia crece medio centavo por carga.
        $amountTolerance = self::AMOUNT_TOLERANCE + 0.005 * (int) ($source['loads'] ?? 0);
        $found = [];
        // Fuente → Bronze: el original de cada carga debe contener las filas que declaró el origen (solo con dato completo).
        if (($bronze['available'] ?? false) === true && ($bronze['loads_rows'] ?? null) !== null && isset($source['rows']) && (int) $source['rows'] !== (int) $bronze['loads_rows']) {
            $found[] = [
                'from' => 'source', 'to' => 'bronze', 'metric' => 'rows', 'expected' => (int) $source['rows'], 'observed' => (int) $bronze['loads_rows'],
                'explained' => false, 'explanation' => 'Los objetos Bronze de las cargas no contienen las mismas filas que declaró el origen.',
            ];
        }
        foreach (self::SOURCE_METRICS as $metric) {
            if (! isset($source[$metric]) || ! isset($silver[$metric])) {
                continue;
            }
            $expected = (float) $source[$metric];
            $observed = (float) $silver[$metric];
            $diff = $expected - $observed;
            $tolerance = $metric === 'net_amount' ? $amountTolerance : 0.0;
            if (abs($diff) <= $tolerance) {
                continue;
            }
            $residual = $diff - ($explained[$metric] ?? 0.0);
            $isExplained = isset($explained[$metric]) && abs($residual) <= $tolerance;
            $found[] = [
                'from' => 'source', 'to' => 'silver', 'metric' => $metric, 'expected' => $expected, 'observed' => $observed,
                'explained' => $isExplained,
                'explanation' => $isExplained
                    ? 'Diferencia explicada por los informes de las cargas (cuarentena, anulados, duplicados, versiones obsoletas u otras categorías declaradas).'
                    : (isset($explained[$metric])
                        ? sprintf('Las cargas explican %s de %s; quedan %s sin explicar.', $this->fmt($explained[$metric]), $this->fmt($diff), $this->fmt($residual))
                        : 'Diferencia sin explicación en los informes de las cargas.'),
            ];
        }

        return $found;
    }

    /**
     * Bronze → Silver: cada documento de la ventana debe poder rastrearse a su objeto original. Los documentos anteriores a la
     * primera captura Bronze de la empresa (o de una empresa sin objetos) son una brecha de linaje EXPLICADA; los posteriores
     * revelan una ruta de escritura sin captura.
     *
     * @param  array<string, mixed>  $silver
     * @return list<array<string, mixed>>
     */
    private function compareLineage(array $silver): array
    {
        $without = (int) $silver['documents_without_bronze'];
        if ($without === 0) {
            return [];
        }
        $after = (int) $silver['documents_without_bronze_after_capture'];
        $out = [];
        if ($without - $after > 0) {
            $out[] = [
                'from' => 'bronze', 'to' => 'silver', 'metric' => 'documents_without_bronze_before_capture', 'expected' => 0, 'observed' => $without - $after,
                'explained' => true,
                'explanation' => $silver['first_bronze_capture_at'] === null
                    ? 'La empresa no tiene objetos Bronze de ventas: los documentos se cargaron antes de la captura Bronze.'
                    : 'Documentos ingeridos antes de la primera captura Bronze de la empresa ('.$silver['first_bronze_capture_at'].'): su original no se conservó.',
            ];
        }
        if ($after > 0) {
            $out[] = [
                'from' => 'bronze', 'to' => 'silver', 'metric' => 'documents_without_bronze_after_capture', 'expected' => 0, 'observed' => $after,
                'explained' => false,
                'explanation' => 'Documentos ingeridos después de iniciada la captura Bronze que no referencian ningún objeto: una ruta de escritura no captura el original.',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $gold
     * @return list<array<string, mixed>>
     */
    private function compareGold(array $gold): array
    {
        if (($gold['available'] ?? false) !== true) {
            return [];
        }
        $rows = (int) $gold['rows'];
        if ($rows === (int) $gold['silver_cells_known_at_cutoff']) {
            return [];
        }
        if ($rows === (int) $gold['silver_cells_known_at_run']) {
            return [[
                'from' => 'silver', 'to' => 'gold', 'metric' => 'sales_cells', 'expected' => (int) $gold['silver_cells_known_at_cutoff'], 'observed' => $rows,
                'explained' => true,
                'explanation' => 'Ejecución vigente: el motor leyó lo recibido hasta el día de la ejecución ('.$gold['snapshot_created_on'].'), posterior a su corte; coincide con Silver a ese día.',
            ]];
        }

        return [[
            'from' => 'silver', 'to' => 'gold', 'metric' => 'sales_cells', 'expected' => (int) $gold['silver_cells_known_at_cutoff'], 'observed' => $rows,
            'explained' => false,
            'explanation' => sprintf(
                'Las celdas de Gold (%d) no coinciden con Silver al corte (%d) ni al día de la ejecución (%d): Silver cambió después de la ejecución o la ejecución usó otra lectura.',
                $rows, (int) $gold['silver_cells_known_at_cutoff'], (int) $gold['silver_cells_known_at_run'],
            ),
        ]];
    }

    /**
     * Capas que no se pudieron comparar: se registran como discrepancia visible para que el informe nunca sea `balanced` sin comparar.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $gold
     * @return list<array<string, mixed>>
     */
    private function notCompared(array $source, array $gold): array
    {
        $found = [];
        if (($source['compared'] ?? false) !== true) {
            $found[] = [
                'from' => 'source', 'to' => 'silver', 'metric' => 'layer_not_compared', 'expected' => null, 'observed' => null, 'explained' => true,
                'explanation' => 'Capa source NO comparada: '.($source['reason'] ?? 'no disponible'),
            ];
        }
        if (($gold['compared'] ?? false) !== true) {
            $found[] = [
                'from' => 'silver', 'to' => 'gold', 'metric' => 'layer_not_compared', 'expected' => null, 'observed' => null, 'explained' => true,
                'explanation' => 'Capa gold NO comparada: '.($gold['reason'] ?? 'no disponible'),
            ];
        }

        return $found;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("La fecha '{$field}' debe tener el formato AAAA-MM-DD.");
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("La fecha '{$field}' no es válida.");
        }

        return $date;
    }
}
