<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z_.]+$/'],
            'user_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        // audit_logs no usa global scope (tenant_id puede ser NULL): se filtra explícitamente.
        $query = AuditLog::query()->where('tenant_id', $context->require()->id)->with('user:id,name')->orderByDesc('id');
        if (isset($filters['action'])) {
            $query->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $filters['action']).'%');
        }
        if (isset($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
                'auditable_type' => $l->auditable_type,
                'auditable_id' => $l->auditable_id,
                'ip_address' => $l->ip_address,
                'metadata' => $l->metadata ?? (object) [],
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
