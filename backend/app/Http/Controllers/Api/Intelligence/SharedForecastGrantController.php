<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\SharedForecastGrant;
use App\Models\SharedForecastGrantAccess;
use App\Services\Intelligence\SharedGrants\SharedForecastGrantService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Concesiones de pronóstico entre empresas autorizadas (V2.1 §10; ADR-V25-25). DESACTIVADO por bandera: todas las rutas responden 404 mientras
 * `intelligence.shared_forecast_grants.enabled` sea false (lo garantiza el middleware EnsureSharedForecastGrantsEnabled y el propio servicio).
 *
 * - Emisor (`settings.manage`): crea (borrador), activa, revoca y consulta la bitácora de accesos de SUS concesiones.
 * - Receptor (`forecast.view`): lista lo concedido a su empresa y lee el pronóstico dentro del alcance. Nunca ve ventas ni clientes del emisor.
 * El receptor accede por identificador numérico y no por route-model binding: un tercero obtiene el mismo 404 que una concesión inexistente y su
 * intento se registra en la bitácora.
 */
final class SharedForecastGrantController extends IntelligenceController
{
    public function __construct(private readonly SharedForecastGrantService $grants, private readonly TenantContext $context) {}

    // ── Emisor ──

    /** GET /intelligence/shared-forecast-grants — concesiones emitidas por la empresa */
    public function index(Request $request): JsonResponse
    {
        $this->grants->ensureEnabled();
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $perPage = $request->integer('per_page', 25);
        $now = CarbonImmutable::now();

        return $this->paginated($this->grants->issuedBy($this->context->require())->paginate($perPage), fn (SharedForecastGrant $g) => $this->grants->presentForIssuer($g, $now));
    }

    /** POST /intelligence/shared-forecast-grants — crea una concesión en borrador */
    public function store(Request $request): JsonResponse
    {
        $this->grants->ensureEnabled();
        $data = $request->validate([
            'recipient_tenant_uuid' => ['required', 'uuid'],
            'scope' => ['required', Rule::in(SharedForecastGrant::SCOPES)],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['integer', 'min:1'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'granularity' => ['required', Rule::in(SharedForecastGrant::GRANULARITIES)],
            'allowed_content' => ['required', 'array', 'min:1'],
            'allowed_content.*' => ['string', 'max:40'],
            'forecast_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['required', 'date'],
            'purpose' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $tenant = $this->context->require();
        $grant = $this->grants->create($tenant, $request->user(), $data)->load('recipient:id,uuid');

        return response()->json(['data' => $this->grants->presentForIssuer($grant, CarbonImmutable::now())], 201);
    }

    /** GET /intelligence/shared-forecast-grants/{grantId} */
    public function show(int $grantId): JsonResponse
    {
        $grant = $this->issuedGrant($grantId);

        return response()->json(['data' => $this->grants->presentForIssuer($grant, CarbonImmutable::now())]);
    }

    /** POST /intelligence/shared-forecast-grants/{grantId}/activate */
    public function activate(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->grants->activate($this->context->require(), $request->user(), $this->issuedGrant($grantId));

        return response()->json(['data' => $this->grants->presentForIssuer($grant->load('recipient:id,uuid'), CarbonImmutable::now())]);
    }

    /** POST /intelligence/shared-forecast-grants/{grantId}/revoke */
    public function revoke(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->grants->revoke($this->context->require(), $request->user(), $this->issuedGrant($grantId));

        return response()->json(['data' => $this->grants->presentForIssuer($grant->load('recipient:id,uuid'), CarbonImmutable::now())]);
    }

    /** GET /intelligence/shared-forecast-grants/{grantId}/accesses — bitácora de accesos (lecturas y rechazos) */
    public function accesses(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->issuedGrant($grantId);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:200']]);
        $perPage = $request->integer('per_page', 50);
        $page = SharedForecastGrantAccess::query()->where('grant_id', $grant->id)->orderByDesc('id')->paginate($perPage);

        return $this->paginated($page, fn (SharedForecastGrantAccess $a) => $this->grants->presentAccess($a, $grant));
    }

    // ── Receptor ──

    /** GET /intelligence/received-forecast-grants — concesiones dirigidas a la empresa (sin borradores) */
    public function received(Request $request): JsonResponse
    {
        $this->grants->ensureEnabled();
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $perPage = $request->integer('per_page', 25);
        $now = CarbonImmutable::now();

        return $this->paginated($this->grants->receivedBy($this->context->require())->paginate($perPage), fn (SharedForecastGrant $g) => $this->grants->presentForRecipient($g, $now));
    }

    /** GET /intelligence/received-forecast-grants/{grantId}/forecast — pronóstico concedido, dentro del alcance */
    public function forecast(Request $request, int $grantId): JsonResponse
    {
        $this->grants->ensureEnabled();
        $filters = $request->validate([
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'granularity' => ['sometimes', Rule::in(SharedForecastGrant::GRANULARITIES)],
            'include' => ['sometimes', 'array'],
            'include.*' => ['string', 'max:40'],
        ]);
        $result = $this->grants->read($this->context->require(), $request->user(), $grantId, $filters);

        return response()->json($result);
    }

    /** Concesión emitida por la empresa activa; cualquier otra (del receptor o de un tercero) es un 404. */
    private function issuedGrant(int $grantId): SharedForecastGrant
    {
        $this->grants->ensureEnabled();
        $tenant = $this->context->require();
        $grant = SharedForecastGrant::query()->with('recipient:id,uuid')->find($grantId);
        if ($grant === null || $grant->issuer_tenant_id !== $tenant->id) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $grant;
    }
}
