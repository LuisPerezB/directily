<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Exceptions\PlanLimitExceededException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lista blanca de herramientas del asistente. Una herramienta no registrada, no habilitada, sin la habilidad exigida por la
 * ruta equivalente o con argumentos fuera de su esquema (incluido cualquier tenant_id) NO se ejecuta. Toda herramienta corre
 * con la empresa de la sesión en el TenantContext: no existe ninguna ruta de acceso a datos de otra empresa.
 */
final class ToolRegistry
{
    /** @var list<class-string<AssistantTool>> */
    public const DEFAULT_TOOLS = [
        DashboardSummaryTool::class,
        ProductForecastTool::class,
        ForecastAccuracyTool::class,
        InventoryRecommendationsTool::class,
        CustomerPredictionsTool::class,
        SupplyPlanTool::class,
    ];

    /** @var array<string, AssistantTool> */
    private array $tools = [];

    /** @param list<AssistantTool>|null $tools null = lista blanca por defecto */
    public function __construct(?array $tools = null)
    {
        $tools ??= array_map(fn (string $class): AssistantTool => app($class), self::DEFAULT_TOOLS);
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]) && $this->tools[$name]->isEnabled();
    }

    /**
     * Herramientas que el usuario puede usar (habilitadas y con la habilidad exigida por la ruta equivalente).
     *
     * @return list<AssistantTool>
     */
    public function availableFor(ToolContext $context): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AssistantTool $t): bool => $t->isEnabled() && $context->can($t->ability()),
        ));
    }

    /** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    public function specsFor(ToolContext $context): array
    {
        return array_map(fn (AssistantTool $t): array => [
            'name' => $t->name(), 'description' => $t->description(), 'parameters' => $t->parameters(),
        ], $this->availableFor($context));
    }

    /** @param array<string, mixed> $arguments */
    public function execute(string $name, array $arguments, ToolContext $context): ToolResult
    {
        if (! $this->has($name)) {
            return ToolResult::failure('tool_not_allowed', 'Herramienta no permitida. Use únicamente las herramientas ofrecidas.');
        }
        $tool = $this->tools[$name];
        if (! $context->can($tool->ability())) {
            return ToolResult::failure('forbidden', 'Su rol o su token no tiene permiso para consultar estos resultados (habilidad '.$tool->ability().').');
        }

        try {
            return app(TenantContext::class)->runAs($context->tenant, function () use ($tool, $arguments, $context): ToolResult {
                $rules = $tool->rules();
                $unknown = array_values(array_diff(array_keys($arguments), array_keys($rules)));
                if ($unknown !== []) {
                    return ToolResult::failure('invalid_arguments', 'Argumentos no permitidos: '.implode(', ', array_map(fn ($k) => (string) $k, $unknown)).'.');
                }
                $validator = Validator::make($arguments, $rules);
                if ($validator->fails()) {
                    return ToolResult::failure('invalid_arguments', 'Argumentos inválidos: '.implode(' ', $validator->errors()->all()));
                }

                return $tool->execute($validator->validated(), $context);
            });
        } catch (PlanLimitExceededException $e) {
            return ToolResult::failure('plan_limit', $e->getMessage());
        } catch (ValidationException $e) {
            return ToolResult::failure('invalid_arguments', implode(' ', $e->validator->errors()->all()));
        } catch (ModelNotFoundException) {
            return ToolResult::failure('not_found', 'No se encontró el recurso solicitado en su empresa.');
        } catch (Throwable $e) {
            // Solo la clase: el mensaje podría contener datos del negocio.
            Log::warning('assistant.tool_error', ['tool' => $name, 'exception' => $e::class, 'tenant_id' => $context->tenant->getKey()]);

            return ToolResult::failure('error', 'No se pudo consultar el resultado en este momento.');
        }
    }
}
