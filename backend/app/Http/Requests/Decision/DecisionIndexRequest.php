<?php

declare(strict_types=1);

namespace App\Http\Requests\Decision;

use App\Models\DecisionRecommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de GET /decisions. Las de control solo las puede pedir quien aprueba (`include_control`); a los demás nunca se les listan. */
class DecisionIndexRequest extends FormRequest
{
    public const SORTS = ['-score', 'score', 'priority', '-priority', '-created_at', 'created_at', 'expires_at', '-expires_at', '-as_of'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'kind' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'assigned_user_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'attribution_label' => ['nullable', Rule::in(DecisionRecommendation::ATTRIBUTION_LABELS)],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'expiring_before' => ['nullable', 'date'],
            'include_control' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return list<string> */
    public function csv(string $key, array $allowed): array
    {
        $values = array_filter(explode(',', (string) $this->query($key, '')));

        return array_values(array_intersect($values, $allowed));
    }
}
