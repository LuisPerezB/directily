<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPublic;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qué factor aplica (o no) a qué perfil/categoría/región (ADR-V25-25). `tenant_id` NULL = regla del catálogo público. Sin API en W2-F: solo el contrato. */
class FactorApplicabilityRule extends Model
{
    use BelongsToTenantOrPublic;

    public const EFFECTS = ['applicable', 'not_applicable'];

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        // Las FK a la definición y al nodo no son compuestas: el modelo impide referenciar lo que la empresa no ve (definiciones o nodos de otra empresa).
        static::saving(function (self $rule): void {
            $scoped = app(TenantContext::class)->has();
            $definition = $scoped ? FactorDefinition::query() : FactorDefinition::withoutTenantScope();
            if ($rule->isDirty('factor_definition_id') && ! $definition->whereKey($rule->factor_definition_id)->exists()) {
                throw new IntelligenceRuleException('La definición de factor no existe o no es visible para la empresa.', 'factor_definition_not_found');
            }
            $node = $scoped ? TaxonomyNode::query() : TaxonomyNode::withoutTenantScope();
            if ($rule->taxonomy_node_id !== null && $rule->isDirty('taxonomy_node_id') && ! $node->whereKey($rule->taxonomy_node_id)->exists()) {
                throw new IntelligenceRuleException('El nodo de taxonomía no existe o no es visible para la empresa.', 'taxonomy_node_not_found');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FactorDefinition::class, 'factor_definition_id');
    }
}
