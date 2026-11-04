<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Scope;

use App\Enums\CommercialRole;
use App\Models\Customer;
use App\Services\Auth\Commercial\CommercialScope as AuthScope;
use App\Services\Conversation\Gateway\DataScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Alcance comercial de quien consulta, visto por el gateway (V2.5 · W6-D). Es una FACHADA delgada sobre el CommercialScope de W6-A (`App\Services\Auth\Commercial`): la
 * regla de qué recomendaciones y qué clientes ve cada rol (vendedor: lo asignado a él y lo sin asignar de su cartera; gerente/servicio: su cartera y su equipo; sin rol
 * comercial: toda la empresa) vive SOLO allí y es la misma que aplican las rutas de la API. Aquí solo se agrega lo propio del gateway: qué TIPO de dato (DataScope)
 * permite cada alcance y cómo acotar una consulta a los clientes visibles.
 *
 * Paridad con la API: el gateway nunca da más que la ruta equivalente. Los datos de TODA la empresa (ROI, resultados medidos) se niegan a quien tiene un alcance
 * restringido, igual que `EnforceCommercialScope` los rechaza en las rutas de equipo.
 */
final class GatewayScope
{
    private function __construct(public readonly AuthScope $commercial, public readonly bool $resolved, public readonly string $source) {}

    /** El alcance ya calculado por W6-A (`ResolveTenant`, atributo `commercial_scope`) o por el resolvedor. */
    public static function from(AuthScope $commercial, string $source): self
    {
        return new self($commercial, true, $source);
    }

    /**
     * Sin persona o sin membresía resoluble (o con un rol comercial desconocido): se trata como el alcance MÁS restrictivo —un vendedor sin persona, que no ve ninguna recomendación
     * ni cliente— y solo permite lectura operativa.
     */
    public static function unresolved(): self
    {
        return new self(new AuthScope(null, CommercialRole::Vendedor), false, 'unresolved');
    }

    public function isRestricted(): bool
    {
        return $this->commercial->isRestricted();
    }

    /**
     * ¿El alcance es utilizable? No lo es sin persona/membresía resoluble ni con un rol comercial guardado que W6-A no reconoce (`unexpectedRole`): en ambos casos el gateway
     * cierra IGUAL, venga el alcance de la petición HTTP (ResolveTenant) o del resolvedor — solo lectura operativa, ninguna herramienta por cliente ni de gestión.
     */
    public function isUsable(): bool
    {
        return $this->resolved && ! $this->commercial->unexpectedRole;
    }

    public function role(): ?string
    {
        return $this->isUsable() ? $this->commercial->role?->value : 'unresolved';
    }

    /** ¿Puede ver este tipo de dato? (la habilidad del rol/token se evalúa aparte). */
    public function permits(DataScope $scope): bool
    {
        return match ($scope) {
            DataScope::Operational => true,
            DataScope::CustomerPortfolio => $this->isUsable(),
            DataScope::TenantManagement => $this->isUsable() && ! $this->isRestricted(),
        };
    }

    /** Acota una consulta de `decision_recommendations` a lo visible para la persona (misma regla que las listas y los detalles de la API). */
    public function constrainRecommendations(EloquentBuilder|QueryBuilder $query, string $table = 'decision_recommendations'): void
    {
        $this->commercial->restrictRecommendations($query, $table);
    }

    /** Acota una consulta con una columna de cliente a los clientes visibles (cartera y asignados). Sin restricción no cambia nada. */
    public function constrainCustomers(EloquentBuilder|QueryBuilder $query, string $column = 'customer_id'): EloquentBuilder|QueryBuilder
    {
        if (! $this->isRestricted()) {
            return $query;
        }
        $visible = Customer::query()->select('customers.id');
        $this->commercial->restrictCustomers($visible);

        return $query->whereIn($column, $visible);
    }

    public function allowsCustomer(int $customerId): bool
    {
        return $this->commercial->canSeeCustomer($customerId);
    }

    /**
     * Resumen para auditoría y estado: rol y si recorta; nunca los ids de la cartera ni del equipo.
     *
     * @return array{role: string|null, restricted: bool, source: string}
     */
    public function describe(): array
    {
        return ['role' => $this->role(), 'restricted' => $this->isRestricted(), 'source' => $this->isUsable() ? $this->source : 'unresolved'];
    }
}
