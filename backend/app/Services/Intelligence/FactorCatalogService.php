<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\FactorDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de factores y activación por empresa (ADR-V25-16 y ADR-V25-25).
 *
 * - Lectura: definiciones públicas (tenant_id NULL) + las propias de la empresa. Escritura del catálogo público: solo como plataforma (PlatformWrite).
 * - Activación: la elección de la empresa vive en `tenants.settings.factor_activation.<clave>` y NO modifica la definición del catálogo. Es una
 *   intención registrada: el motor solo la aplica si el pipeline de factores de la empresa está habilitado (interruptor gobernado por el motor,
 *   apagado por defecto). Este servicio no consulta ni cambia ese interruptor.
 */
final class FactorCatalogService
{
    public const ACTIVATION_SETTING = 'factor_activation';

    public const PIPELINE_NOTE = 'La activación solo registra la elección de la empresa. El motor la aplica únicamente si el pipeline de factores de la empresa está habilitado (interruptor gobernado por el motor, apagado por defecto); con el pipeline apagado ningún factor externo entra al modelo.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{family?: string, availability_class?: string, state?: string, is_informative_alert?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(Tenant $tenant, array $filters = []): Collection
    {
        $query = FactorDefinition::query()->orderBy('family')->orderBy('key');
        foreach (['family', 'availability_class'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (array_key_exists('is_informative_alert', $filters) && $filters['is_informative_alert'] !== null) {
            $query->where('is_informative_alert', (bool) $filters['is_informative_alert']);
        }
        $activation = $this->activationMap($tenant);

        return $query->get()
            ->map(fn (FactorDefinition $d) => $this->present($d, $activation))
            ->when(! empty($filters['state']), fn (Collection $c) => $c->where('state', $filters['state']))
            ->values();
    }

    /** Definición visible para la empresa (propia o pública). Si hay una propia con la misma clave, prevalece sobre la pública. */
    public function find(string $key): ?FactorDefinition
    {
        return FactorDefinition::query()->where('key', $key)->orderByRaw('tenant_id IS NULL')->first();
    }

    /** @return array<string, mixed> */
    public function presentFor(Tenant $tenant, FactorDefinition $definition): array
    {
        return $this->present($definition, $this->activationMap($tenant));
    }

    /** Presentación para el propietario de la plataforma: la definición pública sin elección de ninguna empresa. @return array<string, mixed> */
    public function presentPublic(FactorDefinition $definition): array
    {
        return $this->present($definition, []);
    }

    /** @return array<string, mixed> elección de la empresa por clave de factor */
    public function activationMap(Tenant $tenant): array
    {
        return (array) (((array) ($tenant->settings ?? []))[self::ACTIVATION_SETTING] ?? []);
    }

    /**
     * Registra la activación o desactivación de un factor para la empresa. Idempotente: repetir el mismo estado no cambia nada salvo la marca de tiempo.
     *
     * @return array<string, mixed> la definición presentada con su estado
     */
    public function setActivation(Tenant $tenant, User $user, FactorDefinition $definition, bool $active): array
    {
        DB::transaction(function () use ($tenant, $user, $definition, $active): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $settings = (array) ($locked->settings ?? []);
            $map = (array) ($settings[self::ACTIVATION_SETTING] ?? []);
            $previous = (bool) ($map[$definition->key]['active'] ?? false);
            $map[$definition->key] = ['active' => $active, 'updated_by' => $user->id, 'updated_at' => now()->toIso8601String()];
            $settings[self::ACTIVATION_SETTING] = $map;
            $locked->settings = $settings;
            $locked->save();
            $tenant->settings = $settings;
            $this->audit->log('intelligence.factor_activation_changed', $locked, [
                'factor_key' => $definition->key, 'family' => $definition->family, 'previous' => $previous, 'active' => $active,
                'catalog_is_active' => $definition->is_active, 'engine_effect' => 'none_until_pipeline_enabled',
            ]);
        });

        return $this->presentFor($tenant, $definition);
    }

    /**
     * Estado mostrado: `inactive` (nadie lo activó), `activated_by_company` (la empresa lo eligió pero el catálogo no lo habilita: sin fuente o sin
     * aprobación de la plataforma), `active` (habilitado por la plataforma y elegido por la empresa).
     *
     * @param  array<string, mixed>  $activation
     * @return array<string, mixed>
     */
    public function present(FactorDefinition $d, array $activation): array
    {
        $choice = $activation[$d->key] ?? null;
        $companyActive = (bool) ($choice['active'] ?? false);
        $state = match (true) {
            $companyActive && $d->is_active => 'active',
            $companyActive => 'activated_by_company',
            default => 'inactive',
        };

        return [
            'key' => $d->key,
            'family' => $d->family,
            'name' => $d->name,
            'scope' => $d->isPublic() ? 'public' : 'tenant',
            'availability_class' => $d->availability_class,
            'storage' => $d->storage,
            'data_location' => $this->dataLocation($d),
            'external_indicator_id' => $d->external_indicator_id,
            'unit' => $d->unit,
            'frequency' => $d->frequency,
            'geo_scope' => $d->geo_scope,
            'source_key' => $d->source_key,
            'license' => $d->license,
            'missing_strategy' => $d->missing_strategy,
            'max_staleness_days' => $d->max_staleness_days,
            'retention_days' => $d->retention_days,
            'sector_justification' => $d->sector_justification,
            'schema' => (object) ($d->schema ?? []),
            'is_informative_alert' => $d->is_informative_alert,
            'catalog_is_active' => $d->is_active,
            'company_activation' => $companyActive,
            'state' => $state,
            'activation_updated_at' => $choice['updated_at'] ?? null,
            'engine_effect' => 'none_until_pipeline_enabled',
        ];
    }

    /**
     * Dónde vive el dato de la familia, sin segundo almacén de features: tabla nativa, serie externa, eventos o contrato de concesiones.
     *
     * @return array{kind: string, table: string, status: string, note: string}
     */
    public function dataLocation(FactorDefinition $d): array
    {
        $status = (string) (((array) ($d->schema ?? []))['data_status'] ?? 'unknown');

        return match ($d->storage) {
            'external_indicator_observations' => [
                'kind' => 'external_series', 'table' => 'external_indicator_observations', 'status' => $status,
                'note' => 'Serie externa en external_indicator_observations (fechas de observación, publicación y disponibilidad). Hoy no hay fuente activa: no existen datos hasta que se apruebe e integre una (P2).',
            ],
            'external_events' => [
                'kind' => 'external_events', 'table' => 'external_events', 'status' => $status,
                'note' => 'Eventos y alertas con procedencia y estado PROPOSED/VERIFIED/REJECTED/EXPIRED. Hoy solo se registran manualmente; no hay conectores ni extracción automática.',
            ],
            'shared_forecast_grants' => [
                'kind' => 'contract', 'table' => 'shared_forecast_grants', 'status' => $status,
                'note' => 'Contrato de pronósticos compartidos entre empresas: DISEÑADO y DESACTIVADO por bandera de configuración. El pronóstico permanece en la empresa emisora.',
            ],
            default => [
                'kind' => 'native', 'table' => $d->storage, 'status' => $status,
                'note' => 'Dato nativo de Predictily: no se duplica en un segundo almacén de features.',
            ],
        };
    }

    // ── Escritura del catálogo público: solo el propietario de la plataforma (rutas /admin, sin empresa en contexto) ──

    /** @param array<string, mixed> $data */
    public function createPublic(array $data): FactorDefinition
    {
        return PlatformWrite::run(function () use ($data): FactorDefinition {
            if (FactorDefinition::publicCatalog()->where('key', $data['key'])->exists()) {
                throw new IntelligenceRuleException('Ya existe una definición pública con esa clave.', 'factor_key_taken', 409);
            }
            $definition = FactorDefinition::query()->create($data + ['is_active' => false]);
            $this->audit->log('intelligence.factor_definition_created', $definition, ['key' => $definition->key, 'family' => $definition->family], null);

            return $definition->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePublic(FactorDefinition $definition, array $data): FactorDefinition
    {
        return PlatformWrite::run(function () use ($definition, $data): FactorDefinition {
            $before = ['is_active' => $definition->is_active, 'availability_class' => $definition->availability_class];
            $definition->fill($data)->save();
            $this->audit->log('intelligence.factor_definition_updated', $definition, ['key' => $definition->key, 'changed' => array_keys($data), 'before' => $before], null);

            return $definition->refresh();
        });
    }
}
