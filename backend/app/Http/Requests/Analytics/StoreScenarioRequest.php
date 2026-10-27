<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

class StoreScenarioRequest extends SimulateScenarioRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
