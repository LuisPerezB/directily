<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportMappingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'array:date_format,decimal_separator,thousands_separator,csv_delimiter,default_branch_code,create_missing_products,create_missing_branches,create_missing_categories,skip_invalid_rows'],
            'options.date_format' => ['sometimes', Rule::in(['auto', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'])],
            'options.decimal_separator' => ['sometimes', Rule::in(['auto', '.', ','])],
            'options.thousands_separator' => ['sometimes', 'nullable', Rule::in(['.', ',', ' ', ''])],
            'options.csv_delimiter' => ['sometimes', Rule::in(['auto', ',', ';', '\t', '|'])],
            'options.default_branch_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'options.create_missing_products' => ['sometimes', 'boolean'],
            'options.create_missing_branches' => ['sometimes', 'boolean'],
            'options.create_missing_categories' => ['sometimes', 'boolean'],
            'options.skip_invalid_rows' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        $options = $this->validated('options', []);
        foreach (['create_missing_products', 'create_missing_branches', 'create_missing_categories', 'skip_invalid_rows'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $options[$flag] = filter_var($options[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $options;
    }
}
