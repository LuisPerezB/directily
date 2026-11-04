<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use Illuminate\Support\Collection;

/**
 * Política de qué se puede servir (ADR-V25-07: «el serving solo carga lo aprobado») y política EXPLÍCITA para los modelos legados.
 *
 * Base de servicio de un modelo (`basis`), evaluada en este orden:
 *  1. `not_approved` si el estado es `rejected` o `failed` (aunque conserve `approved_*`: el estado manda, no la huella de una aprobación retirada).
 *  2. `approved`     aprobado por una persona distinta de quien lo publicó o entrenó (`approved_at`/`approved_by`) y en `approved`, `production` o `retired`.
 *  3. `not_approved` si ENTRÓ POR LA PUBLICACIÓN: cualquier marca `published_*` o un evento de auditoría de la CLI (`actor_kind = cli`).
 *  4. `not_approved` si no está en `production`, `validated` o `retired` (un `candidate` jamás es legado servible: es un retador sin decidir).
 *  5. `not_approved` si una PERSONA lo validó (evento `candidate → validated` con `actor_kind = user`): la validación humana de un candidato nunca
 *     lo vuelve servible; solo la aprobación separada lo hace.
 *  6. `legacy_pipeline` si tiene ORIGEN POSITIVO del pipeline propio: una corrida enlazada (`forecast_run_id`, `core_run_id` o `training_job_id`) o
 *     el evento de línea base con `evidence.legacy_policy` («legado aprobado por migración»). Es el comportamiento vigente de la demo, del
 *     rollback y de los E2E; la decisión automática de la política de promoción (W3) hace de aprobación. Un legado se sirve y se revierte como
 *     hasta ahora; publicarlo a otro entorno exige aprobación y re-firmado (ADR-V25-20), lo aplica la CLI de W4-B, no este backend.
 *  7. `not_approved` en cualquier otro caso: la definición de legado es POSITIVA y falla cerrada (sin corrida enlazada ni marca no hay origen).
 *
 * CONTRATO que debe cumplir quien publique (CLI de entrenamiento privado, W4-B): al registrar el candidato fijar `status = candidate`,
 * `published_by`, `published_env`, `published_at` y `published_object_key` (clave del servidor `tenants/<uuid>/models/<core>/<versión>/…`), y
 * registrar sus eventos con `actor_kind = cli`. Cualquiera de esas marcas basta para que el modelo exija aprobación, y un publicado sin
 * `published_object_key` no puede aprobarse (no hay objeto que verificar). Ver docs/ml/MODEL_REGISTRY.md §5.6.
 */
final class ModelServingPolicy
{
    public const APPROVED = 'approved';

    public const LEGACY = 'legacy_pipeline';

    public const NOT_APPROVED = 'not_approved';

    /** Marca que la migración de respaldo y el servicio dejan en `model_registry_events.evidence.legacy_policy`. */
    public const LEGACY_MARK = 'legacy_approved_by_migration';

    /** Estados en los que un modelo del pipeline propio puede ser legado servible. */
    private const LEGACY_STATUSES = [ModelState::PRODUCTION, ModelState::VALIDATED, ModelState::RETIRED];

    public function basis(ModelRegistry $model): string
    {
        return $this->classify($model, $this->eventFlags($model));
    }

    /** ¿Entró por la publicación (marcas `published_*` o evento de la CLI)? `published_at` no se anula al borrar al usuario. */
    public function isPublished(ModelRegistry $model): bool
    {
        return $this->hasPublishedMarks($model) || $this->eventFlags($model)['cli'];
    }

    /** ¿Puede servirse o restaurarse? (aprobado o legado). */
    public function isServable(ModelRegistry $model): bool
    {
        return $this->basis($model) !== self::NOT_APPROVED;
    }

    /** @throws DomainStateException 409 `model_not_approved` */
    public function assertServable(ModelRegistry $model): void
    {
        if (! $this->isServable($model)) {
            throw new DomainStateException(
                'El modelo '.$model->version.' no está aprobado: solo se sirve un modelo aprobado por una persona distinta de quien lo publicó o entrenó (o un legado del pipeline propio).',
                'model_not_approved',
                ['model_id' => $model->id, 'status' => $model->status, 'basis' => $this->basis($model)],
            );
        }
    }

    /**
     * Calcula la base de servicio de varios modelos con UNA consulta de eventos y la deja en `$model->servingBasis` (propiedad no persistida)
     * para las listas, que de otro modo harían una consulta por fila.
     *
     * @param  iterable<ModelRegistry>  $models
     */
    public function annotate(iterable $models): void
    {
        $models = Collection::make($models)->filter(fn ($m) => $m instanceof ModelRegistry);
        $ids = $models->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }
        $rows = ModelRegistryEvent::query()->whereIn('model_registry_id', $ids)
            ->selectRaw($this->flagsSql().', model_registry_id')->groupBy('model_registry_id')->get();
        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row->model_registry_id] = $this->flagsFrom($row);
        }
        foreach ($models as $model) {
            $model->servingBasis = $this->classify($model, $found[(int) $model->id] ?? $this->noFlags());
        }
    }

    /**
     * Alcance de producción: de a un modelo en producción por alcance. Customer: el slot (`h30:probability`); los demás cores: familia,
     * clase de demanda y rol. Null si el modelo no declara un alcance utilizable.
     */
    public function scopeKey(ModelRegistry $model): ?string
    {
        if ($model->core === 'customer') {
            $slot = (string) ($model->hyperparameters['slot'] ?? '');

            return $slot !== '' ? 'customer:'.$slot : null;
        }

        return $model->model_family === '' ? null : implode(':', [(string) $model->core, (string) $model->model_family, (string) ($model->demand_class ?? '*'), (string) $model->role]);
    }

    /** ¿Tiene origen POSITIVO en el pipeline propio? (corrida o trabajo enlazados, o la línea base de legado). */
    public function hasPipelineOrigin(ModelRegistry $model): bool
    {
        return $this->hasRunLink($model) || $this->eventFlags($model)['legacy_mark'];
    }

    /** ¿Una persona lo validó (evento `candidate → validated` con actor de tipo usuario)? */
    public function isHumanValidated(ModelRegistry $model): bool
    {
        return $this->eventFlags($model)['human_validated'];
    }

    /** @param array{cli: bool, human_validated: bool, legacy_mark: bool} $f */
    private function classify(ModelRegistry $model, array $f): string
    {
        $status = (string) $model->status;
        if (in_array($status, [ModelState::REJECTED, ModelState::FAILED], true)) {
            return self::NOT_APPROVED;
        }
        if (($model->approved_at !== null || $model->approved_by !== null)
            && in_array($status, [ModelState::APPROVED, ModelState::PRODUCTION, ModelState::RETIRED], true)) {
            return self::APPROVED;
        }
        if ($this->hasPublishedMarks($model) || $f['cli'] || ! in_array($status, self::LEGACY_STATUSES, true) || $f['human_validated']) {
            return self::NOT_APPROVED;
        }

        return ($this->hasRunLink($model) || $f['legacy_mark']) ? self::LEGACY : self::NOT_APPROVED;
    }

    private function hasPublishedMarks(ModelRegistry $model): bool
    {
        return $model->published_at !== null || $model->published_by !== null
            || $model->published_object_key !== null || $model->published_env !== null;
    }

    private function hasRunLink(ModelRegistry $model): bool
    {
        return $model->forecast_run_id !== null || $model->core_run_id !== null || $model->training_job_id !== null;
    }

    /** @return array{cli: bool, human_validated: bool, legacy_mark: bool} */
    private function eventFlags(ModelRegistry $model): array
    {
        $row = ModelRegistryEvent::query()->where('model_registry_id', (int) $model->id)->selectRaw($this->flagsSql())->first();

        return $row === null ? $this->noFlags() : $this->flagsFrom($row);
    }

    private function flagsSql(): string
    {
        return "coalesce(bool_or(actor_kind = 'cli'), false) as cli, "
            ."coalesce(bool_or(from_status = 'candidate' and to_status = 'validated' and actor_kind = 'user'), false) as human_validated, "
            ."coalesce(bool_or(evidence->>'legacy_policy' is not null), false) as legacy_mark";
    }

    /** @return array{cli: bool, human_validated: bool, legacy_mark: bool} */
    private function flagsFrom(object $row): array
    {
        return ['cli' => (bool) $row->cli, 'human_validated' => (bool) $row->human_validated, 'legacy_mark' => (bool) $row->legacy_mark];
    }

    /** @return array{cli: bool, human_validated: bool, legacy_mark: bool} */
    private function noFlags(): array
    {
        return ['cli' => false, 'human_validated' => false, 'legacy_mark' => false];
    }
}
