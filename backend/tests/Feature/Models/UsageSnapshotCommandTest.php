<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\Forecasting\ModelArtifactVerifier;
use App\Services\Models\Usage\StorageUsageSnapshot;
use App\Services\Models\Usage\TenantUsageMeter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/**
 * `php artisan predictily:usage-snapshot` (V25-REG, ADR-V25-09 §14): bytes de Bronze y de artefactos por empresa, diario, idempotente, sin precios.
 */
class UsageSnapshotCommandTest extends TestCase
{
    use ModelRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
        Storage::fake('local');
        config(['bronze.disk' => 'local', 'mlops.published_disk' => 'local']);
    }

    protected function tearDown(): void
    {
        $this->tearDownRegistry();
        parent::tearDown();
    }

    private function bronze(Tenant $tenant, string $contents, string $entity = 'sales'): void
    {
        $this->withinTenant($tenant, fn () => app(BronzeStore::class)->store($tenant, 'file_upload', $entity, $contents, 'csv'));
    }

    private function artifact(Tenant $tenant, string $relative, int $size): void
    {
        $path = $this->artifactsDir.'/'.$tenant->id.'/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, str_repeat('x', $size));
    }

    /** @return array<string, array{quantity: float, unit: string}> "fuente:métrica" => cantidad */
    private function usage(Tenant $tenant, string $day): array
    {
        return DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->where('source_ref', $day)->orderBy('source')->orderBy('metric')->get()
            ->mapWithKeys(fn ($r) => ["{$r->source}:{$r->metric}" => ['quantity' => (float) $r->quantity, 'unit' => $r->unit]])->all();
    }

    public function test_it_records_bronze_and_artifact_bytes_per_company_without_mixing_companies(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $first = "sku,qty\nCEM-50,2\n";
        $second = "sku,qty\nVAR-12,9\n";
        $this->bronze($a, $first);
        $this->bronze($a, $second, 'inventory');
        $this->bronze($b, str_repeat('b', 1000));
        $this->artifact($a, 'run-1/modelo.joblib', 300);
        $this->artifact($a, 'customer/run-2/otro.joblib', 200);
        $this->artifact($a, 'customer/run-2/otro.joblib.manifest.json', 50);
        $this->artifact($b, 'run-9/ajeno.joblib', 7000);

        $this->assertSame(0, Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']));

        $this->assertSame([
            'artifacts:storage_bytes' => ['quantity' => 550.0, 'unit' => 'bytes'], 'artifacts:storage_objects' => ['quantity' => 3.0, 'unit' => 'objects'],
            'bronze:storage_bytes' => ['quantity' => (float) (strlen($first) + strlen($second)), 'unit' => 'bytes'], 'bronze:storage_objects' => ['quantity' => 2.0, 'unit' => 'objects'],
        ], $this->usage($a, '2026-11-01'));
        $this->assertSame(1000.0, $this->usage($b, '2026-11-01')['bronze:storage_bytes']['quantity']);
        $this->assertSame(7000.0, $this->usage($b, '2026-11-01')['artifacts:storage_bytes']['quantity']);
    }

    public function test_published_objects_are_measured_in_the_object_store_and_purged_bronze_is_not_counted(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $key = 'tenants/'.$a->uuid.'/models/customer/v1/h30.joblib';
        Storage::disk('local')->put($key, str_repeat('m', 120));
        Storage::disk('local')->put($key.'.manifest.json', str_repeat('s', 30));
        $this->customerModel($a, 'candidate', ['published_object_key' => $key, 'published_at' => now()], false);
        $this->customerModel($a, 'candidate', ['published_object_key' => 'tenants/'.$a->uuid.'/models/customer/v2/falta.joblib', 'published_at' => now()], false);   // no está en el almacén
        $this->customerModel($b, 'candidate', ['published_object_key' => $key, 'published_at' => now()], false);                                                    // clave de A en la fila de B: no se cuenta para A

        $this->bronze($a, 'purgado');
        $this->bronze($a, 'vigente');
        $this->withinTenant($a, fn () => BronzeObject::query()->where('size_bytes', 7)->orderBy('id')->first()?->forceFill(['status' => 'purged'])->save());

        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01', '--tenant' => $a->uuid]);
        $usage = $this->usage($a, '2026-11-01');
        $this->assertSame(150.0, $usage['artifacts:storage_bytes']['quantity']);
        $this->assertSame(2.0, $usage['artifacts:storage_objects']['quantity']);
        $this->assertSame(7.0, $usage['bronze:storage_bytes']['quantity'], 'el objeto purgado ya no ocupa almacenamiento');
        $this->assertSame([], $this->usage($b, '2026-11-01'), '--tenant mide solo esa empresa');
    }

    public function test_it_is_idempotent_per_day_and_keeps_history_across_days(): void
    {
        [$a] = $this->createTenantWithUser();
        $this->bronze($a, 'uno');
        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']);
        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']);
        $this->assertSame(4, DB::table('tenant_usage_records')->where('tenant_id', $a->id)->count());
        $this->bronze($a, 'dos-más-largo');
        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']);
        $this->assertSame((float) (strlen('uno') + strlen('dos-más-largo')), $this->usage($a, '2026-11-01')['bronze:storage_bytes']['quantity'], 'repetir el mismo día reemplaza la foto');
        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-02']);
        $this->assertSame(8, DB::table('tenant_usage_records')->where('tenant_id', $a->id)->count(), 'cada día conserva su foto');
        $this->assertSame((float) (strlen('uno') + strlen('dos-más-largo')), (float) DB::table('tenant_usage_records')->where('tenant_id', $a->id)->where('source', 'bronze')
            ->where('metric', 'storage_bytes')->where('source_ref', '2026-11-02')->value('quantity'));
    }

    public function test_options_validation_dry_run_and_inactive_companies(): void
    {
        [$a] = $this->createTenantWithUser();
        [$inactive] = $this->createTenantWithUser();
        Tenant::query()->whereKey($inactive->id)->update(['status' => 'suspended']);
        $this->bronze($a, 'x');

        $this->assertSame(1, Artisan::call('predictily:usage-snapshot', ['--date' => '01/11/2026']));
        $this->assertSame(1, Artisan::call('predictily:usage-snapshot', ['--tenant' => '00000000-0000-0000-0000-000000000000']));
        $this->assertSame(0, Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01', '--dry-run' => true]));
        $this->assertStringContainsString('simulación', Artisan::output());
        $this->assertSame(0, DB::table('tenant_usage_records')->count(), 'la simulación no escribe');

        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']);
        $this->assertGreaterThan(0, DB::table('tenant_usage_records')->where('tenant_id', $a->id)->count());
        $this->assertSame(0, DB::table('tenant_usage_records')->where('tenant_id', $inactive->id)->count(), 'una empresa que no está activa no se mide');
    }

    public function test_an_invisible_artifacts_volume_is_reported_and_not_invented(): void
    {
        [$a] = $this->createTenantWithUser();
        config(['engine.artifacts_dir' => null]);
        Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']);
        $this->assertStringContainsString('no es visible desde el backend', Artisan::output());
        $this->assertSame(0.0, $this->usage($a, '2026-11-01')['artifacts:storage_bytes']['quantity']);
    }

    /** LOW 9: el fallo de una empresa no impide medir a las demás; el comando lo informa y termina con error. */
    public function test_a_failure_in_one_company_does_not_stop_the_others(): void
    {
        [$bad] = $this->createTenantWithUser();
        [$good] = $this->createTenantWithUser();
        $this->bronze($good, 'abcde');
        $badId = $bad->id;
        $this->app->bind(StorageUsageSnapshot::class, fn () => new class($badId, app(TenantContext::class), app(TenantUsageMeter::class), app(ModelArtifactVerifier::class)) extends StorageUsageSnapshot
        {
            public function __construct(private readonly int $failFor, TenantContext $context, TenantUsageMeter $meter, ModelArtifactVerifier $artifacts)
            {
                parent::__construct($context, $meter, $artifacts);
            }

            public function record(Tenant $tenant, string $day): array
            {
                if ($tenant->id === $this->failFor) {
                    throw new \RuntimeException('disco ilegible');
                }

                return parent::record($tenant, $day);
            }
        });

        $this->assertSame(1, Artisan::call('predictily:usage-snapshot', ['--date' => '2026-11-01']));
        $out = Artisan::output();
        $this->assertStringContainsString("Empresa #{$bad->id} · FALLÓ", $out);
        $this->assertStringContainsString('Empresas con error: 1 de 2', $out);
        $this->assertSame(5.0, $this->usage($good, '2026-11-01')['bronze:storage_bytes']['quantity'], 'la empresa sana sí quedó medida');
        $this->assertSame([], $this->usage($bad, '2026-11-01'));
    }

    public function test_the_snapshot_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'predictily:usage-snapshot'));
        $this->assertCount(1, $events);
        $this->assertSame('0 5 * * *', $events->first()->expression);
    }
}
