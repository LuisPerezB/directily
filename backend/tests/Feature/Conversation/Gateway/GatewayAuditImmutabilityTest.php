<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway;

use App\Models\GatewayToolCall;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\Gateway\Audit\AuditRetention;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Conversation\Gateway\Support\GatewayWorld;
use Tests\TestCase;

/**
 * V2.5 · W6-D (revisión M-4): la auditoría del gateway es de solo inserción TAMBIÉN para la base de datos, no solo para el modelo: el disparador de PostgreSQL rechaza el UPDATE y el
 * DELETE directos (constructor de consultas, DB::table, SQL), permite la cascada del borrado de la empresa y de la persona (SET NULL de user_id) y la ruta explícita de retención.
 * Límite documentado: la variable de la ruta de retención la puede fijar cualquier código con acceso a la base de datos.
 */
class GatewayAuditImmutabilityTest extends TestCase
{
    use GatewayWorld;
    use RefreshDatabase;

    /** @return array{tenant: Tenant, user: User, id: int} */
    private function withRow(): array
    {
        $c = $this->radarCompany();
        $this->gateway()->call('decisions_summary', [], $this->ctx($c['tenant'], $c['user']));
        $id = (int) DB::table('gateway_tool_calls')->where('tenant_id', $c['tenant']->id)->value('id');

        return ['tenant' => $c['tenant'], 'user' => $c['user'], 'id' => $id];
    }

    /** Ejecuta una sentencia que la base de datos debe rechazar; el SAVEPOINT evita dejar abortada la transacción de la prueba. */
    private function assertRefused(Closure $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('La base de datos debía rechazar la sentencia.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('solo inserción', $e->getMessage());
        }
    }

    public function test_a_direct_update_is_refused_by_the_database(): void
    {
        $w = $this->withRow();

        $this->assertRefused(fn () => DB::table('gateway_tool_calls')->where('id', $w['id'])->update(['decision' => 'allowed', 'tool' => 'otra']));
        $this->assertRefused(fn () => DB::statement('UPDATE gateway_tool_calls SET latency_ms = 0 WHERE id = ?', [$w['id']]));
        // Poner user_id en NULL fuera de la cascada de borrado de la persona tampoco se admite (sería borrar el rastro de quién consultó).
        $this->assertRefused(fn () => DB::table('gateway_tool_calls')->where('id', $w['id'])->update(['user_id' => null]));
        $this->assertSame('decisions_summary', DB::table('gateway_tool_calls')->where('id', $w['id'])->value('tool'));
    }

    public function test_a_direct_delete_is_refused_by_the_database(): void
    {
        $w = $this->withRow();

        $this->assertRefused(fn () => DB::table('gateway_tool_calls')->where('id', $w['id'])->delete());
        $this->assertRefused(fn () => DB::statement('DELETE FROM gateway_tool_calls WHERE tenant_id = ?', [$w['tenant']->id]));
        $this->assertSame(1, DB::table('gateway_tool_calls')->where('id', $w['id'])->count());
    }

    public function test_the_eloquent_query_builder_has_no_mass_update_or_delete(): void
    {
        $w = $this->withRow();

        $this->withinTenant($w['tenant'], function () use ($w): void {
            foreach ([
                fn () => GatewayToolCall::query()->update(['decision' => 'allowed']),
                fn () => GatewayToolCall::query()->where('id', $w['id'])->delete(),
                fn () => GatewayToolCall::query()->forceDelete(),
                fn () => GatewayToolCall::query()->increment('latency_ms'),
                fn () => GatewayToolCall::query()->decrement('latency_ms'),
                fn () => GatewayToolCall::query()->upsert([['id' => $w['id']]], ['id']),
                fn () => GatewayToolCall::query()->findOrFail($w['id'])->delete(),
                fn () => GatewayToolCall::query()->findOrFail($w['id'])->update(['decision' => 'error']),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('El modelo debía rechazar la escritura.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
        $this->assertSame(1, DB::table('gateway_tool_calls')->where('id', $w['id'])->count());
    }

    public function test_deleting_the_company_cascades_to_its_audit_rows(): void
    {
        $a = $this->withRow();
        $b = $this->withRow();

        DB::table('tenants')->where('id', $a['tenant']->id)->delete();

        $this->assertSame(0, DB::table('gateway_tool_calls')->where('tenant_id', $a['tenant']->id)->count(), 'la supresión de la empresa borra su auditoría');
        $this->assertSame(1, DB::table('gateway_tool_calls')->where('tenant_id', $b['tenant']->id)->count(), 'la de otra empresa queda intacta');
    }

    public function test_deleting_a_person_keeps_the_rows_and_nulls_only_the_user_reference(): void
    {
        $w = $this->withRow();
        $before = (array) DB::table('gateway_tool_calls')->where('id', $w['id'])->first();

        DB::table('users')->where('id', $w['user']->id)->delete();

        $after = (array) DB::table('gateway_tool_calls')->where('id', $w['id'])->first();
        $this->assertNull($after['user_id']);
        unset($before['user_id'], $after['user_id']);
        $this->assertEquals($before, $after, 'solo cambia la referencia a la persona');
    }

    public function test_the_retention_path_deletes_only_what_it_is_told_and_the_flag_does_not_leak(): void
    {
        $a = $this->withRow();
        $b = $this->withRow();
        DB::table('gateway_tool_calls')->insert([
            'tenant_id' => $a['tenant']->id, 'user_id' => $a['user']->id, 'channel' => 'web', 'tool' => 'dashboard_summary', 'decision' => 'allowed',
            'arguments' => '{}', 'result_summary' => '{}', 'latency_ms' => 1, 'created_at' => now()->subDays(400),
        ]);
        DB::table('gateway_tool_calls')->insert([
            'tenant_id' => $b['tenant']->id, 'user_id' => $b['user']->id, 'channel' => 'web', 'tool' => 'dashboard_summary', 'decision' => 'allowed',
            'arguments' => '{}', 'result_summary' => '{}', 'latency_ms' => 1, 'created_at' => now()->subDays(400),
        ]);

        $deleted = app(AuditRetention::class)->purge(now()->subDays(365), $a['tenant']->id);

        $this->assertSame(1, $deleted, 'solo lo antiguo de la empresa indicada');
        $this->assertSame(1, DB::table('gateway_tool_calls')->where('tenant_id', $a['tenant']->id)->count(), 'lo reciente se conserva');
        $this->assertSame(2, DB::table('gateway_tool_calls')->where('tenant_id', $b['tenant']->id)->count(), 'otra empresa intacta');

        // La variable de la ruta de retención no queda activa: un borrado directo posterior sigue rechazado.
        $this->assertRefused(fn () => DB::table('gateway_tool_calls')->where('tenant_id', $b['tenant']->id)->delete());
        $this->assertSame(1, app(AuditRetention::class)->purge(now()->subDays(365)), 'sin empresa: la retención recorre todas');
    }
}
