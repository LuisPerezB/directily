<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Segundo paso del inicio de sesión: el desafío entregado tras la contraseña y un código TOTP o de recuperación. */
final class MfaChallengeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.required_without' => 'Ingrese el código de verificación o un código de recuperación.', 'recovery_code.required_without' => 'Ingrese el código de verificación o un código de recuperación.'];
    }
}
