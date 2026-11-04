<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Exceptions\DomainStateException;
use App\Models\ModelPromotionDecision;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Models\Exceptions\MissingAbilityException;
use App\Services\Models\Exceptions\SegregationOfDutiesException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Decisiones sobre un modelo del registro (ADR-V25-07; V25-REG-*): validar, aprobar, rechazar y promover a producción. Cada una:
 *  - corre en una transacción con el modelo bloqueado y cambia el estado SOLO mediante ModelLifecycle (transición explícita + evento
 *    de auditoría en la misma transacción);
 *  - deja una entrada en la bitácora de auditoría general (`models.validated|approved|rejected|promoted`).
 *
 * Aprobar y rechazar exigen la habilidad `models.approve` (middleware de la ruta) y SEGREGACIÓN DE FUNCIONES: el usuario no puede ser
 * quien publicó el modelo ni quien disparó su entrenamiento (ModelResponsibles). Aprobar además exige firma verificada, gate de datos ≠
 * BLOCK y compatibilidad (ModelApprovalGates), y completa la decisión de promoción de W3 asociada (`approved_by`, `approved_at`).
 */
final class ModelApprovalService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModelLifecycle $lifecycle,
        private readonly ModelServingPolicy $policy,
        private readonly ModelApprovalGates $gates,
        private readonly ModelResponsibles $responsibles,
    ) {}

    /**
     * candidate → validated: pasa las compuertas automáticas (firma, datos, política, compatibilidad). No es una decisión de negocio, pero
     * validar NO vuelve servible a un modelo: solo la aprobación separada lo hace (ModelServingPolicy). Un candidato PUBLICADO por la CLI lo
     * puede validar quien tiene `forecast.run`; un modelo que NO entró por la publicación (retador del pipeline propio) exige `models.approve`.
     *
     * @throws MissingAbilityException 403 si el modelo no es publicado y el usuario no tiene `models.approve`
     */
    public function validate(Tenant $tenant, User $user, int $modelId, ?string $reason = null, bool $canApprove = false): ModelRegistry
    {
        $model = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($modelId, $user, $reason, $canApprove): ModelRegistry {
            $model = $this->lock($modelId);
            if (! $canApprove && ! $this->policy->isPublished($model)) {
                throw new MissingAbilityException('models.approve', 'Validar un modelo del pipeline propio (no publicado por la CLI) exige la habilidad models.approve.');
            }
            if ($model->status !== ModelState::CANDIDATE) {
                throw new DomainStateException('Solo un candidato puede validarse (estado actual «'.$model->status.'»).', 'model_transition_not_allowed', ['model_id' => $model->id, 'from' => $model->status, 'to' => ModelState::VALIDATED]);
            }
            $evidence = $this->gates->evaluate($model);

            return $this->lifecycle->transition($model, ModelState::VALIDATED, $user, ModelRegistryEvent::ACTOR_USER, $reason ?? 'Compuertas automáticas superadas.', ['gates' => $evidence, 'serving_basis_note' => 'La validación no vuelve servible al modelo: falta la aprobación separada.'], ['validated_at' => CarbonImmutable::now()]);
        }));
        $this->audit('models.validated', $tenant, $model, $user);

        return $model;
    }

    /** validated → approved, con segregación de funciones y todas las compuertas. */
    public function approve(Tenant $tenant, User $user, int $modelId, ?string $reason = null): ModelRegistry
    {
        $model = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($modelId, $user, $reason): ModelRegistry {
            $model = $this->lock($modelId);
            if ($model->status !== ModelState::VALIDATED) {
                throw new DomainStateException(
                    'Solo un modelo validado puede aprobarse (estado actual «'.$model->status.'»).',
                    'model_transition_not_allowed',
                    ['model_id' => $model->id, 'from' => $model->status, 'to' => ModelState::APPROVED],
                );
            }
            $this->assertSegregation($model, $user, 'aprobar');
            $evidence = $this->gates->evaluate($model);
            $now = CarbonImmutable::now();
            $this->lifecycle->transition(
                $model, ModelState::APPROVED, $user, ModelRegistryEvent::ACTOR_USER, $reason,
                ['gates' => $evidence, 'segregation' => ['checked' => $this->responsibles->roles($model), 'approver_is_responsible' => false]],
                ['approved_by' => $user->getKey(), 'approved_at' => $now, 'rejected_reason' => null],
            );
            // La decisión de promoción de la política (W3) asociada queda con la aprobación humana separada.
            if ($model->promotion_decision_id !== null) {
                ModelPromotionDecision::query()->whereKey($model->promotion_decision_id)->whereNull('approved_at')
                    ->update(['approved_by' => $user->getKey(), 'approved_at' => $now]);
            }

            return $model;
        }));
        $this->audit('models.approved', $tenant, $model, $user, ['reason' => $reason]);

        return $model;
    }

    /** candidate | validated | approved → rejected, con motivo obligatorio y la misma segregación que aprobar. */
    public function reject(Tenant $tenant, User $user, int $modelId, string $reason): ModelRegistry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainStateException('El motivo del rechazo es obligatorio.', 'rejection_reason_required');
        }
        $model = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($modelId, $user, $reason): ModelRegistry {
            $model = $this->lock($modelId);
            if (! in_array($model->status, [ModelState::CANDIDATE, ModelState::VALIDATED, ModelState::APPROVED], true)) {
                throw new DomainStateException(
                    'Solo se rechaza un candidato, validado o aprobado que aún no está en producción (estado actual «'.$model->status.'»).',
                    'model_transition_not_allowed',
                    ['model_id' => $model->id, 'from' => $model->status, 'to' => ModelState::REJECTED],
                );
            }
            $this->assertSegregation($model, $user, 'rechazar');
            $withdrewApproval = $model->status === ModelState::APPROVED;
            $attributes = ['rejected_reason' => $reason];
            $evidence = ['withdrew_approval' => $withdrewApproval];
            if ($withdrewApproval) {
                // Retirar la aprobación borra la huella de la aprobación vigente (la base de servicio no debe leerla); el evento conserva quién y cuándo.
                $evidence['previous_approval'] = ['approved_by' => $model->approved_by, 'approved_at' => $model->approved_at?->toIso8601String()];
                $attributes += ['approved_by' => null, 'approved_at' => null];
                if ($model->promotion_decision_id !== null && $model->approved_by !== null) {
                    ModelPromotionDecision::query()->whereKey($model->promotion_decision_id)->where('approved_by', $model->approved_by)
                        ->update(['approved_by' => null, 'approved_at' => null]);
                }
            }
            $this->lifecycle->transition($model, ModelState::REJECTED, $user, ModelRegistryEvent::ACTOR_USER, $reason, $evidence, $attributes);

            return $model;
        }));
        $this->audit('models.rejected', $tenant, $model, $user, ['reason' => $reason]);

        return $model;
    }

    /**
     * approved → production: el modelo aprobado empieza a servirse y retira al que servía en su alcance (mismo slot / familia y rol).
     * Vuelve a verificar firma, gate de datos y compatibilidad (el archivo pudo cambiar desde la aprobación) y exige aprobador ≠ responsable.
     *
     * @return array{model: ModelRegistry, retired: list<int>}
     */
    public function promote(Tenant $tenant, User $user, int $modelId, ?string $reason = null): array
    {
        $result = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $modelId, $user, $reason): array {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();   // serializa con corridas y reversiones de la empresa
            $model = $this->lock($modelId);
            if ($model->status !== ModelState::APPROVED) {
                throw new DomainStateException(
                    'Solo un modelo aprobado puede pasar a producción (estado actual «'.$model->status.'»).',
                    'model_transition_not_allowed',
                    ['model_id' => $model->id, 'from' => $model->status, 'to' => ModelState::PRODUCTION],
                );
            }
            if ($model->approved_at === null) {
                throw new DomainStateException('El modelo figura como aprobado pero no registra la aprobación (approved_at).', 'model_not_approved', ['model_id' => $model->id]);
            }
            if ($model->approved_by !== null && $this->responsibles->isResponsible($model, (int) $model->approved_by)) {
                throw new SegregationOfDutiesException('La aprobación de este modelo la dio una persona responsable de él; debe aprobarlo otra.', $this->responsibles->rolesOf($model, (int) $model->approved_by));
            }
            $scope = $this->policy->scopeKey($model);
            if ($scope === null) {
                throw new DomainStateException('El modelo no declara un alcance de producción válido (slot o familia).', 'model_without_scope', ['model_id' => $model->id]);
            }
            $evidence = $this->gates->evaluate($model);
            if ($model->core === 'customer' && preg_match('/^h(\d+):/', (string) ($model->hyperparameters['slot'] ?? ''), $m) === 1) {
                CoreServices::assertHorizon($tenant, (int) $m[1]);                        // 402 si el plan no incluye ese horizonte
            }

            $now = CarbonImmutable::now();
            $peers = ModelRegistry::query()->where('core', $model->core)->where('status', ModelState::PRODUCTION)->where('id', '<>', $model->id)
                ->lockForUpdate()->get()->filter(fn (ModelRegistry $m) => $this->policy->scopeKey($m) === $scope)->values();
            foreach ($peers as $peer) {
                $this->lifecycle->transition($peer, ModelState::RETIRED, $user, ModelRegistryEvent::ACTOR_USER, 'Reemplazado por el modelo '.$model->version.'.',
                    ['replaced_by' => $model->id, 'scope' => $scope], ['retired_at' => $now]);
            }
            $this->lifecycle->transition(
                $model, ModelState::PRODUCTION, $user, ModelRegistryEvent::ACTOR_USER, $reason,
                ['gates' => $evidence, 'scope' => $scope, 'replaces' => $peers->pluck('id')->all()],
                ['promoted_at' => $now, 'retired_at' => null, 'previous_model_registry_id' => $peers->first()?->id ?? $model->previous_model_registry_id],
            );

            return ['model' => $model, 'retired' => $peers->pluck('id')->map(fn ($id) => (int) $id)->all(), 'scope' => $scope];
        }));
        $this->audit('models.promoted', $tenant, $result['model'], $user, ['scope' => $result['scope'], 'retired_model_ids' => $result['retired'], 'reason' => $reason]);

        return ['model' => $result['model'], 'retired' => $result['retired']];
    }

    /** El modelo debe ser de la empresa (de otra responde 404 por el filtro de empresa) y se bloquea durante la decisión. */
    private function lock(int $modelId): ModelRegistry
    {
        return ModelRegistry::query()->lockForUpdate()->find($modelId) ?? throw new NotFoundHttpException('Modelo no encontrado.');
    }

    /** @throws SegregationOfDutiesException 403 si el usuario publicó o disparó el entrenamiento del modelo */
    private function assertSegregation(ModelRegistry $model, User $user, string $verb): void
    {
        $roles = $this->responsibles->rolesOf($model, (int) $user->getKey());
        if ($roles !== []) {
            throw new SegregationOfDutiesException(
                "No puede {$verb} este modelo: usted lo publicó o disparó su entrenamiento. Lo debe decidir otra persona con la habilidad models.approve.",
                $roles,
            );
        }
    }

    /** @param array<string, mixed> $extra */
    private function audit(string $action, Tenant $tenant, ModelRegistry $model, User $user, array $extra = []): void
    {
        $this->context->runAs($tenant, function () use ($action, $model, $user, $extra): void {
            CoreServices::audit($action, $model, array_filter([
                'model_registry_id' => $model->id, 'version' => $model->version, 'core' => $model->core, 'model_family' => $model->model_family,
                'status' => $model->status, 'user_id' => $user->getKey(),
            ] + $extra, fn ($v) => $v !== null));
        });
    }
}
