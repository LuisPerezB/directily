<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `model_evaluations`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class ModelEvaluation extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** V2.5 · W3-A / W3-C: métricas por segmento (Demand: dimensión × valor × horizonte; Customer: calibración por segmento). */
    public const LEVEL_SEGMENT = 'segment';

    /** V2.5 · W3-B: evaluación prospectiva del challenger (shadow) contra lo realizado después del corte. */
    public const TYPE_SHADOW_PROSPECTIVE = 'shadow_prospective';

    /**
     * Filas del Customer Core de S05 (W3-C) que no existían antes: nº de pedidos, cantidad por pedido, unidades, ranking económico,
     * estabilidad, segmentador y ventana de recompra (`level_key`, nivel `overall`). Las consume solo `GET /models/evaluations`.
     */
    public const CUSTOMER_S05_KEY_PATTERN = '^(h[0-9]+:(expected_orders|quantity_per_order|expected_units|ranking_s05|stability|segmenter)|window)$';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean',
            'is_selected' => 'boolean',
            'wape' => 'float',
            'mae' => 'float',
            'rmse' => 'float',
            'bias' => 'float',
            'coverage_80' => 'float',
            'coverage_95' => 'float',
            'cutoffs' => 'array',
            'extra' => 'array',
            'metrics' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Vista CLÁSICA de las evaluaciones (V2.5 · W3-D): excluye las filas de segmento y la evaluación prospectiva del shadow salvo que la
     * petición las pida explícitamente (`$level` / `$evaluationType` informados). Así los consumidores anteriores a W3 (precisión, exportación
     * CSV, evaluación del Customer Core) devuelven exactamente lo mismo aunque el motor escriba filas nuevas; el detalle de calidad vive en
     * `GET /models/evaluations`.
     *
     * @param  Builder<ModelEvaluation>  $query
     */
    public function scopeClassicView(Builder $query, ?string $level = null, ?string $evaluationType = null): void
    {
        if ($level === null) {
            $query->where('level', '<>', self::LEVEL_SEGMENT);
        }
        if ($evaluationType === null) {
            $query->where('evaluation_type', '<>', self::TYPE_SHADOW_PROSPECTIVE);
        }
    }

    /**
     * Igual que `classicView` y además sin las filas S05 del Customer Core (ver CUSTOMER_S05_KEY_PATTERN): la evaluación del Customer Core
     * conserva el contenido que tenía antes de W3-C.
     *
     * @param  Builder<ModelEvaluation>  $query
     */
    public function scopeCustomerClassicView(Builder $query, ?string $level = null): void
    {
        $query->classicView($level)->where(fn (Builder $q) => $q->whereNull('level_key')->orWhereRaw('level_key !~ ?', [self::CUSTOMER_S05_KEY_PATTERN]));
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function modelRegistry(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class, 'model_registry_id');
    }
}
