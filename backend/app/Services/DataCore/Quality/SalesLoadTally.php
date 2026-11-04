<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Models\BronzeObject;

/**
 * Cuentas de una carga de ventas para la reconciliación por carga (ADR-V25-24; W2-A): capa FUENTE (todo lo leído del origen), capa SILVER
 * (lo que quedó escrito o ya vigente) y el destino de lo que no llegó a Silver, por categoría conocida.
 *
 * Cada fila del origen termina en exactamente UN destino: Silver (documento nuevo, actualizado o sin cambios), o una categoría:
 *  - `quarantine`: documento retenido en cuarentena (`skip_invalid_rows`); `rejected_rows`: filas inválidas no retenidas;
 *  - `stale`: documento con una versión ANTERIOR a la almacenada (no se aplica); `duplicates_in_file`: fila idéntica repetida;
 *  - `zero_quantity`: línea de cantidad cero que se omite; `voided` / `pending`: documentos que SÍ están en Silver pero no cuentan como
 *    venta (sus unidades e importe quedan fuera del importe de ventas efectivo).
 * Lo que no encaja en ninguna categoría es la diferencia SIN EXPLICAR y vuelve la reconciliación `unexplained`.
 *
 * Base de comparación: `documents` y `lines` comparan lo presente en Silver (en cualquier estado); `units` e `net_amount` comparan las
 * ventas efectivas (documentos completados; las devoluciones restan).
 */
final class SalesLoadTally
{
    public const CATEGORIES = ['quarantine', 'rejected_rows', 'stale', 'duplicates_in_file', 'zero_quantity', 'voided', 'pending'];

    private const METRICS = ['documents', 'lines', 'units', 'net_amount'];

    private const EXPLANATIONS = [
        'quarantine' => 'documentos retenidos en cuarentena (con hallazgos de revisión) hasta que se liberen o descarten',
        'rejected_rows' => 'filas rechazadas por errores y no retenidas',
        'stale' => 'documentos descartados por traer una versión ANTERIOR a la almacenada (fecha de registro en la fuente)',
        'duplicates_in_file' => 'filas idénticas repetidas dentro del mismo documento, omitidas',
        'zero_quantity' => 'líneas de cantidad cero, omitidas',
        'voided' => 'documentos anulados: están en Silver pero no cuentan como venta',
        'pending' => 'documentos pendientes: están en Silver pero no cuentan como venta',
    ];

    /** @var array<string, float|int> */
    private array $source = ['rows' => 0, 'documents' => 0, 'lines' => 0, 'units' => 0.0, 'net_amount' => 0.0, 'voided' => 0, 'credit_notes' => 0, 'duplicates' => 0, 'unknown_amount_rows' => 0];

    /** @var array<string, float|int> */
    private array $silver = ['documents' => 0, 'lines' => 0, 'units' => 0.0, 'net_amount' => 0.0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_skipped' => 0, 'quarantined' => 0,
        'voided' => 0, 'credit_notes' => 0, 'voided_units' => 0.0, 'voided_net_amount' => 0.0];

    /** @var array<string, array<string, float|int>> */
    private array $buckets = [];

    public function __construct()
    {
        foreach (self::CATEGORIES as $category) {
            $this->buckets[$category] = array_fill_keys(self::METRICS, 0);
        }
    }

    /** Un documento distinto del origen (se llama una vez por documento, al verlo por primera vez). */
    public function sourceDocument(string $type, string $status): void
    {
        $this->source['documents']++;
        $this->source['voided'] += $status === 'voided' ? 1 : 0;
        $this->source['credit_notes'] += $type === 'credit_note' ? 1 : 0;
    }

    /** Una fila del origen. */
    public function sourceRow(float $units, float $net, bool $amountKnown = true): void
    {
        $this->source['rows']++;
        $this->source['lines']++;
        $this->source['units'] += $units;
        $this->source['net_amount'] += $net;
        $this->source['unknown_amount_rows'] += $amountKnown ? 0 : 1;
    }

    /** Filas idénticas repetidas dentro de un documento válido (se omiten). */
    public function duplicateRows(int $rows, float $units, float $net): void
    {
        if ($rows > 0) {
            $this->source['duplicates'] += $rows;
            $this->add('duplicates_in_file', ['lines' => $rows, 'units' => $units, 'net_amount' => $net]);
        }
    }

    /** Líneas de cantidad cero de un documento válido (se omiten). */
    public function zeroQuantityRows(int $rows, float $net): void
    {
        if ($rows > 0) {
            $this->add('zero_quantity', ['lines' => $rows, 'net_amount' => $net]);
        }
    }

    /** Fila sin número de documento: no pertenece a ningún documento. */
    public function unnumberedRow(string $category, float $units, float $net): void
    {
        $this->add($category, ['lines' => 1, 'units' => $units, 'net_amount' => $net]);
        $this->silver['quarantined'] += $category === 'quarantine' ? 1 : 0;
    }

    /** Documento inválido (no llegó a Silver) con las cifras de TODAS sus filas. */
    public function droppedDocument(string $category, int $rows, float $units, float $net): void
    {
        $this->add($category, ['documents' => 1, 'lines' => $rows, 'units' => $units, 'net_amount' => $net]);
        $this->silver['quarantined'] += $category === 'quarantine' ? 1 : 0;
    }

    /** Documento válido cuyo contenido quedó vacío (todas sus líneas fueron de cantidad cero o repetidas). */
    public function emptyDocument(): void
    {
        $this->add('zero_quantity', ['documents' => 1]);
    }

    /**
     * Documento válido con su destino ya conocido (new | updated | unchanged | stale_skipped).
     *
     * @param  array{lines: int, units: float, net: float}  $figures  cifras de las líneas del documento
     */
    public function documentState(string $state, string $status, string $type, array $figures): void
    {
        if ($state === 'stale_skipped') {
            $this->silver['stale_skipped']++;
            $this->add('stale', ['documents' => 1, 'lines' => $figures['lines'], 'units' => $figures['units'], 'net_amount' => $figures['net']]);

            return;
        }
        $this->silver[$state]++;
        $this->silver['documents']++;
        $this->silver['lines'] += $figures['lines'];
        $this->silver['credit_notes'] += $type === 'credit_note' ? 1 : 0;
        if ($status === 'completed') {
            $this->silver['units'] += $figures['units'];
            $this->silver['net_amount'] += $figures['net'];
        } else {
            $this->silver['voided'] += $status === 'voided' ? 1 : 0;
            $this->silver['voided_units'] += $figures['units'];
            $this->silver['voided_net_amount'] += $figures['net'];
            $this->add($status === 'voided' ? 'voided' : 'pending', ['units' => $figures['units'], 'net_amount' => $figures['net']]);
        }
    }

    /**
     * @param  array<string, float|int>  $amounts
     */
    private function add(string $category, array $amounts): void
    {
        foreach ($amounts as $metric => $value) {
            $this->buckets[$category][$metric] += $value;
        }
    }

    /**
     * Capas y discrepancias de la reconciliación.
     *
     * @param  ?BronzeObject  $bronze  objeto Bronze del original (null = no se capturó)
     * @return array{layers: array<string, mixed>, discrepancies: list<array<string, mixed>>, status: string}
     */
    public function build(?BronzeObject $bronze, ?int $bronzeRows): array
    {
        $tolerance = (float) config('data_quality.reconciliation_amount_tolerance', 0.01);
        $round = fn (float $v): float => round($v, 2);
        $source = $this->source;
        $silver = $this->silver;

        $layers = [
            'source' => [
                'rows' => (int) $source['rows'], 'documents' => (int) $source['documents'], 'lines' => (int) $source['lines'],
                'units' => round((float) $source['units'], 4), 'net_amount' => $round((float) $source['net_amount']),
                'voided' => (int) $source['voided'], 'credit_notes' => (int) $source['credit_notes'], 'duplicates' => (int) $source['duplicates'],
                'unknown_amount_rows' => (int) $source['unknown_amount_rows'],
            ],
            'bronze' => $bronze === null ? null : [
                'object_id' => $bronze->id, 'kind' => $bronze->kind, 'sha256' => $bronze->sha256, 'size_bytes' => (int) $bronze->size_bytes,
                'rows' => $bronzeRows, 'schema_version' => $bronze->schema_version,
            ],
            'silver' => [
                'documents' => (int) $silver['documents'], 'lines' => (int) $silver['lines'],
                'units' => round((float) $silver['units'], 4), 'net_amount' => $round((float) $silver['net_amount']),
                'new' => (int) $silver['new'], 'updated' => (int) $silver['updated'], 'unchanged' => (int) $silver['unchanged'],
                'stale_skipped' => (int) $silver['stale_skipped'], 'quarantined' => (int) $silver['quarantined'],
                'voided' => (int) $silver['voided'], 'credit_notes' => (int) $silver['credit_notes'],
                'voided_units' => round((float) $silver['voided_units'], 4), 'voided_net_amount' => $round((float) $silver['voided_net_amount']),
            ],
            'gold' => null, // La capa Gold (datasets del motor) se concilia por ventana: no aplica a una carga.
        ];

        $discrepancies = [];
        $explainedAll = true;
        $expectedKey = ['documents' => 'documents', 'lines' => 'lines', 'units' => 'units', 'net_amount' => 'net_amount'];
        foreach (self::METRICS as $metric) {
            $expected = (float) $source[$expectedKey[$metric]];
            $observed = (float) $silver[$metric];
            $categories = [];
            foreach (self::CATEGORIES as $category) {
                $value = (float) $this->buckets[$category][$metric];
                if (abs($value) > ($metric === 'units' || $metric === 'net_amount' ? $tolerance / 10 : 0.0)) {
                    $categories[$category] = $metric === 'documents' || $metric === 'lines' ? (int) round($value) : round($value, 4);
                }
            }
            $residual = $expected - $observed - array_sum($categories);
            $limit = $metric === 'units' || $metric === 'net_amount' ? $tolerance : 0.0;
            $unexplained = abs($residual) > $limit;
            $difference = $expected - $observed;
            if ($categories === [] && ! $unexplained && abs($difference) <= $limit) {
                continue; // coincide
            }
            $explainedAll = $explainedAll && ! $unexplained;
            $parts = array_map(fn (string $c) => self::EXPLANATIONS[$c].' ('.$categories[$c].')', array_keys($categories));
            $discrepancies[] = [
                'from' => 'source', 'to' => 'silver', 'metric' => $metric,
                'expected' => $metric === 'documents' || $metric === 'lines' ? (int) $expected : round($expected, 4),
                'observed' => $metric === 'documents' || $metric === 'lines' ? (int) $observed : round($observed, 4),
                'difference' => $metric === 'documents' || $metric === 'lines' ? (int) round($difference) : round($difference, 4),
                'categories' => $categories,
                'unexplained' => $metric === 'documents' || $metric === 'lines' ? (int) round($residual) : round($residual, 4),
                'explained' => ! $unexplained,
                'explanation' => $unexplained
                    ? 'Diferencia SIN EXPLICAR de '.round($residual, 4).($parts !== [] ? '; explicado: '.implode('; ', $parts) : '').'.'
                    : implode('; ', $parts).'.',
            ];
        }
        // Bronze frente a la fuente: debe contener las mismas filas que se leyeron.
        if ($bronzeRows !== null && $bronzeRows !== (int) $source['rows']) {
            $explainedAll = false;
            $discrepancies[] = [
                'from' => 'source', 'to' => 'bronze', 'metric' => 'rows', 'expected' => (int) $source['rows'], 'observed' => $bronzeRows,
                'difference' => (int) $source['rows'] - $bronzeRows, 'categories' => [], 'unexplained' => (int) $source['rows'] - $bronzeRows,
                'explained' => false, 'explanation' => 'El objeto Bronze no contiene las mismas filas que se leyeron del origen.',
            ];
        }

        $status = match (true) {
            $discrepancies === [] => 'balanced',
            $explainedAll => 'explained',
            default => 'unexplained',
        };

        return ['layers' => $layers, 'discrepancies' => $discrepancies, 'status' => $status];
    }
}
