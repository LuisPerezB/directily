<?php

declare(strict_types=1);

namespace App\Http\Requests\Bridge;

use Illuminate\Foundation\Http\FormRequest;

/** POST /bridge/heartbeat: versión, plataforma y salud informada por el agente (informativa; acotada y sin secretos). */
class BridgeHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'in:windows,linux,macos,other'],
            'health' => ['nullable', 'array'],
            'health.status' => ['nullable', 'string', 'max:30'],
            'health.pending_batches' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'health.spool_bytes' => ['nullable', 'integer', 'min:0'],
            'health.last_error' => ['nullable', 'string', 'max:300'],
            'health.last_success_at' => ['nullable', 'date'],
            'health.warnings' => ['nullable', 'array', 'max:10'],
            'health.warnings.*' => ['string', 'max:300'],
            'health.entities' => ['nullable', 'array', 'max:20'],
            'health.entities.*' => ['array'],
            'health.entities.*.entity' => ['string', 'max:30'],
            'health.entities.*.status' => ['nullable', 'string', 'max:30'],
            'health.entities.*.watermark' => ['nullable', 'string', 'max:40'],
            'health.entities.*.rows_sent' => ['nullable', 'integer', 'min:0'],
            'health.entities.*.last_error' => ['nullable', 'string', 'max:300'],
            'health.templates' => ['nullable', 'array', 'max:20'],
            'health.templates.*' => ['array'],
            'health.templates.*.entity' => ['string', 'max:30'],
            'health.templates.*.id' => ['string', 'max:80'],
            'health.templates.*.version' => ['integer', 'min:1'],
            'health.templates.*.sha256' => ['string', 'size:64'],
            'health.source' => ['nullable', 'array'],
            'health.source.kind' => ['nullable', 'string', 'max:30'],
            'health.source.read_only_enforcement' => ['nullable', 'string', 'max:80'],
            'health.source.writable_account' => ['nullable', 'boolean'],
        ];
    }
}
