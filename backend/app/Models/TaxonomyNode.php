<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPublic;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nodo de la taxonomía versionada Sector → Industria → Categoría → Subcategoría (V2.1 §24.2; ADR-V25-25). `tenant_id` NULL = taxonomía pública
 * compartida (lectura). El SKU canónico del producto NUNCA se sustituye por un nodo: el nodo solo agrega contexto.
 */
class TaxonomyNode extends Model
{
    use BelongsToTenantOrPublic;

    /** Niveles en orden jerárquico: el padre de un nodo es siempre del nivel inmediatamente superior. */
    public const LEVELS = ['sector', 'industry', 'category', 'subcategory'];

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        // La FK del padre no es compuesta: el modelo impide enlazar con un nodo que la empresa no ve (de otra empresa) o de otro nivel.
        static::saving(function (self $node): void {
            if ($node->parent_id === null) {
                if ($node->level !== self::LEVELS[0]) {
                    throw new IntelligenceRuleException('Solo un nodo de nivel «sector» puede no tener padre.', 'taxonomy_parent_required');
                }

                return;
            }
            // Con empresa en contexto solo ve lo propio y lo público; el propietario de la plataforma (sin contexto) ve todo.
            $parent = app(TenantContext::class)->has() ? self::query()->find($node->parent_id) : self::withoutTenantScope()->find($node->parent_id);
            if ($parent === null) {
                throw new IntelligenceRuleException('El nodo padre no existe o no es visible para la empresa.', 'taxonomy_parent_not_found');
            }
            $expected = self::childLevelOf($parent->level);
            if ($expected !== $node->level) {
                throw new IntelligenceRuleException("Jerarquía inválida: el padre es de nivel «{$parent->level}» y un hijo suyo debe ser de nivel «".($expected ?? 'ninguno').'».', 'taxonomy_invalid_hierarchy');
            }
            // `saving` corre antes de `creating`: un nodo nuevo todavía no tiene tenant_id, que se tomará de la empresa en contexto.
            $ownerTenant = $node->tenant_id ?? app(TenantContext::class)->id();
            if ($parent->tenant_id !== null && $ownerTenant !== null && (int) $parent->tenant_id !== (int) $ownerTenant) {
                throw new IntelligenceRuleException('El nodo padre pertenece a otra empresa.', 'taxonomy_parent_not_found');
            }
            if ($parent->tenant_id !== null && $ownerTenant === null) {
                throw new IntelligenceRuleException('Un nodo público no puede colgar de un nodo privado de una empresa.', 'taxonomy_invalid_hierarchy');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'attributes' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'erp_category_id');
    }

    /** Nivel que corresponde a los hijos de un nodo de este nivel (null en el último). */
    public static function childLevelOf(string $level): ?string
    {
        $i = array_search($level, self::LEVELS, true);

        return $i === false ? null : (self::LEVELS[$i + 1] ?? null);
    }
}
