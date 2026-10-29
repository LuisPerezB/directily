<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use Carbon\CarbonImmutable;

/**
 * Normaliza una línea de venta expresada con campos canónicos (docs/06 §2.1) y
 * devuelve la línea normalizada (o null) más la lista de errores/advertencias.
 * La usan tanto el importador de archivos como POST /sales/batch (misma lógica).
 */
final class SalesLineNormalizer
{
    private CarbonImmutable $maxDate;

    public function __construct(
        private readonly ValueParser $parser,
        private readonly string $currency = 'USD',
        private readonly string $timezone = 'America/Guayaquil',
        ?CarbonImmutable $now = null,
    ) {
        $this->maxDate = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->endOfDay()->addDay();
    }

    /** Clave del documento (tipo|número) o null si falta el número o el tipo es inválido. */
    public function documentKey(array $raw): ?string
    {
        $number = $this->parser->string($raw['document_number'] ?? null, 100);
        if ($number === null) {
            return null;
        }
        $type = ValueParser::documentType($this->parser->string($raw['document_type'] ?? null, 50)) ?? 'other';

        return $type.'|'.mb_strtoupper($number);
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

        $rawType = $p->string($raw['document_type'] ?? null, 50);
        $type = ValueParser::documentType($rawType);
        if ($type === null) {
            $issues[] = Issue::warning('unknown_document_type', "Tipo de documento no reconocido; se registrará como 'otro'.", 'document_type', $rawType);
            $type = 'other';
        }
        $status = ValueParser::documentStatus($p->string($raw['status'] ?? null, 50));

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
            } elseif ($parsedRecorded[0]->greaterThan($this->maxDate)) {
                $issues[] = Issue::warning('future_date', 'La fecha de registro en la fuente está en el futuro; se deja desconocida.', 'source_recorded_at', $rawRecorded);
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
