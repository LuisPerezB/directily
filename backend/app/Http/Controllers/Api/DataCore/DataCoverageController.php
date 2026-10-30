<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Models\DataCoverage;
use App\Services\DataCore\Coverage\CoverageService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cobertura (completitud) de cada fuente: GET para todos con acceso a datos; PUT declara (imports.manage). */
final class DataCoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function index(): JsonResponse
    {
        $rows = DataCoverage::query()->orderBy('entity')->orderBy('data_source_id')->orderBy('branch_id')->get();
        $effective = [];
        foreach (CoverageService::ENTITIES as $entity) {
            $effective[$entity] = $this->coverage->effective($entity);
        }

        return response()->json(['data' => $rows->map(fn (DataCoverage $r) => CoverageService::resource($r)), 'effective' => $effective]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(CoverageService::ENTITIES)],
            'complete_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'complete_through' => ['required', 'date_format:Y-m-d'],
            'data_source_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('data_sources')],
            'branch_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('branches')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $row = $this->coverage->declare(
            $data['entity'], $data['complete_from'] ?? null, $data['complete_through'], $data['data_source_id'] ?? null, $data['branch_id'] ?? null, $data['notes'] ?? null,
        );

        return response()->json(['data' => CoverageService::resource($row->refresh()), 'effective' => $this->coverage->effective($data['entity'], $data['branch_id'] ?? null)]);
    }
}
