<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas, líneas de venta, promociones e historiales de precio y costo.
 *
 * Reglas clave:
 * - Idempotencia: un documento se identifica por (tenant_id, document_type, document_number).
 *   Reimportar actualiza el documento (si cambió su source_hash) o lo omite; nunca duplica.
 * - sale_items guarda precio, descuento y costo HISTÓRICOS del momento de la venta.
 * - Cantidades siempre positivas; las devoluciones se identifican por is_return / credit_note.
 * - sale_date es la fecha local (zona horaria de la empresa), desnormalizada en sale_items
 *   para agregaciones diarias rápidas por (tenant, sucursal, producto, fecha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('promo_type', 20)->default('percentage')->comment('percentage, fixed_amount, bundle, other');
            $table->decimal('discount_pct', 6, 3)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = todas las sucursales');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('product_categories')->cascadeOnDelete();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable()->comment('Último import que creó/actualizó el documento');
            $table->string('document_type', 20)->default('invoice')->comment('invoice, sales_note, credit_note, other');
            $table->string('document_number', 100)->comment('p. ej. 001-001-000012345');
            $table->string('status', 20)->default('completed')->comment('completed, voided, pending');
            $table->timestampTz('issued_at');
            $table->date('sale_date')->comment('Fecha local de la empresa');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('channel', 30)->nullable()->comment('store, wholesale, phone, web, field_sales, other');
            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal', 14, 4)->default(0)->comment('Suma de importes netos antes de impuestos');
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('tax_total', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);
            $table->unsignedBigInteger('related_sale_id')->nullable()->comment('Nota de crédito -> factura original');
            $table->char('source_hash', 64)->comment('Hash del contenido normalizado; detecta cambios al reimportar');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'document_type', 'document_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'sale_date']);
            $table->index(['tenant_id', 'branch_id', 'sale_date']);
            $table->index(['tenant_id', 'customer_id']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->nullOnDelete();
            $table->foreign(['tenant_id', 'related_sale_id'])->references(['tenant_id', 'id'])->on('sales')->nullOnDelete();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->comment('Desnormalizado desde sales');
            $table->date('sale_date')->comment('Desnormalizado desde sales');
            $table->decimal('quantity', 14, 4)->comment('Siempre positiva');
            $table->decimal('unit_price', 14, 4)->comment('Precio unitario original (antes de descuento) en la fecha de venta');
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('net_amount', 14, 4)->comment('quantity*unit_price - discount_amount, sin impuestos');
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable()->comment('Costo histórico, si el origen lo provee');
            $table->boolean('is_return')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestampsTz();
            $table->unique(['sale_id', 'line_number']);
            // Consulta principal del motor: series diarias por (tenant, sucursal, producto, fecha).
            $table->index(['tenant_id', 'sale_date', 'branch_id', 'product_id'], 'sale_items_tenant_date_branch_product_idx');
            // Detalle/histórico de un producto en el dashboard.
            $table->index(['tenant_id', 'product_id', 'sale_date'], 'sale_items_tenant_product_date_idx');
            $table->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'promotion_id'])->references(['tenant_id', 'id'])->on('promotions')->nullOnDelete();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = precio general');
            $table->decimal('price', 14, 4);
            $table->char('currency', 3)->default('USD');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source', 20)->default('import')->comment('import, manual, observed_sales');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id', 'valid_from']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('purchase_cost_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('unit_cost', 14, 4);
            $table->char('currency', 3)->default('USD');
            $table->date('valid_from');
            $table->unsignedBigInteger('purchase_order_id')->nullable()->comment('Sin FK: purchase_orders se crea después');
            $table->string('source', 20)->default('import')->comment('import, manual, purchase_order');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id', 'valid_from']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_cost_history');
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('promotions');
    }
};
