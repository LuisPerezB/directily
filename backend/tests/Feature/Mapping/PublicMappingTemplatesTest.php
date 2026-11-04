<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping;

use App\Models\AuditLog;
use App\Models\MappingTemplate;
use App\Models\User;
use App\Services\DataCore\Mapping\Templates\MappingTemplateException;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Mapping\Support\ErpMappingTestCase;

/** Plantillas públicas de la plataforma (solo superadministrador) frente a las de la empresa: lectura, bifurcación y aplicación. */
final class PublicMappingTemplatesTest extends ErpMappingTestCase
{
    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        return $admin->refresh();
    }

    /** Carga útil de una plantilla pública para el ERP ficticio: la escribe un experto de la plataforma (aquí, a partir de una propuesta de prueba). */
    private function publicPayload(string $entity = 'sales', string $vendor = 'ERP Ficticio'): array
    {
        $template = $this->getJson('/api/v1/mapping-templates/'.$this->proposeIds([$entity])[$entity])->assertOk()->json('data');

        return ['system_kind' => 'erp', 'vendor' => $vendor, 'entity' => $entity, 'source_spec' => $template['source_spec'], 'field_map' => $template['field_map'], 'value_maps' => $template['value_maps']];
    }

    private function asPlatform(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->platformAdmin());
    }

    private function asTenantOwner(): void
    {
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    public function test_only_platform_admins_manage_public_templates_and_they_never_see_company_templates(): void
    {
        $payload = $this->publicPayload();
        $tenantTemplate = (int) DB::table('mapping_templates')->where('tenant_id', $this->tenant->id)->value('id');

        $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertForbidden();   // un propietario de empresa no es superadministrador
        $this->getJson('/api/v1/admin/mapping-templates')->assertForbidden();

        $this->asPlatform();
        $created = $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->assertJsonPath('data.scope', 'platform')->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.version', 1)->assertJsonPath('data.vendor', 'erp ficticio')->assertJsonPath('data.complete', true);
        $id = (int) $created->json('data.id');
        $this->assertNull(DB::table('mapping_templates')->where('id', $id)->value('tenant_id'));
        $this->assertNull(DB::table('mapping_templates')->where('id', $id)->value('data_source_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $created->json('data.schema_fingerprint'));

        $this->getJson('/api/v1/admin/mapping-templates')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/mapping-templates/{$tenantTemplate}")->assertNotFound();   // las de una empresa no existen para la plataforma
        $this->patchJson("/api/v1/admin/mapping-templates/{$tenantTemplate}", ['field_map' => ['channel' => null]])->assertNotFound();
        $this->postJson("/api/v1/admin/mapping-templates/{$tenantTemplate}/approve")->assertNotFound();
        $this->postJson("/api/v1/admin/mapping-templates/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        // Auditoría sin empresa.
        $audit = AuditLog::query()->where('action', 'mapping.template_approved')->whereNull('tenant_id')->firstOrFail();
        $this->assertSame($id, (int) $audit->auditable_id);
        $this->assertSame('platform', $audit->metadata['scope']);
        $this->assertNotNull(AuditLog::query()->where('action', 'mapping.public_template_created')->whereNull('tenant_id')->first());
        // Una vez aprobada no se edita (se crea otra versión); editar antes de aprobar sí se permite.
        $this->patchJson("/api/v1/admin/mapping-templates/{$id}", ['field_map' => ['channel' => null]])->assertStatus(409)->assertJsonPath('code', 'template_not_editable');
    }

    public function test_public_template_validation_rejects_bad_structures_and_personal_data_columns(): void
    {
        $payload = $this->publicPayload();
        $this->asPlatform();

        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['vendor' => '']))->assertStatus(422)->assertJsonValidationErrors(['vendor']);
        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['entity' => 'customers']))->assertStatus(422)->assertJsonValidationErrors(['entity']);
        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['source_spec' => ['dialect' => 'oracle'] + $payload['source_spec']]))->assertStatus(422);
        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['field_map' => ['no_existe' => 't0.cantidad'] + $payload['field_map']]))->assertStatus(422)->assertJsonValidationErrors(['field_map.no_existe']);
        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['field_map' => ['product_name' => 't3.razon_social'] + $payload['field_map']]))->assertStatus(422)->assertJsonValidationErrors(['field_map.product_name']);
        $this->postJson('/api/v1/admin/mapping-templates', array_merge($payload, ['value_maps' => ['status' => ['XYZ' => 'inventado']]]))->assertStatus(422)->assertJsonValidationErrors(['value_maps.status']);
        $incomplete = $payload;
        unset($incomplete['field_map']['quantity']);
        $id = (int) $this->postJson('/api/v1/admin/mapping-templates', $incomplete)->assertCreated()->assertJsonPath('data.complete', false)->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'template_incomplete');
        $this->assertSame(0, DB::table('mapping_templates')->whereNull('tenant_id')->where('status', 'approved')->count());
    }

    public function test_companies_see_approved_public_templates_read_only_and_can_apply_or_fork_them(): void
    {
        $payload = $this->publicPayload();
        $this->asPlatform();
        $publicId = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$publicId}/approve")->assertOk();

        // Otra empresa también la ve, y solo a ella (más las propias).
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($ownerB, $tenantB);
        $listB = $this->getJson('/api/v1/mapping-templates?scope=platform')->assertOk();
        $this->assertSame([$publicId], collect($listB->json('data'))->pluck('id')->all());
        $this->assertTrue($listB->json('data.0.read_only'));
        $this->assertSame([], $this->getJson('/api/v1/mapping-templates?scope=tenant')->json('data'));

        // Solo lectura: toda escritura sobre una pública es 403 con código estable.
        $this->asTenantOwner();
        foreach ([['PATCH', "/api/v1/mapping-templates/{$publicId}", ['field_map' => ['channel' => null]]], ['POST', "/api/v1/mapping-templates/{$publicId}/approve", null],
            ['POST', "/api/v1/mapping-templates/{$publicId}/retire", null], ['POST', "/api/v1/mapping-templates/{$publicId}/revise", []]] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body ?? [])->assertForbidden()->assertJsonPath('code', 'public_template_read_only');
        }
        $this->assertSame('approved', DB::table('mapping_templates')->where('id', $publicId)->value('status'));

        // Aplicarla directo a una fuente cuyo esquema coincide con su huella.
        $applied = $this->apply($publicId)->assertCreated();
        $this->assertSame('sales', $applied->json('data.source_mapping.entity'));
        $audit = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_applied')->firstOrFail();
        $this->assertSame('platform', $audit->metadata['template_scope']);

        // Bifurcarla: copia propia en la empresa, propuesta, con origen en la pública.
        $fork = $this->postJson("/api/v1/mapping-templates/{$publicId}/fork", ['data_source_id' => $this->sourceId])->assertCreated();
        $forkId = (int) $fork->json('data.id');
        $fork->assertJsonPath('data.scope', 'tenant')->assertJsonPath('data.read_only', false)->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.forked_from_id', $publicId)
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.data_source_id', $this->sourceId);
        $this->assertSame($this->tenant->id, (int) DB::table('mapping_templates')->where('id', $forkId)->value('tenant_id'));
        $this->patchJson("/api/v1/mapping-templates/{$forkId}", ['field_map' => ['channel' => null]])->assertOk();   // la copia sí se adapta
        $this->assertArrayHasKey('channel', (array) json_decode((string) DB::table('mapping_templates')->where('id', $publicId)->value('field_map'), true), 'la pública no cambió');
        $this->assertNotNull(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('action', 'mapping.template_forked')->first());

        // Una propuesta o retirada no se bifurca; una pública tampoco se bifurca dos veces como v1.
        $second = $this->postJson("/api/v1/mapping-templates/{$publicId}/fork")->assertCreated();
        $this->assertSame(2, $second->json('data.version'));
        $own = $this->proposeIds(['products'])['products'];
        $this->postJson("/api/v1/mapping-templates/{$own}/fork")->assertStatus(422)->assertJsonPath('code', 'template_not_public');
    }

    public function test_companies_do_not_see_who_on_the_platform_created_or_approved_a_public_template(): void
    {
        $payload = $this->publicPayload();
        $admin = $this->platformAdmin();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $publicId = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$publicId}/approve")->assertOk()->assertJsonPath('data.approved_by.id', $admin->id);
        $this->getJson("/api/v1/admin/mapping-templates/{$publicId}")->assertOk()->assertJsonPath('data.created_by', $admin->id)->assertJsonPath('data.approved_by.id', $admin->id);

        $this->asTenantOwner();
        foreach (["/api/v1/mapping-templates/{$publicId}", '/api/v1/mapping-templates?scope=platform'] as $uri) {
            $response = $this->getJson($uri)->assertOk();
            $item = $response->json('data.id') !== null ? $response->json('data') : $response->json('data.0');
            $this->assertSame('approved', $item['status']);
            $this->assertNull($item['approved_by'], $uri);
            $this->assertNull($item['created_by'], $uri);
            $this->assertNotNull($item['approved_at'], 'la fecha de aprobación sí es pública');
            $this->assertStringNotContainsString($admin->email, $response->getContent());
            $this->assertStringNotContainsString((string) $admin->name, $response->getContent());
        }
        $fork = $this->postJson("/api/v1/mapping-templates/{$publicId}/fork")->assertCreated();
        $this->assertSame($this->owner->id, $fork->json('data.created_by'), 'la copia es de la empresa: su autor sí se ve');
    }

    public function test_a_public_template_is_approved_by_another_platform_admin_when_there_is_one(): void
    {
        $payload = $this->publicPayload();
        $first = $this->platformAdmin();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($first);
        $id = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$id}/approve")->assertOk();   // única persona de la plataforma: puede aprobar lo suyo
        $second = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');

        $other = $this->platformAdmin();
        $this->postJson("/api/v1/admin/mapping-templates/{$second}/approve")->assertForbidden()->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/admin/mapping-templates/{$second}/approve")->assertOk()->assertJsonPath('data.approved_by.id', $other->id);
    }

    public function test_the_service_itself_refuses_to_cross_the_platform_and_company_boundaries(): void
    {
        // Defensa en profundidad: las rutas ya cargan solo lo que corresponde, pero el servicio no confía en quien lo llama.
        $service = app(MappingTemplateService::class);
        $companyTemplate = MappingTemplate::withoutTenantScope()->findOrFail($this->proposeIds(['sales'])['sales']);
        $admin = $this->platformAdmin();
        foreach (['update' => [[]], 'approve' => [], 'retire' => [], 'revise' => [[]]] as $method => $extra) {
            try {
                $service->{$method}($companyTemplate, ...[...$extra, $admin, true]);
                $this->fail("{$method}: la plataforma no debe tocar la plantilla de una empresa");
            } catch (MappingTemplateException $e) {
                $this->assertSame('not_found', $e->errorCode, $method);
                $this->assertSame(404, $e->status);
            }
        }

        [$otherTenant] = $this->createTenantWithUser();
        $otherId = DB::table('mapping_templates')->insertGetId(['tenant_id' => $otherTenant->id, 'system_kind' => 'erp', 'entity' => 'sales', 'version' => 1, 'field_map' => '{}', 'value_maps' => '{}',
            'confidence' => '{}', 'source_spec' => '{}', 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = MappingTemplate::withoutTenantScope()->findOrFail($otherId);
        foreach (['update' => [[]], 'approve' => [], 'retire' => [], 'revise' => [[]]] as $method => $extra) {
            try {
                $this->withinTenant($this->tenant, fn () => $service->{$method}($foreign, ...[...$extra, $this->owner, false]));
                $this->fail("{$method}: una empresa no debe tocar la plantilla de otra");
            } catch (MappingTemplateException $e) {
                $this->assertSame('not_found', $e->errorCode, $method);
            }
        }
        $this->assertSame('proposed', DB::table('mapping_templates')->where('id', $otherId)->value('status'));
        $this->assertSame(0, DB::table('mapping_templates')->where('system_kind', 'erp')->where('version', '>', 1)->count(), 'ninguna versión nueva se coló');
    }

    public function test_a_proposed_or_retired_public_template_is_not_applicable_or_forkable(): void
    {
        $payload = $this->publicPayload();
        $this->asPlatform();
        $publicId = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');
        $this->asTenantOwner();

        $this->apply($publicId)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        $this->postJson("/api/v1/mapping-templates/{$publicId}/fork")->assertStatus(409)->assertJsonPath('code', 'template_not_approved');

        $this->asPlatform();
        $this->postJson("/api/v1/admin/mapping-templates/{$publicId}/approve")->assertOk();
        $this->postJson("/api/v1/admin/mapping-templates/{$publicId}/retire")->assertOk()->assertJsonPath('data.status', 'retired');
        $this->asTenantOwner();
        $this->apply($publicId)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
    }

    public function test_approving_a_newer_public_version_retires_the_previous_one_and_the_company_template_is_unaffected(): void
    {
        $payload = $this->publicPayload();
        $own = (int) DB::table('mapping_templates')->where('tenant_id', $this->tenant->id)->value('id');
        $this->approve($own)->assertOk();

        $this->asPlatform();
        $v1 = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$v1}/approve")->assertOk();
        $v2 = (int) $this->postJson('/api/v1/admin/mapping-templates', $payload)->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->postJson("/api/v1/admin/mapping-templates/{$v2}/approve")->assertOk();

        $this->assertSame('retired', DB::table('mapping_templates')->where('id', $v1)->value('status'));
        $this->assertSame('approved', DB::table('mapping_templates')->where('id', $v2)->value('status'));
        $this->assertSame('approved', DB::table('mapping_templates')->where('id', $own)->value('status'), 'la plantilla de la empresa es de otra clave (tenant_id): no se retira');
    }
}
