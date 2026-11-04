<?php

declare(strict_types=1);

namespace App\Services\Auth\Commercial;

use App\Enums\CommercialRole;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alcance comercial de una persona en la empresa activa (V2.5 · W6-A; ADR-V25-15; §15). Es el ÚNICO lugar donde se decide qué oportunidades y qué clientes ve y toca
 * quien tiene un rol comercial; la lista del Radar, la agenda, el resumen, las decisiones y las rutas por id lo consultan (nada se filtra en el cliente).
 *
 * `tenant_users.commercial_scope` (jsonb): `{"customer_ids": [..], "zones": [..], "segment_ids": [..], "team_user_ids": [..]}`. La cartera de clientes es la unión de los
 * clientes listados, los de las zonas comerciales (`customers.commercial_zone`) y los de los segmentos. Reglas:
 *
 * - vendedor (siempre acotado): ve una recomendación si (a) está asignada a él, o (b) NO está asignada a nadie y su cliente está en su cartera (`radar.seller_scope`
 *   puede desactivar (b)). Una asignada a OTRA persona no se muestra aunque el cliente esté en su cartera. Sin cartera ni asignaciones: lista vacía (falla cerrado).
 * - gerente / servicio: sin alcance guardado ven toda la empresa; con alcance ven las recomendaciones de los clientes de su cartera y las asignadas a su equipo (`team_user_ids`
 *   y a sí mismos), sean de la persona que sean.
 * - sin rol comercial (`NULL`): sin restricción (el rol de acceso manda).
 * - valor guardado INESPERADO (no es gerente, vendedor ni servicio: otra mayúscula, un rol retirado, una cadena vacía): falla CERRADO, nunca abierto. El alcance es el más
 *   restrictivo —no ve ningún cliente ni recomendación— y se registra una advertencia. Nunca equivale a «sin rol comercial».
 * Un cliente es visible si está en la cartera o si hay una recomendación asignada a la persona (o a su equipo) sobre él.
 */
final class CommercialScope
{
    /**
     * @param  list<int>  $customerIds
     * @param  list<string>  $zones
     * @param  list<int>  $segmentIds
     * @param  list<int>  $teamUserIds
     */
    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?CommercialRole $role = null,
        public readonly array $customerIds = [],
        public readonly array $zones = [],
        public readonly array $segmentIds = [],
        public readonly array $teamUserIds = [],
        /** El rol comercial guardado no es reconocido: alcance cerrado (ninguna recomendación ni cliente). */
        public readonly bool $unexpectedRole = false,
    ) {}

    public static function unrestricted(): self
    {
        return new self;
    }

    /** @param mixed $scope jsonb tal como llega de la base (cadena JSON) o ya decodificado */
    public static function fromMembership(int $userId, mixed $role, mixed $scope): self
    {
        [$commercial, $unexpected] = CommercialRole::parseStored($role);
        if ($unexpected) {
            Log::warning('Rol comercial inesperado en tenant_users: alcance cerrado (ninguna recomendación ni cliente).', [
                'user_id' => $userId, 'tenant_id' => app(TenantContext::class)->id(), 'commercial_role' => is_string($role) ? mb_substr($role, 0, 40) : get_debug_type($role),
            ]);

            return new self($userId, null, [], [], [], [], true);
        }
        if ($commercial === null) {
            return new self($userId);
        }
        $data = is_string($scope) ? (json_decode($scope, true) ?: []) : (is_array($scope) ? $scope : []);
        $ints = fn (string $key): array => array_values(array_unique(array_map('intval', array_filter((array) ($data[$key] ?? []), 'is_numeric'))));

        return new self(
            $userId, $commercial, $ints('customer_ids'),
            array_values(array_unique(array_filter(array_map(fn ($z) => is_string($z) ? trim($z) : '', (array) ($data['zones'] ?? [])), fn (string $z): bool => $z !== ''))),
            $ints('segment_ids'), $ints('team_user_ids'),
        );
    }

    public function hasPortfolio(): bool
    {
        return $this->customerIds !== [] || $this->zones !== [] || $this->segmentIds !== [];
    }

    /** ¿Hay alguna restricción de alcance (no es «toda la empresa»)? */
    public function isRestricted(): bool
    {
        if ($this->unexpectedRole) {
            return true;
        }
        if ($this->role === null) {
            return false;
        }

        return $this->role->alwaysScoped() || $this->hasPortfolio() || $this->teamUserIds !== [];
    }

    /** Personas cuyas asignaciones cuentan como «mías»: la persona y, para gerente/servicio, su equipo. @return list<int> */
    public function actorIds(): array
    {
        if ($this->userId === null || $this->unexpectedRole) {
            return [];
        }

        return $this->role?->alwaysScoped() ? [$this->userId] : array_values(array_unique([$this->userId, ...$this->teamUserIds]));
    }

    /**
     * Acota una consulta de `decision_recommendations` (Eloquent o base) a lo que esta persona puede ver. No hace nada sin restricción.
     */
    public function restrictRecommendations(EloquentBuilder|QueryBuilder $query, string $table = 'decision_recommendations'): void
    {
        if (! $this->isRestricted()) {
            return;
        }
        if ($this->userId === null || $this->unexpectedRole) {
            $query->whereRaw('1 = 0');   // restringida sin persona: nada (nunca «IS NULL»)

            return;
        }
        $query->where(function ($w) use ($table): void {
            $actors = $this->actorIds();
            if ($this->role?->alwaysScoped()) {
                $w->where("{$table}.assigned_user_id", $this->userId);
                if ($this->hasPortfolio() && config('radar.seller_scope.unassigned_in_portfolio', true)) {
                    $w->orWhere(fn ($u) => $u->whereNull("{$table}.assigned_user_id")->whereIn("{$table}.customer_id", $this->portfolioCustomers()));
                }

                return;
            }
            $w->whereIn("{$table}.assigned_user_id", $actors);
            if ($this->hasPortfolio()) {
                $w->orWhereIn("{$table}.customer_id", $this->portfolioCustomers());
            }
        });
    }

    /** Acota una consulta de `customers` (Eloquent o base) a la cartera y a los clientes con recomendaciones asignadas. */
    public function restrictCustomers(EloquentBuilder|QueryBuilder $query, string $table = 'customers'): void
    {
        if (! $this->isRestricted()) {
            return;
        }
        if ($this->userId === null || $this->unexpectedRole) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($w) use ($table): void {
            if ($this->hasPortfolio()) {
                $w->whereIn("{$table}.id", $this->portfolioCustomers());
                $w->orWhereIn("{$table}.id", $this->assignedCustomers());
            } else {
                $w->whereIn("{$table}.id", $this->assignedCustomers());
            }
        });
    }

    public function canSeeCustomer(int $customerId): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }
        $query = DB::table('customers')->where('customers.tenant_id', $this->tenantId())->where('customers.id', $customerId);
        $this->restrictCustomers($query);

        return $query->exists();
    }

    public function canSeeRecommendation(int $recommendationId): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }
        $query = DB::table('decision_recommendations')->where('decision_recommendations.tenant_id', $this->tenantId())->where('decision_recommendations.id', $recommendationId);
        $this->restrictRecommendations($query);

        return $query->exists();
    }

    // ── Subconsultas ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Clientes de la cartera (clientes listados ∪ zonas ∪ segmentos), de la empresa activa. */
    private function portfolioCustomers(): Closure
    {
        $tenantId = $this->tenantId();

        return function ($sub) use ($tenantId): void {
            $sub->select('pc.id')->from('customers as pc')->where('pc.tenant_id', $tenantId)->where(function ($c): void {
                $c->whereRaw('1 = 0');
                if ($this->customerIds !== []) {
                    $c->orWhereIn('pc.id', $this->customerIds);
                }
                if ($this->zones !== []) {
                    $c->orWhereIn('pc.commercial_zone', $this->zones);
                }
                if ($this->segmentIds !== []) {
                    $c->orWhereIn('pc.segment_id', $this->segmentIds);
                }
            });
        };
    }

    /** Clientes con alguna recomendación asignada a la persona (o a su equipo). */
    private function assignedCustomers(): Closure
    {
        $tenantId = $this->tenantId();
        $actors = $this->actorIds();

        return function ($sub) use ($tenantId, $actors): void {
            $sub->select('ar.customer_id')->from('decision_recommendations as ar')->where('ar.tenant_id', $tenantId)->whereNotNull('ar.customer_id')
                ->whereIn('ar.assigned_user_id', $actors === [] ? [0] : $actors);
        };
    }

    private function tenantId(): int
    {
        return (int) app(TenantContext::class)->require()->getKey();
    }
}
