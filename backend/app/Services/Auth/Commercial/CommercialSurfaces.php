<?php

declare(strict_types=1);

namespace App\Services\Auth\Commercial;

use Illuminate\Routing\Route;

/**
 * REGISTRO ÚNICO de qué hace el alcance comercial con cada ruta que toca clientes, recomendaciones u oportunidades (V2.5 · W6-A, revisión; ADR-V25-15). Falla CERRADO: con un
 * alcance restringido (vendedor, o gerente/servicio con cartera) una ruta que {@see self::requiresClassification()} y no figure aquí responde 403 `commercial_scope_forbidden`, de modo
 * que una ruta nueva de clientes u oportunidades queda cerrada para el vendedor hasta que alguien decida cómo se acota. `CommercialSurfacesTest` enumera el router real y falla si una
 * ruta de esas queda sin clasificar o si el registro conserva una entrada que ya no existe.
 *
 * Clasificaciones (la clave es `MÉTODO uri` sin el prefijo `api/v1/`):
 * - `scoped_list`: la lista, el resumen o los conteos se acotan en la consulta con `CommercialScope::restrictRecommendations()` (PredictiveSalesAgenda, DecisionController).
 * - `scoped_recommendation` (parámetro): una recomendación por id; fuera del alcance → 404.
 * - `scoped_customer` (parámetro): un cliente por id (cartera o con recomendaciones asignadas); fuera del alcance → 404. Vale también para las ESCRITURAS sobre ese cliente.
 * - `customer_filter`: exige `customer_id` visible (403 sin él, 404 si no es visible).
 * - `team_only`: vista o acción de EQUIPO (toda la empresa): 403 para un alcance restringido.
 * - `non_customer`: no expone datos por cliente (agregados por producto, metadatos de corridas, parámetros): pasa.
 */
final class CommercialSurfaces
{
    public const SCOPED_LIST = 'scoped_list';

    public const SCOPED_RECOMMENDATION = 'scoped_recommendation';

    public const SCOPED_CUSTOMER = 'scoped_customer';

    public const CUSTOMER_FILTER = 'customer_filter';

    public const TEAM_ONLY = 'team_only';

    public const NON_CUSTOMER = 'non_customer';

    /** @var array<string, array{0: string, 1?: string}> */
    public const SURFACES = [
        // Revenue Radar y Agenda (W5-A)
        'GET radar/summary' => [self::SCOPED_LIST],
        'GET radar/opportunities' => [self::SCOPED_LIST],
        'GET radar/agenda' => [self::SCOPED_LIST],
        'GET radar/opportunities/{id}' => [self::SCOPED_RECOMMENDATION, 'id'],
        'POST radar/opportunities/{id}/feedback' => [self::SCOPED_RECOMMENDATION, 'id'],
        'POST radar/opportunities/materialize' => [self::TEAM_ONLY],
        'GET customers/{id}/predictions' => [self::SCOPED_CUSTOMER, 'id'],
        'GET customers/{id}/demand' => [self::SCOPED_CUSTOMER, 'id'],
        'GET customers/{id}/products' => [self::SCOPED_CUSTOMER, 'id'],
        'GET customers/{id}/overview' => [self::SCOPED_CUSTOMER, 'id'],
        'GET demand/forecasts' => [self::NON_CUSTOMER],
        'GET supply/readiness' => [self::NON_CUSTOMER],
        // Decision Engine (W5-B) y outcomes (W5-C)
        'GET decisions' => [self::SCOPED_LIST],
        'GET decisions/summary' => [self::SCOPED_LIST],
        'GET decisions/settings' => [self::NON_CUSTOMER],
        'PUT decisions/settings' => [self::NON_CUSTOMER],
        'GET decisions/{id}' => [self::SCOPED_RECOMMENDATION, 'id'],
        'POST decisions/{id}/transition' => [self::SCOPED_RECOMMENDATION, 'id'],
        'GET decisions/outcomes' => [self::TEAM_ONLY],
        'GET decisions/roi' => [self::TEAM_ONLY],
        // Customer Core (P2)
        'POST customer/runs' => [self::TEAM_ONLY],
        'GET customer/runs' => [self::NON_CUSTOMER],
        'GET customer/runs/{id}' => [self::NON_CUSTOMER],
        'GET customer/predictions' => [self::CUSTOMER_FILTER],
        'GET customer/customers/{id}/profile' => [self::SCOPED_CUSTOMER, 'id'],
        'GET customer/evaluation' => [self::NON_CUSTOMER],
        'GET customer/reconciliation' => [self::TEAM_ONLY],
        // Compromisos comerciales (por cliente, de toda la empresa): de equipo
        'GET commitments' => [self::TEAM_ONLY],
        'POST commitments' => [self::TEAM_ONLY],
        'GET commitments/{commitment}' => [self::TEAM_ONLY],
        'POST commitments/{commitment}/cancel' => [self::TEAM_ONLY],
        // Alias y cambio de código del cliente (lecturas y ESCRITURAS): solo de un cliente visible
        'GET catalog/customers/{customer}/aliases' => [self::SCOPED_CUSTOMER, 'customer'],
        'POST catalog/customers/{customer}/aliases' => [self::SCOPED_CUSTOMER, 'customer'],
        'DELETE catalog/customers/{customer}/aliases/{alias}' => [self::SCOPED_CUSTOMER, 'customer'],
        'POST catalog/customers/{customer}/code-change' => [self::SCOPED_CUSTOMER, 'customer'],
    ];

    /** Clave `MÉTODO uri` de una ruta (el primer método que no sea HEAD/OPTIONS; la uri sin el prefijo `api/v1/`). */
    public static function keyOf(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
        $uri = $route->uri();

        return ($methods[0] ?? 'GET').' '.(str_starts_with($uri, 'api/v1/') ? substr($uri, strlen('api/v1/')) : $uri);
    }

    /** @return array{0: string, 1?: string}|null */
    public static function classify(Route $route): ?array
    {
        return self::SURFACES[self::keyOf($route)] ?? null;
    }

    /**
     * ¿La ruta toca clientes, recomendaciones u oportunidades y por eso DEBE estar clasificada? Por su URI o nombre (radar., decisions., customer., customers., commitments.,
     * catalog.customers) o por un parámetro cuyo nombre contenga customer, recommendation u opportunity.
     */
    public static function requiresClassification(Route $route): bool
    {
        $uri = $route->uri();
        $relative = str_starts_with($uri, 'api/v1/') ? substr($uri, strlen('api/v1/')) : $uri;
        if (preg_match('#^(radar/|decisions(/|$)|customer/|customers/|commitments(/|$)|catalog/customers)#', $relative) === 1) {
            return true;
        }
        if (preg_match('/^(radar\.|decisions\.|customer\.|customers\.|commitments\.|catalog\.customers)/', (string) $route->getName()) === 1) {
            return true;
        }
        foreach ($route->parameterNames() as $parameter) {
            if (preg_match('/customer|recommendation|opportunity/i', $parameter) === 1) {
                return true;
            }
        }

        return false;
    }
}
