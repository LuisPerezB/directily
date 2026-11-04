<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantRls;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * V2.5 · W6-A (ADR-V25-15): RLS de PostgreSQL como defensa ADICIONAL, EXPERIMENTAL y detrás de una bandera (apagada por defecto), en las tablas de ventas. Prueba la propiedad que importa: con
 * las políticas activas, una consulta SIN contexto de empresa no devuelve filas (ni siquiera con SQL escrito a mano sin filtro), con contexto solo las de esa empresa, y no se
 * puede insertar para otra. El DDL corre dentro de la transacción de la prueba y se revierte con ella. Se omite si el rol de la base es superusuario o tiene BYPASSRLS (RLS no
 * aplica a esos roles: ver docs/architecture/TENANT_ISOLATION.md §RLS, «límites»).
 */
final class RowLevelSecurityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @var array{0: Tenant, 1: User} */
    private array $a;

    /** @var array{0: Tenant, 1: User} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $role = DB::selectOne('select rolsuper or rolbypassrls as bypass from pg_roles where rolname = current_user');
        if ($role === null || $role->bypass) {
            $this->markTestSkipped('El rol de la base es superusuario o BYPASSRLS: RLS no le aplica (ver TENANT_ISOLATION.md).');
        }
        $this->a = $this->createTenantWithUser(TenantRole::Owner);
        $this->b = $this->createTenantWithUser(TenantRole::Owner);
        $this->seedSales($this->a[0], 'A');
        $this->seedSales($this->b[0], 'B');
    }

    private function seedSales(Tenant $tenant, string $marker): void
    {
        $branch = (int) DB::table('branches')->insertGetId(['tenant_id' => $tenant->id, 'code' => '001', 'name' => "Sucursal {$marker}", 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['tenant_id' => $tenant->id, 'sku' => "SKU-{$marker}", 'name' => "Producto {$marker}", 'unit' => 'unidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sale = (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $branch, 'document_type' => 'invoice', 'document_number' => "DOC-{$marker}", 'status' => 'completed', 'issued_at' => now(),
            'sale_date' => now()->toDateString(), 'currency' => 'USD', 'total' => 10, 'source_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id, 'sale_id' => $sale, 'line_number' => 1, 'product_id' => $product, 'branch_id' => $branch, 'sale_date' => now()->toDateString(),
            'quantity' => 1, 'unit_price' => 10, 'discount_amount' => 0, 'net_amount' => 10, 'tax_amount' => 0, 'is_return' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enable(): TenantRls
    {
        config(['security.rls.enabled' => true]);
        $rls = app(TenantRls::class);
        $rls->enable();

        return $rls;
    }

    /** Cuántas filas EXISTEN, vistas sin RLS: se quitan las políticas DENTRO de la transacción de la prueba, cuenta y se vuelven a crear. No hay puerta de plataforma en la sesión. */
    private function existing(TenantRls $rls, string $table): int
    {
        $rls->disable();
        $n = $this->rows($table);
        $rls->enable();

        return $n;
    }

    private function rows(string $table): int
    {
        return (int) DB::selectOne("select count(*) as n from {$table}")->n;   // SQL escrito a mano, SIN filtro de empresa
    }

    public function test_it_is_off_by_default_and_changes_nothing(): void
    {
        $this->assertFalse(TenantRls::enabled());
        foreach (app(TenantRls::class)->status() as $table => $state) {
            $this->assertSame(['rls' => false, 'forced' => false, 'policy' => false], $state, $table);
        }
        // Sin la bandera el contexto de empresa no toca la conexión: todas las filas siguen siendo legibles a mano.
        $this->assertSame(2, $this->rows('sales'));
        app(TenantContext::class)->runAs($this->a[0], fn () => null);
        $this->assertSame(2, $this->rows('sales'));
        $this->assertNull(DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as v")->v);
    }

    public function test_with_the_policies_active_a_query_without_company_context_returns_no_rows(): void
    {
        $rls = $this->enable();
        foreach ($rls->tables() as $table) {
            $this->assertSame(0, $this->rows($table), "{$table}: sin empresa en el contexto no hay filas (aunque el SQL no filtre)");
        }
        // Las filas existen (se ven al quitar las políticas); con ellas puestas siguen invisibles.
        $this->assertSame(2, $this->existing($rls, 'sales'));
        $this->assertSame(0, $this->rows('sales'));
        $this->assertSame(['sales', 'sale_items', 'sales_document_revisions', 'sale_item_revisions'], $rls->tables());
        foreach ($rls->status() as $table => $state) {
            $this->assertSame(['rls' => true, 'forced' => true, 'policy' => true], $state, $table);
        }
    }

    public function test_with_a_company_context_only_that_companys_rows_are_visible(): void
    {
        $this->enable();
        $context = app(TenantContext::class);

        $context->runAs($this->a[0], function (): void {
            $this->assertSame(1, $this->rows('sales'));
            $this->assertSame(1, $this->rows('sale_items'));
            $this->assertSame(['DOC-A'], DB::table('sales')->pluck('document_number')->all());
            $this->assertSame(['DOC-A'], Sale::query()->pluck('document_number')->all(), 'el scope de la aplicación y RLS coinciden');
            // Una consulta escrita SIN el filtro de empresa (el error que RLS debe atrapar) tampoco ve a B.
            $this->assertSame(0, (int) DB::selectOne("select count(*) as n from sales where document_number = 'DOC-B'")->n);
        });
        $context->runAs($this->b[0], fn () => $this->assertSame(['DOC-B'], DB::table('sales')->pluck('document_number')->all()));
        $this->assertSame(0, $this->rows('sales'), 'al salir del contexto vuelve a no haber filas');

        // Anidado: la empresa interior manda y la exterior se restablece.
        $context->runAs($this->a[0], function () use ($context): void {
            $context->runAs($this->b[0], fn () => $this->assertSame(['DOC-B'], DB::table('sales')->pluck('document_number')->all()));
            $this->assertSame(['DOC-A'], DB::table('sales')->pluck('document_number')->all());
        });
    }

    public function test_rows_cannot_be_inserted_without_context_or_for_another_company(): void
    {
        $this->enable();
        $row = fn (Tenant $t): array => [
            'tenant_id' => $t->id, 'branch_id' => (int) DB::table('branches')->where('tenant_id', $t->id)->value('id'), 'document_type' => 'invoice', 'document_number' => 'DOC-NUEVO', 'status' => 'completed',
            'issued_at' => now(), 'sale_date' => now()->toDateString(), 'currency' => 'USD', 'total' => 1, 'source_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ];
        $attempt = function (callable $insert): bool {
            try {
                DB::transaction($insert);   // savepoint: una violación no aborta la transacción de la prueba

                return true;
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());

                return false;
            }
        };

        // Sin empresa: ni siquiera para una empresa existente.
        $this->assertFalse($attempt(fn () => DB::table('sales')->insert($row($this->a[0]))));
        // Con la empresa A activa, un registro de B se rechaza en la base (aunque la aplicación lo permitiera por un error).
        app(TenantContext::class)->runAs($this->a[0], function () use ($attempt, $row): void {
            $this->assertFalse($attempt(fn () => DB::table('sales')->insert($row($this->b[0]))));
            $this->assertTrue($attempt(fn () => DB::table('sales')->insert($row($this->a[0]))), 'lo propio sí');
            // UPDATE de una fila ajena: no la ve, así que no la toca.
            $this->assertSame(0, DB::table('sales')->where('document_number', 'DOC-B')->update(['total' => 999]));
        });
        $rls = app(TenantRls::class);
        $rls->disable();
        $this->assertSame(10.0, (float) DB::table('sales')->where('document_number', 'DOC-B')->value('total'), 'la fila ajena no se tocó');
    }

    public function test_the_http_request_sets_and_clears_the_company_on_the_connection(): void
    {
        $bSale = (int) DB::table('sales')->where('document_number', 'DOC-B')->value('id');   // leído ANTES de activar las políticas
        $this->enable();

        $this->actingAsTenantUser($this->a[1], $this->a[0])->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'DOC-A');
        // Terminada la petición, la conexión no conserva la empresa (un trabajo siguiente en el mismo proceso no la hereda).
        $this->assertNull(DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as v")->v);
        $this->assertSame(0, $this->rows('sales'));
        $this->actingAsTenantUser($this->b[1], $this->b[0])->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'DOC-B');
        // El detalle de la venta de B pedido con la empresa A: 404 también por RLS.
        $this->actingAsTenantUser($this->a[1], $this->a[0])->getJson("/api/v1/sales/{$bSale}")->assertNotFound();
    }

    public function test_no_session_setting_can_lift_the_policy(): void
    {
        $this->enable();

        // El propio rol de la aplicación puede fijar cualquier parámetro de sesión: ninguno puede abrir la política (no hay «puerta de plataforma» en la sesión).
        foreach (['app.rls_bypass' => 'on', 'app.bypass_rls' => 'on', 'app.tenant_id' => ''] as $name => $value) {
            DB::select('select set_config(?, ?, false)', [$name, $value]);
            $this->assertSame(0, $this->rows('sales'), "{$name}={$value} no abre la política");
            $this->assertSame(0, $this->rows('sale_items'));
        }
        // Y la política no menciona ninguna puerta.
        $definition = (string) DB::selectOne("select pg_get_expr(polqual, polrelid) as q from pg_policy where polname = ? and polrelid = 'sales'::regclass", [TenantRls::POLICY])->q;
        $this->assertStringContainsString('app.tenant_id', $definition);
        $this->assertStringNotContainsString('bypass', $definition);
    }

    public function test_the_company_is_reasserted_after_a_rollback_reverts_the_set(): void
    {
        $this->enable();
        $context = app(TenantContext::class);
        $context->set($this->a[0]);
        $this->assertSame(['DOC-A'], DB::table('sales')->pluck('document_number')->all());

        // Dentro de una transacción se cambia a B y falla: el rollback deshace el SET de la conexión (volvería a A mientras la aplicación cree B).
        try {
            DB::transaction(function () use ($context): void {
                $context->set($this->b[0]);
                throw new \RuntimeException('fallo a mitad de la transacción');
            });
        } catch (\RuntimeException) {
        }

        // Tras el rollback la conexión y el contexto de la aplicación coinciden de nuevo (la empresa vigente del contexto es B).
        $this->assertSame($this->b[0]->id, $context->id());
        $this->assertSame((string) $this->b[0]->id, (string) DB::selectOne("select current_setting('app.tenant_id', true) as v")->v, 'la conexión se volvió a alinear con el contexto');
        $this->assertSame(['DOC-B'], DB::table('sales')->pluck('document_number')->all());
        $context->forget();
        $this->assertSame(0, $this->rows('sales'));
    }

    public function test_run_as_does_not_mask_the_original_error_on_an_aborted_transaction(): void
    {
        $this->enable();
        $context = app(TenantContext::class);

        try {
            DB::transaction(fn () => $context->runAs($this->a[0], function (): void {
                DB::select('select * from tabla_que_no_existe_w6a');   // el error SQL aborta la transacción
            }));
            $this->fail('Debió propagarse el error original.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('tabla_que_no_existe_w6a', $e->getMessage(), 'se ve el error ORIGINAL, no «current transaction is aborted»');
            $this->assertStringNotContainsString('current transaction is aborted', $e->getMessage());
        }
        // El `finally` de runAs no pudo limpiar la conexión dentro de la transacción abortada; el rollback la realinea: sin empresa en el contexto no hay filas.
        $this->assertFalse($context->has());
        $this->assertSame(0, $this->rows('sales'));
        $this->assertNull(DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as v")->v);
    }

    public function test_the_flag_defaults_to_off(): void
    {
        // RLS es experimental: apagado por defecto. Se comprueba el valor efectivo y el valor por defecto ESCRITO en el archivo de configuración (sin variable de entorno).
        $this->assertFalse(TenantRls::enabled());
        $this->assertFalse(config('security.rls.enabled'));
        $source = (string) file_get_contents(base_path('config/security.php'));
        $this->assertStringContainsString("filter_var(env('PREDICTILY_RLS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)", $source, 'el valor por defecto en el código es false');
        $this->assertStringNotContainsString("env('PREDICTILY_RLS_ENABLED', true)", $source);
    }

    public function test_disabling_restores_the_previous_behaviour(): void
    {
        $rls = $this->enable();
        $this->assertSame(0, $this->rows('sales'));

        $rls->disable();
        foreach ($rls->status() as $table => $state) {
            $this->assertSame(['rls' => false, 'forced' => false, 'policy' => false], $state, $table);
        }
        $this->assertSame(2, $this->rows('sales'));
        // Idempotente.
        $rls->disable();
        $rls->enable();
        $rls->enable();
        $this->assertSame(0, $this->rows('sales'));
    }

    public function test_the_command_reports_status_and_refuses_to_enable_with_the_application_flag_off(): void
    {
        $this->artisan('predictily:rls', ['action' => 'enable'])->assertFailed();
        $this->assertFalse(app(TenantRls::class)->status()['sales']['rls'], 'no se activó nada');

        $this->artisan('predictily:rls', ['action' => 'status'])->assertSuccessful();
        $this->artisan('predictily:rls', ['action' => 'borrar'])->assertFailed();

        config(['security.rls.enabled' => true]);
        $this->artisan('predictily:rls', ['action' => 'enable'])->assertSuccessful();
        $this->assertTrue(app(TenantRls::class)->status()['sales']['forced']);
        $this->artisan('predictily:rls', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse(app(TenantRls::class)->status()['sales']['rls']);
    }
}
