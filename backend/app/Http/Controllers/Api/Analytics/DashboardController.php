<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\DashboardRequest;
use App\Services\Analytics\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends AnalyticsController
{
    public function summary(DashboardRequest $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => $dashboard->summary(
                $this->tenant(),
                $request->horizon($this->defaultHorizon()),
                $request->intOrNull('branch_id'),
            ),
        ]);
    }
}
