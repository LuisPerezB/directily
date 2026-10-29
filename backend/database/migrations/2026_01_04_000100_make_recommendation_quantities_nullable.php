<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recomendaciones de abastecimiento honestas (validación 2026-10-28, hallazgos F1–F3).
 *
 * - F2: si el pronóstico disponible no cubre plazo + revisión, el motor NO extrapola: publica
 *   la recomendación sin cantidades (suggested_qty, demand_lead_time, safety_stock y
 *   reorder_point en NULL) y con assumptions.coverage_insufficient = true.
 * - F3: stockout_probability y demand_lead_time_std (ya admitían NULL) quedan en NULL cuando la
 *   incertidumbre es desconocida; solo se actualiza su descripción.
 * - F1: available pasa a ser la existencia física disponible hoy (on_hand − reserved); las
 *   compras abiertas llegan en su fecha y la posición de inventario va en assumptions.
 */
return new class extends Migration
{
    private const NULLABLE = ['suggested_qty', 'demand_lead_time', 'safety_stock', 'reorder_point'];

    public function up(): void
    {
        foreach (self::NULLABLE as $column) {
            DB::statement("ALTER TABLE recommendations ALTER COLUMN {$column} DROP NOT NULL");
        }
        $this->comments([
            'available' => 'Existencias físicas disponibles hoy: on_hand - reserved (las compras abiertas llegan en su fecha; posición de inventario en assumptions)',
            'in_transit' => 'Total pendiente de recibir (órdenes abiertas o in_transit del inventario); ver assumptions.receipts',
            'demand_lead_time' => 'Demanda pronosticada durante plazo + revisión; NULL si el pronóstico no cubre ese período',
            'suggested_qty' => 'Compra sugerida = max(0, reorden - posición de inventario); NULL si no hay base suficiente (assumptions.coverage_insufficient)',
            'demand_lead_time_std' => 'Desviación de la demanda en plazo + revisión; NULL si la incertidumbre es desconocida',
            'stockout_probability' => 'P(quiebre dentro de plazo + revisión) con recepciones por fecha; NULL si la incertidumbre es desconocida',
        ]);
    }

    public function down(): void
    {
        foreach (self::NULLABLE as $column) {
            DB::statement("UPDATE recommendations SET {$column} = 0 WHERE {$column} IS NULL");
            DB::statement("ALTER TABLE recommendations ALTER COLUMN {$column} SET NOT NULL");
        }
        $this->comments([
            'available' => 'on_hand - reserved + in_transit',
            'in_transit' => null,
            'demand_lead_time' => 'Demanda pronosticada durante plazo + revisión',
            'suggested_qty' => null,
            'demand_lead_time_std' => null,
            'stockout_probability' => 'P(demanda en plazo > disponible), aproximación',
        ]);
    }

    /** @param array<string, string|null> $comments */
    private function comments(array $comments): void
    {
        foreach ($comments as $column => $comment) {
            $value = $comment === null ? 'NULL' : DB::getPdo()->quote($comment);
            DB::statement("COMMENT ON COLUMN recommendations.{$column} IS {$value}");
        }
    }
};
