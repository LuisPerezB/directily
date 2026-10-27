<?php

declare(strict_types=1);

namespace App\Services\Scenarios;

use App\Models\ForecastRun;
use App\Services\Analytics\RunResolver;

/** Orquesta la simulación: base calculada (pronóstico vigente) + supuestos del usuario. */
class ScenarioService
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly ScenarioBaselineLoader $loader,
        private readonly ScenarioSimulator $simulator,
    ) {}

    /**
     * @param  array<string, int|null>  $filters
     * @param  array<string, mixed>  $assumptions
     * @return array<string, mixed>
     */
    public function simulate(int $horizon, array $filters, array $assumptions, ?int $runId = null): array
    {
        $run = $this->runs->resolveCompleted($runId);
        $baseline = $run instanceof ForecastRun
            ? $this->loader->load($run, $horizon, $filters)
            : new ScenarioBaseline($horizon, []);

        $result = $this->simulator->simulate($baseline, $assumptions);
        $result['filters'] = $filters;
        $result['base_run'] = $run ? ['id' => $run->id, 'version' => $run->version, 'cutoff_date' => $run->cutoff_date?->toDateString()] : null;

        return $result;
    }
}
