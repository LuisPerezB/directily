<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
            'accept_terms' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'email' => 'correo electrónico', 'password' => 'contraseña', 'accept_terms' => 'términos y política de privacidad'];
    }
}
