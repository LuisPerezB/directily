<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Plans\PlanLimitService;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administración de la plataforma (superadministrador). Expone metadatos de uso y plan,
 * NUNCA ventas, clientes ni pronósticos de las empresas (privacidad desde el diseño).
 */
final class AdminController extends Controller
{
    public function __construct(private readonly PlanLimitService $plans, private readonly AuditLogger $audit) {}

    public function tenants(Request $request): JsonResponse
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Tenant::query()->with('plan:id,code,name')->withCount('users')->orderBy('name');
        if ($search = $request->input('search')) {
            $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        $page = $query->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Tenant $t) => $this->present($t)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function updateTenant(Request $request, string $uuid): JsonResponse
    {
        $tenant = Tenant::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'plan_code' => ['sometimes', Rule::exists('plans', 'code')],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            ...$this->overrideRules(),
        ]);
        if (isset($data['plan_code'])) {
            $tenant->plan_id = Plan::query()->where('code', $data['plan_code'])->value('id');
        }
        if (array_key_exists('status', $data)) {
            $tenant->status = $data['status'];
        }
        if (array_key_exists('plan_overrides', $data)) {
            $tenant->plan_overrides = $data['plan_overrides'];
        }
        $tenant->save();
        $this->audit->log('admin.tenant_updated', $tenant, array_keys($data) === [] ? [] : ['fields' => array_keys($data)], $tenant->id);

        return response()->json(['data' => $this->present($tenant->load('plan:id,code,name')->loadCount('users'), withSeries: true)]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->orderBy('id')->get()->map(fn (Plan $p) => [
            'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'limits' => $p->limits,
            'monthly_price_usd' => $p->monthly_price_usd, 'metadata' => $p->metadata, 'is_active' => $p->is_active,
        ])]);
    }

    /**
     * Reglas de `plan_overrides` (límites por contrato, ADR-V25-18). Solo las claves de `predictily.plan_limit_keys`; los topes numéricos son enteros ≥ 0
     * (null = sin tope); `horizons` ⊆ catálogo único; `features` = banderas booleanas. Un precio no es una clave válida: nunca es un límite.
     *
     * @return array<string, mixed>
     */
    private function overrideRules(): array
    {
        $keys = array_merge((array) config('predictily.plan_limit_keys.enforced'), (array) config('predictily.plan_limit_keys.declared'));
        $rules = [
            'plan_overrides' => ['sometimes', 'nullable', 'array:'.implode(',', $keys)],
            'plan_overrides.horizons' => ['sometimes', 'array'],
            'plan_overrides.horizons.*' => ['integer', Rule::in(HorizonCatalog::all())],
            // Mapa capacidad → booleano. `{}`/`[]` = sin cambios (PlanLimitService::mergeLimits); una lista con elementos no es un mapa.
            'plan_overrides.features' => ['sometimes', 'array', fn (string $attribute, mixed $value, \Closure $fail) => is_array($value) && $value !== [] && array_is_list($value)
                ? $fail('Las capacidades deben ser un mapa {capacidad: true|false}.') : null],
            'plan_overrides.features.*' => ['boolean'],
            'plan_overrides.modules' => ['sometimes', 'array'],
            'plan_overrides.modules.*' => ['string', 'max:60'],
            'plan_overrides.frequencies' => ['sometimes', 'array'],
            'plan_overrides.frequencies.*' => ['string', 'max:30'],
            'plan_overrides.connectors' => ['sometimes', 'array'],
            'plan_overrides.connectors.*' => ['string', 'max:60'],
            'plan_overrides.retention_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:240'],
            'plan_overrides.compute_budget' => ['sometimes', 'nullable', 'array'],
            'plan_overrides.llm_budget' => ['sometimes', 'nullable', 'array'],
        ];
        foreach (['max_branches', 'max_series', 'max_active_products', 'max_import_rows_per_month', 'max_forecast_runs_per_day', 'max_users'] as $limit) {
            $rules["plan_overrides.{$limit}"] = ['sometimes', 'nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    /** Consumo agregado del mes por empresa (métricas de plataforma). */
    public function usage(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['sometimes', 'regex:/^\d{4}-\d{2}$/']])['period'] ?? now('America/Guayaquil')->format('Y-m');
        $rows = DB::table('tenant_usage as u')->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->where('u.period', $period)->orderBy('t.name')
            ->get(['t.uuid', 't.name', 'u.metric', 'u.value']);

        return response()->json(['data' => [
            'period' => $period,
            'tenants' => $rows->groupBy('uuid')->map(fn ($items) => [
                'uuid' => $items->first()->uuid,
                'name' => $items->first()->name,
                'metrics' => $items->pluck('value', 'metric')->map(fn ($v) => (int) $v),
            ])->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(Tenant $t, bool $withSeries = false): array
    {
        return [
            'uuid' => $t->uuid, 'name' => $t->name, 'sector' => $t->sector, 'status' => $t->status,
            'plan' => $t->plan ? ['code' => $t->plan->code, 'name' => $t->plan->name] : null,
            'plan_overrides' => $t->plan_overrides, 'users_count' => (int) ($t->users_count ?? 0),
            // El listado recorre hasta 100 empresas: no mide series activas (consulta sobre ventas). El detalle (tras editar una empresa) sí, con caché.
            'usage' => $this->plans->snapshot($t, $withSeries),
            'created_at' => $t->created_at?->toIso8601String(), 'deletion_requested_at' => $t->deletion_requested_at?->toIso8601String(),
        ];
    }
}
