<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación producto → nodo de taxonomía con procedencia (V2.1 §24.2; ADR-V25-25). Una propuesta `llm_proposed` es candidata con procedencia y
 * revisión, NUNCA verdad oficial: solo `manual` (la asignó un usuario) y `verified` (propuesta confirmada por un usuario) son usables.
 * El SKU canónico no se sustituye: el producto conserva su identificador.
 */
class ProductTaxonomyAssignment extends Model
{
    use BelongsToTenant;

    public const PROVENANCE_MANUAL = 'manual';

    public const PROVENANCE_LLM_PROPOSED = 'llm_proposed';

    public const PROVENANCE_VERIFIED = 'verified';

    public const PROVENANCES = [self::PROVENANCE_MANUAL, self::PROVENANCE_LLM_PROPOSED, self::PROVENANCE_VERIFIED];

    /** Procedencias que cuentan como verdad para el motor y las pantallas. */
    public const USABLE = [self::PROVENANCE_MANUAL, self::PROVENANCE_VERIFIED];

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        // La FK del nodo no es compuesta: el modelo impide apuntar a un nodo que la empresa no ve (de otra empresa).
        static::saving(function (self $assignment): void {
            if ($assignment->isDirty('taxonomy_node_id') && ! TaxonomyNode::query()->whereKey($assignment->taxonomy_node_id)->exists()) {
                throw new IntelligenceRuleException('El nodo de taxonomía no existe o no es visible para la empresa.', 'taxonomy_node_not_found');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'taxonomy_node_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->provenance, self::USABLE, true);
    }

    /** Solo asignaciones que cuentan como verdad (excluye las propuestas del LLM pendientes de revisión). */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.provenance', self::USABLE);
    }

    /** Vigentes en la fecha dada (valid_from/valid_to nulos = sin límite). */
    public function scopeValidOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();
        $t = $this->getTable();

        return $query->where(fn (Builder $q) => $q->whereNull("{$t}.valid_from")->orWhere("{$t}.valid_from", '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull("{$t}.valid_to")->orWhere("{$t}.valid_to", '>=', $day));
    }
}
