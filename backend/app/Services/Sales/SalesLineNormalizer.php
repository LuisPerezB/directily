<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Normaliza una línea de venta expresada con campos canónicos (docs/06 §2.1) y
 * devuelve la línea normalizada (o null) más la lista de errores/advertencias.
 * La usan tanto el importador de archivos como POST /sales/batch (misma lógica).
 */
final class SalesLineNormalizer
{
    /** Tolerancia por defecto (minutos) de `source_recorded_at` respecto de la hora actual: desfases de reloj de la fuente. */
    public const DEFAULT_RECORDED_AT_TOLERANCE_MINUTES = 5;

    private CarbonImmutable $maxDate;

    private CarbonImmutable $recordedBound;

    public function __construct(
        private readonly ValueParser $parser,
        private readonly string $currency = 'USD',
        private readonly string $timezone = 'America/Guayaquil',
        ?CarbonImmutable $now = null,
        ?int $recordedAtToleranceMinutes = null,
    ) {
        $this->maxDate = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->endOfDay()->addDay();
        $this->recordedBound = self::recordedAtBound($now, $recordedAtToleranceMinutes);
    }

    /**
     * Cota superior de `source_recorded_at`: ahora + tolerancia (`predictily.imports.source_recorded_at_tolerance_minutes`, por
     * defecto 5 minutos). Una marca posterior NO se guarda como orden de versión (queda NULL) y se cuenta como anomalía
     * (`future_recorded_at`). Es la ÚNICA cota: la usan el normalizador (importaciones y /sales/batch) y la preparación de la
     * sincronización SQL (`CanonicalStager`), para que los tres caminos coincidan.
     */
    public static function recordedAtBound(?CarbonInterface $now = null, ?int $toleranceMinutes = null): CarbonImmutable
    {
        $minutes = max(0, $toleranceMinutes ?? (int) config('predictily.imports.source_recorded_at_tolerance_minutes', self::DEFAULT_RECORDED_AT_TOLERANCE_MINUTES));

        return CarbonImmutable::instance($now ?? CarbonImmutable::now())->addMinutes($minutes);
    }

    /**
     * Anomalía de `source_recorded_at` que un hallazgo del normalizador representa: `future` (posterior a la cota), `unreadable`
     * (ilegible) o null (otro hallazgo). Permite contarlas igual en importaciones y en /sales/batch.
     */
    public static function recordedAtAnomaly(Issue $issue): ?string
    {
        if ($issue->column !== 'source_recorded_at') {
            return null;
        }

        return match ($issue->code) {
            'future_date' => 'future',
            'invalid_date' => 'unreadable',
            default => null,
        };
    }

    /**
     * Clave del documento (tipo|NÚMERO) o null si falta el número. Un tipo no reconocido usa el marcador `unknown`: esas filas
     * son error (`unknown_document_type`) y nunca comparten clave con un documento `other` válido.
     */
    public function documentKey(array $raw): ?string
    {
        $number = $this->parser->string($raw['document_number'] ?? null, 100);
        if ($number === null) {
            return null;
        }
        $type = ValueParser::documentType($this->parser->string($raw['document_type'] ?? null, 50)) ?? 'unknown';

        return $type.'|'.mb_strtoupper($number);
    }

    /** ¿La clave de documento corresponde a una fila con tipo NO vacío y no reconocido (marcador `unknown`)? */
    public static function isUnknownTypeKey(string $key): bool
    {
        return str_starts_with($key, 'unknown|');
    }

    /** Número de documento (en MAYÚSCULAS) de una clave `tipo|NÚMERO`. */
    public static function numberOfKey(string $key): string
    {
        return substr($key, (int) strpos($key, '|') + 1);
    }

    /**
     * Aviso (WARNING, no error) sobre un documento RECONOCIDO cuyo número también traen filas de tipo NO reconocido (misma fuente:
     * mismo archivo o mismo lote). En los ERP la numeración suele ser por tipo o serie (la factura 100 y la proforma o guía 100 son
     * documentos distintos), así que el documento reconocido SE ESCRIBE completo con sus propias líneas; las filas de tipo
     * desconocido siguen siendo error REVIEW_REQUIRED y no se escriben. El aviso solo deja visible el caso, por si esas filas fueran
     * líneas del propio documento con el tipo mal escrito (el documento quedaría sin ellas).
     */
    public static function sharedNumberWarning(string $number): Issue
    {
        return Issue::warning('document_number_shared_with_unknown_type', "El número {$number} también aparece en filas de un tipo de documento no reconocido, que se descartan para revisión. Este documento se escribe con sus propias líneas: confirme que la numeración es por tipo o serie y que esas filas no eran líneas suyas con el tipo mal escrito.", 'document_number', $number);
    }

    /**
     * @param  array<string, mixed>  $raw  campo canónico => valor crudo
     * @return array{0: array<string, mixed>|null, 1: list<Issue>}
     */
    public function normalize(array $raw): array
    {
        $issues = [];
        $p = $this->parser;

        $number = $p->string($raw['document_number'] ?? null, 100);
        if ($number === null) {
            $issues[] = Issue::error('required', 'Falta el número de documento.', 'document_number');
        }

        // Un tipo o estado NO vacío que no se reconoce ya NO se cuenta como venta (defecto de auditoría): es un error de fila de
        // clase REVIEW_REQUIRED y el documento no se escribe hasta que alguien lo mapee. Vacío = factura / completada (convención).
        $rawType = $p->string($raw['document_type'] ?? null, 50);
        $type = ValueParser::documentType($rawType);
        if ($type === null) {
            $issues[] = Issue::error('unknown_document_type', "Tipo de documento no reconocido ('{$rawType}'): requiere revisión. Use factura, nota de venta, nota de crédito u otro, o excluya el documento (cotizaciones, proformas y guías no son ventas).", 'document_type', $rawType);
            $type = 'other'; // marcador: la fila es un error y no se escribe
        }
        $rawStatus = $p->string($raw['status'] ?? null, 50);
        $status = ValueParser::documentStatus($rawStatus);
        if ($status === null) {
            $issues[] = Issue::error('unknown_document_status', "Estado de documento no reconocido ('{$rawStatus}'): requiere revisión. Use completada (o vacío), anulada o pendiente; un documento rechazado, reversado o no autorizado no es una venta.", 'status', $rawStatus);
            $status = 'completed'; // marcador: la fila es un error y no se escribe
        }

        $issuedAt = null;
        $hasTime = false;
        $rawDate = $raw['issued_at'] ?? null;
        if (ValueParser::isBlank($rawDate)) {
            $issues[] = Issue::error('required', 'Falta la fecha de emisión.', 'issued_at');
        } else {
            $parsed = $p->dateTime($rawDate);
            if ($parsed === null) {
                $issues[] = Issue::error('invalid_date', 'Fecha no válida. Revise el formato (p. ej. 2026-03-15 o 15/03/2026).', 'issued_at', $rawDate);
            } else {
                [$issuedAt, $hasTime] = $parsed;
                if ($issuedAt->greaterThan($this->maxDate)) {
                    $issues[] = Issue::error('future_date', 'La fecha de emisión está en el futuro.', 'issued_at', $rawDate);
                }
            }
        }

        $sku = $p->string($raw['product_sku'] ?? null, 100);
        if ($sku === null) {
            $issues[] = Issue::error('required', 'Falta el código de producto.', 'product_sku');
        }

        $quantity = null;
        $isReturn = $type === 'credit_note';
        $rawQty = $raw['quantity'] ?? null;
        if (ValueParser::isBlank($rawQty)) {
            $issues[] = Issue::error('required', 'Falta la cantidad.', 'quantity');
        } elseif (($quantity = $p->number($rawQty)) === null) {
            $issues[] = Issue::error('invalid_number', 'Cantidad no numérica.', 'quantity', $rawQty);
        } elseif ($quantity < 0) {
            $issues[] = Issue::warning('negative_quantity', 'Cantidad negativa: se registra como devolución.', 'quantity', $rawQty);
            $isReturn = true;
            $quantity = abs($quantity);
        }

        $price = null;
        $rawPrice = $raw['unit_price'] ?? null;
        if (ValueParser::isBlank($rawPrice)) {
            $issues[] = Issue::error('required', 'Falta el precio unitario.', 'unit_price');
        } elseif (($price = $p->number($rawPrice)) === null) {
            $issues[] = Issue::error('invalid_number', 'Precio unitario no numérico.', 'unit_price', $rawPrice);
        } elseif ($price < 0) {
            $issues[] = Issue::error('negative_price', 'El precio unitario no puede ser negativo.', 'unit_price', $rawPrice);
        }

        $discount = 0.0;
        $rawDiscount = $raw['discount'] ?? null;
        if (! ValueParser::isBlank($rawDiscount)) {
            $pct = $p->percentage($rawDiscount);
            if ($pct !== null && $quantity !== null && $price !== null) {
                $discount = round($quantity * $price * $pct, 4);
            } elseif (($value = $p->number($rawDiscount)) === null || $value < 0) {
                $issues[] = Issue::error('invalid_number', 'Descuento no válido.', 'discount', $rawDiscount);
            } else {
                $discount = $value;
            }
        }

        $tax = 0.0;
        $rawTax = $raw['tax'] ?? null;
        if (! ValueParser::isBlank($rawTax)) {
            $value = $p->number($rawTax);
            if ($value === null) {
                $issues[] = Issue::error('invalid_number', 'Impuesto no numérico.', 'tax', $rawTax);
            } else {
                $tax = abs($value);
            }
        }

        $unitCost = null;
        $rawCost = $raw['unit_cost'] ?? null;
        if (! ValueParser::isBlank($rawCost)) {
            $value = $p->number($rawCost);
            if ($value === null || $value < 0) {
                $issues[] = Issue::warning('invalid_number', 'Costo unitario no válido; se ignora.', 'unit_cost', $rawCost);
            } else {
                $unitCost = $value;
            }
        }

        $currency = $p->string($raw['currency'] ?? null, 10);
        if ($currency !== null && mb_strtoupper($currency) !== $this->currency && ! in_array(mb_strtoupper($currency), ['$', 'US$', 'DOLAR', 'DOLARES', 'DÓLARES'], true)) {
            $issues[] = Issue::error('currency_mismatch', "La moneda ({$currency}) no coincide con la moneda de la empresa ({$this->currency}).", 'currency', $currency);
        }

        $lineNumber = null;
        $rawLine = $raw['line_number'] ?? null;
        if (! ValueParser::isBlank($rawLine)) {
            $lineNumber = $p->integer($rawLine);
            if ($lineNumber === null || $lineNumber < 1) {
                $issues[] = Issue::warning('invalid_number', 'Número de línea no válido; se usará el orden de aparición.', 'line_number', $rawLine);
                $lineNumber = null;
            }
        }

        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return [null, $issues];
            }
        }

        if ($quantity == 0.0) {
            $issues[] = Issue::warning('zero_quantity', 'Cantidad cero: la línea se omite.', 'quantity', $rawQty);

            return [null, $issues];
        }

        $gross = round($quantity * $price, 4);
        $computedNet = round($gross - $discount, 4);
        $net = $computedNet;
        $rawNet = $raw['net_amount'] ?? null;
        if (! ValueParser::isBlank($rawNet)) {
            $value = $p->number($rawNet);
            if ($value === null) {
                $issues[] = Issue::warning('invalid_number', 'Importe neto no numérico; se calcula como cantidad × precio − descuento.', 'net_amount', $rawNet);
            } else {
                $value = abs($value);
                $diff = abs($value - $computedNet);
                if ($diff > 0.01 && $diff > 0.01 * max(abs($computedNet), 0.01)) {
                    $issues[] = Issue::warning('net_amount_mismatch', 'El importe neto difiere más de 1% de cantidad × precio − descuento; se conserva el valor del archivo.', 'net_amount', $rawNet);
                }
                $net = $value;
            }
        }

        $customerCode = $p->string($raw['customer_code'] ?? null, 100);

        // Disponibilidad en la fuente (tres fechas): NULL = desconocida; nunca se rellena con la fecha de ingestión.
        $recordedAt = null;
        $rawRecorded = $raw['source_recorded_at'] ?? null;
        if (! ValueParser::isBlank($rawRecorded)) {
            $parsedRecorded = $p->dateTime($rawRecorded);
            if ($parsedRecorded === null) {
                $issues[] = Issue::warning('invalid_date', 'Fecha de registro en la fuente no válida; se deja desconocida.', 'source_recorded_at', $rawRecorded);
            } elseif ($parsedRecorded[0]->greaterThan($this->recordedBound)) {
                $issues[] = Issue::warning('future_date', 'La fecha de registro en la fuente es posterior a la hora actual (más la tolerancia); se deja desconocida y no ordena versiones.', 'source_recorded_at', $rawRecorded);
            } else {
                $recordedAt = $parsedRecorded[0]->utc()->format('Y-m-d H:i:sP');
            }
        }

        return [[
            'doc_key' => $type.'|'.mb_strtoupper((string) $number),
            'document_type' => $type,
            'document_number' => (string) $number,
            'status' => $status,
            'issued_at' => $issuedAt->utc()->format('Y-m-d H:i:sP'),
            'sale_date' => $issuedAt->setTimezone($this->timezone)->toDateString(),
            'has_time' => $hasTime,
            'branch_code' => $p->string($raw['branch_code'] ?? null, 50),
            'product_sku' => (string) $sku,
            'product_name' => $p->string($raw['product_name'] ?? null, 255),
            'category' => $p->string($raw['category'] ?? null, 150),
            'quantity' => round($quantity, 4),
            'unit_price' => round($price, 4),
            'discount' => round($discount, 4),
            'tax' => round($tax, 4),
            'net_amount' => round($net, 4),
            'unit_cost' => $unitCost !== null ? round($unitCost, 4) : null,
            'is_return' => $isReturn,
            'customer_code' => $customerCode,
            'customer_segment' => $p->string($raw['customer_segment'] ?? null, 150),
            'customer_province' => $p->string($raw['customer_province'] ?? null, 80),
            'channel' => ValueParser::channel($p->string($raw['channel'] ?? null, 50)),
            'currency' => $this->currency,
            'line_number' => $lineNumber,
            'source_recorded_at' => $recordedAt,
        ], $issues];
    }
}
