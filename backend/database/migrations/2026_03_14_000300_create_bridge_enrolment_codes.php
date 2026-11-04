<?php

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6-B · Predictily Bridge (ADR-V25-10): códigos de registro de un solo uso.
 *
 * El contrato del arquitecto (2026_03_14_000100) crea `bridge_devices` y `bridge_checkpoints`, pero no dice cómo se autoriza el ALTA de un
 * dispositivo. Un administrador de la empresa genera un código de registro ligado a UNA fuente de datos; el agente lo canjea una sola
 * vez junto con su clave pública y recibe su credencial. Esta tabla guarda solo el SHA-256 del código (el código se muestra una vez),
 * su vencimiento y su consumo; es una credencial, por lo que NO se exporta (TenantDataExporter::NOT_EXPORTED).
 * Aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_enrolment_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_source_id');
            $table->char('code_hash', 64)->comment('SHA-256 del código de registro; el código nunca se guarda');
            $table->string('device_name_hint', 120)->nullable()->comment('Nombre sugerido para el dispositivo (informativo)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_by_device_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['code_hash']);
            $table->index(['tenant_id', 'data_source_id', 'created_at']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'consumed_by_device_id'])->references(['tenant_id', 'id'])->on('bridge_devices')->nullOnDelete();
        });
        // FKs compuestas (tenant_id, x_id) ON DELETE SET NULL: anular solo la referencia, nunca tenant_id (2026_03_12_000400).
        CompositeForeignKeys::setNullOnlyReferences();
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_enrolment_codes');
    }
};
