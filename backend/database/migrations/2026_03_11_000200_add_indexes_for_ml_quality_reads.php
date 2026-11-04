<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W3-D: índices de las lecturas de calidad ML (GET /models/evaluations, /models/promotion-decisions y /models/shadow-evaluations).
 *
 * El contrato de W3 (2026_03_11_000100) dejó en `model_evaluations` solo (tenant_id, forecast_run_id, evaluation_type, level), que sirve a Demand pero no a las
 * filas del Customer Core (`forecast_run_id` NULL, ligadas por `core_run_id`), y en `model_promotion_decisions` solo (tenant_id, core, decided_at) y la
 * unicidad (tenant_id, id): las claves foráneas compuestas hacia `forecast_runs` y `core_runs` NO crean índice en Postgres, de modo que filtrar por ejecución
 * y alcance recorría toda la tabla de la empresa. Aditivo y reversible; no modifica ninguna migración existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_evaluations', function (Blueprint $table) {
            $table->index(['tenant_id', 'core', 'core_run_id', 'level'], 'model_evaluations_tenant_core_run_level_idx');
        });
        Schema::table('model_promotion_decisions', function (Blueprint $table) {
            $table->index(['tenant_id', 'forecast_run_id', 'scope_key', 'policy'], 'model_promotion_decisions_run_scope_policy_idx');
            $table->index(['tenant_id', 'core_run_id', 'scope_key', 'policy'], 'model_promotion_decisions_core_run_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('model_promotion_decisions', function (Blueprint $table) {
            $table->dropIndex('model_promotion_decisions_core_run_scope_idx');
            $table->dropIndex('model_promotion_decisions_run_scope_policy_idx');
        });
        Schema::table('model_evaluations', function (Blueprint $table) {
            $table->dropIndex('model_evaluations_tenant_core_run_level_idx');
        });
    }
};
