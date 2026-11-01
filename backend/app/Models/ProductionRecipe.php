<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla `production_recipes`. Receta genérica insumo → producto (cantidades, rendimiento, tiempo, recurso). Sin reglas sectoriales.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class ProductionRecipe extends Model
{
    use BelongsToTenant;

    protected $table = 'production_recipes';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['output_quantity' => 'float', 'yield_rate' => 'float', 'resource_usage_per_batch' => 'float', 'cost_per_batch' => 'float', 'max_batches_per_period' => 'float', 'is_active' => 'boolean'];
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProductionResource::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionRecipeLine::class, 'recipe_id');
    }
}
