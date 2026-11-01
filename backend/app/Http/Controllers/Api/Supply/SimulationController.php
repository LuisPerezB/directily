<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Resources\Supply\SupplyRunResource;
use App\Models\CoreRun;
use App\Services\Supply\SupplyRunService;
use App\Support\Results\JsonMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Simulación histórica de operación (E2-20): re-juega políticas de reposición (etapa 1, referencia simple y planificador)
 * con la información disponible a cada fecha y mide demanda no atendida, sobrestock, nivel de servicio, días de inventario,
 * costos y capital comprometido. No reemplaza la validación con datos reales: la demanda es la venta observada (censurada).
 */
class SimulationController extends SupplyController
{
    public function store(Request $request, SupplyRunService $service): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'min:28', 'max:365'],
            'review_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'seed' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $run = $service->request($this->tenant(), $request->user(), 'simulation', array_filter($data, fn ($v) => $v !== null) + ['historical' => true],
            $request->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'user');

        return (new SupplyRunResource($run))->response()->setStatusCode(202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplyRunResource::collection(CoreRun::query()->where('core', 'supply')->where('operation', 'simulate')->with('trainingJobs')->latest('id')->paginate($this->perPage($request, 20)));
    }

    public function show(int $id): JsonResponse
    {
        $run = CoreRun::query()->where('core', 'supply')->where('operation', 'simulate')->with('trainingJobs')->findOrFail($id);

        return response()->json([
            'data' => ['run' => (new SupplyRunResource($run))->resolve(request()), 'results' => $run->status === 'completed' ? JsonMap::of($run->summary) : null],
            'envelope' => $this->envelope($run, ['result_type' => 'evaluation']),
        ]);
    }
}
