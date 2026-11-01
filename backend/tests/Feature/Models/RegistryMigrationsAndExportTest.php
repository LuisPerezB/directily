<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;
use ZipArchive;

/** Migraciones de W4-A (línea base de legados, índice de idempotencia de la medición) y exportación LOPDP de eventos y uso. */
class RegistryMigrationsAndExportTest extends TestCase
{
    use ModelRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    protected function tearDown(): void
    {
        $this->tearDownRegistry();
        parent::tearDown();
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    public function test_the_backfill_marks_legacy_pipeline_models_and_never_publishes_candidates_or_published_models_as_legacy(): void
    {
        $c = $this->registryCompany();
        $t = $c['tenant'];
        $legacy = [
            $this->customerModel($t, 'production', [], false), $this->customerModel($t, 'validated', [], false), $this->customerModel($t, 'retired', [], false),
        ];
        $untouched = [
            'candidate' => $this->customerModel($t, 'candidate', [], false),
            'rejected' => $this->customerModel($t, 'rejected', [], false),
            'failed' => $this->customerModel($t, 'failed', [], false),
            'published_production' => $this->customerModel($t, 'production', ['published_at' => now(), 'published_env' => 'staging'], false),
            'published_by_only' => $this->customerModel($t, 'validated', ['published_by' => $c['publisher']->id], false),
            'approved' => $this->customerModel($t, 'approved', ['approved_at' => now(), 'approved_by' => $c['approver']->id], false),
            'with_events' => $this->customerModel($t, 'production', [], false),
        ];
        DB::table('model_registry_events')->insert(['tenant_id' => $t->id, 'model_registry_id' => $untouched['with_events'], 'to_status' => 'production', 'actor_kind' => 'system']);
        $global = (int) DB::table('model_registry')->insertGetId(['tenant_id' => null, 'version' => 'global-1', 'model_family' => 'naive', 'strategy_level' => 'A', 'status' => 'production', 'created_at' => now(), 'updated_at' => now()]);

        $migration = $this->migration('2026_03_12_000300_backfill_legacy_model_registry_events.php');
        $migration->up();

        foreach ($legacy as $id) {
            $events = $this->eventsOf($id);
            $this->assertCount(1, $events);
            $this->assertSame([null, 'system'], [$events[0]['from'], $events[0]['kind']]);
            $this->assertSame($this->statusOf($id), $events[0]['to']);
            $this->assertSame(['legacy_approved_by_migration', 'legacy_pipeline'], [$events[0]['evidence']['legacy_policy'], $events[0]['evidence']['serving_basis']]);
            $this->assertStringContainsString('legado aprobado por migración', (string) $events[0]['reason']);
        }
        foreach (['candidate', 'rejected', 'failed', 'published_production', 'published_by_only', 'approved'] as $key) {
            $this->assertSame([], $this->eventsOf($untouched[$key]), "{$key} no recibe línea base de legado");
        }
        $this->assertCount(1, $this->eventsOf($untouched['with_events']), 'un modelo que ya tiene eventos no se toca');
        $this->assertSame(0, DB::table('model_registry_events')->where('model_registry_id', $global)->count(), 'los modelos globales (sin empresa) no tienen eventos de empresa');

        // idempotente
        $migration->up();
        $this->assertSame(3, DB::table('model_registry_events')->whereIn('model_registry_id', $legacy)->count());
        // el legado sigue siendo servible por la API (la política dinámica no depende de la migración)
        $row = collect($this->actingAsTenantUser($c['viewer'], $t)->getJson('/api/v1/models?status=production')->assertOk()->json('data'))->firstWhere('id', $legacy[0]);
        $this->assertSame('legacy_pipeline', $row['serving_basis']);

        // reversible: solo borra lo que escribió
        $migration->down();
        $this->assertSame(0, DB::table('model_registry_events')->whereIn('model_registry_id', $legacy)->count());
        $this->assertCount(1, $this->eventsOf($untouched['with_events']));
    }

    public function test_the_usage_index_migration_removes_existing_duplicates_keeping_the_latest_and_enforces_uniqueness(): void
    {
        [$t] = $this->createTenantWithUser();
        DB::statement('DROP INDEX tenant_usage_records_source_unique');
        $row = fn (float $q, ?string $ref) => DB::table('tenant_usage_records')->insertGetId([
            'tenant_id' => $t->id, 'period' => '2026-11-01', 'metric' => 'runs', 'quantity' => $q, 'unit' => 'run', 'source' => 'forecast_run', 'source_ref' => $ref,
        ]);
        $row(1, '7');
        $latest = $row(2, '7');
        $row(5, null);
        $row(6, null);                                                // sin referencia: nunca son duplicado

        $this->migration('2026_03_12_000200_add_idempotency_key_to_tenant_usage_records.php')->up();

        $this->assertSame([$latest], DB::table('tenant_usage_records')->where('source_ref', '7')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(2, DB::table('tenant_usage_records')->whereNull('source_ref')->count());
        try {
            DB::transaction(fn () => $row(9, '7'));
            $this->fail('el índice único debe impedir el duplicado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tenant_usage_records_source_unique', $e->getMessage());
        }
    }

    public function test_the_lopdp_export_includes_the_events_and_usage_of_the_company_only_and_never_the_storage_key(): void
    {
        Storage::fake('local');
        $a = $this->registryCompany();
        $b = $this->registryCompany();
        $keyA = 'tenants/'.$a['tenant']->uuid.'/models/customer/v1/CLAVE-INTERNA-A.joblib';
        $idA = $this->publishedCandidate($a['tenant'], $a['publisher'], ['published_object_key' => $keyA, 'purpose' => 'MARCADOR-A propósito']);
        $idB = $this->publishedCandidate($b['tenant'], $b['publisher'], ['purpose' => 'MARCADOR-B propósito']);
        foreach ([[$a, $idA, 'MARCADOR-A'], [$b, $idB, 'MARCADOR-B']] as [$c, $id, $mark]) {
            DB::table('model_registry_events')->insert(['tenant_id' => $c['tenant']->id, 'model_registry_id' => $id, 'from_status' => 'candidate', 'to_status' => 'validated',
                'actor_user_id' => $c['analyst']->id, 'actor_kind' => 'user', 'reason' => "Motivo {$mark}", 'evidence' => json_encode(['nota' => $mark])]);
            DB::table('tenant_usage_records')->insert(['tenant_id' => $c['tenant']->id, 'period' => '2026-11-01', 'metric' => 'train_cpu_seconds', 'quantity' => 12.5, 'unit' => 's', 'source' => 'forecast_run', 'source_ref' => $mark]);
        }

        $exportId = $this->actingAsTenantUser($a['owner'], $a['tenant'])->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $this->getJson("/api/v1/tenant/export/{$exportId}")->assertOk()->assertJsonPath('data.status', 'completed');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) DB::table('tenant_exports')->where('id', $exportId)->value('path'))) === true);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        foreach (['model_registry_events.csv', 'tenant_usage_records.csv', 'model_registry.csv'] as $name) {
            $this->assertArrayHasKey($name, $files);
        }
        $this->assertStringContainsString('Motivo MARCADOR-A', $files['model_registry_events.csv']);
        $this->assertStringContainsString('MARCADOR-A', $files['tenant_usage_records.csv']);
        $this->assertStringContainsString('MARCADOR-A propósito', $files['model_registry.csv']);
        $everything = implode("\n", $files);
        $this->assertStringNotContainsString('MARCADOR-B', $everything);
        $this->assertStringNotContainsString('CLAVE-INTERNA-A', $everything, 'la clave interna del almacén no se exporta');
        $this->assertStringNotContainsString('published_object_key', $files['model_registry.csv']);
    }
}
