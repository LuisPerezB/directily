<?php

declare(strict_types=1);

namespace App\Services\Auth\Tokens;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Tokens de API con alcance mínimo y caducidad (V2.5 · W6-A; ADR-V25-15). Sanctum ya valida `abilities` y `expires_at` en CADA petición contra la base de datos; revocar
 * es borrar la fila, de modo que el efecto es inmediato (la siguiente petición con ese token responde 401: no hay caché de tokens).
 *
 * Tipos (se deducen del nombre, sin columnas nuevas): `login` (`api-login`, inicio de sesión de un cliente API), `connector` (`datasource:<id>`, conector de una fuente; lo emite
 * DataSourceController) y `personal` (`user:<etiqueta>`, lo emite la persona aquí). Un token personal nace ATADO a una empresa (`tenant_id`), con habilidades explícitas que
 * no superan las de su rol efectivo en ella, y con caducidad (por defecto `security.tokens.default_ttl_days`; máximo `max_ttl_days`).
 */
final class TokenService
{
    public const MAX_PERSONAL_TOKENS = 25;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function kindOf(string $name): string
    {
        return match (true) {
            $name === 'api-login' => 'login',
            str_starts_with($name, 'datasource:') => 'connector',
            str_starts_with($name, 'user:') => 'personal',
            default => 'other',
        };
    }

    /** @return Collection<int, PersonalAccessToken> los tokens de la persona, el más reciente primero */
    public function listFor(User $user): Collection
    {
        return PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())->orderByDesc('id')->get();
    }

    /**
     * Habilidades de la persona en la empresa (rol efectivo: servicio tiene techo de solo lectura).
     *
     * @return list<string>|null null si no es miembro
     */
    public function abilitiesOf(User $user, Tenant $tenant): ?array
    {
        $membership = $user->membershipIn($tenant);
        $role = $membership !== null ? TenantRole::tryFrom((string) $membership->role) : null;
        if ($role === null) {
            return null;
        }

        [$commercial, $unexpected] = CommercialRole::parseStored($membership->commercial_role);

        return CommercialRole::effectiveAccessRole($role, $commercial, $unexpected)->abilities();
    }

    /**
     * Habilidades pedidas que el token NO puede tener: las que el rol no concede y SIEMPRE el comodín (`*` solo lo tiene el token de inicio de sesión, que no se emite aquí),
     * aunque algún día una lista de habilidades lo incluyera.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function deniedAbilities(array $requested, array $granted): array
    {
        return array_values(array_filter($requested, fn (string $a): bool => $a === '*' || ! in_array($a, $granted, true)));
    }

    /**
     * Emite un token personal.
     *
     * @param  list<string>  $abilities
     *
     * @throws ValidationException habilidades fuera del rol, comodín, exceso de tokens
     */
    public function create(User $user, Tenant $tenant, string $label, array $abilities, ?int $ttlDays, ?PersonalAccessToken $caller = null): NewAccessToken
    {
        $granted = $this->abilitiesOf($user, $tenant) ?? [];
        $abilities = array_values(array_unique($abilities));
        // Un token NUNCA emite otro con más alcance que el suyo ni para otra empresa (defensa en profundidad: las rutas de cuenta ya exigen un inicio de sesión completo).
        if ($caller !== null && $caller->exists) {
            if ($caller->tenant_id !== null && (int) $caller->tenant_id !== (int) $tenant->getKey()) {
                throw new TokenScopeException('Un token ligado a una empresa no puede emitir tokens para otra.');
            }
            $callerAbilities = (array) $caller->abilities;
            if (! in_array('*', $callerAbilities, true) && array_diff($abilities, $callerAbilities) !== []) {
                throw new TokenScopeException('Un token no puede emitir otro con habilidades que él no tiene: '.implode(', ', array_diff($abilities, $callerAbilities)).'.');
            }
        }
        $denied = self::deniedAbilities($abilities, $granted);
        if ($denied !== []) {
            throw ValidationException::withMessages(['abilities' => 'Habilidades no permitidas para su rol en esta empresa: '.implode(', ', $denied).'.']);
        }
        $active = PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())
            ->where('name', 'like', 'user:%')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        if ($active >= self::MAX_PERSONAL_TOKENS) {
            throw ValidationException::withMessages(['name' => 'Alcanzó el máximo de '.self::MAX_PERSONAL_TOKENS.' tokens personales activos: revoque alguno.']);
        }
        $days = max(1, min((int) config('security.tokens.max_ttl_days', 365), $ttlDays ?? (int) config('security.tokens.default_ttl_days', 30)));
        $new = $user->createToken('user:'.trim($label), $abilities, now()->addDays($days));
        $new->accessToken->forceFill(['tenant_id' => $tenant->getKey()])->save();
        $this->audit->log('token.created', null, ['token_id' => $new->accessToken->getKey(), 'abilities' => $abilities, 'expires_in_days' => $days], $tenant->getKey(), $user->getKey());

        return $new;
    }

    /** Revoca un token PROPIO. Devuelve false si no existe o no es de la persona. */
    public function revokeOwn(User $user, int $tokenId): bool
    {
        $token = PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())->whereKey($tokenId)->first();
        if ($token === null) {
            return false;
        }
        $tenantId = $token->tenant_id !== null ? (int) $token->tenant_id : null;
        $token->delete();
        $this->audit->log('token.revoked', null, ['token_id' => $tokenId, 'kind' => self::kindOf((string) $token->name), 'by' => 'owner'], $tenantId, $user->getKey());

        return true;
    }

    /**
     * Revoca todos los demás tokens propios (cierra «las otras sesiones» de clientes API). Los de conector se conservan salvo `$includeConnectors`.
     */
    public function revokeOthers(User $user, ?int $keepTokenId, bool $includeConnectors = false): int
    {
        $query = PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey());
        if ($keepTokenId !== null) {
            $query->whereKeyNot($keepTokenId);
        }
        if (! $includeConnectors) {
            $query->where('name', 'not like', 'datasource:%');
        }
        $count = $query->delete();
        $this->audit->log('token.revoked_others', null, ['count' => $count, 'include_connectors' => $includeConnectors], null, $user->getKey());

        return $count;
    }

    /**
     * Tokens ligados a una empresa (de cualquier persona): para que la administración los vea y revoque.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function listForTenant(Tenant $tenant): Collection
    {
        return PersonalAccessToken::query()->where('tenant_id', $tenant->getKey())->orderByDesc('id')->get();
    }

    /** Revocación por la administración de la empresa: solo tokens ligados a ESA empresa. */
    public function revokeForTenant(Tenant $tenant, int $tokenId, User $actor): bool
    {
        $token = PersonalAccessToken::query()->where('tenant_id', $tenant->getKey())->whereKey($tokenId)->first();
        if ($token === null) {
            return false;
        }
        $token->delete();
        $this->audit->log('token.revoked', null, ['token_id' => $tokenId, 'kind' => self::kindOf((string) $token->name), 'by' => 'admin', 'owner_user_id' => $token->tokenable_id], $tenant->getKey(), $actor->getKey());

        return true;
    }
}
