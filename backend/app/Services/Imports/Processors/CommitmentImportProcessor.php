<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Models\CommercialCommitment;
use App\Services\Catalog\CatalogResolver;
use App\Services\DataCore\Units\UnitConverter;
use App\Services\DataCore\Units\UnitNormalizer;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use App\Support\Privacy\CustomerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compromisos comerciales (cotización / pedido / contrato), una fila por línea. No son ventas realizadas.
 *
 * - Identidad: (tipo, identificador externo). Reimportar el mismo contenido no cambia nada; un contenido distinto
 *   actualiza la cabecera, reemplaza las líneas y agrega una REVISIÓN (conserva el estado conocido en cada momento).
 * - Cancelaciones: `cancelled_at` o estado «cancelado». Una cancelación sin fecha queda con fecha desconocida.
 * - Cumplimiento: `quantity_fulfilled` por línea; pendiente = max(0, cantidad − cumplida).
 * - `known_at` opcional: NULL = la fuente no declaró cuándo conocía esta versión (nunca se rellena con la ingestión).
 * - Unidades: una unidad distinta de la del producto exige una conversión declarada; si no, error (sin conversiones silenciosas).
 */
final class CommitmentImportProcessor implements ImportProcessor
{
    private const STATUS_MAP = [
        'open' => ['open', 'abierto', 'abierta', 'pendiente', 'vigente', 'emitido', 'emitida'],
        'confirmed' => ['confirmed', 'confirmado', 'confirmada', 'aprobado', 'aprobada', 'aceptado', 'aceptada', 'en_proceso'],
        'partially_fulfilled' => ['partially_fulfilled', 'parcial', 'entrega_parcial', 'entregado_parcial', 'cumplido_parcial', 'parcialmente_cumplido'],
        'fulfilled' => ['fulfilled', 'cumplido', 'cumplida', 'entregado', 'entregada', 'facturado', 'facturada', 'completado', 'completada', 'cerrado'],
        'cancelled' => ['cancelled', 'canceled', 'cancelado', 'cancelada', 'anulado', 'anulada', 'rechazado', 'rechazada'],
        'expired' => ['expired', 'vencido', 'vencida', 'expirado', 'expirada', 'caducado', 'caducada'],
    ];

    private const TYPE_MAP = [
        'quote' => ['quote', 'cotizacion', 'proforma', 'presupuesto', 'oferta', 'cot'],
        'order' => ['order', 'pedido', 'orden', 'orden_de_venta', 'pedido_confirmado', 'orden_de_compra', 'sales_order', 'oc'],
        'contract' => ['contract', 'contrato', 'convenio', 'acuerdo_marco'],
    ];

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $tz = $tenant->timezone ?: 'America/Guayaquil';
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $converter = new UnitConverter($tenant);
        $productUnits = [];
        foreach (DB::table('products')->where('tenant_id', $tenant->id)->get(['id', 'sku', 'unit']) as $row) {
            $productUnits[CatalogResolver::norm($row->sku)] = ['id' => (int) $row->id, 'unit' => UnitNormalizer::normalize($row->unit) ?? 'unidad'];
        }
        $maxDate = CarbonImmutable::now($tz)->endOfDay()->addDay();
        $currency = $tenant->currency ?: 'USD';

        $docs = [];
        $total = 0;
        $rowCount = $ctx->countRows();
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $total = $n;
            $issues = [];

            $rawType = $p->string($raw['commitment_type'] ?? null, 40);
            $type = $this->enumValue(self::TYPE_MAP, $rawType);
            if ($type === null) {
                $issues[] = $rawType === null
                    ? Issue::error('required', 'Falta el tipo de compromiso (cotización, pedido o contrato).', 'commitment_type')
                    : Issue::error('invalid_value', "Tipo de compromiso no reconocido: '{$rawType}'. Use quote, order o contract.", 'commitment_type', $rawType);
            }
            $externalId = $p->string($raw['external_id'] ?? null, 100);
            if ($externalId === null) {
                $issues[] = Issue::error('required', 'Falta el identificador externo del compromiso.', 'external_id');
            }
            $key = $type !== null && $externalId !== null ? $type.'|'.mb_strtoupper($externalId) : null;

            $issuedAt = $this->moment($p, $raw['issued_at'] ?? null, 'issued_at', true, $maxDate, $issues);
            $knownAt = $this->moment($p, $raw['known_at'] ?? null, 'known_at', false, $maxDate, $issues);
            $cancelledAt = $this->moment($p, $raw['cancelled_at'] ?? null, 'cancelled_at', false, $maxDate, $issues);
            $validFrom = $this->day($p, $raw['valid_from'] ?? null, 'valid_from', $issues);
            $validTo = $this->day($p, $raw['valid_to'] ?? null, 'valid_to', $issues);
            $expected = $this->day($p, $raw['expected_delivery_date'] ?? null, 'expected_delivery_date', $issues);
            $delivery = $this->day($p, $raw['delivery_date'] ?? null, 'delivery_date', $issues);
            if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
                $issues[] = Issue::error('invalid_date', 'La vigencia termina antes de empezar.', 'valid_to', $validTo);
            }
            if ($issuedAt !== null && $knownAt !== null && $knownAt->lessThan($issuedAt->startOfDay())) {
                $issues[] = Issue::warning('known_before_issued', 'La fecha en que la fuente conocía el compromiso es anterior a su emisión.', 'known_at', $raw['known_at']);
            }
            if ($issuedAt !== null && $cancelledAt !== null && $cancelledAt->lessThan($issuedAt)) {
                $issues[] = Issue::warning('cancelled_before_issued', 'La cancelación es anterior a la emisión.', 'cancelled_at', $raw['cancelled_at']);
            }

            $rawStatus = $p->string($raw['status'] ?? null, 40);
            $status = $this->enumValue(self::STATUS_MAP, $rawStatus);
            if ($rawStatus !== null && $status === null) {
                $issues[] = Issue::warning('unknown_status', "Estado no reconocido: '{$rawStatus}'; se derivará de la cancelación y las cantidades cumplidas.", 'status', $rawStatus);
            }

            $branchCode = $p->string($raw['branch_code'] ?? null, 50);
            if ($branchCode !== null) {
                [$branchCode, $branchIssues] = $catalog->checkBranch($branchCode);
                $issues = array_merge($issues, $branchIssues);
            }
            $customerCode = $p->string($raw['customer_code'] ?? null, 100);
            $rawCurrency = $p->string($raw['currency'] ?? null, 10);
            if ($rawCurrency !== null && mb_strtoupper($rawCurrency) !== $currency && ! in_array(mb_strtoupper($rawCurrency), ['$', 'US$', 'DOLAR', 'DOLARES', 'DÓLARES'], true)) {
                $issues[] = Issue::error('currency_mismatch', "La moneda ({$rawCurrency}) no coincide con la moneda de la empresa ({$currency}).", 'currency', $rawCurrency);
            }

            $lineNumber = null;
            if (! ValueParser::isBlank($raw['line_number'] ?? null)) {
                $lineNumber = $p->integer($raw['line_number']);
                if ($lineNumber === null || $lineNumber < 1) {
                    $issues[] = Issue::warning('invalid_number', 'Número de línea no válido; se usará el orden de aparición.', 'line_number', $raw['line_number']);
                    $lineNumber = null;
                }
            }

            $sku = $p->string($raw['product_sku'] ?? null, 100);
            if ($sku === null) {
                $issues[] = Issue::error('required', 'Falta el código de producto.', 'product_sku');
            } else {
                $issues = array_merge($issues, $catalog->checkProduct($sku));
            }
            $quantity = $this->amount($p, $raw['quantity'] ?? null, 'quantity', true, $issues, false);
            $fulfilled = $this->amount($p, $raw['quantity_fulfilled'] ?? null, 'quantity_fulfilled', false, $issues, true) ?? 0.0;
            $price = $this->amount($p, $raw['unit_price'] ?? null, 'unit_price', false, $issues, true);

            // Unidades: sin conversiones silenciosas.
            $unit = UnitNormalizer::normalize($p->string($raw['unit'] ?? null, 20));
            $sourceQuantity = null;
            $sourceUnit = null;
            $factor = null;
            if ($quantity !== null && $sku !== null) {
                $product = $productUnits[CatalogResolver::norm($sku)] ?? null;
                $productUnit = $product['unit'] ?? 'unidad'; // los productos nuevos se crean con la unidad por defecto
                if ($unit !== null && $unit !== $productUnit) {
                    $conversion = $converter->resolve($product['id'] ?? null, $unit, $productUnit, $issuedAt?->toDateString());
                    if ($conversion === null) {
                        $issues[] = Issue::error('unit_conversion_missing', "La unidad '{$unit}' difiere de la del producto ('{$productUnit}') y no hay una conversión declarada.", 'unit', $unit);
                    } else {
                        $sourceQuantity = $quantity;
                        $sourceUnit = $unit;
                        $factor = $conversion->factor;
                        $quantity = $conversion->convert($quantity);
                        $fulfilled = $conversion->convert($fulfilled);
                        $price = $price !== null ? $price / $conversion->factor : null;
                        $unit = $productUnit;
                        $issues[] = Issue::warning('unit_converted', "Cantidad convertida de '{$sourceUnit}' a '{$productUnit}' con la conversión declarada (factor ".rtrim(rtrim(number_format($factor, 6, '.', ''), '0'), '.').').', 'unit', $sourceUnit);
                    }
                } else {
                    $unit = $productUnit;
                }
            }
            if ($quantity !== null && $fulfilled > $quantity + 1e-9) {
                $issues[] = Issue::warning('fulfilled_exceeds_quantity', 'La cantidad cumplida supera la comprometida; el pendiente se toma como cero.', 'quantity_fulfilled', $raw['quantity_fulfilled'] ?? null);
            }

            $line = null;
            $hasError = count(array_filter($issues, fn (Issue $i) => $i->isError())) > 0;
            if (! $hasError && $key !== null && $issuedAt !== null && $quantity !== null) {
                $header = [
                    'commitment_type' => $type, 'external_id' => $externalId, 'status' => $status,
                    'issued_at' => $issuedAt->utc()->format('Y-m-d H:i:sP'),
                    'known_at' => $knownAt?->utc()->format('Y-m-d H:i:sP'),
                    'valid_from' => $validFrom, 'valid_to' => $validTo, 'expected_delivery_date' => $expected,
                    'cancelled_at' => $cancelledAt?->utc()->format('Y-m-d H:i:sP'),
                    'branch_code' => $branchCode, 'customer_code' => $customerCode, 'currency' => $currency,
                ];
                $line = [
                    'line_number' => $lineNumber, 'product_sku' => $sku, 'quantity' => round($quantity, 4),
                    'quantity_fulfilled' => round($fulfilled, 4), 'unit' => $unit, 'unit_price' => $price !== null ? round($price, 4) : null,
                    'delivery_date' => $delivery, 'source_quantity' => $sourceQuantity !== null ? round($sourceQuantity, 4) : null,
                    'source_unit' => $sourceUnit, 'conversion_factor' => $factor,
                ];
            }

            if ($line !== null) {
                $doc = $docs[$key] ?? ['header' => $header, 'lines' => [], 'line_numbers' => [], 'hashes' => [], 'next_line' => 1, 'invalid' => false, 'first_row' => $n];
                $doc['header'] ??= $header; // el documento nació de una fila con error: la cabecera se toma de la primera fila válida
                if ($doc['header'] !== $header) {
                    $issues[] = Issue::error('document_conflict', 'El compromiso aparece con datos de cabecera distintos en otra fila.', 'external_id', $externalId);
                    $doc['invalid'] = true;
                } else {
                    $hash = md5(serialize($line));
                    if (isset($doc['hashes'][$hash])) {
                        $issues[] = Issue::warning('duplicate_in_file', 'Fila idéntica repetida dentro del mismo compromiso; se omite.', null, $externalId);
                    } elseif ($lineNumber !== null && isset($doc['line_numbers'][$lineNumber])) {
                        $issues[] = Issue::error('duplicate_in_file', "Número de línea {$lineNumber} repetido en el compromiso.", 'line_number', $lineNumber);
                        $doc['invalid'] = true;
                    } else {
                        $doc['hashes'][$hash] = true;
                        $number = $lineNumber ?? $doc['next_line'];
                        while (isset($doc['line_numbers'][$number])) {
                            $number++;
                        }
                        $doc['line_numbers'][$number] = true;
                        $doc['next_line'] = max($doc['next_line'], $number + 1);
                        $doc['lines'][] = ['line_number' => $number] + $line;
                    }
                }
                $docs[$key] = $doc;
            }
            $ctx->issues->addMany($n, $issues);
            if ($key !== null && $ctx->issues->rowHasError($n)) {
                $docs[$key] ??= ['header' => null, 'lines' => [], 'line_numbers' => [], 'hashes' => [], 'next_line' => 1, 'invalid' => true, 'first_row' => $n];
                $docs[$key]['invalid'] = true;
            }
            $ctx->progress($n, $rowCount, 5, 60);
        }

        // Cierre de cada compromiso: estado derivado, huella, revisiones.
        $valid = [];
        $invalid = 0;
        foreach ($docs as $key => $doc) {
            if ($doc['invalid'] || $doc['lines'] === [] || $doc['header'] === null) {
                $invalid++;

                continue;
            }
            $valid[$key] = $this->finalize($doc, $ctx);
        }
        $ctx->issues->flush();

        $stats = [
            'rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => max(0, $total - $ctx->issues->rowsWithErrors()),
            'commitments_total' => count($docs), 'commitments_valid' => count($valid), 'commitments_invalid' => $invalid,
            'commitments_new' => 0, 'commitments_updated' => 0, 'commitments_unchanged' => 0,
            'cancelled_commitments' => count(array_filter($valid, fn (array $d) => $d['header']['status'] === 'cancelled')),
            'lines_valid' => array_sum(array_map(fn (array $d) => count($d['lines']), $valid)),
            'products_new' => $catalog->productsToCreate(), 'branches_new' => $catalog->branchesToCreate(),
            'date_from' => null, 'date_to' => null, 'lines_written' => 0,
        ];
        foreach ($valid as $doc) {
            $day = substr(CarbonImmutable::parse($doc['header']['issued_at'])->setTimezone($tz)->toDateString(), 0, 10);
            $stats['date_from'] = $stats['date_from'] === null || $day < $stats['date_from'] ? $day : $stats['date_from'];
            $stats['date_to'] = $stats['date_to'] === null || $day > $stats['date_to'] ? $day : $stats['date_to'];
        }
        $existing = $this->existing($tenant->id, $valid);
        $toWrite = [];
        foreach ($valid as $key => $doc) {
            $current = $existing[$key] ?? null;
            if ($current === null) {
                $stats['commitments_new']++;
                $toWrite[$key] = $doc;
            } elseif ($current->source_hash === $doc['source_hash']) {
                $stats['commitments_unchanged']++;
            } else {
                $stats['commitments_updated']++;
                $toWrite[$key] = $doc;
            }
        }
        $stats['normalized_preview'] = array_slice(array_map(fn (array $d) => $d['header'] + ['lines' => count($d['lines'])], array_values($valid)), 0, 20);

        if ($ctx->commit && $toWrite !== []) {
            $stats['lines_written'] = $this->write($ctx, $catalog, $toWrite, $existing);
        }

        $issues = $ctx->issues->summary();
        if ($stats['commitments_updated'] > 0) {
            $issues[] = ['code' => 'existing_commitment_changed', 'severity' => 'warning', 'count' => $stats['commitments_updated'],
                'message' => "{$stats['commitments_updated']} compromisos ya existían con contenido distinto y se actualizarán (queda una revisión)."];
        }

        return ['stats' => $stats, 'issues' => $issues];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function finalize(array $doc, ImportRunContext $ctx): array
    {
        usort($doc['lines'], fn (array $a, array $b) => $a['line_number'] <=> $b['line_number']);
        $header = $doc['header'];
        $ordered = array_sum(array_column($doc['lines'], 'quantity'));
        $done = array_sum(array_map(fn (array $l) => min($l['quantity_fulfilled'], $l['quantity']), $doc['lines']));
        $status = $header['status'];
        if ($header['cancelled_at'] !== null && $status !== 'cancelled') {
            if ($status !== null) {
                $ctx->issues->add($doc['first_row'], Issue::warning('status_overridden', "El compromiso tiene fecha de cancelación: se registra como cancelado (estado original '{$status}').", 'status', $status));
            }
            $status = 'cancelled';
        }
        $status ??= match (true) {
            $ordered > 0 && $done >= $ordered - 1e-9 => 'fulfilled',
            $done > 0 => 'partially_fulfilled',
            $header['commitment_type'] === 'quote' => 'open',
            default => 'confirmed',
        };
        if ($status === 'cancelled' && $header['cancelled_at'] === null) {
            $ctx->issues->add($doc['first_row'], Issue::warning('cancelled_without_date', 'Compromiso cancelado sin fecha de cancelación: la fecha queda desconocida y no se podrá reconstruir su estado a un corte anterior.', 'cancelled_at'));
        }
        if ($status === 'fulfilled' && $done < $ordered - 1e-9) {
            $ctx->issues->add($doc['first_row'], Issue::warning('status_quantity_mismatch', 'El estado es «cumplido» pero las cantidades cumplidas no cubren lo comprometido.', 'status'));
        }
        $header['status'] = $status;

        $canonical = [
            $header['commitment_type'], mb_strtoupper($header['external_id']), $status, $header['issued_at'], $header['known_at'],
            $header['valid_from'], $header['valid_to'], $header['expected_delivery_date'], $header['cancelled_at'],
            $header['branch_code'] !== null ? mb_strtoupper($header['branch_code']) : null,
            $header['customer_code'] !== null ? CustomerIdentity::fromCode($header['customer_code'])['external_code'] : null,
            $header['currency'],
            array_map(fn (array $l) => [$l['line_number'], mb_strtoupper($l['product_sku']), sprintf('%.4f', $l['quantity']), sprintf('%.4f', $l['quantity_fulfilled']),
                $l['unit'], $l['unit_price'] === null ? null : sprintf('%.4f', $l['unit_price']), $l['delivery_date'],
                $l['source_quantity'] === null ? null : sprintf('%.4f', $l['source_quantity']), $l['source_unit']], $doc['lines']),
        ];
        $doc['header'] = $header;
        $doc['source_hash'] = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE) ?: '');

        return $doc;
    }

    /**
     * @param  array<string, array<string, mixed>>  $valid
     * @return array<string, object>
     */
    private function existing(int $tenantId, array $valid): array
    {
        $byType = [];
        foreach ($valid as $doc) {
            $byType[$doc['header']['commitment_type']][] = $doc['header']['external_id'];
        }
        $existing = [];
        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                foreach (DB::table('commercial_commitments')->where('tenant_id', $tenantId)->where('commitment_type', $type)->whereIn('external_id', $chunk)->get(['id', 'external_id', 'source_hash']) as $row) {
                    $existing[$type.'|'.mb_strtoupper($row->external_id)] = $row;
                }
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, array<string, mixed>>  $toWrite
     * @param  array<string, object>  $existing
     */
    private function write(ImportRunContext $ctx, CatalogResolver $catalog, array $toWrite, array $existing): int
    {
        $tenant = $ctx->tenant;
        $linesWritten = 0;
        $now = now()->format('Y-m-d H:i:sP');
        foreach (array_chunk($toWrite, 200, true) as $chunk) {
            $linesWritten += DB::transaction(function () use ($chunk, $catalog, $tenant, $ctx, $existing, $now): int {
                DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7434, $tenant->id]);
                $catalog->ensureBranches(array_values(array_unique(array_filter(array_map(fn ($d) => $d['header']['branch_code'], $chunk)))));
                $products = [];
                $customers = [];
                foreach ($chunk as $doc) {
                    foreach ($doc['lines'] as $line) {
                        $products[$line['product_sku']] ??= ['name' => null, 'category' => null];
                    }
                    if ($doc['header']['customer_code'] !== null) {
                        $customers[$doc['header']['customer_code']] ??= ['segment' => null, 'province' => null];
                    }
                }
                $catalog->ensureProducts($products);
                $customerIds = $catalog->ensureCustomers($customers);
                $written = 0;
                foreach ($chunk as $key => $doc) {
                    $h = $doc['header'];
                    $row = [
                        'customer_id' => $h['customer_code'] !== null ? ($customerIds[$h['customer_code']] ?? null) : null,
                        'branch_id' => $h['branch_code'] !== null ? $catalog->branchId($h['branch_code']) : null,
                        'status' => $h['status'], 'issued_at' => $h['issued_at'], 'known_at' => $h['known_at'],
                        'valid_from' => $h['valid_from'], 'valid_to' => $h['valid_to'], 'expected_delivery_date' => $h['expected_delivery_date'],
                        'cancelled_at' => $h['cancelled_at'], 'currency' => $h['currency'], 'source_hash' => $doc['source_hash'], 'updated_at' => $now,
                    ];
                    $current = $existing[$key] ?? null;
                    if ($current === null) {
                        $id = (int) DB::table('commercial_commitments')->insertGetId($row + [
                            'tenant_id' => $tenant->id, 'commitment_type' => $h['commitment_type'], 'external_id' => $h['external_id'],
                            'data_source_id' => $ctx->job->data_source_id, 'created_at' => $now,
                        ]);
                    } else {
                        $id = (int) $current->id;
                        DB::table('commercial_commitments')->where('tenant_id', $tenant->id)->where('id', $id)->update(
                            $row + ($ctx->job->data_source_id !== null ? ['data_source_id' => $ctx->job->data_source_id] : [])
                        );
                        DB::table('commercial_commitment_items')->where('tenant_id', $tenant->id)->where('commitment_id', $id)->delete();
                    }
                    $items = array_map(fn (array $l) => [
                        'tenant_id' => $tenant->id, 'commitment_id' => $id, 'line_number' => $l['line_number'],
                        'product_id' => $catalog->productId($l['product_sku']), 'quantity' => $l['quantity'],
                        'quantity_fulfilled' => $l['quantity_fulfilled'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'],
                        'delivery_date' => $l['delivery_date'], 'source_quantity' => $l['source_quantity'], 'source_unit' => $l['source_unit'],
                        'conversion_factor' => $l['conversion_factor'], 'created_at' => $now, 'updated_at' => $now,
                    ], $doc['lines']);
                    DB::table('commercial_commitment_items')->insert($items);
                    $written += count($items);

                    $revision = (int) DB::table('commercial_commitment_revisions')->where('tenant_id', $tenant->id)->where('commitment_id', $id)->max('revision') + 1;
                    DB::table('commercial_commitment_revisions')->insert([
                        'tenant_id' => $tenant->id, 'commitment_id' => $id, 'import_job_id' => $ctx->job->id, 'revision' => $revision,
                        'status' => $h['status'], 'known_at' => $h['known_at'], 'cancelled_at' => $h['cancelled_at'],
                        'source_hash' => $doc['source_hash'], 'recorded_at' => $now,
                        // El código de cliente (puede ser cédula/RUC) nunca se guarda en claro: solo su identidad seudonimizada.
                        'snapshot' => json_encode(['header' => array_merge($h, ['customer_code' => $h['customer_code'] !== null ? CustomerIdentity::fromCode($h['customer_code'])['external_code'] : null]), 'lines' => $doc['lines']], JSON_UNESCAPED_UNICODE),
                    ]);
                }

                return $written;
            });
        }

        return $linesWritten;
    }

    /** @param array<string, list<string>> $map */
    private function enumValue(array $map, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = ValueParser::key($raw);
        foreach ($map as $value => $synonyms) {
            if (in_array($key, $synonyms, true)) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<Issue> $issues */
    private function moment(ValueParser $p, mixed $raw, string $field, bool $required, CarbonImmutable $max, array &$issues): ?CarbonImmutable
    {
        if (ValueParser::isBlank($raw)) {
            if ($required) {
                $issues[] = Issue::error('required', 'Falta la fecha de emisión.', $field);
            }

            return null;
        }
        $parsed = $p->dateTime($raw);
        if ($parsed === null) {
            $issues[] = Issue::error('invalid_date', 'Fecha no válida. Revise el formato (p. ej. 2026-03-15 o 15/03/2026).', $field, $raw);

            return null;
        }
        if ($parsed[0]->greaterThan($max)) {
            $issues[] = Issue::error('future_date', 'La fecha está en el futuro.', $field, $raw);

            return null;
        }

        return $parsed[0];
    }

    /** @param list<Issue> $issues */
    private function day(ValueParser $p, mixed $raw, string $field, array &$issues): ?string
    {
        if (ValueParser::isBlank($raw)) {
            return null;
        }
        $parsed = $p->dateTime($raw);
        if ($parsed === null) {
            $issues[] = Issue::error('invalid_date', 'Fecha no válida.', $field, $raw);

            return null;
        }

        return $parsed[0]->toDateString();
    }

    /** @param list<Issue> $issues */
    private function amount(ValueParser $p, mixed $raw, string $field, bool $required, array &$issues, bool $allowZero): ?float
    {
        if (ValueParser::isBlank($raw)) {
            if ($required) {
                $issues[] = Issue::error('required', 'Falta la cantidad.', $field);
            }

            return null;
        }
        $value = $p->number($raw);
        if ($value === null) {
            $issues[] = Issue::error('invalid_number', 'Valor numérico no válido.', $field, $raw);

            return null;
        }
        if ($value < 0 || (! $allowZero && $value == 0.0)) {
            $issues[] = Issue::error('invalid_number', $allowZero ? 'El valor no puede ser negativo.' : 'La cantidad debe ser mayor que cero.', $field, $raw);

            return null;
        }

        return $value;
    }
}
