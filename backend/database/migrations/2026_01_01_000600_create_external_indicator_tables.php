<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indicadores externos (INEC, BCE, combustibles, materias primas, clima...).
 *
 * Prevención de fuga temporal: cada observación guarda la fecha del período
 * observado (period_date) y la fecha REAL de publicación (published_at). En un
 * backtest con corte T solo pueden usarse observaciones con published_at <= T,
 * tomando la última revisión disponible a esa fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = indicador público compartido');
            $table->string('code', 80)->comment('p. ej. EC_INEC_IPC_GENERAL');
            $table->string('name', 200);
            $table->string('source', 50)->comment('INEC, BCE, EIA, FRED, manual, ...');
            $table->string('frequency', 15)->comment('daily, weekly, monthly, quarterly, annual');
            $table->string('unit', 50)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('affected_sectors')->default('[]');
            $table->string('connector_key', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        // Los indicadores públicos (tenant_id NULL) también deben tener código único.
        DB::statement('CREATE UNIQUE INDEX external_indicators_public_code_unique ON external_indicators (code) WHERE tenant_id IS NULL');

        Schema::create('external_indicator_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained('external_indicators')->cascadeOnDelete();
            $table->date('period_date')->comment('Inicio del período observado');
            $table->decimal('value', 20, 6);
            $table->timestampTz('published_at')->comment('Fecha real de publicación por la fuente');
            $table->timestampTz('ingested_at')->useCurrent()->comment('Fecha de incorporación a Predictily');
            $table->unsignedSmallInteger('revision')->default(0);
            $table->string('source_url', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['indicator_id', 'period_date', 'revision']);
            $table->index(['indicator_id', 'published_at']); // consultas "as-of"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_indicator_observations');
        Schema::dropIfExists('external_indicators');
    }
};
