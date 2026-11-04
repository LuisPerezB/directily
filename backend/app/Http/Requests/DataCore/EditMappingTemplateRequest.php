<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corrección humana de una plantilla: `field_map` (campo → «alias.columna», {"const": valor} o null para quitarlo) y `value_maps`
 * (campo → {valor_origen: valor_canónico}, null para quitar el campo). Las referencias y los destinos se validan en el servicio.
 */
class EditMappingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field_map' => ['sometimes', 'array', 'max:60'],
            'value_maps' => ['sometimes', 'array', 'max:20'],
        ];
    }

    /** @return array{field_map?: array<string, mixed>, value_maps?: array<string, mixed>} */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['field_map', 'value_maps']));
    }
}
