<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Models\Tenant;
use App\Services\Privacy\TenantDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación LOPDP (portabilidad) completa de la etapa 1: alertas, escenarios, compras, movimientos, indicadores propios, membresías, consumo,
 * errores de importación, resultados de demanda y registro técnico. Solo quedan fuera las credenciales (y las columnas secretas o internas),
 * el catálogo público compartido y todo lo de otra empresa.
 */
class TenantDataExporterStage1Test extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    /** Tablas de la etapa 1 que antes estaban fuera de la exportación (NOT_EXPORTED «PENDIENTE») y ahora deben salir. */
    private const FORMERLY_PENDING = [
        'alerts', 'scenarios', 'purchase_orders', 'purchase_order_items', 'stock_movements', 'external_indicators', 'external_indicator_observations',
        'tenant_users', 'tenant_exports', 'tenant_usage', 'import_errors', 'forecast_values', 'forecast_actuals', 'forecast_features', 'forecasting_series',
        'model_registry', 'model_evaluations', 'training_jobs', 'data_quality_reports',
    ];

    /** Cadenas que NUNCA pueden aparecer en el ZIP (credenciales, errores técnicos, rutas internas, catálogo público). */
    private const FORBIDDEN = ['HASH-TOKEN-A', 'ruta/interna/artefacto-A', 'ERR-TECNICO-TRABAJO-A', 'INDICADOR-PUBLICO', 'fuente.publica.example'];

    /** @return array<string, int> filas de la empresa por tabla exportada (referencia independiente del exportador) */
    private function seedStage1(Tenant $tenant, string $marker): array
    {
        $now = now();
        $tid = $tenant->id;
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, '2026-09-30', 3);
        $this->seedForecastRun($tenant, $cat, '2026-09-30', ['horizon' => 7]);
        $run = (int) DB::table('forecast_runs')->where('tenant_id', $tid)->value('id');

        DB::table('alerts')->insert([
            'tenant_id' => $tid, 'type' => 'stockout_risk', 'severity' => 'critical', 'title' => "Alerta {$marker}", 'message' => "Riesgo de quiebre {$marker}",
            'payload' => json_encode(['marker' => $marker]), 'dedup_key' => "dedup-{$marker}", 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('scenarios')->insert([
            'tenant_id' => $tid, 'forecast_run_id' => $run, 'name' => "Escenario {$marker}", 'horizon_days' => 30, 'filters' => '{}',
            'assumptions' => json_encode(['price_change_pct' => 0.1]), 'results' => json_encode(['marker' => $marker]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $supplier = (int) DB::table('suppliers')->insertGetId(['tenant_id' => $tid, 'code' => 'PRV-1', 'name' => "Proveedor {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        $order = (int) DB::table('purchase_orders')->insertGetId([
            'tenant_id' => $tid, 'supplier_id' => $supplier, 'branch_id' => $cat['branches']['b1'], 'external_id' => "OC-{$marker}", 'status' => 'open',
            'ordered_at' => '2026-09-01', 'expected_at' => '2026-09-10', 'total' => 150.5, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('purchase_order_items')->insert([
            'tenant_id' => $tid, 'purchase_order_id' => $order, 'product_id' => $cat['products']['p1'], 'quantity_ordered' => 30, 'quantity_received' => 0,
            'unit_cost' => 5.02, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stock_movements')->insert([
            'tenant_id' => $tid, 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'], 'movement_type' => 'adjustment', 'quantity' => -2,
            'occurred_at' => $now, 'reference' => "AJUSTE-{$marker}", 'created_at' => $now,
        ]);

        // Indicadores: uno PROPIO de la empresa y uno PÚBLICO compartido (tenant_id NULL) con sus observaciones.
        $own = (int) DB::table('external_indicators')->insertGetId([
            'tenant_id' => $tid, 'code' => "IND-{$marker}", 'name' => "Indicador propio {$marker}", 'source' => 'manual', 'frequency' => 'monthly',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('external_indicator_observations')->insert([
            ['indicator_id' => $own, 'period_date' => '2026-08-01', 'value' => 101.5, 'published_at' => $now, 'source_url' => "https://fuente.example/{$marker}", 'created_at' => $now],
            ['indicator_id' => $own, 'period_date' => '2026-09-01', 'value' => 102.5, 'published_at' => $now, 'source_url' => "https://fuente.example/{$marker}", 'created_at' => $now],
        ]);
        $public = (int) DB::table('external_indicators')->insertGetId([
            'tenant_id' => null, 'code' => "PUB-{$tid}", 'name' => 'INDICADOR-PUBLICO', 'source' => 'bce', 'frequency' => 'monthly', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('external_indicator_observations')->insert([
            'indicator_id' => $public, 'period_date' => '2026-09-01', 'value' => 999.5, 'published_at' => $now, 'source_url' => 'https://fuente.publica.example/x', 'created_at' => $now,
        ]);

        DB::table('tenant_usage')->insert(['tenant_id' => $tid, 'period' => '2026-10', 'metric' => 'imports', 'value' => 7, 'created_at' => $now, 'updated_at' => $now]);
        $job = (int) DB::table('import_jobs')->insertGetId([
            'tenant_id' => $tid, 'entity_type' => 'sales', 'original_filename' => "ventas-{$marker}.csv", 'stored_path' => "tenants/{$tid}/imports/x.csv",
            'file_hash' => str_repeat('c', 64), 'file_size' => 10, 'file_format' => 'csv', 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('import_errors')->insert([
            'tenant_id' => $tid, 'import_job_id' => $job, 'row_number' => 4, 'column' => 'quantity', 'error_code' => 'invalid_number', 'severity' => 'error',
            'message' => 'Cantidad no numérica', 'raw_value' => "abc-{$marker}", 'created_at' => $now,
        ]);
        DB::table('data_quality_reports')->insert([
            'tenant_id' => $tid, 'import_job_id' => $job, 'scope' => "calidad-{$marker}", 'score' => 0.9, 'summary' => '{}', 'issues' => '[]', 'created_at' => $now,
        ]);
        DB::table('forecast_features')->insert([
            ['tenant_id' => $tid, 'feature_set_version' => 'fs-1', 'name' => "feature-{$marker}", 'kind' => 'calendar', 'created_at' => $now],
            // Definición global compartida (tenant_id NULL): no es de la empresa.
            ['tenant_id' => null, 'feature_set_version' => 'fs-1', 'name' => "feature-global-{$tid}", 'kind' => 'calendar', 'created_at' => $now],
        ]);
        DB::table('forecast_actuals')->insert([
            'tenant_id' => $tid, 'forecast_run_id' => $run, 'node_key' => "series-{$marker}", 'level' => 'series', 'target_date' => '2026-10-01', 'horizon_step' => 1,
            'yhat' => 5, 'actual' => 6, 'abs_error' => 1, 'evaluated_at' => $now,
        ]);

        // Columnas / tablas que jamás deben salir: error técnico del trabajo, ruta del artefacto, hash de un token.
        DB::table('training_jobs')->where('tenant_id', $tid)->update(['error_message' => 'ERR-TECNICO-TRABAJO-A']);
        DB::table('model_registry')->where('tenant_id', $tid)->update(['artifact_path' => 'ruta/interna/artefacto-A']);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => (int) DB::table('tenant_users')->where('tenant_id', $tid)->value('user_id'),
            'tenant_id' => $tid, 'name' => 'datasource:1', 'token' => "HASH-TOKEN-A-{$tid}", 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $counts = [];
        foreach (self::FORMERLY_PENDING as $table) {
            if ($table === 'external_indicator_observations') {
                $counts[$table] = DB::table($table)->whereIn('indicator_id', DB::table('external_indicators')->where('tenant_id', $tid)->select('id'))->count();

                continue;
            }
            $counts[$table] = DB::table($table)->where('tenant_id', $tid)->count();
        }

        return $counts;
    }

    /** @return array{0: array<string, string>, 1: array<string, int>} contenido de cada archivo del ZIP y resumen */
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

    /** @return list<list<string|null>> filas de datos (sin cabecera) de un CSV del ZIP */
    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', trim(ltrim($csv, "\xEF\xBB\xBF"))) ?: []));

        return array_map('str_getcsv', array_slice($lines, 1));
    }

    public function test_every_formerly_pending_table_is_exported_with_exactly_the_company_rows(): void
    {
        Storage::fake('local');
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $countsA = $this->seedStage1($tenantA, 'MARCADOR-A');
        $this->seedStage1($tenantB, 'MARCADOR-B');
        foreach (self::FORMERLY_PENDING as $table) {
            if ($table === 'tenant_exports') {
                continue;   // la fila de la propia exportación se crea al solicitarla
            }
            $this->assertGreaterThan(0, $countsA[$table], "La prueba debe sembrar filas en {$table}.");
        }

        [$files, $summary] = $this->exportAndRead($tenantA, $ownerA);

        foreach (self::FORMERLY_PENDING as $table) {
            $this->assertArrayHasKey("{$table}.csv", $files, "Falta {$table}.csv en la exportación");
            $rows = $this->rows($files["{$table}.csv"]);
            if ($table === 'tenant_exports') {
                $this->assertCount(1, $rows, 'tenant_exports: solo la exportación de la propia empresa');
                $this->assertSame(1, $summary[$table]);

                continue;
            }
            $this->assertSame($countsA[$table], $summary[$table], "Resumen de {$table}");
            $this->assertCount($countsA[$table], $rows, "Filas de {$table}.csv");
        }
        // Los datos de la empresa están (texto libre sembrado por tabla) …
        foreach ([
            'alerts' => 'Alerta MARCADOR-A', 'scenarios' => 'Escenario MARCADOR-A', 'purchase_orders' => 'OC-MARCADOR-A', 'stock_movements' => 'AJUSTE-MARCADOR-A',
            'external_indicators' => 'Indicador propio MARCADOR-A', 'external_indicator_observations' => 'https://fuente.example/MARCADOR-A',
            'import_errors' => 'abc-MARCADOR-A', 'data_quality_reports' => 'calidad-MARCADOR-A', 'forecast_features' => 'feature-MARCADOR-A',
            'forecast_actuals' => 'series-MARCADOR-A', 'tenant_usage' => 'imports', 'tenant_users' => 'owner', 'model_registry' => 'lightgbm_global',
            'model_evaluations' => 'seasonal_naive', 'training_jobs' => 'forecast_pipeline', 'forecasting_series' => 'smooth', 'forecast_values' => 'lightgbm_global',
        ] as $table => $needle) {
            $this->assertStringContainsString($needle, $files["{$table}.csv"], "{$table}.csv debe contener datos de la empresa");
        }
        $this->assertCount(2, $this->rows($files['external_indicator_observations.csv']));
        $this->assertCount(1, $this->rows($files['external_indicators.csv']), 'Solo el indicador propio; el público no es de la empresa');
        $this->assertCount(1, $this->rows($files['forecast_features.csv']), 'Solo la definición propia; la global (tenant_id NULL) no');
    }

    public function test_nothing_from_another_company_and_no_credentials_or_internal_columns(): void
    {
        Storage::fake('local');
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->seedStage1($tenantA, 'MARCADOR-A');
        $countsB = $this->seedStage1($tenantB, 'MARCADOR-B');

        [$filesA] = $this->exportAndRead($tenantA, $ownerA);
        $everythingA = implode("\n", $filesA);
        $this->assertStringNotContainsString('MARCADOR-B', $everythingA);
        foreach (self::FORBIDDEN as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everythingA, "No debe exportarse: {$forbidden}");
        }
        // Credenciales: ni la tabla de tokens ni columnas internas (ruta del artefacto, ruta del archivo exportado, errores técnicos).
        $this->assertArrayNotHasKey('personal_access_tokens.csv', $filesA);
        foreach ([['model_registry', 'artifact_path'], ['tenant_exports', 'path'], ['tenant_exports', 'error_message'], ['training_jobs', 'error_message']] as [$table, $column]) {
            $header = strtolower((string) strtok(ltrim($filesA["{$table}.csv"], "\xEF\xBB\xBF"), "\n"));
            $this->assertStringNotContainsString($column, $header, "{$table}.csv no debe exponer {$column}");
        }

        // La otra empresa recibe solo lo suyo (y tampoco ve nada de A).
        Storage::fake('local');
        [$filesB, $summaryB] = $this->exportAndRead($tenantB, $ownerB);
        $this->assertStringNotContainsString('MARCADOR-A', implode("\n", $filesB));
        foreach (self::FORMERLY_PENDING as $table) {
            if ($table !== 'tenant_exports') {
                $this->assertSame($countsB[$table], $summaryB[$table], "Resumen de {$table} de la empresa B");
            }
        }
        $this->assertStringContainsString('Alerta MARCADOR-B', $filesB['alerts.csv']);
    }

    /** Solo las credenciales quedan fuera; el resto de tablas con `tenant_id` se exporta. */
    public function test_only_credentials_are_excluded(): void
    {
        $this->assertSame(['personal_access_tokens'], array_keys(TenantDataExporter::NOT_EXPORTED));
        $this->assertStringContainsString('credenciales', TenantDataExporter::NOT_EXPORTED['personal_access_tokens']);
        foreach (self::FORMERLY_PENDING as $table) {
            $this->assertArrayHasKey($table, TenantDataExporter::TABLES, "{$table} debe exportarse");
        }
    }
}
