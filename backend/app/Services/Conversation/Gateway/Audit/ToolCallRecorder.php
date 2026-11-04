<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Audit;

use App\Models\GatewayToolCall;
use App\Services\Conversation\Gateway\GatewayDecision;
use App\Services\Conversation\Tools\ToolContext;
use App\Support\Privacy\PiiMasker;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Escribe UNA fila de `gateway_tool_calls` por llamada a herramienta (V2.5 · W6-D). La empresa y el usuario salen del contexto de la petición, nunca del modelo; el nombre
 * de herramienta que propuso el modelo solo se guarda si tiene forma de identificador (snake_case): cualquier otra cosa se registra como «(invalid)».
 */
class ToolCallRecorder
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $arguments  ya minimizados (ArgumentMinimizer)
     * @param  array<string, mixed>  $summary  resumen del resultado sin valores de negocio
     */
    public function record(ToolContext $context, string $tool, string $decision, array $arguments, array $summary, int $latencyMs): GatewayToolCall
    {
        if (! in_array($decision, GatewayDecision::ALL, true)) {
            throw new InvalidArgumentException("Decisión de gateway desconocida: {$decision}");
        }

        return $this->tenants->runAs($context->tenant, fn (): GatewayToolCall => GatewayToolCall::query()->create([
            'user_id' => $context->user?->getKey(),
            'channel' => in_array($context->channel, ToolContext::CHANNELS, true) ? $context->channel : 'api',
            'tool' => self::safeToolName($tool),
            'decision' => $decision,
            'arguments' => PiiMasker::sanitize($arguments) ?: (object) [],
            'result_summary' => PiiMasker::sanitize($summary) ?: (object) [],
            'latency_ms' => max(0, $latencyMs),
        ]));
    }

    public static function safeToolName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,59}$/', $name) === 1 ? $name : '(invalid)';
    }
}
