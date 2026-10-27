<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['current_password' => 'contraseña actual', 'password' => 'nueva contraseña'];
    }
}
