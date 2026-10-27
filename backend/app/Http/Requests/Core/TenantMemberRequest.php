<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TenantMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'email' => [$isCreate ? 'required' : 'prohibited', 'string', 'email:rfc', 'max:191'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'role' => ['required', Rule::enum(TenantRole::class)],
        ];
    }
}
