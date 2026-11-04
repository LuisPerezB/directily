<?php

declare(strict_types=1);

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Constructor de consultas de `GatewayToolCall`: la auditoría del gateway es de SOLO INSERCIÓN. Además de los eventos del modelo y del disparador de la base de datos
 * (migración 2026_03_14_000500), aquí se cierran las vías masivas de Eloquent (`->where(...)->update()`, `->delete()`…), que no disparan los eventos del modelo.
 * La retención y el borrado por derecho de supresión pasan por AuditRetention::purge().
 *
 * @template TModel of \App\Models\GatewayToolCall
 *
 * @extends Builder<TModel>
 */
class GatewayToolCallBuilder extends Builder
{
    private const MESSAGE = 'La auditoría del gateway es de solo inserción.';

    public function update(array $values): int
    {
        throw new LogicException(self::MESSAGE);
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException(self::MESSAGE);
    }

    public function delete(): mixed
    {
        throw new LogicException(self::MESSAGE);
    }

    public function forceDelete(): mixed
    {
        throw new LogicException(self::MESSAGE);
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw new LogicException(self::MESSAGE);
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw new LogicException(self::MESSAGE);
    }
}
