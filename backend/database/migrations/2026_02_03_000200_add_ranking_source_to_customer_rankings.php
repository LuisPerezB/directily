<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P2 (ADR-P2-01, regla `cust-ranking-v1`): fuente del ranking servido de productos probables, decidida en el holdout
 * entre la probabilidad del modelo servido, la popularidad y la recencia. Cada fila del ranking declara su fuente y el puntaje usado.
 * Aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_product_rankings', function (Blueprint $table) {
            $table->string('ranking_source', 12)->default('model')->comment('model (probabilidad servida), popularity (compradores distintos 90 d), recency (compra más reciente)');
            $table->decimal('ranking_score', 16, 6)->nullable()->comment('Puntaje con el que se ordenó (recencia: −días desde la última compra)');
        });
    }

    public function down(): void
    {
        Schema::table('customer_product_rankings', fn (Blueprint $table) => $table->dropColumn(['ranking_source', 'ranking_score']));
    }
};
