<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado de una ablation: baseline vs candidato con holdout intacto y decisión promote | reject | inconclusive (V2.1 §24.7; ADR-V25-25).
 * Sin API ni cálculo en W2-F: la ablation sectorial es P2 (DISEÑADO). Solo existe el contrato de almacenamiento, aislado por empresa.
 */
class FactorEvaluation extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const DECISIONS = ['promote', 'reject', 'inconclusive'];

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        // Una promoción exige un holdout intacto (regla crítica V2.1 §24.7): no se puede guardar «promote» con el holdout usado.
        static::saving(function (self $evaluation): void {
            if ($evaluation->decision === 'promote' && ! $evaluation->holdout_untouched) {
                throw new IntelligenceRuleException('No se puede promover un factor si el holdout final se usó para elegir variables o hiperparámetros.', 'holdout_not_untouched');
            }
            if ($evaluation->factor_definition_id !== null && $evaluation->isDirty('factor_definition_id') && ! FactorDefinition::query()->whereKey($evaluation->factor_definition_id)->exists()) {
                throw new IntelligenceRuleException('La definición de factor no existe o no es visible para la empresa.', 'factor_definition_not_found');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'factor_set' => 'array',
            'horizon_days' => 'integer',
            'cutoff' => 'date:Y-m-d',
            'baseline_metrics' => 'array',
            'candidate_metrics' => 'array',
            'holdout_untouched' => 'boolean',
            'cost' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FactorDefinition::class, 'factor_definition_id');
    }
}
