<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos por empresa: sucursales, categorías, productos, clientes, proveedores.
 *
 * Integridad multi-tenant: las tablas referenciadas desde datos transaccionales
 * exponen UNIQUE (tenant_id, id) para permitir llaves foráneas compuestas
 * (tenant_id, x_id) -> x(tenant_id, id). Así la base de datos impide, por sí misma,
 * que una venta de la Empresa A apunte a un producto de la Empresa B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->comment('Código del establecimiento (p. ej. 001 del SRI)');
            $table->string('name', 150);
            $table->string('province', 80)->nullable();
            $table->string('canton', 80)->nullable();
            $table->string('commercial_zone', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->jsonb('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 50)->nullable();
            $table->string('name', 150);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('product_categories')->nullOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('sku', 100);
            $table->string('name', 255);
            $table->string('unit', 20)->default('unidad')->comment('unidad, kg, litro, metro, caja, ...');
            $table->string('brand', 100)->nullable();
            $table->decimal('current_price', 14, 4)->nullable()->comment('Referencia actual; NO usar para reconstruir ventas pasadas');
            $table->decimal('current_cost', 14, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('attributes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'category_id']);
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('product_categories')->nullOnDelete();
        });

        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        // Minimización de datos personales (LOPDP): no se requiere dirección exacta.
        // El nombre se guarda cifrado (cast "encrypted") y la cédula/RUC solo como HMAC.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('external_code', 100)->comment('Código del cliente en el sistema de origen');
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('customer_type', 20)->default('unknown')->comment('person, company, final_consumer, unknown');
            $table->text('name_encrypted')->nullable();
            $table->char('tax_id_hash', 64)->nullable()->comment('HMAC-SHA256 de cédula/RUC, para deduplicar sin almacenar el valor');
            $table->string('province', 80)->nullable();
            $table->string('canton', 80)->nullable();
            $table->string('commercial_zone', 80)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'external_code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'segment_id']);
            $table->foreign(['tenant_id', 'segment_id'])->references(['tenant_id', 'id'])->on('customer_segments')->nullOnDelete();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('tax_id', 20)->nullable();
            $table->char('country', 2)->default('EC');
            $table->unsignedSmallInteger('default_lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_segments');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('branches');
    }
};
