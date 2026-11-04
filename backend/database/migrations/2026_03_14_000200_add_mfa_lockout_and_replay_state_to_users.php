<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6-A (ADR-V25-15) · estado del segundo factor que el contrato 2026_03_14_000100 no trae.
 *
 * El contrato fija el secreto cifrado, la fecha de activación y los hashes de recuperación. El BLOQUEO por fuerza bruta y la defensa contra la REPETICIÓN de un código
 * (RFC 6238 §5.2: un código no se acepta dos veces) necesitan estado compartido y persistente: en la caché se perdería con un vaciado o entre nodos, y un bloqueo que
 * se evapora no es un bloqueo. Por eso van en `users`, y se actualizan con una sola sentencia atómica (varias peticiones paralelas no esquivan el contador).
 * Aditivo y reversible; no se modifica ninguna migración anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('mfa_failed_attempts')->default(0)->comment('Intentos fallidos consecutivos de segundo factor');
            $table->timestampTz('mfa_locked_until')->nullable()->comment('Bloqueo por fuerza bruta: no se acepta ningún código hasta esta fecha');
            $table->unsignedBigInteger('mfa_last_used_step')->nullable()->comment('Último paso TOTP aceptado (contador T de RFC 6238); evita repetir un código');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_failed_attempts', 'mfa_locked_until', 'mfa_last_used_step']);
        });
    }
};
