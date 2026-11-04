<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Restablecimiento del segundo factor por la plataforma (pérdida del dispositivo y de los códigos de recuperación): el motivo es obligatorio y queda en la auditoría. */
final class MfaResetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Indique el motivo del restablecimiento.', 'reason.min' => 'El motivo debe explicar la verificación de identidad realizada (mínimo 10 caracteres).'];
    }
}
