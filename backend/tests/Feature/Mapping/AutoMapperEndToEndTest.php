<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Mapping\Support\ErpMappingTestCase;

/**
 * Recorrido de punta a punta del Auto Mapper contra un ERP PostgreSQL ficticio con columnas en español:
 * descubrimiento → propuesta → aprobación humana → aplicar → sincronización por el pipeline de importación EXISTENTE → filas canónicas esperadas.
 */
final class AutoMapperEndToEndTest extends ErpMappingTestCase
{
    public function test_discovery_returns_metadata_only(): void
    {
        $response = $this->postJson("/api/v1/data-sources/{$this->sourceId}/schema/discover?include_columns=1")->assertOk();
        $data = $response->json('data');

        $this->assertSame('pgsql', $data['dialect']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['schema_fingerprint']);
        $this->assertFalse($data['truncated']);
        $tables = collect($data['tables'])->keyBy(fn ($t) => $t['schema'].'.'.$t['name']);
        foreach (['comercial.cab_venta', 'comercial.det_venta', 'comercial.cliente', 'inventario.articulo', 'inventario.existencia_bodega', 'compras.proveedor', 'compras.plazo_entrega', 'public.bitacora_usuario'] as $name) {
            $this->assertTrue($tables->has($name), $name);
        }
        $this->assertFalse($tables->keys()->contains(fn ($k) => str_starts_with($k, 'pg_catalog.') || str_starts_with($k, 'information_schema.')), 'sin catálogos del sistema');
        $detail = $tables['comercial.det_venta'];
        $this->assertSame(['id_venta', 'nro_linea'], $detail['primary_key']);
        $header = collect($detail['foreign_keys'])->firstWhere('ref_table', 'cab_venta');
        $this->assertSame(['id_venta'], $header['columns']);
        $this->assertSame(['id_venta'], $header['ref_columns']);
        $this->assertSame('articulo', collect($detail['foreign_keys'])->firstWhere('columns', ['cod_articulo'])['ref_table']);
        $column = collect($detail['columns'])->firstWhere('name', 'cantidad');
        $this->assertSame('decimal', $column['type_class']);
        $this->assertSame('numeric', $column['data_type']);
        // Metadatos, no datos: ningún valor sembrado sale en la respuesta.
        foreach (['1710034065', 'Cliente Ficticio Uno', 'uno@ficticio.test', '0999999991', 'Calle Falsa 123', '001-001-000000001'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        // Sin columnas por omisión (respuesta liviana).
        $light = $this->postJson("/api/v1/data-sources/{$this->sourceId}/schema/discover")->assertOk()->json('data.tables.0');
        $this->assertArrayNotHasKey('columns', $light);
    }

    public function test_the_full_flow_imports_the_expected_canonical_rows(): void
    {
        $ids = $this->proposeIds(['products', 'sales', 'inventory', 'suppliers', 'lead_times'], ['vendor' => 'ERP Ficticio']);

        // Nada se puede sincronizar sin aprobar, ni siquiera con una propuesta completa.
        foreach (['sales', 'products'] as $entity) {
            $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => $entity])->assertStatus(409)->assertJsonPath('code', 'mapping_not_approved');
        }
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$ids['sales']}/apply")->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count(), 'proponer no crea mapeos');

        $detail = $this->getJson("/api/v1/mapping-templates/{$ids['sales']}")->assertOk()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.complete', true)->assertJsonPath('data.vendor', 'erp ficticio');
        $this->assertStringContainsString('FROM "comercial"."det_venta" t0', (string) $detail->json('data.query'));
        $this->assertSame('heuristic_score', $detail->json('data.confidence.kind'));

        foreach ($ids as $entity => $templateId) {
            $this->approve($templateId)->assertOk()->assertJsonPath('data.status', 'approved');
            $applied = $this->apply($templateId)->assertCreated()->assertJsonPath('data.source_mapping.entity', $entity)->assertJsonPath('data.source_mapping.is_active', true);
            $this->assertStringContainsString('SELECT', (string) $applied->json('data.source_mapping.query'));
        }

        // Orden: productos, ventas, inventario, proveedores, plazos.
        $products = $this->sync('products');
        $this->assertSame('completed', $products['status'], json_encode($products));
        $this->assertSame(3, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
        $cement = DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'CEM-50')->first();
        $this->assertSame('Cemento 50kg', $cement->name);
        $this->assertSame('Ficticia', $cement->brand);

        $sales = $this->sync('sales');
        $this->assertSame('completed', $sales['status'], json_encode($sales));
        $this->assertSame(7, $sales['rows_extracted']);
        $this->assertSame(0, $sales['stats']['import']['rows_error']);
        $this->assertSame(5, $this->sales()->count(), '5 documentos');
        $this->assertSame(7, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count(), '7 líneas');
        $this->assertSame(4, $this->sales()->where('document_type', 'invoice')->count(), 'FC → factura por el mapeo de valores propuesto');
        $this->assertSame(1, $this->sales()->where('document_type', 'credit_note')->count(), 'NCR → nota de crédito');
        $this->assertSame(1, $this->sales()->where('status', 'voided')->count(), 'ANU → anulada');
        $this->assertSame(4, $this->sales()->where('status', 'completed')->count(), 'EMI → completada');
        $this->assertSame(5, $this->sales()->where('data_source_id', $this->sourceId)->count(), 'procedencia en todos los documentos');
        $this->assertSame(5, $this->sales()->whereNotNull('source_recorded_at')->count());
        $invoice = $this->sales()->where('document_number', '001-001-000000001')->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $invoice->subtotal, 0.001);
        $this->assertSame('09:15', $invoice->issued_at->setTimezone('America/Guayaquil')->format('H:i'));
        $item = DB::table('sale_items')->where('sale_id', $invoice->id)->where('line_number', 1)->first();
        $this->assertEqualsWithDelta(10.0, (float) $item->quantity, 0.0001);
        $this->assertEqualsWithDelta(8.5, (float) $item->unit_price, 0.0001);
        $this->assertEqualsWithDelta(6.9, (float) $item->unit_cost, 0.0001);
        // El mapeo de valores se aplicó y quedó registrado en las transformaciones de la carga.
        $hits = collect($sales['stats']['transformations']['value_maps']['document_type'] ?? [])->keyBy('from');
        $this->assertSame('invoice', $hits['FC']['to']);
        $this->assertSame(6, $hits['FC']['count'], 'se cuenta por fila de origen: 4 documentos FC con 6 líneas');
        // Datos personales: el cliente se identifica por su código interno; nombre, correo, teléfono y RUC nunca llegaron a Predictily.
        $this->assertSame(0, DB::table('customers')->where('tenant_id', $this->tenant->id)->whereIn('external_code', ['1710034065', 'id:1710034065'])->count());
        $this->assertSame(['1', '2'], DB::table('customers')->where('tenant_id', $this->tenant->id)->orderBy('external_code')->pluck('external_code')->all());
        $this->assertSame(['MAYORISTA', 'MINORISTA'], DB::table('customers')->join('customer_segments', 'customer_segments.id', '=', 'customers.segment_id')->where('customers.tenant_id', $this->tenant->id)
            ->orderBy('customer_segments.name')->pluck('customer_segments.name')->map(fn ($n) => strtoupper($n))->all());
        $dump = json_encode(DB::table('sales')->where('tenant_id', $this->tenant->id)->get()).json_encode(DB::table('customers')->where('tenant_id', $this->tenant->id)->get());
        foreach (['Cliente Ficticio Uno', 'uno@ficticio.test', '0999999991', 'Calle Falsa 123', '1710034065'] as $personal) {
            $this->assertStringNotContainsString($personal, $dump);
        }

        $inventory = $this->sync('inventory');
        $this->assertSame('completed', $inventory['status'], json_encode($inventory));
        $this->assertSame(4, DB::table('inventory_snapshots')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEqualsWithDelta(95.0, (float) DB::table('inventory_snapshots')->where('tenant_id', $this->tenant->id)->orderByDesc('snapshot_date')->orderBy('id')->value('on_hand'), 0.001);

        $suppliers = $this->sync('suppliers');
        $this->assertSame('completed', $suppliers['status'], json_encode($suppliers));
        $this->assertSame(2, DB::table('suppliers')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(45, (int) DB::table('suppliers')->where('tenant_id', $this->tenant->id)->where('code', 'PRV-02')->value('default_lead_time_days'));

        $leadTimes = $this->sync('lead_times');
        $this->assertSame('completed', $leadTimes['status'], json_encode($leadTimes));
        $this->assertSame(2, DB::table('supplier_lead_times')->where('tenant_id', $this->tenant->id)->count());

        // Reaplicar la misma plantilla no duplica versiones del mapeo; resincronizar no duplica filas.
        $before = DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->where('entity', 'sales')->count();
        $this->apply($ids['sales'])->assertOk()->assertJsonPath('data.reused', true);
        $this->assertSame($before, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->where('entity', 'sales')->count());
        $again = $this->sync('sales');
        $this->assertSame(0, $again['stats']['import']['documents_new']);
        $this->assertSame(5, $this->sales()->count());
    }

    public function test_a_document_type_the_mapper_could_not_resolve_is_flagged_and_the_sync_waits_for_review(): void
    {
        $this->erp->dropSchema();
        $this->erp->createSchema();
        $this->erp->seed(withQuotation: true);   // incluye una cotización «COT» que NO es una venta
        $response = $this->propose(['sales'])->assertCreated();
        $proposal = $response->json('data.proposals.0');

        $this->assertTrue($proposal['complete']);
        $this->assertNotEmpty(array_filter($proposal['warnings'], fn ($w) => str_contains($w, 'COT') && str_contains($w, 'no_es_documento_de_venta')), json_encode($proposal['warnings']));
        $this->assertArrayNotHasKey('COT', (array) $proposal['template']['value_maps']['document_type'], 'una cotización nunca se mapea a factura');
        $unresolved = $proposal['template']['confidence']['value_maps']['document_type']['unresolved'];
        $this->assertSame('COT', $unresolved[0]['value']);

        $templateId = (int) $proposal['template']['id'];
        $this->approve($templateId)->assertOk();
        $this->apply($templateId)->assertCreated();
        $sync = $this->sync('sales');
        $this->assertSame('needs_review', $sync['status'], 'el documento no reconocido bloquea la carga hasta revisar: el importador no lo cuenta como venta');
        $this->assertSame(0, $this->sales()->count());
    }

    public function test_proposals_are_idempotent_and_a_changed_schema_creates_the_next_version(): void
    {
        $first = $this->propose(['sales'])->assertCreated();
        $id = $first->json('data.proposals.0.template.id');
        $this->assertTrue($first->json('data.proposals.0.created'));
        $this->assertSame(1, $first->json('data.proposals.0.template.version'));

        $again = $this->propose(['sales'])->assertCreated();
        $this->assertFalse($again->json('data.proposals.0.created'), 'el mismo esquema no crea otra versión');
        $this->assertSame($id, $again->json('data.proposals.0.template.id'));

        // Una columna nueva usada por la plantilla (renombrada) cambia el contenido: versión 2.
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN cantidad TO cantidad_vendida');
        $changed = $this->propose(['sales'])->assertCreated();
        $this->assertTrue($changed->json('data.proposals.0.created'));
        $this->assertSame(2, $changed->json('data.proposals.0.template.version'));
        $this->assertSame('t0.cantidad_vendida', $changed->json('data.proposals.0.template.field_map.quantity'));
        $this->assertNotSame($first->json('data.proposals.0.template.schema_fingerprint'), $changed->json('data.proposals.0.template.schema_fingerprint'));
    }

    public function test_incomplete_proposals_cannot_be_approved_and_name_the_missing_required_fields(): void
    {
        $this->erp->pdo->exec('ALTER TABLE inventario.existencia_bodega DROP CONSTRAINT existencia_bodega_pkey; ALTER TABLE inventario.existencia_bodega DROP COLUMN fecha_corte');
        $response = $this->propose(['inventory'])->assertCreated();
        $proposal = $response->json('data.proposals.0');

        $this->assertFalse($proposal['complete']);
        $this->assertSame(['snapshot_date'], $proposal['missing_required']);
        $templateId = (int) $proposal['template']['id'];
        $this->approve($templateId)->assertStatus(422)->assertJsonPath('code', 'template_incomplete')->assertJsonPath('context.missing_required.0', 'snapshot_date');
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $templateId)->value('status'));
        $this->apply($templateId)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');

        // El Auto Mapper no inventa una fecha de corte: sin columna de fecha la propuesta queda incompleta, no es aprobable y no crea ningún mapeo.
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_human_corrections_are_validated_and_recorded(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        // Quitar un campo obligatorio: queda incompleta.
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['unit_price' => null]])->assertOk()->assertJsonPath('data.complete', false)->assertJsonPath('data.missing_required.0', 'unit_price');
        // Volver a mapearlo a mano.
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['unit_price' => 't0.precio_unitario']])->assertOk()->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.confidence.fields.unit_price.band', 'human');
        // Una constante y un mapeo de valores propios.
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['currency' => ['const' => 'USD']], 'value_maps' => ['status' => ['EMI' => 'completed', 'ANU' => 'voided', 'RZD' => 'voided']]])
            ->assertOk()->assertJsonPath('data.field_map.currency.const', 'USD')->assertJsonPath('data.value_maps.status.RZD', 'voided');
        // Rechazos: campo desconocido, columna inexistente, tipo incompatible, destino fuera del vocabulario y columna con datos personales.
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['no_existe' => 't0.cantidad']])->assertStatus(422)->assertJsonValidationErrors(['field_map.no_existe']);
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['channel' => 't0.no_existe']])->assertStatus(422)->assertJsonValidationErrors(['field_map.channel']);
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['issued_at' => 't0.cantidad']])->assertStatus(422)->assertJsonValidationErrors(['field_map.issued_at']);
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['value_maps' => ['status' => ['XYZ' => 'valor inventado']]])->assertStatus(422)->assertJsonValidationErrors(['value_maps.status']);
        $detail = $this->getJson("/api/v1/mapping-templates/{$templateId}")->json('data');
        $cliente = collect($detail['source_spec']['tables'])->firstWhere('name', 'cliente');
        $this->assertNotNull($cliente);
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['product_name' => $cliente['alias'].'.razon_social']])->assertStatus(422)->assertJsonValidationErrors(['field_map.product_name']);
        $this->assertSame('descripcion', explode('.', (string) $this->getJson("/api/v1/mapping-templates/{$templateId}")->json('data.field_map.product_name'))[1]);

        // Una plantilla aprobada ya no se edita: hay que crear otra versión.
        $this->approve($templateId)->assertOk();
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['channel' => null]])->assertStatus(409)->assertJsonPath('code', 'template_not_editable');
        $v2 = $this->postJson("/api/v1/mapping-templates/{$templateId}/revise", ['field_map' => ['channel' => null]])->assertCreated();
        $this->assertSame(2, $v2->json('data.version'));
        $this->assertSame('proposed', $v2->json('data.status'));
        $this->assertArrayNotHasKey('channel', (array) $v2->json('data.field_map'));
        $this->getJson("/api/v1/mapping-templates/{$templateId}")->assertJsonPath('data.status', 'approved');
        // Aprobar la versión 2 retira la 1 (a lo sumo una aprobada por clave).
        $this->approve((int) $v2->json('data.id'))->assertOk();
        $this->getJson("/api/v1/mapping-templates/{$templateId}")->assertJsonPath('data.status', 'retired');
    }
}
