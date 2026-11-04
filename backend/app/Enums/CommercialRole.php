<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol comercial de una persona en una empresa (V2.5 · §15, ADR-V25-15; `tenant_users.commercial_role`). SE SUMA al rol de acceso (owner/admin/analyst/viewer) y nunca lo
 * reemplaza: el rol de acceso dice QUÉ puede hacer (habilidades); el comercial dice SOBRE QUÉ DATOS comerciales (alcance) y, en el caso de servicio, fija un techo de solo lectura.
 *
 * - gerente: ve todo el equipo (toda la cartera de la empresa). Si se le asigna un alcance (zonas, segmentos, clientes, equipo), solo ese.
 * - vendedor: ve y actúa solo sobre su cartera (clientes, zonas o segmentos asignados) y las recomendaciones que le asignaron. Sin cartera ni asignaciones no ve nada.
 * - servicio: vistas de atención al cliente, SOLO LECTURA: el techo de sus habilidades es el del rol de consulta (viewer), cualquiera sea su rol de acceso.
 *
 * El rol comercial no concede habilidades: quien deba registrar seguimiento o gestionar decisiones necesita un rol de acceso que las tenga (`decisions.manage`). Mapa y pruebas:
 * docs/security/ENTERPRISE_SECURITY.md y tests/Feature/Security/CommercialRolesTest.php.
 */
enum CommercialRole: string
{
    case Gerente = 'gerente';
    case Vendedor = 'vendedor';
    case Servicio = 'servicio';

    public function label(): string
    {
        return match ($this) {
            self::Gerente => 'Gerente comercial',
            self::Vendedor => 'Vendedor',
            self::Servicio => 'Servicio al cliente',
        };
    }

    /** El vendedor siempre está acotado a su cartera y a lo que le asignaron. */
    public function alwaysScoped(): bool
    {
        return $this === self::Vendedor;
    }

    public function readOnly(): bool
    {
        return $this === self::Servicio;
    }

    /** Rol de acceso EFECTIVO: servicio no supera al rol de consulta; los demás roles comerciales no cambian el de acceso. */
    public function capAccessRole(TenantRole $role): TenantRole
    {
        return $this->readOnly() ? TenantRole::Viewer : $role;
    }

    /**
     * Lee el valor guardado en `tenant_users.commercial_role` FALLANDO CERRADO: `null` = sin rol comercial; un valor reconocido = ese rol; CUALQUIER otro valor no nulo
     * (otra mayúscula, un espacio, un rol que ya no existe, una cadena vacía) es «inesperado» y quien lo lee debe tratarlo como el alcance MÁS restrictivo, nunca como
     * «sin rol comercial» (que es el alcance más amplio). La escritura solo admite los tres roles o null (CommercialRoleRequest y la restricción CHECK de la base).
     *
     * @return array{0: ?self, 1: bool} [rol, ¿valor inesperado?]
     */
    public static function parseStored(mixed $raw): array
    {
        if ($raw === null) {
            return [null, false];
        }
        $role = is_string($raw) ? self::tryFrom($raw) : null;

        return [$role, $role === null];
    }

    /** Rol de acceso efectivo dado el comercial guardado: un valor inesperado queda en solo lectura (el del rol de consulta), igual que servicio. */
    public static function effectiveAccessRole(TenantRole $role, ?self $commercial, bool $unexpected): TenantRole
    {
        return $unexpected ? TenantRole::Viewer : ($commercial?->capAccessRole($role) ?? $role);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}
