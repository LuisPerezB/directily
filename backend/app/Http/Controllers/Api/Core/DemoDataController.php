<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Jobs\Imports\LoadDemoData;
use App\Services\Audit\AuditLogger;
use App\Services\Demo\DemoDataService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** POST /tenant/demo-data — carga datos FICTICIOS en la empresa activa (solo si no tiene ventas). */
final class DemoDataController extends Controller
{
    public function store(Request $request, TenantContext $context, DemoDataService $demo, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['dataset' => ['sometimes', Rule::in(array_keys((array) config('predictily.demo.tenants')))]]);
        $slug = $data['dataset'] ?? 'ferreteria_andina';
        $tenant = $context->require();
        if (DB::table('sales')->where('tenant_id', $tenant->id)->exists()) {
            return response()->json(['message' => 'La empresa ya tiene ventas: los datos de demostración solo se cargan en empresas vacías.', 'code' => 'tenant_not_empty'], 409);
        }
        if (! $demo->available($slug)) {
            return response()->json(['message' => 'Los datos de demostración no están disponibles en este servidor.', 'code' => 'demo_unavailable'], 503);
        }
        $audit->log('tenant.demo_data_requested', $tenant, ['dataset' => $slug]);
        LoadDemoData::dispatch($tenant->id, $slug, $request->user()->id)->onQueue('imports');

        return response()->json(['data' => [
            'dataset' => $slug,
            'status' => 'queued',
            'notice' => 'Datos ficticios de demostración: no representan a ninguna empresa real.',
            'import_job_ids' => DB::table('import_jobs')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id'),
        ]], 202);
    }
}
