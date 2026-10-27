<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\AlertIndexRequest;
use App\Http\Requests\Analytics\UpdateAlertRequest;
use App\Http\Resources\Analytics\AlertResource;
use App\Models\Alert;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends AnalyticsController
{
    public function index(AlertIndexRequest $request): AnonymousResourceCollection
    {
        $alerts = Alert::query()
            ->when($request->validated('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->validated('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->validated('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->latest('id')
            ->paginate($this->perPage($request));

        return AlertResource::collection($alerts);
    }

    public function update(UpdateAlertRequest $request, int $id): AlertResource
    {
        $alert = Alert::query()->findOrFail($id);
        $status = (string) $request->validated('status');
        $alert->forceFill([
            'status' => $status,
            'acknowledged_by' => $status === Alert::STATUS_OPEN ? null : $request->user()?->getKey(),
            'acknowledged_at' => $status === Alert::STATUS_OPEN ? null : now(),
        ])->save();

        return new AlertResource($alert);
    }
}
