<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Enums\TenantRole;
use App\Support\Logging\RedactPersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

final class PrivacyAndOperationsTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    public function test_logs_mask_personal_data_and_secrets(): void
    {
        config(['logging.channels.pii_test' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [RedactPersonalData::class]]]);
        $logger = Log::channel('pii_test');
        $logger->warning('Cliente ana.perez@correo.com con cédula 1710034065 y RUC 1790012345001', [
            'password' => 'ClaveSegura123', 'token' => '5|abcdefghijklmnopqrstuvwxyz123456', 'nota' => 'Bearer abc.def.ghi',
        ]);
        /** @var TestHandler $handler */
        $handler = $logger->getLogger()->getHandlers()[0];
        $record = $handler->getRecords()[0];
        $dump = $record->message.json_encode($record->context);

        $this->assertStringNotContainsString('ana.perez@correo.com', $dump);
        $this->assertStringNotContainsString('1710034065', $dump);
        $this->assertStringNotContainsString('1790012345001', $dump);
        $this->assertStringNotContainsString('ClaveSegura123', $dump);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $dump);
        $this->assertStringContainsString('a***@c***.com', $dump);
    }

    public function test_full_tenant_export_generates_zip_with_signed_download(): void
    {
        Storage::fake('local');
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->createBranch($tenant, '001');
        $this->actingAsTenantUser($owner, $tenant);
        $this->postJson('/api/v1/sales/batch', ['documents' => [['document_number' => 'E-1', 'issued_at' => '2026-05-01', 'items' => [['product_sku' => 'Z', 'quantity' => 1, 'unit_price' => 3]]]]])->assertOk();

        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $this->actingAsTenantUser($admin, $tenant)->postJson('/api/v1/tenant/export')->assertForbidden();

        $this->actingAsTenantUser($owner, $tenant);
        $exportId = $this->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $status = $this->getJson("/api/v1/tenant/export/{$exportId}")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, $status->json('data.summary.sales'));
        $url = $status->json('data.download_url');
        $this->assertNotNull($url);

        $path = DB::table('tenant_exports')->where('id', $exportId)->value('path');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $this->assertNotFalse($zip->locateName('sales.csv'));
        $this->assertStringContainsString('E-1', (string) $zip->getFromName('sales.csv'));
        $zip->close();

        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $url))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'data.exported']);
    }

    public function test_audit_log_endpoint_is_tenant_scoped(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($otherOwner, $other)->patchJson('/api/v1/tenant', ['name' => 'Otra'])->assertOk();
        $this->actingAsTenantUser($owner, $tenant)->patchJson('/api/v1/tenant', ['name' => 'Mía'])->assertOk();
        $logs = $this->getJson('/api/v1/audit-logs')->assertOk()->json('data');
        $this->assertNotEmpty($logs);
        $this->assertSame(['tenant.updated'], array_values(array_unique(array_column($logs, 'action'))));
    }

    public function test_purge_command_applies_grace_period_and_retention(): void
    {
        [$expired] = $this->createTenantWithUser();
        $expired->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()->subDays(31)])->save();
        [$recent] = $this->createTenantWithUser();
        $recent->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()->subDays(5)])->save();
        [$active, $owner] = $this->createTenantWithUser();
        $active->forceFill(['data_retention_months' => 12])->save();
        $this->createBranch($active, '001');
        $this->actingAsTenantUser($owner, $active)->postJson('/api/v1/sales/batch', ['documents' => [
            ['document_number' => 'OLD', 'issued_at' => now()->subMonths(13)->toDateString(), 'items' => [['product_sku' => 'Z', 'quantity' => 1, 'unit_price' => 1]]],
            ['document_number' => 'NEW', 'issued_at' => now()->subMonth()->toDateString(), 'items' => [['product_sku' => 'Z', 'quantity' => 1, 'unit_price' => 1]]],
        ]])->assertOk()->assertJsonPath('data.created', 2);

        $this->artisan('predictily:purge')->assertSuccessful();

        $this->assertDatabaseMissing('tenants', ['id' => $expired->id]);
        $this->assertDatabaseHas('tenants', ['id' => $recent->id]);
        $this->assertSame(['NEW'], DB::table('sales')->where('tenant_id', $active->id)->pluck('document_number')->all());
    }

    public function test_demo_data_endpoint_loads_fictitious_dataset_only_into_empty_tenant(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/demo-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/mini_branches.csv", "code,name,province,canton,commercial_zone\n001,Matriz,Pichincha,Quito,Norte\n");
        file_put_contents("{$dir}/mini_products.csv", "sku,name,category,unit,brand,current_price,current_cost,is_active\nM-1,Martillo,Herramientas,unidad,X,10,6,true\n");
        file_put_contents("{$dir}/mini_sales.csv", "document_number,document_type,status,issued_at,branch_code,product_sku,quantity,unit_price\nF1,factura,,2026-01-02 10:00:00,001,M-1,2,10\nF2,factura,anulada,2026-01-03 10:00:00,001,M-1,1,10\n");
        config(['predictily.demo.path' => $dir, 'predictily.demo.tenants.mini' => ['name' => 'Mini (Demo ficticia)', 'sector' => 'ferreteria', 'email' => 'mini@predictily.test', 'user_name' => 'Mini']]);

        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/tenant/demo-data', ['dataset' => 'mini'])->assertStatus(202);
        $this->assertSame(2, DB::table('sales')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DB::table('branches')->where('tenant_id', $tenant->id)->count());
        $this->postJson('/api/v1/tenant/demo-data', ['dataset' => 'mini'])->assertStatus(409)->assertJsonPath('code', 'tenant_not_empty');

        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }

    public function test_security_headers_are_present(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant)->getJson('/api/v1/tenant')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
