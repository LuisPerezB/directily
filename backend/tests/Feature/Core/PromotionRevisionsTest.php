<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\ImportJob;
use App\Models\Promotion;
use App\Models\PromotionRevision;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Fecha de disponibilidad (`known_at`) y versiones históricas de promociones
 * (auditoría 2026-10-28, hallazgo F5): el motor pronostica cada corte solo con lo conocido ese día.
 */
final class PromotionRevisionsTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00', 'UTC'));
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /** @return list<object> */
    private function revisions(int $promotionId): array
    {
        return DB::table('promotion_revisions')->where('promotion_id', $promotionId)->orderBy('revision')->get()->all();
    }

    private function importAndConfirm(array $headers, array $rows): ImportJob
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows), 'promotions', [], [], 'promos.csv');
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        return $job;
    }

    // ───────────────────────── esquema y migración ─────────────────────────

    public function test_schema_has_known_at_not_null_and_revisions_with_composite_tenant_fks(): void
    {
        $this->assertTrue(Schema::hasColumns('promotions', ['known_at', 'deleted_at']));
        $nullable = DB::selectOne("SELECT is_nullable, column_default FROM information_schema.columns WHERE table_name = 'promotions' AND column_name = 'known_at'");
        $this->assertSame('NO', $nullable->is_nullable);
        $this->assertNotNull($nullable->column_default);
        $this->assertTrue(Schema::hasColumns('promotion_revisions', ['tenant_id', 'promotion_id', 'revision', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id', 'known_at', 'deleted', 'source', 'recorded_at']));

        // La FK compuesta impide que una revisión de la Empresa B apunte a una promoción de la A.
        $promoA = $this->withinTenant($this->tenant, fn () => Promotion::query()->create(['name' => 'A', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05']));
        [$tenantB] = $this->createTenantWithUser();
        $this->expectException(QueryException::class);
        DB::table('promotion_revisions')->insert(['tenant_id' => $tenantB->id, 'promotion_id' => $promoA->id, 'revision' => 9, 'name' => 'x', 'promo_type' => 'percentage',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'known_at' => now(), 'recorded_at' => now()]);
    }

    public function test_migration_backfills_known_at_and_initial_revision(): void
    {
        $addKnownAt = require database_path('migrations/2026_01_03_000100_add_known_at_and_soft_deletes_to_promotions.php');
        $revisions = require database_path('migrations/2026_01_03_000200_create_promotion_revisions_table.php');
        // Vuelve al esquema anterior (DDL transaccional en PostgreSQL: RefreshDatabase lo revierte).
        $revisions->down();
        $addKnownAt->down();
        $id = DB::table('promotions')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Feria 2025', 'promo_type' => 'percentage', 'discount_pct' => 0.15,
            'starts_on' => '2025-03-10', 'ends_on' => '2025-03-23', 'created_at' => '2025-02-01 10:00:00+00', 'updated_at' => '2025-02-20 10:00:00+00']);

        $addKnownAt->up();
        $revisions->up();

        $this->assertEquals(CarbonImmutable::parse('2025-02-01 10:00:00+00'), CarbonImmutable::parse(DB::table('promotions')->where('id', $id)->value('known_at')));
        $rev = $this->revisions($id);
        $this->assertCount(1, $rev);
        $this->assertSame([1, 'migration', false, '0.150'], [(int) $rev[0]->revision, $rev[0]->source, $rev[0]->deleted, $rev[0]->discount_pct]);
        $this->assertEquals(CarbonImmutable::parse('2025-02-20 10:00:00+00'), CarbonImmutable::parse($rev[0]->recorded_at));
    }

    // ───────────────────────── modelo y observer ─────────────────────────

    public function test_model_defaults_known_at_to_creation_and_records_every_change(): void
    {
        $promotion = $this->withinTenant($this->tenant, fn () => Promotion::query()->create(['name' => 'Feria', 'discount_pct' => 0.1, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-20']))->refresh();
        $this->assertTrue($promotion->known_at->equalTo($promotion->created_at));
        $this->assertCount(1, $this->revisions($promotion->id));

        $this->travel(2)->days();
        $this->withinTenant($this->tenant, fn () => $promotion->fill(['discount_pct' => 0.25])->save());
        $this->withinTenant($this->tenant, fn () => $promotion->touch()); // sin cambio versionado: no crea revisión
        $this->withinTenant($this->tenant, fn () => $promotion->delete());
        $this->assertNotNull(DB::table('promotions')->where('id', $promotion->id)->value('deleted_at'), 'La baja es lógica');
        $this->withinTenant($this->tenant, fn () => $promotion->restore());

        $rev = $this->revisions($promotion->id);
        $this->assertSame([1, 2, 3, 4], array_map(fn ($r) => (int) $r->revision, $rev));
        $this->assertSame(['0.100', '0.250', '0.250', '0.250'], array_column($rev, 'discount_pct'));
        $this->assertSame([false, false, true, false], array_column($rev, 'deleted'));
        $this->assertSame(['api'], array_values(array_unique(array_column($rev, 'source'))));
        $this->assertTrue(CarbonImmutable::parse($rev[1]->recorded_at)->greaterThan(CarbonImmutable::parse($rev[0]->recorded_at)));
        // Todas conservan el known_at original (lo que se sabía desde el alta).
        $this->assertCount(1, array_unique(array_column($rev, 'known_at')));

        // Borrado definitivo: el historial se elimina en cascada.
        $this->withinTenant($this->tenant, fn () => $promotion->forceDelete());
        $this->assertSame([], $this->revisions($promotion->id));
    }

    // ───────────────────────── API ─────────────────────────

    public function test_api_accepts_known_at_in_tenant_timezone_and_rejects_future(): void
    {
        $created = $this->postJson('/api/v1/promotions', ['name' => 'Black Friday', 'discount_pct' => 0.2, 'starts_on' => '2026-11-27', 'ends_on' => '2026-11-30', 'known_at' => '2026-10-05'])
            ->assertCreated()->assertJsonPath('data.known_at', '2026-10-05T05:00:00+00:00'); // medianoche en Guayaquil
        $this->assertSame('2026-10-05 05:00:00+00', DB::table('promotions')->where('id', $created->json('data.id'))->value('known_at'));

        $this->postJson('/api/v1/promotions', ['name' => 'Futura', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'known_at' => '2026-10-29'])
            ->assertUnprocessable()->assertJsonValidationErrors('known_at');
        $this->postJson('/api/v1/promotions', ['name' => 'Futura', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'known_at' => 'ayer'])
            ->assertUnprocessable()->assertJsonValidationErrors('known_at');
        $this->patchJson('/api/v1/promotions/'.$created->json('data.id'), ['known_at' => '2026-11-01T00:00:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('known_at');

        $default = $this->postJson('/api/v1/promotions', ['name' => 'Sin fecha', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02'])->assertCreated();
        $this->assertSame('2026-10-28T15:00:00+00:00', $default->json('data.known_at'));
    }

    public function test_api_create_edit_delete_create_revisions_and_history_is_tenant_isolated(): void
    {
        $id = $this->postJson('/api/v1/promotions', ['name' => 'Feria', 'discount_pct' => 0.15, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-20'])->assertCreated()->json('data.id');
        $this->travel(1)->days();
        $this->patchJson("/api/v1/promotions/{$id}", ['discount_pct' => 0.3, 'ends_on' => '2026-11-25'])->assertOk()->assertJsonPath('data.discount_pct', 0.3);
        $this->patchJson("/api/v1/promotions/{$id}", ['discount_pct' => 0.3])->assertOk(); // sin cambios: no versiona
        $this->getJson("/api/v1/promotions/{$id}/revisions")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.discount_pct', 0.15)->assertJsonPath('data.0.ends_on', '2026-11-20')
            ->assertJsonPath('data.1.discount_pct', 0.3)->assertJsonPath('data.1.ends_on', '2026-11-25')
            ->assertJsonPath('data.1.recorded_at', '2026-10-29T15:00:00+00:00');

        $this->deleteJson("/api/v1/promotions/{$id}")->assertNoContent();
        $this->getJson("/api/v1/promotions/{$id}")->assertNotFound();
        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/promotions/{$id}/revisions")->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.deleted', true)->assertJsonPath('data.2.source', 'api');

        // Otra empresa no ve la promoción ni su historial, y sus escrituras no tocan las revisiones de A.
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($ownerB, $tenantB);
        $this->getJson("/api/v1/promotions/{$id}/revisions")->assertNotFound();
        $this->patchJson("/api/v1/promotions/{$id}", ['discount_pct' => 0.9])->assertNotFound();
        $idB = $this->postJson('/api/v1/promotions', ['name' => 'Feria', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-20'])->assertCreated()->json('data.id');
        $this->assertSame(1, DB::table('promotion_revisions')->where('tenant_id', $tenantB->id)->count());
        $this->assertSame(3, DB::table('promotion_revisions')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame([$tenantB->id], $this->withinTenant($tenantB, fn () => PromotionRevision::query()->pluck('tenant_id')->unique()->values()->all()));
        $this->assertSame($idB, (int) DB::table('promotion_revisions')->where('tenant_id', $tenantB->id)->value('promotion_id'));
    }

    // ───────────────────────── importación ─────────────────────────

    public function test_import_accepts_known_at_synonym_rejects_future_and_versions_changes(): void
    {
        $this->postJson('/api/v1/product-categories', ['name' => 'Pinturas'])->assertCreated();
        $headers = ['Nombre', 'Descuento', 'Desde', 'Hasta', 'Categoría', 'Fecha de registro'];
        $rows = [['Feria 2026', '15%', '2026-03-09', '2026-03-22', 'Pinturas', '2026-02-17'], ['Liquidación', '0.3', '2026-02-01', '2026-03-31', '', '']];

        $future = $this->uploadAndValidate($this->tenant, $this->csv($headers, [['Futura', '10', '2026-12-01', '2026-12-05', '', '2026-11-01']]), 'promotions', filename: 'futura.csv');
        $this->assertSame(1, $future->stats['rows_error']);
        $this->assertSame('future_date', DB::table('import_errors')->where('import_job_id', $future->id)->value('error_code'));
        $this->assertSame('known_at', DB::table('import_errors')->where('import_job_id', $future->id)->value('column'));

        $job = $this->importAndConfirm($headers, $rows);
        $this->assertSame('Fecha de registro', $job->column_mapping['known_at'] ?? null);
        $feria = DB::table('promotions')->where('name', 'Feria 2026')->first();
        $this->assertSame('2026-02-17 05:00:00+00', $feria->known_at);
        $liquidacion = DB::table('promotions')->where('name', 'Liquidación')->first();
        $this->assertSame('2026-10-28 15:00:00+00', $liquidacion->known_at, 'Sin fecha: momento de la importación');
        $this->assertSame('import', DB::table('promotion_revisions')->where('promotion_id', $feria->id)->value('source'));

        // Reimportar lo mismo: idempotente, sin revisiones nuevas.
        $this->travel(3)->days();
        $again = $this->importAndConfirm($headers, $rows);
        $this->assertSame(2, $this->getJson("/api/v1/imports/{$again->id}")->json('data.stats.promotions_unchanged'));
        $this->assertSame(2, DB::table('promotions')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, DB::table('promotion_revisions')->where('tenant_id', $this->tenant->id)->count());

        // Cambio de descuento sin known_at: nueva versión registrada hoy, known_at original intacto.
        $rows[0][1] = '20%';
        $rows[0][5] = '';
        $changed = $this->importAndConfirm($headers, $rows);
        $this->assertSame(1, $this->getJson("/api/v1/imports/{$changed->id}")->json('data.stats.promotions_updated'));
        $rev = $this->revisions($feria->id);
        $this->assertSame(['0.150', '0.200'], array_column($rev, 'discount_pct'));
        $this->assertSame('2026-10-31 15:00:00+00', $rev[1]->recorded_at);
        $this->assertSame('2026-02-17 05:00:00+00', DB::table('promotions')->where('id', $feria->id)->value('known_at'));
    }

    public function test_demo_promotions_file_imports_with_its_known_at_column(): void
    {
        $path = rtrim((string) config('predictily.demo.path'), '/').'/ferreteria_andina_promotions.csv';
        $this->assertFileExists($path);
        $this->createBranch($this->tenant, '001');
        foreach (['Pinturas', 'Herramientas eléctricas'] as $category) {
            $this->postJson('/api/v1/product-categories', ['name' => $category])->assertCreated();
        }
        foreach (['FA-CEM-001', 'FA-ELE-010'] as $sku) {
            $this->postJson('/api/v1/products', ['sku' => $sku, 'name' => $sku])->assertCreated();
        }
        $job = $this->uploadAndValidate($this->tenant, (string) file_get_contents($path), 'promotions', filename: 'ferreteria_andina_promotions.csv');
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->assertSame('known_at', $job->column_mapping['known_at'] ?? null);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $csv = array_map('str_getcsv', array_slice(file($path, FILE_IGNORE_NEW_LINES), 1));
        $this->assertSame(count($csv), DB::table('promotions')->where('tenant_id', $this->tenant->id)->count());
        foreach ($csv as $row) {
            $stored = DB::table('promotions')->where('tenant_id', $this->tenant->id)->where('name', $row[0])->value('known_at');
            $this->assertSame($row[8], CarbonImmutable::parse($stored)->setTimezone('America/Guayaquil')->toDateString(), $row[0]);
        }
    }
}
