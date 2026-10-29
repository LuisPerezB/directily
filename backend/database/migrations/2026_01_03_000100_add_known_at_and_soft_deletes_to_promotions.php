<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de disponibilidad de las promociones (auditoría 2026-10-28, hallazgo F5).
 *
 * - known_at: momento en que el negocio conocía/registró la promoción. El motor solo la usa
 *   para pronosticar fechas posteriores a un corte si known_at ≤ corte. Por defecto es el alta
 *   en Predictily (created_at); las importaciones de históricos pueden indicar la fecha real.
 * - deleted_at: la baja pasa a ser lógica para conservar el historial (promotion_revisions):
 *   una promoción cancelada sigue siendo lo que se sabía en los cortes anteriores a su baja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->timestampTz('known_at')->nullable()
                ->comment('Momento en que el negocio conocía/registró la promoción (no futuro); por defecto = created_at');
            $table->softDeletesTz();
        });

        // Backfill: lo único demostrable para las promociones existentes es su alta en Predictily.
        DB::statement('UPDATE promotions SET known_at = COALESCE(created_at, now()) WHERE known_at IS NULL');
        DB::statement('ALTER TABLE promotions ALTER COLUMN known_at SET DEFAULT CURRENT_TIMESTAMP, ALTER COLUMN known_at SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['known_at', 'deleted_at']);
        });
    }
};
