<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use Illuminate\Foundation\Http\FormRequest;

/** POST /assistant/messages: mensaje acotado y contexto previo opcional (solo user/assistant, tamaño limitado). */
class AssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la habilidad data.view se exige en la ruta (tenant.ability)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxChars = max(1, (int) config('conversation.limits.max_message_chars', 1000));
        $maxHistory = max(0, (int) config('conversation.limits.max_history_messages', 6));
        $maxHistoryChars = max(1, (int) config('conversation.limits.max_history_chars', 1500));

        return [
            'message' => ['required', 'string', 'min:1', 'max:'.$maxChars],
            'history' => ['sometimes', 'array', 'max:'.$maxHistory],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:'.$maxHistoryChars],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.max' => 'El mensaje no puede superar :max caracteres.',
            'history.max' => 'El contexto previo no puede superar :max mensajes.',
        ];
    }
}
