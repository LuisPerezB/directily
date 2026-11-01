<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\TenantSectorProfile;
use App\Services\Intelligence\SectorProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Perfiles de relevancia sectorial de la empresa, con prioridad y alcance (V2.1 §24.2; ADR-V25-25). Una empresa puede tener varios. */
final class SectorProfileController extends IntelligenceController
{
    public function __construct(private readonly SectorProfileService $profiles) {}

    /** GET /intelligence/sector-profiles — data.view */
    public function index(): JsonResponse
    {
        $items = TenantSectorProfile::query()->orderBy('priority')->orderBy('id')->get()->map(fn (TenantSectorProfile $p) => $this->profiles->present($p));

        return response()->json(['data' => $items->values()->all(), 'meta' => ['total' => $items->count()]]);
    }

    /** POST /intelligence/sector-profiles — settings.manage */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'scope' => ['sometimes', Rule::in(TenantSectorProfile::SCOPES)],
            'scope_ref' => ['sometimes', 'nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->profiles->present($this->profiles->create($data))], 201);
    }

    /** PATCH /intelligence/sector-profiles/{profile} — settings.manage (prioridad y estado) */
    public function update(Request $request, TenantSectorProfile $profile): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->profiles->present($this->profiles->update($profile, $data))]);
    }

    /** DELETE /intelligence/sector-profiles/{profile} — settings.manage */
    public function destroy(TenantSectorProfile $profile): JsonResponse
    {
        $this->profiles->delete($profile);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
