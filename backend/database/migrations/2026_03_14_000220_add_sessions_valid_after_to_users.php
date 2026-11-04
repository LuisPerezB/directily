<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6-A (ADR-V25-15, revisión) · revocación de sesiones web.
 *
 * Los tokens de API se revocan borrando su fila; una SESIÓN de la SPA (cookie) vive en el almacén de sesiones (base de datos o Redis) y no se puede listar por persona. Para poder
 * cerrar «las demás sesiones» de una persona al activar o desactivar su segundo factor o al restablecérselo la plataforma —con cualquier controlador de sesión—, cada sesión
 * guarda el instante en que se emitió (`auth_issued_at`, milisegundos) y la persona guarda desde cuándo valen (`sessions_valid_after`): una sesión emitida antes de ese instante se
 * rechaza (middleware `AuthenticateSessionWithRevocation`). Aditivo y reversible; no modifica ninguna migración anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('sessions_valid_after')->nullable()->comment('Milisegundos Unix: las sesiones web emitidas antes de este instante ya no valen');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sessions_valid_after');
        });
    }
};
