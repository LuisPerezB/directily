<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway;

use App\Enums\TenantRole;
use App\Models\GatewayToolCall;
use App\Services\Conversation\Gateway\Audit\ToolCallRecorder;
use App\Services\Conversation\Tools\ToolContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Feature\Conversation\Gateway\Support\GatewayWorld;
use Tests\Feature\Conversation\Support\FakeConversationProvider as Fake;
use Tests\TestCase;

/**
 * V2.5 · W6-D (ADR-V25-17): auditoría en `gateway_tool_calls`: una fila por llamada con empresa, usuario, canal, herramienta, decisión, argumentos MINIMIZADOS, resumen sin
 * valores de negocio y latencia; sin PII; solo inserción; sin auditoría no se entrega el resultado; y lectura por empresa con `audit.view`.
 */
class GatewayAuditTest extends TestCase
{
    use GatewayWorld;
    use RefreshDatabase;

    private function rows(): Collection
    {
        return GatewayToolCall::withoutTenantScope()->orderBy('id')->get();
    }

    public function test_each_tool_call_writes_one_row_with_context_latency_minimized_arguments_and_a_summary_without_business_values(): void
    {
        $c = $this->gatewayCompany();
        $this->fakeProvider([
            Fake::tool('customer_predictions', ['customer_code' => 'CLI-0001', 'product' => 'Cemento', 'horizon' => 30, 'min_probability' => 0.2, 'limit' => 5]),
            Fake::text('Consultado.'),
        ]);

        $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => '¿Qué comprará CLI-0001?'])->assertOk();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($c['tenant']->id, $row->tenant_id);
        $this->assertSame($c['user']->id, $row->user_id);
        $this->assertSame('web', $row->channel);
        $this->assertSame('customer_predictions', $row->tool);
        $this->assertSame('allowed', $row->decision);
        $this->assertIsInt($row->latency_ms);
        $this->assertGreaterThanOrEqual(0, $row->latency_ms);
        $this->assertNotNull($row->created_at);
        // Argumentos: lo no textual tal cual; el texto libre (código de comprador, búsqueda) solo como longitud.
        $this->assertEquals(['customer_code' => ['chars' => 8], 'horizon' => 30, 'limit' => 5, 'min_probability' => 0.2, 'product' => ['chars' => 7]], $row->arguments);   // jsonb no conserva el orden de las claves
        // Resumen: estado, procedencia y tamaños; nada de códigos, SKU, nombres ni cifras de negocio.
        $this->assertSame('ok', $row->result_summary['status']);
        $this->assertSame('customer', $row->result_summary['core']);
        $this->assertArrayHasKey('as_of', $row->result_summary);
        $this->assertSame(['predictions' => 1], $row->result_summary['rows']);
        $dump = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['CLI-0001', 'CEM-01', 'Cemento', '0.65', 'purchase_probability'] as $leak) {
            $this->assertStringNotContainsString($leak, $dump, "la auditoría no debe contener «{$leak}»");
        }
    }

    public function test_the_channel_is_api_when_the_request_uses_a_real_token(): void
    {
        $c = $this->gatewayCompany();
        $this->fakeProvider([Fake::tool('decisions_summary', []), Fake::text('Consultado.')]);
        $token = $c['user']->createToken('integracion', ['*'])->plainTextToken;
        app('auth')->forgetGuards();

        $this->withToken($token)->withHeader('X-Tenant-ID', $c['tenant']->uuid)->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertOk();

        $this->assertSame('api', $this->rows()->last()->channel);
    }

    public function test_personal_data_in_the_message_or_the_arguments_never_reaches_the_audit(): void
    {
        $c = $this->gatewayCompany();
        $this->fakeProvider([
            Fake::tool('radar_opportunities', ['customer_code' => 'ana.perez@correo.com', 'limit' => 5]),
            Fake::tool('product_forecast', ['product' => '1710034065 RUC 1790012345001', 'horizon' => 30, 'tenant_id' => 77]),
            Fake::text('Sin resultados.'),
        ]);

        $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', [
            'message' => 'Datos de ana.perez@correo.com, cédula 1710034065 y RUC 1790012345001',
        ])->assertOk();

        $dump = json_encode($this->rows()->map(fn ($r) => $r->getAttributes())->all(), JSON_UNESCAPED_UNICODE);
        foreach (['ana.perez', 'correo.com', '1710034065', '1790012345001', 'RUC', '77'] as $leak) {
            $this->assertStringNotContainsString($leak, str_replace('"tenant_id":'.$c['tenant']->id, '', $dump), "PII o valor rechazado «{$leak}» en la auditoría");
        }
        $this->assertEquals(['customer_code' => ['chars' => mb_strlen('ana.perez@correo.com')], 'limit' => 5], $this->rows()[0]->arguments);
        $this->assertEquals(['horizon' => 30, 'product' => ['chars' => mb_strlen('1710034065 RUC 1790012345001')], 'rejected_keys' => ['tenant_id']], $this->rows()[1]->arguments);
    }

    public function test_without_an_audit_row_no_result_is_delivered(): void
    {
        $c = $this->gatewayCompany();
        $this->app->instance(ToolCallRecorder::class, new class($this->app->make(TenantContext::class)) extends ToolCallRecorder
        {
            public function record(ToolContext $context, string $tool, string $decision, array $arguments, array $summary, int $latencyMs): GatewayToolCall
            {
                throw new RuntimeException('La base de auditoría no responde.');
            }
        });

        $result = $this->gateway()->call('decisions_summary', [], $this->ctx($c['tenant'], $c['user']));

        $this->assertSame('error', $result->status);
        $this->assertNull($result->data, 'sin rastro de auditoría no se entrega ningún dato');
        $this->assertStringContainsString('auditoría', (string) $result->message);
        $this->assertSame(0, GatewayToolCall::withoutTenantScope()->count());
    }

    public function test_the_audit_is_insert_only(): void
    {
        $c = $this->gatewayCompany();
        $this->gateway()->call('decisions_summary', [], $this->ctx($c['tenant'], $c['user']));
        $row = GatewayToolCall::withoutTenantScope()->firstOrFail();

        try {
            $row->update(['decision' => 'error']);
            $this->fail('La auditoría no debe poder editarse.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $row->delete();
            $this->fail('La auditoría no debe poder borrarse.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, GatewayToolCall::withoutTenantScope()->count());
    }

    public function test_the_recorder_masks_personal_data_even_if_a_caller_passes_it_in_arguments_or_summary(): void
    {
        $c = $this->gatewayCompany();
        $ctx = new ToolContext($c['tenant'], $c['user'], TenantRole::Owner);

        $row = app(ToolCallRecorder::class)->record($ctx, 'dashboard_summary', 'allowed', ['nota' => 'escribir a ana.perez@correo.com'], ['detalle' => 'cédula 1710034065'], 3);

        $this->assertStringNotContainsString('ana.perez@correo.com', json_encode($row->arguments));
        $this->assertStringNotContainsString('1710034065', json_encode($row->result_summary));
        $this->assertStringContainsString('a***@c***.com', json_encode($row->arguments));
        $this->assertStringContainsString('[ID]', json_encode($row->result_summary));
    }

    public function test_the_recorder_refuses_unknown_decisions_and_normalizes_the_tool_name_and_channel(): void
    {
        $c = $this->gatewayCompany();
        $ctx = new ToolContext($c['tenant'], $c['user'], TenantRole::Owner, null, 'canal-raro');
        $recorder = app(ToolCallRecorder::class);

        $row = $recorder->record($ctx, "x'; DROP TABLE users;--", 'denied_policy', [], ['status' => 'tool_not_allowed'], 3);
        $this->assertSame('(invalid)', $row->tool);
        $this->assertSame('api', $row->channel, 'un canal desconocido se registra como api, nunca como texto libre');
        $this->expectException(\InvalidArgumentException::class);
        $recorder->record($ctx, 'dashboard_summary', 'permitido', [], [], 1);
    }

    // ── lectura ──
    public function test_the_audit_can_be_read_by_those_with_audit_view_and_only_for_their_company(): void
    {
        $a = $this->gatewayCompany();
        $b = $this->gatewayCompany();
        $admin = $this->member($a['tenant'], TenantRole::Admin);
        $analyst = $this->member($a['tenant'], TenantRole::Analyst);
        $this->gateway()->call('decisions_summary', [], $this->ctx($a['tenant'], $a['user']));
        $this->gateway()->call('run_sql', ['query' => 'x'], $this->ctx($a['tenant'], $a['user']));
        $this->gateway()->call('decisions_summary', [], $this->ctx($b['tenant'], $b['user']));

        $this->actingAsTenantUser($analyst, $a['tenant'])->getJson('/api/v1/gateway/tool-calls')->assertForbidden();
        $this->actingAsTenantUser($this->member($a['tenant'], TenantRole::Viewer), $a['tenant'])->getJson('/api/v1/gateway/tool-calls')->assertForbidden();

        $res = $this->actingAsTenantUser($admin, $a['tenant'])->getJson('/api/v1/gateway/tool-calls')->assertOk();
        $this->assertSame(2, $res->json('meta.total'), 'las llamadas de la otra empresa no aparecen');
        $this->assertSame(['run_sql', 'decisions_summary'], array_column($res->json('data'), 'tool'));
        $this->assertSame(['id', 'user_id', 'channel', 'tool', 'decision', 'arguments', 'result_summary', 'latency_ms', 'created_at'], array_keys($res->json('data.0')));
        $this->assertStringContainsString('"arguments":{"rejected_keys":["query"]}', $res->getContent());

        $denied = $this->actingAsTenantUser($admin, $a['tenant'])->getJson('/api/v1/gateway/tool-calls?decision=denied_policy')->assertOk();
        $this->assertSame(['run_sql'], array_column($denied->json('data'), 'tool'));
        $this->actingAsTenantUser($admin, $a['tenant'])->getJson('/api/v1/gateway/tool-calls?decision=inventada')->assertStatus(422);
        $this->actingAsTenantUser($admin, $a['tenant'])->getJson('/api/v1/gateway/tool-calls?channel=telegram')->assertStatus(422);
        $this->actingAsTenantUser($admin, $a['tenant'])->getJson('/api/v1/gateway/tool-calls?per_page=1')->assertOk()->assertJsonPath('meta.last_page', 2);
    }

    public function test_the_audit_rows_belong_to_the_company_of_the_request(): void
    {
        $a = $this->gatewayCompany();
        $b = $this->gatewayCompany();
        $this->gateway()->call('decisions_summary', [], $this->ctx($a['tenant'], $a['user']));

        $this->withinTenant($b['tenant'], fn () => $this->assertSame(0, GatewayToolCall::query()->count()));
        $this->withinTenant($a['tenant'], fn () => $this->assertSame(1, GatewayToolCall::query()->count()));
        $this->assertSame(1, DB::table('gateway_tool_calls')->where('tenant_id', $a['tenant']->id)->count());
    }
}
