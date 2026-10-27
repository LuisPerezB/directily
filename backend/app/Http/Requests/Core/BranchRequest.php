<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class BranchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('branch')?->id;
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-]+$/', TenantRule::unique('branches', 'code', $id)],
            'name' => [$req, 'string', 'max:150'],
            'province' => ['sometimes', 'nullable', 'string', 'max:80'],
            'canton' => ['sometimes', 'nullable', 'string', 'max:80'],
            'commercial_zone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'código', 'name' => 'nombre', 'province' => 'provincia', 'canton' => 'cantón'];
    }
}
