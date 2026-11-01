<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\StoreCustomerRunRequest;
use App\Http\Resources\Customer\CustomerRunResource;
use App\Models\CoreRun;
use App\Services\Customer\CustomerRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Sanctum\PersonalAccessToken;

/** Corridas del Customer Core: entrenar y predecir comportamiento de compra (asíncronas, en la cola `forecasting`). */
class CustomerRunController extends CustomerController
{
    public function store(StoreCustomerRunRequest $request, CustomerRunService $service): JsonResponse
    {
        $run = $service->request(
            tenant: $this->tenant(),
            user: $request->user(),
            operation: (string) $request->validated('operation'),
            horizons: $request->validated('horizons'),
            asOf: $request->validated('as_of'),
            triggeredBy: $request->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'user',
        );

        return (new CustomerRunResource($run))->response()->setStatusCode(202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = CoreRun::query()->where('core', 'customer')->with('trainingJobs')
            ->when($request->query('operation'), fn ($q, $op) => $q->where('operation', (string) $op))
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', (string) $st))
            ->latest('id')->paginate($this->perPage($request, 20));

        return CustomerRunResource::collection($runs);
    }

    public function show(int $id): CustomerRunResource
    {
        return new CustomerRunResource(CoreRun::query()->where('core', 'customer')->with('trainingJobs')->findOrFail($id));
    }
}
