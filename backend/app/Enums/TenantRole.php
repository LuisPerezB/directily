<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles dentro de una empresa. El superadministrador de Predictily es un flag
 * en users.is_superadmin y NO otorga acceso a datos de negocio de las empresas.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propietario',
            self::Admin => 'Administrador',
            self::Analyst => 'Analista comercial',
            self::Viewer => 'Usuario de consulta',
        };
    }

    /**
     * Habilidades por rol. Las Policies y el middleware "ability" consultan este mapa.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $viewer = ['data.view', 'forecast.view', 'alerts.view'];
        $analyst = [...$viewer, 'imports.manage', 'forecast.run', 'scenarios.run', 'alerts.manage', 'data.export', 'catalog.manage'];
        $admin = [...$analyst, 'users.manage', 'branches.manage', 'datasources.manage', 'settings.manage', 'audit.view'];
        $owner = [...$admin, 'tenant.delete', 'tenant.consent', 'owners.manage', 'tenant.export_all'];

        return match ($this) {
            self::Viewer => $viewer,
            self::Analyst => $analyst,
            self::Admin => $admin,
            self::Owner => $owner,
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }
}
