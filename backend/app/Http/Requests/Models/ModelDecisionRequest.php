<?php

declare(strict_types=1);

namespace App\Http\Requests\Models;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de validar / aprobar / promover un modelo: justificación opcional. El permiso (`models.approve` o `forecast.run`) lo valida el
 * middleware de la ruta; la segregación de funciones, el servicio.
 */
class ModelDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
