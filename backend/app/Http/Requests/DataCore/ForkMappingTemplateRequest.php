<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use Illuminate\Foundation\Http\FormRequest;

/** Bifurcar una plantilla pública: fuente de la empresa a la que se destina (opcional). */
class ForkMappingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['data_source_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }
}
