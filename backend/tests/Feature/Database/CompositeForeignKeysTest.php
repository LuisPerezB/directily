<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** FKs compuestas (tenant_id, x_id) con ON DELETE SET NULL: deben anular solo la referencia, nunca tenant_id. */
final class CompositeForeignKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_composite_set_null_foreign_key_would_null_a_not_null_column(): void
    {
        $offending = [];
        foreach (CompositeForeignKeys::compositeSetNullForeignKeys() as $fk) {
            $protected = $fk['not_null'] !== [] || in_array(CompositeForeignKeys::TENANT_COLUMN, $fk['columns'], true);
            if ($protected && ! preg_match('/ON DELETE SET NULL\s*\(/', $fk['definition'])) {
                $offending[] = "{$fk['table']}.{$fk['constraint']}";
            }
        }
        $this->assertSame([], $offending, 'FKs compuestas que anularían tenant_id o una columna NOT NULL (llame a CompositeForeignKeys::setNullOnlyReferences() en la migración)');
        foreach (CompositeForeignKeys::compositeSetNullForeignKeys() as $fk) {
            if (preg_match('/ON DELETE SET NULL\s*\(([^)]*)\)/', $fk['definition'], $m)) {
                $this->assertStringNotContainsString(CompositeForeignKeys::TENANT_COLUMN, $m[1], "{$fk['table']}.{$fk['constraint']} anula tenant_id");
            }
        }
        $this->assertNotEmpty(CompositeForeignKeys::compositeSetNullForeignKeys(), 'el esquema tiene FKs compuestas ON DELETE SET NULL');
    }

    public function test_hard_deleting_a_referenced_parent_nulls_only_the_reference_and_keeps_the_tenant(): void
    {
        $tenantId = DB::table('tenants')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'FK compuesta']);
        $sourceId = DB::table('data_sources')->insertGetId(['tenant_id' => $tenantId, 'type' => 'connector', 'name' => 'ERP']);
        $objectId = DB::table('bronze_objects')->insertGetId([
            'tenant_id' => $tenantId, 'data_source_id' => $sourceId, 'kind' => 'file_upload', 'entity' => 'sales',
            'storage_driver' => 'local', 'object_key' => 'k', 'sha256' => str_repeat('a', 64), 'size_bytes' => 1,
        ]);

        DB::table('data_sources')->where('id', $sourceId)->delete();

        $row = DB::table('bronze_objects')->where('id', $objectId)->first();
        $this->assertNotNull($row);
        $this->assertSame($tenantId, (int) $row->tenant_id);
        $this->assertNull($row->data_source_id);
    }

    public function test_deleting_a_previous_model_keeps_the_tenant_of_the_model_that_replaced_it(): void
    {
        // model_registry.tenant_id es NULLABLE (NULL = modelo de plataforma): anularlo publicaría el modelo a todas las empresas.
        $tenantId = DB::table('tenants')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'FK compuesta']);
        $model = fn (string $version, ?int $previous = null): int => DB::table('model_registry')->insertGetId([
            'tenant_id' => $tenantId, 'version' => $version, 'model_family' => 'lightgbm', 'strategy_level' => 'A',
            'previous_model_registry_id' => $previous,
        ]);
        $previousId = $model('v1');
        $currentId = $model('v2', $previousId);

        DB::table('model_registry')->where('id', $previousId)->delete();

        $row = DB::table('model_registry')->where('id', $currentId)->first();
        $this->assertNotNull($row);
        $this->assertSame($tenantId, (int) $row->tenant_id);
        $this->assertNull($row->previous_model_registry_id);
    }
}
