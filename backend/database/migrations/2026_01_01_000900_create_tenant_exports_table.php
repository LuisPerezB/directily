<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones completas de datos de una empresa (derecho de acceso/portabilidad, LOPDP).
 * El archivo ZIP vive en el disco privado y se elimina tras la retención configurada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued')->comment('queued, running, completed, failed, expired');
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->jsonb('summary')->nullable()->comment('Filas exportadas por tabla');
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
    }
};
