<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6 Plataforma (arquitecto; ADR-V25-10, -15 y -17; §4, §13, §15).
 *
 * - Seguridad Enterprise (ADR-V25-15): MFA TOTP para usuarios privilegiados (`users.mfa_*`: secreto cifrado con la clave
 *   de la aplicación, códigos de recuperación con hash) y rol comercial por empresa (`tenant_users.commercial_role`:
 *   gerente, vendedor, servicio) que se suma al rol de acceso vigente (owner/admin/analyst/viewer) sin reemplazarlo; los
 *   tokens ya tienen alcance y caducidad (Sanctum `abilities`, `expires_at`).
 * - Predictily Bridge (ADR-V25-10): `bridge_devices` (registro de dispositivo con huella de clave pública, token con hash,
 *   última conexión, revocación) y `bridge_checkpoints` (marca de agua y checkpoint por fuente y entidad).
 * - Auto Mapper (§4): `mapping_templates` versionadas por sistema de origen y entidad (propuesta determinista con
 *   confianza heurística, aprobación y huella del esquema al que aplica).
 * - Intelligence Gateway (ADR-V25-17): `gateway_tool_calls` audita cada llamada a herramienta tipada (autorización por
 *   herramienta, argumentos minimizados, resultado y bloqueo por política o por inyección de instrucciones).
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret_encrypted')->nullable()->comment('Secreto TOTP cifrado (APP_KEY)');
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->jsonb('mfa_recovery_codes')->nullable()->comment('Hashes de los códigos de recuperación (nunca en claro)');
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('commercial_role', 20)->nullable()->comment('gerente, vendedor, servicio (V2.5 §15); NULL = sin rol comercial');
            $table->jsonb('commercial_scope')->default('{}')->comment('Zonas, segmentos o clientes asignados (vendedor)');
        });

        Schema::create('bridge_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_source_id');
            $table->string('name', 120);
            $table->char('public_key_fingerprint', 64)->comment('SHA-256 de la clave pública del dispositivo');
            $table->char('token_hash', 64)->comment('SHA-256 del token del dispositivo; el token nunca se guarda');
            $table->string('agent_version', 40)->nullable();
            $table->string('platform', 40)->nullable()->comment('windows, linux, macos');
            $table->timestampTz('registered_at')->useCurrent();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['token_hash']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
        });

        Schema::create('bridge_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bridge_device_id');
            $table->string('entity', 30);
            $table->timestampTz('watermark')->nullable()->comment('Acotada al inicio de la extracción (misma regla que SourceSyncService)');
            $table->string('checkpoint', 200)->nullable()->comment('Cursor opaco del adaptador (página, clave, archivo)');
            $table->unsignedBigInteger('bronze_object_id')->nullable()->comment('Último lote recibido');
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['tenant_id', 'bridge_device_id', 'entity']);
            $table->foreign(['tenant_id', 'bridge_device_id'])->references(['tenant_id', 'id'])->on('bridge_devices')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
        });

        Schema::create('mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = plantilla pública de la plataforma');
            $table->string('system_kind', 20)->comment('erp, pos, spreadsheet, ...');
            $table->string('vendor', 80)->nullable();
            $table->string('entity', 30);
            $table->unsignedInteger('version');
            $table->char('schema_fingerprint', 64)->nullable()->comment('Huella del esquema de origen al que aplica');
            $table->jsonb('field_map')->comment('Campo canónico → expresión de origen');
            $table->jsonb('value_maps')->default('{}');
            $table->jsonb('confidence')->default('{}')->comment('Confianza heurística por campo (no probabilidad)');
            $table->string('status', 20)->default('proposed')->comment('proposed, approved, retired');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'system_kind', 'vendor', 'entity', 'version'], 'mapping_templates_version_unique');
        });

        Schema::create('gateway_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->comment('web, whatsapp (contrato), api');
            $table->string('tool', 60);
            $table->string('decision', 20)->comment('allowed, denied_ability, denied_policy, blocked_injection, error');
            $table->jsonb('arguments')->default('{}')->comment('Argumentos minimizados (sin PII)');
            $table->jsonb('result_summary')->default('{}');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_tool_calls');
        Schema::dropIfExists('mapping_templates');
        Schema::dropIfExists('bridge_checkpoints');
        Schema::dropIfExists('bridge_devices');
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn(['commercial_role', 'commercial_scope']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret_encrypted', 'mfa_enabled_at', 'mfa_recovery_codes']);
        });
    }
};
