<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\Channels\ChannelRegistry;
use App\Services\Conversation\Channels\DraftMessage;
use App\Services\Conversation\Channels\DraftStore;
use App\Services\Conversation\Channels\Exceptions\DraftConfirmationException;
use App\Services\Conversation\Channels\HumanConfirmation;
use App\Services\Conversation\Channels\WebhookSignature;
use App\Services\Conversation\ProviderResponse;
use App\Services\Conversation\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Conversation\Gateway\Support\FakeMessagingChannel;
use Tests\Feature\Conversation\Gateway\Support\GatewayWorld;
use Tests\Feature\Conversation\Support\FakeConversationProvider as Fake;
use Tests\TestCase;

/**
 * V2.5 · W6-D (V25-GWY-019..024; ADR-V25-17): CONTRATO del canal de mensajería (WhatsApp). Sin un proveedor registrado el canal no existe (404) y nada se envía; con uno
 * (aquí un canal simulado «mock») el flujo es borrador → confirmación humana explícita del texto exacto → entrega, una sola vez. El asistente no tiene ninguna vía hacia el canal.
 */
class ChannelContractTest extends TestCase
{
    use GatewayWorld;
    use RefreshDatabase;

    private const VARS = ['contacto' => 'Marta', 'empresa' => 'Ferretería Andina', 'producto' => 'Cemento 50kg'];

    private function enable(bool $fail = false): FakeMessagingChannel
    {
        config(['conversation.channels.whatsapp.enabled' => true]);
        $channel = new FakeMessagingChannel('whatsapp', $fail);
        app(ChannelRegistry::class)->extend('whatsapp', fn () => $channel);

        return $channel;
    }

    /** @return array{tenant: Tenant, user: User, cat: array, run: int} */
    private function company(): array
    {
        return $this->radarCompany(TenantRole::Analyst);
    }

    /** @return array<string, mixed> */
    private function createDraft(array $c, ?int $customer = null, array $variables = self::VARS, string $template = 'opportunity_follow_up'): TestResponse
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/gateway/channels/whatsapp/drafts', [
            'template' => $template, 'customer_id' => $customer ?? $c['cat']['customers']['c1'], 'variables' => $variables,
        ]);
    }

    // ── sin proveedor, el canal no existe ──
    public function test_without_a_provider_the_channel_does_not_exist_and_every_route_answers_404(): void
    {
        $c = $this->company();
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $uuid = '2f1e9a64-6a0f-4d86-8b7c-0d6a3f0d9a11';

        foreach ([
            fn () => $api->getJson('/api/v1/gateway/channels/whatsapp'),
            fn () => $api->postJson('/api/v1/gateway/channels/whatsapp/drafts', ['template' => 'opportunity_follow_up', 'customer_id' => $c['cat']['customers']['c1'], 'variables' => self::VARS]),
            fn () => $api->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$uuid}/confirm", ['body_sha256' => str_repeat('a', 64)]),
            fn () => $api->getJson('/api/v1/gateway/channels/telegram'),
        ] as $request) {
            $request()->assertNotFound()->assertJsonPath('code', 'channel_not_available');
        }
    }

    public function test_the_application_ships_no_provider_and_the_channel_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('conversation.channels.whatsapp.enabled'));
        $this->assertFalse(app(ChannelRegistry::class)->exists('whatsapp'));
        config(['conversation.channels.whatsapp.enabled' => true]);
        $this->assertFalse(app(ChannelRegistry::class)->exists('whatsapp'), 'habilitado por configuración pero sin proveedor registrado: sigue sin existir');
        $c = $this->company();
        $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/gateway/channels/whatsapp')->assertNotFound();
    }

    public function test_a_registered_provider_is_ignored_while_the_channel_is_disabled_or_misnamed(): void
    {
        $c = $this->company();
        $channel = new FakeMessagingChannel('whatsapp');
        app(ChannelRegistry::class)->extend('whatsapp', fn () => $channel);
        $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/gateway/channels/whatsapp')->assertNotFound();   // registrado pero no habilitado

        config(['conversation.channels.whatsapp.enabled' => true]);
        app(ChannelRegistry::class)->extend('whatsapp', fn () => new FakeMessagingChannel('otro'));
        $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/gateway/channels/whatsapp')->assertNotFound();   // un proveedor mal nombrado no se usa
        foreach (['../x', 'WhatsApp', 'what sapp', str_repeat('a', 30)] as $bad) {
            $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/gateway/channels/'.rawurlencode($bad))->assertNotFound();
        }
    }

    // ── con un canal simulado: borrador → confirmación → entrega ──
    public function test_the_channel_lists_its_templates_as_drafts_not_approved_by_any_provider(): void
    {
        $c = $this->company();
        $this->enable();

        $data = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/gateway/channels/whatsapp')->assertOk()->json('data');

        $this->assertSame('whatsapp', $data['channel']);
        $this->assertTrue($data['confirmation_required']);
        $this->assertSame(['opportunity_follow_up', 'stock_available_notice'], array_column($data['templates'], 'key'));
        foreach ($data['templates'] as $template) {
            $this->assertSame('draft', $template['status']);
            $this->assertSame('not_submitted', $template['provider_approval']);
        }
    }

    public function test_creating_a_draft_sends_nothing(): void
    {
        $c = $this->company();
        $channel = $this->enable();

        $res = $this->createDraft($c)->assertCreated();

        $draft = $res->json('data');
        $this->assertSame('pending_confirmation', $draft['status']);
        $this->assertTrue($draft['requires_confirmation']);
        $this->assertSame('Hola Marta, le escribimos de Ferretería Andina. Notamos que Cemento 50kg suele formar parte de sus compras y queríamos saber si necesita reponerlo. ¿Le preparamos una cotización?', $draft['body']);
        $this->assertSame(hash('sha256', $draft['body']), $draft['body_sha256']);
        $this->assertStringContainsString('no se ha enviado nada', $draft['notice']);
        $this->assertSame([], $channel->sent, 'crear un borrador nunca envía');
        $audit = DB::table('audit_logs')->where('tenant_id', $c['tenant']->id)->where('action', 'gateway.channel_draft_created')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('Marta', (string) $audit->metadata, 'la auditoría no guarda el texto del mensaje');
    }

    public function test_only_the_exact_confirmed_text_is_handed_to_the_channel_and_only_once(): void
    {
        $c = $this->company();
        $channel = $this->enable();
        $draft = $this->createDraft($c)->json('data');
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $url = "/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm";

        // Sin la huella o con una huella de OTRO texto: no se envía.
        $api->postJson($url, [])->assertStatus(422);
        $api->postJson($url, ['body_sha256' => 'xyz'])->assertStatus(422);
        $api->postJson($url, ['body_sha256' => hash('sha256', 'otro texto')])->assertStatus(422)->assertJsonPath('code', 'draft_content_changed');
        $this->assertSame([], $channel->sent);

        $ok = $api->postJson($url, ['body_sha256' => strtoupper($draft['body_sha256'])])->assertOk();
        $this->assertSame('confirmed_and_handed_to_channel', $ok->json('data.status'));
        $this->assertSame('mock-1', $ok->json('data.receipt.provider_message_id'));
        $this->assertCount(1, $channel->sent);
        $this->assertSame($draft['body'], $channel->sent[0]->body);
        $this->assertSame($c['user']->id, $channel->sent[0]->confirmedBy);
        $this->assertSame($c['tenant']->id, $channel->sent[0]->tenantId);
        $this->assertSame($c['cat']['customers']['c1'], $channel->sent[0]->customerId);

        // Una segunda confirmación (repetición o carrera) no envía de nuevo, y el texto ya no se conserva.
        $api->postJson($url, ['body_sha256' => $draft['body_sha256']])->assertStatus(409)->assertJsonPath('code', 'draft_already_confirmed');
        $this->assertCount(1, $channel->sent);
        $this->assertNull(app(DraftStore::class)->find($c['tenant']->id, $draft['id']));
        $audit = DB::table('audit_logs')->where('tenant_id', $c['tenant']->id)->where('action', 'gateway.channel_message_confirmed')->first();
        $this->assertNotNull($audit);
        $meta = (array) json_decode((string) $audit->metadata, true);
        $this->assertSame($c['user']->id, $meta['confirmed_by']);
        $this->assertSame(substr($draft['body_sha256'], 0, 16), $meta['body_sha256_prefix']);
        $this->assertStringNotContainsString('Marta', (string) $audit->metadata);
    }

    public function test_creating_and_confirming_require_the_ability_to_manage_decisions(): void
    {
        $c = $this->company();
        $channel = $this->enable();
        $viewer = $this->addUserToTenant($c['tenant'], TenantRole::Viewer);
        $draft = $this->createDraft($c)->json('data');

        $api = $this->actingAsTenantUser($viewer, $c['tenant']);
        $api->postJson('/api/v1/gateway/channels/whatsapp/drafts', ['template' => 'opportunity_follow_up', 'customer_id' => $c['cat']['customers']['c1'], 'variables' => self::VARS])->assertForbidden();
        $api->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm", ['body_sha256' => $draft['body_sha256']])->assertForbidden();
        $this->assertSame([], $channel->sent);

        // Un token que solo puede ver tampoco confirma (aunque el rol del usuario sí pueda).
        $token = $c['user']->createToken('solo-lectura', ['decisions.view'])->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-Tenant-ID', $c['tenant']->uuid)
            ->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm", ['body_sha256' => $draft['body_sha256']])->assertForbidden();
        $this->assertSame([], $channel->sent);

        // Otra persona con la habilidad sí puede confirmar el borrador de su colega.
        $colleague = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $this->actingAsTenantUser($colleague, $c['tenant'])->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm", ['body_sha256' => $draft['body_sha256']])->assertOk();
        $this->assertSame($colleague->id, $channel->sent[0]->confirmedBy);
    }

    public function test_the_human_confirmation_step_rejects_expired_drafts_and_roles_without_the_ability(): void
    {
        $c = $this->company();
        $now = CarbonImmutable::now();
        $draft = new DraftMessage('d1', $c['tenant']->id, 'whatsapp', 'opportunity_follow_up', 1, $c['cat']['customers']['c1'], 'Hola', hash('sha256', 'Hola'), $c['user']->id, $now, $now->addMinutes(10));
        $confirmation = app(HumanConfirmation::class);

        foreach ([
            ['forbidden', fn () => $confirmation->confirm($draft, $c['user'], TenantRole::Viewer, $draft->bodySha256, $now)],
            ['forbidden', fn () => $confirmation->confirm($draft, $c['user'], null, $draft->bodySha256, $now)],
            ['draft_expired', fn () => $confirmation->confirm($draft, $c['user'], TenantRole::Analyst, $draft->bodySha256, $now->addMinutes(11))],
            ['content_changed', fn () => $confirmation->confirm($draft, $c['user'], TenantRole::Analyst, hash('sha256', 'Hola!'), $now)],
        ] as [$reason, $attempt]) {
            try {
                $attempt();
                $this->fail("Debía rechazarse: {$reason}");
            } catch (DraftConfirmationException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        // Una huella que coincide con la declarada pero NO con el texto real del borrador (borrador manipulado) tampoco confirma.
        $tampered = new DraftMessage('d2', $c['tenant']->id, 'whatsapp', 'opportunity_follow_up', 1, 1, 'Texto cambiado', hash('sha256', 'Hola'), $c['user']->id, $now, $now->addMinutes(10));
        $this->expectException(DraftConfirmationException::class);
        $confirmation->confirm($tampered, $c['user'], TenantRole::Analyst, hash('sha256', 'Hola'), $now);
    }

    public function test_an_expired_draft_cannot_be_confirmed_over_http(): void
    {
        $c = $this->company();
        $channel = $this->enable();
        config(['conversation.channels.whatsapp.draft_ttl_minutes' => 5]);
        $draft = $this->createDraft($c)->json('data');

        $this->travel(6)->minutes();

        $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm", ['body_sha256' => $draft['body_sha256']])
            ->assertStatus(404);   // el borrador ya no existe: caducó y el almacén lo descartó
        $this->assertSame([], $channel->sent);
    }

    public function test_a_draft_of_another_company_does_not_exist_for_this_one(): void
    {
        $a = $this->company();
        $b = $this->company();
        $channel = $this->enable();
        $draft = $this->createDraft($a)->json('data');

        $this->actingAsTenantUser($b['user'], $b['tenant'])->postJson("/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm", ['body_sha256' => $draft['body_sha256']])
            ->assertNotFound()->assertJsonPath('code', 'draft_not_found');
        $this->assertSame([], $channel->sent);
        $this->assertNull(app(DraftStore::class)->find($b['tenant']->id, $draft['id']));
    }

    public function test_the_customer_must_exist_and_be_inside_the_users_commercial_scope(): void
    {
        $c = $this->company();
        $channel = $this->enable();
        $seller = $this->member($c['tenant'], TenantRole::Analyst, 'vendedor', ['customer_ids' => [$c['cat']['customers']['c1']]]);
        $api = fn () => $this->actingAsTenantUser($seller, $c['tenant']);
        $body = fn (int $customer) => ['template' => 'stock_available_notice', 'customer_id' => $customer, 'variables' => self::VARS];

        $api()->postJson('/api/v1/gateway/channels/whatsapp/drafts', $body($c['cat']['customers']['c1']))->assertCreated();
        $outside = $api()->postJson('/api/v1/gateway/channels/whatsapp/drafts', $body($c['cat']['customers']['c2']))->assertNotFound();
        $missing = $api()->postJson('/api/v1/gateway/channels/whatsapp/drafts', $body(999999))->assertNotFound();
        $this->assertSame($missing->json(), $outside->json(), 'un cliente ajeno y uno inexistente dan la misma respuesta');
        $this->assertSame([], $channel->sent);
    }

    public function test_invalid_templates_and_variables_are_rejected(): void
    {
        $c = $this->company();
        $this->enable();

        $this->createDraft($c, null, self::VARS, 'plantilla_inexistente')->assertNotFound();
        $this->createDraft($c, null, ['contacto' => 'Marta'])->assertStatus(422)->assertJsonPath('code', 'invalid_template_variables');
        $this->createDraft($c, null, self::VARS + ['extra' => 'x'])->assertStatus(422);
        $this->createDraft($c, null, ['contacto' => 'Marta', 'empresa' => 'Visite https://evil.example', 'producto' => 'x'])->assertStatus(422);
        $this->createDraft($c, null, ['contacto' => '{{empresa}}', 'empresa' => 'A', 'producto' => 'B'])->assertStatus(422);
        $this->createDraft($c, null, ['contacto' => str_repeat('a', 81), 'empresa' => 'A', 'producto' => 'B'])->assertStatus(422);
        $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/gateway/channels/whatsapp/drafts', ['template' => 'opportunity_follow_up'])->assertStatus(422);
    }

    public function test_a_provider_failure_is_a_502_that_is_not_retried_automatically(): void
    {
        $c = $this->company();
        $channel = $this->enable(fail: true);
        $draft = $this->createDraft($c)->json('data');
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $url = "/api/v1/gateway/channels/whatsapp/drafts/{$draft['id']}/confirm";

        $api->postJson($url, ['body_sha256' => $draft['body_sha256']])->assertStatus(502)->assertJsonPath('code', 'channel_send_failed');
        $this->assertSame([], $channel->sent);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $c['tenant']->id, 'action' => 'gateway.channel_send_failed']);
        $channel->failOnSend = false;
        $api->postJson($url, ['body_sha256' => $draft['body_sha256']])->assertStatus(409);   // ya reclamado: hay que crear un borrador nuevo
        $this->assertSame([], $channel->sent);
    }

    // ── el asistente no tiene vía hacia el canal ──
    public function test_the_assistant_cannot_create_confirm_or_send_messages(): void
    {
        $c = $this->company();
        $channel = $this->enable();
        $this->fakeProvider([
            new ProviderResponse('', [
                ['name' => 'send_whatsapp', 'arguments' => ['to' => 'CLI-0001', 'text' => 'Hola']],
                ['name' => 'create_draft', 'arguments' => ['template' => 'opportunity_follow_up']],
                ['name' => 'confirm_draft', 'arguments' => ['body_sha256' => str_repeat('a', 64)]],
            ]),
            Fake::text('Enviado.'),
        ]);

        $res = $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson('/api/v1/assistant/messages', ['message' => 'Envía un WhatsApp al cliente CLI-0001'])->assertOk();

        $this->assertSame(['tool_not_allowed', 'tool_not_allowed', 'tool_not_allowed'], array_column($res->json('data.figures'), 'status'));
        $this->assertSame([], $channel->sent);
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $c['tenant']->id)->where('action', 'like', 'gateway.channel%')->count());
        $this->assertSame([], array_values(array_filter((new ToolRegistry)->names(), fn (string $n) => preg_match('/send|draft|confirm|whatsapp|message/', $n) === 1)));
    }

    public function test_the_webhook_contract_helpers_are_part_of_the_channel_interface(): void
    {
        $channel = new FakeMessagingChannel;
        $body = '{"entry":[]}';
        $signature = WebhookSignature::sign('secreto-de-prueba', $body);

        $this->assertTrue($channel->verifyWebhook($body, ['x-hub-signature-256' => $signature], []));
        $this->assertFalse($channel->verifyWebhook($body.'x', ['x-hub-signature-256' => $signature], []));
        $this->assertFalse($channel->verifyWebhook($body, [], []));
        $this->assertNull($channel->resolveIdentity('hash-del-telefono'), 'sin vinculación previa no hay identidad');
    }
}
