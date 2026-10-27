<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Analytics\Support\ModelCatalog;

/**
 * Explicaciones en lenguaje sencillo generadas con PLANTILLAS deterministas a partir de
 * métricas ya calculadas. No usa LLM, no inventa cifras y no convierte métricas como R²
 * en "porcentajes de confianza".
 */
class AccuracyExplainer
{
    /**
     * @param  array<string, mixed>  $context  backtest, realized, data (ver AccuracyService)
     * @return list<string>
     */
    public function explain(array $context): array
    {
        $lines = [];
        $selected = $context['backtest']['selected'] ?? null;
        $baseline = $context['backtest']['baseline'] ?? null;
        $windows = (int) ($context['backtest']['n_windows'] ?? 0);

        if ($selected === null || $selected['wape'] === null) {
            $lines[] = 'Todavía no hay métricas de validación histórica para esta ejecución.';
        } else {
            $sel = $this->pct($selected['wape']);
            $windowText = $windows > 0 ? " con {$windows} cortes sucesivos" : '';
            if ($baseline !== null && $baseline['wape'] !== null) {
                $base = $this->pct($baseline['wape']);
                $baseName = ModelCatalog::label($baseline['model_family'] ?? null);
                $diff = round(((float) $baseline['wape'] - (float) $selected['wape']) * 100, 1);
                if ($diff > 0.05) {
                    $lines[] = "En la validación histórica (backtesting temporal{$windowText}), el modelo seleccionado tuvo un error WAPE de {$sel}, frente a {$base} del método de referencia «{$baseName}»: {$this->num($diff)} puntos porcentuales menos de error.";
                } else {
                    $lines[] = "En la validación histórica (backtesting temporal{$windowText}), ningún modelo superó al método de referencia «{$baseName}» (WAPE {$base}); por eso se usa el método más simple.";
                }
            } else {
                $lines[] = "En la validación histórica (backtesting temporal{$windowText}), el modelo seleccionado tuvo un error WAPE de {$sel}.";
            }
            $lines[] = "El WAPE mide el error total relativo a lo vendido: un WAPE de {$sel} significa que, por cada 100 unidades vendidas, el pronóstico se desvió en total unas ".$this->num(round((float) $selected['wape'] * 100)).' unidades.';

            $bias = $selected['bias'] ?? null;
            if ($bias !== null) {
                $lines[] = abs((float) $bias) >= 0.05
                    ? ((float) $bias > 0
                        ? 'El modelo tiende a sobreestimar la demanda en aproximadamente '.$this->pct(abs((float) $bias)).'.'
                        : 'El modelo tiende a subestimar la demanda en aproximadamente '.$this->pct(abs((float) $bias)).'.')
                    : 'No se observa un sesgo relevante (menor al 5%): el modelo no sobreestima ni subestima de forma sistemática.';
            }

            $coverage = $selected['coverage_80'] ?? null;
            if ($coverage !== null) {
                $text = 'El rango de 80% contuvo la venta real el '.$this->pct($coverage).' de las veces';
                $text .= match (true) {
                    (float) $coverage < 0.70 => '; es menos de lo esperado, así que conviene tomar los rangos con cautela.',
                    (float) $coverage > 0.92 => '; es más amplio de lo necesario (rangos conservadores).',
                    default => ', cercano a lo esperado.',
                };
                $lines[] = $text;
            }
        }

        $byHorizon = $context['backtest']['by_horizon'] ?? [];
        $parts = [];
        foreach ($byHorizon as $row) {
            if (($row['wape'] ?? null) !== null && ($row['horizon_days'] ?? null) !== null) {
                $parts[] = $this->pct($row['wape']).' a '.$row['horizon_days'].' días';
            }
        }
        if (count($parts) >= 2) {
            $lines[] = 'El error cambia con la distancia del pronóstico: '.implode(', ', $parts).'. Los pronósticos lejanos son menos precisos.';
        }

        $data = $context['data'] ?? [];
        if (($data['has_inventory'] ?? false) === false) {
            $lines[] = 'No hay datos de inventario: no es posible distinguir días sin demanda de días sin stock, por lo que la demanda real podría estar subestimada.';
        } elseif (($data['censored_days'] ?? 0) > 0) {
            $lines[] = 'Se detectaron '.$data['censored_days'].' días con posible quiebre de stock en '.$data['censored_series'].' series: en esos días la venta no refleja toda la demanda y se excluyen de la evaluación.';
        }

        $realized = $context['realized'] ?? null;
        if ($realized !== null && ($realized['wape'] ?? null) !== null) {
            $line = 'Con las ventas reales posteriores, el error observado fue '.$this->pct($realized['wape']).' ('.$realized['n_obs'].' observaciones).';
            if ($selected !== null && $selected['wape'] !== null && (float) $realized['wape'] > max(1.3 * (float) $selected['wape'], (float) $selected['wape'] + 0.10)) {
                $line .= ' Es notablemente mayor que en la validación histórica: el comportamiento de las ventas pudo cambiar y conviene reentrenar.';
            }
            $lines[] = $line;
        }

        $lines[] = 'Estas cifras describen errores pasados y orientan la confianza en el pronóstico; no garantizan la precisión futura.';

        return $lines;
    }

    private function pct(mixed $ratio): string
    {
        return $this->num(round((float) $ratio * 100, 1)).'%';
    }

    private function num(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, ',', '.'), '0'), ',');
    }
}
