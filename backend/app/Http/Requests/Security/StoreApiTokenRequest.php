<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Token personal con alcance mínimo: empresa (uuid), nombre, habilidades EXPLÍCITAS (nunca «*») y caducidad. Que las habilidades no superen las del rol de la persona en esa
 * empresa lo verifica TokenService (422 con la lista de las no permitidas).
 */
final class StoreApiTokenRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'abilities' => ['required', 'array', 'min:1', 'max:40'],
            'abilities.*' => ['required', 'string', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('security.tokens.max_ttl_days', 365)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'abilities.required' => 'Indique las habilidades del token: se emite con el alcance mínimo, nunca con todas.',
            'abilities.*.regex' => 'Habilidad no válida.',
        ];
    }
}
