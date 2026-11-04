<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway;

/**
 * Alcance de datos que declara cada herramienta del gateway (V2.5 · W6-D; ADR-V25-17). Es la segunda mitad de la autorización por herramienta: la primera es la
 * habilidad del rol/token (la misma de la ruta equivalente) y esta decide, con el rol comercial de la empresa (W6-A: gerente, vendedor, servicio), si el usuario puede
 * ver ESE TIPO de dato.
 */
enum DataScope: string
{
    /** Resultados que no se atribuyen a un cliente (pronóstico de producto, inventario, abastecimiento, calidad de modelos). Cualquier rol con la habilidad. */
    case Operational = 'operational';

    /** Resultados por cliente (oportunidades, predicciones de compra, decisiones, outcomes): se acotan a la cartera comercial del usuario en el servidor. */
    case CustomerPortfolio = 'customer_portfolio';

    /** Agregados económicos de TODA la empresa (ROI): no admiten recortarse a una cartera sin desvirtuarse, así que se niegan a quien tiene una cartera acotada. */
    case TenantManagement = 'tenant_management';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operativo (sin atribución a clientes)',
            self::CustomerPortfolio => 'Por cliente (acotado a la cartera comercial)',
            self::TenantManagement => 'Gestión de toda la empresa',
        };
    }
}
