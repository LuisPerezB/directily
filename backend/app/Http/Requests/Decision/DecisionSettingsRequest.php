<?php

declare(strict_types=1);

namespace App\Http\Requests\Decision;

use App\Models\DecisionRecommendation;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /decisions/settings: parámetros del Decision Engine de la empresa (todos provisionales, «a aprobar en el piloto»). Solo claves conocidas. */
class DecisionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kinds = Rule::in(DecisionRecommendation::KINDS);

        return [
            'control_fraction' => ['sometimes', 'numeric', 'min:0', 'max:0.5'],
            'experiment_key' => ['sometimes', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'experiment_seed' => ['sometimes', 'nullable', 'string', 'min:8', 'max:120'],
            'horizons' => ['sometimes', 'array', 'min:1', 'max:5'],
            'horizons.*' => ['integer', Rule::in(HorizonCatalog::customer())],   // catálogo único de horizontes de Customer (ADR-V25-02)
            'contact_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'contact_source' => ['sometimes', Rule::in(['engine', 'radar'])],
            'contact_min_probability' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'max_contact_per_horizon' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'max_supply_candidates' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'expiry_days' => ['sometimes', 'array:recovery,customer_risk,inventory_mismatch,budget_constraint,scenario'],
            'expiry_days.*' => ['integer', 'min:1', 'max:365'],
            'approval_required_kinds' => ['sometimes', 'array', 'max:7'],
            'approval_required_kinds.*' => ['string', $kinds],
            'approval_cost_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unreliable_cost_weight' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'cost_reliability' => ['sometimes', 'array:window_days,min_observations,min_coverage,max_cv'],
            'cost_reliability.window_days' => ['integer', 'min:7', 'max:730'],
            'cost_reliability.min_observations' => ['integer', 'min:1', 'max:100'],
            'cost_reliability.min_coverage' => ['numeric', 'min:0', 'max:1'],
            'cost_reliability.max_cv' => ['numeric', 'min:0', 'max:5'],
            'inventory_max_age_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'excess_demand_ratio' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
