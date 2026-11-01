<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Tenant;
use App\Services\Analytics\Support\SalesQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta las series activas de una empresa (pares sucursal × producto con alguna venta válida en la ventana) para `max_series`.
 *
 * Es un DISTINCT sobre `sale_items ⋈ sales` de la ventana: puede ser caro en empresas grandes. Por eso (1) corre dentro de una
 * transacción con `SET LOCAL statement_timeout` (PostgreSQL), que se revierte sola al terminar y no afecta a otras consultas, y
 * (2) PlanLimitService la cachea por empresa y día. Si la consulta excede el tiempo, lanza SeriesCountUnavailable: nunca bloquea la petición.
 */
class ActiveSeriesCounter
{
    /** Código SQLSTATE de «query_canceled» (statement_timeout) en PostgreSQL. */
    public const SQLSTATE_QUERY_CANCELED = '57014';

    /** ¿La excepción es la cancelación por `statement_timeout` de PostgreSQL? */
    public static function isTimeout(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === self::SQLSTATE_QUERY_CANCELED;
    }

    /** @throws SeriesCountUnavailable si la consulta supera `predictily.series_count.timeout_ms` */
    public function count(Tenant $tenant, string $since): int
    {
        $timeoutMs = max(1, (int) config('predictily.series_count.timeout_ms', 5000));

        try {
            return DB::transaction(function () use ($tenant, $since, $timeoutMs): int {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL statement_timeout = '.$timeoutMs);   // entero validado arriba: sin parámetros en SET
                }

                return (int) DB::query()->fromSub(
                    SalesQuery::validItems((int) $tenant->id)->where('si.sale_date', '>=', $since)->select('si.branch_id', 'si.product_id')->distinct(),
                    'series',
                )->count();
            });
        } catch (QueryException $e) {
            if (self::isTimeout($e)) {
                throw new SeriesCountUnavailable("El conteo de series activas superó {$timeoutMs} ms.", previous: $e);
            }

            throw $e;
        }
    }
}
