<?php

declare(strict_types=1);

namespace App\Http\Requests\Models;

use Illuminate\Foundation\Http\FormRequest;

/** POST /models/{id}/reject: el motivo es obligatorio (queda en el evento de auditoría y en `rejected_reason`). */
class RejectModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indique el motivo del rechazo.',
            'reason.min' => 'El motivo del rechazo debe tener al menos 5 caracteres.',
        ];
    }
}
