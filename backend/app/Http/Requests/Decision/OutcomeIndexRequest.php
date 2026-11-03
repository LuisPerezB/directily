<?php

declare(strict_types=1);

namespace App\Http\Requests\Decision;

use App\Models\DecisionRecommendation;
use App\Services\Outcomes\OutcomeVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de GET /decisions/outcomes (V2.5 · W5-C). El periodo filtra por el corte (`as_of`) de la recomendación. El grupo de control solo lo ve quien aprueba. */
class OutcomeIndexRequest extends FormRequest
{
    public const SORTS = ['-measured_at', 'measured_at', '-window_to', 'window_to', '-observed_net_amount', '-contribution', '-id', 'id'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'classification' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'action_kind' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'observed_event' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'experiment_key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'experiment_group' => ['nullable', Rule::in(['treatment', 'control'])],
            'recommendation_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'assigned_user_id' => ['nullable', 'integer', 'min:1'],
            'contribution_known' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return list<string> solo los valores conocidos: uno desconocido no filtra «todo», no devuelve nada */
    public function csv(string $key, array $allowed): array
    {
        return array_values(array_intersect(array_filter(explode(',', (string) $this->query($key, ''))), $allowed));
    }

    /** @return array{kinds: list<string>, classifications: list<string>, actions: list<string>, events: list<string>} */
    public function lists(): array
    {
        return [
            'kinds' => $this->csv('kind', DecisionRecommendation::KINDS),
            'classifications' => $this->csv('classification', OutcomeVocabulary::CLASSIFICATIONS),
            'actions' => $this->csv('action_kind', OutcomeVocabulary::ACTION_KINDS),
            'events' => $this->csv('observed_event', OutcomeVocabulary::EVENTS),
        ];
    }
}
