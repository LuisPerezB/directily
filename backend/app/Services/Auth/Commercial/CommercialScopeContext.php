<?php

declare(strict_types=1);

namespace App\Services\Auth\Commercial;

/**
 * Alcance comercial de la persona en la petición actual (V2.5 · W6-A). Se registra como `scoped` (se reinicia por petición/trabajo, seguro con Octane) y lo fija
 * `ResolveTenant` junto con la empresa y el rol. Fuera de una petición de usuario (comandos, colas del sistema) no hay persona y el alcance es «sin restricción»:
 * el Decision Engine y los trabajos programados operan sobre toda la empresa.
 */
final class CommercialScopeContext
{
    private ?CommercialScope $scope = null;

    public function set(?CommercialScope $scope): void
    {
        $this->scope = $scope;
    }

    public function current(): CommercialScope
    {
        return $this->scope ?? CommercialScope::unrestricted();
    }

    public function forget(): void
    {
        $this->scope = null;
    }
}
