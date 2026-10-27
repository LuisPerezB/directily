<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario, movimientos, órdenes de compra y plazos de proveedores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->date('snapshot_date')->comment('Existencias al cierre del día');
            $table->decimal('on_hand', 14, 4);
            $table->decimal('reserved', 14, 4)->default(0);
            $table->decimal('in_transit', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'branch_id', 'product_id', 'snapshot_date'], 'inventory_snapshots_natural_key');
            // "último inventario conocido" y detección de días sin stock.
            $table->index(['tenant_id', 'snapshot_date']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->nullOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->string('movement_type', 20)->comment('purchase_receipt, sale, return, adjustment, transfer_in, transfer_out');
            $table->decimal('quantity', 14, 4)->comment('Con signo: + entrada, - salida');
            $table->timestampTz('occurred_at');
            $table->string('reference', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id', 'occurred_at']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('branch_id');
            $table->string('external_id', 100)->nullable();
            $table->string('status', 20)->default('ordered')->comment('draft, ordered, partially_received, received, cancelled');
            $table->date('ordered_at');
            $table->date('expected_at')->nullable();
            $table->date('received_at')->nullable();
            $table->decimal('total', 14, 4)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->nullOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity_ordered', 14, 4);
            $table->decimal('quantity_received', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'purchase_order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        // Resolución del plazo: producto+sucursal > producto > proveedor > tenants.settings.default_lead_time_days
        Schema::create('supplier_lead_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedSmallInteger('lead_time_days');
            $table->decimal('lead_time_std_days', 6, 2)->nullable();
            $table->string('source', 20)->default('manual')->comment('manual, import, observed');
            $table->date('valid_from')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::table('purchase_cost_history', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'purchase_order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_cost_history', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'purchase_order_id']);
        });
        Schema::dropIfExists('supplier_lead_times');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_snapshots');
    }
};
