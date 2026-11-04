<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Un código TOTP de 6 dígitos y la CONTRASEÑA actual (activar el segundo factor o generar códigos de recuperación nuevos): quien solo tiene una sesión o un token no puede enrolar su propio autenticador. */
final class MfaCodeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20'], 'password' => ['required', 'string', 'max:200']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.required' => 'Ingrese el código de verificación.', 'password.required' => 'Confirme con su contraseña actual.'];
    }
}
