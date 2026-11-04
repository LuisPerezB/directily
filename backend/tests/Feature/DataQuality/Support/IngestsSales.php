<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality\Support;

use App\Models\ImportJob;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ayudas para las pruebas de calidad de ingestión (W2-A): filas de ventas con las columnas de BuildsImportFiles::SALES_HEADERS, importación
 * completa por la API y lecturas de las tablas del Data Quality Engine. Requiere `$this->tenant` y las ayudas de BuildsImportFiles.
 */
trait IngestsSales
{
    /**
     * @return list<string|int|float>
     */
    protected function salesRow(
        string $number, string $type = 'Factura', string $status = '', string $date = '2026-01-05 10:00:00', string $branch = '001',
        string $sku = 'CEM-50', string $quantity = '2', string $price = '8.50', string $customer = '', string $net = '',
    ): array {
        return [$number, $type, $status, $date, $branch, $sku, 'Cemento 50kg', 'Cemento', $quantity, $price, '0', '0', $net, '', $customer, 'Local'];
    }

    /**
     * Sube, valida y (si `$confirm`) confirma una importación de ventas por la API; devuelve la importación en su estado final.
     *
     * @param  list<list<string|int|float>>  $rows
     * @param  array<string, mixed>  $options
     */
    protected function importSales(array $rows, array $options = [], bool $confirm = true, ?string $filename = null, ?Tenant $tenant = null): ImportJob
    {
        $tenant ??= $this->tenant;
        $job = $this->uploadAndValidate($tenant, $this->csv(self::SALES_HEADERS, $rows), 'sales', [], $options, $filename ?? 'ventas.csv');
        if ($confirm) {
            $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        }

        return $this->withinTenant($tenant, fn () => ImportJob::query()->findOrFail($job->id));
    }

    /** Último informe de calidad de una importación (fila cruda de data_quality_reports). */
    protected function qualityReport(ImportJob $job): object
    {
        return DB::table('data_quality_reports')->where('import_job_id', $job->id)->latest('id')->firstOrFail();
    }

    /** @return Collection<int, object> hallazgos del informe */
    protected function findings(int $reportId): Collection
    {
        return DB::table('data_quality_issues')->where('report_id', $reportId)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    protected function decode(?string $value): array
    {
        return json_decode((string) $value, true) ?? [];
    }
}
