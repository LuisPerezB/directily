<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versiones históricas de las promociones (auditoría 2026-10-28, hallazgo F5).
 *
 * Solo inserción: una fila por alta, modificación de un campo versionado, baja o
 * restauración (App\Services\Promotions\PromotionRevisionRecorder). El motor reconstruye
 * con ella la promoción tal como se conocía en cada corte (regla as-of).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedInteger('revision')->comment('1 = alta; +1 en cada cambio');
            $table->string('name', 150);
            $table->string('promo_type', 20)->comment('percentage, fixed_amount, bundle, other');
            $table->decimal('discount_pct', 6, 3)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = todas las sucursales');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestampTz('known_at')->comment('known_at de la promoción en esta versión');
            $table->boolean('deleted')->default(false)->comment('true = versión que registra la baja');
            $table->string('source', 20)->default('api')->comment('api, import, migration');
            $table->timestampTz('recorded_at')->useCurrent()->comment('Momento en que Predictily registró esta versión');
            $table->unique(['tenant_id', 'promotion_id', 'revision']);
            $table->foreign(['tenant_id', 'promotion_id'])->references(['tenant_id', 'id'])->on('promotions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('product_categories')->cascadeOnDelete();
        });

        // Backfill: una revisión inicial con el estado actual, registrada en su última escritura.
        DB::statement(<<<'SQL'
            INSERT INTO promotion_revisions (tenant_id, promotion_id, revision, name, promo_type, discount_pct, starts_on,
                ends_on, branch_id, product_id, category_id, known_at, deleted, source, recorded_at)
            SELECT tenant_id, id, 1, name, promo_type, discount_pct, starts_on, ends_on, branch_id, product_id,
                   category_id, known_at, deleted_at IS NOT NULL, 'migration', COALESCE(updated_at, created_at, now())
            FROM promotions
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_revisions');
    }
};
