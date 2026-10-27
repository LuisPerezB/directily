<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use App\Http\Middleware\ResolveTenant;
use App\Services\Plans\PlanLimitService;
use App\Services\Tenancy\OnboardingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
final class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $request->attributes->get('tenant_role') ?? $request->user()?->roleIn($this->resource);
        $plan = $this->plan_id ? $this->plan()->first() : null;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'sector' => $this->sector,
            'country' => $this->country,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'status' => $this->status,
            'settings' => $this->effectiveSettings(),
            'allow_shared_training' => (bool) $this->allow_shared_training,
            'shared_training_consented_at' => $this->shared_training_consented_at?->toIso8601String(),
            'data_retention_months' => $this->data_retention_months,
            'onboarding_completed_at' => $this->onboarding_completed_at?->toIso8601String(),
            'deletion_requested_at' => $this->deletion_requested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'abilities' => $role?->abilities() ?? [],
            'plan' => [
                'code' => $plan?->code ?? config('predictily.default_plan'),
                'name' => $plan?->name,
                'limits' => app(PlanLimitService::class)->limits($this->resource),
            ],
            'onboarding' => app(OnboardingStatus::class)->for($this->resource),
        ];
    }
}
