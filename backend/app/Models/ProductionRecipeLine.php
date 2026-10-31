<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `production_recipe_lines`. Insumo consumido por lote de una receta.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class ProductionRecipeLine extends Model
{
    use BelongsToTenant;

    protected $table = 'production_recipe_lines';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity_per_batch' => 'float'];
    }

    public function inputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'input_product_id');
    }
}
