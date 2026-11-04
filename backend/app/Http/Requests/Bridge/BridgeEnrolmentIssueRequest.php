<?php

declare(strict_types=1);

namespace App\Http\Requests\Bridge;

use Illuminate\Foundation\Http\FormRequest;

/** POST /data-sources/{dataSource}/bridge/enrolments: código de registro de un solo uso (habilidad datasources.manage). */
class BridgeEnrolmentIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_name_hint' => ['nullable', 'string', 'max:120'],
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('bridge.enrolment_max_ttl_minutes', 1440)],
        ];
    }
}
