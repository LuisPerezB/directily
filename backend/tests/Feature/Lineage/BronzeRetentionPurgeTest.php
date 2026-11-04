<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzeRetention;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Retención y purga de Bronze (ADR-V25-24): política por empresa (`tenants.settings` → `data.bronze_retention_days`) con
 * precedencia sobre `config('bronze.retention_days')`, comando `predictily:bronze-purge` con auditoría, objetos retenidos por
 * cuarentena pendiente y aislamiento entre empresas.
 */
final class BronzeRetentionPurgeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['bronze.disk' => 'local', 'bronze.retention_days' => 0, 'bronze.retention_max_days' => 3650]);
    }

    private function tenantWithPolicy(mixed $days): Tenant
    {
        [$tenant] = $this->createTenantWithUser();
        if ($days !== 'none') {
            $tenant->forceFill(['settings' => array_merge(Tenant::DEFAULT_SETTINGS, ['data' => ['bronze_retention_days' => $days]])])->save();
        }

        return $tenant->refresh();
    }

    private function store(Tenant $tenant, string $contents, string $entity = 'sales'): BronzeObject
    {
        return app(TenantContext::class)->runAs($tenant, fn () => app(BronzeStore::class)->store($tenant, 'file_upload', $entity, $contents, 'csv'));
    }

    /** @return array<string, mixed> */
    private function state(Tenant $tenant, BronzeObject $object): array
    {
        $fresh = app(TenantContext::class)->runAs($tenant, fn () => BronzeObject::query()->findOrFail($object->id));

        return ['status' => $fresh->status, 'until' => $fresh->retention_until?->toDateString(), 'exists' => Storage::disk('local')->exists($object->object_key)];
    }

    public function test_the_company_policy_takes_precedence_over_the_platform_default_and_ignores_corrupt_values(): void
    {
        $retention = app(BronzeRetention::class);
        config(['bronze.retention_days' => 90]);

        $this->assertSame(90, $retention->daysFor($this->tenantWithPolicy('none')), 'sin política de empresa rige la de la plataforma');
        $this->assertSame('platform', $retention->sourceFor($this->tenantWithPolicy('none')));
        $this->assertSame(30, $retention->daysFor($this->tenantWithPolicy(30)));
        $this->assertSame('tenant', $retention->sourceFor($this->tenantWithPolicy(30)));
        $this->assertSame(0, $retention->daysFor($this->tenantWithPolicy(0)), '0 = sin vencimiento, y gana al valor de la plataforma');
        $this->assertSame(45, $retention->daysFor($this->tenantWithPolicy('45')), 'un entero en texto es válido');

        foreach ([-5, 99999, 'abc', 1.5, true, null, ['x']] as $corrupt) {
            $this->assertSame(90, $retention->daysFor($this->tenantWithPolicy($corrupt)), 'un valor inválido nunca se interpreta como purgar: '.json_encode($corrupt));
        }
    }

    public function test_retention_until_is_set_from_the_policy_when_the_object_is_stored(): void
    {
        $this->travelTo('2026-01-10 15:00:00');
        $with = $this->tenantWithPolicy(30);
        $without = $this->tenantWithPolicy(0);

        $this->assertSame('2026-02-09', $this->store($with, 'uno')->retention_until->toDateString());
        $this->assertNull($this->store($without, 'uno')->retention_until);
    }

    public function test_the_purge_command_removes_only_expired_objects_of_each_company_and_audits_it(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $a = $this->tenantWithPolicy(30);
        $b = $this->tenantWithPolicy(0);
        $c = $this->tenantWithPolicy('none');   // sin política: la plataforma (10 días)
        config(['bronze.retention_days' => 10]);
        $oldA = $this->store($a, 'a viejo');
        $oldB = $this->store($b, 'b viejo');
        $oldC = $this->store($c, 'c viejo');
        $this->travelTo('2026-02-15 10:00:00');
        $newA = $this->store($a, 'a nuevo');

        $this->travelTo('2026-02-20 10:00:00');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();

        $this->assertSame(['status' => 'purged', 'until' => '2026-01-31', 'exists' => false], $this->state($a, $oldA));
        $this->assertSame('stored', $this->state($a, $newA)['status']);
        $this->assertTrue($this->state($a, $newA)['exists']);
        $this->assertSame(['status' => 'stored', 'until' => null, 'exists' => true], $this->state($b, $oldB), '0 días = sin vencimiento');
        $this->assertSame('purged', $this->state($c, $oldC)['status'], 'la empresa sin política usa la de la plataforma');

        $audit = AuditLog::query()->where('action', 'bronze.purged')->orderBy('id')->get();
        $this->assertSame([$a->id, $c->id], $audit->pluck('tenant_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('retention', $audit[0]->metadata['reason']);
    }

    public function test_the_retention_date_is_inclusive_and_the_purge_is_idempotent(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $tenant = $this->tenantWithPolicy(30);
        $object = $this->store($tenant, 'borde');

        $this->travelTo('2026-01-31 23:00:00');   // día local de Guayaquil: 31-ene → todavía se conserva
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame('stored', $this->state($tenant, $object)['status']);

        $this->travelTo('2026-02-01 12:00:00');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame('purged', $this->state($tenant, $object)['status']);
        $this->assertSame(1, AuditLog::query()->where('action', 'bronze.purged')->count(), 'una sola purga auditada');
    }

    public function test_dry_run_and_single_company_options(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $a = $this->tenantWithPolicy(5);
        $b = $this->tenantWithPolicy(5);
        $oa = $this->store($a, 'a');
        $ob = $this->store($b, 'b');
        $this->travelTo('2026-03-01 10:00:00');

        $this->artisan('predictily:bronze-purge', ['--dry-run' => true])->expectsOutputToContain('se purgarían 1')->assertSuccessful();
        $this->assertSame('stored', $this->state($a, $oa)['status']);
        $this->assertSame('stored', $this->state($b, $ob)['status']);
        $this->assertSame(0, AuditLog::query()->where('action', 'bronze.purged')->count());

        $this->artisan('predictily:bronze-purge', ['--tenant' => $a->uuid])->assertSuccessful();
        $this->assertSame('purged', $this->state($a, $oa)['status']);
        $this->assertSame('stored', $this->state($b, $ob)['status'], 'solo la empresa pedida');

        $this->artisan('predictily:bronze-purge', ['--tenant' => '00000000-0000-0000-0000-000000000000'])->assertFailed();
    }

    public function test_a_policy_change_applies_to_objects_already_stored(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $tenant = $this->tenantWithPolicy(0);
        $object = $this->store($tenant, 'antes sin política');
        $this->assertNull($this->state($tenant, $object)['until']);

        $this->travelTo('2026-04-01 10:00:00');
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['data' => ['bronze_retention_days' => 30]])])->save();
        $this->artisan('predictily:bronze-purge', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('stored', $this->state($tenant, $object)['status'], 'el simulacro no cambia nada');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame(['status' => 'purged', 'until' => '2026-01-31', 'exists' => false], $this->state($tenant, $object));

        // Alargar la política realinea la fecha de los objetos vigentes.
        $other = $this->store($tenant, 'segundo');
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['data' => ['bronze_retention_days' => 365]])])->save();
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame(['status' => 'stored', 'until' => '2027-04-01', 'exists' => true], $this->state($tenant, $other));
    }

    public function test_objects_with_pending_quarantine_records_are_kept(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $tenant = $this->tenantWithPolicy(5);
        $held = $this->store($tenant, 'con cuarentena');
        $released = $this->store($tenant, 'cuarentena resuelta');
        $free = $this->store($tenant, 'libre');
        foreach ([[$held, 'pending'], [$released, 'discarded']] as [$object, $status]) {
            DB::table('quarantine_records')->insert([
                'tenant_id' => $tenant->id, 'bronze_object_id' => $object->id, 'entity' => 'sales', 'record_key' => 'F-1', 'payload' => '{}',
                'issue_codes' => '[]', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->travelTo('2026-03-01 10:00:00');
        $this->artisan('predictily:bronze-purge')->expectsOutputToContain('retenidos por cuarentena pendiente 1')->assertSuccessful();

        $this->assertSame('stored', $this->state($tenant, $held)['status'], 'su reprocesamiento lo necesita');
        $this->assertSame('purged', $this->state($tenant, $released)['status']);
        $this->assertSame('purged', $this->state($tenant, $free)['status']);
    }

    public function test_a_storage_failure_does_not_mark_the_object_purged_and_does_not_stop_the_others(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $tenant = $this->tenantWithPolicy(5);
        $stuck = $this->store($tenant, 'no se deja borrar');
        $other = $this->store($tenant, 'si se borra');
        $this->travelTo('2026-03-01 10:00:00');

        $real = Storage::disk('local');
        $flaky = \Mockery::mock($real)->makePartial();
        $flaky->shouldReceive('delete')->andReturnUsing(fn ($path) => str_contains((string) $path, hash('sha256', 'no se deja borrar')) ? false : $real->delete($path));
        Storage::set('local', $flaky);

        $this->artisan('predictily:bronze-purge')->assertFailed();

        $this->assertSame('stored', $this->state($tenant, $stuck)['status'], 'sin borrar el contenido no se declara purgado');
        $this->assertTrue($this->state($tenant, $stuck)['exists']);
        $this->assertSame('purged', $this->state($tenant, $other)['status']);
    }

    public function test_deleting_a_company_erases_its_bronze_originals_and_retries_when_the_store_fails(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        [$gone] = $this->createTenantWithUser();
        [$kept] = $this->createTenantWithUser();
        $goneObject = $this->store($gone, 'datos personales de la empresa eliminada');
        $keptObject = $this->store($kept, 'datos de otra empresa');
        $gone->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()->subDays(31)])->save();
        $this->assertTrue(Storage::disk('local')->exists($goneObject->object_key));

        // Fallo del almacén al borrar el prefijo Bronze: la empresa NO se elimina (para reintentar) y los originales siguen ahí.
        $real = Storage::disk('local');
        $flaky = \Mockery::mock($real)->makePartial();
        $flaky->shouldReceive('deleteDirectory')->andReturnUsing(function ($directory) use ($real) {
            if (str_contains((string) $directory, '/bronze')) {
                throw new \RuntimeException('almacén no disponible');
            }

            return $real->deleteDirectory($directory);
        });
        Storage::set('local', $flaky);
        $this->artisan('predictily:purge')->expectsOutputToContain('no se elimina todavía')->assertSuccessful();
        $this->assertSame(1, DB::table('tenants')->where('id', $gone->id)->count());
        $this->assertTrue($real->exists($goneObject->object_key));

        // Con el almacén disponible se borran los originales de ESA empresa (y solo de ella) y luego la empresa.
        Storage::set('local', $real);
        $this->artisan('predictily:purge')->assertSuccessful();
        $this->assertSame(0, DB::table('tenants')->where('id', $gone->id)->count());
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $gone->id)->count(), 'las filas se van en cascada');
        $this->assertFalse($real->exists($goneObject->object_key), 'el original se borró del almacén');
        $this->assertTrue($real->exists($keptObject->object_key), 'otra empresa intacta');
        $this->assertSame('stored', $this->state($kept, $keptObject)['status']);
    }

    public function test_deleting_a_company_without_bronze_objects_still_completes(): void
    {
        [$gone] = $this->createTenantWithUser();
        $gone->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()->subDays(31)])->save();

        $this->artisan('predictily:purge')->assertSuccessful();

        $this->assertSame(0, DB::table('tenants')->where('id', $gone->id)->count());
    }

    public function test_a_rehydrated_object_keeps_its_first_receipt_and_the_retention_runs_from_the_last_one(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $tenant = $this->tenantWithPolicy(30);
        $object = $this->store($tenant, 'vuelve a llegar');
        $this->travelTo('2026-03-01 10:00:00');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame('purged', $this->state($tenant, $object)['status']);

        // El mismo archivo se vuelve a recibir: recupera su contenido, conserva la primera recepción y su retención corre desde hoy.
        $again = $this->store($tenant, 'vuelve a llegar');
        $this->assertSame($object->id, $again->id);
        $this->assertSame('2026-01-01T10:00:00+00:00', $again->received_at->toIso8601String());
        $this->assertSame(['status' => 'stored', 'until' => '2026-03-31', 'exists' => true], $this->state($tenant, $object));

        // Sin la corrección se habría vuelto a purgar de inmediato (recibido el 1-ene + 30 días ya vencidos).
        $this->travelTo('2026-03-05 10:00:00');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame('stored', $this->state($tenant, $object)['status']);
        $this->artisan('predictily:bronze-purge', ['--dry-run' => true])->expectsOutputToContain('vencidos 0')->assertSuccessful();

        $this->travelTo('2026-04-05 10:00:00');
        $this->artisan('predictily:bronze-purge')->assertSuccessful();
        $this->assertSame('purged', $this->state($tenant, $object)['status']);
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'predictily:bronze-purge'));
        $this->assertCount(1, $events);
        $this->assertSame('45 3 * * *', $events->first()->expression);
    }

    public function test_only_owner_or_admin_can_set_the_policy_through_the_tenant_settings_api(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);

        $this->actingAsTenantUser($owner, $tenant)->patchJson('/api/v1/tenant', ['settings' => ['data' => ['bronze_retention_days' => 120]]])->assertOk();
        $this->assertSame(120, $tenant->refresh()->effectiveSettings()['data']['bronze_retention_days']);
        $this->assertSame(120, $this->getJson('/api/v1/tenant')->json('data.settings.data.bronze_retention_days'));
        $this->assertSame(120, app(BronzeRetention::class)->daysFor($tenant));

        $this->actingAsTenantUser($admin, $tenant)->patchJson('/api/v1/tenant', ['settings' => ['data' => ['bronze_retention_days' => 0]]])->assertOk();
        $this->assertSame(0, app(BronzeRetention::class)->daysFor($tenant->refresh()));

        $this->actingAsTenantUser($analyst, $tenant)->patchJson('/api/v1/tenant', ['settings' => ['data' => ['bronze_retention_days' => 1]]])->assertForbidden();
        foreach ([-1, 3651, 'x', 1.5] as $invalid) {
            $this->actingAsTenantUser($owner, $tenant)->patchJson('/api/v1/tenant', ['settings' => ['data' => ['bronze_retention_days' => $invalid]]])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/tenant', ['settings' => ['data' => ['otra_clave' => 1]]])->assertUnprocessable();
    }
}
