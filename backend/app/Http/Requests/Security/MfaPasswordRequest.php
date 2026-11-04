<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** La contraseña actual (iniciar la activación del segundo factor): quien solo tiene una sesión o un token no puede enrolar su propio autenticador. */
final class MfaPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:200']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.required' => 'Confirme con su contraseña actual.'];
    }
}
