<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
