<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use Illuminate\Foundation\Http\FormRequest;

/** Validación estructural del lote; las reglas de negocio las aplica SalesLineNormalizer. */
final class SalesBatchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:500'],
            'documents.*.document_number' => ['required', 'string', 'max:100'],
            'documents.*.document_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.issued_at' => ['required', 'string', 'max:40'],
            'documents.*.branch_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'documents.*.channel' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.customer_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.customer_segment' => ['sometimes', 'nullable', 'string', 'max:150'],
            'documents.*.customer_province' => ['sometimes', 'nullable', 'string', 'max:80'],
            'documents.*.items' => ['required', 'array', 'min:1', 'max:1000'],
            'documents.*.items.*.product_sku' => ['required', 'string', 'max:100'],
            'documents.*.items.*.product_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.items.*.category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'documents.*.items.*.quantity' => ['required'],
            'documents.*.items.*.unit_price' => ['required'],
            'documents.*.items.*.discount' => ['sometimes', 'nullable'],
            'documents.*.items.*.tax' => ['sometimes', 'nullable'],
            'documents.*.items.*.net_amount' => ['sometimes', 'nullable'],
            'documents.*.items.*.unit_cost' => ['sometimes', 'nullable'],
            'documents.*.items.*.line_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'options' => ['sometimes', 'array:create_missing_products,create_missing_branches,create_missing_categories'],
            'options.*' => ['boolean'],
        ];
    }
}
