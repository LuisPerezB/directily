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
            if ($fk['not_null'] !== [] && ! preg_match('/ON DELETE SET NULL\s*\(/', $fk['definition'])) {
                $offending[] = "{$fk['table']}.{$fk['constraint']}";
            }
        }
        $this->assertSame([], $offending, 'FKs compuestas que anularían una columna NOT NULL (llame a CompositeForeignKeys::setNullOnlyReferences() en la migración)');
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
}
