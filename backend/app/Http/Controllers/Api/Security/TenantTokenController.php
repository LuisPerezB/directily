<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\Tokens\TokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tokens ligados a la empresa activa, para su administración (V2.5 · W6-A; `users.manage`, sensible: exige segundo factor fresco a quien tiene MFA). Solo los tokens con
 * `tenant_id` = la empresa activa: un id de otra empresa (o un token de inicio de sesión sin empresa) responde 404. La revocación es inmediata.
 */
final class TenantTokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens, private readonly TenantContext $context) {}

    /** GET /tenant/tokens */
    public function index(): JsonResponse
    {
        $tokens = $this->tokens->listForTenant($this->context->require());
        $owners = User::query()->whereIn('id', $tokens->pluck('tokenable_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return response()->json(['data' => $tokens->map(function (PersonalAccessToken $t) use ($owners): array {
            $kind = TokenService::kindOf((string) $t->name);
            $owner = $owners->get($t->tokenable_id);

            return [
                'id' => $t->getKey(),
                'kind' => $kind,
                'name' => $kind === 'personal' ? substr((string) $t->name, strlen('user:')) : (string) $t->name,
                'user' => $owner !== null ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
                'abilities' => $t->abilities,
                'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ];
        })->values()]);
    }

    /** DELETE /tenant/tokens/{id} */
    public function destroy(Request $request, int $id): Response
    {
        if (! $this->tokens->revokeForTenant($this->context->require(), $id, $request->user())) {
            throw new NotFoundHttpException('Token no encontrado.');
        }

        return response()->noContent();
    }
}
