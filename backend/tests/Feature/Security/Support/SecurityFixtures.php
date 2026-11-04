<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Support;

use App\Enums\CommercialRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Mfa\MfaService;
use App\Services\Auth\Mfa\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Apoyos de las pruebas de seguridad Enterprise (V2.5 · W6-A). El reloj lo fijan las pruebas con `travelTo()`; el código TOTP se calcula con el mismo
 * `Totp` del servicio (verificado contra los vectores del RFC en TotpTest), de modo que el flujo HTTP se prueba con códigos reales de cada instante.
 */
trait SecurityFixtures
{
    protected function freezeClock(string $at = '2026-11-02 12:00:05'): void
    {
        $this->travelTo(new \DateTimeImmutable($at, new \DateTimeZone('UTC')));
        RateLimiter::clear('mfa');
    }

    /** Código TOTP vigente (o de `$steps` pasos más allá) para un secreto, según el reloj de la prueba. */
    protected function totp(string $secret, int $steps = 0): string
    {
        return Totp::code($secret, now()->getTimestamp() + $steps * 30);
    }

    /**
     * Un código válido y NO usado: avanza el reloj un paso (30 s) y devuelve el código de ese paso. Cada llamada consume un paso nuevo (un código no se acepta dos veces).
     * Importante: avanzar el reloj no vence nada relevante en estas pruebas (la ventana «fresca» es de 600 s y se prueba con `travel` explícito).
     */
    protected function freshCode(string $secret): string
    {
        $this->travel(30)->seconds();

        return $this->totp($secret);
    }

    /**
     * Activa el segundo factor de una persona SIN pasar por HTTP (preparación). Devuelve el secreto y los códigos de recuperación en claro.
     *
     * @return array{secret: string, recovery: list<string>}
     */
    protected function enableMfa(User $user): array
    {
        $mfa = app(MfaService::class);
        $secret = $mfa->begin($user)['secret'];
        $recovery = $mfa->confirm($user, $this->totp($secret));

        // El primer código quedó «usado»: el siguiente paso de reloj es el próximo válido; las pruebas avanzan el reloj o piden un paso más.
        return ['secret' => $secret, 'recovery' => $recovery];
    }

    protected function setCommercial(Tenant $tenant, User $user, ?CommercialRole $role, array $scope = []): void
    {
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->update(['commercial_role' => $role?->value, 'commercial_scope' => json_encode((object) $scope, JSON_THROW_ON_ERROR)]);
    }
}
