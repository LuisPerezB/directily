<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Commercial\CommercialScope as AuthScope;
use App\Services\Conversation\Gateway\Scope\GatewayScope;
use Illuminate\Http\Request;
use LogicException;

/**
 * Quién consulta: empresa activa, usuario, rol, token (si lo hay), canal y alcance comercial. TODO sale de la petición autenticada: el modelo nunca aporta empresa, usuario,
 * rol, habilidades ni alcance. Las habilidades se evalúan igual que en EnsureTenantAbility. El alcance comercial lo fija el gateway tras resolverlo (withScope).
 */
final class ToolContext
{
    public const CHANNELS = ['web', 'api', 'whatsapp'];

    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?User $user,
        public readonly ?TenantRole $role,
        public readonly ?PersonalAccessToken $token = null,
        public readonly string $channel = 'web',
        public readonly ?GatewayScope $scope = null,
    ) {}

    public static function fromRequest(Request $request, Tenant $tenant): self
    {
        $token = $request->user()?->currentAccessToken();
        $real = $token instanceof PersonalAccessToken && $token->exists ? $token : null;
        // El alcance comercial ya lo resolvió ResolveTenant (W6-A) en el servidor; si no está, el gateway lo resuelve con el usuario de la petición.
        $commercial = $request->attributes->get('commercial_scope');

        return new self(
            $tenant,
            $request->user(),
            $request->attributes->get('tenant_role'),
            $real,
            $real !== null ? 'api' : 'web',
            $commercial instanceof AuthScope ? GatewayScope::from($commercial, 'request') : null,
        );
    }

    /** Mismo criterio que el middleware tenant.ability: el rol debe permitirla y, con token real, el token también. */
    public function can(string $ability): bool
    {
        $roleAllows = $this->role !== null && $this->role->can($ability);
        $tokenAllows = $this->token === null || $this->token->can($ability);

        return $roleAllows && $tokenAllows;
    }

    public function withScope(GatewayScope $scope): self
    {
        return new self($this->tenant, $this->user, $this->role, $this->token, $this->channel, $scope);
    }

    public function withChannel(string $channel): self
    {
        return new self($this->tenant, $this->user, $this->role, $this->token, $channel, $this->scope);
    }

    /** Alcance comercial ya resuelto por el gateway; sin él, una herramienta por cliente NO debe ejecutarse (cierra en falso). */
    public function requireScope(): GatewayScope
    {
        return $this->scope ?? throw new LogicException('El alcance comercial no se resolvió: la herramienta debe ejecutarse a través del gateway.');
    }
}
