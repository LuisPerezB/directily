<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Services\Catalog\CatalogResolver;
use App\Support\Canonical\CanonicalContracts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Escritura idempotente y VERSIONADA de documentos de venta (importador de archivos, POST /sales/batch, sincronización SQL
 * y demo). Contrato temporal: ADR-V25-23 y migración 2026_03_01_000100.
 *
 * - Clave natural: (tenant_id, document_type, document_number).
 * - Documento nuevo → INSERT: `first_seen_at` = ahora, `current_revision` = 0, `ingestion_mode` el declarado por quien escribe y
 *   `version_known_from` NULL (backfill: conocido desde el inicio del día local de `sale_date`) o ahora (incremental).
 * - Existente con el MISMO `source_hash` → sin cambios (no se escribe; no cambia el modo de ingestión almacenado). Si el modo
 *   almacenado difiere del declarado se cuenta `unchanged_mode_mismatch` para que el no-op sea visible, no silencioso.
 * - Existente con distinto `source_hash`:
 *     a) ORDEN: si la entrante y la almacenada traen `source_recorded_at` y la entrante es ANTERIOR, se descarta como
 *        `stale_skipped` (no se escribe nada). Si alguna no trae marca de tiempo, gana la última ingestión y se cuenta en
 *        `updated_without_source_order`.
 *     b) ARCHIVO: antes de sobrescribir se copia la versión vigente a `sales_document_revisions` / `sale_item_revisions`
 *        con su ventana `[known_from, known_until)`.
 *     c) REEMPLAZO: se sustituyen cabecera y líneas con `current_revision + 1`; `version_known_from` e `ingestion_mode`
 *        son los de ESTA escritura. `first_seen_at` NO se actualiza nunca. `source_recorded_at` solo se reemplaza si la entrante
 *        trae marca (una actualización sin marca conserva la almacenada; se sigue contando `updated_without_source_order`).
 * - Todo por lotes, dentro de una transacción por lote y del bloqueo de aviso (advisory lock) por empresa.
 * - LINAJE (ADR-V25-24): cada versión escrita guarda `bronze_object_id` (objeto Bronze del original; `$context['bronze_object_id']`),
 *   `source_row` (primera fila del documento en el origen; `$doc['source_row']`) y `schema_version` (versión del contrato canónico de
 *   ventas con que se escribió). Al archivar, la revisión CONSERVA el linaje de SU versión (el de la fila vigente que se reemplaza); la
 *   versión nueva trae el suyo. Un documento sin cambios o descartado por versión anterior no se escribe, así que no cambia su linaje.
 *
 * Ventanas de conocimiento (INVARIANTE: en todo corte, a lo sumo UNA versión visible por documento; con la última escritura ya
 * pasada, exactamente la vigente). Una versión es visible al corte C si `known_from < medianoche local de C+1` y
 * (`known_until` nula o `>=` esa medianoche), es decir, si esa medianoche cae en `(known_from, known_until]`; las ventanas
 * de las versiones de un documento deben ser pues DISJUNTAS. Al archivar:
 *   - la nueva es incremental → `known_until` de la versión archivada = el instante de esta escritura (sin bajar nunca de su
 *     `known_from` efectivo: ventana vacía, jamás invertida). Cualquier revisión más antigua que aún terminara después de ese
 *     instante (reloj atrasado entre servidores) se recorta a él;
 *   - la nueva es backfill → una re-carga histórica COLAPSA TODA LA CADENA: la versión archivada y TODAS las revisiones más
 *     antiguas del documento quedan con `known_until = known_from` efectivo (ventana vacía). No basta con cerrar la inmediata:
 *     la nueva es conocida desde el inicio del día local de su `sale_date`, y una revisión anterior no vacía (p. ej. la
 *     histórica que una incremental cerró) se solaparía con ella y el documento se contaría dos veces en esos cortes.
 *     Se colapsa en vez de recortar en la `sale_date` nueva porque coincide con el contrato («una re-carga backfill colapsa
 *     versiones») y con el comportamiento de la versión inmediata, y no depende de que la re-carga cambie la fecha de venta.
 *     Las filas de revisión se conservan (auditoría, `change_kind`, hash, procedencia); solo dejan de ser visibles.
 */
final class SalesDocumentWriter
{
    public const STATE_NEW = 'new';

    public const STATE_UPDATED = 'updated';

    public const STATE_UNCHANGED = 'unchanged';

    public const STATE_STALE_SKIPPED = 'stale_skipped';

    /**
     * Clasifica documentos contra la base de datos (sin escribir).
     *
     * `state`: new | updated | unchanged | stale_skipped. `without_order` (solo `updated`): la entrante o la almacenada no
     * traen `source_recorded_at`, así que el orden se decidió por última ingestión. `stored`: cabecera almacenada (solo `updated`).
     * `mode_mismatch` (solo `unchanged`, y solo si se pasa `$mode`): el `ingestion_mode` almacenado difiere del declarado; el
     * documento no se reescribe, así que conserva el modo (y la ventana de conocimiento) con que se cargó.
     *
     * @param  list<array<string, mixed>>  $documents
     * @param  ?string  $mode  modo de ingestión de la escritura (backfill | incremental), para detectar `mode_mismatch`
     * @return array<string, array{state: string, id: ?int, without_order: bool, stored: ?object, mode_mismatch: bool}> doc_key => clasificación
     */
    public function classify(Tenant $tenant, array $documents, ?string $mode = null): array
    {
        $byType = [];
        foreach ($documents as $doc) {
            $byType[$doc['document_type']][] = $doc['document_number'];
        }
        $existing = [];
        foreach ($byType as $type => $numbers) {
            foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
                $rows = DB::table('sales')
                    ->where('tenant_id', $tenant->id)
                    ->where('document_type', $type)
                    ->whereIn('document_number', $chunk)
                    ->get(['id', 'document_number', 'source_hash', 'source_recorded_at', 'ingestion_mode', 'status', 'issued_at', 'sale_date', 'branch_id', 'customer_id', 'channel', 'currency', 'updated_at']);
                foreach ($rows as $row) {
                    $existing[$type.'|'.mb_strtoupper($row->document_number)] = $row;
                }
            }
        }

        $result = [];
        foreach ($documents as $doc) {
            $found = $existing[$doc['doc_key']] ?? null;
            if ($found === null) {
                $result[$doc['doc_key']] = ['state' => self::STATE_NEW, 'id' => null, 'without_order' => false, 'stored' => null, 'mode_mismatch' => false];
            } elseif ($found->source_hash === $doc['source_hash'] || (isset($doc['legacy_source_hash']) && $found->source_hash === $doc['legacy_source_hash'])) {
                // `legacy_source_hash`: la huella con el código de cliente CRUDO, como la calculaban las versiones anteriores a la forma única
                // del cliente (SalesDocumentAssembler::withTotals): un documento ya guardado con ella no cambió.
                $result[$doc['doc_key']] = [
                    'state' => self::STATE_UNCHANGED, 'id' => (int) $found->id, 'without_order' => false, 'stored' => null,
                    'mode_mismatch' => $mode !== null && (string) $found->ingestion_mode !== $mode,
                ];
            } else {
                $order = $this->sourceOrder($doc['source_recorded_at'] ?? null, $found->source_recorded_at);
                $result[$doc['doc_key']] = [
                    'state' => $order === 'older' ? self::STATE_STALE_SKIPPED : self::STATE_UPDATED,
                    'id' => (int) $found->id,
                    'without_order' => $order === 'unknown',
                    'stored' => $order === 'older' ? null : $found,
                    'mode_mismatch' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * Escribe un lote de documentos ya validados.
     *
     * `$context`: `ingestion_mode` (OBLIGATORIO: backfill | incremental), `import_job_id`, `data_source_id` y, solo para pruebas,
     * `now` (instante de la escritura).
     *
     * @param  list<array<string, mixed>>  $documents
     * @param  array{ingestion_mode?: ?string, import_job_id?: ?int, data_source_id?: ?int, bronze_object_id?: ?int, now?: CarbonInterface}  $context
     * @return array{new: int, updated: int, unchanged: int, stale_skipped: int, revisions_archived: int, updated_without_source_order: int, unchanged_mode_mismatch: int, products_created: int, branches_created: int, lines_written: int, states: array<string, string>}
     *                                                                                                                                                                                                                                                                      `states`: doc_key => estado (new | updated | unchanged | stale_skipped) de cada documento recibido
     *
     * @throws InvalidArgumentException si falta el modo de ingestión o no es válido
     */
    public function write(Tenant $tenant, array $documents, CatalogResolver $catalog, array $context = []): array
    {
        $mode = IngestionModeResolver::normalize($context['ingestion_mode'] ?? null)
            ?? throw new InvalidArgumentException('Todo camino de escritura de ventas debe fijar el modo de ingestión (backfill | incremental).');
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_skipped' => 0, 'revisions_archived' => 0, 'updated_without_source_order' => 0,
            'unchanged_mode_mismatch' => 0, 'products_created' => 0, 'branches_created' => 0, 'lines_written' => 0, 'states' => []];
        if ($documents === []) {
            return $counts;
        }

        return DB::transaction(function () use ($tenant, $documents, $catalog, $context, $mode, $counts): array {
            // Serializa escrituras de ventas por empresa (importaciones/API concurrentes).
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7421, $tenant->id]);
            $states = $this->classify($tenant, $documents, $mode);
            $toWrite = [];
            foreach ($documents as $doc) {
                $class = $states[$doc['doc_key']];
                $counts[$class['state']]++;
                $counts['states'][$doc['doc_key']] = $class['state'];
                if ($class['without_order']) {
                    $counts['updated_without_source_order']++;
                }
                if ($class['mode_mismatch']) {
                    $counts['unchanged_mode_mismatch']++;
                }
                if (in_array($class['state'], [self::STATE_NEW, self::STATE_UPDATED], true)) {
                    $toWrite[] = $doc;
                }
            }
            if ($toWrite === []) {
                return $counts;
            }

            // 1) Catálogos
            $counts['branches_created'] = $catalog->ensureBranches(array_values(array_unique(array_map(fn ($d) => $d['branch_code'], $toWrite))));
            $products = [];
            $customers = [];
            foreach ($toWrite as $doc) {
                foreach ($doc['lines'] as $line) {
                    $products[$line['product_sku']] ??= ['name' => $line['product_name'], 'category' => $line['category']];
                    if ($catalog->hasAliasFor($line['product_sku'])) {
                        // La vigencia del alias se evalúa con la fecha de CADA documento (ADR-V25-24), no con la de hoy.
                        $products[$line['product_sku']]['dates'][$doc['sale_date']] = true;
                    }
                }
                if ($doc['customer_code'] !== null) {
                    $customers[$doc['customer_code']] ??= ['segment' => $doc['customer_segment'], 'province' => $doc['customer_province']];
                    if ($catalog->hasCustomerAliases()) {
                        $customers[$doc['customer_code']]['dates'][$doc['sale_date']] = true;
                    }
                }
            }
            $counts['products_created'] = $catalog->ensureProducts($products);
            $customerIds = $catalog->ensureCustomers($customers);

            // 2) Archivo de las versiones vigentes que se van a reemplazar (ANTES de tocar `sales` y `sale_items`).
            $now = CarbonImmutable::instance($context['now'] ?? now());
            $nowText = $now->format('Y-m-d H:i:sP');
            $updated = array_values(array_filter($toWrite, fn ($d) => $states[$d['doc_key']]['state'] === self::STATE_UPDATED));
            if ($updated !== []) {
                $kinds = $this->changeKinds($tenant, $updated, $states, $catalog, $customerIds);
                $counts['revisions_archived'] = $this->archiveVersions($tenant, $kinds, $mode, $nowText, $context['import_job_id'] ?? null);
            }

            // 3) Cabeceras (upsert con RETURNING para obtener ids)
            $columns = ['tenant_id', 'branch_id', 'data_source_id', 'import_job_id', 'document_type', 'document_number', 'status', 'issued_at',
                'sale_date', 'customer_id', 'channel', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total', 'source_hash', 'source_recorded_at',
                'ingestion_mode', 'version_known_from', 'current_revision', 'first_seen_at', 'created_at', 'updated_at',
                'bronze_object_id', 'source_row', 'schema_version'];
            $schemaVersion = CanonicalContracts::version('sales');
            $bronzeObjectId = $context['bronze_object_id'] ?? null;
            $versionKnownFrom = $mode === IngestionModeResolver::BACKFILL ? null : $nowText;
            $ids = [];
            foreach (array_chunk($toWrite, 500) as $chunk) {
                $bindings = [];
                $placeholders = [];
                foreach ($chunk as $doc) {
                    $branchId = $catalog->branchId($doc['branch_code']);
                    array_push($bindings,
                        $tenant->id, $branchId, $context['data_source_id'] ?? null, $context['import_job_id'] ?? null,
                        $doc['document_type'], $doc['document_number'], $doc['status'], $doc['issued_at'], $doc['sale_date'],
                        $doc['customer_code'] !== null ? $catalog->customerIdFor($doc['customer_code'], $doc['sale_date'], $customerIds) : null,
                        $doc['channel'], $doc['currency'], $doc['subtotal'], $doc['discount_total'], $doc['tax_total'], $doc['total'],
                        $doc['source_hash'], $doc['source_recorded_at'] ?? null,
                        $mode, $versionKnownFrom, 0, $nowText, $nowText, $nowText,
                        $bronzeObjectId, $doc['source_row'] ?? null, $schemaVersion,
                    );
                    $placeholders[] = '('.implode(',', array_fill(0, count($columns), '?')).')';
                }
                // `first_seen_at` y `created_at` no se actualizan nunca; `current_revision` avanza solo cuando hay conflicto (versión nueva).
                // `source_recorded_at` solo se reemplaza si la entrante trae marca: una actualización sin ella (que gana por última
                // ingestión y se cuenta en `updated_without_source_order`) no borra la marca almacenada, que sigue ordenando versiones.
                $update = implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", [
                    'branch_id', 'import_job_id', 'status', 'issued_at', 'sale_date', 'customer_id', 'channel', 'currency',
                    'subtotal', 'discount_total', 'tax_total', 'total', 'source_hash',
                    'ingestion_mode', 'version_known_from', 'updated_at',
                    'bronze_object_id', 'source_row', 'schema_version',
                ]));
                $sql = 'INSERT INTO sales ('.implode(',', $columns).') VALUES '.implode(',', $placeholders)
                    .' ON CONFLICT (tenant_id, document_type, document_number) DO UPDATE SET '.$update
                    .', source_recorded_at = COALESCE(EXCLUDED.source_recorded_at, sales.source_recorded_at)'
                    .', current_revision = sales.current_revision + 1'
                    .', data_source_id = COALESCE(EXCLUDED.data_source_id, sales.data_source_id)'
                    .' RETURNING id, document_type, document_number';
                foreach (DB::select($sql, $bindings) as $row) {
                    $ids[$row->document_type.'|'.mb_strtoupper($row->document_number)] = (int) $row->id;
                }
            }

            // 4) Líneas: se reemplazan por completo en documentos actualizados (las anteriores ya quedaron archivadas).
            $updatedIds = array_map(fn ($d) => $ids[$d['doc_key']], $updated);
            foreach (array_chunk($updatedIds, 1000) as $chunk) {
                DB::table('sale_items')->where('tenant_id', $tenant->id)->whereIn('sale_id', $chunk)->delete();
            }

            $items = [];
            foreach ($toWrite as $doc) {
                $saleId = $ids[$doc['doc_key']];
                $branchId = $catalog->branchId($doc['branch_code']);
                foreach ($doc['lines'] as $line) {
                    $items[] = [
                        'tenant_id' => $tenant->id,
                        'sale_id' => $saleId,
                        'line_number' => $line['line_number'],
                        'product_id' => $catalog->productId($line['product_sku'], $doc['sale_date']),
                        'branch_id' => $branchId,
                        'sale_date' => $doc['sale_date'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_amount' => $line['discount'],
                        'net_amount' => $line['net_amount'],
                        'tax_amount' => $line['tax'],
                        'unit_cost' => $line['unit_cost'],
                        'is_return' => $line['is_return'],
                        'created_at' => $nowText,
                        'updated_at' => $nowText,
                    ];
                    if (count($items) >= 2000) {
                        DB::table('sale_items')->insert($items);
                        $counts['lines_written'] += count($items);
                        $items = [];
                    }
                }
            }
            if ($items !== []) {
                DB::table('sale_items')->insert($items);
                $counts['lines_written'] += count($items);
            }

            return $counts;
        });
    }

    /**
     * Orden de la versión entrante frente a la almacenada según `source_recorded_at`.
     * `older`: la entrante es ANTERIOR (se descarta). `newer`: igual o posterior (se aplica). `unknown`: falta alguna marca.
     */
    private function sourceOrder(?string $incoming, mixed $stored): string
    {
        if ($incoming === null || $stored === null) {
            return 'unknown';
        }

        return CarbonImmutable::parse($incoming)->getTimestamp() < CarbonImmutable::parse((string) $stored)->getTimestamp() ? 'older' : 'newer';
    }

    /**
     * Tipo de cambio de cada documento actualizado (status_changed | lines_changed | header_changed | mixed), comparando la
     * versión almacenada con la entrante. Un hash distinto sin diferencia visible en estado, cabecera ni líneas (p. ej. cambió el
     * segmento del cliente, que forma parte de la huella pero no de `sales`) se registra como `header_changed`.
     *
     * @param  list<array<string, mixed>>  $updated
     * @param  array<string, array{state: string, id: ?int, without_order: bool, stored: ?object}>  $states
     * @param  array<string, int>  $customerIds
     * @return array<int, string> sale_id => change_kind
     */
    private function changeKinds(Tenant $tenant, array $updated, array $states, CatalogResolver $catalog, array $customerIds): array
    {
        $storedLines = [];
        foreach (array_chunk(array_map(fn ($d) => $states[$d['doc_key']]['id'], $updated), 1000) as $chunk) {
            $rows = DB::table('sale_items')->where('tenant_id', $tenant->id)->whereIn('sale_id', $chunk)
                ->get(['sale_id', 'line_number', 'product_id', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'unit_cost', 'is_return']);
            foreach ($rows as $r) {
                $storedLines[(int) $r->sale_id][(int) $r->line_number] = self::lineSignature(
                    (int) $r->product_id, $r->quantity, $r->unit_price, $r->discount_amount, $r->net_amount, $r->tax_amount, $r->unit_cost, self::toBool($r->is_return),
                );
            }
        }

        $kinds = [];
        foreach ($updated as $doc) {
            $stored = $states[$doc['doc_key']]['stored'];
            $saleId = (int) $stored->id;
            $newLines = [];
            foreach ($doc['lines'] as $line) {
                $newLines[(int) $line['line_number']] = self::lineSignature(
                    (int) $catalog->productId($line['product_sku'], $doc['sale_date']), $line['quantity'], $line['unit_price'], $line['discount'], $line['net_amount'], $line['tax'], $line['unit_cost'], (bool) $line['is_return'],
                );
            }
            $oldLines = $storedLines[$saleId] ?? [];
            ksort($oldLines);
            ksort($newLines);
            $customerId = $doc['customer_code'] !== null ? $catalog->customerIdFor($doc['customer_code'], $doc['sale_date'], $customerIds) : null;

            $status = $stored->status !== $doc['status'];
            $lines = $oldLines !== $newLines;
            $header = CarbonImmutable::parse((string) $stored->issued_at)->getTimestamp() !== CarbonImmutable::parse($doc['issued_at'])->getTimestamp()
                || (string) $stored->sale_date !== $doc['sale_date']
                || (int) $stored->branch_id !== (int) $catalog->branchId($doc['branch_code'])
                || ($stored->customer_id === null ? null : (int) $stored->customer_id) !== $customerId
                || ($stored->channel ?? null) !== ($doc['channel'] ?? null)
                || trim((string) $stored->currency) !== $doc['currency'];

            $kinds[$saleId] = match (true) {
                ((int) $status + (int) $lines + (int) $header) > 1 => 'mixed',
                $status => 'status_changed',
                $lines => 'lines_changed',
                default => 'header_changed',
            };
        }

        return $kinds;
    }

    /**
     * Copia la versión vigente (cabecera y líneas) a las tablas de revisiones y deja DISJUNTAS las ventanas de conocimiento de
     * todas las revisiones del documento. Va en SQL por lotes: no consulta por documento.
     *
     * @param  array<int, string>  $kinds  sale_id => change_kind
     * @return int cabeceras archivadas
     */
    private function archiveVersions(Tenant $tenant, array $kinds, string $mode, string $nowText, ?int $importJobId): int
    {
        $archived = 0;
        $isBackfill = $mode === IngestionModeResolver::BACKFILL;
        // known_from efectivo (NULL = inicio del día local de la sale_date de la fila: regla de carga histórica) de la versión
        // vigente (s) y de una revisión ya archivada (r).
        $currentFrom = 'COALESCE(s.version_known_from, (s.sale_date::timestamp AT TIME ZONE t.timezone))';
        $archivedFrom = 'COALESCE(r.known_from, (r.sale_date::timestamp AT TIME ZONE t.timezone))';
        // known_until de la versión que se archiva: backfill → colapsa (= su known_from efectivo, ventana vacía); incremental → el
        // instante de esta escritura, sin bajar de su known_from efectivo (GREATEST evita ventanas invertidas).
        $until = $isBackfill ? $currentFrom : "GREATEST(?::timestamptz, {$currentFrom})";

        foreach (array_chunk($kinds, 1000, true) as $chunk) {
            $bindings = $isBackfill ? [] : [$nowText];
            $bindings[] = $importJobId;
            $values = [];
            foreach ($chunk as $saleId => $kind) {
                array_push($bindings, $saleId, $kind);
                $values[] = '(?::bigint, ?::text)';
            }
            $bindings[] = $tenant->id;

            $inserted = DB::affectingStatement(
                'INSERT INTO sales_document_revisions (tenant_id, sale_id, revision, document_type, status, issued_at, sale_date, branch_id, customer_id, channel, currency,'
                .' subtotal, discount_total, tax_total, total, source_hash, source_recorded_at, ingestion_mode, known_from, known_until,'
                .' import_job_id, superseded_by_import_job_id, data_source_id, change_kind, bronze_object_id, source_row, schema_version)'
                .' SELECT s.tenant_id, s.id, s.current_revision, s.document_type, s.status, s.issued_at, s.sale_date, s.branch_id, s.customer_id, s.channel, s.currency,'
                ." s.subtotal, s.discount_total, s.tax_total, s.total, s.source_hash, s.source_recorded_at, s.ingestion_mode, s.version_known_from, {$until},"
                .' s.import_job_id, ?::bigint, s.data_source_id, v.change_kind, s.bronze_object_id, s.source_row, s.schema_version'
                .' FROM sales s JOIN tenants t ON t.id = s.tenant_id'
                .' JOIN (VALUES '.implode(',', $values).') AS v(sale_id, change_kind) ON v.sale_id = s.id'
                .' WHERE s.tenant_id = ?',
                $bindings,
            );
            if ($inserted !== count($chunk)) {
                throw new RuntimeException("No se pudieron archivar todas las versiones de documentos de venta ({$inserted} de ".count($chunk).').');
            }
            $archived += $inserted;

            $saleIds = array_keys($chunk);
            $this->closeOlderWindows($tenant, $saleIds, $isBackfill, $nowText, $archivedFrom);

            DB::affectingStatement(
                'INSERT INTO sale_item_revisions (tenant_id, sale_id, revision, line_number, product_id, branch_id, sale_date, quantity, unit_price,'
                .' discount_amount, net_amount, tax_amount, unit_cost, is_return, promotion_id)'
                .' SELECT si.tenant_id, si.sale_id, s.current_revision, si.line_number, si.product_id, si.branch_id, si.sale_date, si.quantity, si.unit_price,'
                .' si.discount_amount, si.net_amount, si.tax_amount, si.unit_cost, si.is_return, si.promotion_id'
                .' FROM sale_items si JOIN sales s ON s.tenant_id = si.tenant_id AND s.id = si.sale_id'
                .' WHERE si.tenant_id = ? AND si.sale_id IN ('.implode(',', array_fill(0, count($saleIds), '?')).')',
                [$tenant->id, ...$saleIds],
            );
        }

        return $archived;
    }

    /**
     * Deja disjuntas las ventanas de las revisiones MÁS ANTIGUAS que la recién archivada (mismas `sale_id`), en el mismo lote SQL:
     *   - backfill: las colapsa todas (`known_until` = su `known_from` efectivo), ver la documentación de la clase;
     *   - incremental: recorta a este instante las que terminarían después de él (no ocurre con relojes monótonos; defensa ante
     *     desfases de reloj entre servidores).
     * Solo toca ventanas NO vacías que realmente se solapan con la nueva versión. Corre ANTES de reemplazar `sales`, así que
     * `s.current_revision` aún es la de la versión recién archivada y `r.revision < s.current_revision` selecciona las anteriores.
     *
     * @param  list<int>  $saleIds
     */
    private function closeOlderWindows(Tenant $tenant, array $saleIds, bool $isBackfill, string $nowText, string $archivedFrom): void
    {
        $set = $isBackfill ? $archivedFrom : "GREATEST({$archivedFrom}, LEAST(r.known_until, ?::timestamptz))";
        $bindings = $isBackfill ? [] : [$nowText];
        array_push($bindings, $tenant->id, ...$saleIds);
        $overlap = '';
        if (! $isBackfill) {
            $overlap = ' AND r.known_until > ?::timestamptz';
            $bindings[] = $nowText;
        }
        DB::affectingStatement(
            "UPDATE sales_document_revisions r SET known_until = {$set}"
            .' FROM sales s, tenants t'
            .' WHERE s.tenant_id = r.tenant_id AND s.id = r.sale_id AND t.id = r.tenant_id'
            .' AND r.tenant_id = ? AND r.sale_id IN ('.implode(',', array_fill(0, count($saleIds), '?')).')'
            ." AND r.revision < s.current_revision AND r.known_until > {$archivedFrom}{$overlap}",
            $bindings,
        );
    }

    private static function lineSignature(int $productId, mixed $quantity, mixed $unitPrice, mixed $discount, mixed $net, mixed $tax, mixed $unitCost, bool $isReturn): string
    {
        $money = fn (mixed $v): string => sprintf('%.4f', (float) $v);

        return implode('|', [$productId, $money($quantity), $money($unitPrice), $money($discount), $money($net), $money($tax), $unitCost === null ? '' : $money($unitCost), $isReturn ? '1' : '0']);
    }

    private static function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
