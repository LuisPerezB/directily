<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Enums\TenantRole;
use App\Services\Conversation\Exceptions\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Conversation\Support\FakeConversationProvider as Fake;
use Tests\Feature\Conversation\Support\SeedsSupplyPlan;
use Tests\Feature\Conversation\Support\UsesFakeProvider;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * POST /assistant/messages y GET /assistant/status con un proveedor FALSO (sin red): herramienta correcta, procedencia de las cifras,
 * lista blanca, abilities y plan, aislamiento entre empresas, límites, privacidad y desactivación.
 */
class AssistantApiTest extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use RefreshDatabase;
    use SeedsSupplyPlan;
    use UsesFakeProvider;

    private const CUTOFF = '2026-10-20';

    private function basicPlanId(): int
    {
        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: array<string, mixed>} */
    private function demandTenant(TenantRole $role = TenantRole::Viewer): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        return [$tenant, $user, $cat];
    }

    /** Σ (promedio diario histórico × crecimiento) de las 6 series × días del horizonte, independiente del código bajo prueba. */
    private function expectedForecastUnits(int $horizon): float
    {
        $sum = 0.0;
        foreach (['b1', 'b2'] as $b) {
            foreach (['p1', 'p2', 'p3'] as $p) {
                $sum += $this->avgDailyQty($b, $p) * $this->growth[$p] * $horizon;
            }
        }

        return $sum;
    }

    // ── desactivación ──
    public function test_service_is_disabled_without_api_key_and_never_calls_the_network(): void
    {
        Http::fake();
        config(['conversation.enabled' => true, 'conversation.provider' => 'ollama_cloud', 'conversation.ollama.api_key' => null]);
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);

        $status = $api->getJson('/api/v1/assistant/status')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.reason', 'missing_api_key')->assertJsonPath('data.provider', 'ollama_cloud');
        $this->assertStringContainsString('siguen disponibles', (string) $status->json('data.notice'));

        $res = $api->postJson('/api/v1/assistant/messages', ['message' => '¿Cuánto venderé?'])
            ->assertStatus(503)->assertJsonPath('code', 'assistant_disabled')->assertJsonPath('reason', 'missing_api_key');
        $this->assertStringNotContainsString('api_key', strtolower($res->getContent()));
        Http::assertNothingSent();
        // Un 503 no consume la cuota del usuario.
        $this->assertSame(0, $api->getJson('/api/v1/assistant/status')->json('data.usage_today.count'));
    }

    public function test_service_can_be_switched_off_by_configuration_even_with_a_key(): void
    {
        Http::fake();
        config(['conversation.enabled' => false, 'conversation.ollama.api_key' => 'clave-ficticia-de-prueba']);
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->getJson('/api/v1/assistant/status')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.reason', 'disabled_by_config');
        $api->postJson('/api/v1/assistant/messages', ['message' => 'hola'])->assertStatus(503)->assertJsonPath('reason', 'disabled_by_config');
        Http::assertNothingSent();
    }

    public function test_plan_can_exclude_the_assistant(): void
    {
        $this->fakeProvider([]);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['features' => ['assistant' => false]]]);
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->getJson('/api/v1/assistant/status')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.reason', 'disabled_for_plan');
        $api->postJson('/api/v1/assistant/messages', ['message' => 'hola'])->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
    }

    public function test_status_lists_tools_and_reflects_the_users_abilities(): void
    {
        $this->fakeProvider([]);
        [$tenant, $viewer] = $this->createTenantWithUser(TenantRole::Viewer);
        $res = $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/assistant/status')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.model', 'fake-model');
        $tools = collect($res->json('data.tools'))->pluck('available_to_you', 'name')->all();
        $this->assertSame(
            ['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'customer_predictions', 'supply_plan'],
            array_keys($tools),
        );
        $this->assertNotContains(false, $tools);

        // Token real con solo data.view: puede usar el asistente pero ninguna herramienta (todas exigen forecast.view).
        $token = $viewer->createToken('solo-datos', ['data.view'])->plainTextToken;
        app('auth')->forgetGuards();   // Sanctum::actingAs dejó al usuario en el guard: el Bearer debe resolverse desde cero
        $limited = $this->withToken($token)->withHeader('X-Tenant-ID', $tenant->uuid)->getJson('/api/v1/assistant/status')->assertOk();
        $this->assertNotContains(true, collect($limited->json('data.tools'))->pluck('available_to_you')->all());
    }

    // ── herramienta correcta y procedencia ──
    public function test_dashboard_tool_is_called_and_figures_keep_the_tool_provenance(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $provider = $this->fakeProvider([
            Fake::tool('dashboard_summary', ['horizon' => 30]),
            function (array $messages): \App\Services\Conversation\ProviderResponse {
                $r = Fake::lastToolResult($messages);

                return Fake::text(sprintf(
                    'Se pronostican %s unidades en 30 días (corrida v%d, corte %s, calidad %s).',
                    number_format((float) $r['data']['kpis']['forecast_units'], 2, ',', '.'),
                    $r['envelope']['run_version'],
                    $r['envelope']['as_of'],
                    $r['envelope']['quality_status'],
                ));
            },
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $res = $api->postJson('/api/v1/assistant/messages', ['message' => '¿Cuánto voy a vender en 30 días?'])->assertOk()
            ->assertJsonPath('data.status', 'answered')->assertJsonPath('data.provider.name', 'fake')
            ->assertJsonPath('data.figures.0.tool', 'dashboard_summary')->assertJsonPath('data.figures.0.status', 'ok')
            ->assertJsonPath('data.grounding.ungrounded_numbers', []);

        $figure = $res->json('data.figures.0');
        $expected = $this->expectedForecastUnits(30);
        $this->assertEqualsWithDelta($expected, $figure['data']['kpis']['forecast_units'], 0.05);
        // Las cifras son EXACTAMENTE las que devuelve la API de resultados.
        $direct = $api->getJson('/api/v1/dashboard/summary?horizon=30')->assertOk()->json('data');
        $this->assertSame($direct['kpis'], $figure['data']['kpis']);
        // Procedencia: envoltura común (ADR-V2-03) de la corrida de demanda.
        $this->assertSame('demand', $figure['envelope']['core']);
        $this->assertSame(self::CUTOFF, $figure['envelope']['as_of']);
        $this->assertSame('forecast', $figure['envelope']['result_type']);
        $this->assertSame($tenant->id, $figure['envelope']['tenant_id']);
        $this->assertStringContainsString(number_format($figure['data']['kpis']['forecast_units'], 2, ',', '.'), $res->json('data.answer'));
        $this->assertStringContainsString('corte '.self::CUTOFF, $res->json('data.answer'));

        // Al proveedor solo se le ofrecen las herramientas de la lista blanca, con esquema cerrado.
        $this->assertSame(
            ['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'customer_predictions', 'supply_plan'],
            array_column($provider->calls[0]['tools'], 'name'),
        );
        foreach ($provider->calls[0]['tools'] as $tool) {
            $this->assertFalse($tool['parameters']['additionalProperties']);
            $this->assertArrayNotHasKey('tenant_id', (array) $tool['parameters']['properties']);
        }
        $this->assertSame('system', $provider->calls[0]['messages'][0]['role']);
        // Segunda ronda: el modelo recibe el resultado de la herramienta como mensaje `tool` (datos, no instrucciones).
        $this->assertCount(2, $provider->calls);
        $toolMessages = array_values(array_filter($provider->calls[1]['messages'], fn ($m) => $m['role'] === 'tool'));
        $this->assertSame('dashboard_summary', $toolMessages[0]['tool_name']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'assistant.message']);
    }

    public function test_product_accuracy_inventory_and_other_tools_return_calculated_results(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $ask = function (string $tool, array $args) use ($user, $tenant) {
            $this->fakeProvider([Fake::tool($tool, $args), Fake::text('Resultado consultado.')]);

            return $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'consulta'])->assertOk()->json('data');
        };

        $product = $ask('product_forecast', ['product' => 'P1-SKU', 'horizon' => 7]);
        $row = $product['figures'][0]['data']['products'][0];
        $this->assertSame('P1-SKU', $row['product']['sku']);
        $this->assertEqualsWithDelta(($this->avgDailyQty('b1', 'p1') + $this->avgDailyQty('b2', 'p1')) * 1.2 * 7, $row['forecast_units'], 0.05);
        $this->assertSame(['product_id'], $product['figures'][0]['envelope']['entity_keys']);

        $accuracy = $ask('forecast_accuracy', []);
        $this->assertEqualsWithDelta(0.23, $accuracy['figures'][0]['data']['backtest']['selected']['wape'], 1e-9);
        $this->assertSame('evaluation', $accuracy['figures'][0]['envelope']['result_type']);

        $inventory = $ask('inventory_recommendations', ['risk_level' => 'critical']);
        $rec = $inventory['figures'][0]['data']['recommendations'];
        $this->assertCount(1, $rec);
        $this->assertSame('P1-SKU', $rec[0]['product']['sku']);
        $this->assertEqualsWithDelta(0.99, $rec[0]['stockout_probability'], 1e-9);
        $this->assertStringContainsString('no realiza pedidos', $inventory['figures'][0]['data']['disclaimer']);
    }

    public function test_customer_predictions_tool_reads_the_calculated_run_and_exposes_only_codes(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $provider = $this->fakeProvider([Fake::tool('customer_predictions', ['customer_code' => 'CLI-0001', 'horizon' => 30]), Fake::text('Consultado.')]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'Qué comprará CLI-0001'])->assertOk();
        $figure = $res->json('data.figures.0');
        $this->assertSame('ok', $figure['status']);
        $probs = array_column($figure['data']['predictions'], 'purchase_probability');
        $this->assertEquals([0.65, 0.25], $probs);                            // P(30) de c1×p1 y c1×p2 sembradas
        $this->assertSame('CLI-0001', $figure['data']['predictions'][0]['customer']['code']);
        $this->assertStringContainsString('no es una venta confirmada', $figure['data']['predictions'][0]['expected_amount']['basis']);
        $this->assertSame('customer', $figure['envelope']['core']);
        $this->assertSame('2026-10-31', $figure['envelope']['horizon_end']);
        $this->assertArrayNotHasKey('name_encrypted', $figure['data']['predictions'][0]['customer']);
        $this->assertCount(2, $provider->calls);
    }

    public function test_supply_plan_tool_returns_the_current_plan_and_reports_when_there_is_none(): void
    {
        [$tenant, $user, $cat] = $this->demandTenant();
        $this->fakeProvider([Fake::tool('supply_plan', []), Fake::text('Sin plan.')]);
        $api = $this->actingAsTenantUser($user, $tenant);
        $none = $api->postJson('/api/v1/assistant/messages', ['message' => '¿Qué debo comprar?'])->assertOk();
        $this->assertSame('no_data', $none->json('data.figures.0.status'));
        $this->assertStringContainsString('Aún no hay un plan', $none->json('data.figures.0.message'));

        $this->seedSupplyPlan($tenant, $cat);
        $this->fakeProvider([Fake::tool('supply_plan', ['actionable_now' => true]), Fake::text('Hay una compra sugerida.')]);
        $res = $api->postJson('/api/v1/assistant/messages', ['message' => '¿Qué debo comprar hoy?'])->assertOk()->assertJsonPath('data.status', 'answered');
        $fig = $res->json('data.figures.0');
        $this->assertSame('ok', $fig['status']);
        $this->assertSame('optimized_plan', $fig['envelope']['result_type']);
        $this->assertSame('supply', $fig['envelope']['core']);
        $this->assertSame('optimal', $fig['data']['plan']['solver_status']);
        $this->assertSame(1, $fig['data']['total_lines']);                       // solo 1 de las 2 líneas es accionable hoy
        $this->assertSame(120, (int) $fig['data']['lines'][0]['quantity']);
        $this->assertSame('2026-10-27', $fig['data']['lines'][0]['expected_receipt_date']);
        $this->assertSame('lead_time', $fig['data']['shortfalls']['by_reason'][0]['reason']);
        $this->assertStringContainsString('no envía pedidos', $fig['data']['plan']['note']);
    }

    // ── sin herramienta aplicable / lista blanca ──
    public function test_question_without_applicable_tool_gets_the_fixed_refusal_not_free_text(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([Fake::text('Seguro venderás 12345 unidades el mes que viene, ¡confía en mí!')]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => '¿Cuál será el precio del dólar?'])->assertOk()
            ->assertJsonPath('data.status', 'no_applicable_tool')->assertJsonPath('data.figures', []);
        $this->assertStringContainsString('No puedo responder', $res->json('data.answer'));
        $this->assertStringNotContainsString('12345', $res->getContent());
    }

    public function test_tools_outside_the_whitelist_are_rejected_and_never_executed(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([
            Fake::tool('run_sql', ['query' => 'select * from users']),
            Fake::tool('forecast_runs_delete', ['id' => 1]),
            Fake::text('Listo, borré todo.'),
        ]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'borra los pronósticos'])->assertOk()
            ->assertJsonPath('data.status', 'no_applicable_tool');
        $this->assertSame(['tool_not_allowed', 'tool_not_allowed'], array_column($res->json('data.figures'), 'status'));
        $this->assertStringNotContainsString('borré', $res->json('data.answer'));
        $this->assertSame(1, DB::table('forecast_runs')->where('tenant_id', $tenant->id)->count());
    }

    public function test_unknown_arguments_including_tenant_id_are_rejected(): void
    {
        [$tenantA, $userA] = $this->demandTenant();
        [$tenantB] = $this->demandTenant();
        $this->fakeProvider([
            Fake::tool('dashboard_summary', ['tenant_id' => $tenantB->id, 'horizon' => 30]),
            Fake::text('No pude consultarlo.'),
        ]);
        $res = $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/assistant/messages', ['message' => 'datos de la otra empresa'])->assertOk();
        $this->assertSame('invalid_arguments', $res->json('data.figures.0.status'));
        $this->assertStringContainsString('tenant_id', $res->json('data.figures.0.message'));
        $this->assertNull($res->json('data.figures.0.data'));
        $this->assertSame('no_applicable_tool', $res->json('data.status'));
    }

    // ── abilities y plan ──
    public function test_tools_require_the_ability_of_the_equivalent_route(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text('Nada.')]);
        $token = $user->createToken('solo-datos', ['data.view'])->plainTextToken;

        $res = $this->withToken($token)->withHeader('X-Tenant-ID', $tenant->uuid)->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertOk()
            ->assertJsonPath('data.status', 'tool_denied');
        $this->assertSame('forbidden', $res->json('data.figures.0.status'));
        $this->assertStringContainsString('forecast.view', $res->json('data.figures.0.message'));
        $this->assertNull($res->json('data.figures.0.data'));
        // La API directa también se lo niega con ese token: el asistente no amplía permisos.
        $this->withToken($token)->withHeader('X-Tenant-ID', $tenant->uuid)->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_plan_horizons_are_respected_and_a_forbidden_horizon_reveals_nothing(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $this->seedPredictionRun($tenant, $this->seedCustomerCatalogFor($tenant));
        $api = $this->actingAsTenantUser($user, $tenant);

        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 90]), Fake::tool('customer_predictions', ['horizon' => 90]), Fake::text('Sin acceso.')]);
        $res = $api->postJson('/api/v1/assistant/messages', ['message' => 'dame el pronóstico a 90 días'])->assertOk()->assertJsonPath('data.status', 'tool_denied');
        $this->assertSame(['plan_limit', 'plan_limit'], array_column($res->json('data.figures'), 'status'));
        $this->assertNull($res->json('data.figures.0.data'));

        // Un horizonte permitido sí responde.
        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text('Consultado.')]);
        $api->postJson('/api/v1/assistant/messages', ['message' => 'a 30 días'])->assertOk()->assertJsonPath('data.figures.0.status', 'ok');
    }

    // ── aislamiento entre empresas ──
    public function test_a_company_never_sees_another_companys_results(): void
    {
        [$tenantA, $userA] = $this->demandTenant();
        $this->base = ['p1' => 500.0, 'p2' => 400.0, 'p3' => 300.0];
        [$tenantB, $userB, $catB] = $this->demandTenant();
        DB::table('products')->where('tenant_id', $tenantB->id)->update(['name' => 'Producto Reservado de B']);
        $this->seedSupplyPlan($tenantB, $catB, supplierName: 'Proveedor Exclusivo de B');
        $this->seedPredictionRun($tenantB, $this->seedCustomerCatalogFor($tenantB, 'CLI-SOLO-B'));
        $this->seedPredictionRun($tenantA, $this->seedCustomerCatalogFor($tenantA));

        $this->base = ['p1' => 10.0, 'p2' => 4.0, 'p3' => 1.0];
        $askA = function (string $tool, array $args) use ($userA, $tenantA) {
            $this->fakeProvider([Fake::tool($tool, $args), Fake::text('Consultado.')]);

            return $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/assistant/messages', ['message' => 'consulta'])->assertOk();
        };

        $dash = $askA('dashboard_summary', ['horizon' => 30]);
        $this->assertEqualsWithDelta($this->expectedForecastUnits(30), $dash->json('data.figures.0.data.kpis.forecast_units'), 0.05);
        $this->assertSame($tenantA->id, $dash->json('data.figures.0.envelope.tenant_id'));

        $product = $askA('product_forecast', ['product' => 'Reservado']);
        $this->assertSame('no_data', $product->json('data.figures.0.status'));
        $this->assertSame(0, $product->json('data.figures.0.data.total_matches'));
        $this->assertStringNotContainsString('Reservado', $product->getContent());

        // product_id de B: la validación acotada a la empresa lo rechaza antes de consultar.
        $foreignProductId = $catB['products']['p1'];
        $byId = $askA('product_forecast', ['product_id' => $foreignProductId]);
        $this->assertSame('invalid_arguments', $byId->json('data.figures.0.status'));
        $this->assertStringNotContainsString('Reservado', $byId->getContent());

        $plan = $askA('supply_plan', []);
        $this->assertSame('no_data', $plan->json('data.figures.0.status'));
        $this->assertStringNotContainsString('Exclusivo', $plan->getContent());

        $customers = $askA('customer_predictions', ['customer_code' => 'CLI-SOLO-B', 'horizon' => 30]);
        $this->assertSame('no_data', $customers->json('data.figures.0.status'));
        $this->assertSame(0, $customers->json('data.figures.0.data.total_matches'));
        $this->assertSame([], $customers->json('data.figures.0.data.predictions'));
        $own = $askA('customer_predictions', ['customer_code' => 'CLI-0001', 'horizon' => 30]);
        $this->assertSame($tenantA->id, $own->json('data.figures.0.envelope.tenant_id'));
        $this->assertSame(2, $own->json('data.figures.0.data.total_matches'));

        // Y B ve lo suyo.
        $this->fakeProvider([Fake::tool('supply_plan', []), Fake::text('Consultado.')]);
        $this->actingAsTenantUser($userB, $tenantB)->postJson('/api/v1/assistant/messages', ['message' => 'plan'])->assertOk()
            ->assertJsonPath('data.figures.0.status', 'ok')->assertJsonPath('data.figures.0.data.lines.0.supplier.name', 'Proveedor Exclusivo de B');
    }

    // ── cifras fundamentadas ──
    public function test_text_with_invented_or_derived_numbers_is_discarded_but_the_figures_remain(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $api = $this->actingAsTenantUser($user, $tenant);

        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text('Venderá 987654 unidades, un 41 % más que antes.')]);
        $res = $api->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertOk()->assertJsonPath('data.status', 'ungrounded_rejected');
        $this->assertContains('987654', $res->json('data.grounding.ungrounded_numbers'));
        $this->assertStringNotContainsString('987654', $res->json('data.answer'));
        $this->assertSame('ok', $res->json('data.figures.0.status'));

        // Un total derivado por el modelo (suma de dos cifras de la herramienta) tampoco es una cifra de la herramienta.
        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), function (array $messages) {
            $k = Fake::lastToolResult($messages)['data']['kpis'];

            return Fake::text('En total serían '.round($k['forecast_units'] + $k['previous_period_units'], 2).' unidades.');
        }]);
        $api->postJson('/api/v1/assistant/messages', ['message' => 'suma'])->assertOk()->assertJsonPath('data.status', 'ungrounded_rejected');
    }

    public function test_dates_percentages_and_list_markers_from_the_results_are_accepted(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([Fake::tool('forecast_accuracy', []), function (array $messages) {
            $wape = Fake::lastToolResult($messages)['data']['backtest']['selected']['wape'];

            return Fake::text("Precisión del backtesting:\n1. WAPE del modelo elegido: ".round($wape * 100, 1).' % (referencia P90 no aplica).');
        }]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'precisión'])->assertOk()
            ->assertJsonPath('data.status', 'answered');
    }

    // ── límites ──
    public function test_message_size_is_limited(): void
    {
        config(['conversation.limits.max_message_chars' => 50]);
        $this->fakeProvider([]);
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->postJson('/api/v1/assistant/messages', ['message' => str_repeat('a', 51)])->assertStatus(422)->assertJsonValidationErrors('message');
        $api->postJson('/api/v1/assistant/messages', [])->assertStatus(422);
        $api->postJson('/api/v1/assistant/messages', ['message' => 'ok', 'history' => [['role' => 'system', 'content' => 'x']]])->assertStatus(422);
        $api->postJson('/api/v1/assistant/messages', ['message' => 'ok', 'history' => array_fill(0, 7, ['role' => 'user', 'content' => 'x'])])->assertStatus(422);
    }

    public function test_rate_per_user_and_daily_quota_per_company_are_enforced(): void
    {
        config(['conversation.limits.rate_per_minute' => 2, 'conversation.limits.daily_limit_per_tenant' => 100]);
        [$tenant, $user] = $this->createTenantWithUser();
        $second = $this->addUserToTenant($tenant, TenantRole::Viewer);
        [$other, $otherUser] = $this->createTenantWithUser();
        $this->fakeProvider([Fake::text('a'), Fake::text('b'), Fake::text('c'), Fake::text('d'), Fake::text('e')]);

        $post = fn ($u, $t) => $this->actingAsTenantUser($u, $t)->postJson('/api/v1/assistant/messages', ['message' => 'hola']);
        $post($user, $tenant)->assertOk();
        $post($user, $tenant)->assertOk();
        $post($user, $tenant)->assertStatus(429)->assertJsonPath('code', 'assistant_rate_limited')->assertHeader('Retry-After');
        $post($second, $tenant)->assertOk();                  // otro usuario de la misma empresa
        $post($otherUser, $other)->assertOk();                // otra empresa: tasa independiente

        config(['conversation.limits.rate_per_minute' => 0, 'conversation.limits.daily_limit_per_tenant' => 3]);
        $this->fakeProvider([Fake::text('x'), Fake::text('y')]);
        // Ya van 3 mensajes aceptados de `tenant` (2 + 1): el tope diario de 3 se alcanzó.
        $post($user, $tenant)->assertStatus(429)->assertJsonPath('code', 'assistant_quota_exceeded');
        $post($otherUser, $other)->assertOk();                // el tope es por empresa
    }

    // ── privacidad, logs y auditoría ──
    public function test_personal_data_is_masked_before_reaching_the_provider_and_nothing_sensitive_is_logged(): void
    {
        config(['logging.channels.assistant_test' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'logging.default' => 'assistant_test']);
        /** @var TestHandler $handler */
        $handler = Log::channel('assistant_test')->getLogger()->getHandlers()[0];

        [$tenant, $user] = $this->demandTenant();
        $message = 'Resumen para ana.perez@correo.com con cédula 1710034065 y RUC 1790012345001';
        $provider = $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text('Consultado.')]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => $message, 'history' => [['role' => 'user', 'content' => 'mi correo es luis@empresa.ec']]])->assertOk();

        $sent = json_encode($provider->calls, JSON_UNESCAPED_UNICODE);
        foreach (['ana.perez@correo.com', '1710034065', '1790012345001', 'luis@empresa.ec'] as $secret) {
            $this->assertStringNotContainsString($secret, $sent);
        }
        $this->assertStringContainsString('a***@c***.com', $sent);
        // Ni el nombre de la empresa ni el correo del usuario viajan al proveedor.
        $this->assertStringNotContainsString((string) $tenant->name, $sent);
        $this->assertStringNotContainsString((string) $user->email, $sent);

        // Auditoría: solo metadatos (herramientas, estados, conteos), nunca el texto.
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'assistant.message')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->id, (int) $audit->user_id);
        $meta = (array) json_decode((string) $audit->metadata, true);
        $this->assertSame('answered', $meta['status']);
        $this->assertSame(['dashboard_summary'], $meta['tools']);
        $this->assertSame(['ok' => 1], $meta['tool_statuses']);
        $this->assertSame(mb_strlen($message), $meta['message_chars']);
        $this->assertStringNotContainsString('Resumen para', (string) $audit->metadata);
        $this->assertStringNotContainsString('Consultado.', (string) $audit->metadata);

        // Logs: solo metadatos; ni mensaje, ni respuesta, ni datos de las herramientas, ni datos personales.
        $dump = implode("\n", array_map(fn ($r) => $r->message.' '.json_encode($r->context, JSON_UNESCAPED_UNICODE), $handler->getRecords()));
        $this->assertStringContainsString('assistant.message', $dump);
        foreach (['ana.perez', '1710034065', 'Resumen para', 'Consultado.', 'forecast_units'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }
    }

    public function test_provider_failure_returns_502_without_leaking_details_and_does_not_affect_the_cores(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([new ProviderException('El proveedor conversacional respondió con estado HTTP 500.', 'provider_error', 500)]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $res = $api->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertStatus(502)->assertJsonPath('code', 'assistant_provider_error');
        $this->assertStringNotContainsString('500', (string) $res->json('message'));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'assistant.provider_error']);
        $api->getJson('/api/v1/dashboard/summary?horizon=30')->assertOk();   // los resultados siguen disponibles
    }

    public function test_customer_tool_can_be_disabled_by_configuration(): void
    {
        config(['conversation.privacy.customer_tools_enabled' => false]);
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $provider = $this->fakeProvider([Fake::tool('customer_predictions', []), Fake::text('No.')]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'compradores'])->assertOk();
        $this->assertNotContains('customer_predictions', array_column($provider->calls[0]['tools'], 'name'));
        $this->assertSame('tool_not_allowed', $res->json('data.figures.0.status'));
        $this->assertNull($res->json('data.figures.0.data'));
    }

    /** Catálogo del Customer Core de un tenant que ya tiene otro catálogo de demanda (códigos de sucursal/sku propios). */
    private function seedCustomerCatalogFor(\App\Models\Tenant $tenant, string $customerCode = 'CLI-0001'): array
    {
        $now = now();
        $tid = $tenant->id;
        $branch = (int) DB::table('branches')->where('tenant_id', $tid)->value('id');
        $products = ['p1' => (int) DB::table('products')->where('tenant_id', $tid)->where('sku', 'P1-SKU')->value('id'), 'p2' => (int) DB::table('products')->where('tenant_id', $tid)->where('sku', 'P2-SKU')->value('id')];
        $customers = [];
        foreach (['c1' => [$customerCode, 'company', false], 'c2' => ['CLI-0002', 'person', false], 'c3' => ['9999999999999', 'final_consumer', true]] as $key => [$code, $type, $generic]) {
            $customers[$key] = (int) DB::table('customers')->insertGetId([
                'tenant_id' => $tid, 'external_code' => $code, 'customer_type' => $type, 'is_generic' => $generic, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['branch' => $branch, 'products' => $products, 'customers' => $customers];
    }
}
