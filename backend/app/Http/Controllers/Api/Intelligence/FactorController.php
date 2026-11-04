<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\FactorDefinition;
use App\Services\Intelligence\FactorCatalogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de factores y su activación por empresa (ADR-V25-25). El catálogo público es de solo lectura para las empresas; la activación
 * (`tenants.settings.factor_activation`) es una elección de la empresa y no cambia nada del motor si su pipeline de factores está apagado.
 */
final class FactorController extends IntelligenceController
{
    public function __construct(private readonly FactorCatalogService $catalog, private readonly TenantContext $context) {}

    /** GET /intelligence/factors — data.view */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'family' => ['sometimes', Rule::in(FactorDefinition::FAMILIES)],
            'availability_class' => ['sometimes', Rule::in(FactorDefinition::AVAILABILITY_CLASSES)],
            'state' => ['sometimes', Rule::in(['inactive', 'activated_by_company', 'active'])],
            'is_informative_alert' => ['sometimes', 'boolean'],
        ]);
        $items = $this->catalog->list($this->context->require(), $filters);

        return response()->json([
            'data' => $items->all(),
            'meta' => [
                'total' => $items->count(),
                'families' => $items->pluck('family')->unique()->count(),
                'catalog_active' => $items->where('catalog_is_active', true)->count(),
                'company_activated' => $items->where('company_activation', true)->count(),
                'pipeline_note' => FactorCatalogService::PIPELINE_NOTE,
                'external_integrations' => 'Ninguna integración externa está activa: las definiciones de series y eventos externos son contrato, no datos.',
            ],
        ]);
    }

    /** PUT /intelligence/factors/{key}/activation — settings.manage (propietario o administrador) */
    public function activation(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $definition = $this->catalog->find($key);
        abort_if($definition === null, 404, 'Factor no encontrado.');
        $tenant = $this->context->require();

        return response()->json(['data' => $this->catalog->setActivation($tenant, $request->user(), $definition, (bool) $data['active']), 'meta' => ['pipeline_note' => FactorCatalogService::PIPELINE_NOTE]]);
    }
}
