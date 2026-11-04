<?php

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6-B · Predictily Bridge: dos tablas de apoyo a la revisión adversarial (ADR-V25-10).
 *
 * - `bridge_request_nonces` (B-5): memoria ANTI-REPETICIÓN de las peticiones firmadas del dispositivo. Vive en la base de datos (clave primaria
 *   dispositivo + nonce, `INSERT … ON CONFLICT DO NOTHING`), no en la caché: sobrevive a un vaciado de la caché y a un almacén por proceso (array/file), y
 *   es atómica entre instancias. Sin `tenant_id` (se borra en cascada con el dispositivo); las filas vencidas se podan de forma oportunista.
 * - `bridge_run_batches` (B-3): libro de LOTES CONFIRMADOS por extracción (dispositivo, entidad, corrida, posición dentro de la corrida). Permite que el
 *   servidor compruebe, al completar la corrida, que sus lotes son contiguos, no hay huecos ni lotes fallidos antes de mover la marca de agua.
 * Aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_request_nonces', function (Blueprint $table) {
            $table->unsignedBigInteger('bridge_device_id');
            $table->string('nonce', 64);
            $table->timestampTz('expires_at');
            $table->primary(['bridge_device_id', 'nonce']);
            $table->index('expires_at');
            $table->foreign('bridge_device_id')->references('id')->on('bridge_devices')->cascadeOnDelete();
        });

        Schema::create('bridge_run_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bridge_device_id');
            $table->string('entity', 30);
            $table->string('run_id', 40);
            $table->unsignedInteger('batch_index')->comment('Posición (1, 2, …) del lote dentro de la corrida');
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'bridge_device_id', 'entity', 'run_id', 'batch_index'], 'bridge_run_batches_position_unique');
            $table->foreign(['tenant_id', 'bridge_device_id'])->references(['tenant_id', 'id'])->on('bridge_devices')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
        });
        // FKs compuestas (tenant_id, x_id) ON DELETE SET NULL: anular solo la referencia, nunca tenant_id (2026_03_12_000400).
        CompositeForeignKeys::setNullOnlyReferences();
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_run_batches');
        Schema::dropIfExists('bridge_request_nonces');
    }
};
