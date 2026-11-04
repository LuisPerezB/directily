<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Support\Canonical\CanonicalContracts;
use App\Support\Canonical\ContractFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Contratos canónicos versionados (V2.5 §3; ADR-V25-24). La prueba FIJA el SHA-256 de las columnas reales (information_schema) de
 * cada entidad y FALLA si cambian sin subir la versión: la huella de CanonicalContracts debe coincidir con la base migrada y,
 * además, con el historial tests/Fixtures/canonical_contract_fingerprints.json (versión → huella, solo se AGREGA). Así actualizar
 * la huella sin subir el correlativo de la versión también falla.
 *
 * Cuando falle porque una migración cambió columnas:
 *  1. suba el correlativo de la `version` de la entrada (p. ej. sales@2026.03.1 → sales@2026.03.2);
 *  2. actualice su `fingerprint` y `fields` en CanonicalContracts (el mensaje de la prueba muestra los valores reales);
 *  3. AGREGUE `<versión nueva>: <huella>` al historial (no edite las versiones ya publicadas);
 *  4. actualice docs/data/CANONICAL_CONTRACTS.md.
 */
final class CanonicalContractsTest extends TestCase
{
    use RefreshDatabase;

    /** Entidades de V2.5 §3, en el orden de la especificación → clave del contrato. */
    private const SECTION_3 = [
        'Tenant' => 'tenant', 'Branch' => 'branch', 'SourceSystem' => 'source_system', 'Product' => 'product', 'ProductAlias' => 'product_alias',
        'Category' => 'category', 'Customer' => 'customer', 'CustomerAlias' => 'customer_alias', 'Supplier' => 'supplier',
        'SalesDocument' => 'sales', 'SalesLine' => 'sales_line', 'Order' => 'commercial_commitment', 'OrderLine' => 'commercial_commitment_item',
        'CreditNote' => 'credit_note', 'Return' => 'return', 'PriceHistory' => 'price_history', 'InventorySnapshot' => 'inventory_snapshot',
        'InventoryMovement' => 'inventory_movement', 'PurchaseOrder' => 'purchase_order', 'InboundShipment' => 'inbound_shipment',
        'SupplierLeadTime' => 'supplier_lead_time', 'CostHistory' => 'cost_history', 'UnitConversion' => 'unit_conversion',
        'CalendarEvent' => 'calendar_event', 'ExternalSignal' => 'external_signal', 'Action' => 'action', 'Outcome' => 'outcome',
        'Forecast' => 'forecast', 'Recommendation' => 'recommendation', 'ModelVersion' => 'model_version', 'DataQualityReport' => 'data_quality_report',
    ];

    private function history(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/canonical_contract_fingerprints.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_catalog_covers_each_entity_of_v25_section_3_exactly_once_and_in_order(): void
    {
        $this->assertCount(31, self::SECTION_3);
        $this->assertSame(array_values(self::SECTION_3), CanonicalContracts::entities());
        $this->assertSame(array_keys(self::SECTION_3), array_column(CanonicalContracts::CONTRACTS, 'entity'));
    }

    public function test_every_entry_has_the_documented_shape(): void
    {
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            $this->assertEqualsCanonicalizing(['entity', 'status', 'version', 'tables', 'implemented_by', 'fields', 'fingerprint', 'required', 'gaps', 'notes'], array_keys($c), $key);
            $this->assertContains($c['status'], [CanonicalContracts::IMPLEMENTED, CanonicalContracts::PARTIAL, CanonicalContracts::DESIGNED], $key);
            $this->assertSame(CanonicalContracts::REQUIRED_FIELDS, array_keys($c['required']), "{$key}: los 7 campos imprescindibles de §3");
            foreach ($c['required'] as $field => $column) {
                $this->assertTrue($column === null || $column === false || is_string($column), "{$key}.{$field}: columna, null (falta) o false (no aplica)");
            }
            $this->assertSame(array_keys($c['fields']), $c['tables'], "{$key}: fields por cada tabla");
            $this->assertSame($c['tables'] !== [], is_string($c['fingerprint']) && preg_match('/^[a-f0-9]{64}$/', $c['fingerprint']) === 1, "{$key}: huella SHA-256 solo si tiene tablas");
        }
    }

    public function test_versions_follow_the_format_and_designed_entities_stay_at_correlative_zero(): void
    {
        $seen = [];
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            $this->assertMatchesRegularExpression('/^'.$key.'@20\d{2}\.(0[1-9]|1[0-2])\.\d+$/', $c['version'], "{$key}: <entidad>@<año>.<mes>.<correlativo>");
            $correlative = (int) substr($c['version'], strrpos($c['version'], '.') + 1);
            $c['status'] === CanonicalContracts::DESIGNED ? $this->assertSame(0, $correlative, "{$key}: DISEÑADO = correlativo 0") : $this->assertGreaterThanOrEqual(1, $correlative, $key);
            $this->assertArrayNotHasKey($c['version'], $seen);
            $seen[$c['version']] = true;
            $this->assertSame($c['version'], CanonicalContracts::version($key));
        }
        foreach (CanonicalContracts::VERSIONS as $key => $version) {
            $this->assertSame($version, CanonicalContracts::CONTRACTS[$key]['version'], "la versión heredada de {$key} coincide con el contrato");
            $this->assertSame($version, CanonicalContracts::version($key));
        }
        $this->expectException(InvalidArgumentException::class);
        CanonicalContracts::version('inexistente');
    }

    public function test_status_is_derived_from_tables_required_fields_and_known_gaps(): void
    {
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            $this->assertSame(CanonicalContracts::derivedStatus($c), $c['status'], "{$key}: estado declarado = estado derivado");
        }
        $this->assertSame('DISEÑADO', CanonicalContracts::derivedStatus(['tables' => [], 'implemented_by' => null, 'required' => ['a' => null], 'gaps' => []]));
        $this->assertSame('PARCIAL', CanonicalContracts::derivedStatus(['tables' => ['t'], 'implemented_by' => null, 'required' => ['a' => 'x', 'b' => null], 'gaps' => []]));
        $this->assertSame('PARCIAL', CanonicalContracts::derivedStatus(['tables' => ['t'], 'implemented_by' => null, 'required' => ['a' => 'x', 'b' => false], 'gaps' => ['brecha']]));
        $this->assertSame('IMPLEMENTADO', CanonicalContracts::derivedStatus(['tables' => ['t'], 'implemented_by' => null, 'required' => ['a' => 'x', 'b' => false], 'gaps' => []]));
        // Los subtipos sin tabla propia no son DISEÑADOS: los implementa otra entrada.
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            if ($c['implemented_by'] !== null) {
                $this->assertSame([], $c['tables'], $key);
                $this->assertNotSame(CanonicalContracts::DESIGNED, $c['status'], $key);
                $this->assertNotEmpty(CanonicalContracts::CONTRACTS[$c['implemented_by']]['tables'], "{$key} se implementa en {$c['implemented_by']}");
            }
        }
        $this->assertSame(['inbound_shipment', 'action', 'outcome'], array_keys(array_filter(CanonicalContracts::CONTRACTS, fn ($c) => $c['status'] === 'DISEÑADO')));
    }

    public function test_declared_fields_are_exactly_the_real_columns_of_each_table(): void
    {
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            $real = ContractFingerprint::columnNames($c['tables']);
            foreach ($c['tables'] as $table) {
                $this->assertNotEmpty($real[$table], "{$key}: la tabla {$table} no existe");
                $declared = explode(' ', $c['fields'][$table]);
                $this->assertEqualsCanonicalizing($real[$table], $declared, "{$key}.{$table}: las columnas cambiaron. Suba la versión de {$c['version']} y actualice los campos.");
            }
        }
    }

    public function test_fingerprints_fix_the_real_columns_and_fail_if_they_change_without_a_version_bump(): void
    {
        $history = $this->history();
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            if ($c['tables'] === []) {
                $this->assertArrayNotHasKey($c['version'], $history, "{$key}: DISEÑADO no tiene huella");

                continue;
            }
            $actual = ContractFingerprint::hash($c['tables']);
            $this->assertSame(
                $c['fingerprint'],
                $actual,
                "{$key}: las columnas reales de [".implode(', ', $c['tables'])."] cambiaron sin subir la versión {$c['version']}. Suba el correlativo, actualice la huella (real: {$actual}) y agréguela al historial.",
            );
            $this->assertArrayHasKey($c['version'], $history, "{$key}: la versión {$c['version']} no está en el historial de huellas; agréguela.");
            $this->assertSame($history[$c['version']], $c['fingerprint'], "{$key}: la huella de {$c['version']} difiere de la ya publicada: una versión publicada no cambia de huella; suba el correlativo.");
        }
        foreach ($history as $version => $fingerprint) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint, $version);
        }
    }

    public function test_changing_a_real_column_is_detected_by_the_fingerprint_and_the_declared_fields(): void
    {
        $contract = CanonicalContracts::contract('unit_conversion');
        $this->assertSame($contract['fingerprint'], ContractFingerprint::hash($contract['tables']));

        // La migración hipotética que agrega una columna cambia la huella y la lista real de campos (DDL transaccional: se revierte al terminar).
        DB::statement('ALTER TABLE unit_conversions ADD COLUMN columna_sin_contrato text');
        $this->assertNotSame($contract['fingerprint'], ContractFingerprint::hash($contract['tables']), 'agregar una columna cambia la huella');
        $this->assertContains('columna_sin_contrato', ContractFingerprint::columnNames($contract['tables'])['unit_conversions']);
        $this->assertNotContains('columna_sin_contrato', explode(' ', $contract['fields']['unit_conversions']));

        // Cambiar solo el tipo o la nulabilidad también la cambia.
        DB::statement('ALTER TABLE unit_conversions DROP COLUMN columna_sin_contrato');
        $this->assertSame($contract['fingerprint'], ContractFingerprint::hash($contract['tables']));
        DB::statement('ALTER TABLE unit_conversions ALTER COLUMN source TYPE varchar(10)');
        $this->assertNotSame($contract['fingerprint'], ContractFingerprint::hash($contract['tables']), 'cambiar el tipo cambia la huella');
        DB::statement('ALTER TABLE unit_conversions ALTER COLUMN source TYPE varchar(30)');
        $original = DB::selectOne("SELECT is_nullable, character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'unit_conversions' AND column_name = 'source'");
        DB::statement('ALTER TABLE unit_conversions ALTER COLUMN source '.($original->is_nullable === 'YES' ? 'SET' : 'DROP').' NOT NULL');
        $this->assertNotSame($contract['fingerprint'], ContractFingerprint::hash($contract['tables']), 'cambiar la nulabilidad cambia la huella');
    }

    public function test_the_fingerprint_is_independent_of_the_order_of_the_columns_and_of_the_requested_tables(): void
    {
        $this->assertSame(ContractFingerprint::hash(['sales', 'sale_items']), ContractFingerprint::hash(['sale_items', 'sales']));
        $this->assertNotSame(ContractFingerprint::hash(['sales']), ContractFingerprint::hash(['sales', 'sale_items']));
        $this->assertSame(hash('sha256', ''), ContractFingerprint::hash([]));
        $this->assertSame([], ContractFingerprint::columns(['tabla_que_no_existe']));
    }

    public function test_the_required_field_columns_exist(): void
    {
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            $tables = $c['tables'] !== [] ? $c['tables'] : (CanonicalContracts::CONTRACTS[$c['implemented_by']]['tables'] ?? []);
            $own = array_merge(...array_values(ContractFingerprint::columnNames($tables)) ?: [[]]);
            foreach ($c['required'] as $field => $column) {
                if (! is_string($column)) {
                    continue;
                }
                if (str_contains($column, '.')) {
                    [$table, $name] = explode('.', $column, 2);
                    $this->assertContains($name, ContractFingerprint::columnNames([$table])[$table], "{$key}.{$field} → {$column}");
                } else {
                    $this->assertContains($column, $own, "{$key}.{$field} → {$column} no es una columna de [".implode(', ', $tables).']');
                }
            }
        }
    }

    public function test_the_tenant_isolation_column_exists_in_every_company_table(): void
    {
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            foreach (ContractFingerprint::columnNames($c['tables']) as $table => $columns) {
                // tenants es la raíz y las observaciones externas se aíslan por su indicador (que lleva tenant_id).
                if (in_array($table, ['tenants', 'external_indicator_observations'], true)) {
                    continue;
                }
                $this->assertContains('tenant_id', $columns, "{$key}.{$table} debe llevar tenant_id (V2.5 §3: en TODAS las entidades)");
            }
        }
    }

    public function test_schema_version_columns_fit_the_longest_contract_version(): void
    {
        $longest = max(array_map('strlen', array_column(CanonicalContracts::CONTRACTS, 'version')));
        $rows = DB::select("SELECT table_name, character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'schema_version' AND table_name IN ('sales', 'sales_document_revisions', 'inventory_snapshots')");
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual($longest, (int) $row->character_maximum_length, "{$row->table_name}.schema_version debe caber la versión más larga ({$longest} caracteres)");
        }
        // La versión que hoy se escribe en inventario (28 caracteres) cabe de verdad.
        $this->assertGreaterThan(20, strlen(CanonicalContracts::version('inventory_snapshot')));
    }

    public function test_the_widening_migration_refuses_to_roll_back_over_long_values_instead_of_truncating_them(): void
    {
        $migration = require base_path('database/migrations/2026_03_07_000100_widen_schema_version_columns.php');
        $branch = DB::table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'T', 'sector' => 'ferreteria', 'country' => 'EC', 'currency' => 'USD', 'timezone' => 'America/Guayaquil',
            'locale' => 'es-EC', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['tenant_id' => $branch, 'code' => '001', 'name' => 'M', 'created_at' => now(), 'updated_at' => now()]);
        $branchId = DB::table('branches')->where('tenant_id', $branch)->value('id');
        DB::table('products')->insert(['tenant_id' => $branch, 'sku' => 'A', 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->where('tenant_id', $branch)->value('id');
        DB::table('inventory_snapshots')->insert([
            'tenant_id' => $branch, 'branch_id' => $branchId, 'product_id' => $productId, 'snapshot_date' => '2026-05-01', 'on_hand' => 1,
            'schema_version' => CanonicalContracts::version('inventory_snapshot'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Debió abortar: hay una versión de 28 caracteres');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inventory_snapshots: 1', $e->getMessage());
        }
        $this->assertSame(40, (int) DB::selectOne("SELECT character_maximum_length AS n FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'inventory_snapshots' AND column_name = 'schema_version'")->n, 'no cambió nada');
        $this->assertSame('inventory_snapshot@2026.03.1', DB::table('inventory_snapshots')->value('schema_version'), 'ningún valor se truncó');

        // Sin valores largos sí revierte, y se puede volver a aplicar.
        DB::table('inventory_snapshots')->update(['schema_version' => null]);
        $migration->down();
        $this->assertSame(20, (int) DB::selectOne("SELECT character_maximum_length AS n FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'sales' AND column_name = 'schema_version'")->n);
        $migration->up();
        $this->assertSame(40, (int) DB::selectOne("SELECT character_maximum_length AS n FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'sales' AND column_name = 'schema_version'")->n);
    }

    public function test_the_documentation_lists_every_entity_with_its_version_and_status(): void
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../../docs/data/CANONICAL_CONTRACTS.md');
        foreach (CanonicalContracts::CONTRACTS as $key => $c) {
            // El catálogo (§3) es la tabla cuyas filas llevan la versión (`entidad@año.mes.n`); la matriz de §4 no.
            $rows = array_values(array_filter(explode("\n", $doc), fn ($line) => str_starts_with($line, "| `{$key}` |") && str_contains($line, '@20')));
            $this->assertCount(1, $rows, "{$key}: una fila en el catálogo de docs/data/CANONICAL_CONTRACTS.md");
            $this->assertStringContainsString($c['entity'], $rows[0]);
            $this->assertStringContainsString("`{$c['version']}`", $rows[0], "{$key}: versión en la documentación");
            $this->assertStringContainsString($c['status'], $rows[0], "{$key}: estado en la documentación");
        }
    }
}
