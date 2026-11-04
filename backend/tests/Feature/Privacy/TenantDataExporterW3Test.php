<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\TenantDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación LOPDP (V2.5 · W3-D): las tablas y columnas nuevas de la calidad ML (`model_promotion_decisions`, `shadow_forecast_values` y las columnas de S05 de
 * `customer_predictions`) pertenecen a la empresa titular: se exportan con todos sus campos y nada de otra empresa. La clasificación la dejó la activación del
 * contrato (4eb34f4); aquí queda demostrada con filas reales.
 */
class TenantDataExporterW3Test extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use ModelQualityFixtures;
    use RefreshDatabase;

    private const S05_COLUMNS = ['expected_orders', 'quantity_per_order', 'expected_units', 'window_p10_days', 'window_p50_days', 'window_p90_days', 'window_status', 'segment'];

    /** @return array{0: array<string, string>, 1: array<string, int>} */
    private function exportAndRead(Tenant $tenant, $owner): array
    {
        $exportId = $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $status = $this->getJson("/api/v1/tenant/export/{$exportId}")->assertOk()->assertJsonPath('data.status', 'completed');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) DB::table('tenant_exports')->where('id', $exportId)->value('path'))) === true);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return [$files, (array) $status->json('data.summary')];
    }

    /** @return array{0: Tenant, 1: User, 2: int} */
    private function company(string $marker): array
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, '2026-09-30', ['horizon' => 30, 'with_recommendations' => false]);
        $registry = (int) DB::table('model_registry')->where('tenant_id', $tenant->id)->value('id');
        $ids = $this->seedPromotionDecisions($tenant->id, $run->id, $registry);
        DB::table('model_promotion_decisions')->where('id', $ids['legacy:global'])->update(['reason' => "Motivo {$marker}", 'approved_by' => $owner->id, 'approved_at' => '2026-10-02 10:00:00+00']);
        $this->seedShadow($tenant->id, $run->id, true, $registry, "tsb_{$marker}");
        DB::table('branches')->where('tenant_id', $tenant->id)->update(['code' => DB::raw("'D'||code")]);   // los dos catálogos de prueba usan los mismos códigos
        DB::table('products')->where('tenant_id', $tenant->id)->update(['sku' => DB::raw("'D'||sku")]);
        $customers = $this->seedCustomerCatalog($tenant);
        $predictions = $this->seedPredictionRun($tenant, $customers);
        $this->seedCustomerS05($tenant->id, $predictions, $customers);
        DB::table('customer_predictions')->where('core_run_id', $predictions)->update(['segment' => "frequency:{$marker}"]);

        return [$tenant, $owner, $run->id];
    }

    public function test_the_w3_tables_are_exported_and_classified(): void
    {
        foreach (['model_promotion_decisions', 'shadow_forecast_values', 'customer_predictions'] as $table) {
            $this->assertArrayHasKey($table, TenantDataExporter::TABLES, "{$table} debe exportarse");
            $this->assertArrayNotHasKey($table, TenantDataExporter::NOT_EXPORTED);
        }
        foreach (self::S05_COLUMNS as $column) {
            $this->assertContains($column, TenantDataExporter::TABLES['customer_predictions'], "customer_predictions.{$column} debe exportarse");
            $this->assertTrue(Schema::hasColumn('customer_predictions', $column));
        }
        // Todas las columnas de las tablas nuevas, salvo tenant_id (implícito), están clasificadas como exportadas: nada se omite sin decidirlo.
        foreach (['model_promotion_decisions', 'shadow_forecast_values'] as $table) {
            $missing = array_values(array_diff(Schema::getColumnListing($table), TenantDataExporter::TABLES[$table], ['tenant_id']));
            $this->assertSame([], $missing, "{$table}: columnas sin clasificar en el exportador");
        }
        $missing = array_values(array_diff(Schema::getColumnListing('customer_predictions'), TenantDataExporter::TABLES['customer_predictions'], ['tenant_id']));
        $this->assertSame([], $missing, 'customer_predictions: columnas sin clasificar en el exportador');
    }

    public function test_the_export_carries_decisions_shadow_and_s05_columns_of_the_company_only(): void
    {
        Storage::fake('local');
        [$tenantA, $ownerA] = $this->company('MARCADOR-A');
        $this->company('MARCADOR-B');

        [$files, $summary] = $this->exportAndRead($tenantA, $ownerA);

        foreach (['model_promotion_decisions', 'shadow_forecast_values', 'customer_predictions'] as $table) {
            $this->assertArrayHasKey("{$table}.csv", $files);
            $this->assertSame(DB::table($table)->where('tenant_id', $tenantA->id)->count(), $summary[$table], "Resumen de {$table}");
        }
        $this->assertSame(6, $summary['model_promotion_decisions']);
        $this->assertSame(6, $summary['shadow_forecast_values']);
        $decisions = $files['model_promotion_decisions.csv'];
        foreach (['scope_key', 'policy', 'policy_version', 'is_effective', 'gates', 'approved_by', 'Motivo MARCADOR-A', 'temporal_stability'] as $needle) {
            $this->assertStringContainsString($needle, $decisions);
        }
        $this->assertStringContainsString('tsb_MARCADOR-A', $files['shadow_forecast_values.csv']);
        $header = strtok(ltrim($files['customer_predictions.csv'], "\xEF\xBB\xBF"), "\n");
        foreach (self::S05_COLUMNS as $column) {
            $this->assertStringContainsString($column, (string) $header);
        }
        $this->assertStringContainsString('frequency:MARCADOR-A', $files['customer_predictions.csv']);
        $this->assertStringContainsString('estimated', $files['customer_predictions.csv']);
        $this->assertStringContainsString('1.35', $files['customer_predictions.csv']);

        $everything = implode("\n", $files);
        $this->assertStringNotContainsString('MARCADOR-B', $everything, 'nada de otra empresa');
    }
}
