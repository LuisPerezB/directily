<?php

declare(strict_types=1);

namespace App\Http\Requests\Bridge;

use Illuminate\Foundation\Http\FormRequest;

/** POST /bridge/uploads: declaración de UN lote comprimido (el servidor responde con una URL prefirmada acotada o `duplicate`). */
class BridgeUploadDeclareRequest extends FormRequest
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
            'sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'row_count' => ['required', 'integer', 'min:1', 'max:'.(int) config('bridge.max_batch_rows', 50_000)],
            'content_type' => ['required', 'string', 'in:application/gzip'],
            'columns' => ['required', 'array', 'min:1', 'max:200'],
            'columns.*' => ['nullable', 'string', 'max:20'],
            'source_timezone' => ['nullable', 'string', 'timezone:all'],
            'run_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{4,40}$/'],
            'seq' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'run_mode' => ['nullable', 'string', 'in:full,incremental'],
            'template_version' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
