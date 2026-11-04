<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\StoreApiTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Tokens\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tokens propios (V2.5 · W6-A; ADR-V25-15): listar, emitir con alcance mínimo y caducidad, y revocar (efecto inmediato). El token en claro se muestra UNA vez al emitirlo;
 * nunca se lista. La emisión exige un segundo factor fresco a quien tiene MFA (`mfa.step_up`, en la ruta). Solo se operan tokens PROPIOS: el id de un token ajeno responde 404.
 */
final class ApiTokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    /** GET /auth/tokens */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken && $current->exists ? (int) $current->getKey() : null;
        $tenants = Tenant::query()->whereIn('id', $this->tokens->listFor($user)->pluck('tenant_id')->filter()->unique())->pluck('uuid', 'id');

        return response()->json(['data' => $this->tokens->listFor($user)->map(fn (PersonalAccessToken $t): array => $this->present($t, $currentId, $tenants->all()))->values()]);
    }

    /** POST /auth/tokens */
    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $tenant = Tenant::query()->where('uuid', $request->string('tenant')->toString())->first();
        // 404 (no 403): no se revela qué empresas existen; solo se emiten tokens para empresas de las que se es miembro.
        if ($tenant === null || $this->tokens->abilitiesOf($user, $tenant) === null) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }
        $caller = $user->currentAccessToken();
        $new = $this->tokens->create($user, $tenant, $request->string('name')->toString(), (array) $request->input('abilities'), $request->filled('expires_in_days') ? (int) $request->input('expires_in_days') : null, $caller instanceof PersonalAccessToken ? $caller : null);

        return response()->json(['data' => [
            'token' => $new->plainTextToken,
            'token_type' => 'Bearer',
            'token_note' => 'Guárdelo ahora: no se vuelve a mostrar.',
            'id' => $new->accessToken->getKey(),
            'name' => substr((string) $new->accessToken->name, strlen('user:')),
            'tenant' => $tenant->uuid,
            'abilities' => $new->accessToken->abilities,
            'expires_at' => $new->accessToken->expires_at?->toIso8601String(),
        ]], 201);
    }

    /** DELETE /auth/tokens/{id} */
    public function destroy(Request $request, int $id): Response
    {
        if (! $this->tokens->revokeOwn($this->user($request), $id)) {
            throw new NotFoundHttpException('Token no encontrado.');
        }

        return response()->noContent();
    }

    /** POST /auth/tokens/revoke-others: revoca los demás tokens propios (los de conector solo con `include_connectors`). */
    public function revokeOthers(Request $request): JsonResponse
    {
        $data = $request->validate(['include_connectors' => ['sometimes', 'boolean']]);
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $count = $this->tokens->revokeOthers($user, $current instanceof PersonalAccessToken && $current->exists ? (int) $current->getKey() : null, (bool) ($data['include_connectors'] ?? false));

        return response()->json(['data' => ['revoked' => $count]]);
    }

    /**
     * @param  array<int, string>  $tenants
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $t, ?int $currentId, array $tenants): array
    {
        $kind = TokenService::kindOf((string) $t->name);

        return [
            'id' => $t->getKey(),
            'kind' => $kind,
            'name' => $kind === 'personal' ? substr((string) $t->name, strlen('user:')) : (string) $t->name,
            'tenant' => $t->tenant_id !== null ? ($tenants[(int) $t->tenant_id] ?? null) : null,
            'abilities' => $t->abilities,
            'current' => $currentId !== null && (int) $t->getKey() === $currentId,
            'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
