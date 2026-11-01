<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forecasting\ModelArtifactVerifier;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reversión del Customer Core (ADR-V2-07, E2-24): re-sirve una versión `validated` o `retired` de un modelo de comportamiento
 * de compra. La versión elegida pasa a `production` y la actual del MISMO horizonte y objetivo (`hyperparameters.slot`, p. ej.
 * `h30:probability`) pasa a `retired`. No reentrena, no borra resultados: la siguiente `predict` carga la versión revertida.
 *
 * Reglas: el modelo debe ser de la empresa (de otra empresa o de otro core responde 404); estado validated|retired; slot válido;
 * horizonte incluido en el plan (402); artefacto presente, de un esquema de variables compatible y con SHA-256 íntegro —si el
 * archivo es visible desde el backend (`engine.artifacts_dir`, volumen compartido) y no coincide, se rechaza (409
 * `rollback_artifact_mismatch`); si no es visible, la respuesta lo informa explícitamente (`artifact_verification`) y el motor lo
 * verifica al predecir y sirve la referencia validada si falla—. Queda en auditoría.
 */
final class CustomerRollbackService
{
    /** Debe coincidir con `predictily_engine.customer.FEATURE_SCHEMA_VERSION` (el motor lo exige al cargar el artefacto). */
    public const FEATURE_SCHEMA_VERSION = 'cust-fs-2026.10-v1';

    public function __construct(private readonly TenantContext $context, private readonly ModelArtifactVerifier $artifacts) {}

    /** @return array<string, mixed> */
    public function rollback(Tenant $tenant, ?User $user, int $modelRegistryId): array
    {
        $result = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $modelRegistryId): array {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();   // serializa con corridas y otras reversiones

            $target = ModelRegistry::query()->where('core', 'customer')->lockForUpdate()->find($modelRegistryId)
                ?? throw new NotFoundHttpException('Modelo de comportamiento de compra no encontrado.');
            if ($target->status === 'production') {
                throw new DomainStateException('Ese modelo ya está en producción.', 'rollback_already_current', ['model_id' => $target->id]);
            }
            if (! in_array($target->status, ['validated', 'retired'], true)) {
                throw new DomainStateException('Solo se puede restaurar una versión validada o retirada.', 'rollback_target_not_restorable', ['status' => $target->status]);
            }
            $slot = (string) ($target->hyperparameters['slot'] ?? '');
            if (! preg_match('/^h(7|30|90):(probability|quantity)$/', $slot, $m)) {
                throw new DomainStateException('El modelo no declara un horizonte y objetivo (slot) válidos.', 'rollback_target_without_slot', ['slot' => $slot ?: null]);
            }
            CoreServices::assertHorizon($tenant, (int) $m[1]);                       // 402 si el plan no incluye ese horizonte
            if ($target->artifact_path === null || $target->artifact_sha256 === null) {
                throw new DomainStateException('El modelo no tiene un artefacto registrado: no se puede restaurar.', 'rollback_target_without_artifact', ['model_id' => $target->id]);
            }
            if (($target->artifact_compat['feature_schema_version'] ?? null) !== self::FEATURE_SCHEMA_VERSION) {
                throw new DomainStateException('El esquema de variables del artefacto no es compatible con el motor actual.', 'rollback_target_incompatible', [
                    'artifact' => $target->artifact_compat['feature_schema_version'] ?? null, 'engine' => self::FEATURE_SCHEMA_VERSION,
                ]);
            }
            $verified = $this->artifacts->verify([$target]);

            $now = CarbonImmutable::now();
            $retired = ModelRegistry::query()->where('core', 'customer')->where('status', 'production')
                ->where('hyperparameters->slot', $slot)->where('id', '<>', $target->id)->get(['id', 'version', 'model_family']);
            ModelRegistry::query()->whereIn('id', $retired->pluck('id'))->update(['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            $target->forceFill(['status' => 'production', 'promoted_at' => $now, 'retired_at' => null, 'updated_at' => $now])->save();

            return ['target' => $target->fresh(), 'retired' => $retired, 'slot' => $slot, 'horizon' => (int) $m[1], 'objective' => $m[2], 'verified' => $verified];
        }));

        /** @var ModelRegistry $target */
        $target = $result['target'];
        $summary = [
            'restored' => ['id' => $target->id, 'version' => $target->version, 'model_family' => $target->model_family, 'role' => $target->role, 'status' => $target->status],
            'slot' => $result['slot'], 'horizon_days' => $result['horizon'], 'objective' => $result['objective'],
            'retired' => $result['retired']->map(fn ($r) => ['id' => $r->id, 'version' => $r->version, 'model_family' => $r->model_family])->values()->all(),
            'artifact_sha256_verified' => $result['verified']['verified'],
            'artifact_verification' => $result['verified'],
            'ranking_source' => $target->hyperparameters['ranking_source'] ?? null,
        ];
        $this->context->runAs($tenant, function () use ($target, $summary, $user): void {
            CoreServices::audit('models.customer_rolled_back', $target, $summary + ['user_id' => $user?->getKey()]);
        });

        return $summary + ['note' => 'La siguiente predicción usará esta versión; las predicciones ya generadas no cambian. Ejecute POST /customer/runs {operation: predict} para actualizarlas.'];
    }
}
