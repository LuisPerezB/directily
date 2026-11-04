<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway\Support;

use App\Services\Conversation\Gateway\DataScope;
use App\Services\Conversation\Tools\AssistantTool;
use App\Services\Conversation\Tools\ToolContext;
use App\Services\Conversation\Tools\ToolResult;
use Closure;

/** Herramienta de prueba (solo lectura) cuyo cuerpo es un cierre: sirve para probar plazos, latencia y fallos del gateway sin depender de un servicio real. */
final class FakeTool implements AssistantTool
{
    /** @param Closure(array<string, mixed>, ToolContext): ToolResult $run */
    public function __construct(private readonly string $toolName, private readonly Closure $run, private readonly DataScope $scope = DataScope::Operational, private readonly string $requiredAbility = 'forecast.view') {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Herramienta de prueba.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function ability(): string
    {
        return $this->requiredAbility;
    }

    public function dataScope(): DataScope
    {
        return $this->scope;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return ($this->run)($arguments, $context);
    }

    public function summarize(ToolResult $result): array
    {
        return ['status' => $result->status];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
