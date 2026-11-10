<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-V26-01 · F1: estado de las etapas del ciclo diario por empresa y día de negocio.
 *
 * Hasta ahora las etapas de la madrugada (predicción de clientes → radar → decisiones) dependían solo de la hora: si la anterior no
 * había terminado, la siguiente trabajaba con los datos del día anterior y terminaba «bien». Esta tabla deja constancia de qué pasó
 * con cada etapa de cada empresa cada día, y de por qué una etapa no se ejecutó:
 *
 * - waiting   la etapa de la que depende sigue en curso; se lanzará sola cuando termine.
 * - running   en ejecución.
 * - succeeded terminó (con `reason` si lo hizo con salvedades, p. ej. `partial_errors` o `no_prediction_today`).
 * - failed    falló; `reason` lleva un código estable (el detalle queda en el registro de la aplicación).
 * - blocked   no se ejecutó porque la etapa de la que depende (`blocked_by`) falló hoy.
 * - skipped   no aplica a la empresa hoy (p. ej. su plan no incluye el módulo).
 *
 * Una fila por (empresa, día, etapa): repetir una etapa actualiza su fila. Aditiva y reversible.
 */
return new class extends Migration
{
    private const STATUSES = ['waiting', 'running', 'succeeded', 'failed', 'blocked', 'skipped'];

    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('business_date')->comment('Día de negocio (zona horaria de la empresa)');
            $table->string('stage', 40)->comment('customer_predict, radar, decisions, ...');
            $table->string('status', 20);
            $table->string('blocked_by', 40)->nullable()->comment('Etapa de la que depende y que impide ejecutar esta');
            $table->string('reason', 80)->nullable()->comment('Código estable; nunca el texto de una excepción');
            $table->string('source_ref', 80)->nullable()->comment('Origen de los datos usados, p. ej. core_run:123');
            $table->jsonb('detail')->nullable()->comment('Conteos de la etapa; sin datos de clientes');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'business_date', 'stage']);
            $table->index(['business_date', 'status']);
        });
        DB::statement("ALTER TABLE pipeline_stages ADD CONSTRAINT pipeline_stages_status_check CHECK (status IN ('".implode("', '", self::STATUSES)."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
