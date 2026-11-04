<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conversation;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Conversation\Channels\ChannelRegistry;
use App\Services\Conversation\Channels\DraftMessage;
use App\Services\Conversation\Channels\DraftStore;
use App\Services\Conversation\Channels\Exceptions\DraftConfirmationException;
use App\Services\Conversation\Channels\HumanConfirmation;
use App\Services\Conversation\Channels\TemplateCatalog;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Conversation\Gateway\IntelligenceGateway;
use App\Services\Conversation\Gateway\Scope\GatewayScope;
use App\Services\Conversation\Tools\ToolContext;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Canal de mensajería (WhatsApp) — SOLO CONTRATO (V2.5 · W6-D; ADR-V25-17). Predictily no incluye ningún proveedor ni envía mensajes. Sin un proveedor registrado en el
 * ChannelRegistry todas estas rutas responden 404 `channel_not_available`: el canal no existe. Con uno, el flujo es siempre el mismo y nada se envía sin una persona:
 *
 *   1. POST …/drafts            una persona crea un BORRADOR desde una plantilla para un cliente de su alcance (`decisions.manage`); no se envía nada;
 *   2. POST …/drafts/{id}/confirm   la misma u otra persona con la habilidad confirma el texto EXACTO (por su SHA-256); recién entonces se entrega al canal.
 * El asistente no tiene ninguna herramienta que cree ni confirme borradores (todas son de solo lectura).
 */
final class ChannelController extends Controller
{
    /** GET /gateway/channels/{channel} — el canal y sus plantillas (borradores). 404 si el canal no existe. */
    public function show(string $channel, ChannelRegistry $channels, TemplateCatalog $templates): JsonResponse
    {
        $channels->channel($channel);

        return response()->json(['data' => [
            'channel' => $channel,
            'confirmation_required' => true,
            'templates' => array_map(fn ($t) => $t->toArray(), $templates->all()),
            'notice' => 'Las plantillas son borradores. Predictily no envía nada sin la confirmación explícita de una persona.',
        ]]);
    }

    /** POST /gateway/channels/{channel}/drafts — crea un borrador (201); no envía. */
    public function createDraft(Request $request, string $channel, ChannelRegistry $channels, TemplateCatalog $templates, DraftStore $store, IntelligenceGateway $gateway, AuditLogger $audit): JsonResponse
    {
        $channels->channel($channel);
        $tenant = app(TenantContext::class)->require();
        $data = $request->validate([
            'template' => ['required', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'variables' => ['required', 'array'],
            'variables.*' => ['string', 'max:200'],
        ]);
        $template = $templates->find($data['template']) ?? throw new NotFoundHttpException('Plantilla no encontrada.');

        // El cliente debe existir en la empresa Y estar en el alcance comercial del usuario; si no, la misma respuesta (no se revela qué clientes existen).
        $customerId = (int) $data['customer_id'];
        if (! $this->customerInScope($gateway->scopeFor(ToolContext::fromRequest($request, $tenant)), $customerId)) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }
        try {
            $body = $template->render($data['variables']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_template_variables'], 422);
        }

        $now = CarbonImmutable::now();
        $draft = new DraftMessage(
            (string) Str::uuid(), (int) $tenant->getKey(), $channel, $template->key, $template->version, $customerId, $body, hash('sha256', $body),
            (int) $request->user()->getKey(), $now, $now->addMinutes(max(1, (int) config('conversation.channels.'.$channel.'.draft_ttl_minutes', 1440))),
        );
        $store->put($draft);
        $audit->log('gateway.channel_draft_created', null, ['channel' => $channel, 'template' => $template->key, 'draft_id' => $draft->id, 'customer_id' => $customerId]);

        return response()->json(['data' => $draft->toArray()], 201);
    }

    /** POST /gateway/channels/{channel}/drafts/{draft}/confirm — confirmación humana explícita; recién entonces se entrega al canal. */
    public function confirm(Request $request, string $channel, string $draft, ChannelRegistry $channels, DraftStore $store, HumanConfirmation $confirmation, AuditLogger $audit, IntelligenceGateway $gateway): JsonResponse
    {
        $messaging = $channels->channel($channel);
        $tenant = app(TenantContext::class)->require();
        $input = $request->validate(['body_sha256' => ['required', 'string', 'size:64', 'regex:/^[A-Fa-f0-9]{64}$/']]);

        $found = $store->find((int) $tenant->getKey(), $draft);
        if ($found === null || $found->channel !== $channel) {
            throw new DraftConfirmationException($store->wasClaimed((int) $tenant->getKey(), $draft) ? 'already_confirmed' : 'not_found');
        }
        // Quien confirma también debe tener al cliente en su alcance comercial (igual que quien crea el borrador): si no, el mismo 404 que un borrador inexistente y el borrador
        // sigue pendiente para quien sí puede. Un cliente borrado desde entonces tampoco se confirma.
        if (! $this->customerInScope($gateway->scopeFor(ToolContext::fromRequest($request, $tenant)), $found->customerId)) {
            throw new DraftConfirmationException('not_found');
        }
        $confirmed = $confirmation->confirm($found, $request->user(), $request->attributes->get('tenant_role'), $input['body_sha256']);
        $store->discard($found->tenantId, $found->id);   // el texto ya no se conserva
        try {
            $receipt = $messaging->send($confirmed);
        } catch (Throwable $e) {
            $audit->log('gateway.channel_send_failed', null, ['channel' => $channel, 'draft_id' => $found->id, 'exception' => $e::class]);

            return response()->json(['message' => 'El proveedor del canal no aceptó el mensaje. No se reintenta automáticamente; cree un borrador nuevo.', 'code' => 'channel_send_failed'], 502);
        }
        $audit->log('gateway.channel_message_confirmed', null, [
            'channel' => $channel, 'template' => $found->templateKey, 'draft_id' => $found->id, 'customer_id' => $found->customerId,
            'confirmed_by' => $confirmed->confirmedBy, 'body_sha256_prefix' => substr($found->bodySha256, 0, 16), 'provider_status' => $receipt->status,   // el AuditLogger enmascara hex largos: se registra el prefijo
        ]);

        return response()->json(['data' => ['draft_id' => $found->id, 'status' => 'confirmed_and_handed_to_channel', 'receipt' => $receipt->toArray()]]);
    }

    /** El cliente existe en la empresa Y está dentro del alcance comercial de la persona (cartera y asignaciones). */
    private function customerInScope(GatewayScope $scope, int $customerId): bool
    {
        return $scope->permits(DataScope::CustomerPortfolio) && Customer::query()->whereKey($customerId)->exists() && $scope->allowsCustomer($customerId);
    }
}
