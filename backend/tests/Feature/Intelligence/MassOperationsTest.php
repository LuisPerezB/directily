<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\ExternalEvent;
use App\Models\FactorApplicabilityRule;
use App\Models\FactorDefinition;
use App\Models\SharedForecastGrant;
use App\Models\SharedForecastGrantAccess;
use App\Models\SourceVersion;
use App\Models\TaxonomyNode;
use App\Models\Tenant;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\PlatformWrite;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/**
 * Operaciones MASIVAS del builder (update, delete, forceDelete, increment, decrement, touch, insert, upsert…): no disparan los eventos de modelo, así que el
 * builder propio (TenantOrPublicBuilder / PartyOwnedBuilder / AppendOnlyBuilder) les aplica las mismas reglas. Desde una empresa, el catálogo público nunca se
 * modifica, crea ni borra «en masa»; la bitácora de accesos es de solo inserción; y solo el emisor escribe sus concesiones.
 */
final class MassOperationsTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactorCatalog();
        [$this->a] = $this->createTenantWithUser();
        [$this->b] = $this->createTenantWithUser();
    }

    /** Fila pública de cada modelo con catálogo público, insertada sin pasar por los modelos. @return array<class-string, int> */
    private function seedPublicRows(): array
    {
        $now = now();
        $factor = (int) DB::table('factor_definitions')->whereNull('tenant_id')->where('key', 'weather.observed')->value('id');

        return [
            FactorDefinition::class => $factor,
            SourceVersion::class => (int) DB::table('source_versions')->insertGetId(['tenant_id' => null, 'source_key' => 'oficial', 'version_label' => 'v1', 'license' => 'Datos abiertos', 'created_at' => $now, 'updated_at' => $now]),
            ExternalEvent::class => (int) DB::table('external_events')->insertGetId(['tenant_id' => null, 'family' => 'weather', 'event_type' => 'storm', 'title' => 'Público', 'status' => 'VERIFIED', 'created_at' => $now, 'updated_at' => $now]),
            TaxonomyNode::class => (int) DB::table('taxonomy_nodes')->insertGetId(['tenant_id' => null, 'level' => 'sector', 'code' => 'publico', 'name' => 'Público', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]),
            FactorApplicabilityRule::class => (int) DB::table('factor_applicability_rules')->insertGetId(['tenant_id' => null, 'factor_definition_id' => $factor, 'justification' => 'Pública', 'created_at' => $now, 'updated_at' => $now]),
        ];
    }

    /** Columna de texto que se intenta alterar en cada modelo. */
    private const TEXT = [
        FactorDefinition::class => 'name', SourceVersion::class => 'license', ExternalEvent::class => 'title', TaxonomyNode::class => 'name', FactorApplicabilityRule::class => 'justification',
    ];

    public function test_a_company_cannot_mass_update_delete_or_touch_public_rows(): void
    {
        $ids = $this->seedPublicRows();
        $before = [];
        foreach ($ids as $model => $id) {
            $before[$model] = (array) DB::table((new $model)->getTable())->find($id);
        }

        $this->withinTenant($this->a, function () use ($ids): void {
            foreach ($ids as $model => $id) {
                $column = self::TEXT[$model];
                $this->assertSame(0, $model::query()->whereKey($id)->update([$column => 'HACK']), "update masivo sobre {$model}");
                $this->assertSame(0, $model::query()->whereKey($id)->touch(), "touch masivo sobre {$model}");
                $this->assertSame(0, $model::query()->whereKey($id)->delete(), "delete masivo sobre {$model}");
                $this->assertSame(0, $model::query()->whereKey($id)->forceDelete(), "forceDelete masivo sobre {$model}");
                // Sin filtro alguno: tampoco alcanza lo público (el builder restringe a la empresa).
                $this->assertSame(0, $model::query()->update([$column => 'HACK-TODO']), "update masivo sin filtro sobre {$model}");
                $this->assertSame(0, $model::query()->delete(), "delete masivo sin filtro sobre {$model}");
            }
            // increment / decrement sobre una columna numérica de una definición pública.
            $factor = $ids[FactorDefinition::class];
            $this->assertSame(0, FactorDefinition::query()->whereKey($factor)->increment('retention_days', 5));
            $this->assertSame(0, FactorDefinition::query()->whereKey($factor)->decrement('retention_days', 5));
            $this->assertSame(0, FactorDefinition::query()->whereKey($factor)->incrementEach(['retention_days' => 5]));
            $this->assertSame(0, FactorDefinition::query()->whereKey($factor)->decrementEach(['retention_days' => 5]));
            $this->assertSame(0, TaxonomyNode::query()->whereKey($ids[TaxonomyNode::class])->increment('version'));
        });

        foreach ($ids as $model => $id) {
            $this->assertSame($before[$model], (array) DB::table((new $model)->getTable())->find($id), "{$model}: la fila pública quedó intacta");
        }
        $this->assertSame(0, DB::table('factor_definitions')->where('is_active', true)->count());
    }

    public function test_a_company_can_still_mass_update_and_delete_its_own_rows_and_only_them(): void
    {
        $now = now();
        $mine = (int) DB::table('taxonomy_nodes')->insertGetId(['tenant_id' => $this->a->id, 'level' => 'sector', 'code' => 'mio', 'name' => 'Mío', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $theirs = (int) DB::table('taxonomy_nodes')->insertGetId(['tenant_id' => $this->b->id, 'level' => 'sector', 'code' => 'suyo', 'name' => 'Suyo', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $public = $this->seedPublicRows()[TaxonomyNode::class];

        $this->withinTenant($this->a, function () use ($mine): void {
            $this->assertSame(1, TaxonomyNode::query()->update(['name' => 'Renombrado en masa']), 'solo cambia la fila de la empresa (no la pública ni la ajena)');
            $this->assertSame(1, TaxonomyNode::query()->whereKey($mine)->increment('version'));
        });

        $this->assertSame('Renombrado en masa', DB::table('taxonomy_nodes')->where('id', $mine)->value('name'));
        $this->assertSame(2, (int) DB::table('taxonomy_nodes')->where('id', $mine)->value('version'));
        $this->assertSame('Suyo', DB::table('taxonomy_nodes')->where('id', $theirs)->value('name'));
        $this->assertSame('Público', DB::table('taxonomy_nodes')->where('id', $public)->value('name'));
        $this->withinTenant($this->a, fn () => $this->assertSame(1, TaxonomyNode::query()->delete()));
        $this->assertSame(0, DB::table('taxonomy_nodes')->where('id', $mine)->count());
        $this->assertSame(1, DB::table('taxonomy_nodes')->where('id', $theirs)->count());
        $this->assertSame(1, DB::table('taxonomy_nodes')->where('id', $public)->count());
    }

    public function test_a_company_cannot_mass_insert_or_upsert_public_rows_or_rows_of_another_company(): void
    {
        $ids = $this->seedPublicRows();
        $now = now();
        $factor = ['family' => 'weather', 'key' => 'weather.inyectada', 'name' => 'Inyectada', 'availability_class' => 'STATIC', 'storage' => 'sales', 'created_at' => $now, 'updated_at' => $now];

        $this->withinTenant($this->a, function () use ($factor, $ids, $now): void {
            // insert con tenant_id NULL (fila pública), sin tenant_id o con el de otra empresa.
            foreach ([['tenant_id' => null], [], ['tenant_id' => $this->b->id]] as $i => $override) {
                foreach (['insert', 'insertGetId', 'insertOrIgnore'] as $method) {
                    try {
                        FactorDefinition::query()->{$method}(['key' => "weather.inyectada.{$i}"] + $override + $factor);
                        $this->fail("{$method} con ".json_encode($override).' debía rechazarse.');
                    } catch (PublicCatalogReadOnlyException|TenantNotResolvedException) {
                        $this->assertTrue(true);
                    }
                }
            }
            // insert de varias filas donde UNA es pública: se rechaza todo el lote.
            try {
                FactorDefinition::query()->insert([
                    ['tenant_id' => $this->a->id, 'key' => 'weather.ok'] + $factor,
                    ['tenant_id' => null, 'key' => 'weather.publica'] + $factor,
                ]);
                $this->fail('Un lote con una fila pública debe rechazarse.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // upsert que cae por id sobre una fila PÚBLICA: la pisaría.
            try {
                FactorDefinition::query()->upsert([['id' => $ids[FactorDefinition::class], 'tenant_id' => $this->a->id] + $factor + ['key' => 'weather.pisada']], ['id'], ['name']);
                $this->fail('Un upsert no puede pisar una fila pública.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // upsert que reasigna la empresa de una fila.
            try {
                FactorDefinition::query()->upsert([['id' => 999999, 'tenant_id' => $this->a->id, 'key' => 'weather.reasigna'] + $factor], ['id'], ['tenant_id', 'name']);
                $this->fail('Un upsert no puede reasignar tenant_id.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // upsert con tenant_id de otra empresa / NULL.
            foreach ([null, $this->b->id] as $tenantId) {
                try {
                    TaxonomyNode::query()->upsert([['tenant_id' => $tenantId, 'level' => 'sector', 'code' => 'x', 'name' => 'x', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]], ['id'], ['name']);
                    $this->fail('Un upsert de filas ajenas o públicas debe rechazarse.');
                } catch (PublicCatalogReadOnlyException|TenantNotResolvedException) {
                    $this->assertTrue(true);
                }
            }
            // Métodos de escritura que se saltan los filtros: no se admiten.
            foreach (['truncate' => [], 'updateOrInsert' => [['key' => 'k'], ['name' => 'n']]] as $method => $args) {
                try {
                    FactorDefinition::query()->{$method}(...$args);
                    $this->fail("{$method} debía rechazarse.");
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        });

        $this->assertSame(0, DB::table('factor_definitions')->where('key', 'like', 'weather.inyectada%')->count());
        $this->assertSame(0, DB::table('factor_definitions')->whereIn('key', ['weather.ok', 'weather.publica', 'weather.pisada', 'weather.reasigna'])->count());
        $this->assertNull(DB::table('factor_definitions')->where('id', $ids[FactorDefinition::class])->value('tenant_id'), 'La definición pública sigue siendo pública.');
        $this->assertSame(0, DB::table('taxonomy_nodes')->where('code', 'x')->count());
        // Y el catálogo sigue completo: nada se truncó.
        $this->assertGreaterThanOrEqual(20, DB::table('factor_definitions')->whereNull('tenant_id')->count());
    }

    public function test_a_company_can_mass_insert_and_upsert_its_own_rows(): void
    {
        $now = now();
        $row = fn (string $code, string $name) => ['tenant_id' => $this->a->id, 'level' => 'sector', 'code' => $code, 'name' => $name, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];

        $this->withinTenant($this->a, function () use ($row): void {
            $this->assertTrue(TaxonomyNode::query()->insert([$row('a1', 'Uno'), $row('a2', 'Dos')]));
            $id = (int) TaxonomyNode::query()->insertGetId($row('a3', 'Tres'));
            // upsert sobre una fila PROPIA: permitido.
            TaxonomyNode::query()->upsert([['id' => $id] + $row('a3', 'Tres renombrado')], ['id'], ['name']);
        });

        $this->assertSame(3, DB::table('taxonomy_nodes')->where('tenant_id', $this->a->id)->count());
        $this->assertSame('Tres renombrado', DB::table('taxonomy_nodes')->where('tenant_id', $this->a->id)->where('code', 'a3')->value('name'));
    }

    public function test_the_platform_owner_can_mass_write_public_rows_only_inside_platform_write(): void
    {
        $ids = $this->seedPublicRows();

        // Sin empresa y sin PlatformWrite: la consulta sin scope tampoco alcanza lo público.
        $this->assertSame(0, FactorDefinition::withoutTenantScope()->whereKey($ids[FactorDefinition::class])->update(['name' => 'Mantenimiento']));
        $this->assertSame(0, FactorDefinition::withoutTenantScope()->whereKey($ids[FactorDefinition::class])->delete());
        try {
            FactorDefinition::withoutTenantScope()->insert(['tenant_id' => null, 'family' => 'weather', 'key' => 'weather.sin-plataforma', 'name' => 'x', 'availability_class' => 'STATIC', 'storage' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Sin PlatformWrite no se crean filas públicas.');
        } catch (TenantNotResolvedException) {
            $this->assertTrue(true);
        }

        // Dentro de PlatformWrite sí.
        PlatformWrite::run(function () use ($ids): void {
            $this->assertSame(1, FactorDefinition::withoutTenantScope()->whereKey($ids[FactorDefinition::class])->update(['name' => 'Renombrada por la plataforma']));
            FactorDefinition::withoutTenantScope()->insert(['tenant_id' => null, 'family' => 'weather', 'key' => 'weather.de-plataforma', 'name' => 'x', 'availability_class' => 'STATIC', 'storage' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->assertSame('Renombrada por la plataforma', DB::table('factor_definitions')->where('id', $ids[FactorDefinition::class])->value('name'));
        $this->assertSame(1, DB::table('factor_definitions')->where('key', 'weather.de-plataforma')->count());
    }

    // ── Bitácora de accesos: solo inserción, también en masa ──

    private function seedGrantWithAccesses(): array
    {
        $now = now();
        $grant = (int) DB::table('shared_forecast_grants')->insertGetId([
            'issuer_tenant_id' => $this->a->id, 'recipient_tenant_id' => $this->b->id, 'scope' => 'product', 'subjects' => '[]', 'period_from' => '2026-11-01', 'period_to' => '2026-11-05',
            'granularity' => 'day', 'allowed_content' => '["point_forecast"]', 'issued_at' => $now, 'expires_at' => $now->copy()->addDays(10), 'purpose' => 'Prueba de operaciones masivas',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $access = (int) DB::table('shared_forecast_grant_accesses')->insertGetId(['grant_id' => $grant, 'accessed_by_tenant_id' => $this->b->id, 'action' => 'read', 'accessed_at' => $now]);

        return [$grant, $access];
    }

    public function test_the_access_log_cannot_be_changed_or_deleted_in_bulk_by_either_party(): void
    {
        [$grant, $access] = $this->seedGrantWithAccesses();

        foreach ([$this->a, $this->b] as $tenant) {
            $this->withinTenant($tenant, function () use ($access): void {
                foreach ([
                    'update' => fn () => SharedForecastGrantAccess::query()->update(['action' => 'denied_scope']),
                    'delete' => fn () => SharedForecastGrantAccess::query()->delete(),
                    'forceDelete' => fn () => SharedForecastGrantAccess::query()->forceDelete(),
                    'whereKey->delete' => fn () => SharedForecastGrantAccess::query()->whereKey($access)->delete(),
                    'increment' => fn () => SharedForecastGrantAccess::query()->increment('grant_id'),
                    'decrement' => fn () => SharedForecastGrantAccess::query()->decrement('grant_id'),
                    'touch' => fn () => SharedForecastGrantAccess::query()->touch(),
                    'upsert' => fn () => SharedForecastGrantAccess::query()->upsert([['id' => $access, 'grant_id' => 1, 'accessed_by_tenant_id' => 1, 'action' => 'read']], ['id'], ['action']),
                    'truncate' => fn () => SharedForecastGrantAccess::query()->truncate(),
                    'instance update' => fn () => SharedForecastGrantAccess::query()->firstOrFail()->update(['action' => 'denied_scope']),
                    'instance delete' => fn () => SharedForecastGrantAccess::query()->firstOrFail()->delete(),
                ] as $name => $operation) {
                    try {
                        $operation();
                        $this->fail("La bitácora no admite «{$name}».");
                    } catch (LogicException) {
                        $this->assertTrue(true);
                    }
                }
            });
        }

        $this->assertSame(1, DB::table('shared_forecast_grant_accesses')->count());
        $this->assertSame('read', DB::table('shared_forecast_grant_accesses')->where('id', $access)->value('action'));
        $this->assertSame($grant, (int) DB::table('shared_forecast_grant_accesses')->where('id', $access)->value('grant_id'));
    }

    public function test_the_access_log_only_accepts_entries_in_the_name_of_the_company_that_accesses(): void
    {
        [$grant] = $this->seedGrantWithAccesses();

        $this->withinTenant($this->b, function () use ($grant): void {
            SharedForecastGrantAccess::query()->create(['grant_id' => $grant, 'accessed_by_tenant_id' => $this->b->id, 'action' => 'read', 'accessed_at' => now()]);
            // Falsificar el acceso de otra empresa (masivo o por modelo) se rechaza.
            foreach ([
                fn () => SharedForecastGrantAccess::query()->insert([['grant_id' => $grant, 'accessed_by_tenant_id' => $this->a->id, 'action' => 'read', 'accessed_at' => now()]]),
                fn () => SharedForecastGrantAccess::query()->create(['grant_id' => $grant, 'accessed_by_tenant_id' => $this->a->id, 'action' => 'read', 'accessed_at' => now()]),
            ] as $forge) {
                try {
                    $forge();
                    $this->fail('No se puede registrar un acceso a nombre de otra empresa.');
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        });

        $this->assertSame(2, DB::table('shared_forecast_grant_accesses')->count());
        $this->assertSame(0, DB::table('shared_forecast_grant_accesses')->where('accessed_by_tenant_id', $this->a->id)->count());
    }

    // ── Concesiones: la escritura masiva es solo del emisor ──

    public function test_the_recipient_cannot_bulk_change_or_delete_a_grant_it_received(): void
    {
        [$grant] = $this->seedGrantWithAccesses();
        $original = (array) DB::table('shared_forecast_grants')->find($grant);

        $this->withinTenant($this->b, function () use ($grant): void {
            $this->assertSame(1, SharedForecastGrant::query()->count(), 'El receptor la ve…');
            $this->assertSame(0, SharedForecastGrant::query()->update(['expires_at' => now()->addYears(10), 'status' => 'active']), '…pero no la modifica en masa');
            $this->assertSame(0, SharedForecastGrant::query()->whereKey($grant)->increment('id', 0));
            $this->assertSame(0, SharedForecastGrant::query()->delete());
            $this->assertSame(0, SharedForecastGrant::query()->whereKey($grant)->forceDelete());
            try {
                SharedForecastGrant::query()->insert([['issuer_tenant_id' => $this->a->id, 'recipient_tenant_id' => $this->b->id, 'scope' => 'product', 'subjects' => '[]', 'period_from' => '2026-11-01',
                    'period_to' => '2026-11-05', 'granularity' => 'day', 'allowed_content' => '[]', 'issued_at' => now(), 'expires_at' => now()->addDay(), 'purpose' => 'Falsificada', 'status' => 'active']]);
                $this->fail('El receptor no crea concesiones a nombre del emisor.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        });

        $this->assertSame($original, (array) DB::table('shared_forecast_grants')->find($grant));
        // El emisor sí puede (positivo de control).
        $this->withinTenant($this->a, fn () => $this->assertSame(1, SharedForecastGrant::query()->whereKey($grant)->update(['purpose' => 'Propósito actualizado por el emisor'])));
        $this->assertSame('Propósito actualizado por el emisor', DB::table('shared_forecast_grants')->where('id', $grant)->value('purpose'));
    }
}
