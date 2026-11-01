<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `product_aliases` (ADR-V25-24): código de origen → identidad canónica de producto, con vigencia.
 * `kind`: alias, code_change (se crea con la API de cambio de código), merge y split (DISEÑADOS: no se crean con la API).
 * `data_source_id` NULL = cualquier fuente. Una fila nunca reescribe historia: redirige la resolución del código.
 */
class ProductAlias extends Model
{
    use BelongsToTenant;

    public const KINDS = ['alias', 'code_change', 'merge', 'split'];

    /** Tipos que la API puede crear hoy. merge y split están DISEÑADOS (docs/data/BRONZE_SILVER_GOLD.md), no implementados. */
    public const CREATABLE_KINDS = ['alias'];

    protected $table = 'product_aliases';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'data_source_id' => $this->data_source_id,
            'source_code' => $this->source_code,
            'kind' => $this->kind,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
