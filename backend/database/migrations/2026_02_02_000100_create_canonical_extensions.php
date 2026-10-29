<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · Contrato canónico común (arquitecto). Lo consumen los paquetes P2 (compromisos),
 * P3 (conectores, calendarios, unidades, almacenes, mapeos) y P4 (costos, unidades).
 *
 * Tres nociones de fecha (instrucctions.md §6.C): fecha efectiva del evento, fecha en que la fuente
 * conocía la información (`known_at`/`source_recorded_at`) y fecha de ingestión (`created_at`).
 * Una carga histórica actual NO demuestra que algo se conocía antes: `known_at` NULL = desconocido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->decimal('storage_capacity', 16, 4)->nullable();
            $table->string('capacity_unit', 20)->nullable()->comment('Unidad física de la capacidad (m3, pallets, kg...)');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        // Conversiones explícitas: nunca se convierte en silencio. product_id NULL = conversión general.
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('from_unit', 20);
            $table->string('to_unit', 20);
            $table->decimal('factor', 20, 10)->comment('cantidad_to = cantidad_from × factor');
            $table->date('valid_from')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX unit_conversions_natural_key ON unit_conversions (tenant_id, COALESCE(product_id, 0), from_unit, to_unit, COALESCE(valid_from, DATE \'1900-01-01\'))');

        // Intención comercial (quote), compromiso (order/contract) y su cumplimiento: no son ventas realizadas.
        Schema::create('commercial_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->string('commitment_type', 20)->comment('quote, order, contract');
            $table->string('external_id', 100);
            $table->string('status', 25)->comment('open, confirmed, partially_fulfilled, fulfilled, cancelled, expired');
            $table->timestampTz('issued_at');
            $table->timestampTz('known_at')->nullable()->comment('Cuándo la fuente conocía esta versión; NULL = desconocido');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->char('source_hash', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'commitment_type', 'external_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'expected_delivery_date']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
        });

        Schema::create('commercial_commitment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('commitment_id');
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 14, 4);
            $table->decimal('quantity_fulfilled', 14, 4)->default(0);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->date('delivery_date')->nullable();
            $table->timestampsTz();
            $table->unique(['commitment_id', 'line_number']);
            $table->index(['tenant_id', 'product_id', 'delivery_date']);
            $table->foreign(['tenant_id', 'commitment_id'])->references(['tenant_id', 'id'])->on('commercial_commitments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        // Calendario unificado con alcance y estado de conocimiento. tenant_id NULL = calendario público.
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20)->comment('country, region, tenant, branch, category, product, sector');
            $table->string('scope_ref', 80)->nullable()->comment('EC, sierra, branch:3, product:45, sector:ferreteria...');
            $table->string('code', 80);
            $table->string('name', 200);
            $table->string('category', 30)->comment('holiday, local_holiday, commercial_season, campaign, promotion, sector_event, agricultural_season, international_peak, exceptional');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('confirmed')->comment('planned, confirmed, cancelled');
            $table->timestampTz('known_at')->nullable()->comment('Desde cuándo se conocía el evento; NULL = desconocido (no usar a futuro en backtests)');
            $table->string('source', 50)->default('manual');
            $table->unsignedInteger('revision')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });
        DB::statement('CREATE UNIQUE INDEX calendar_events_natural_key ON calendar_events (COALESCE(tenant_id, 0), scope, COALESCE(scope_ref, \'\'), code, starts_on, revision)');

        // Consultas/vistas autorizadas por fuente SQL (solo lectura). Versionadas y aprobadas.
        Schema::create('source_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity', 30)->comment('sales, products, customers, inventory, commitments, costs, branches, suppliers');
            $table->text('query')->comment('Una sola sentencia SELECT o una vista autorizada');
            $table->jsonb('field_map')->default('{}')->comment('{campo_predictily: columna_origen}');
            $table->jsonb('options')->default('{}');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'data_source_id', 'entity', 'version']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
        });

        Schema::table('purchase_cost_history', function (Blueprint $table) {
            $table->timestampTz('known_at')->nullable()->comment('Cuándo se conocía el costo (factura/lista recibida); NULL = desconocido');
            $table->string('unit', 20)->nullable();
        });
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->timestampTz('known_at')->nullable();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->timestampTz('source_recorded_at')->nullable()->comment('Cuándo la fuente registró/modificó el documento (si la provee)');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_generic')->default(false)->comment('Código genérico (p. ej. consumidor final): no representa un comprador recurrente');
        });
        DB::statement("UPDATE customers SET is_generic = true WHERE customer_type = 'final_consumer' OR external_code IN ('9999999999999', '9999999999')");
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('is_generic'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('source_recorded_at'));
        Schema::table('product_price_history', fn (Blueprint $t) => $t->dropColumn('known_at'));
        Schema::table('purchase_cost_history', fn (Blueprint $t) => $t->dropColumn(['known_at', 'unit']));
        Schema::dropIfExists('source_mappings');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('commercial_commitment_items');
        Schema::dropIfExists('commercial_commitments');
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('warehouses');
    }
};
