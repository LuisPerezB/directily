<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resuelve la ejecución de pronóstico a consultar dentro de la empresa activa:
 * la indicada por `run_id` (404 si no existe o es de otra empresa) o la vigente.
 */
class RunResolver
{
    /** Ejecución vigente: la marcada is_current o, en su defecto, la última de producción completada. */
    public function current(): ?ForecastRun
    {
        return ForecastRun::query()->where('is_current', true)->first()
            ?? ForecastRun::query()
                ->completed()
                ->where('run_type', ForecastRun::TYPE_PRODUCTION)
                ->latest('completed_at')
                ->latest('id')
                ->first();
    }

    public function resolve(?int $runId): ?ForecastRun
    {
        if ($runId === null) {
            return $this->current();
        }

        return ForecastRun::query()->find($runId) ?? throw new NotFoundHttpException('Ejecución de pronóstico no encontrada.');
    }

    /** Igual que resolve() pero exige que la ejecución esté completada. */
    public function resolveCompleted(?int $runId): ?ForecastRun
    {
        $run = $this->resolve($runId);
        if ($run !== null && $run->status !== ForecastRun::STATUS_COMPLETED) {
            return $runId === null ? null : throw new NotFoundHttpException('La ejecución aún no tiene resultados (estado: '.$run->status.').');
        }

        return $run;
    }
}
