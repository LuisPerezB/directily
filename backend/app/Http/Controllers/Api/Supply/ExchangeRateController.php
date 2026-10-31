<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Model;

/** Tasas de cambio explícitas con fecha y fuente. Sin tasa, una oferta o presupuesto en otra moneda no es comparable. */
class ExchangeRateController extends ConfigResourceController
{
    protected function model(): string
    {
        return ExchangeRate::class;
    }

    protected function entity(): string
    {
        return 'exchange_rate';
    }

    protected function orderBy(): string
    {
        return 'valid_on';
    }

    protected function filters(): array
    {
        return ['from_currency', 'to_currency'];
    }

    protected function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'from_currency' => [$req, 'string', 'size:3', 'alpha'],
            'to_currency' => [$req, 'string', 'size:3', 'alpha', 'different:from_currency'],
            'rate' => [$req, 'numeric', 'gt:0'],
            'valid_on' => [$req, 'date_format:Y-m-d'],
            'source' => [$req, 'string', 'max:50'],
            'known_at' => ['nullable', 'date'],
        ];
    }

    protected function fill(array $data): array
    {
        foreach (['from_currency', 'to_currency'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = strtoupper((string) $data[$k]);
            }
        }

        return $data;
    }

    protected function present(Model $m): array
    {
        /** @var ExchangeRate $m */
        return ['id' => $m->id, 'from_currency' => $m->from_currency, 'to_currency' => $m->to_currency, 'rate' => $m->rate,
            'valid_on' => $m->valid_on?->toDateString(), 'source' => $m->source, 'known_at' => $m->known_at?->toIso8601String()];
    }
}
