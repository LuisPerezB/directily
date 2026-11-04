<?php

declare(strict_types=1);

namespace App\Http\Requests\Bridge;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /bridge/checkpoints: el agente confirma un lote ya subido (cursor intermedio) o el fin de la extracción (marca de agua). */
class BridgeCheckpointCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entity' => ['required', 'string', 'regex:/^[a-z_]{2,30}$/'],
            'run_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{4,40}$/'],
            'batch_sha256' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'run_batch_index' => ['required_with:batch_sha256', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'run_batches' => [Rule::requiredIf(fn () => $this->boolean('run_complete')), 'nullable', 'integer', 'min:0', 'max:10000000'],
            'checkpoint' => ['nullable', 'string', 'max:200'],
            'watermark' => ['nullable', 'date'],
            'run_complete' => ['required', 'boolean'],
        ];
    }
}
