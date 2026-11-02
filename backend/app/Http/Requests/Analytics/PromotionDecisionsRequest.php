<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Http\Requests\Analytics\Concerns\NormalizesBooleanQuery;
use App\Services\Analytics\ModelQuality\PromotionDecisionPresenter;
use Illuminate\Validation\Rule;

/** GET /models/promotion-decisions (V2.5 · W3-D): decisiones de la política heredada y de la V2.5, por ejecución y alcance. */
class PromotionDecisionsRequest extends AnalyticsRequest
{
    use NormalizesBooleanQuery;

    /** @return list<string> */
    protected function booleanFilters(): array
    {
        return ['is_effective'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'core' => ['nullable', Rule::in(['demand', 'customer'])],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'core_run_id' => ['nullable', 'integer', 'min:1'],
            'policy' => ['nullable', Rule::in(PromotionDecisionPresenter::POLICIES)],
            'scope' => ['nullable', 'string', 'max:80'],
            'decision' => ['nullable', Rule::in(PromotionDecisionPresenter::DECISIONS)],
            'is_effective' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
