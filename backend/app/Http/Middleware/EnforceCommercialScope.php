<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\Commercial\CommercialScope;
use App\Services\Auth\Commercial\CommercialSurfaces;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alcance comercial en las rutas con identificador (V2.5 · W6-A; ADR-V25-15; §15). Corre DESPUÉS de `ResolveTenant` (que fija el alcance de la persona) y solo actúa
 * cuando el alcance está restringido (vendedor, o gerente/servicio con cartera). Las LISTAS (Radar, agenda, resumen, decisiones) se acotan en sus consultas con el mismo
 * `CommercialScope`; aquí se cubre lo que llega por id y lo que no se puede acotar sin tocar su controlador, según el REGISTRO ÚNICO {@see CommercialSurfaces}:
 *
 * - `scoped_recommendation`: si la recomendación no es visible → 404, igual que una inexistente (sin oráculo);
 * - `scoped_customer`: si el cliente no es visible → 404 (lecturas y ESCRITURAS: ficha, alias y cambio de código);
 * - `customer_filter`: `GET /customer/predictions` exige `customer_id` visible (403 sin él, 404 si no es visible);
 * - `team_only`: vistas y acciones de equipo (outcomes, ROI, materialización, compromisos, conciliación…) → 403 `commercial_scope_forbidden`;
 * - `scoped_list` y `non_customer`: pasan (las listas se acotan en su consulta; las otras no exponen datos por cliente).
 * FALLA CERRADO: una ruta que toca clientes u oportunidades y no está en el registro responde 403 a un alcance restringido (`CommercialSurfacesTest` enumera el router).
 */
final class EnforceCommercialScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $request->attributes->get('commercial_scope');
        if (! $scope instanceof CommercialScope || ! $scope->isRestricted()) {
            return $next($request);
        }
        $route = $request->route();
        if ($route === null) {
            return $next($request);
        }
        $surface = CommercialSurfaces::classify($route);
        if ($surface === null) {
            return CommercialSurfaces::requiresClassification($route) ? $this->forbidden('Esta vista no está clasificada para su rol comercial: está cerrada hasta que se acote por cartera.') : $next($request);
        }
        [$type, $parameter] = [$surface[0], $surface[1] ?? 'id'];

        return match ($type) {
            CommercialSurfaces::TEAM_ONLY => $this->forbidden('Esta vista es de toda la empresa y su rol comercial solo permite ver su cartera.'),
            CommercialSurfaces::SCOPED_RECOMMENDATION => ($id = $this->identifier($request, $parameter)) !== null && $scope->canSeeRecommendation($id) ? $next($request) : $this->notFound(),
            CommercialSurfaces::SCOPED_CUSTOMER => ($id = $this->identifier($request, $parameter)) !== null && $scope->canSeeCustomer($id) ? $next($request) : $this->notFound(),
            CommercialSurfaces::CUSTOMER_FILTER => $this->customerFilter($request, $scope, $next),
            default => $next($request),
        };
    }

    /** Identificador numérico del parámetro de la ruta, venga como número o como modelo ya enlazado (`{customer}`). */
    private function identifier(Request $request, string $parameter): ?int
    {
        $value = $request->route($parameter);
        if ($value instanceof Model) {
            return (int) $value->getKey();
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function customerFilter(Request $request, CommercialScope $scope, Closure $next): Response
    {
        $customerId = $request->query('customer_id');
        if (! is_numeric($customerId)) {
            return $this->forbidden('Su rol comercial solo permite consultar las predicciones de un cliente de su cartera: indique customer_id.');
        }

        return $scope->canSeeCustomer((int) $customerId) ? $next($request) : $this->notFound();
    }

    private function forbidden(string $message): Response
    {
        return response()->json(['message' => $message, 'code' => 'commercial_scope_forbidden'], 403);
    }

    private function notFound(): Response
    {
        return response()->json(['message' => 'Recurso no encontrado.', 'code' => 'not_found'], 404);
    }
}
