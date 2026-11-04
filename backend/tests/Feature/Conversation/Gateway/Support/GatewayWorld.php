<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway\Support;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\Gateway\IntelligenceGateway;
use App\Services\Conversation\Tools\ToolContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Conversation\Support\UsesFakeProvider;
use Tests\Feature\Radar\Support\RadarFixtures;

/**
 * Mundo ficticio del gateway (V2.5 · W6-D): la empresa del Revenue Radar (compradores c1 «CLI-0001», c2 «CLI-0002» y el consumidor final genérico c3, con las 14
 * oportunidades del Decision Engine) más usuarios con distintos roles de acceso y roles comerciales (gerente, vendedor, servicio) y utilidades para llamar al gateway
 * directamente con un contexto armado como lo hace una petición.
 */
trait GatewayWorld
{
    use RadarFixtures;
    use UsesFakeProvider;

    protected function gateway(): IntelligenceGateway
    {
        return app(IntelligenceGateway::class);
    }

    /**
     * Contexto de una petición: empresa, usuario y rol salen del servidor. Con `$tokenAbilities` simula un token Sanctum real con esas habilidades.
     *
     * @param  list<string>|null  $tokenAbilities
     */
    protected function ctx(Tenant $tenant, User $user, ?TenantRole $role = null, ?array $tokenAbilities = null, string $channel = 'web'): ToolContext
    {
        $member = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first(['role', 'commercial_role']);
        // Como ResolveTenant (W6-A): el rol comercial «servicio» pone un techo de solo lectura (consulta) al rol de acceso guardado.
        $role ??= CommercialRole::tryFrom((string) ($member->commercial_role ?? ''))?->capAccessRole(TenantRole::from((string) $member->role)) ?? TenantRole::from((string) $member->role);
        $token = $tokenAbilities === null ? null : $user->createToken('prueba', $tokenAbilities)->accessToken;

        return new ToolContext($tenant, $user, $role, $token, $channel);
    }

    /** Agrega un miembro a la empresa con un rol de acceso y, opcionalmente, un rol comercial y su cartera. */
    protected function member(Tenant $tenant, TenantRole $role, ?string $commercialRole = null, array $portfolio = []): User
    {
        $user = $this->addUserToTenant($tenant, $role);
        $this->setCommercial($tenant, $user, $commercialRole, $portfolio);

        return $user;
    }

    protected function setCommercial(Tenant $tenant, User $user, ?string $commercialRole, array $portfolio = []): void
    {
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->update(['commercial_role' => $commercialRole, 'commercial_scope' => json_encode($portfolio === [] ? (object) [] : $portfolio)]);
    }

    /**
     * Empresa con oportunidades, zonas distintas para c1 (NORTE) y c2 (SUR) y un resultado medido por cada comprador modelado.
     *
     * @return array{tenant: Tenant, user: User, cat: array, run: int, recs: array<string, int>}
     */
    protected function gatewayCompany(): array
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        $this->materializeAll($c);
        DB::table('customers')->where('id', $c['cat']['customers']['c1'])->update(['commercial_zone' => 'NORTE']);
        DB::table('customers')->where('id', $c['cat']['customers']['c2'])->update(['commercial_zone' => 'SUR']);
        $recs = [];
        foreach (['c1', 'c2'] as $key) {
            $recs[$key] = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('customer_id', $c['cat']['customers'][$key])
                ->where('kind', 'contact')->where('horizon_days', 30)->orderBy('id')->value('id');
            $this->insertOutcome($c['tenant'], $recs[$key], $key === 'c1' ? 'temporally_associated' : 'observed');
        }
        $c['recs'] = $recs;

        return $c;
    }

    protected function insertOutcome(Tenant $tenant, int $recommendationId, string $classification, string $event = 'purchase'): void
    {
        DB::table('decision_outcomes')->insert([
            'tenant_id' => $tenant->id, 'recommendation_id' => $recommendationId, 'action_kind' => 'contacted', 'action_at' => now()->subDays(20),
            'window_from' => now()->subDays(40)->toDateString(), 'window_to' => now()->subDays(10)->toDateString(), 'observed_event' => $event, 'observed_ref' => 'sale:1',
            'observed_units' => 4, 'observed_net_amount' => 32, 'contribution' => 8, 'contact_cost' => 0, 'classification' => $classification, 'method' => 'none',
            'evidence' => json_encode(['contribution' => ['status' => 'known']]), 'measured_at' => now()->subDays(9),
        ]);
    }

    /** Compradores y estados que devolvió una lista de oportunidades. @param array<string, mixed> $data */
    protected function customerCodes(array $data, string $listKey = 'opportunities'): array
    {
        return array_values(array_unique(array_map(fn (array $o) => $o['customer']['code'] ?? $o['customer_code'] ?? null, (array) ($data[$listKey] ?? []))));
    }
}
