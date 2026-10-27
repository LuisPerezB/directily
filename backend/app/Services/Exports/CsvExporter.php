<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación CSV en streaming (UTF-8 con BOM para Excel).
 *
 * El contenido se genera DESPUÉS de que el middleware de empresa terminó, por eso el
 * callback restablece explícitamente el TenantContext. Protege contra inyección de
 * fórmulas en hojas de cálculo (celdas que empiezan con = + - @).
 */
class CsvExporter
{
    /**
     * @param  list<string>  $header
     * @param  callable(): iterable<array<int, mixed>>  $rows
     * @param  array<string, mixed>  $auditMetadata
     */
    public function stream(string $filename, array $header, callable $rows, string $delimiter = ',', array $auditMetadata = []): StreamedResponse
    {
        $tenant = app(TenantContext::class)->require();
        CoreServices::audit('data.exported', null, ['file' => $filename] + $auditMetadata);

        return response()->streamDownload(function () use ($tenant, $header, $rows, $delimiter): void {
            app(TenantContext::class)->runAs($tenant, function () use ($header, $rows, $delimiter): void {
                $out = fopen('php://output', 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header, $delimiter, '"', '');
                foreach ($rows() as $row) {
                    fputcsv($out, array_map([self::class, 'cell'], $row), $delimiter, '"', '');
                }
                fclose($out);
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
