<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\StoreForecastRunRequest;
use App\Http\Resources\Analytics\ForecastRunResource;
use App\Http\Resources\Analytics\TrainingJobResource;
use App\Models\ForecastRun;
use App\Models\TrainingJob;
use App\Services\Forecasting\ForecastRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Sanctum\PersonalAccessToken;

/** Ejecuciones de pronóstico y trabajos asíncronos. */
class ForecastRunController extends AnalyticsController
{
    public function store(StoreForecastRunRequest $request, ForecastRunService $service): JsonResponse
    {
        $run = $service->request(
            tenant: $this->tenant(),
            user: $request->user(),
            horizonDays: (int) ($request->validated('horizon_days') ?? 90),
            cutoffDate: $request->validated('cutoff_date'),
            triggeredBy: $this->isApiToken($request) ? 'api' : 'user',
        );
        $run->loadMissing('trainingJob');

        return (new ForecastRunResource($run))->response()->setStatusCode(202);
    }

    /** Peticiones con token personal (integraciones) frente a sesión SPA. */
    private function isApiToken(Request $request): bool
    {
        return $request->user()?->currentAccessToken() instanceof PersonalAccessToken;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = ForecastRun::query()
            ->with('trainingJob')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', (string) $status))
            ->latest('id')
            ->paginate($this->perPage($request, 20));

        return ForecastRunResource::collection($runs);
    }

    public function show(int $id): ForecastRunResource
    {
        $run = ForecastRun::query()->with('trainingJob')->findOrFail($id);

        return new ForecastRunResource($run);
    }

    public function job(int $id): TrainingJobResource
    {
        return new TrainingJobResource(TrainingJob::query()->findOrFail($id));
    }
}
