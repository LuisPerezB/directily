<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Plans\PlanLimitService;
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
            'plan_overrides' => ['sometimes', 'nullable', 'array'],
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

        return response()->json(['data' => $this->present($tenant->load('plan:id,code,name')->loadCount('users'))]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->orderBy('id')->get()->map(fn (Plan $p) => [
            'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'limits' => $p->limits,
            'monthly_price_usd' => $p->monthly_price_usd, 'is_active' => $p->is_active,
        ])]);
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
    private function present(Tenant $t): array
    {
        return [
            'uuid' => $t->uuid, 'name' => $t->name, 'sector' => $t->sector, 'status' => $t->status,
            'plan' => $t->plan ? ['code' => $t->plan->code, 'name' => $t->plan->name] : null,
            'plan_overrides' => $t->plan_overrides, 'users_count' => (int) ($t->users_count ?? 0),
            'usage' => $this->plans->snapshot($t),
            'created_at' => $t->created_at?->toIso8601String(), 'deletion_requested_at' => $t->deletion_requested_at?->toIso8601String(),
        ];
    }
}
