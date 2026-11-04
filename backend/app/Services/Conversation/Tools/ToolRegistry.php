<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

/**
 * Catálogo (lista blanca) de herramientas tipadas del gateway (V2.5 · W6-D; ADR-V25-17). Solo sabe QUÉ herramientas existen: qué usuario puede usar cuál, con qué
 * argumentos y con qué auditoría lo decide el IntelligenceGateway, que es el único camino de ejecución (el registro ya no ejecuta). Una herramienta no registrada o no
 * habilitada no se puede ejecutar; no existe ninguna ruta de acceso a datos de otra empresa porque ninguna herramienta recibe empresa, usuario ni habilidades del modelo.
 *
 * Todas son de SOLO LECTURA sobre resultados ya calculados y persistidos: ninguna escribe, calcula, propone ni ejecuta acciones.
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
        // V2.5 · W6-D: oportunidades (Radar), recomendaciones y su explicación, resultados medidos, ROI y calidad de modelos.
        RadarOpportunitiesTool::class,
        ExplainRecommendationTool::class,
        DecisionsSummaryTool::class,
        OutcomesTool::class,
        RoiSummaryTool::class,
        ModelQualityTool::class,
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

    /** @return list<string> todas las herramientas registradas (habilitadas o no) */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /** La herramienta registrada y habilitada con ese nombre exacto, o null. */
    public function find(string $name): ?AssistantTool
    {
        $tool = $this->tools[$name] ?? null;

        return $tool !== null && $tool->isEnabled() ? $tool : null;
    }

    /** @return list<AssistantTool> las habilitadas, en el orden del catálogo */
    public function enabled(): array
    {
        return array_values(array_filter($this->tools, fn (AssistantTool $t): bool => $t->isEnabled()));
    }
}
