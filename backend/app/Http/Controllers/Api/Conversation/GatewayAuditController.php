<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conversation;

use App\Http\Controllers\Controller;
use App\Models\GatewayToolCall;
use App\Services\Conversation\Gateway\GatewayDecision;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Auditoría del Intelligence Gateway (V2.5 · W6-D): una fila por llamada a herramienta, permitida o no, con la decisión (allowed, denied_ability, denied_policy,
 * blocked_injection, error), los argumentos MINIMIZADOS (sin texto libre ni PII), el resumen del resultado y la latencia. Solo lectura, por empresa; exige `audit.view`.
 */
final class GatewayAuditController extends Controller
{
    /** GET /gateway/tool-calls */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'decision' => ['sometimes', 'string', Rule::in(GatewayDecision::ALL)],
            'tool' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z_()]+$/'],
            'channel' => ['sometimes', 'string', Rule::in(['web', 'api', 'whatsapp'])],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $context->require();
        $query = GatewayToolCall::query()->orderByDesc('id');
        foreach (['decision', 'tool', 'channel', 'user_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (GatewayToolCall $c): array => [
                'id' => (int) $c->id,
                'user_id' => $c->user_id !== null ? (int) $c->user_id : null,
                'channel' => $c->channel,
                'tool' => $c->tool,
                'decision' => $c->decision,
                'arguments' => (object) ($c->arguments ?? []),
                'result_summary' => (object) ($c->result_summary ?? []),
                'latency_ms' => $c->latency_ms,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
