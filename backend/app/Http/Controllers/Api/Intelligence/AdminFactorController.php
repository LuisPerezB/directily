<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\FactorDefinition;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Services\Intelligence\FactorCatalogService;
use App\Services\Intelligence\SharedGrants\SharedForecastGrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escritura del catálogo público de factores: SOLO el propietario de la plataforma (superadministrador, rutas /admin, sin empresa en contexto).
 * Las empresas leen el catálogo por /intelligence/factors y jamás lo escriben (el modelo lo impide además de la ruta).
 */
final class AdminFactorController extends IntelligenceController
{
    public function __construct(private readonly FactorCatalogService $catalog) {}

    /** GET /admin/intelligence/factors */
    public function index(): JsonResponse
    {
        $items = FactorDefinition::publicCatalog()->orderBy('family')->orderBy('key')->get()
            ->map(fn (FactorDefinition $d) => $this->catalog->present($d, []))->values();

        return response()->json(['data' => $items->all(), 'meta' => ['total' => $items->count()]]);
    }

    /** POST /admin/intelligence/factors */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family' => ['required', Rule::in(FactorDefinition::FAMILIES)],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/'],
        ] + $this->rules(true));
        $this->assertConsistent($data);

        return response()->json(['data' => $this->catalog->presentPublic($this->catalog->createPublic($data))], 201);
    }

    /** PATCH /admin/intelligence/factors/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $definition = FactorDefinition::publicCatalog()->where('key', $key)->first();
        abort_if($definition === null, 404, 'Factor no encontrado.');
        $data = $request->validate($this->rules(false));
        $this->assertConsistent($data + $definition->only(['storage', 'source_key', 'external_indicator_id']) + ['is_active' => $definition->is_active]);

        return response()->json(['data' => $this->catalog->presentPublic($this->catalog->updatePublic($definition, $data))]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:200'],
            'availability_class' => [$req, Rule::in(FactorDefinition::AVAILABILITY_CLASSES)],
            'storage' => [$req, Rule::in(FactorDefinition::STORAGES)],
            // La definición es pública: solo puede apuntar a un indicador público (tenant_id NULL), nunca a uno privado de una empresa.
            'external_indicator_id' => ['sometimes', 'nullable', 'integer', Rule::exists('external_indicators', 'id')->whereNull('tenant_id')],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:15'],
            'geo_scope' => ['sometimes', 'nullable', Rule::in(['country', 'region', 'province', 'branch', 'product'])],
            'source_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'license' => ['sometimes', 'nullable', 'string', 'max:120'],
            'missing_strategy' => ['sometimes', Rule::in(FactorDefinition::MISSING_STRATEGIES)],
            'max_staleness_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:36500'],
            'sector_justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schema' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'is_informative_alert' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reglas de coherencia: un indicador externo solo con almacenamiento de series; y ningún factor sin fuente (externo) ni el de concesiones con
     * la bandera apagada puede declararse activo en el catálogo (no se inventan fuentes activas).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertConsistent(array $data): void
    {
        if (($data['external_indicator_id'] ?? null) !== null && ($data['storage'] ?? null) !== 'external_indicator_observations') {
            throw new IntelligenceRuleException('external_indicator_id solo aplica al almacenamiento external_indicator_observations.', 'indicator_requires_series_storage', 422);
        }
        if (! ($data['is_active'] ?? false)) {
            return;
        }
        $storage = (string) ($data['storage'] ?? '');
        if (in_array($storage, ['external_indicator_observations', 'external_events'], true) && empty($data['source_key'])) {
            throw new IntelligenceRuleException('No se puede activar en el catálogo un factor externo sin fuente (source_key): hoy no hay integraciones externas activas.', 'factor_without_source', 422);
        }
        if ($storage === 'shared_forecast_grants' && ! SharedForecastGrantService::isEnabled()) {
            throw new IntelligenceRuleException('Las proyecciones compartidas dependen de una función desactivada por configuración.', 'factor_feature_disabled', 422);
        }
    }
}
