<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Services\DataCore\Quality\ExtendedQualityReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /data-quality/extended — reporte de calidad ampliado del core de Datos (calculado bajo demanda). */
final class DataQualityController extends Controller
{
    public function __construct(private readonly ExtendedQualityReport $report, private readonly TenantContext $context) {}

    public function extended(Request $request): JsonResponse
    {
        $request->validate(['persist' => ['sometimes', 'boolean']]);
        $tenant = $this->context->require();
        $report = $this->report->build($tenant);
        if ($request->boolean('persist')) {
            DB::table('data_quality_reports')->insert([
                'tenant_id' => $tenant->id, 'scope' => 'data_core', 'score' => null,
                'summary' => json_encode(['overall_status' => $report['overall_status'], 'sections' => array_map(fn ($s) => $s['status'], $report['sections'])]),
                'issues' => json_encode($report['sections']), 'created_at' => now(),
            ]);
        }

        return response()->json(['data' => $report]);
    }
}
