<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Política de seguridad de la empresa (V2.5 · W6-A): hoy, MFA obligatorio para propietarios y administradores. */
final class SecurityPolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mfa_required_for_privileged' => ['required', 'boolean']];
    }
}
