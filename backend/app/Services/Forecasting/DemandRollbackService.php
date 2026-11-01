<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Exceptions\DomainStateException;
use App\Models\ForecastRun;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Models\ModelApprovalGates;
use App\Services\Models\ModelLifecycle;
use App\Services\Models\ModelServingPolicy;
use App\Services\Models\ModelState;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reversión de la demanda (ADR-V2-07, E2-24): re-apunta la ejecución VIGENTE a una ejecución de producción
 * completada anterior. No reentrena ni modifica pronósticos: cada ejecución conserva sus valores, su huella
 * (data_snapshot_id), su configuración efectiva y sus modelos. Los modelos de producción actuales pasan a
 * `retired` y los de la ejecución elegida vuelven a `production`. Queda registrada en auditoría.
 *
 * Reglas: la ejecución debe ser de la empresa (de otra empresa responde 404), de producción, completada, ANTERIOR a
 * la vigente y tener modelos retirados que restaurar; si el artefacto de un modelo es visible desde el backend
 * (`engine.artifacts_dir`, volumen compartido) se verifica su SHA-256 y una discrepancia impide la reversión; si no lo es, la
 * respuesta lo informa explícitamente (`artifacts_verification`, ver ModelArtifactVerifier).
 *
 * V2.5 · W4 (ADR-V25-07): solo se restauran modelos APROBADOS por una persona distinta de quien los publicó o entrenó, o LEGADOS del
 * pipeline propio (ModelServingPolicy: la política explícita que mantiene la demo y los E2E); un modelo candidato, rechazado o publicado
 * sin aprobar impide la reversión (409 `rollback_target_not_approved`). Cada cambio de estado pasa por ModelLifecycle y deja su evento
 * de auditoría en la misma transacción.
 */
final class DemandRollbackService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModelArtifactVerifier $artifacts,
        private readonly ModelLifecycle $lifecycle,
        private readonly ModelServingPolicy $policy,
        private readonly ModelApprovalGates $gates,
    ) {}

    /** @return array<string, mixed> */
    public function rollback(Tenant $tenant, ?User $user, int $targetRunId): array
    {
        $result = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $targetRunId, $user): array {
            // Serializa con nuevas ejecuciones y otras reversiones de la misma empresa.
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

            $current = ForecastRun::query()->where('is_current', true)->lockForUpdate()->first();
            if ($current === null) {
                throw new DomainStateException('No hay una ejecución vigente: no hay a qué volver.', 'no_current_run');
            }
            $target = ForecastRun::query()->find($targetRunId)
                ?? throw new NotFoundHttpException('Ejecución de pronóstico no encontrada.');
            if ($target->id === $current->id) {
                throw new DomainStateException('Esa ejecución ya es la vigente.', 'rollback_already_current');
            }
            if ($target->run_type !== ForecastRun::TYPE_PRODUCTION || $target->status !== ForecastRun::STATUS_COMPLETED) {
                throw new DomainStateException('Solo se puede volver a una ejecución de producción completada.', 'rollback_target_not_production', [
                    'run_type' => $target->run_type, 'status' => $target->status,
                ]);
            }
            if ($target->id > $current->id) {
                throw new DomainStateException('Solo se puede volver a una ejecución anterior a la vigente; para una más nueva genere un pronóstico.', 'rollback_target_not_previous');
            }
            $restorable = ModelRegistry::query()
                ->where('core', 'demand')->where('forecast_run_id', $target->id)
                ->where('status', 'retired')->get();
            if ($restorable->isEmpty()) {
                throw new DomainStateException('La ejecución elegida no tiene modelos retirados que restaurar.', 'rollback_target_without_models');
            }
            if (! DB::table('forecast_values')->where('tenant_id', $target->tenant_id)->where('forecast_run_id', $target->id)->exists()) {
                throw new DomainStateException('La ejecución elegida ya no conserva sus pronósticos (retención).', 'rollback_target_without_forecasts');
            }
            $notApproved = $restorable->filter(fn (ModelRegistry $m) => ! $this->policy->isServable($m));
            if ($notApproved->isNotEmpty()) {
                throw new DomainStateException('La ejecución elegida tiene modelos sin aprobar: solo se restauran modelos aprobados o legados del pipeline propio.', 'rollback_target_not_approved', [
                    'model_ids' => $notApproved->pluck('id')->all(),
                ]);
            }
            foreach ($restorable as $model) {
                if ($this->policy->isPublished($model)) {
                    $this->gates->signature($model);   // un modelo publicado se sirve desde el objeto del almacén: se vuelve a verificar su firma
                }
            }
            $verified = $this->artifacts->verify($restorable);

            $now = CarbonImmutable::now();
            $actorKind = $user !== null ? ModelRegistryEvent::ACTOR_USER : ModelRegistryEvent::ACTOR_SYSTEM;
            $evidence = ['trigger' => 'demand_rollback', 'from_run_id' => $current->id, 'to_run_id' => $target->id];
            $retired = ModelRegistry::query()->where('core', 'demand')->where('status', ModelState::PRODUCTION)->lockForUpdate()->get();
            foreach ($retired as $model) {
                $this->lifecycle->transition($model, ModelState::RETIRED, $user, $actorKind, 'Reversión de la demanda a la ejecución v'.$target->version.'.', $evidence, ['retired_at' => $now]);
            }
            foreach ($restorable as $model) {
                $this->lifecycle->transition($model, ModelState::PRODUCTION, $user, $actorKind, 'Reversión de la demanda a la ejecución v'.$target->version.'.',
                    $evidence + ['artifacts' => $verified['status']], ['promoted_at' => $now, 'retired_at' => null]);
            }
            // El índice parcial admite una sola vigente por empresa: primero se libera, luego se asigna.
            ForecastRun::query()->whereKey($current->id)->update(['is_current' => false]);
            ForecastRun::query()->whereKey($target->id)->update(['is_current' => true]);

            return [
                'from' => $current, 'to' => $target, 'restored' => $restorable, 'retired' => $retired, 'verified' => $verified,
            ];
        }));

        /** @var ForecastRun $from */
        $from = $result['from'];
        /** @var ForecastRun $to */
        $to = $result['to'];
        $summary = [
            'from_run_id' => $from->id, 'from_version' => $from->version, 'to_run_id' => $to->id, 'to_version' => $to->version,
            'to_cutoff_date' => $to->cutoff_date?->toDateString(), 'to_data_snapshot_id' => $to->data_snapshot_id,
            'to_config_version' => $to->config_version,
            'restored_model_ids' => $result['restored']->pluck('id')->all(),
            'retired_model_ids' => $result['retired']->pluck('id')->all(),
            'artifacts_verified' => $result['verified']['verified'],
            'artifacts_verification' => $result['verified'],
        ];
        $this->context->runAs($tenant, function () use ($to, $summary, $user): void {
            CoreServices::audit('models.demand_rolled_back', $to, $summary + ['user_id' => $user?->getKey()]);
        });

        return $summary + [
            'restored_models' => $result['restored']->map(fn (ModelRegistry $m) => [
                'id' => $m->id, 'model_family' => $m->model_family, 'role' => $m->role,
            ])->values()->all(),
            'note' => 'La ejecución elegida conserva los pronósticos de su corte '.($to->cutoff_date?->toDateString() ?? '').
                '; genere un nuevo pronóstico para actualizarlos con las ventas más recientes.',
        ];
    }
}
