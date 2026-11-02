<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use Illuminate\Validation\Rule;

/** Alias V2.2 de los pronósticos agregados: `level` elige el endpoint vigente al que se delega (series, products o categories). */
class DemandForecastsRequest extends RadarRequest
{
    public const LEVELS = ['product', 'category', 'series'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['level' => ['nullable', Rule::in(self::LEVELS)]];
    }
}
