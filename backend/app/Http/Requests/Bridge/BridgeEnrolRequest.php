<?php

declare(strict_types=1);

namespace App\Http\Requests\Bridge;

use Illuminate\Foundation\Http\FormRequest;

/** POST /bridge/enrol (público, acotado por IP): canje del código de registro con la clave pública del dispositivo y su prueba de posesión. */
class BridgeEnrolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'device_name' => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'proof' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'timestamp' => ['required', 'integer', 'min:1000000000'],
            'agent_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'in:windows,linux,macos,other'],
        ];
    }
}
