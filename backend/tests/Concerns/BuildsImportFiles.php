<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\Forecasting\RefreshReadiness;
use App\Models\ImportJob;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Helpers para pruebas de importación (backend-core). */
trait BuildsImportFiles
{
    protected function fakeImportInfrastructure(): void
    {
        Storage::fake('local');
        // La evaluación del motor no se ejecuta en estas pruebas (se verifica que se despacha).
        Bus::fake([RefreshReadiness::class]);
    }

    /** @param list<list<string|int|float|null>> $rows */
    protected function csv(array $headers, array $rows, string $delimiter = ','): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $headers, $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, '"', '');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    protected const SALES_HEADERS = ['Nro Factura', 'Tipo', 'Estado', 'Fecha Emisión', 'Sucursal', 'Código', 'Descripción', 'Categoría', 'Cantidad', 'Precio Unitario', 'Descuento', 'IVA', 'Subtotal', 'Costo', 'Cliente', 'Canal'];

    /** Filas de ventas válidas: 3 documentos, 5 líneas. */
    protected function validSalesRows(): array
    {
        return [
            ['001-001-000000001', 'Factura', '', '2026-01-05 09:15:00', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '10', '8.50', '0', '10.20', '85.00', '6.90', '1710034065', 'Local'],
            ['001-001-000000001', 'Factura', '', '2026-01-05 09:15:00', '001', 'VAR-12', 'Varilla 12mm', 'Hierro', '5', '7.20', '1.00', '5.25', '35.00', '5.10', '1710034065', 'Local'],
            ['001-001-000000002', 'Factura', '', '06/01/2026', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '4', '8.50', '', '4.08', '', '', '9999999999999', 'Mayoreo'],
            ['001-001-000000003', 'Factura', 'ANULADA', '2026-01-07', '001', 'PIN-01', 'Pintura galón', 'Pinturas', '2', '15.00', '0', '3.60', '30.00', '', '', ''],
            ['001-001-000000003', 'Factura', 'ANULADA', '2026-01-07', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '1', '8.50', '0', '1.02', '8.50', '', '', ''],
        ];
    }

    protected function uploadImport(string $content, string $entity = 'sales', string $filename = 'ventas.csv'): TestResponse
    {
        return $this->post('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
            'entity_type' => $entity,
        ], ['Accept' => 'application/json']);
    }

    /** Sube, mapea con la sugerencia (más ajustes) y devuelve el ImportJob validado. */
    protected function uploadAndValidate(Tenant $tenant, string $content, string $entity = 'sales', array $mappingOverrides = [], array $options = [], string $filename = 'ventas.csv'): ImportJob
    {
        $upload = $this->uploadImport($content, $entity, $filename)->assertCreated();
        $mapping = array_merge($upload->json('data.suggested_mapping'), $mappingOverrides);
        $this->putJson('/api/v1/imports/'.$upload->json('data.id').'/mapping', ['mapping' => $mapping, 'options' => $options])->assertAccepted();

        return $this->withinTenant($tenant, fn () => ImportJob::query()->findOrFail($upload->json('data.id')));
    }
}
