<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Imports\Support\Issue;

/**
 * Agrupa líneas normalizadas en documentos. Detecta:
 * - document_conflict: misma clave con cabecera distinta (fecha, sucursal, estado, cliente).
 * - duplicate_in_file: número de línea repetido (error) o fila idéntica repetida (advertencia; se omite).
 * Los documentos con alguna fila inválida se marcan inválidos y no se escriben.
 */
final class SalesDocumentAssembler
{
    /** @var array<string, array<string, mixed>> */
    private array $open = [];

    public function markInvalid(string $docKey): void
    {
        $this->open[$docKey] ??= $this->emptyDoc();
        $this->open[$docKey]['invalid'] = true;
    }

    /**
     * @param array<string, mixed> $line
     * @return list<Issue>
     */
    public function add(array $line): array
    {
        $key = $line['doc_key'];
        $doc = $this->open[$key] ?? $this->emptyDoc();
        $issues = [];

        $signature = [$line['document_type'], $line['document_number'], $line['status'], $line['sale_date'], $line['branch_code'], $line['customer_code']];
        if ($doc['signature'] === null) {
            $doc['signature'] = $signature;
            $doc['header'] = array_intersect_key($line, array_flip([
                'doc_key', 'document_type', 'document_number', 'status', 'issued_at', 'sale_date', 'branch_code',
                'customer_code', 'customer_segment', 'customer_province', 'channel', 'currency',
            ]));
        } elseif ($doc['signature'] !== $signature) {
            $issues[] = Issue::error('document_conflict', 'El documento aparece con datos de cabecera distintos (fecha, sucursal, estado o cliente).', 'document_number', $line['document_number']);
            $doc['invalid'] = true;
            $this->open[$key] = $doc;

            return $issues;
        }

        $hash = md5(serialize([$line['product_sku'], $line['quantity'], $line['unit_price'], $line['discount'], $line['tax'], $line['net_amount'], $line['unit_cost'], $line['is_return'], $line['line_number']]));
        if (isset($doc['hashes'][$hash])) {
            $issues[] = Issue::warning('duplicate_in_file', 'Fila idéntica repetida dentro del mismo documento; se omite.', null, $line['document_number']);
            $this->open[$key] = $doc;

            return $issues;
        }
        $doc['hashes'][$hash] = true;

        $lineNumber = $line['line_number'];
        if ($lineNumber !== null) {
            if (isset($doc['line_numbers'][$lineNumber])) {
                $issues[] = Issue::error('duplicate_in_file', "Número de línea {$lineNumber} repetido en el documento.", 'line_number', $lineNumber);
                $doc['invalid'] = true;
                $this->open[$key] = $doc;

                return $issues;
            }
        } else {
            $lineNumber = $doc['next_line'];
            while (isset($doc['line_numbers'][$lineNumber])) {
                $lineNumber++;
            }
        }
        $doc['line_numbers'][$lineNumber] = true;
        $doc['next_line'] = max($doc['next_line'], $lineNumber + 1);

        $doc['lines'][] = [
            'line_number' => $lineNumber,
            'product_sku' => $line['product_sku'],
            'product_name' => $line['product_name'],
            'category' => $line['category'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'discount' => $line['discount'],
            'tax' => $line['tax'],
            'net_amount' => $line['net_amount'],
            'unit_cost' => $line['unit_cost'],
            'is_return' => $line['is_return'],
        ];
        $this->open[$key] = $doc;

        return $issues;
    }

    /** Cierra el documento: devuelve el documento final o null si es inválido o vacío. */
    public function complete(string $docKey): ?array
    {
        $doc = $this->open[$docKey] ?? null;
        unset($this->open[$docKey]);

        return $doc === null ? null : $this->finalize($doc);
    }

    /** @return list<array<string, mixed>> documentos aún abiertos (válidos) */
    public function drain(): array
    {
        $docs = [];
        foreach (array_keys($this->open) as $key) {
            if (($doc = $this->complete($key)) !== null) {
                $docs[] = $doc;
            }
        }

        return $docs;
    }

    public function invalidCount(): int
    {
        return count(array_filter($this->open, fn ($d) => $d['invalid']));
    }

    /** @return array<string, mixed>|null */
    private function finalize(array $doc): ?array
    {
        if ($doc['invalid'] || $doc['lines'] === []) {
            return null;
        }
        $lines = $doc['lines'];
        usort($lines, fn ($a, $b) => $a['line_number'] <=> $b['line_number']);

        return self::withTotals($doc['header'], $lines);
    }

    /**
     * Calcula totales y huella (source_hash) del documento.
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function withTotals(array $header, array $lines): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        foreach ($lines as $l) {
            $sign = $l['is_return'] && $header['document_type'] !== 'credit_note' ? -1 : 1;
            $subtotal += $sign * $l['net_amount'];
            $discount += $sign * $l['discount'];
            $tax += $sign * $l['tax'];
        }
        $header['subtotal'] = round($subtotal, 4);
        $header['discount_total'] = round($discount, 4);
        $header['tax_total'] = round($tax, 4);
        $header['total'] = round($subtotal + $tax, 4);
        $header['lines'] = $lines;

        $canonical = [
            $header['document_type'], $header['document_number'], $header['status'], $header['issued_at'], $header['branch_code'],
            $header['customer_code'], $header['customer_segment'], $header['channel'], $header['currency'],
            array_map(fn ($l) => [
                $l['line_number'], $l['product_sku'], sprintf('%.4f', $l['quantity']), sprintf('%.4f', $l['unit_price']),
                sprintf('%.4f', $l['discount']), sprintf('%.4f', $l['tax']), sprintf('%.4f', $l['net_amount']),
                $l['unit_cost'] === null ? null : sprintf('%.4f', $l['unit_cost']), $l['is_return'],
            ], $lines),
        ];
        $header['source_hash'] = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE) ?: '');

        return $header;
    }

    /** @return array<string, mixed> */
    private function emptyDoc(): array
    {
        return ['signature' => null, 'header' => null, 'lines' => [], 'line_numbers' => [], 'hashes' => [], 'next_line' => 1, 'invalid' => false];
    }
}
