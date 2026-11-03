<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W5-C · una medición por recomendación (complemento de 2026_03_13_000100, que no se modifica).
 *
 * `decision_outcomes` quedó con un índice NO único por (empresa, recomendación): la idempotencia de la medición dependía solo del servicio (candado consultivo por empresa +
 * bloqueo de las filas). Un índice único lo garantiza aun si algún día se escribe desde otro camino o falla el candado: el servicio trata la violación como «ya medida».
 * El resultado es inmutable, así que de haber duplicados (no debería: el servicio nunca los crea) se conserva la fila MÁS ANTIGUA de cada recomendación.
 * Aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM decision_outcomes a USING decision_outcomes b WHERE a.tenant_id = b.tenant_id AND a.recommendation_id = b.recommendation_id AND a.id > b.id');
        DB::statement('CREATE UNIQUE INDEX decision_outcomes_recommendation_unique ON decision_outcomes (tenant_id, recommendation_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS decision_outcomes_recommendation_unique');
    }
};
