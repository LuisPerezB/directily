<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Requests\Core\BranchRequest;
use App\Http\Resources\Core\BranchResource;
use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Services\Plans\PlanLimitService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class BranchController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitService $plans,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Branch::query()->orderBy('code');
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return BranchResource::collection($query->get());
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $this->plans->assertCan($this->context->require(), 'max_branches');
        $branch = Branch::query()->create($request->validated());
        $this->audit->log('branch.created', $branch, ['code' => $branch->code]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        $data = $request->validated();
        if (($data['is_active'] ?? false) && ! $branch->is_active) {
            $this->plans->assertCan($this->context->require(), 'max_branches');
        }
        $branch->fill($data)->save();
        $this->audit->log('branch.updated', $branch, ['fields' => array_keys($data)]);

        return new BranchResource($branch);
    }

    /** Si la sucursal tiene ventas se desactiva (se conserva la historia); si no, se elimina. */
    public function destroy(Branch $branch): JsonResponse
    {
        $hasSales = DB::table('sales')->where('tenant_id', $branch->tenant_id)->where('branch_id', $branch->id)->exists();
        if ($hasSales) {
            $branch->forceFill(['is_active' => false])->save();
            $this->audit->log('branch.deactivated', $branch);

            return response()->json(['data' => ['deleted' => false, 'deactivated' => true]]);
        }
        $branch->delete();
        $this->audit->log('branch.deleted', $branch);

        return response()->json(['data' => ['deleted' => true, 'deactivated' => false]]);
    }
}
