<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-E (ADR-V25-18): metadatos informativos de los planes comerciales configurables (Starter, Growth, Enterprise).
 *
 * Los precios de referencia (Starter 49, Growth 119, rangos Enterprise) y el origen de cada plan son DATOS INFORMATIVOS: viven aquí y en
 * `monthly_price_usd`, nunca en `limits`. Ningún precio es un límite ni condiciona una validación. Columna nullable: los planes heredados
 * (trial, básico, profesional, empresarial) no la usan. `plans` es un catálogo global (sin tenant_id): no entra en la exportación por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable()->after('limits')
                ->comment('Metadatos informativos (precios de referencia, origen, hipótesis); nunca límites');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
