<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Máquina de estados del registro de modelos (ADR-V25-07). PUNTO ÚNICO de cambio de `model_registry.status` del backend:
 * valida que la transición exista (ModelState::TRANSITIONS), que un modelo que entra en producción esté aprobado o sea legado
 * (ModelServingPolicy) y escribe la fila de `model_registry_events` EN LA MISMA TRANSACCIÓN que el cambio de estado.
 *
 * Si el modelo todavía no tiene eventos (legado, escrito por el motor sin pasar por aquí) se deja primero un evento de línea base
 * con su estado actual, para que el historial sea completo y quede marcada su base de servicio.
 */
final class ModelLifecycle
{
    public function __construct(private readonly ModelServingPolicy $policy) {}

    /**
     * @param  array<string, mixed>  $evidence  métricas, verificación de firma, decisión de política, entorno...
     * @param  array<string, mixed>  $attributes  otras columnas que cambian con la transición (approved_by, promoted_at, ...)
     *
     * @throws DomainStateException 409 `model_transition_not_allowed` o `model_not_approved`
     */
    public function transition(ModelRegistry $model, string $to, ?User $actor, string $actorKind, ?string $reason = null, array $evidence = [], array $attributes = []): ModelRegistry
    {
        if (! in_array($actorKind, ModelRegistryEvent::ACTOR_KINDS, true)) {
            throw new LogicException('Tipo de actor desconocido: '.$actorKind);
        }

        return DB::transaction(function () use ($model, $to, $actor, $actorKind, $reason, $evidence, $attributes): ModelRegistry {
            $from = (string) $model->status;
            if (! ModelState::allows($from, $to)) {
                throw new DomainStateException(
                    "El modelo {$model->version} no puede pasar de «{$from}» a «{$to}».",
                    'model_transition_not_allowed',
                    ['model_id' => $model->id, 'from' => $from, 'to' => $to, 'allowed' => ModelState::TRANSITIONS[$from] ?? []],
                );
            }
            if ($to === ModelState::APPROVED && ($attributes['approved_at'] ?? null) === null) {
                throw new LogicException('Un modelo aprobado siempre registra approved_at (use ModelApprovalService::approve).');
            }
            if ($to === ModelState::PRODUCTION) {
                $this->policy->assertServable($model);
            }

            $this->ensureBaseline($model);
            $model->forceFill(array_merge($attributes, ['status' => $to]))->save();
            ModelRegistryEvent::query()->forceCreate([
                'tenant_id' => $model->tenant_id, 'model_registry_id' => $model->id, 'from_status' => $from, 'to_status' => $to,
                'actor_user_id' => $actor?->getKey(), 'actor_kind' => $actorKind, 'reason' => $reason,
                'evidence' => $evidence + ['serving_basis' => $this->basisAfter($model, $from, $to, $actorKind)],
            ]);

            return $model;
        });
    }

    /**
     * Base de servicio tras la transición, para la evidencia. La validación humana de un candidato (evento que aún no se escribió) deja al modelo
     * en `not_approved`: validar nunca lo vuelve servible.
     */
    private function basisAfter(ModelRegistry $model, string $from, string $to, string $actorKind): string
    {
        if ($from === ModelState::CANDIDATE && $to === ModelState::VALIDATED && $actorKind === ModelRegistryEvent::ACTOR_USER) {
            return ModelServingPolicy::NOT_APPROVED;
        }

        return $this->policy->basis($model);
    }

    /** Evento de línea base («estado inicial conocido») para un modelo que el motor o la CLI registraron sin eventos. Idempotente. */
    public function ensureBaseline(ModelRegistry $model): void
    {
        if (ModelRegistryEvent::query()->where('model_registry_id', $model->id)->exists()) {
            return;
        }
        $basis = $this->policy->basis($model);
        // Solo lo que el motor dejó en production/validated/retired con origen del pipeline (basis = legacy) lleva la marca de legado: un
        // candidato (retador sin decidir) o un publicado nunca la reciben.
        $legacy = $basis === ModelServingPolicy::LEGACY;
        ModelRegistryEvent::query()->forceCreate([
            'tenant_id' => $model->tenant_id, 'model_registry_id' => $model->id, 'from_status' => null, 'to_status' => $model->status,
            'actor_user_id' => null, 'actor_kind' => ModelRegistryEvent::ACTOR_SYSTEM,
            'reason' => $legacy
                ? 'Línea base: modelo del pipeline propio anterior a la aprobación separada (legado aprobado por la política de promoción).'
                : 'Línea base: estado inicial del modelo al entrar en el registro auditado.',
            'evidence' => array_filter([
                'serving_basis' => $basis,
                'legacy_policy' => $legacy ? ModelServingPolicy::LEGACY_MARK : null,
                'baseline_origin' => 'registry_service',
                'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            ], fn ($v) => $v !== null),
        ]);
    }
}
