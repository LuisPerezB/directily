<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Models\Tenant;
use App\Services\Privacy\TenantDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Conversation\Support\SeedsSupplyPlan;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación LOPDP (portabilidad) con las tablas de la etapa 2: incluye los datos de la empresa de los cores nuevos, nunca
 * credenciales ni la configuración de conexión de las fuentes, y nada de otra empresa.
 */
class TenantDataExporterStage2Test extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;
    use SeedsSupplyPlan;

    /** Tablas nuevas de la etapa 2 que deben aparecer en la exportación (migraciones 2026_02_01 … 2026_02_06). */
    private const STAGE2_TABLES = [
        'core_runs', 'data_snapshots', 'data_coverage', 'warehouses', 'unit_conversions', 'commercial_commitments', 'commercial_commitment_items',
        'commercial_commitment_revisions', 'calendar_events', 'data_sources', 'source_mappings', 'source_syncs',
        'customer_predictions', 'customer_product_rankings', 'customer_activity', 'customer_reconciliations',
        'exchange_rates', 'supplier_offers', 'location_capacities', 'purchase_budgets', 'production_resources', 'production_recipes',
        'production_recipe_lines', 'supply_plans', 'supply_plan_lines', 'supply_plan_shortfalls', 'cost_forecasts', 'scenario_evaluations',
    ];

    /** Cadenas que NUNCA pueden aparecer en el ZIP. */
    private const FORBIDDEN = ['SECRETO-CRED-A', 'db-interna-host-A', 'usuario-sql-A', 'ERR-INTERNO-A', 'Feriado público compartido'];

    /** @return array<string, int> filas insertadas por tabla (para comparar con el resumen) */
    private function seedStage2(Tenant $tenant, string $marker): array
    {
        $now = now();
        $tid = $tenant->id;
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $supply = $this->seedSupplyPlan($tenant, ['branches' => ['b1' => $cat['branch'], 'b2' => $cat['branch']], 'products' => $cat['products']], supplierName: "Proveedor {$marker}");

        $source = (int) DB::table('data_sources')->insertGetId([
            'tenant_id' => $tid, 'type' => 'sql', 'connector_key' => 'postgresql', 'name' => "Fuente {$marker}", 'status' => 'active',
            'config' => json_encode(['host' => 'db-interna-host-A', 'username' => 'usuario-sql-A']), 'credentials_encrypted' => 'SECRETO-CRED-A',
            'last_error' => 'ERR-INTERNO-A', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $mapping = (int) DB::table('source_mappings')->insertGetId([
            'tenant_id' => $tid, 'data_source_id' => $source, 'entity' => 'sales', 'query' => "SELECT 1 AS x /* {$marker} */", 'field_map' => '{}',
            'options' => '{}', 'version' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('source_syncs')->insert([
            'tenant_id' => $tid, 'data_source_id' => $source, 'source_mapping_id' => $mapping, 'entity' => 'sales', 'mode' => 'full', 'status' => 'completed',
            'rows_extracted' => 10, 'error_message' => 'ERR-INTERNO-A', 'stats' => json_encode(['marker' => $marker]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('data_snapshots')->insert([
            'tenant_id' => $tid, 'core' => 'customer', 'as_of' => '2026-10-01', 'hash' => str_repeat('a', 64), 'components' => json_encode(['marker' => $marker]),
            'row_counts' => '{}', 'coverage' => '{}',
        ]);
        DB::table('data_coverage')->insert([
            'tenant_id' => $tid, 'entity' => 'sales', 'data_source_id' => $source, 'complete_through' => '2026-09-30', 'basis' => 'declared', 'notes' => "Cobertura {$marker}",
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('warehouses')->insert(['tenant_id' => $tid, 'branch_id' => $cat['branch'], 'code' => 'ALM-1', 'name' => "Almacén {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('unit_conversions')->insert(['tenant_id' => $tid, 'from_unit' => 'saco', 'to_unit' => 'kg', 'factor' => 50, 'source' => "manual {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        $commitment = (int) DB::table('commercial_commitments')->insertGetId([
            'tenant_id' => $tid, 'customer_id' => $cat['customers']['c1'], 'commitment_type' => 'order', 'external_id' => "PED-{$marker}", 'status' => 'confirmed',
            'issued_at' => $now, 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('commercial_commitment_items')->insert([
            'tenant_id' => $tid, 'commitment_id' => $commitment, 'line_number' => 1, 'product_id' => $cat['products']['p1'], 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('commercial_commitment_revisions')->insert([
            'tenant_id' => $tid, 'commitment_id' => $commitment, 'revision' => 1, 'status' => 'confirmed', 'source_hash' => str_repeat('b', 64), 'snapshot' => json_encode(['marker' => $marker]),
        ]);
        DB::table('calendar_events')->insert([
            'tenant_id' => $tid, 'scope' => 'tenant', 'code' => 'EVT-1', 'name' => "Evento {$marker}", 'category' => 'campaign', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('exchange_rates')->insert(['tenant_id' => $tid, 'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.1, 'valid_on' => '2026-10-01', 'source' => "manual {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('supplier_offers')->insert([
            'tenant_id' => $tid, 'supplier_id' => $supply['supplier'], 'product_id' => $cat['products']['p1'], 'unit_cost' => 6.25, 'currency' => 'USD', 'unit' => 'saco',
            'notes' => "Oferta {$marker}", 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('location_capacities')->insert(['tenant_id' => $tid, 'branch_id' => $cat['branch'], 'capacity' => 100, 'capacity_unit' => 'm3', 'notes' => "Capacidad {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('purchase_budgets')->insert(['tenant_id' => $tid, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => 5000, 'currency' => 'USD', 'notes' => "Presupuesto {$marker}", 'created_at' => $now, 'updated_at' => $now]);
        $resource = (int) DB::table('production_resources')->insertGetId([
            'tenant_id' => $tid, 'code' => 'RES-1', 'name' => "Recurso {$marker}", 'capacity_per_period' => 40, 'capacity_unit' => 'horas', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $recipe = (int) DB::table('production_recipes')->insertGetId([
            'tenant_id' => $tid, 'code' => 'REC-1', 'name' => "Receta {$marker}", 'output_product_id' => $cat['products']['p1'], 'output_quantity' => 10, 'resource_id' => $resource,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('production_recipe_lines')->insert(['tenant_id' => $tid, 'recipe_id' => $recipe, 'input_product_id' => $cat['products']['p2'], 'quantity_per_batch' => 3, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cost_forecasts')->insert([
            'tenant_id' => $tid, 'core_run_id' => $supply['run'], 'product_id' => $cat['products']['p1'], 'cost_source' => 'purchase_history', 'currency' => 'USD', 'method' => "ets {$marker}",
            'quality_status' => 'ok', 'n_obs' => 12,
        ]);
        $scenarioRun = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'scenario', 'operation' => 'evaluate', 'version' => 1, 'run_type' => 'production', 'status' => 'completed', 'options' => '{}',
            'assumptions' => '[]', 'limitations' => '[]', 'triggered_by' => 'user', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('scenario_evaluations')->insert([
            'tenant_id' => $tid, 'core_run_id' => $scenarioRun, 'name' => "Escenario {$marker}", 'horizon_days' => 30, 'request' => '{}', 'results' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Calendario público compartido (tenant_id NULL): no es dato de la empresa y no debe exportarse.
        DB::table('calendar_events')->insert([
            'tenant_id' => null, 'scope' => 'country', 'scope_ref' => 'EC', 'code' => 'FERIADO-PUBLICO-'.$tid, 'name' => 'Feriado público compartido', 'category' => 'holiday',
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $counts = [];
        foreach (self::STAGE2_TABLES as $table) {
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

    public function test_export_includes_the_stage2_tables_of_the_company_only_and_never_credentials(): void
    {
        Storage::fake('local');
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $countsA = $this->seedStage2($tenantA, 'MARCADOR-A');
        $this->seedStage2($tenantB, 'MARCADOR-B');
        foreach (self::STAGE2_TABLES as $table) {
            $this->assertGreaterThan(0, $countsA[$table], "La prueba debe sembrar filas en {$table}.");
        }

        [$files, $summary] = $this->exportAndRead($tenantA, $ownerA);

        foreach (self::STAGE2_TABLES as $table) {
            $this->assertArrayHasKey("{$table}.csv", $files, "Falta {$table}.csv en la exportación");
            $this->assertSame($countsA[$table], $summary[$table], "Resumen de {$table}");
            // Filas del CSV = filas de la empresa (sin cabecera); el BOM y las cabeceras no cuentan.
            $rows = array_filter(array_map('str_getcsv', preg_split('/\r\n|\n/', trim($files["{$table}.csv"])) ?: []));
            $this->assertCount($countsA[$table] + 1, $rows, "Filas de {$table}.csv");
        }
        // Los datos de la empresa están (muestras por tabla con texto libre) …
        foreach (['core_runs' => 'forecast', 'data_coverage' => 'MARCADOR-A', 'warehouses' => 'MARCADOR-A', 'commercial_commitments' => 'PED-MARCADOR-A',
            'commercial_commitment_revisions' => 'MARCADOR-A', 'calendar_events' => 'Evento MARCADOR-A', 'source_mappings' => 'MARCADOR-A', 'exchange_rates' => 'MARCADOR-A',
            'supplier_offers' => 'Oferta MARCADOR-A', 'location_capacities' => 'MARCADOR-A', 'purchase_budgets' => 'MARCADOR-A', 'production_resources' => 'MARCADOR-A',
            'production_recipes' => 'MARCADOR-A', 'cost_forecasts' => 'MARCADOR-A', 'scenario_evaluations' => 'MARCADOR-A', 'data_snapshots' => 'MARCADOR-A',
            'source_syncs' => 'MARCADOR-A', 'data_sources' => 'Fuente MARCADOR-A'] as $table => $needle) {
            $this->assertStringContainsString($needle, $files["{$table}.csv"], "{$table}.csv debe contener datos de la empresa");
        }
        $this->assertStringContainsString('0.65', $files['customer_predictions.csv']);
        $this->assertStringContainsString('lead_time', $files['supply_plan_shortfalls.csv']);
        $this->assertStringContainsString('optimal', $files['supply_plans.csv']);

        // … nada de otra empresa, ningún secreto ni configuración de conexión, ni el calendario público.
        $everything = implode("\n", $files);
        $this->assertStringNotContainsString('MARCADOR-B', $everything);
        foreach (self::FORBIDDEN as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything, "No debe exportarse: {$forbidden}");
        }
        // Las cabeceras de cada CSV tampoco incluyen columnas de credenciales o errores internos.
        foreach (self::STAGE2_TABLES as $table) {
            $header = strtolower((string) strtok(ltrim($files["{$table}.csv"], "\xEF\xBB\xBF"), "\n"));
            foreach (TenantDataExporter::FORBIDDEN_COLUMN_PARTS as $part) {
                $this->assertStringNotContainsString($part, $header, "{$table}.csv expone una columna con «{$part}»");
            }
        }
        $this->assertStringContainsString('credenciales', $files['LEEME.txt']);
    }

    public function test_the_other_company_gets_only_its_own_rows(): void
    {
        Storage::fake('local');
        [$tenantA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->seedStage2($tenantA, 'MARCADOR-A');
        $countsB = $this->seedStage2($tenantB, 'MARCADOR-B');

        [$files, $summary] = $this->exportAndRead($tenantB, $ownerB);

        $this->assertStringNotContainsString('MARCADOR-A', implode("\n", $files));
        foreach (self::STAGE2_TABLES as $table) {
            $this->assertSame($countsB[$table], $summary[$table], "Resumen de {$table} de la empresa B");
        }
    }

    /** Toda tabla con `tenant_id` está clasificada: exportada o excluida con motivo. Una tabla nueva sin clasificar rompe la prueba. */
    public function test_every_tenant_table_is_classified_as_exported_or_deliberately_excluded(): void
    {
        $tenantTables = collect(DB::select("select table_name from information_schema.columns where column_name = 'tenant_id' and table_schema = current_schema()"))
            ->pluck('table_name')->unique()->sort()->values()->all();
        $classified = array_merge(array_keys(TenantDataExporter::TABLES), array_keys(TenantDataExporter::NOT_EXPORTED));
        // Las tablas hijas (sin `tenant_id` propio) pertenecen a la empresa por su tabla padre, que sí lo tiene.
        $children = array_keys(TenantDataExporter::CHILD_TABLES);

        $this->assertSame([], array_values(array_diff($tenantTables, $classified)), 'Tablas con tenant_id sin clasificar en TenantDataExporter (TABLES o NOT_EXPORTED).');
        $this->assertSame([], array_values(array_diff($classified, $tenantTables, $children)), 'TenantDataExporter menciona tablas que no existen o no tienen tenant_id.');
        foreach (TenantDataExporter::CHILD_TABLES as $table => [$link, $parent]) {
            $this->assertArrayHasKey($table, TenantDataExporter::TABLES, "{$table} (hija de {$parent}) debe exportarse");
            $this->assertNotContains($table, $tenantTables, "{$table} tiene tenant_id: no debe declararse como hija");
            $this->assertContains($parent, $tenantTables, "El padre {$parent} de {$table} debe tener tenant_id");
            $this->assertTrue(Schema::hasColumn($table, $link), "No existe {$table}.{$link}");
        }
        $this->assertSame([], array_values(array_intersect(array_keys(TenantDataExporter::TABLES), array_keys(TenantDataExporter::NOT_EXPORTED))), 'Una tabla no puede estar exportada y excluida a la vez.');
        foreach (TenantDataExporter::NOT_EXPORTED as $table => $reason) {
            $this->assertNotSame('', trim($reason), "Falta el motivo de exclusión de {$table}");
        }
        foreach (self::STAGE2_TABLES as $table) {
            $this->assertArrayHasKey($table, TenantDataExporter::TABLES, "{$table} (etapa 2) debe exportarse");
        }
    }

    /** Las columnas listadas existen y ninguna es una credencial, token o error interno. */
    public function test_exported_columns_exist_and_none_is_a_secret(): void
    {
        foreach (TenantDataExporter::TABLES as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "No existe la tabla {$table}");
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "No existe {$table}.{$column}");
                foreach (TenantDataExporter::FORBIDDEN_COLUMN_PARTS as $part) {
                    $this->assertStringNotContainsString($part, $column, "{$table}.{$column} parece un secreto");
                }
            }
        }
        $this->assertNotContains('config', TenantDataExporter::TABLES['data_sources']);
    }
}
