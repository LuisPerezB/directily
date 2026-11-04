<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway;

use App\Enums\TenantRole;
use App\Models\GatewayToolCall;
use App\Services\Conversation\Tools\ToolContext;
use App\Services\Conversation\Tools\ToolRegistry;
use App\Services\Conversation\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Conversation\Gateway\Support\GatewayWorld;
use Tests\TestCase;

/**
 * V2.5 · W6-D (ADR-V25-17; V25-GWY): autorización POR HERRAMIENTA del Intelligence Gateway: habilidad del rol/token, alcance comercial (gerente, vendedor, servicio),
 * validación de argumentos, aislamiento entre empresas y catálogo por canal. Cada llamada —permitida o no— deja una fila en `gateway_tool_calls`.
 */
class GatewayAuthorizationTest extends TestCase
{
    use GatewayWorld;
    use RefreshDatabase;

    private const ALL = [
        'dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'customer_predictions', 'supply_plan',
        'radar_opportunities', 'explain_recommendation', 'decisions_summary', 'outcomes', 'roi_summary', 'model_quality',
    ];

    private const OPERATIONAL = ['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'supply_plan', 'model_quality'];

    private function lastCall(): GatewayToolCall
    {
        return GatewayToolCall::withoutTenantScope()->orderByDesc('id')->firstOrFail();
    }

    // ── habilidad por herramienta ──
    public function test_every_tool_is_denied_without_its_ability_and_each_denial_is_audited(): void
    {
        $c = $this->gatewayCompany();
        $before = GatewayToolCall::withoutTenantScope()->count();
        $ctx = $this->ctx($c['tenant'], $c['user'], TenantRole::Owner, ['data.view']);   // token real que solo puede ver datos

        foreach (self::ALL as $tool) {
            $result = $this->gateway()->call($tool, [], $ctx);
            $this->assertSame('forbidden', $result->status, $tool);
            $this->assertNull($result->data, $tool);
            $this->assertSame('denied_ability', $this->lastCall()->decision, $tool);
            $this->assertSame($tool, $this->lastCall()->tool);
        }
        $this->assertSame($before + count(self::ALL), GatewayToolCall::withoutTenantScope()->count(), 'una fila por llamada');
    }

    public function test_a_token_with_only_the_needed_ability_can_use_exactly_those_tools(): void
    {
        $c = $this->gatewayCompany();
        $viewTokens = $this->ctx($c['tenant'], $c['user'], TenantRole::Owner, ['decisions.view']);

        $names = array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($viewTokens));
        $this->assertEqualsCanonicalizing(['explain_recommendation', 'decisions_summary', 'outcomes', 'roi_summary'], $names);
        $this->assertSame('forbidden', $this->gateway()->call('dashboard_summary', [], $viewTokens)->status);
    }

    public function test_a_context_without_role_has_no_tools(): void
    {
        $c = $this->gatewayCompany();
        $ctx = new ToolContext($c['tenant'], $c['user'], null);

        $this->assertSame([], $this->gateway()->availableFor($ctx));
        $this->assertSame('forbidden', $this->gateway()->call('dashboard_summary', [], $ctx)->status);
    }

    public function test_the_viewer_role_can_use_every_tool_the_equivalent_api_routes_allow(): void
    {
        $c = $this->gatewayCompany();
        $viewer = $this->member($c['tenant'], TenantRole::Viewer);

        $available = array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($this->ctx($c['tenant'], $viewer)));
        $this->assertSame(self::ALL, $available);
    }

    // ── alcance comercial ──
    /** @return array<string, array{0: ?string, 1: array<string, mixed>, 2: list<string>, 3: string}> rol comercial, alcance guardado, herramientas disponibles y rol en la auditoría */
    public static function commercialRoles(): array
    {
        $teamOnly = ['roi_summary', 'outcomes'];
        $withoutTeam = array_values(array_diff(self::ALL, $teamOnly));

        return [
            'sin rol comercial' => [null, [], self::ALL, ''],
            'gerente sin alcance (equipo = empresa)' => ['gerente', [], self::ALL, 'gerente'],
            'gerente de una zona' => ['gerente', ['zones' => ['NORTE']], $withoutTeam, 'gerente'],
            'gerente con equipo' => ['gerente', ['team_user_ids' => [999]], $withoutTeam, 'gerente'],
            'vendedor con cartera' => ['vendedor', ['customer_ids' => [1]], $withoutTeam, 'vendedor'],
            'vendedor sin cartera' => ['vendedor', [], $withoutTeam, 'vendedor'],
            'servicio sin alcance (solo lectura, toda la empresa)' => ['servicio', [], self::ALL, 'servicio'],
            'servicio con cartera' => ['servicio', ['zones' => ['SUR']], $withoutTeam, 'servicio'],
            'rol comercial desconocido (el gateway cierra en falso)' => ['auditor-externo', [], self::OPERATIONAL, 'unresolved'],
        ];
    }

    /**
     * @param  array<string, mixed>  $portfolio
     * @param  list<string>  $expected
     */
    #[DataProvider('commercialRoles')]
    public function test_the_commercial_role_decides_which_tools_exist_for_the_user(?string $role, array $portfolio, array $expected, string $auditedRole): void
    {
        $c = $this->gatewayCompany();
        $user = $this->member($c['tenant'], TenantRole::Analyst, $role, $portfolio);
        $ctx = $this->ctx($c['tenant'], $user);

        $this->assertSame($expected, array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($ctx)));
        // Lo que no existe para el usuario tampoco se le ofrece al modelo.
        $this->assertSame($expected, array_column($this->gateway()->specsFor($ctx), 'name'));

        $denied = array_values(array_diff(self::ALL, $expected));
        foreach ($denied as $tool) {
            $result = $this->gateway()->call($tool, $tool === 'explain_recommendation' ? ['recommendation_id' => $c['recs']['c1']] : [], $ctx);
            $this->assertSame('scope_denied', $result->status, "{$role} → {$tool}");
            $this->assertNull($result->data);
            $call = $this->lastCall();
            $this->assertSame('denied_policy', $call->decision);
            $this->assertSame('commercial_scope', $call->result_summary['reason']);
            $this->assertSame($auditedRole, $call->result_summary['commercial']['role']);
        }
        foreach (array_slice($expected, 0, 3) as $tool) {
            $this->assertNotContains($this->gateway()->call($tool, $tool === 'explain_recommendation' ? ['recommendation_id' => $c['recs']['c1']] : [], $ctx)->status, ['scope_denied', 'forbidden']);
        }
    }

    public function test_a_salesperson_sees_only_their_portfolio_and_assignments_in_every_customer_tool(): void
    {
        $c = $this->gatewayCompany();
        $c1 = $c['cat']['customers']['c1'];
        $analyst = $this->ctx($c['tenant'], $c['user']);
        $byIdUser = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c1]]);
        $byId = $this->ctx($c['tenant'], $byIdUser);
        $byZone = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['zones' => ['NORTE']]));
        $otherZone = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['zones' => ['SUR']]));
        $empty = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Analyst, 'vendedor'));

        // Referencia: lo que ve quien no tiene recorte, solo del comprador c1.
        $c1Radar = $this->gateway()->call('radar_opportunities', ['customer_code' => 'CLI-0001', 'limit' => 10], $analyst)->data;
        $this->assertGreaterThan(0, $c1Radar['total_matches']);

        foreach ([$byId, $byZone] as $ctx) {
            $radar = $this->gateway()->call('radar_opportunities', ['limit' => 10], $ctx);
            $this->assertSame(['CLI-0001'], $this->customerCodes($radar->data), 'solo su cartera');
            $this->assertSame($c1Radar['total_matches'], $radar->data['total_matches']);

            $predictions = $this->gateway()->call('customer_predictions', ['horizon' => 30, 'limit' => 10], $ctx);
            $this->assertSame(['CLI-0001'], array_values(array_unique(array_map(fn ($p) => $p['customer']['code'], $predictions->data['predictions']))));

            $summary = $this->gateway()->call('decisions_summary', [], $ctx)->data;
            $this->assertSame(DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('customer_id', $c1)->count(), $summary['total']);
            $this->assertSame('alcance comercial del usuario', $summary['scope']);

            $this->assertSame('ok', $this->gateway()->call('explain_recommendation', ['recommendation_id' => $c['recs']['c1']], $ctx)->status);
            // Los resultados medidos y el ROI son de equipo: igual que en la API, no se entregan a un alcance restringido.
            $this->assertSame('scope_denied', $this->gateway()->call('outcomes', [], $ctx)->status);
            $this->assertSame('scope_denied', $this->gateway()->call('roi_summary', [], $ctx)->status);
        }

        // Fuera de la cartera: el comprador de otra zona y un código inexistente dan EXACTAMENTE la misma respuesta (no se revela qué existe).
        $foreign = $this->gateway()->call('radar_opportunities', ['customer_code' => 'CLI-0002'], $byId);
        $missing = $this->gateway()->call('radar_opportunities', ['customer_code' => 'CLI-NO-EXISTE'], $byId);
        $this->assertSame('no_data', $foreign->status);
        $this->assertEquals($missing->toArray(), $foreign->toArray());
        $this->assertEquals(
            $this->gateway()->call('customer_predictions', ['customer_code' => 'CLI-NO-EXISTE', 'horizon' => 30], $byId)->toArray(),
            $this->gateway()->call('customer_predictions', ['customer_code' => 'CLI-0002', 'horizon' => 30], $byId)->toArray(),
        );
        $foreignRec = $this->gateway()->call('explain_recommendation', ['recommendation_id' => $c['recs']['c2']], $byId);
        $this->assertEquals($this->gateway()->call('explain_recommendation', ['recommendation_id' => 999999], $byId)->toArray(), $foreignRec->toArray());
        $this->assertSame('no_data', $foreignRec->status);

        // Otra zona (la de c2) ve c2 y no c1; una cartera vacía no ve ningún comprador.
        $this->assertSame(['CLI-0002'], $this->customerCodes($this->gateway()->call('radar_opportunities', ['limit' => 10], $otherZone)->data));
        $this->assertSame('no_data', $this->gateway()->call('radar_opportunities', [], $empty)->status);
        $this->assertSame('no_data', $this->gateway()->call('customer_predictions', ['horizon' => 30], $empty)->status);
        $this->assertSame(0, $this->gateway()->call('decisions_summary', [], $empty)->data['total']);
    }

    public function test_assignments_decide_for_a_salesperson_what_the_portfolio_alone_does_not(): void
    {
        $c = $this->gatewayCompany();
        $c1 = $c['cat']['customers']['c1'];
        $seller = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c1]]);
        $colleague = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c1]]);
        $ctx = $this->ctx($c['tenant'], $seller);
        $assign = fn (int $recommendation, ?int $user) => DB::table('decision_recommendations')->where('id', $recommendation)->update(['assigned_user_id' => $user]);

        $before = $this->gateway()->call('radar_opportunities', ['limit' => 10], $ctx)->data['total_matches'];
        // Una oportunidad de su cartera asignada a OTRA persona deja de verse; una de FUERA de su cartera asignada a él sí se ve.
        $assign($c['recs']['c1'], $colleague->id);
        $assign($c['recs']['c2'], $seller->id);

        $radar = $this->gateway()->call('radar_opportunities', ['limit' => 10], $ctx)->data;
        $this->assertSame($before, $radar['total_matches'], 'una sale (asignada a otra persona) y una entra (asignada a él)');
        $this->assertSame('ok', $this->gateway()->call('explain_recommendation', ['recommendation_id' => $c['recs']['c2']], $ctx)->status);
        $this->assertSame('no_data', $this->gateway()->call('explain_recommendation', ['recommendation_id' => $c['recs']['c1']], $ctx)->status);
        $this->assertEquals(
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => 999999], $ctx)->toArray(),
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => $c['recs']['c1']], $ctx)->toArray(),
        );
        $this->assertContains('CLI-0002', array_map(fn ($p) => $p['customer']['code'], $this->gateway()->call('customer_predictions', ['horizon' => 30, 'limit' => 10], $ctx)->data['predictions']), 'un cliente con una recomendación asignada a él es visible');
    }

    public function test_a_manager_with_a_declared_portfolio_is_limited_to_it_and_one_without_sees_the_whole_company(): void
    {
        $c = $this->gatewayCompany();
        $wide = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Analyst, 'gerente'));
        $zone = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Analyst, 'gerente', ['zones' => ['SUR']]));

        $this->assertEqualsCanonicalizing(['CLI-0001', 'CLI-0002'], array_values(array_filter($this->customerCodes($this->gateway()->call('radar_opportunities', ['limit' => 10], $wide)->data), fn ($x) => $x !== null && $x !== '9999999999999')));
        $this->assertSame(['CLI-0002'], $this->customerCodes($this->gateway()->call('radar_opportunities', ['limit' => 10], $zone)->data));
        $this->assertSame('ok', $this->gateway()->call('roi_summary', [], $wide)->status);
        $this->assertSame('ok', $this->gateway()->call('outcomes', [], $wide)->status);
        $this->assertSame('scope_denied', $this->gateway()->call('roi_summary', [], $zone)->status);
        $this->assertSame('scope_denied', $this->gateway()->call('outcomes', [], $zone)->status);
    }

    public function test_a_service_user_is_capped_to_read_only_and_follows_their_stored_scope(): void
    {
        $c = $this->gatewayCompany();
        // Un administrador con rol comercial «servicio»: el techo de solo lectura lo deja con las habilidades de consulta (ResolveTenant), y todas las herramientas son de lectura.
        $admin = $this->member($c['tenant'], TenantRole::Admin, 'servicio');
        $ctx = $this->ctx($c['tenant'], $admin);
        $this->assertSame(TenantRole::Viewer, $ctx->role);
        $this->assertFalse($ctx->can('decisions.manage'));
        $this->assertSame(self::ALL, array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($ctx)), 'sin alcance guardado ve toda la empresa, en lectura');

        // Con un alcance guardado (zona), queda acotado a ella y sin vistas de equipo.
        $zoned = $this->ctx($c['tenant'], $this->member($c['tenant'], TenantRole::Admin, 'servicio', ['zones' => ['SUR']]));
        $this->assertSame(['CLI-0002'], $this->customerCodes($this->gateway()->call('radar_opportunities', ['limit' => 10], $zoned)->data));
        $this->assertSame('scope_denied', $this->gateway()->call('roi_summary', [], $zoned)->status);
        $this->assertSame('denied_policy', $this->lastCall()->decision);
    }

    // ── aislamiento entre empresas ──
    public function test_a_company_never_reaches_another_companys_opportunities_decisions_or_outcomes(): void
    {
        $a = $this->gatewayCompany();
        $b = $this->gatewayCompany();
        DB::table('customers')->where('id', $b['cat']['customers']['c1'])->update(['external_code' => 'CLI-SOLO-B']);
        $ctxA = $this->ctx($a['tenant'], $a['user']);

        // El código de un comprador de B no existe para A: misma respuesta que un código inexistente.
        $foreign = $this->gateway()->call('radar_opportunities', ['customer_code' => 'CLI-SOLO-B'], $ctxA);
        $missing = $this->gateway()->call('radar_opportunities', ['customer_code' => 'CLI-INVENTADO'], $ctxA);
        $this->assertEquals($missing->toArray(), $foreign->toArray());
        $this->assertEquals(
            $this->gateway()->call('customer_predictions', ['customer_code' => 'CLI-INVENTADO', 'horizon' => 30], $ctxA)->toArray(),
            $this->gateway()->call('customer_predictions', ['customer_code' => 'CLI-SOLO-B', 'horizon' => 30], $ctxA)->toArray(),
        );

        // Una recomendación de B (por id) no se explica a A: igual que una inexistente.
        $this->assertEquals(
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => 999999], $ctxA)->toArray(),
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => $b['recs']['c1']], $ctxA)->toArray(),
        );

        // Los conteos y resultados medidos son solo de A.
        $summary = $this->gateway()->call('decisions_summary', [], $ctxA)->data;
        $this->assertSame(DB::table('decision_recommendations')->where('tenant_id', $a['tenant']->id)->count(), $summary['total']);
        $outcomes = $this->gateway()->call('outcomes', ['limit' => 10], $ctxA)->data;
        $this->assertSame(2, $outcomes['total_matches']);
        $this->assertNotContains('CLI-SOLO-B', array_map(fn ($o) => $o['recommendation']['customer_code'], $outcomes['outcomes']));
        $this->assertStringNotContainsString('CLI-SOLO-B', json_encode($this->gateway()->call('radar_opportunities', ['limit' => 10], $ctxA)->toArray()));

        // Y la auditoría de A no contiene las llamadas de B.
        $this->gateway()->call('decisions_summary', [], $this->ctx($b['tenant'], $b['user']));
        $this->assertSame(0, GatewayToolCall::withoutTenantScope()->where('tenant_id', $a['tenant']->id)->where('user_id', $b['user']->id)->count());
        $this->withinTenant($a['tenant'], fn () => $this->assertSame([$a['tenant']->id], GatewayToolCall::query()->pluck('tenant_id')->unique()->values()->all()));
    }

    // ── argumentos ──
    public function test_the_model_cannot_choose_the_company_the_user_or_the_abilities(): void
    {
        $a = $this->gatewayCompany();
        $b = $this->gatewayCompany();
        $ctx = $this->ctx($a['tenant'], $a['user'], TenantRole::Viewer);

        foreach ([
            ['tenant_id' => $b['tenant']->id], ['user_id' => $b['user']->id], ['role' => 'owner'], ['abilities' => ['*']], ['Tenant' => 'x'], ['scope' => 'all'],
            ['commercial_role' => 'gerente'], ['channel' => 'api'], ['token' => 'abc'],
        ] as $arguments) {
            $result = $this->gateway()->call('decisions_summary', $arguments, $ctx);
            $this->assertSame('invalid_arguments', $result->status, json_encode($arguments));
            $this->assertNull($result->data);
            $call = $this->lastCall();
            $this->assertSame('denied_policy', $call->decision);
            $this->assertSame('reserved_arguments', $call->result_summary['reason']);
            $this->assertSame($a['tenant']->id, $call->tenant_id, 'la empresa de la fila es la de la petición');
            $this->assertSame($a['user']->id, $call->user_id);
            // El valor que el modelo intentó pasar no se guarda.
            $this->assertStringNotContainsString((string) $b['tenant']->id.'"', json_encode($call->arguments));
            $this->assertStringNotContainsString('owner', json_encode($call->arguments));
        }
    }

    public function test_arguments_are_validated_against_the_json_schema_before_anything_runs(): void
    {
        $c = $this->gatewayCompany();
        $ctx = $this->ctx($c['tenant'], $c['user']);

        foreach ([
            ['radar_opportunities', ['horizon' => '30']],            // entero como texto
            ['radar_opportunities', ['horizon' => 31]],              // fuera de la lista cerrada
            ['radar_opportunities', ['limit' => 999]],
            ['radar_opportunities', ['kind' => 'rm -rf']],
            ['radar_opportunities', ['customer_code' => str_repeat('x', 51)]],
            ['radar_opportunities', ['foo' => 'bar']],
            ['decisions_summary', ['kind' => ['contact']]],
            ['explain_recommendation', []],                          // falta el requerido
            ['explain_recommendation', ['recommendation_id' => '12']],
            ['explain_recommendation', ['recommendation_id' => 0]],
            ['roi_summary', ['from' => '01/10/2026']],
            ['outcomes', ['classification' => 'proven']],
        ] as [$tool, $arguments]) {
            $result = $this->gateway()->call($tool, $arguments, $ctx);
            $this->assertSame('invalid_arguments', $result->status, $tool.' '.json_encode($arguments));
            $this->assertSame('schema', $this->lastCall()->result_summary['reason'], $tool.' '.json_encode($arguments));
        }
    }

    public function test_a_period_longer_than_the_report_maximum_is_rejected_as_invalid_arguments(): void
    {
        $c = $this->gatewayCompany();
        $result = $this->gateway()->call('roi_summary', ['from' => '2020-01-01', 'to' => '2026-10-01'], $this->ctx($c['tenant'], $c['user']));

        $this->assertSame('invalid_arguments', $result->status);
        $this->assertStringContainsString('730', (string) $result->message);
    }

    // ── catálogo por canal ──
    public function test_the_channel_restricts_the_tool_catalog(): void
    {
        $c = $this->gatewayCompany();
        $whatsapp = $this->ctx($c['tenant'], $c['user'], null, null, 'whatsapp');

        $this->assertSame(['dashboard_summary', 'inventory_recommendations', 'radar_opportunities', 'decisions_summary'], array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($whatsapp)));
        $roi = $this->gateway()->call('roi_summary', [], $whatsapp);
        $this->assertSame('channel_denied', $roi->status);
        $this->assertSame('denied_policy', $this->lastCall()->decision);
        $this->assertSame('whatsapp', $this->lastCall()->channel);
        $this->assertSame('channel_denied', $this->lastCall()->result_summary['reason']);
        $this->assertNotSame('channel_denied', $this->gateway()->call('radar_opportunities', [], $whatsapp)->status);

        // Un canal que no figura en la configuración no tiene ninguna herramienta.
        $unknown = $this->ctx($c['tenant'], $c['user'])->withChannel('telegram');
        $this->assertSame([], $this->gateway()->availableFor($unknown));
        $this->assertSame('channel_denied', $this->gateway()->call('dashboard_summary', [], $unknown)->status);
    }

    // ── plan ──
    public function test_a_plan_without_customer_behavior_is_denied_by_policy_and_audited(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['features' => ['customer' => false]]]);
        $result = $this->gateway()->call('radar_opportunities', [], $this->ctx($tenant, $user));

        $this->assertSame('plan_limit', $result->status);
        $this->assertSame('denied_policy', $this->lastCall()->decision);
        $this->assertSame('plan_limit', $this->lastCall()->result_summary['status']);
    }

    // ── herramienta no registrada ──
    public function test_unknown_or_disabled_tools_are_denied_and_the_audit_keeps_only_a_safe_name(): void
    {
        $c = $this->gatewayCompany();
        $ctx = $this->ctx($c['tenant'], $c['user']);

        $result = $this->gateway()->call('run_sql', ['query' => 'select * from users where email = "ana@correo.com"'], $ctx);
        $this->assertSame('tool_not_allowed', $result->status);
        $call = $this->lastCall();
        $this->assertSame(['decision' => 'denied_policy', 'tool' => 'run_sql'], ['decision' => $call->decision, 'tool' => $call->tool]);
        $this->assertSame(['rejected_keys' => ['query']], $call->arguments);
        $this->assertStringNotContainsString('ana@correo.com', json_encode($call->getAttributes()));

        $this->gateway()->call("dashboard_summary'; DROP TABLE users;--", [], $ctx);
        $this->assertSame('(invalid)', $this->lastCall()->tool);

        config(['conversation.privacy.customer_tools_enabled' => false]);
        $this->assertSame('tool_not_allowed', $this->gateway()->call('radar_opportunities', [], $this->ctx($c['tenant'], $c['user']))->status);
        $this->assertNotContains('radar_opportunities', array_map(fn ($t) => $t->name(), (new ToolRegistry)->enabled()));
    }

    public function test_status_lists_each_tool_with_its_ability_data_scope_and_the_users_commercial_scope(): void
    {
        $c = $this->gatewayCompany();
        $this->fakeProvider([]);
        $seller = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c['cat']['customers']['c1']]]);

        $data = $this->actingAsTenantUser($seller, $c['tenant'])->getJson('/api/v1/assistant/status')->assertOk()->json('data');
        $tools = collect($data['tools'])->keyBy('name');

        $this->assertSame(self::ALL, $tools->keys()->all());
        $this->assertSame(['name' => 'roi_summary', 'ability' => 'decisions.view', 'data_scope' => 'tenant_management', 'available_to_you' => false], $tools['roi_summary']);
        $this->assertSame(['name' => 'radar_opportunities', 'ability' => 'forecast.view', 'data_scope' => 'customer_portfolio', 'available_to_you' => true], $tools['radar_opportunities']);
        $this->assertSame(['role' => 'vendedor', 'restricted' => true, 'source' => 'request'], $data['commercial_scope'], 'el alcance viene de ResolveTenant (W6-A)');
        $this->assertSame(['name' => 'outcomes', 'ability' => 'decisions.view', 'data_scope' => 'tenant_management', 'available_to_you' => false], $tools['outcomes']);
        $this->assertStringNotContainsString((string) $c['cat']['customers']['c1'].',', json_encode($data['commercial_scope']));   // nunca los ids de la cartera
    }

    public function test_a_tool_result_is_a_tool_result_value_object_not_an_exception_for_every_failure_path(): void
    {
        $c = $this->gatewayCompany();
        $this->assertInstanceOf(ToolResult::class, $this->gateway()->call('explain_recommendation', ['recommendation_id' => 1], $this->ctx($c['tenant'], $c['user'], TenantRole::Viewer, ['data.view'])));
    }
}
