<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * API de Bronze (ADR-V25-24): metadatos de los objetos de la empresa activa (`data.view`) y descarga del contenido solo para
 * propietario/administrador, con verificación SHA-256 y auditoría. Nunca se expone la clave del almacén.
 */
final class BronzeApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['bronze.disk' => 'local', 'bronze.retention_days' => 0]);
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
    }

    private function store(Tenant $tenant, string $contents, array $provenance = [], string $entity = 'sales', string $kind = 'file_upload'): BronzeObject
    {
        return app(TenantContext::class)->runAs($tenant, fn () => app(BronzeStore::class)->store($tenant, $kind, $entity, $contents, 'csv', $provenance));
    }

    public function test_metadata_list_and_detail_are_scoped_to_the_active_company_and_hide_the_storage_key(): void
    {
        $mine = $this->store($this->tenant, "a,b\n1,2\n", ['original_name' => 'ventas.csv', 'row_count' => 1, 'metadata' => ['canal' => 'archivo']]);
        $this->store($this->tenant, 'otro', [], 'products', 'api_batch');
        [$other] = $this->createTenantWithUser();
        $foreign = $this->store($other, 'de otra empresa');

        $this->actingAsTenantUser($this->owner, $this->tenant);
        $list = $this->getJson('/api/v1/lineage/bronze')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertNotContains($foreign->id, array_column($list->json('data'), 'id'));

        $detail = $this->getJson("/api/v1/lineage/bronze/{$mine->id}")->assertOk()->json('data');
        $this->assertSame($mine->sha256, $detail['sha256']);
        $this->assertSame('ventas.csv', $detail['original_name']);
        $this->assertSame(['canal' => 'archivo'], $detail['metadata'], 'sin el nombre interno del disco');
        $this->assertTrue($detail['content_available']);
        foreach ([$list->getContent(), json_encode($detail)] as $body) {
            $this->assertStringNotContainsString('object_key', $body);
            $this->assertStringNotContainsString('tenants/'.$this->tenant->uuid, $body, 'la clave del almacén no se expone');
        }

        $this->getJson("/api/v1/lineage/bronze/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/lineage/bronze/{$foreign->id}/content")->assertNotFound();
    }

    public function test_list_filters(): void
    {
        $a = $this->store($this->tenant, 'uno');
        $b = $this->store($this->tenant, 'dos', [], 'products', 'sql_extract');
        $this->actingAsTenantUser($this->owner, $this->tenant);

        $this->assertSame([$b->id], array_column($this->getJson('/api/v1/lineage/bronze?entity=products')->json('data'), 'id'));
        $this->assertSame([$b->id], array_column($this->getJson('/api/v1/lineage/bronze?kind=sql_extract')->json('data'), 'id'));
        $this->assertSame([$a->id], array_column($this->getJson('/api/v1/lineage/bronze?sha256='.$a->sha256)->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/lineage/bronze?status=purged')->json('data'));
        $this->getJson('/api/v1/lineage/bronze?kind=otro')->assertUnprocessable();
        $this->getJson('/api/v1/lineage/bronze?entity=../x')->assertUnprocessable();
        $this->getJson('/api/v1/lineage/bronze?sha256=xyz')->assertUnprocessable();
    }

    public function test_every_role_reads_metadata_but_only_owner_and_admin_download_the_content(): void
    {
        $object = $this->store($this->tenant, "sku,qty\nA,1\n", ['original_name' => '../../peligroso.html']);
        $matrix = [
            [TenantRole::Viewer, false],
            [TenantRole::Analyst, false],
            [TenantRole::Admin, true],
            [TenantRole::Owner, true],
        ];
        foreach ($matrix as [$role, $canDownload]) {
            $user = $role === TenantRole::Owner ? $this->owner : $this->addUserToTenant($this->tenant, $role);
            $this->actingAsTenantUser($user, $this->tenant);
            $this->getJson("/api/v1/lineage/bronze/{$object->id}")->assertOk();
            $response = $this->get("/api/v1/lineage/bronze/{$object->id}/content");
            $canDownload ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_download_returns_the_verified_content_as_a_safe_attachment_and_is_audited(): void
    {
        $contents = "sku,qty\nA,1\n<script>alert(1)</script>\n";
        $object = $this->store($this->tenant, $contents, ['original_name' => '"><img src=x onerror=1>.html', 'content_type' => 'text/html']);
        $this->actingAsTenantUser($this->owner, $this->tenant);

        $response = $this->get("/api/v1/lineage/bronze/{$object->id}/content")->assertOk();

        $this->assertSame($contents, $response->streamedContent());
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame($object->sha256, $response->headers->get('X-Content-SHA256'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('sales-'.substr($object->sha256, 0, 12).'.csv', $disposition);
        $this->assertStringNotContainsString('onerror', $disposition, 'el nombre lo genera el servidor, no el cliente');

        $audit = AuditLog::query()->where('action', 'bronze.downloaded')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->owner->id, (int) $audit[0]->user_id);
        $this->assertSame($object->id, (int) $audit[0]->auditable_id);
        $this->assertSame($this->tenant->id, (int) $audit[0]->tenant_id);
    }

    public function test_download_of_altered_or_purged_content_is_refused_and_recorded(): void
    {
        $altered = $this->store($this->tenant, 'original');
        $purged = $this->store($this->tenant, 'a purgar');
        app(TenantContext::class)->runAs($this->tenant, fn () => app(BronzeStore::class)->purge($purged, 'retention'));
        Storage::disk('local')->put($altered->object_key, 'manipulado');
        $this->actingAsTenantUser($this->owner, $this->tenant);

        $this->get("/api/v1/lineage/bronze/{$altered->id}/content")->assertStatus(409)->assertJsonPath('code', 'bronze_integrity');
        $this->get("/api/v1/lineage/bronze/{$purged->id}/content")->assertStatus(410)->assertJsonPath('code', 'bronze_purged');
        $this->getJson("/api/v1/lineage/bronze/{$purged->id}")->assertOk()->assertJsonPath('data.content_available', false)->assertJsonPath('data.status', 'purged');

        $this->assertSame(1, AuditLog::query()->where('action', 'bronze.integrity_failed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'bronze.downloaded')->count(), 'no hubo descargas');
    }

    public function test_it_requires_authentication_and_an_active_company(): void
    {
        $object = $this->store($this->tenant, 'x');
        $this->getJson('/api/v1/lineage/bronze')->assertUnauthorized();
        $this->getJson("/api/v1/lineage/bronze/{$object->id}/content")->assertUnauthorized();

        // Un usuario de otra empresa no puede operar sobre esta aunque conozca los ids.
        [$otherTenant, $outsider] = $this->createTenantWithUser();
        $this->actingAsTenantUser($outsider, $otherTenant);
        $this->getJson("/api/v1/lineage/bronze/{$object->id}")->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->tenant->uuid)->getJson('/api/v1/lineage/bronze')->assertNotFound();
    }
}
