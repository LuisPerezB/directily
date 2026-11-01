<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W2-F · Segregación de funciones en la verificación de eventos externos (ADR-V25-25).
 *
 * El contrato 2026_03_04 guarda quién VERIFICÓ un evento (`verified_by`) pero no quién lo PROPUSO, y sin ese dato no se puede exigir que
 * verifique un usuario distinto. Esta migración aditiva agrega `proposed_by` (NULL = propuesto por un conector o por el LLM, sin usuario) y
 * `review_note` (motivo de un rechazo o nota de la verificación). `verified_by`/`verified_at` guardan al revisor tanto de VERIFIED como de REJECTED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_events', function (Blueprint $table) {
            $table->foreignId('proposed_by')->nullable()->after('extracted_by')->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_by');
            $table->dropColumn('review_note');
        });
    }
};
