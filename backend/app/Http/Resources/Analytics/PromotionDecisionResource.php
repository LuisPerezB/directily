<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ModelPromotionDecision;
use App\Services\Analytics\ModelQuality\PromotionDecisionPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModelPromotionDecision */
class PromotionDecisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return PromotionDecisionPresenter::row($this->resource);
    }
}
