<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conversation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\AssistantMessageRequest;
use App\Services\Conversation\AssistantLimiter;
use App\Services\Conversation\AssistantReply;
use App\Services\Conversation\AssistantService;
use App\Services\Conversation\Tools\ToolContext;
use App\Services\Conversation\Tools\ToolRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistente conversacional OPCIONAL (ADR-V2-12). Las rutas exigen `data.view`; cada herramienta exige además la habilidad de
 * la ruta de resultados equivalente. Su disponibilidad no afecta a ninguna otra ruta de la API.
 */
class AssistantController extends Controller
{
    /** GET /assistant/status — disponibilidad, herramientas que el usuario puede usar y límites vigentes. */
    public function status(Request $request, AssistantService $service, ToolRegistry $tools, AssistantLimiter $limiter): JsonResponse
    {
        $tenant = app(TenantContext::class)->require();
        $context = ToolContext::fromRequest($request, $tenant);
        $availability = $service->availability($tenant);
        $available = array_map(fn ($t) => $t->name(), $tools->availableFor($context));

        return response()->json(['data' => $availability + [
            'tools' => array_values(array_map(fn (string $name) => [
                'name' => $name,
                'available_to_you' => in_array($name, $available, true),
            ], array_values(array_filter($tools->names(), fn (string $n) => $tools->has($n))))),
            'limits' => [
                'max_message_chars' => (int) config('conversation.limits.max_message_chars'),
                'max_history_messages' => (int) config('conversation.limits.max_history_messages'),
                'rate_per_minute' => (int) config('conversation.limits.rate_per_minute'),
                'daily_limit_per_tenant' => (int) config('conversation.limits.daily_limit_per_tenant') ?: null,
                'max_tool_rounds' => (int) config('conversation.limits.max_tool_rounds'),
            ],
            'usage_today' => $limiter->usageToday($context),
            'notice' => $availability['enabled']
                ? AssistantReply::NOTICE
                : 'El asistente no está disponible. Los resultados de Predictily (datos, demanda, comportamiento de compra y abastecimiento) siguen disponibles en el resto de la aplicación.',
        ]]);
    }

    /** POST /assistant/messages — 503 assistant_disabled sin proveedor; 429 por tasa o tope diario; 502 si el proveedor falla. */
    public function message(AssistantMessageRequest $request, AssistantService $service): JsonResponse
    {
        $tenant = app(TenantContext::class)->require();
        $reply = $service->reply(
            ToolContext::fromRequest($request, $tenant),
            (string) $request->validated('message'),
            (array) ($request->validated('history') ?? []),
        );

        return response()->json(['data' => $reply->toArray()]);
    }
}
