<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use App\Models\TrainingJob;

/**
 * Quién es «responsable» de un modelo para la segregación de funciones (ADR-V25-07): quien lo publicó (`published_by`), quien disparó
 * su entrenamiento y quien lo registró como candidato. Del entrenamiento se consideran TODOS los trabajos que enlacen al modelo o a su
 * corrida: el trabajo del modelo (`model_registry.training_job_id`), los que lo apuntan (`training_jobs.model_registry_id`) y los de su
 * corrida (`training_jobs.forecast_run_id` / `core_run_id`), más `forecast_runs.user_id` / `core_runs.user_id`. Un entrenamiento programado o
 * del sistema (`triggered_by` distinto de user, `user_id` nulo) no tiene responsable humano.
 */
final class ModelResponsibles
{
    /**
     * @return list<array{role: string, user_id: int}> una entrada por función y persona (una persona puede cumplir varias funciones)
     */
    public function of(ModelRegistry $model): array
    {
        $found = [];
        $add = function (string $role, mixed $userId) use (&$found): void {
            if (is_numeric($userId) && (int) $userId > 0) {
                $entry = ['role' => $role, 'user_id' => (int) $userId];
                if (! in_array($entry, $found, true)) {
                    $found[] = $entry;
                }
            }
        };
        $add('published_by', $model->published_by);
        if ($model->training_job_id !== null) {
            $add('training_job.user_id', TrainingJob::query()->whereKey($model->training_job_id)->value('user_id'));
        }
        foreach (TrainingJob::query()->where('model_registry_id', $model->id)->whereNotNull('user_id')->pluck('user_id') as $id) {
            $add('training_job.user_id', $id);
        }
        if ($model->forecast_run_id !== null) {
            $add('forecast_run.user_id', ForecastRun::query()->whereKey($model->forecast_run_id)->value('user_id'));
            foreach (TrainingJob::query()->where('forecast_run_id', $model->forecast_run_id)->whereNotNull('user_id')->pluck('user_id') as $id) {
                $add('run_training_job.user_id', $id);
            }
        }
        if ($model->core_run_id !== null) {
            $add('core_run.user_id', CoreRun::query()->whereKey($model->core_run_id)->value('user_id'));
            foreach (TrainingJob::query()->where('core_run_id', $model->core_run_id)->whereNotNull('user_id')->pluck('user_id') as $id) {
                $add('run_training_job.user_id', $id);
            }
        }
        $add('registered_by', ModelRegistryEvent::query()->where('model_registry_id', $model->id)->whereNotNull('actor_user_id')
            ->where('to_status', ModelState::CANDIDATE)->orderBy('id')->value('actor_user_id'));

        return $found;
    }

    /** ¿Es `$userId` responsable del modelo en alguna función? */
    public function isResponsible(ModelRegistry $model, int $userId): bool
    {
        return $this->rolesOf($model, $userId) !== [];
    }

    /**
     * Funciones que cumple un usuario sobre el modelo (vacío = no es responsable y puede decidir).
     *
     * @return list<string>
     */
    public function rolesOf(ModelRegistry $model, int $userId): array
    {
        return array_values(array_unique(array_column(array_filter($this->of($model), fn (array $e) => $e['user_id'] === $userId), 'role')));
    }

    /**
     * Funciones que intervinieron (sin identificadores), para la evidencia.
     *
     * @return list<string>
     */
    public function roles(ModelRegistry $model): array
    {
        return array_values(array_unique(array_column($this->of($model), 'role')));
    }
}
