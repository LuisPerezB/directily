<?php

declare(strict_types=1);

namespace App\Http\Requests\Decision;

use App\Models\DecisionRecommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /decisions/{id}/transition. La habilidad concreta la decide la transición (assign/review/dismiss/start/execute/close: decisions.manage;
 * approve/reject: decisions.approve) y la segregación de funciones el servicio; la ruta solo exige decisions.manage como mínimo.
 */
class DecisionTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(DecisionRecommendation::STATUSES)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'assignee_user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Indique idempotency_key: un reintento con la misma clave no repite la transición.',
            'idempotency_key.regex' => 'idempotency_key solo admite letras, números y . _ : -',
            'to_status.in' => 'Estado de destino no válido.',
        ];
    }

    /** @return array{expected_version: int|null, reason: string|null, assignee_user_id: int|null} */
    public function params(): array
    {
        return [
            'expected_version' => $this->validated('expected_version') === null ? null : (int) $this->validated('expected_version'),
            'reason' => $this->validated('reason'),
            'assignee_user_id' => $this->validated('assignee_user_id') === null ? null : (int) $this->validated('assignee_user_id'),
        ];
    }
}
