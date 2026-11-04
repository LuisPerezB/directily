<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\MappingTemplate;
use App\Models\Tenant;
use App\Services\DataCore\Mapping\Templates\SourceSpec;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Mapping\Support\ErpMappingTestCase;

/** Quién puede descubrir, proponer, aprobar y aplicar; auditoría de cada decisión humana; aislamiento entre empresas. */
final class MappingTemplateAccessTest extends ErpMappingTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: ?array}> */
    private function endpoints(int $templateId): array
    {
        $s = $this->sourceId;

        return [
            'descubrir' => ['POST', "/api/v1/data-sources/{$s}/schema/discover", null],
            'proponer' => ['POST', "/api/v1/data-sources/{$s}/mapping-proposals", ['entities' => ['sales']]],
            'listar' => ['GET', '/api/v1/mapping-templates', null],
            'ver' => ['GET', "/api/v1/mapping-templates/{$templateId}", null],
            'corregir' => ['PATCH', "/api/v1/mapping-templates/{$templateId}", ['field_map' => ['channel' => null]]],
            'revisar' => ['POST', "/api/v1/mapping-templates/{$templateId}/revise", []],
            'deriva' => ['POST', "/api/v1/data-sources/{$s}/mapping-templates/{$templateId}/drift", null],
            'aprobar' => ['POST', "/api/v1/mapping-templates/{$templateId}/approve", null],
            'retirar' => ['POST', "/api/v1/mapping-templates/{$templateId}/retire", null],
            'aplicar' => ['POST', "/api/v1/data-sources/{$s}/mapping-templates/{$templateId}/apply", null],
        ];
    }

    public function test_viewers_and_analysts_can_do_nothing_and_anonymous_users_are_rejected(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        foreach ([TenantRole::Viewer, TenantRole::Analyst] as $role) {
            $user = $this->addUserToTenant($this->tenant, $role);
            $this->actingAsTenantUser($user, $this->tenant);
            foreach ($this->endpoints($templateId) as $name => [$method, $uri, $body]) {
                $this->json($method, $uri, $body ?? [])->assertForbidden()->assertJsonPath('code', 'forbidden');
            }
        }
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $templateId)->value('status'));
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant-ID', $this->tenant->uuid);
        foreach ($this->endpoints($templateId) as $name => [$method, $uri, $body]) {
            $this->json($method, $uri, $body ?? [])->assertUnauthorized();
        }
    }

    public function test_administrators_discover_propose_and_approve_but_the_approval_is_a_separate_ability(): void
    {
        $this->assertTrue(TenantRole::Admin->can('datasources.manage') && TenantRole::Admin->can('settings.manage'));
        $this->assertFalse(TenantRole::Analyst->can('datasources.manage') || TenantRole::Analyst->can('settings.manage'));

        // El propietario propone; la persona administradora aprueba (segregación de funciones).
        $templateId = $this->proposeIds(['sales'])['sales'];
        $admin = $this->addUserToTenant($this->tenant, TenantRole::Admin);
        $this->actingAsTenantUser($admin, $this->tenant);
        $this->approve($templateId)->assertOk()->assertJsonPath('data.approved_by.id', $admin->id);
    }

    public function test_the_person_who_proposed_cannot_approve_when_someone_else_can_but_a_single_administrator_can(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        $other = $this->addUserToTenant($this->tenant, TenantRole::Admin);

        // Hay otra persona con settings.manage: quien propuso no aprueba.
        $this->approve($templateId)->assertForbidden()->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $templateId)->value('status'));
        // Un analista o un usuario de consulta no cuentan como «otra persona que puede aprobar».
        DB::table('tenant_users')->where('tenant_id', $this->tenant->id)->where('user_id', $other->id)->update(['role' => TenantRole::Analyst->value]);
        $this->approve($templateId)->assertOk()->assertJsonPath('data.approved_by.id', $this->owner->id);

        // Con otra persona, la aprobación de ella sí vale; la bitácora conserva ambas.
        $second = $this->proposeIds(['products'])['products'];
        DB::table('tenant_users')->where('tenant_id', $this->tenant->id)->where('user_id', $other->id)->update(['role' => TenantRole::Admin->value]);
        $this->actingAsTenantUser($other, $this->tenant);
        $this->approve($second)->assertOk()->assertJsonPath('data.approved_by.id', $other->id);
        $this->assertSame($this->owner->id, (int) DB::table('mapping_templates')->where('id', $second)->value('created_by'));
    }

    public function test_a_token_without_settings_manage_can_propose_but_cannot_approve_or_apply(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        $this->app['auth']->forgetGuards();
        $narrow = $this->owner->createToken('mapper', ['datasources.manage'])->plainTextToken;
        $headers = ['X-Tenant-ID' => $this->tenant->uuid, 'Authorization' => 'Bearer '.$narrow];

        $this->withHeaders($headers)->postJson("/api/v1/data-sources/{$this->sourceId}/schema/discover")->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/mapping-templates/{$templateId}/approve")->assertForbidden()->assertJsonPath('required_ability', 'settings.manage');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->postJson("/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$templateId}/apply")->assertForbidden();
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $templateId)->value('status'));

        $this->app['auth']->forgetGuards();
        $wide = $this->owner->createToken('admin', ['datasources.manage', 'settings.manage'])->plainTextToken;
        $this->withHeaders(['X-Tenant-ID' => $this->tenant->uuid, 'Authorization' => 'Bearer '.$wide])->postJson("/api/v1/mapping-templates/{$templateId}/approve")->assertOk();
    }

    public function test_only_a_proposed_template_can_be_approved_and_a_retired_one_never_comes_back(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        $this->approve($templateId)->assertOk();
        $this->approve($templateId)->assertStatus(409)->assertJsonPath('code', 'template_not_proposed');   // ya aprobada
        $this->postJson("/api/v1/mapping-templates/{$templateId}/retire")->assertOk();
        $this->approve($templateId)->assertStatus(409)->assertJsonPath('code', 'template_not_proposed');   // retirada
        $this->postJson("/api/v1/mapping-templates/{$templateId}/retire")->assertStatus(409)->assertJsonPath('code', 'template_already_retired');
        $this->assertSame('retired', DB::table('mapping_templates')->where('id', $templateId)->value('status'));
        $this->assertSame(1, AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_approved')->count(), 'una sola aprobación en la bitácora');
    }

    public function test_the_read_only_query_policy_is_checked_again_at_approval(): void
    {
        // «sp_cantidad» es un nombre de objeto de sistema para SqlQueryGuard: la propuesta lo mapea, pero la consulta generada no cumple la política de solo lectura.
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN cantidad TO sp_cantidad');
        $proposal = $this->propose(['sales'])->assertCreated()->json('data.proposals.0');
        $this->assertTrue($proposal['complete']);
        $this->assertNull($proposal['template']['query']);
        $this->assertStringContainsString('solo lectura', (string) $proposal['template']['query_error']);

        $id = (int) $proposal['template']['id'];
        $this->approve($id)->assertStatus(422)->assertJsonPath('code', 'sql_query_rejected');
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $id)->value('status'));
        $this->apply($id)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_apply_checks_the_read_only_policy_again_on_the_query_it_generates(): void
    {
        $id = $this->proposeIds(['sales'])['sales'];
        $this->approve($id)->assertOk();
        // Una plantilla alterada después de aprobarla (y con el esquema de origen cambiado a la par, para que la huella coincida) ya no genera una consulta aceptable.
        $this->erp->pdo->exec('ALTER TABLE comercial.det_venta RENAME COLUMN cantidad TO sp_cantidad');
        $row = DB::table('mapping_templates')->where('id', $id)->first();
        $spec = json_decode((string) $row->source_spec, true);
        $map = json_decode((string) $row->field_map, true);
        foreach ($spec['tables'] as $i => $table) {
            if ($table['name'] === 'det_venta') {
                $spec['tables'][$i]['columns'] = array_combine(array_map(fn ($c) => $c === 'cantidad' ? 'sp_cantidad' : $c, array_keys($table['columns'])), array_values($table['columns']));
                $map['quantity'] = $table['alias'].'.sp_cantidad';
            }
        }
        DB::table('mapping_templates')->where('id', $id)->update(['source_spec' => json_encode($spec), 'field_map' => json_encode($map),
            'schema_fingerprint' => SourceSpec::fingerprint('pgsql', SourceSpec::referenced($spec, $map))]);

        $this->apply($id)->assertStatus(422)->assertJsonPath('code', 'sql_query_rejected')->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'La consulta generada no cumple la política de solo lectura'));
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_constant_cannot_stand_in_for_a_required_field_or_an_identity_key(): void
    {
        $id = $this->proposeIds(['sales'])['sales'];
        foreach ([['const' => null], ['const' => ''], ['const' => '   '], ['const' => 'FACTURA-1']] as $const) {
            $this->patchJson("/api/v1/mapping-templates/{$id}", ['field_map' => ['document_number' => $const]])->assertStatus(422)->assertJsonValidationErrors(['field_map.document_number']);
        }
        foreach (['product_sku', 'customer_code'] as $identity) {
            $this->patchJson("/api/v1/mapping-templates/{$id}", ['field_map' => [$identity => ['const' => 'X']]])->assertStatus(422)->assertJsonValidationErrors(["field_map.{$identity}"]);
        }
        $this->patchJson("/api/v1/mapping-templates/{$id}", ['field_map' => ['channel' => ['const' => '']]])->assertStatus(422)->assertJsonValidationErrors(['field_map.channel']);
        $this->patchJson("/api/v1/mapping-templates/{$id}", ['field_map' => ['currency' => ['const' => 'USD'], 'discount' => ['const' => 0]]])->assertOk()
            ->assertJsonPath('data.field_map.currency.const', 'USD')->assertJsonPath('data.field_map.discount.const', 0);
        // Un mapa heredado con una constante nula en un campo obligatorio se cuenta como campo sin mapear: no se puede aprobar.
        $field = json_decode((string) DB::table('mapping_templates')->where('id', $id)->value('field_map'), true);
        $field['document_number'] = ['const' => null];
        DB::table('mapping_templates')->where('id', $id)->update(['field_map' => json_encode($field)]);
        $this->getJson("/api/v1/mapping-templates/{$id}")->assertJsonPath('data.complete', false)->assertJsonPath('data.missing_required.0', 'document_number');
        $this->approve($id)->assertStatus(422)->assertJsonPath('code', 'template_incomplete');
    }

    public function test_with_mfa_enabled_the_sensitive_mapping_actions_need_a_fresh_second_factor(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        // W6-A: datasources.manage y settings.manage son habilidades sensibles; quien tiene MFA activo necesita un segundo factor FRESCO (403 mfa_step_up_required).
        DB::table('users')->where('id', $this->owner->id)->update(['mfa_enabled_at' => now(), 'mfa_secret_encrypted' => encrypt('secreto-de-prueba')]);
        $this->actingAsTenantUser($this->owner->refresh(), $this->tenant);

        $this->approve($templateId)->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/schema/discover")->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $templateId)->value('status'));
    }

    public function test_every_human_decision_is_audited_with_who_what_and_the_schema_fingerprint(): void
    {
        $admin = $this->addUserToTenant($this->tenant, TenantRole::Admin);
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/schema/discover")->assertOk();
        $templateId = $this->proposeIds(['sales'])['sales'];
        $this->patchJson("/api/v1/mapping-templates/{$templateId}", ['field_map' => ['channel' => null]])->assertOk();
        $fingerprint = $this->getJson("/api/v1/mapping-templates/{$templateId}")->json('data.schema_fingerprint');

        // Quien propone (propietario) no aprueba aquí: aprueba otra persona con la habilidad.
        $this->actingAsTenantUser($admin, $this->tenant);
        $this->approve($templateId)->assertOk();
        $this->apply($templateId)->assertCreated();

        $audit = fn (string $action) => AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', $action)->orderBy('id')->get();
        $this->assertSame($this->owner->id, $audit('mapping.discovery_run')->first()->user_id);
        $this->assertSame($this->owner->id, $audit('mapping.template_proposed')->first()->user_id);
        $this->assertSame($this->owner->id, $audit('mapping.template_edited')->first()->user_id);
        $this->assertSame(['channel'], $audit('mapping.template_edited')->first()->metadata['fields_changed']);
        $approved = $audit('mapping.template_approved')->first();
        $this->assertSame($admin->id, $approved->user_id);
        $this->assertSame($templateId, (int) $approved->auditable_id);
        $this->assertSame(substr($fingerprint, 0, 16), $approved->metadata['schema_fingerprint_prefix'], 'la bitácora guarda un prefijo: PiiMasker enmascara las cadenas hexadecimales largas');
        $this->assertSame('tenant', $approved->metadata['scope']);
        $applied = $audit('mapping.template_applied')->first();
        $this->assertSame($admin->id, $applied->user_id);
        $this->assertSame($templateId, $applied->metadata['template_id']);
        $this->assertNotEmpty($audit('datasource.mapping_approved'), 'además queda la aprobación del mapeo SQL del pipeline existente');
        $row = DB::table('mapping_templates')->where('id', $templateId)->first();
        $this->assertSame($admin->id, (int) $row->approved_by);
        $this->assertSame($this->owner->id, (int) $row->created_by);
    }

    public function test_proposing_never_approves_or_syncs_anything(): void
    {
        $ids = $this->proposeIds(['products', 'sales', 'inventory', 'suppliers', 'lead_times']);

        $rows = DB::table('mapping_templates')->whereIn('id', array_values($ids))->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame('proposed', $row->status);
            $this->assertNull($row->approved_by);
            $this->assertNull($row->approved_at);
        }
        $this->assertSame(0, DB::table('source_mappings')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, DB::table('source_syncs')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_other_companies_cannot_see_or_touch_templates_sources_or_proposals(): void
    {
        $templateId = $this->proposeIds(['sales'])['sales'];
        $this->approve($templateId)->assertOk();

        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($ownerB, $tenantB);
        $sourceB = $this->createSource($tenantB);

        $this->getJson('/api/v1/mapping-templates')->assertOk()->assertJsonCount(0, 'data');
        foreach ([
            ['GET', "/api/v1/mapping-templates/{$templateId}", null], ['PATCH', "/api/v1/mapping-templates/{$templateId}", ['field_map' => ['channel' => null]]],
            ['POST', "/api/v1/mapping-templates/{$templateId}/revise", []], ['POST', "/api/v1/mapping-templates/{$templateId}/fork", []],
            ['POST', "/api/v1/mapping-templates/{$templateId}/approve", null], ['POST', "/api/v1/mapping-templates/{$templateId}/retire", null],
            ['POST', "/api/v1/data-sources/{$sourceB}/mapping-templates/{$templateId}/apply", null], ['POST', "/api/v1/data-sources/{$sourceB}/mapping-templates/{$templateId}/drift", null],
            // La fuente de la empresa A tampoco se descubre ni se propone desde la B.
            ['POST', "/api/v1/data-sources/{$this->sourceId}/schema/discover", null], ['POST', "/api/v1/data-sources/{$this->sourceId}/mapping-proposals", ['entities' => ['sales']]],
            ['POST', "/api/v1/data-sources/{$this->sourceId}/mapping-templates/{$templateId}/apply", null],
        ] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body ?? [])->assertNotFound();
        }
        $this->assertSame('approved', DB::table('mapping_templates')->where('id', $templateId)->value('status'), 'la plantilla de A no cambió');

        // Las propuestas de B quedan en B; A no las ve.
        $own = (int) $this->propose(['sales'], [], $sourceB)->assertCreated()->json('data.proposals.0.template.id');
        $this->assertNotSame($templateId, $own);
        $this->assertSame($tenantB->id, (int) DB::table('mapping_templates')->where('id', $own)->value('tenant_id'));
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $ids = collect($this->getJson('/api/v1/mapping-templates')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$templateId], $ids);
        $this->getJson("/api/v1/mapping-templates/{$own}")->assertNotFound();
        // Auditoría por empresa.
        $this->assertSame(0, AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_proposed')->where('auditable_id', $own)->count());
    }

    public function test_the_model_scope_is_fail_closed_and_a_tenant_row_cannot_be_created_for_another_company(): void
    {
        $this->app->make(TenantContext::class)->forget();
        try {
            MappingTemplate::query()->count();
            $this->fail('Sin empresa activa la consulta debe fallar (fail-closed).');
        } catch (TenantNotResolvedException) {
            $this->assertSame(0, MappingTemplate::platform()->count(), 'las tareas de plataforma usan platform()');
        }
        $other = Tenant::query()->create(['name' => 'Otra', 'sector' => 'ferreteria']);
        $this->expectException(TenantNotResolvedException::class);
        $this->withinTenant($this->tenant, function () use ($other): void {
            $t = new MappingTemplate;
            $t->forceFill(['tenant_id' => $other->id, 'system_kind' => 'erp', 'entity' => 'sales', 'version' => 1, 'field_map' => [], 'status' => 'proposed'])->save();
        });
    }

    public function test_database_constraints_keep_versions_unique_and_one_approved_per_key(): void
    {
        $row = fn (array $o = []) => array_merge(['tenant_id' => $this->tenant->id, 'system_kind' => 'erp', 'vendor' => null, 'entity' => 'sales', 'version' => 1,
            'field_map' => '{}', 'value_maps' => '{}', 'confidence' => '{}', 'status' => 'proposed', 'source_spec' => '{}', 'created_at' => now(), 'updated_at' => now()], $o);
        DB::table('mapping_templates')->insert($row());
        $must = function (callable $insert, string $message): void {
            try {
                DB::transaction($insert);
                $this->fail($message);
            } catch (QueryException $e) {
                $this->assertStringContainsString('violates', $e->getMessage(), $message);
            }
        };
        $must(fn () => DB::table('mapping_templates')->insert($row()), 'versión duplicada de una plantilla de empresa sin proveedor (NULL no era único en el contrato)');
        DB::table('mapping_templates')->insert($row(['tenant_id' => null, 'vendor' => 'odoo']));
        $must(fn () => DB::table('mapping_templates')->insert($row(['tenant_id' => null, 'vendor' => 'odoo'])), 'versión duplicada de una plantilla pública');
        $must(fn () => DB::table('mapping_templates')->insert($row(['version' => 2, 'status' => 'archived'])), 'estado fuera del vocabulario');
        DB::table('mapping_templates')->where('tenant_id', $this->tenant->id)->update(['status' => 'approved']);
        $must(fn () => DB::table('mapping_templates')->insert($row(['version' => 2, 'status' => 'approved'])), 'dos aprobadas para la misma clave');
        $must(fn () => DB::table('mapping_templates')->insert($row(['version' => 3, 'tenant_id' => null, 'vendor' => 'x', 'data_source_id' => $this->sourceId])), 'una plantilla pública no apunta a una fuente');
    }

    public function test_discovery_and_proposals_need_a_read_only_sql_source(): void
    {
        $csv = (int) $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => 'generic_csv', 'name' => 'Archivos'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/data-sources/{$csv}/schema/discover")->assertStatus(422)->assertJsonValidationErrors(['data_source']);
        $this->propose(['sales'], [], $csv)->assertStatus(422);
        $this->propose(['customers'])->assertStatus(422)->assertJsonValidationErrors(['entities.0']);
        $this->propose(['sales', 'sales'])->assertStatus(422);
    }

    public function test_events_log_only_counts_never_names_or_values(): void
    {
        $messages = [];
        Event::listen(MessageLogged::class, function ($event) use (&$messages): void {
            $messages[] = $event->message.' '.json_encode($event->context);
        });
        $this->propose(['sales', 'products'])->assertCreated();

        $mapping = array_values(array_filter($messages, fn (string $m) => str_contains($m, 'mapping.')));
        $this->assertNotEmpty($mapping);
        foreach ($messages as $message) {
            foreach (['1710034065', 'Cliente Ficticio Uno', 'uno@ficticio.test', 'det_venta', 'cantidad', 'FC'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $message, 'el registro no incluye valores ni nombres del origen');
            }
        }
    }
}
