<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Enums\TenantRole;
use App\Jobs\DataCore\SyncDataSource;
use App\Models\Customer;
use App\Models\DataSource;
use App\Models\ImportJob;
use App\Models\Sale;
use App\Models\SourceSync;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\ConnectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;
use Tests\TestCase;

/**
 * E2-25 · Recorrido SQL de solo lectura contra una base «ERP de origen» ficticia (PostgreSQL):
 * extracción → normalización → importación (mismo pipeline) → reimportación sin duplicados → corrección en origen
 * que actualiza conservando la procedencia → intento de escritura rechazado.
 */
final class SqlSourceSyncTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private ErpSourceDatabase $erp;

    private Tenant $tenant;

    private User $owner;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $erp = ErpSourceDatabase::tryConnect();
        if ($erp === null) {
            $this->markTestSkipped('No hay base de datos «ERP de origen» de pruebas (predictily_erp_source_p3).');
        }
        $this->erp = $erp;
        $this->erp->createSchema();
        $this->erp->seed();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = $this->createSource();
    }

    protected function tearDown(): void
    {
        if (isset($this->erp)) {
            $this->erp->dropSchema();
        }
        parent::tearDown();
    }

    private function sales(): \Illuminate\Database\Eloquent\Builder
    {
        return Sale::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    private function customers(): \Illuminate\Database\Eloquent\Builder
    {
        return Customer::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    private function imports(): \Illuminate\Database\Eloquent\Builder
    {
        return ImportJob::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    private function sources(): \Illuminate\Database\Eloquent\Builder
    {
        return DataSource::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    private function syncModels(): \Illuminate\Database\Eloquent\Builder
    {
        return SourceSync::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    /** Consulta modelos con empresa en contexto (el scope es fail-closed fuera de una petición). */
    private function inTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return $this->withinTenant($tenant ?? $this->tenant, $callback);
    }

    private function createSource(array $overrides = []): int
    {
        $response = $this->postJson('/api/v1/data-sources', array_merge([
            'type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'ERP ficticio (PostgreSQL)',
            'config' => ['host' => $this->erp->host, 'port' => $this->erp->port, 'database' => $this->erp->database, 'statement_timeout_seconds' => 30],
            'credentials' => ['username' => $this->erp->username, 'password' => $this->erp->password],
        ], $overrides))->assertCreated();

        return (int) $response->json('data.id');
    }

    /** @return array<string, mixed> */
    private function salesMapping(array $options = [], ?string $query = null): array
    {
        return [
            'entity' => 'sales',
            'query' => $query ?? <<<'SQL'
SELECT f.num_factura, f.tipo, f.estado, f.fecha_emision, f.cod_sucursal, l.linea AS num_linea, l.cod_item, p.descripcion, p.linea AS categoria,
       l.cantidad, l.precio_unit, l.descuento, l.iva, l.subtotal, l.costo_unit, c.ruc AS cliente, f.canal, f.modificado_en
FROM erp_facturas f
JOIN erp_factura_lineas l ON l.id_factura = f.id_factura
JOIN erp_productos p ON p.cod_item = l.cod_item
LEFT JOIN erp_clientes c ON c.id_cliente = f.id_cliente
WHERE f.modificado_en >= :since
ORDER BY f.id_factura, l.linea
SQL,
            'field_map' => [
                'document_number' => 'num_factura', 'document_type' => 'tipo', 'status' => 'estado', 'issued_at' => 'fecha_emision', 'branch_code' => 'cod_sucursal',
                'line_number' => 'num_linea', 'product_sku' => 'cod_item', 'product_name' => 'descripcion', 'category' => 'categoria', 'quantity' => 'cantidad',
                'unit_price' => 'precio_unit', 'discount' => 'descuento', 'tax' => 'iva', 'net_amount' => 'subtotal', 'unit_cost' => 'costo_unit',
                'customer_code' => 'cliente', 'channel' => 'canal', 'source_recorded_at' => 'modificado_en',
            ],
            'options' => $options,
        ];
    }

    private function approvedSalesMapping(array $options = []): int
    {
        $id = (int) $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $this->salesMapping($options))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$id}/approve")->assertOk()->assertJsonPath('data.is_active', true);

        return $id;
    }

    /** @return array<string, mixed> */
    private function sync(string $entity = 'sales', string $mode = 'incremental'): array
    {
        $response = $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => $entity, 'mode' => $mode])->assertStatus(202);

        return $this->getJson('/api/v1/syncs/'.$response->json('data.id'))->assertOk()->json('data');
    }

    public function test_connector_catalog_declares_real_verification_levels(): void
    {
        $connectors = collect($this->getJson('/api/v1/connectors')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('tested_with_test_database', $connectors['sql_postgresql']['verification']);
        $this->assertSame('requires_credentials', $connectors['sql_postgresql']['status']);
        // MySQL y SQL Server están implementados pero NO se declaran disponibles: no hay servidor en este entorno.
        $this->assertSame('implemented_untested', $connectors['sql_mysql']['status']);
        $this->assertSame('implemented_untested', $connectors['sql_sqlserver']['status']);
        $this->assertSame('planned', $connectors['generic_odbc']['status']);
        $this->assertNotContains('available', [$connectors['sql_mysql']['status'], $connectors['sql_sqlserver']['status']]);
    }

    public function test_connection_test_works_without_leaking_credentials(): void
    {
        $result = $this->postJson("/api/v1/data-sources/{$this->sourceId}/test")->assertOk();
        $result->assertJsonPath('data.ok', true);
        $this->assertNotEmpty($result->json('data.details.server_version'));
        // La cuenta de pruebas es propietaria de la base: la prueba lo advierte (la política real es una cuenta de solo lectura).
        $this->assertNotEmpty($result->json('data.details.warnings'));
        foreach ([$result->getContent(), $this->getJson('/api/v1/data-sources')->getContent(), $this->getJson("/api/v1/data-sources/{$this->sourceId}/mappings")->getContent()] as $body) {
            $this->assertStringNotContainsString($this->erp->password, $body);
        }
        $this->assertTrue($this->getJson('/api/v1/data-sources')->json('data.0.has_credentials'));
        $this->assertStringNotContainsString($this->erp->password, (string) DB::table('data_sources')->where('id', $this->sourceId)->value('credentials_encrypted'), 'credenciales cifradas en reposo');
    }

    public function test_failed_connection_reports_error_without_secrets(): void
    {
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['credentials' => ['username' => 'predictily', 'password' => 'clave-incorrecta-123']])->assertOk();
        $result = $this->postJson("/api/v1/data-sources/{$this->sourceId}/test")->assertOk();
        $result->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('clave-incorrecta-123', $result->getContent());
    }

    public function test_invalid_connection_config_is_rejected(): void
    {
        $this->postJson('/api/v1/data-sources', [
            'type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'x',
            'config' => ['host' => 'db;host=evil', 'database' => 'erp', 'password' => 'no-aqui'], 'credentials' => ['username' => 'u', 'password' => 'p'],
        ])->assertStatus(422)->assertJsonValidationErrors(['config.host', 'config.password']);
    }

    public function test_unapproved_mapping_cannot_sync(): void
    {
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertStatus(409)->assertJsonPath('code', 'mapping_not_approved');
        $id = (int) $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $this->salesMapping())->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 1)->json('data.id');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertStatus(409)->assertJsonPath('code', 'mapping_not_approved');
        // Preview sin aprobar: permitido (muestra campos canónicos, no importa nada).
        $preview = $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$id}/preview")->assertOk();
        $this->assertContains('document_number', $preview->json('data.headers'));
        $this->assertSame(0, $this->sales()->count());
    }

    public function test_full_sync_imports_through_the_import_pipeline_with_provenance(): void
    {
        $this->approvedSalesMapping();
        $sync = $this->sync(mode: 'full');

        $this->assertSame('completed', $sync['status'], json_encode($sync));
        $this->assertSame('full', $sync['mode']);
        $this->assertSame(8, $sync['rows_extracted']);
        $this->assertSame(6, $sync['stats']['import']['documents_new']);
        $this->assertSame(0, $sync['stats']['import']['rows_error']);
        $this->assertTrue($sync['stats']['staged_file_deleted']);

        // Pasó por el importador: existe un ImportJob con la fuente como procedencia.
        $job = $this->imports()->findOrFail($sync['import_job_id']);
        $this->assertSame('completed', $job->status);
        $this->assertSame($this->sourceId, $job->data_source_id);
        $this->assertSame('sales', $job->entity_type);

        $this->assertSame(6, $this->sales()->count());
        $this->assertSame(8, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(6, $this->sales()->where('data_source_id', $this->sourceId)->count(), 'procedencia data_source_id en todos los documentos');
        $this->assertSame(6, $this->sales()->whereNotNull('source_hash')->count());
        $this->assertSame(6, $this->sales()->whereNotNull('source_recorded_at')->count(), 'disponibilidad en la fuente (source_recorded_at)');
        $this->assertSame(1, $this->sales()->where('status', 'voided')->count());
        $this->assertSame(1, $this->sales()->where('document_type', 'credit_note')->count());
        $invoice = $this->sales()->where('document_number', '001-001-000000001')->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $invoice->subtotal, 0.001);
        $this->assertSame('2026', $invoice->issued_at->setTimezone('America/Guayaquil')->format('Y'));
        $this->assertSame('09:15', $invoice->issued_at->setTimezone('America/Guayaquil')->format('H:i'), 'timestamp ingenuo de la fuente interpretado en la zona de la empresa');

        // Cédula nunca en claro; consumidor final marcado como genérico.
        $this->assertSame(0, $this->customers()->where('external_code', '1710034065')->count());
        $person = $this->customers()->where('external_code', 'like', 'id:%')->firstOrFail();
        $this->assertNotNull(DB::table('customers')->where('id', $person->id)->value('tax_id_hash'));
        $this->assertTrue((bool) DB::table('customers')->where('external_code', 'CONSUMIDOR_FINAL')->where('tenant_id', $this->tenant->id)->value('is_generic'));
        $this->assertSame($this->sourceId, $this->sources()->findOrFail($this->sourceId)->id);
        $this->assertNotNull($this->sources()->findOrFail($this->sourceId)->last_synced_at);
    }

    public function test_reimport_creates_no_duplicates_and_source_correction_updates_keeping_provenance(): void
    {
        $this->approvedSalesMapping();
        $first = $this->sync(mode: 'full');
        $originalImport = $this->sales()->where('document_number', '001-001-000000001')->value('import_job_id');
        $originalRecorded = $this->sales()->where('document_number', '001-001-000000001')->value('source_recorded_at');

        // Reimportación completa: nada nuevo, nada duplicado.
        $again = $this->sync(mode: 'full');
        $this->assertSame('completed', $again['status']);
        $this->assertSame(0, $again['stats']['import']['documents_new']);
        $this->assertSame(6, $again['stats']['import']['documents_unchanged']);
        $this->assertSame(0, $again['stats']['import']['documents_updated']);
        $this->assertSame(6, $this->sales()->count());
        $this->assertSame(8, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());

        // Incremental sin cambios: solo reextrae la ventana (documento más reciente + solape) y no cambia nada.
        $incremental = $this->sync();
        $this->assertSame('incremental', $incremental['mode']);
        $this->assertNotNull($incremental['since']);
        $this->assertSame(1, $incremental['rows_extracted'], 'solo el último documento entra en la ventana');
        $this->assertSame(6, $this->sales()->count());

        // Corrección en origen: cambia la cantidad de una línea y su fecha de modificación.
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $corrected = $this->sync();
        $this->assertSame('completed', $corrected['status']);
        $this->assertSame(1, $corrected['stats']['import']['documents_updated']);
        $this->assertSame(0, $corrected['stats']['import']['documents_new']);
        $this->assertSame(6, $this->sales()->count(), 'sigue habiendo 6 documentos');
        $this->assertSame(8, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count(), 'las líneas se reemplazan, no se acumulan');

        $invoice = $this->sales()->where('document_number', '001-001-000000001')->firstOrFail();
        $this->assertEqualsWithDelta(12.0, (float) DB::table('sale_items')->where('sale_id', $invoice->id)->where('line_number', 1)->value('quantity'), 0.0001);
        $this->assertSame($this->sourceId, $invoice->data_source_id, 'la corrección conserva la procedencia (fuente)');
        $this->assertNotSame($originalImport, $invoice->import_job_id, 'se registra la importación que aplicó la corrección');
        $this->assertTrue(CarbonImmutable::parse((string) $invoice->source_recorded_at)->greaterThan(CarbonImmutable::parse((string) $originalRecorded)), 'source_recorded_at avanza con la corrección');
        $this->assertNotSame($first['import_job_id'], $corrected['import_job_id']);
    }

    public function test_write_attempts_through_a_mapping_are_rejected(): void
    {
        $before = (int) $this->erp->scalar('SELECT COUNT(*) FROM erp_facturas');
        $attempts = [
            "UPDATE erp_facturas SET estado = 'ANULADA'",
            "DELETE FROM erp_facturas WHERE num_factura = '001-001-000000001'",
            'SELECT 1; DROP TABLE erp_facturas',
            "INSERT INTO erp_clientes (ruc, nombre) VALUES ('x', 'y')",
            'WITH d AS (DELETE FROM erp_factura_lineas RETURNING *) SELECT * FROM d',
            'SELECT * INTO nueva_tabla FROM erp_facturas',
            'SELECT pg_sleep(60)',
            'SELECT * FROM pg_shadow',
            "SELECT * FROM erp_facturas FOR UPDATE",
            "SELECT 'a\\' ; DROP TABLE erp_facturas; --'",
            'SELECT 1 -- comentario'."\n".'; TRUNCATE erp_facturas',
            'CREATE TABLE x AS SELECT 1',
            'CALL algun_procedimiento()',
        ];
        foreach ($attempts as $sql) {
            $mapping = $this->salesMapping([], $sql);
            $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $mapping)
                ->assertStatus(422)->assertJsonPath('code', 'sql_query_rejected');
        }
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame($before, (int) $this->erp->scalar('SELECT COUNT(*) FROM erp_facturas'), 'la base de origen no cambió');
        $this->assertSame(2, (int) $this->erp->scalar('SELECT COUNT(*) FROM erp_clientes'));
    }

    public function test_database_level_read_only_transaction_blocks_writes_even_if_the_guard_were_bypassed(): void
    {
        $source = $this->sources()->findOrFail($this->sourceId);
        $connection = $this->inTenant(fn () => app(ConnectorRegistry::class)->get('sql_postgresql')->open($source));
        try {
            $this->expectException(PDOException::class);
            $this->expectExceptionMessageMatches('/read-only transaction/i');
            $connection->pdo()->exec("INSERT INTO erp_clientes (ruc, nombre) VALUES ('1', 'escritura')");
        } finally {
            $connection->close();
            $this->assertSame(2, (int) $this->erp->scalar('SELECT COUNT(*) FROM erp_clientes'));
        }
    }

    public function test_tampered_stored_query_is_revalidated_before_execution(): void
    {
        $id = $this->approvedSalesMapping();
        // Alguien altera la consulta guardada en la base de datos tras la aprobación.
        DB::table('source_mappings')->where('id', $id)->update(['query' => "DELETE FROM erp_facturas WHERE :since IS NOT NULL"]);
        $sync = $this->sync(mode: 'full');
        $this->assertSame('failed', $sync['status']);
        $this->assertStringContainsString('SELECT', (string) $sync['error_message']);
        $this->assertSame(6, (int) $this->erp->scalar('SELECT COUNT(*) FROM erp_facturas'));
    }

    public function test_invalid_rows_leave_the_sync_for_review_unless_the_mapping_allows_skipping(): void
    {
        $this->erp->pdo->exec("UPDATE erp_factura_lineas SET precio_unit = -5 WHERE cod_item = 'VAR-12' AND id_factura = (SELECT id_factura FROM erp_facturas WHERE num_factura = '001-001-000000005')");
        $this->approvedSalesMapping();
        $review = $this->sync(mode: 'full');
        $this->assertSame('needs_review', $review['status']);
        $this->assertSame(0, $this->sales()->count(), 'sin importar nada hasta revisar');
        $job = $this->imports()->findOrFail($review['import_job_id']);
        $this->assertSame('validated', $job->status);
        $this->getJson("/api/v1/imports/{$job->id}/errors?severity=error")->assertOk()->assertJsonFragment(['error_code' => 'negative_price']);
        $this->assertNull($this->syncModels()->findOrFail($review['id'])->watermark);
        $this->assertSame(0, DB::table('data_coverage')->where('tenant_id', $this->tenant->id)->where('complete_through', '!=', null)->count(), 'una sincronización con errores no declara cobertura');

        // Nueva versión del mapeo que permite importar solo las filas válidas.
        $second = (int) $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $this->salesMapping(['import' => ['skip_invalid_rows' => true]]))->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$second}/approve")->assertOk();
        $this->assertSame(1, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->where('is_active', true)->count(), 'solo una versión activa');
        $skipped = $this->sync(mode: 'full');
        $this->assertSame('completed', $skipped['status']);
        $this->assertSame(5, $this->sales()->count(), 'el documento con error no se escribe; los demás sí');
        $this->assertSame(1, $skipped['stats']['import']['rows_error']);
    }

    public function test_sync_declares_source_coverage_with_documented_rule_and_user_declaration_wins(): void
    {
        $this->approvedSalesMapping();
        $this->sync(mode: 'full');
        $today = CarbonImmutable::now('America/Guayaquil');
        $coverage = $this->getJson('/api/v1/data-coverage')->assertOk();
        $sales = collect($coverage->json('data'))->firstWhere('entity', 'sales');
        $this->assertSame($today->subDay()->toDateString(), $sales['complete_through']);
        $this->assertSame($today->subDays(20)->toDateString(), $sales['complete_from']);
        $this->assertSame('inferred', $sales['basis']);
        $this->assertSame($this->sourceId, $sales['data_source_id']);
        $this->assertSame('min_over_sources', $coverage->json('effective.sales.rule'));

        // La declaración del usuario manda sobre la inferencia.
        $declared = $today->subDays(3)->toDateString();
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_from' => $today->subDays(25)->toDateString(), 'complete_through' => $declared])->assertOk()
            ->assertJsonPath('effective.complete_through', $declared)->assertJsonPath('effective.rule', 'declared_by_user');
        // Y la sincronización siguiente no la pisa.
        $this->sync(mode: 'full');
        $this->assertSame($declared, $this->getJson('/api/v1/data-coverage')->json('effective.sales.complete_through'));
        // No se puede declarar el futuro.
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => $today->addDays(2)->toDateString()])->assertStatus(422);
    }

    public function test_other_tenant_cannot_see_or_run_sources_mappings_or_syncs(): void
    {
        $mapping = $this->approvedSalesMapping();
        $sync = $this->sync(mode: 'full');
        [$otherTenant, $otherUser] = $this->createTenantWithUser();
        $this->createBranch($otherTenant, '001', 'Matriz B');
        $this->actingAsTenantUser($otherUser, $otherTenant);

        $this->assertSame([], $this->getJson('/api/v1/data-sources')->assertOk()->json('data'));
        $this->getJson("/api/v1/data-sources/{$this->sourceId}/mappings")->assertNotFound();
        $this->getJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$mapping}")->assertNotFound();
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$mapping}/approve")->assertNotFound();
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertNotFound();
        $this->getJson('/api/v1/syncs/'.$sync['id'])->assertNotFound();
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/test")->assertNotFound();
        $this->assertSame(0, Sale::withoutTenantScope()->where('tenant_id', $otherTenant->id)->count());

        // Un trabajo con la empresa B y la sincronización de A no la toca (tenant explícito + scope fail-closed).
        $queued = $this->withinTenant($this->tenant, function () {
            $s = new SourceSync;
            $s->forceFill(['data_source_id' => $this->sourceId, 'entity' => 'sales', 'mode' => 'full', 'status' => 'queued'])->save();

            return $s;
        });
        // Equivale al middleware SetTenantContext del trabajo: contexto de B, sincronización de A.
        $this->withinTenant($otherTenant, fn () => (new SyncDataSource($otherTenant->id, $queued->id))->handle(app(\App\Services\DataCore\Sync\SourceSyncService::class)));
        $this->assertSame('queued', DB::table('source_syncs')->where('id', $queued->id)->value('status'));
    }

    public function test_viewer_cannot_manage_mappings_or_run_syncs(): void
    {
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->getJson("/api/v1/data-sources/{$this->sourceId}/mappings")->assertForbidden();
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $this->salesMapping())->assertForbidden();
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertForbidden();
    }

    public function test_scheduled_command_enqueues_incremental_syncs_only_for_scheduled_sources(): void
    {
        $this->approvedSalesMapping();
        $this->artisan('predictily:sync-sources')->assertSuccessful();
        $this->assertSame(0, $this->syncModels()->count(), 'la fuente es manual: no se programa');

        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['host' => $this->erp->host, 'port' => $this->erp->port, 'database' => $this->erp->database, 'sync_schedule' => 'hourly']])->assertOk();
        $this->artisan('predictily:sync-sources')->assertSuccessful();
        $first = $this->syncModels()->firstOrFail();
        $this->assertSame('schedule', $first->triggered_by);
        $this->assertSame('full', $first->mode, 'la primera vez no hay marca de agua: carga completa');
        $this->assertSame('completed', $first->status);
        $this->assertSame(6, $this->sales()->count());

        $this->artisan('predictily:sync-sources')->assertSuccessful();
        $this->assertSame(1, $this->syncModels()->count(), 'no repite dentro del intervalo');
        $this->artisan('predictily:sync-sources', ['--force' => true])->assertSuccessful();
        $second = $this->syncModels()->latest('id')->firstOrFail();
        $this->assertSame('incremental', $second->mode);
        $this->assertSame(6, $this->sales()->count());

        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['host' => $this->erp->host, 'database' => $this->erp->database, 'sync_schedule' => 'cada_minuto']])->assertStatus(422);
    }

    public function test_only_one_sync_per_source_and_entity_can_be_active(): void
    {
        $this->approvedSalesMapping();
        $mapping = $this->inTenant(fn () => \App\Models\SourceMapping::query()->firstOrFail());
        $this->inTenant(function () use ($mapping): void {
            $busy = new SourceSync;
            $busy->forceFill(['data_source_id' => $this->sourceId, 'source_mapping_id' => $mapping->id, 'entity' => 'sales', 'mode' => 'full', 'status' => 'running'])->save();
        });
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales'])->assertStatus(409)->assertJsonPath('code', 'sync_in_progress');
    }
}
