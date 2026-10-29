<?php

declare(strict_types=1);

namespace App\Services\DataCore\Coverage;

use App\Models\DataCoverage;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Completitud de cada fuente (ADR-V2-05): hasta qué fecha el dato es COMPLETO. Fuera de la cobertura un día sin
 * registros es AUSENTE, no cero.
 *
 * Quién declara:
 *  - el usuario (PUT /data-coverage): basis = `declared`; manda sobre cualquier inferencia;
 *  - el conector tras cada sincronización con la regla documentada en recordConnectorSync(): basis = `inferred`.
 */
final class CoverageService
{
    public const ENTITIES = ['sales', 'inventory', 'purchases', 'costs', 'promotions', 'customers', 'commitments', 'calendar', 'externals'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /**
     * Declaración del usuario. Upsert por (entidad, fuente, sucursal).
     *
     * @throws ValidationException
     */
    public function declare(string $entity, ?string $from, ?string $through, ?int $dataSourceId, ?int $branchId, ?string $notes): DataCoverage
    {
        $tenant = $this->context->require();
        $today = CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil')->toDateString();
        $errors = [];
        if ($through === null) {
            $errors['complete_through'][] = 'Indique hasta qué fecha está completa la fuente.';
        } elseif ($through > $today) {
            $errors['complete_through'][] = 'La fecha de completitud no puede ser futura.';
        }
        if ($from !== null && $through !== null && $from > $through) {
            $errors['complete_from'][] = 'La fecha inicial no puede ser posterior a la final.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $row = DataCoverage::query()->where('entity', $entity)->where('data_source_id', $dataSourceId)->where('branch_id', $branchId)->first() ?? new DataCoverage;
        $row->fill(['entity' => $entity, 'data_source_id' => $dataSourceId, 'branch_id' => $branchId])->forceFill([
            'complete_from' => $from, 'complete_through' => $through, 'basis' => 'declared', 'notes' => $notes,
        ])->save();
        $this->audit->log('data_coverage.declared', $row, ['entity' => $entity, 'complete_from' => $from, 'complete_through' => $through, 'data_source_id' => $dataSourceId, 'branch_id' => $branchId]);

        return $row;
    }

    /**
     * Regla del conector (documentada en docs/architecture/v2/P3-datos.md §6):
     *  1. solo si la sincronización terminó (`completed`) con CERO filas con error;
     *  2. solo si el mapeo no desactiva la cobertura (`options.coverage.enabled`);
     *  3. complete_through = día local de inicio de la sincronización − `lag_days` (por defecto 1: «completo hasta ayer»);
     *     ajuste `lag_days` al rezago real de replicación si la fuente es un almacén alimentado por ETL;
     *  4. nunca retrocede; nunca pisa una declaración del usuario para la misma fuente (basis `declared`);
     *  5. complete_from: en carga completa, la menor fecha de evento extraída (antes no hay nada en la fuente);
     *     una incremental no lo modifica.
     */
    public function recordConnectorSync(DataSource $source, string $entity, CarbonImmutable $startedAt, string $mode, bool $clean, ?string $minEventDate, array $mappingOptions): ?DataCoverage
    {
        $tenant = $this->context->require();
        $row = DataCoverage::query()->where('entity', $entity)->where('data_source_id', $source->id)->whereNull('branch_id')->first();
        $coverage = (array) ($mappingOptions['coverage'] ?? []);
        $eligible = $clean && ($coverage['enabled'] ?? true) !== false && $entity !== 'calendar_events';
        if ($row !== null && $row->basis === 'declared') {
            $row->forceFill(['last_ingested_at' => now()])->save();

            return $row;
        }
        $row ??= new DataCoverage(['entity' => $entity, 'data_source_id' => $source->id]);
        $row->last_ingested_at = now();
        if ($eligible) {
            $lag = max(0, (int) ($coverage['lag_days'] ?? 1));
            $through = $startedAt->setTimezone($tenant->timezone ?: 'America/Guayaquil')->subDays($lag)->toDateString();
            if ($row->complete_through === null || $through > $row->complete_through->toDateString()) {
                $row->complete_through = $through;
            }
            if ($mode === 'full' && $minEventDate !== null && ($row->complete_from === null || $minEventDate < $row->complete_from->toDateString())) {
                $row->complete_from = $minEventDate;
            }
            $row->basis = 'inferred';
            $row->notes = 'Inferida por el conector: sincronización sin errores; completa hasta el inicio de la sincronización menos '.$lag.' día(s).';
        }
        $row->save();

        return $row;
    }

    /**
     * Cobertura efectiva de una entidad (y sucursal opcional). Precedencia:
     *  1. declaración del usuario (basis `declared`) sin fuente, de la sucursal pedida o de toda la empresa;
     *  2. fuentes: complete_through = MÍNIMO entre fuentes y complete_from = MÁXIMO (todas deben ser completas);
     *  3. sin información: sin cobertura (el dato fuera de ella es ausente).
     *
     * @return array{entity: string, complete_from: ?string, complete_through: ?string, rule: string, basis: ?string, sources: list<int>}
     */
    public function effective(string $entity, ?int $branchId = null): array
    {
        $rows = DataCoverage::query()->where('entity', $entity)->get();
        $declared = $rows->filter(fn (DataCoverage $r) => $r->data_source_id === null && $r->basis === 'declared' && ($r->branch_id === null || $r->branch_id === $branchId))
            ->sortByDesc(fn (DataCoverage $r) => $r->branch_id === null ? 0 : 1)->first();
        if ($declared !== null) {
            return $this->present($entity, $declared->complete_from?->toDateString(), $declared->complete_through?->toDateString(), 'declared_by_user', 'declared', [$declared->id]);
        }
        $sources = $rows->filter(fn (DataCoverage $r) => $r->data_source_id !== null && $r->complete_through !== null && ($r->branch_id === null || $r->branch_id === $branchId));
        if ($sources->isNotEmpty()) {
            $through = $sources->min(fn (DataCoverage $r) => $r->complete_through->toDateString());
            $fromValues = $sources->map(fn (DataCoverage $r) => $r->complete_from?->toDateString())->filter();
            $basis = $sources->every(fn (DataCoverage $r) => $r->basis === 'declared') ? 'declared' : 'inferred';

            return $this->present($entity, $fromValues->isEmpty() ? null : $fromValues->max(), $through, 'min_over_sources', $basis, $sources->pluck('id')->all());
        }

        return $this->present($entity, null, null, 'none', null, []);
    }

    /** @param list<int> $ids */
    private function present(string $entity, ?string $from, ?string $through, string $rule, ?string $basis, array $ids): array
    {
        return ['entity' => $entity, 'complete_from' => $from, 'complete_through' => $through, 'rule' => $rule, 'basis' => $basis, 'sources' => $ids];
    }

    public static function resource(DataCoverage $row): array
    {
        return [
            'id' => $row->id,
            'entity' => $row->entity,
            'data_source_id' => $row->data_source_id,
            'branch_id' => $row->branch_id,
            'complete_from' => $row->complete_from?->toDateString(),
            'complete_through' => $row->complete_through?->toDateString(),
            'basis' => $row->basis,
            'last_ingested_at' => $row->last_ingested_at?->toIso8601String(),
            'notes' => $row->notes,
        ];
    }

    public function tenant(): Tenant
    {
        return $this->context->require();
    }
}
