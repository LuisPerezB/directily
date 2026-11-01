<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W4 MLOps P0 (arquitecto; ADR-V25-07, -08, -09 y -19).
 *
 * - Ciclo de vida del registro (ADR-V25-07): estados candidate → validated → approved → production → retired, más
 *   rejected y el legado failed (CHECK). La aprobación es SEPARADA: `approved_by` debe ser un usuario con la habilidad
 *   models.approve distinto de quien entrenó o publicó (`published_by`); lo verifica el servicio y lo prueba el paquete.
 *   Se registran propósito, granularidad, modelo anterior, decisión de política aplicada y motivo de rechazo.
 * - Publicación (ADR-V25-08 / -19): `published_object_key` (clave generada por el servidor con prefijo de la empresa en el
 *   almacén de objetos), entorno y fecha de publicación.
 * - Auditoría: `model_registry_events` (una fila por transición: de, a, actor, tipo de actor, motivo y evidencia).
 * - Costo por empresa (ADR-V25-09, §14): `tenant_usage_records` mide el consumo con parámetros (segundos de CPU de
 *   entrenamiento e inferencia, bytes almacenados, llamadas de inferencia, ejecuciones) para el modelo de costos; sin
 *   precios: los precios son parámetros del modelo de costos, nunca valores inventados. Es distinta de `tenant_usage`
 *   (etapa 1: contadores por período para los límites del plan, PlanLimitService), que no se toca.
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    private const STATUSES = ['candidate', 'validated', 'approved', 'production', 'retired', 'rejected', 'failed'];

    public function up(): void
    {
        Schema::table('model_registry', function (Blueprint $table) {
            $table->string('purpose', 120)->nullable()->comment('Para qué se usa el modelo (p. ej. demanda diaria por serie)');
            $table->string('granularity', 40)->nullable()->comment('series_day, customer_sku, ...');
            $table->unsignedBigInteger('previous_model_registry_id')->nullable()->comment('Modelo vigente al que reemplaza');
            $table->unsignedBigInteger('promotion_decision_id')->nullable()->comment('Decisión de política que lo propuso (W3)');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_object_key', 500)->nullable()->comment('Clave del artefacto en el almacén de objetos (servidor)');
            $table->string('published_env', 20)->nullable()->comment('local, staging, production');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreign(['tenant_id', 'previous_model_registry_id'])->references(['tenant_id', 'id'])->on('model_registry')->nullOnDelete();
            $table->foreign(['tenant_id', 'promotion_decision_id'])->references(['tenant_id', 'id'])->on('model_promotion_decisions')->nullOnDelete();
        });
        DB::statement("ALTER TABLE model_registry ADD CONSTRAINT model_registry_status_check CHECK (status IN ('".implode("', '", self::STATUSES)."'))");

        Schema::create('model_registry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('model_registry_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_kind', 20)->comment('user, system, cli');
            $table->text('reason')->nullable();
            $table->jsonb('evidence')->default('{}')->comment('Métricas, verificación de firma, decisión de política, entorno');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'model_registry_id', 'created_at']);
            $table->foreign(['tenant_id', 'model_registry_id'])->references(['tenant_id', 'id'])->on('model_registry')->cascadeOnDelete();
        });

        Schema::create('tenant_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period')->comment('Día de consumo (zona de la empresa)');
            $table->string('metric', 40)->comment('train_cpu_seconds, inference_cpu_seconds, inference_calls, storage_bytes, runs, ...');
            $table->decimal('quantity', 20, 4);
            $table->string('unit', 20);
            $table->string('source', 40)->comment('forecast_run, core_run, bronze, artifacts, ...');
            $table->string('source_ref', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'period', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_records');
        Schema::dropIfExists('model_registry_events');
        DB::statement('ALTER TABLE model_registry DROP CONSTRAINT IF EXISTS model_registry_status_check');
        Schema::table('model_registry', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'previous_model_registry_id']);
            $table->dropForeign(['tenant_id', 'promotion_decision_id']);
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['purpose', 'granularity', 'previous_model_registry_id', 'promotion_decision_id', 'published_object_key',
                'published_env', 'published_at', 'approved_at', 'rejected_reason']);
        });
    }
};
