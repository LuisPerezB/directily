<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Bronze\BronzeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * `php artisan predictily:bronze-reprocess {id}` (ADR-V25-04 y ADR-V25-24; W2-A): re-ejecuta de forma determinista la normalización y la
 * escritura de un objeto Bronze de ventas con el mapeo y las opciones de la carga original; es idempotente (la segunda vez da `unchanged`),
 * no destruye datos y no consume la cuota mensual de filas.
 */
final class BronzeReprocessTest extends TestCase
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

    /** @return array<string, mixed> */
    private function reprocess(int $id): array
    {
        $code = Artisan::call('predictily:bronze-reprocess', ['id' => $id, '--json' => true]);
        $output = trim(Artisan::output());
        $this->assertSame(0, $code, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<list<string|int|float>> */
    private function rows(): array
    {
        return [
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50', '1710034065'),
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'VAR-12', '1', '7.20', '1710034065'),
            $this->salesRow('F-2', 'Factura', 'Anulada', '2026-01-06 10:00:00', '001', 'CEM-50', '1', '8.50'),
        ];
    }

    public function test_reprocessing_an_uploaded_file_is_deterministic_and_idempotent(): void
    {
        $job = $this->importSales($this->rows());
        $usageBefore = (int) DB::table('tenant_usage')->where('tenant_id', $this->tenant->id)->where('metric', 'import_rows')->sum('value');
        $salesBefore = DB::table('sales')->orderBy('document_number')->get(['id', 'document_number', 'source_hash', 'updated_at', 'current_revision', 'bronze_object_id'])->all();
        $this->assertNotEmpty($salesBefore);

        $first = $this->reprocess($job->bronze_object_id);
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 2, 'stale_skipped' => 0, 'rejected' => 0], $first['documents']);
        $this->assertSame($job->bronze_object_id, $first['object_id']);
        $second = $this->reprocess($job->bronze_object_id);
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 2, 'stale_skipped' => 0, 'rejected' => 0], $second['documents'], 'idempotente: una segunda vez da unchanged');

        $this->assertEquals($salesBefore, DB::table('sales')->orderBy('document_number')->get(['id', 'document_number', 'source_hash', 'updated_at', 'current_revision', 'bronze_object_id'])->all(), 'no reescribe ni archiva nada');
        $this->assertSame(0, DB::table('sales_document_revisions')->count());
        $this->assertSame($usageBefore, (int) DB::table('tenant_usage')->where('tenant_id', $this->tenant->id)->where('metric', 'import_rows')->sum('value'), 'reprocesar no consume la cuota mensual de filas');
        $reprocessJob = DB::table('import_jobs')->where('id', $first['import_job_id'])->first();
        $this->assertSame('completed', $reprocessJob->status);
        $this->assertSame($job->bronze_object_id, (int) $this->decode($reprocessJob->options)['reprocess_of_bronze_object_id']);
        $this->assertStringContainsString('reprocesamiento', $reprocessJob->original_filename);
        $this->assertSame(0, DB::table('import_errors')->where('import_job_id', $first['import_job_id'])->count());
    }

    public function test_reprocessing_rebuilds_lost_documents_with_the_same_content_and_lineage(): void
    {
        $job = $this->importSales($this->rows());
        $before = DB::table('sales')->where('document_number', 'F-1')->first();
        $items = DB::table('sale_items')->where('sale_id', $before->id)->orderBy('line_number')->get(['product_id', 'quantity', 'unit_price', 'net_amount'])->all();
        DB::table('sales')->where('tenant_id', $this->tenant->id)->delete(); // pérdida de Silver (p. ej. retención o error operativo)

        $result = $this->reprocess($job->bronze_object_id);
        $this->assertSame(2, $result['documents']['new']);
        $after = DB::table('sales')->where('document_number', 'F-1')->first();
        $this->assertSame($before->source_hash, $after->source_hash, 'determinista: mismo contenido, misma huella');
        $this->assertEquals($items, DB::table('sale_items')->where('sale_id', $after->id)->orderBy('line_number')->get(['product_id', 'quantity', 'unit_price', 'net_amount'])->all());
        $this->assertSame([$job->bronze_object_id, 1, 'sales@2026.03.1'], [(int) $after->bronze_object_id, (int) $after->source_row, $after->schema_version]);
        $this->assertSame($this->reprocess($job->bronze_object_id)['documents']['unchanged'], 2);
    }

    public function test_reprocessing_a_batch_object_is_idempotent_too(): void
    {
        $documents = [['document_number' => 'B-1', 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001', 'customer_code' => '1710034065',
            'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => 2, 'unit_price' => 5]]]];
        $objectId = $this->postJson('/api/v1/sales/batch', ['documents' => $documents])->assertOk()->json('data.bronze_object_id');
        $requests = (int) DB::table('tenant_usage')->where('tenant_id', $this->tenant->id)->where('metric', 'api_requests')->sum('value');

        $result = $this->reprocess($objectId);
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 1, 'stale_skipped' => 0, 'rejected' => 0], $result['documents']);
        $this->assertSame($requests, (int) DB::table('tenant_usage')->where('tenant_id', $this->tenant->id)->where('metric', 'api_requests')->sum('value'));
        $this->assertSame(1, DB::table('bronze_objects')->where('kind', 'api_batch')->count(), 'no captura otro objeto: reprocesa el mismo');
        $this->assertSame($objectId, (int) DB::table('sales')->where('document_number', 'B-1')->value('bronze_object_id'));
    }

    public function test_it_refuses_unknown_non_sales_purged_or_corrupted_objects(): void
    {
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => 987654]));
        $this->assertStringContainsString('No existe', Artisan::output());

        $products = $this->uploadAndValidate($this->tenant, $this->csv(['codigo', 'nombre'], [['A', 'Producto A']]), 'products', [], [], 'productos.csv');
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => $products->bronze_object_id]));
        $this->assertStringContainsString('ventas', Artisan::output());

        $job = $this->importSales($this->rows());
        $object = DB::table('bronze_objects')->where('id', $job->bronze_object_id)->first();
        // Contenido alterado: el SHA-256 no coincide y el reprocesamiento se niega.
        $disk = Storage::disk('local');
        $disk->put($object->object_key, "adulterado\n");
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => $object->id]));
        $this->assertStringContainsString('SHA-256', Artisan::output());

        // Purgado por retención: no hay contenido.
        DB::table('bronze_objects')->where('id', $object->id)->update(['status' => 'purged']);
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => $object->id]));
        $this->assertStringContainsString('purgado', Artisan::output());
        $this->assertSame(2, DB::table('sales')->count(), 'ningún intento fallido destruyó datos');
    }

    public function test_the_default_output_summarizes_the_documents(): void
    {
        $job = $this->importSales($this->rows());
        $this->artisan('predictily:bronze-reprocess', ['id' => $job->bronze_object_id])->expectsOutputToContain('sin cambios 2')->assertSuccessful();
        // La orden verifica que el servicio de Bronze resuelve el contenido íntegro del original antes de reprocesar.
        $this->assertSame(hash('sha256', $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->read(BronzeObject::query()->findOrFail($job->bronze_object_id)))), DB::table('bronze_objects')->where('id', $job->bronze_object_id)->value('sha256'));
    }
}
