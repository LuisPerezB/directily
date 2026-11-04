<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Models;

use App\Http\Controllers\Controller;
use App\Http\Requests\Models\ModelDecisionRequest;
use App\Http\Requests\Models\RejectModelRequest;
use App\Http\Resources\Models\ModelRegistryDetailResource;
use App\Models\ModelRegistry;
use App\Models\Tenant;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaStepUp;
use App\Services\Models\Exceptions\MissingAbilityException;
use App\Services\Models\ModelApprovalService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Detalle y ciclo de vida de un modelo del registro (V2.5 · W4; ADR-V25-07). La lista (`GET /models`) vive en
 * AccuracyController y se amplió con el filtro por familia y los estados nuevos.
 *
 * - GET  /models/{id}           detalle con eventos de auditoría y decisión de promoción (`forecast.view`).
 * - POST /models/{id}/validate  candidate → validated tras las compuertas automáticas (`forecast.run` para un candidato publicado por la CLI;
 *                               `models.approve` para un modelo del pipeline propio). Validar NO vuelve servible al modelo.
 * - POST /models/{id}/approve   validated → approved: `models.approve` + segregación de funciones + firma y gate de datos.
 * - POST /models/{id}/reject    → rejected con motivo obligatorio: `models.approve` + segregación de funciones.
 * - POST /models/{id}/promote   approved → production (retira al del mismo alcance): `models.approve`.
 * Un modelo de otra empresa responde 404 (BelongsToTenant) en todas.
 */
final class ModelRegistryController extends Controller
{
    public function __construct(private readonly ModelApprovalService $service, private readonly MfaStepUp $stepUp) {}

    public function show(int $id): ModelRegistryDetailResource
    {
        $model = ModelRegistry::query()->with('events')->find($id) ?? throw new NotFoundHttpException('Modelo no encontrado.');

        return new ModelRegistryDetailResource($model);
    }

    public function validateModel(ModelDecisionRequest $request, int $id): JsonResponse
    {
        // V2.5 · W6-A: validar un modelo que NO entró por la publicación es una acción de la clase «aprobar» (`models.approve`): con MFA activo exige un segundo factor fresco.
        $roleCan = $request->attributes->get('tenant_role')?->can('models.approve') === true;
        $canApprove = $this->stepUp->approveClassAllowed($request, $roleCan);
        try {
            return $this->respond($this->service->validate($this->tenant(), $request->user(), $id, $request->reason(), $canApprove)->getKey());
        } catch (MissingAbilityException $e) {
            throw $roleCan && ! $canApprove ? MfaException::stepUpRequired() : $e;
        }
    }

    public function approve(ModelDecisionRequest $request, int $id): JsonResponse
    {
        return $this->respond($this->service->approve($this->tenant(), $request->user(), $id, $request->reason())->getKey());
    }

    public function reject(RejectModelRequest $request, int $id): JsonResponse
    {
        return $this->respond($this->service->reject($this->tenant(), $request->user(), $id, (string) $request->validated('reason'))->getKey());
    }

    public function promote(ModelDecisionRequest $request, int $id): JsonResponse
    {
        $result = $this->service->promote($this->tenant(), $request->user(), $id, $request->reason());

        return $this->respond($result['model']->getKey(), ['retired_model_ids' => $result['retired']]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(int|string $modelId, array $extra = []): JsonResponse
    {
        $model = ModelRegistry::query()->with('events')->findOrFail($modelId);

        return response()->json(['data' => (new ModelRegistryDetailResource($model))->resolve(request()) + $extra]);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->require();
    }
}
