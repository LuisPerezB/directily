<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaStepUp;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un segundo factor FRESCO a quien tiene MFA activo (V2.5 · W6-A; ADR-V25-15). Uso: `->middleware('mfa.step_up')` o `'mfa.step_up:approval'`.
 * - sin parámetro: siempre que la persona tenga MFA activo (token personal, desactivar MFA…);
 * - `approval`: solo cuando la petición aprueba o rechaza formalmente una decisión (`to_status` = approved | rejected, o el seguimiento «descartado» de una oportunidad revisada);
 * - `write`: solo en métodos que modifican (GET/HEAD/OPTIONS pasan).
 * Quien no tiene MFA activo pasa (la obligatoriedad por política la impone `ResolveTenant`/`EnsureSuperadmin`, que bloquean hasta activarlo). Respuesta: 403 `mfa_step_up_required`;
 * se resuelve con POST /auth/mfa/step-up. Las habilidades sensibles (config `security.mfa.sensitive_abilities`) se cubren en `EnsureTenantAbility`.
 */
final class RequireFreshMfa
{
    public function __construct(private readonly MfaStepUp $stepUp) {}

    public function handle(Request $request, Closure $next, string $mode = 'always'): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->hasMfaEnabled()) {
            return $next($request);
        }
        if ($mode === 'approval' && ! $this->isApprovalClass($request)) {
            return $next($request);
        }
        if ($mode === 'write' && in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if (! $this->stepUp->isFresh($request)) {
            throw MfaException::stepUpRequired();
        }

        return $next($request);
    }

    /**
     * ¿La petición aprueba o rechaza FORMALMENTE una decisión (`decisions.approve`)? Por la transición (`to_status` approved|rejected) o por el seguimiento de la agenda: marcar
     * «descartado» una oportunidad REVISADA implica rechazarla formalmente (la agenda sincroniza el workflow); descartar una sugerida o asignada es `dismiss` (`decisions.manage`).
     */
    private function isApprovalClass(Request $request): bool
    {
        if (in_array($request->input('to_status'), ['approved', 'rejected'], true)) {
            return true;
        }
        $id = $request->route('id');
        $tenantId = app(TenantContext::class)->id();
        if ($request->input('state') === 'descartado' && is_numeric($id) && $tenantId !== null) {
            return DB::table('decision_recommendations')->where('tenant_id', $tenantId)->where('id', (int) $id)->value('status') === 'reviewed';
        }

        return false;
    }
}
