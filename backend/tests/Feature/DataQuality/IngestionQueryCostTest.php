<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Rendimiento por lotes (W2-A): el piloto con datos reales usa la importación y el lote REST, así que la captura Bronze, los hallazgos, la
 * cuarentena, el linaje y la reconciliación NO pueden agregar consultas por documento: el número de consultas de una carga de 5 documentos es
 * IGUAL al de una de 60 (también con documentos en cuarentena, avisos y normalizaciones).
 */
final class IngestionQueryCostTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /**
     * @param  callable(int): list<list<string|int|float>>  $rows
     * @return array{validation: int, commit: int}
     */
    private function importQueries(callable $rows, int $documents, array $options): array
    {
        $upload = $this->uploadImport($this->csv(self::SALES_HEADERS, $rows($documents)))->assertCreated();
        $id = $upload->json('data.id');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => $upload->json('data.suggested_mapping'), 'options' => $options])->assertAccepted();
        $validation = $this->countWithoutProgress();
        DB::flushQueryLog();
        $this->postJson("/api/v1/imports/{$id}/confirm")->assertAccepted();
        $commit = $this->countWithoutProgress();
        DB::disableQueryLog();

        return ['validation' => $validation, 'commit' => $commit];
    }

    /**
     * El avance (`import_jobs.progress`) se actualiza como máximo cada 2 puntos porcentuales (≤ 50 por carga, sin importar su tamaño):
     * es el contador de progreso que ya existía y no es una consulta por documento, así que no se cuenta.
     */
    private function countWithoutProgress(): int
    {
        return count(array_filter(DB::getQueryLog(), fn (array $q) => ! str_starts_with($q['query'], 'update "import_jobs" set "progress"')));
    }

    public function test_import_queries_do_not_grow_with_the_number_of_documents(): void
    {
        // Documentos con avisos (cantidad negativa), normalizaciones (coma decimal) y la mitad en cuarentena (tipo desconocido).
        $rows = function (int $documents, string $prefix): array {
            $out = [];
            for ($i = 1; $i <= $documents; $i++) {
                $type = $i % 2 === 0 ? 'Cotización' : 'Factura';
                $out[] = $this->salesRow("{$prefix}{$i}", $type, '', '25/01/2026', '001', 'CEM-50', $i % 3 === 0 ? '-1' : '2', '8,50');
                $out[] = $this->salesRow("{$prefix}{$i}", $type, '', '25/01/2026', '001', 'VAR-12', '1', '7,20');
            }

            return $out;
        };
        $this->importQueries(fn (int $n) => $rows($n, 'W'), 1, ['skip_invalid_rows' => true]); // calienta: crea productos y categoría
        $few = $this->importQueries(fn (int $n) => $rows($n, 'A'), 5, ['skip_invalid_rows' => true]);
        $many = $this->importQueries(fn (int $n) => $rows($n, 'B'), 60, ['skip_invalid_rows' => true]);

        $this->assertSame($few['validation'], $many['validation'], 'validación: mismas consultas con 5 y con 60 documentos');
        $this->assertSame($few['commit'], $many['commit'], 'confirmación: mismas consultas con 5 y con 60 documentos');
        $this->assertSame(30, DB::table('quarantine_records')->where('record_key', 'like', 'invoice|B%')->count() + DB::table('quarantine_records')->where('record_key', 'like', 'unknown|B%')->count());
        $this->assertSame(30, DB::table('sales')->where('document_number', 'like', 'B%')->count());
    }

    public function test_batch_queries_do_not_grow_with_the_number_of_documents(): void
    {
        $batch = function (int $count, string $prefix): array {
            return array_map(fn (int $i) => [
                'document_number' => sprintf('%s-%03d', $prefix, $i), 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001', 'customer_code' => '1710034065',
                'document_type' => $i % 2 === 0 ? 'cotizacion' : 'factura',
                'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => '2,5', 'unit_price' => 5], ['product_sku' => 'B', 'product_name' => 'Producto B', 'quantity' => -1, 'unit_price' => 5]],
            ], range(1, $count));
        };
        $queries = function (int $count, string $prefix) use ($batch): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson('/api/v1/sales/batch', ['documents' => $batch($count, $prefix)])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $queries(3, 'W'); // calienta los catálogos (productos creados)
        $this->assertSame($queries(5, 'P'), $queries(60, 'Q'));
    }
}
