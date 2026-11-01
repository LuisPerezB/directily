<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Quality\SchemaDrift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Deriva de esquema (ADR-V25-24; W2-A): huella SHA-256 de los encabezados normalizados y ordenados por fuente y entidad; si cambia, sube la
 * versión de esquema, el objeto Bronze guarda huella y versión y el informe registra un WARNING `schema_drift` con las columnas agregadas y
 * quitadas (sin valores). Sin fuente, se compara con la última importación de la misma entidad de la empresa.
 */
final class SchemaDriftTest extends TestCase
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
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    private function load(array $headers, array $rows, ?int $sourceId = null, string $entity = 'sales'): object
    {
        $upload = $this->post('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('carga.csv', $this->csv($headers, $rows)), 'entity_type' => $entity,
        ] + ($sourceId !== null ? ['data_source_id' => $sourceId] : []), ['Accept' => 'application/json'])->assertCreated();
        $id = (int) $upload->json('data.id');
        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => $upload->json('data.suggested_mapping'), 'options' => ['create_missing_products' => true]])->assertAccepted();

        return (object) ['id' => $id, 'bronze' => DB::table('bronze_objects')->where('id', DB::table('import_jobs')->where('id', $id)->value('bronze_object_id'))->first(), 'report' => DB::table('data_quality_reports')->where('import_job_id', $id)->first()];
    }

    private function drift(object $load): ?object
    {
        return DB::table('data_quality_issues')->where('report_id', $load->report->id)->where('code', 'schema_drift')->first();
    }

    public function test_the_fingerprint_ignores_order_case_and_accents_and_detects_added_and_removed_columns(): void
    {
        $base = ['Nro Factura', 'Fecha Emisión', 'Código', 'Cantidad', 'Precio Unitario', 'Canal'];
        $row = fn (string $n) => [$n, '2026-01-05', 'CEM-50', '2', '8.50', 'Local'];

        $first = $this->load($base, [$row('F-1')]);
        $this->assertSame(1, (int) $first->bronze->schema_version);
        $this->assertNull($this->drift($first), 'la primera carga fija la línea base');
        $this->assertNull($first->report->source_sync_id);

        // Otro orden, mayúsculas y sin tildes: misma huella.
        $reordered = $this->load(['CANAL', 'PRECIO UNITARIO', 'cantidad', 'CODIGO', 'fecha emision', 'nro factura'], [['Local', '8.50', '2', 'CEM-50', '2026-01-06', 'F-2']]);
        $this->assertSame($first->bronze->schema_fingerprint, $reordered->bronze->schema_fingerprint);
        $this->assertSame(1, (int) $reordered->bronze->schema_version);
        $this->assertNull($this->drift($reordered));

        // Una columna agregada: sube la versión, nueva huella, WARNING sin valores.
        $added = $this->load([...$base, 'Vendedor'], [[...$row('F-3'), 'Juan Pérez']]);
        $this->assertSame(2, (int) $added->bronze->schema_version);
        $this->assertNotSame($first->bronze->schema_fingerprint, $added->bronze->schema_fingerprint);
        $drift = $this->drift($added);
        $this->assertSame('WARNING', $drift->dq_class);
        $this->assertStringContainsString('Columnas agregadas: vendedor', $drift->reason);
        $this->assertStringNotContainsString('Juan', $drift->reason, 'sin valores');
        $metrics = $this->decode($added->report->metrics);
        $this->assertEquals(['previous_version' => 1, 'added' => ['vendedor'], 'removed' => [], 'retyped' => []], array_intersect_key($metrics['schema']['drift'], array_flip(['previous_version', 'added', 'removed', 'retyped'])));
        $this->assertSame($added->bronze->schema_fingerprint, $metrics['schema']['fingerprint']);
        $bronzeColumns = $this->decode($added->bronze->metadata)['columns'];
        $this->assertArrayHasKey('vendedor', $bronzeColumns);

        // Una columna quitada y otra agregada: ambas se listan; versión 3.
        $swapped = $this->load([...array_slice($base, 0, 5), 'Descuento'], [['F-4', '2026-01-05', 'CEM-50', '2', '8.50', '0']]);
        $this->assertSame(3, (int) $swapped->bronze->schema_version);
        $reason = $this->drift($swapped)->reason;
        $this->assertStringContainsString('Columnas agregadas: descuento', $reason);
        $this->assertStringContainsString('Columnas quitadas: canal, vendedor', $reason);
    }

    public function test_the_same_file_again_is_not_drift_and_a_different_entity_is_compared_apart(): void
    {
        $headers = ['Nro Factura', 'Fecha Emisión', 'Código', 'Cantidad', 'Precio Unitario'];
        $rows = [['F-1', '2026-01-05', 'CEM-50', '2', '8.50']];
        $first = $this->load($headers, $rows);
        $again = $this->load($headers, $rows);
        $this->assertSame($first->bronze->id, $again->bronze->id);
        $this->assertNull($this->drift($again), 'reenviar el mismo archivo no es deriva');

        // Otra entidad con columnas distintas no es deriva de ventas: cada entidad compara con la suya.
        $products = $this->load(['codigo', 'nombre'], [['A', 'Producto A']], null, 'products');
        $this->assertNull($this->drift($products));
        $this->assertSame(1, (int) $products->bronze->schema_version);
    }

    public function test_a_data_source_stores_the_combined_fingerprint_and_raises_its_version_with_each_drift(): void
    {
        $source = $this->withinTenant($this->tenant, fn () => DataSource::query()->create(['type' => 'file_upload', 'name' => 'Exportación manual', 'config' => []]));
        $this->assertSame(1, (int) $source->refresh()->schema_version);
        $this->assertNull($source->schema_fingerprint);

        $headers = ['Nro Factura', 'Fecha Emisión', 'Código', 'Cantidad', 'Precio Unitario'];
        $one = $this->load($headers, [['F-1', '2026-01-05', 'CEM-50', '2', '8.50']], $source->id);
        $afterFirst = DB::table('data_sources')->where('id', $source->id)->first();
        $this->assertSame(1, (int) $afterFirst->schema_version);
        $this->assertNotNull($afterFirst->schema_fingerprint, 'la huella de la fuente se guarda desde la primera carga');
        $this->assertSame($one->bronze->data_source_id, $source->id);

        $this->load([...$headers, 'Vendedor'], [['F-2', '2026-01-05', 'CEM-50', '2', '8.50', 'x']], $source->id);
        $afterDrift = DB::table('data_sources')->where('id', $source->id)->first();
        $this->assertSame(2, (int) $afterDrift->schema_version, 'sube con la deriva');
        $this->assertNotSame($afterFirst->schema_fingerprint, $afterDrift->schema_fingerprint);

        // Otra carga del mismo esquema, y la fuente sin fuente asociada no se mezclan.
        $this->load([...$headers, 'Vendedor'], [['F-3', '2026-01-05', 'CEM-50', '2', '8.50', 'y']], $source->id);
        $this->assertSame(2, (int) DB::table('data_sources')->where('id', $source->id)->value('schema_version'));
        $withoutSource = $this->load($headers, [['F-4', '2026-01-05', 'CEM-50', '2', '8.50']]);
        $this->assertNull($this->drift($withoutSource), 'sin fuente se compara con las cargas sin fuente, no con las de la fuente');
        $this->assertSame(2, (int) DB::table('data_sources')->where('id', $source->id)->value('schema_version'));
    }

    public function test_sql_types_count_but_an_unknown_type_is_not_drift(): void
    {
        $drift = new SchemaDrift;
        $this->withinTenant($this->tenant, function () use ($drift): void {
            $stored = $drift->observe(null, 'sales', ['num' => 'string', 'cantidad' => null, 'precio' => 'float']);
            $this->assertNull($stored['drift']);
            DB::table('bronze_objects')->insert([
                'tenant_id' => $this->tenant->id, 'kind' => 'sql_extract', 'entity' => 'sales', 'storage_driver' => 'local', 'object_key' => 'k', 'sha256' => str_repeat('a', 64),
                'size_bytes' => 1, 'schema_fingerprint' => $stored['fingerprint'], 'schema_version' => 1, 'metadata' => json_encode(['columns' => $stored['columns']]),
                'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // La columna `cantidad` (antes solo NULL) ahora se observa como int: no es deriva.
            $learned = $drift->observe(null, 'sales', ['num' => 'string', 'cantidad' => 'int', 'precio' => 'float']);
            $this->assertNull($learned['drift']);
            $this->assertSame(1, $learned['version']);
            // Pero `precio` pasó de float a string: cambio de tipo.
            $retyped = $drift->observe(null, 'sales', ['num' => 'string', 'cantidad' => 'int', 'precio' => 'string']);
            $this->assertSame(['precio'], $retyped['drift']['retyped']);
            $this->assertSame(2, $retyped['version']);
        });
    }
}
