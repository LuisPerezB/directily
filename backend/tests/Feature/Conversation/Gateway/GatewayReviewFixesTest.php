<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway;

use App\Enums\TenantRole;
use App\Models\GatewayToolCall;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Commercial\CommercialScope as AuthScope;
use App\Services\Auth\Commercial\CommercialScopeContext;
use App\Services\Conversation\Channels\ChannelRegistry;
use App\Services\Conversation\Gateway\Security\InjectionGuard;
use App\Services\Conversation\ProviderResponse;
use App\Services\Conversation\Tools\ToolRegistry;
use App\Services\Conversation\Tools\ToolResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Conversation\Gateway\Support\FakeMessagingChannel;
use Tests\Feature\Conversation\Gateway\Support\FakeTool;
use Tests\Feature\Conversation\Gateway\Support\GatewayWorld;
use Tests\Feature\Conversation\Support\FakeConversationProvider as Fake;
use Tests\TestCase;

/**
 * V2.5 · W6-D: correcciones de la revisión adversarial (M-1, M-2, L-1, L-2, L-5, L-6) y las pruebas que faltaban (mutantes sobrevivientes): exclusión del grupo de control,
 * búsqueda exacta del nombre de la herramienta, latencia, plazo, PiiMasker hacia el proveedor, restauración del alcance comercial y existencia del cliente de un borrador.
 */
class GatewayReviewFixesTest extends TestCase
{
    use AnalyticsFixtures;
    use GatewayWorld;
    use RefreshDatabase;

    private const CUTOFF = '2026-10-20';

    /** @return array{0: Tenant, 1: User, 2: array<string, mixed>} */
    private function demandTenant(TenantRole $role = TenantRole::Analyst): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        return [$tenant, $user, $cat];
    }

    private function lastCall(): GatewayToolCall
    {
        return GatewayToolCall::withoutTenantScope()->orderByDesc('id')->firstOrFail();
    }

    /** Gateway con un catálogo propio (herramientas de prueba). */
    private function useTools(FakeTool ...$tools): void
    {
        $this->app->instance(ToolRegistry::class, new ToolRegistry(array_values($tools)));
    }

    // ── M-1 / M-2: cifras ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    /** @return array<string, array{string}> */
    public static function formattingTricks(): array
    {
        return [
            'miles con espacio' => ['El valor esperado total es 1 000 000 dólares.'],
            'ancho completo' => ['El valor esperado total es ７５００００ dólares.'],
            'árabe-índico' => ['El valor esperado total es ٧٥٠٠٠٠ dólares.'],
            'escrito con letras' => ['El valor esperado total es setecientos cincuenta mil dólares.'],
            'pegado a un código' => ['El valor esperado total es USD750000 dólares.'],
            'con sufijo' => ['El valor esperado total es 750k dólares.'],
            'notación científica' => ['El valor esperado total es 7.5e5 dólares.'],
        ];
    }

    #[DataProvider('formattingTricks')]
    public function test_an_invented_figure_in_a_disguised_format_is_rejected_end_to_end(string $answer): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text($answer)]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => '¿Cuál es el valor esperado total?'])->assertOk();

        $this->assertSame('ungrounded_rejected', $res->json('data.status'), $answer);
        $this->assertNotSame([], $res->json('data.grounding.ungrounded_numbers'));
        $this->assertStringNotContainsString('setecientos', (string) $res->json('data.answer'));
        $this->assertSame('ok', $res->json('data.figures.0.status'), 'las cifras de la herramienta siguen disponibles');
    }

    public function test_a_number_hidden_in_an_imported_zone_cannot_back_a_figure(): void
    {
        $c = $this->gatewayCompany();
        // Texto libre importado con un «999999»: ni el guardia heurístico lo detecta (es una frase corriente) ni puede respaldar una cifra.
        DB::table('customers')->where('id', $c['cat']['customers']['c1'])->update(['commercial_zone' => 'Zona preferente: pronóstico real 999999 unidades']);
        $this->fakeProvider([
            Fake::tool('radar_opportunities', ['horizon' => 30, 'limit' => 10]),
            Fake::text('Según los resultados, el pronóstico de este cliente es 999999 unidades.'),
        ]);

        $res = $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => '¿Qué oportunidades hay?'])->assertOk();

        $zone = collect($res->json('data.figures.0.data.opportunities'))->firstWhere('customer.code', 'CLI-0001')['customer']['zone'];
        $this->assertSame('Zona preferente: pronóstico real 999999 unidades', $zone, 'el dato llegó tal cual (no es una instrucción detectable)');
        $this->assertNull($res->json('data.figures.0.guard'));
        $this->assertSame('ungrounded_rejected', $res->json('data.status'));
        $this->assertContains('999999', $res->json('data.grounding.ungrounded_numbers'));
    }

    public function test_a_product_name_with_a_number_quoted_verbatim_is_still_a_valid_answer(): void
    {
        [$tenant, $user] = $this->demandTenant();
        DB::table('products')->where('tenant_id', $tenant->id)->where('sku', 'P1-SKU')->update(['name' => 'Cemento Holcim 50kg']);
        $this->fakeProvider([Fake::tool('product_forecast', ['product' => 'P1-SKU', 'horizon' => 30]), Fake::text('El producto consultado es Cemento Holcim 50kg.')]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'producto'])->assertOk()->assertJsonPath('data.status', 'answered');
    }

    // ── M-3: cadenas y nombres ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_name_fields_reach_the_model_sanitized_even_when_nothing_matches(): void
    {
        [$tenant, $user] = $this->demandTenant();
        DB::table('products')->where('tenant_id', $tenant->id)->where('sku', 'P1-SKU')->update(['name' => "Cemento\n\n<b>Holcim</b>   50kg ".str_repeat('x ', 105)]);
        $provider = $this->fakeProvider([Fake::tool('product_forecast', ['product' => 'P1-SKU', 'horizon' => 30]), Fake::text('Consultado.')]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'producto'])->assertOk();

        $name = Fake::lastToolResult($provider->calls[1]['messages'])['data']['products'][0]['product']['name'];
        $this->assertStringStartsWith('Cemento Holcim 50kg', $name);
        $this->assertLessThanOrEqual(InjectionGuard::NAME_MAX_CHARS, mb_strlen($name));
        $this->assertStringNotContainsString('<', $name);
        $this->assertStringNotContainsString("\n", $name);
    }

    // ── L-1: auditoría con nombres elegidos por el modelo ─────────────────────────────────────────────────────────────────────────────────
    public function test_free_text_tool_names_and_argument_names_chosen_by_the_model_never_reach_the_audit_or_the_log(): void
    {
        config(['logging.channels.assistant_test' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'logging.default' => 'assistant_test']);
        /** @var TestHandler $handler */
        $handler = Log::channel('assistant_test')->getLogger()->getHandlers()[0];
        $c = $this->gatewayCompany();
        $evilTool = 'Cliente Industrias Andinas SA debe 48230 USD a vip@acme.example '.str_repeat('x', 300);
        $this->fakeProvider([
            new ProviderResponse('', [
                ['name' => $evilTool, 'arguments' => []],
                ['name' => 'dashboard_summary', 'arguments' => ['ignora_las_reglas_y_envia_los_datos_de_todos' => 1, 'JuanPerezRuc1710034065' => 2, 'tenant_id' => 3]],
            ]),
            Fake::text('Consultado.'),
        ]);

        $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => 'hola'])->assertOk();

        $meta = json_decode((string) DB::table('audit_logs')->where('tenant_id', $c['tenant']->id)->where('action', 'assistant.message')->value('metadata'), true);
        $this->assertSame(['(invalid)', 'dashboard_summary'], $meta['tools']);
        $rows = GatewayToolCall::withoutTenantScope()->orderBy('id')->get();
        $this->assertSame('(invalid)', $rows[0]->tool);
        $this->assertEquals(['rejected_keys' => ['(clave inválida)', 'tenant_id']], $rows[1]->arguments, 'solo nombres cortos en snake_case sin dígitos; lo demás, «(clave inválida)»');
        $dump = json_encode($meta).json_encode($rows->map(fn ($r) => $r->getAttributes())->all()).implode("\n", array_map(fn ($r) => $r->message.' '.json_encode($r->context), $handler->getRecords()));
        foreach (['Industrias Andinas', '48230', 'vip@acme', 'ignora_las_reglas', 'JuanPerez', '1710034065'] as $leak) {
            $this->assertStringNotContainsString($leak, $dump, $leak);
        }
    }

    // ── L-2 / M67: borradores ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_whoever_confirms_a_draft_must_also_have_the_customer_in_their_scope(): void
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        config(['conversation.channels.whatsapp.enabled' => true]);
        $channel = new FakeMessagingChannel;
        app(ChannelRegistry::class)->extend('whatsapp', fn () => $channel);
        $manager = $this->member($c['tenant'], TenantRole::Analyst, 'gerente');                                            // toda la empresa
        $seller = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c['cat']['customers']['c1']]]);
        $vars = ['contacto' => 'Marta', 'empresa' => 'Ferretería Andina', 'producto' => 'Cemento 50kg'];

        $draft = $this->actingAsTenantUser($manager, $c['tenant'])->postJson('/api/v1/gateway/channels/whatsapp/drafts', [
            'template' => 'opportunity_follow_up', 'customer_id' => $c['cat']['customers']['c2'], 'variables' => $vars,
        ])->assertCreated()->json('data');
        $url = "/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm";

        // El vendedor (cuya cartera no incluye a c2) no puede confirmarlo: misma respuesta que un borrador inexistente, y el borrador sigue pendiente.
        $foreign = $this->actingAsTenantUser($seller, $c['tenant'])->postJson($url, ['body_sha256' => $draft['body_sha256']])->assertNotFound()->assertJsonPath('code', 'draft_not_found');
        $missing = $this->actingAsTenantUser($seller, $c['tenant'])->postJson('/api/v1/gateway/channels/whatsapp/drafts/2f1e9a64-6a0f-4d86-8b7c-0d6a3f0d9a11/confirm', ['body_sha256' => $draft['body_sha256']])->assertNotFound();
        $this->assertSame($missing->json(), $foreign->json());
        $this->assertSame([], $channel->sent);

        $this->actingAsTenantUser($manager, $c['tenant'])->postJson($url, ['body_sha256' => $draft['body_sha256']])->assertOk();
        $this->assertCount(1, $channel->sent);
    }

    public function test_a_draft_for_a_customer_that_does_not_exist_is_a_404_even_without_a_commercial_scope(): void
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        config(['conversation.channels.whatsapp.enabled' => true]);
        app(ChannelRegistry::class)->extend('whatsapp', fn () => new FakeMessagingChannel);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);   // analista sin rol comercial: el alcance no restringe a nadie

        $api->postJson('/api/v1/gateway/channels/whatsapp/drafts', ['template' => 'stock_available_notice', 'customer_id' => 999999, 'variables' => ['contacto' => 'Marta', 'empresa' => 'A', 'producto' => 'B']])
            ->assertNotFound();
        $api->postJson('/api/v1/gateway/channels/whatsapp/drafts', ['template' => 'stock_available_notice', 'customer_id' => $c['cat']['customers']['c1'], 'variables' => ['contacto' => 'Marta', 'empresa' => 'A', 'producto' => 'B']])
            ->assertCreated();
    }

    // ── L-5: un rol comercial desconocido cierra igual por HTTP y por el resolvedor ──────────────────────────────────────────────────────
    public function test_an_unknown_stored_commercial_role_fails_closed_the_same_way_over_http_and_through_the_resolver(): void
    {
        $c = $this->gatewayCompany();
        $odd = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        try {
            DB::table('tenant_users')->where('tenant_id', $c['tenant']->id)->where('user_id', $odd->id)->update(['commercial_role' => 'auditor-externo']);
        } catch (QueryException) {
            $this->markTestSkipped('La base rechaza roles comerciales desconocidos (restricción CHECK de W6-A): el caso ya no puede darse.');
        }
        $this->fakeProvider([]);

        $http = $this->actingAsTenantUser($odd, $c['tenant'])->getJson('/api/v1/assistant/status')->assertOk()->json('data');
        $viaHttp = array_values(array_map(fn ($t) => $t['name'], array_filter($http['tools'], fn ($t) => $t['available_to_you'])));
        $viaResolver = array_map(fn ($t) => $t->name(), $this->gateway()->availableFor($this->ctx($c['tenant'], $odd)));

        $this->assertSame(['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'supply_plan', 'model_quality'], $viaHttp);
        $this->assertSame($viaHttp, $viaResolver);
        $this->assertSame(['role' => 'unresolved', 'restricted' => true, 'source' => 'unresolved'], $http['commercial_scope']);
        $this->assertSame($http['commercial_scope'], $this->gateway()->scopeFor($this->ctx($c['tenant'], $odd))->describe());

        // Y las herramientas por cliente se niegan igual cuando el modelo las pide.
        $this->fakeProvider([Fake::tool('customer_predictions', ['horizon' => 30]), Fake::tool('roi_summary', []), Fake::text('Sin acceso.')]);
        $res = $this->actingAsTenantUser($odd, $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => 'datos'])->assertOk();
        $this->assertSame(['scope_denied', 'scope_denied'], array_column($res->json('data.figures'), 'status'));
    }

    // ── mutantes sobrevivientes ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_the_control_group_of_an_experiment_is_never_explained_counted_or_listed(): void
    {
        $c = $this->gatewayCompany();
        $admin = $this->member($c['tenant'], TenantRole::Admin);   // puede aprobar: ve el control en la API
        $analyst = $this->ctx($c['tenant'], $c['user']);
        $approver = $this->ctx($c['tenant'], $admin);
        $control = $c['recs']['c1'];
        DB::table('decision_recommendations')->where('id', $control)->update(['experiment_group' => 'control']);

        // explain_recommendation: quien no aprueba no la ve (igual que una inexistente); quien aprueba sí (la API se lo permite).
        $this->assertEquals(
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => 999999], $analyst)->toArray(),
            $this->gateway()->call('explain_recommendation', ['recommendation_id' => $control], $analyst)->toArray(),
        );
        $this->assertSame('ok', $this->gateway()->call('explain_recommendation', ['recommendation_id' => $control], $approver)->status);

        // decisions_summary, outcomes y la lista del Radar: el control no se cuenta ni se lista NUNCA, tampoco para quien aprueba (el modelo no conoce los experimentos).
        $visible = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where(fn ($q) => $q->whereNull('experiment_group')->orWhere('experiment_group', '<>', 'control'))->count();
        $all = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->count();
        $this->assertLessThan($all, $visible);
        foreach ([$analyst, $approver] as $ctx) {
            $this->assertSame($visible, $this->gateway()->call('decisions_summary', [], $ctx)->data['total']);
            $outcomes = $this->gateway()->call('outcomes', ['limit' => 10], $ctx)->data;
            $this->assertSame(1, $outcomes['total_matches'], 'el resultado medido del control no se cuenta');
            $this->assertSame(0, $outcomes['by_classification']['temporally_associated']);
            $this->assertNotContains($control, array_map(fn ($o) => $o['recommendation']['id'], $outcomes['outcomes']));
            $radar = $this->gateway()->call('radar_opportunities', ['limit' => 10], $ctx)->data;
            $this->assertNotContains($control, array_column($radar['opportunities'], 'id'));
            foreach ($radar['opportunities'] as $opportunity) {
                $this->assertArrayNotHasKey('experiment_group', $opportunity);
            }
        }
        $this->assertStringNotContainsString('control', json_encode($this->gateway()->call('decisions_summary', [], $approver)->toArray()), 'el modelo no conoce los experimentos');
    }

    public function test_the_tool_name_lookup_is_exact(): void
    {
        $c = $this->gatewayCompany();
        $ctx = $this->ctx($c['tenant'], $c['user']);

        foreach (['Dashboard_Summary', ' dashboard_summary', 'dashboard_summary ', 'DASHBOARD_SUMMARY', "dashboard_summary\0", 'dashboard-summary'] as $name) {
            $result = $this->gateway()->call($name, [], $ctx);
            $this->assertSame('tool_not_allowed', $result->status, json_encode($name));
            $this->assertSame('denied_policy', $this->lastCall()->decision);
        }
        $this->assertNotSame('tool_not_allowed', $this->gateway()->call('dashboard_summary', [], $ctx)->status);
    }

    public function test_the_audit_records_the_real_latency_of_the_call(): void
    {
        $c = $this->gatewayCompany();
        $this->useTools(new FakeTool('slow_tool', function (): ToolResult {
            usleep(60_000);

            return ToolResult::ok(['rows' => []]);
        }));

        $this->gateway()->call('slow_tool', [], $this->ctx($c['tenant'], $c['user']));

        $latency = $this->lastCall()->latency_ms;
        $this->assertGreaterThanOrEqual(55, $latency);
        $this->assertLessThan(3000, $latency);
    }

    public function test_the_provider_receives_tool_data_with_personal_data_masked(): void
    {
        [$tenant, $user] = $this->demandTenant();
        DB::table('products')->where('tenant_id', $tenant->id)->where('sku', 'P1-SKU')->update(['name' => 'Cemento (contacto ana.perez@correo.com cédula 1710034065)']);
        $provider = $this->fakeProvider([Fake::tool('product_forecast', ['product' => 'P1-SKU', 'horizon' => 30]), Fake::text('Consultado.')]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'producto'])->assertOk();

        $sent = json_encode($provider->calls, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ana.perez@correo.com', $sent);
        $this->assertStringNotContainsString('1710034065', $sent);
        $this->assertStringContainsString('a***@c***.com', $sent);
        $this->assertStringContainsString('[ID]', $sent);
    }

    public function test_the_commercial_scope_context_is_restored_after_every_call(): void
    {
        $c = $this->gatewayCompany();
        $sentinel = new AuthScope(12345);   // el alcance que tenía la petición antes de llamar al gateway
        $context = app(CommercialScopeContext::class);
        $context->set($sentinel);
        $seller = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c['cat']['customers']['c1']]]);

        $this->gateway()->call('radar_opportunities', [], $this->ctx($c['tenant'], $seller));
        $this->assertSame($sentinel, $context->current(), 'tras una llamada permitida');

        $this->gateway()->call('radar_opportunities', ['foo' => 1], $this->ctx($c['tenant'], $seller));
        $this->assertSame($sentinel, $context->current(), 'tras una llamada rechazada por argumentos');

        $this->useTools(new FakeTool('boom_tool', fn () => throw new \RuntimeException('falla de la herramienta')));
        $this->gateway()->call('boom_tool', [], $this->ctx($c['tenant'], $seller));
        $this->assertSame($sentinel, $context->current(), 'tras una excepción de la herramienta');
    }

    // ── L-6: plazos y saneado de la respuesta ─────────────────────────────────────────────────────────────────────────────────────────────
    public function test_the_conversation_deadline_is_checked_before_executing_tools_and_before_every_provider_call(): void
    {
        config(['conversation.limits.deadline_seconds' => 1, 'conversation.limits.rate_per_minute' => 0]);
        [$tenant, $user] = $this->demandTenant();
        $api = $this->actingAsTenantUser($user, $tenant);

        // 1. El proveedor tarda más que el plazo: no se ejecuta ninguna herramienta.
        $provider = $this->fakeProvider([function () {
            usleep(1_150_000);

            return Fake::tool('dashboard_summary', ['horizon' => 30]);
        }, Fake::text('no debería llegar')]);
        $res = $api->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertOk();
        $this->assertCount(1, $provider->calls);
        $this->assertSame(0, GatewayToolCall::withoutTenantScope()->count(), 'sin tiempo no se ejecuta ninguna herramienta');
        $this->assertSame('no_applicable_tool', $res->json('data.status'));

        // 2. Una herramienta lenta agota el plazo: no hay otra llamada al proveedor.
        $this->useTools(new FakeTool('slow_tool', function (): ToolResult {
            usleep(1_150_000);

            return ToolResult::ok(['rows' => [['a' => 1]]]);
        }));
        $provider = $this->fakeProvider([Fake::tool('slow_tool', []), Fake::text('no debería llegar')]);
        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'lento'])->assertOk();
        $this->assertCount(1, $provider->calls, 'vencido el plazo no se vuelve a llamar al proveedor');
        $this->assertSame(1, GatewayToolCall::withoutTenantScope()->count());
        $this->assertSame('no_answer', $res->json('data.status'));
    }

    public function test_a_number_smuggled_through_an_identifier_argument_does_not_ground_a_figure(): void
    {
        $c = $this->gatewayCompany();
        // El modelo mete un número en un argumento de TEXTO con clave de identificación (customer_code): no cuenta como cifra de la herramienta.
        $this->fakeProvider([Fake::tool('customer_predictions', ['customer_code' => 'ZZ-777123', 'horizon' => 30]), Fake::text('Hay 777123 unidades esperadas.')]);

        $res = $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => 'datos'])->assertOk();

        $this->assertSame('ungrounded_rejected', $res->json('data.status'));
        $this->assertContains('777123', $res->json('data.grounding.ungrounded_numbers'));
    }

    public function test_a_second_tool_call_of_the_same_response_is_not_executed_once_the_deadline_has_passed(): void
    {
        config(['conversation.limits.deadline_seconds' => 1, 'conversation.limits.rate_per_minute' => 0]);
        [$tenant, $user] = $this->demandTenant();
        $this->useTools(
            new FakeTool('slow_tool', function (): ToolResult {
                usleep(1_150_000);

                return ToolResult::ok(['rows' => [['a' => 1]]]);
            }),
            new FakeTool('second_tool', fn () => ToolResult::ok(['rows' => [['b' => 2]]])),
        );
        $this->fakeProvider([new ProviderResponse('', [['name' => 'slow_tool', 'arguments' => []], ['name' => 'second_tool', 'arguments' => []]]), Fake::text('no debería llegar')]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'dos herramientas'])->assertOk();

        $this->assertSame(['slow_tool'], GatewayToolCall::withoutTenantScope()->orderBy('id')->pluck('tool')->all(), 'la segunda herramienta no se ejecutó');
        $this->assertCount(1, $res->json('data.figures'));
    }

    public function test_a_tool_that_exceeds_its_timeout_is_discarded_and_audited_as_a_timeout(): void
    {
        config(['conversation.gateway.tool_timeout_seconds' => 1]);
        $c = $this->gatewayCompany();
        $ctx = $this->ctx($c['tenant'], $c['user']);

        // Plazo blando: la ejecución completa superó el plazo → el resultado se descarta.
        $this->useTools(new FakeTool('slow_tool', function (): ToolResult {
            usleep(1_150_000);

            return ToolResult::ok(['secret' => 'no debe entregarse']);
        }));
        $result = $this->gateway()->call('slow_tool', [], $ctx);
        $this->assertSame('error', $result->status);
        $this->assertNull($result->data);
        $this->assertSame(['reason' => 'timeout', 'status' => 'error'], $this->lastCall()->result_summary);
        $this->assertSame('error', $this->lastCall()->decision);

        // Plazo duro: PostgreSQL cancela la consulta larga (statement_timeout local a la herramienta).
        $this->useTools(new FakeTool('db_sleep', function (): ToolResult {
            DB::select('select pg_sleep(4)');

            return ToolResult::ok(['rows' => []]);
        }));
        $started = microtime(true);
        $result = $this->gateway()->call('db_sleep', [], $ctx);
        $this->assertLessThan(3.0, microtime(true) - $started, 'la consulta se cortó cerca del plazo, no a los 4 s');
        $this->assertSame('error', $result->status);
        $this->assertSame('timeout', $this->lastCall()->result_summary['reason']);

        // El plazo no queda aplicado a lo que sigue.
        $before = DB::selectOne("select current_setting('statement_timeout') as v")->v;
        $this->useTools(new FakeTool('quick_tool', fn () => ToolResult::ok(['rows' => []])));
        $this->assertSame('ok', $this->gateway()->call('quick_tool', [], $ctx)->status);
        $this->assertSame($before, DB::selectOne("select current_setting('statement_timeout') as v")->v);
    }

    public function test_the_timeout_can_be_switched_off(): void
    {
        config(['conversation.gateway.tool_timeout_seconds' => 0]);
        $c = $this->gatewayCompany();
        $this->useTools(new FakeTool('quick_tool', fn () => ToolResult::ok(['rows' => []])));

        $this->assertSame('ok', $this->gateway()->call('quick_tool', [], $this->ctx($c['tenant'], $c['user']))->status);
    }

    public function test_links_and_markup_in_the_models_answer_are_stripped_server_side(): void
    {
        [$tenant, $user] = $this->demandTenant();
        $this->fakeProvider([Fake::tool('dashboard_summary', ['horizon' => 30]), Fake::text(
            "Consulte [aquí](https://evil.example/login) ![p](https://evil.example/p.png) <script>alert(1)</script><b>ahora</b> www.evil.example http://evil.example/y\n\n\n\nFin."
        )]);

        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])->assertOk()->assertJsonPath('data.status', 'answered');

        $answer = (string) $res->json('data.answer');
        $this->assertStringNotContainsString('evil.example', $answer);
        $this->assertStringNotContainsString('<', $answer);
        $this->assertStringNotContainsString('](', $answer);
        $this->assertStringContainsString('aquí', $answer);
        $this->assertStringContainsString('[enlace omitido]', $answer);
        $this->assertStringNotContainsString("\n\n\n", $answer);
    }
}
