<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * Modo de ingestión (ADR-V25-23) en la importación de archivos: primera carga = backfill, siguientes = incremental, declaración
 * explícita respetada, decisión al iniciar la escritura, estadísticas de versiones y el comando demo con backfill explícito.
 */
final class SalesImportIngestionModeTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, ReadsSalesVersions, RefreshDatabase;

    private const T0 = '2026-06-10 12:00:00';

    private const T1 = '2026-06-12 15:30:00';

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

    /** @return list<string> */
    private function headers(): array
    {
        return [...self::SALES_HEADERS, 'Modificado en'];
    }

    /**
     * Una factura de dos líneas.
     *
     * @return list<list<string>>
     */
    private function invoice(string $number, string $qtyA = '2', string $date = '2026-05-04 10:00:00', string $status = '', string $modified = ''): array
    {
        return [
            [$number, 'Factura', $status, $date, '001', 'CEM-50', 'Cemento 50kg', 'Cemento', $qtyA, '8.50', '0', '0', '', '', '', 'Local', $modified],
            [$number, 'Factura', $status, $date, '001', 'VAR-12', 'Varilla 12mm', 'Hierro', '1', '7.20', '0', '0', '', '', '', 'Local', $modified],
        ];
    }

    /** @param  list<list<string>>  $rows */
    private function validate(array $rows, array $options = []): ImportJob
    {
        return $this->uploadAndValidate($this->tenant, $this->csv($this->headers(), $rows), options: $options);
    }

    private function confirm(ImportJob $job): ImportJob
    {
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        return $this->withinTenant($this->tenant, fn () => ImportJob::query()->findOrFail($job->id));
    }

    /** @return list<string> */
    private function modesOf(string ...$numbers): array
    {
        return array_map(fn (string $n) => $this->saleRow($this->tenant, $n)->ingestion_mode, $numbers);
    }

    public function test_first_import_is_backfill_and_the_second_is_incremental_by_default(): void
    {
        $this->travelTo(self::T0);
        $first = $this->validate($this->invoice('F-1'));
        $this->assertSame('validated', $first->status);
        $this->assertSame('backfill', $first->stats['ingestion_mode'], 'proyección en la validación');
        $this->assertSame('first_load', $first->stats['ingestion_mode_basis']);
        $first = $this->confirm($first);
        $this->assertSame('completed', $first->status);
        $this->assertSame('backfill', $first->stats['ingestion_mode']);
        $this->assertSame('backfill', $first->options['ingestion_mode'], 'el valor resuelto queda en import_jobs.options');
        $this->getJson("/api/v1/imports/{$first->id}")->assertOk()->assertJsonPath('data.options.ingestion_mode', 'backfill')->assertJsonPath('data.stats.ingestion_mode', 'backfill');

        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertSame('backfill', $sale->ingestion_mode);
        $this->assertNull($sale->version_known_from);
        $this->assertSame(self::T0, $this->utc($sale->first_seen_at));

        $this->travelTo(self::T1);
        $second = $this->validate($this->invoice('F-2'));
        $this->assertSame('incremental', $second->stats['ingestion_mode']);
        $this->assertSame('subsequent_load', $second->stats['ingestion_mode_basis']);
        $second = $this->confirm($second);
        $this->assertSame('incremental', $second->options['ingestion_mode']);
        $sale = $this->saleRow($this->tenant, 'F-2');
        $this->assertSame('incremental', $sale->ingestion_mode);
        $this->assertSame(self::T1, $this->utc($sale->version_known_from));
        $this->assertSame(['backfill'], $this->modesOf('F-1'), 'el modo del documento anterior no cambia');
    }

    public function test_explicit_declaration_is_respected_over_the_default_rule(): void
    {
        $this->travelTo(self::T0);
        // Empresa sin ventas, pero se declara incremental.
        $first = $this->confirm($this->validate($this->invoice('F-1'), ['ingestion_mode' => 'incremental']));
        $this->assertSame('incremental', $first->stats['ingestion_mode']);
        $this->assertSame('declared', $first->stats['ingestion_mode_basis']);
        $this->assertSame(['incremental'], $this->modesOf('F-1'));
        $this->assertSame(self::T0, $this->utc($this->saleRow($this->tenant, 'F-1')->version_known_from));

        // Empresa con ventas, pero se declara backfill (carga histórica adicional).
        $this->travelTo(self::T1);
        $second = $this->confirm($this->validate($this->invoice('F-0', date: '2025-01-15 09:00:00'), ['ingestion_mode' => 'backfill']));
        $this->assertSame('backfill', $second->options['ingestion_mode']);
        $this->assertSame(['backfill'], $this->modesOf('F-0'));
        $this->assertNull($this->saleRow($this->tenant, 'F-0')->version_known_from);
    }

    public function test_the_mode_is_decided_when_the_write_starts_not_when_the_file_is_validated(): void
    {
        $this->travelTo(self::T0);
        // Dos archivos validados con la empresa aún vacía: ambos proyectan backfill.
        $a = $this->validate($this->invoice('F-1'));
        $b = $this->validate($this->invoice('F-2'));
        $this->assertSame('backfill', $a->stats['ingestion_mode']);
        $this->assertSame('backfill', $b->stats['ingestion_mode']);

        $this->confirm($a);
        $b = $this->confirm($b);
        $this->assertSame(['backfill', 'incremental'], $this->modesOf('F-1', 'F-2'), 'al confirmar B la empresa ya tenía ventas: primera carga solo hay una');
        $this->assertSame('incremental', $b->stats['ingestion_mode']);
        $this->assertSame('subsequent_load', $b->stats['ingestion_mode_basis']);
    }

    public function test_ingestion_mode_option_is_validated(): void
    {
        $upload = $this->uploadImport($this->csv($this->headers(), $this->invoice('F-1')))->assertCreated();
        $mapping = $upload->json('data.suggested_mapping');
        $this->putJson("/api/v1/imports/{$upload->json('data.id')}/mapping", ['mapping' => $mapping, 'options' => ['ingestion_mode' => 'semanal']])
            ->assertUnprocessable()->assertJsonValidationErrors('options.ingestion_mode');
        // null/vacío = automático.
        $this->putJson("/api/v1/imports/{$upload->json('data.id')}/mapping", ['mapping' => $mapping, 'options' => ['ingestion_mode' => null]])->assertAccepted();

        // Solo aplica a ventas.
        $products = $this->uploadImport($this->csv(['sku', 'name'], [['P-1', 'Producto uno']]), 'products', 'productos.csv')->assertCreated();
        $this->putJson("/api/v1/imports/{$products->json('data.id')}/mapping", ['mapping' => $products->json('data.suggested_mapping'), 'options' => ['ingestion_mode' => 'backfill']])
            ->assertUnprocessable()->assertJsonValidationErrors('options.ingestion_mode');
    }

    public function test_import_updates_archive_versions_and_report_the_new_statistics(): void
    {
        $this->travelTo(self::T0);
        $first = $this->confirm($this->validate($this->invoice('F-1', modified: '2026-06-01 10:00:00')));
        $this->assertSame(0, $first->stats['revisions_archived']);
        $this->assertSame(0, $first->stats['stale_skipped']);

        // Versión posterior en la fuente con otra cantidad: se actualiza y la anterior se archiva.
        $this->travelTo(self::T1);
        $updated = $this->validate($this->invoice('F-1', qtyA: '9', modified: '2026-06-05 10:00:00'));
        $this->assertSame(1, $updated->stats['documents_updated']);
        $this->assertSame(1, $updated->stats['revisions_archived'], 'proyección de la validación');
        $this->assertSame(0, DB::table('sales_document_revisions')->count(), 'la validación no escribe');
        $updated = $this->confirm($updated);
        $this->assertSame(1, $updated->stats['documents_updated']);
        $this->assertSame(1, $updated->stats['revisions_archived']);
        $this->assertSame(0, $updated->stats['stale_skipped']);
        $this->assertSame(0, $updated->stats['updated_without_source_order']);
        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertSame($first->id, (int) $revision->import_job_id, 'import que tenía la versión archivada');
        $this->assertSame($updated->id, (int) $revision->superseded_by_import_job_id, 'import que la reemplazó');
        $this->assertSame('lines_changed', $revision->change_kind);
        $this->assertSame('backfill', $revision->ingestion_mode, 'la versión archivada conserva su modo');
        $this->assertSame('incremental', $this->saleRow($this->tenant, 'F-1')->ingestion_mode, 'la vigente, el de esta escritura');

        // Un export viejo vuelve a subirse (versión ANTERIOR): se descarta sin tocar nada.
        $this->travelTo('2026-06-20 09:00:00');
        $stale = $this->validate($this->invoice('F-1', qtyA: '3', modified: '2026-06-02 10:00:00'));
        $this->assertSame(1, $stale->stats['stale_skipped']);
        $this->assertSame(0, $stale->stats['documents_updated']);
        $codes = collect($this->getJson("/api/v1/imports/{$stale->id}")->json('data.quality_report.issues'))->pluck('code')->all();
        $this->assertContains('stale_document_skipped', $codes);
        $this->assertNotContains('existing_document_changed', $codes);
        $stale = $this->confirm($stale);
        $this->assertSame(1, $stale->stats['stale_skipped']);
        $this->assertSame(0, $stale->stats['revisions_archived']);
        $this->assertSame(1, (int) $this->saleRow($this->tenant, 'F-1')->current_revision, 'sin cambios');
        $this->assertEqualsWithDelta(9.0, (float) DB::table('sale_items')->where('sale_id', $this->saleRow($this->tenant, 'F-1')->id)->where('line_number', 1)->value('quantity'), 0.0001);
        $this->assertCount(1, $this->headerRevisions($this->tenant, 'F-1'));
    }

    public function test_updates_without_a_source_timestamp_are_counted_and_reported(): void
    {
        $this->travelTo(self::T0);
        $this->confirm($this->validate($this->invoice('F-1')));
        $this->travelTo(self::T1);
        $job = $this->confirm($this->validate($this->invoice('F-1', qtyA: '4')));
        $this->assertSame(1, $job->stats['documents_updated']);
        $this->assertSame(1, $job->stats['updated_without_source_order']);
        $codes = collect($this->getJson("/api/v1/imports/{$job->id}")->json('data.quality_report.issues'))->pluck('code')->all();
        $this->assertContains('updated_without_source_order', $codes);
        $this->assertSame(1, $this->getJson("/api/v1/imports/{$job->id}")->json('data.quality_report.summary.updated_without_source_order'));
    }

    public function test_demo_loads_sales_as_declared_backfill_even_on_a_company_with_sales(): void
    {
        $dir = sys_get_temp_dir().'/predictily-demo-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/mini_branches.csv", $this->csv(['code', 'name', 'province', 'canton', 'commercial_zone'], [['002', 'Sucursal Dos', 'Pichincha', 'Quito', 'Sierra']]));
        file_put_contents("{$dir}/mini_products.csv", $this->csv(['sku', 'name', 'category', 'unit'], [['DEMO-1', 'Producto demo', 'Demo', 'unidad']]));
        file_put_contents("{$dir}/mini_sales.csv", $this->csv(
            ['document_number', 'document_type', 'status', 'issued_at', 'branch_code', 'product_sku', 'product_name', 'quantity', 'unit_price'],
            [['D-1', 'factura', '', '2026-03-02 10:00:00', '001', 'DEMO-1', 'Producto demo', '3', '4.00']],
        ));
        config(['predictily.demo.path' => $dir]);

        try {
            $this->travelTo(self::T0);
            // La empresa ya tiene ventas: por la regla automática la siguiente carga sería incremental.
            $this->confirm($this->validate($this->invoice('F-1')));
            $this->assertSame(['backfill'], $this->modesOf('F-1'));
            $this->travelTo(self::T1);
            $jobs = app(DemoDataService::class)->load($this->tenant, 'mini', $this->owner);

            $this->assertSame('backfill', $jobs['sales']->options['ingestion_mode']);
            $this->assertArrayNotHasKey('ingestion_mode', $jobs['products']->options ?? [], 'solo las ventas llevan modo de ingestión');
            $this->assertSame(['backfill'], $this->modesOf('D-1'));
            $this->assertNull($this->saleRow($this->tenant, 'D-1')->version_known_from);
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }
}
