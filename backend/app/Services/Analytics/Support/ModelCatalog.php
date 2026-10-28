<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/** Nombres comprensibles de las familias de modelos del motor. */
final class ModelCatalog
{
    private const LABELS = [
        'naive' => 'Ingenuo (repite el último valor)',
        'seasonal_naive' => 'Estacional ingenuo (repite la semana anterior)',
        'window_average' => 'Promedio móvil',
        'auto_ets' => 'Suavizamiento exponencial (ETS)',
        'croston_sba' => 'Croston SBA (demanda intermitente)',
        'adida' => 'ADIDA (demanda intermitente)',
        'tsb' => 'TSB (demanda intermitente)',
        'lightgbm_global' => 'LightGBM global (machine learning)',
        'timesfm' => 'TimesFM (experimental)',
        'selection' => 'Selección por patrón de demanda',
    ];

    private const DEMAND_CLASSES = [
        'smooth' => 'regular',
        'erratic' => 'errática',
        'intermittent' => 'intermitente',
        'lumpy' => 'esporádica',
        'insufficient' => 'con historia insuficiente',
    ];

    public static function label(?string $family): string
    {
        return $family === null ? 'Sin modelo' : (self::LABELS[$family] ?? $family);
    }

    public static function demandClass(?string $class): string
    {
        return $class === null ? 'sin clasificar' : (self::DEMAND_CLASSES[$class] ?? $class);
    }

    /** Familias consideradas compuestas (resultado de la selección por clase de demanda). */
    public static function isComposite(?string $family): bool
    {
        return in_array($family, ['selection', 'auto', 'selected', 'ensemble'], true);
    }
}
