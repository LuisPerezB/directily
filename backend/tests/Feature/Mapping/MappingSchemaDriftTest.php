<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping;

use App\Models\AuditLog;
use App\Models\SourceSync;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Mapping\Support\ErpMappingTestCase;

/** Deriva de esquema: una plantilla cuyo esquema de origen cambió no se aplica, se invalida y exige revisión; se informa con el formato de W2. */
final class MappingSchemaDriftTest extends ErpMappingTestCase
{
    /** Propuesta de ventas aprobada (y no aplicada). */
    private function approvedSales(): int
    {
        $id = $this->proposeIds(['sales'])['sales'];
        $this->approve($id)->assertOk();

        return $id;
    }

    private function statusOf(int $id): string
    {
        return (string) DB::table('mapping_templates')->where('id', $id)->value('status');
    }

    public function test_a_renamed_column_blocks_the_apply_invalidates_the_company_template_and_asks_for_review(): void
    {
        $id = $this->approvedSales();
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN cantidad TO cantidad_vendida');

        $response = $this->apply($id)->assertStatus(409)->assertJsonPath('code', 'schema_drift_review_required')->assertJsonPath('context.template_status', 'proposed');
        $drift = $response->json('context.drift');
        $this->assertFalse($drift['matches']);
        $this->assertSame(['comercial.det_venta.cantidad'], $drift['removed']);
        $this->assertContains('comercial.det_venta.cantidad_vendida', $drift['added']);
        $this->assertNotSame($drift['expected_fingerprint'], $drift['actual_fingerprint']);

        $row = DB::table('mapping_templates')->where('id', $id)->first();
        $this->assertSame('proposed', $row->status, 'la plantilla vuelve a revisión');
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);
        $this->assertNotNull($row->invalidated_at);
        $this->assertSame(['comercial.det_venta.cantidad'], json_decode((string) $row->drift, true)['removed']);
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count(), 'no se creó ningún mapeo');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertStatus(409)->assertJsonPath('code', 'mapping_not_approved');
        $audit = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_invalidated')->firstOrFail();
        $this->assertSame($id, (int) $audit->auditable_id);
        $this->assertSame(1, $audit->metadata['removed']);

        // Aprobarla de nuevo sin corregir no sirve: la aprobación compara con el esquema actual de su fuente (C-6) y la deriva persiste; ni aprobar ni aplicar la ocultan.
        $this->approve($id)->assertStatus(409)->assertJsonPath('code', 'schema_drift_review_required')->assertJsonPath('context.drift.removed.0', 'comercial.det_venta.cantidad');
        $this->apply($id)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        $this->assertSame('proposed', $this->statusOf($id));

        // Revisión correcta: una propuesta nueva sobre el esquema actual (versión 2), aprobada y aplicada; importa.
        $v2 = $this->propose(['sales'])->assertCreated()->assertJsonPath('data.proposals.0.template.version', 2)->assertJsonPath('data.proposals.0.template.field_map.quantity', 't0.cantidad_vendida');
        $v2Id = (int) $v2->json('data.proposals.0.template.id');
        $this->approve($v2Id)->assertOk();
        $this->apply($v2Id)->assertCreated();
        $sync = $this->sync('sales');
        $this->assertSame('completed', $sync['status'], json_encode($sync['error_message'] ?? $sync['stats']));
        $this->assertSame(5, $this->sales()->count());
        $first = $this->sales()->where('document_number', '001-001-000000001')->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) DB::table('sale_items')->where('sale_id', $first->id)->where('line_number', 1)->value('quantity'), 0.0001, 'la cantidad viene de la columna renombrada');
    }

    public function test_approval_compares_the_template_with_the_current_schema_of_its_source(): void
    {
        $id = $this->proposeIds(['sales'])['sales'];
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta ALTER COLUMN precio_unitario TYPE varchar(20) USING precio_unitario::text');

        $response = $this->approve($id)->assertStatus(409)->assertJsonPath('code', 'schema_drift_review_required');
        $this->assertSame(['comercial.det_venta.precio_unitario: decimal→text'], $response->json('context.drift.retyped'));
        $this->assertSame('proposed', $this->statusOf($id), 'no se aprobó');
        $this->assertNull(DB::table('mapping_templates')->where('id', $id)->value('approved_by'));

        // Sin deriva se aprueba; con la fuente borrada la comprobación no aplica y se compara al aplicar.
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta ALTER COLUMN precio_unitario TYPE numeric USING precio_unitario::numeric');
        $this->approve($id)->assertOk();
    }

    public function test_drift_blind_spots_are_the_documented_ones(): void
    {
        $id = $this->approvedSales();
        // Punto ciego 1: una columna renombrada Y otra nueva con el mismo nombre y la misma clase de tipo => misma huella (no se detecta el cambio de significado).
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN cantidad TO cantidad_anterior; ALTER TABLE comercial.det_venta ADD COLUMN cantidad numeric DEFAULT 0');
        $this->assertTrue($this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk()->json('data.matches'), 'limitación documentada en AUTO_MAPPING.md §9');
        // Estricto con la clase de tipo: entero ↔ decimal cuenta como deriva aunque sean compatibles.
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta ALTER COLUMN nro_linea TYPE numeric');
        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();
        $this->assertFalse($report->json('data.matches'));
        $this->assertSame(['comercial.det_venta.nro_linea: integer→decimal'], $report->json('data.retyped'));
    }

    public function test_the_drift_endpoint_reports_without_applying_or_invalidating(): void
    {
        $id = $this->approvedSales();
        $ok = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();
        $this->assertTrue($ok->json('data.matches'));
        $this->assertSame($ok->json('data.expected_fingerprint'), $ok->json('data.actual_fingerprint'));

        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta ALTER COLUMN precio_unitario TYPE varchar(20) USING precio_unitario::text');
        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertFalse($report->json('data.matches'));
        $this->assertSame(['comercial.det_venta.precio_unitario: decimal→text'], $report->json('data.retyped'));
        $this->assertSame([], $report->json('data.removed'));
        $this->assertSame('approved', $this->statusOf($id), 'consultar la deriva no invalida la plantilla');
        $this->assertSame(0, AuditLog::query()->where('action', 'mapping.template_invalidated')->count());

        // Aplicar sí la invalida.
        $this->apply($id)->assertStatus(409)->assertJsonPath('code', 'schema_drift_review_required');
        $this->assertSame('proposed', $this->statusOf($id));
    }

    public function test_new_columns_and_changes_inside_the_same_type_class_are_informative_and_do_not_break_the_template(): void
    {
        $id = $this->approvedSales();
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta ADD COLUMN lote varchar(20); ALTER TABLE comercial.det_venta ALTER COLUMN cantidad TYPE double precision; CREATE TABLE comercial.tabla_nueva (x int)');

        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();
        $this->assertTrue($report->json('data.matches'));
        $this->assertSame(['comercial.det_venta.lote'], $report->json('data.added'));
        $this->apply($id)->assertCreated();
        $this->assertSame('approved', $this->statusOf($id));
    }

    public function test_a_dropped_table_is_reported_as_removed(): void
    {
        $id = $this->approvedSales();
        $this->erp->pdo->exec('ALTER TABLE comercial.cab_venta ALTER COLUMN id_cliente DROP NOT NULL; ALTER TABLE comercial.cab_venta DROP CONSTRAINT cab_venta_id_cliente_fkey; DROP TABLE comercial.cliente CASCADE');
        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();

        $this->assertFalse($report->json('data.matches'));
        $this->assertSame(['comercial.cliente'], $report->json('data.tables_removed'));
        $this->assertContains('comercial.cliente.provincia', $report->json('data.removed'));
    }

    public function test_a_public_template_is_never_modified_by_drift_but_the_attempt_is_audited(): void
    {
        $own = $this->approvedSales();
        $template = $this->getJson("/api/v1/mapping-templates/{$own}")->json('data');
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin->refresh());
        $publicId = (int) $this->postJson('/api/v1/admin/mapping-templates', ['system_kind' => 'erp', 'vendor' => 'ERP Ficticio', 'entity' => 'sales', 'source_spec' => $template['source_spec'],
            'field_map' => $template['field_map'], 'value_maps' => $template['value_maps']])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$publicId}/approve")->assertOk();
        $this->actingAsTenantUser($this->owner, $this->tenant);

        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN precio_unitario TO precio_lista');
        $before = (array) DB::table('mapping_templates')->where('id', $publicId)->first();
        $this->apply($publicId)->assertStatus(409)->assertJsonPath('code', 'schema_drift_review_required')->assertJsonPath('context.template_status', 'approved');
        $this->assertEquals($before, (array) DB::table('mapping_templates')->where('id', $publicId)->first(), 'la pública no cambia');
        $audit = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_drift_detected')->firstOrFail();
        $this->assertSame('platform', $audit->metadata['scope']);
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_w2_schema_drift_findings_of_the_latest_sync_are_included_when_they_exist(): void
    {
        $id = $this->approvedSales();
        $this->apply($id)->assertCreated();
        $sync = $this->sync('sales');
        $this->assertSame('completed', $sync['status']);
        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();
        $this->assertNull($report->json('data.w2_sync_drift'), 'sin hallazgo de W2 no hay nada que reutilizar');

        // Hallazgo de deriva de W2 (SchemaDrift/IngestionQuality) en la última sincronización de la fuente y entidad.
        $this->withinTenant($this->tenant, function () use ($sync): void {
            $model = SourceSync::query()->findOrFail($sync['id']);
            $stats = (array) $model->stats;
            $stats['schema'] = ['fingerprint' => str_repeat('a', 64), 'version' => 2, 'drift' => ['previous_fingerprint' => str_repeat('b', 64), 'previous_version' => 1, 'added' => ['columna_nueva'], 'removed' => ['vieja'], 'retyped' => []]];
            $model->forceFill(['stats' => $stats])->save();
        });

        $report = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$id}/drift")->assertOk();
        $this->assertSame($sync['id'], $report->json('data.w2_sync_drift.source_sync_id'));
        $this->assertSame(['columna_nueva'], $report->json('data.w2_sync_drift.added'));
        $this->assertSame(['vieja'], $report->json('data.w2_sync_drift.removed'));
    }

    public function test_a_template_from_another_source_cannot_be_applied_and_unreachable_sources_report_source_unavailable(): void
    {
        $id = $this->approvedSales();
        $second = $this->createSource($this->tenant);
        $this->apply($id, $second)->assertStatus(409)->assertJsonPath('code', 'template_source_mismatch');

        $mysql = (int) $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => 'sql_mysql', 'name' => 'MySQL inexistente',
            'config' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 'erp', 'connect_timeout_seconds' => 1], 'credentials' => ['username' => 'lector', 'password' => 'sin-servidor']])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/mapping-templates/{$id}/fork")->assertStatus(422);   // propia, no pública
        $this->postJson("/api/v1/data-sources/{$mysql}/schema/discover")->assertStatus(422)->assertJsonPath('code', 'source_unavailable');
        $this->propose(['sales'], [], $mysql)->assertStatus(422)->assertJsonPath('code', 'source_unavailable');
    }
}
