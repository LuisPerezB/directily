<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\ExternalEvent;
use App\Models\FactorDefinition;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\ExternalEventService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Eventos externos manuales de la empresa con procedencia (V2.1 §24.6; ADR-V25-25). Sin integraciones externas ni LLM: el evento lo registra una
 * persona; la URL de la fuente solo se guarda (nunca se descarga). Los eventos públicos (tenant_id NULL) se leen pero no se escriben.
 * Flujo PROPOSED → VERIFIED | REJECTED por un usuario distinto de quien lo propuso; EXPIRED al vencer la vigencia.
 */
final class ExternalEventController extends IntelligenceController
{
    public function __construct(private readonly ExternalEventService $events, private readonly TenantContext $context) {}

    /** GET /intelligence/events — data.view. Eventos y señales (sin las alertas informativas, que se listan aparte). */
    public function index(Request $request): JsonResponse
    {
        return $this->list($request, false);
    }

    /** GET /intelligence/events/informative-alerts — data.view. Alertas oficiales/regulatorias: se muestran aunque no mejoren el error. */
    public function informative(Request $request): JsonResponse
    {
        return $this->list($request, true);
    }

    /** GET /intelligence/events/{id} — data.view */
    public function show(int $id): JsonResponse
    {
        $event = ExternalEvent::query()->findOrFail($id);

        return response()->json(['data' => $this->events->present($event, $this->context->require())]);
    }

    /** POST /intelligence/events — catalog.manage */
    public function store(Request $request): JsonResponse
    {
        $event = $this->events->propose($request->user(), $this->validated($request, true));

        return response()->json(['data' => $this->events->present($event, $this->context->require())], 201);
    }

    /** PATCH /intelligence/events/{id} — catalog.manage; solo un evento PROPOSED y solo quien lo propuso */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = $this->ownEvent($id);
        $event = $this->events->update($request->user(), $event, $this->validated($request, false));

        return response()->json(['data' => $this->events->present($event, $this->context->require())]);
    }

    /** DELETE /intelligence/events/{id} — catalog.manage; PROPOSED (solo su proponente) o REJECTED */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->events->delete($request->user(), $this->ownEvent($id));

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** POST /intelligence/events/{id}/verify — settings.manage; por un usuario distinto de quien lo propuso y sobre la versión que revisó (`review_token`) */
    public function verify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'], 'corroboration' => ['sometimes', Rule::in(ExternalEvent::CORROBORATIONS)],
            'review_token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $event = $this->events->verify($this->context->require(), $request->user(), $this->ownEvent($id), $this->reviewToken($request, $data), $data['note'] ?? null, $data['corroboration'] ?? null);

        return response()->json(['data' => $this->events->present($event, $this->context->require())]);
    }

    /** POST /intelligence/events/{id}/reject — settings.manage; motivo obligatorio y versión revisada (`review_token`) */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000'], 'review_token' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $event = $this->events->reject($request->user(), $this->ownEvent($id), $data['reason'], $this->reviewToken($request, $data));

        return response()->json(['data' => $this->events->present($event, $this->context->require())]);
    }

    /** La URL de procedencia no puede traer credenciales incrustadas (`https://usuario:clave@host`): se guardaría y se mostraría en claro a todos los usuarios. */
    private function withoutCredentials(string $attribute, mixed $value, \Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            $fail('La URL de la fuente no puede incluir usuario ni clave.');
        }
    }

    /** Versión revisada: `review_token` del cuerpo o, si falta, el encabezado `If-Match` (con o sin comillas). @param array<string, mixed> $data */
    private function reviewToken(Request $request, array $data): ?string
    {
        $token = $data['review_token'] ?? null;
        if ($token === null || $token === '') {
            $token = trim((string) $request->header('If-Match', ''), " \t\"");
            $token = str_starts_with($token, 'W/"') ? substr($token, 3) : $token;
        }

        return $token === '' ? null : (string) $token;
    }

    /** Evento de la empresa. Uno público existe pero no se escribe (403); uno de otra empresa no existe (404). */
    private function ownEvent(int $id): ExternalEvent
    {
        $event = ExternalEvent::query()->findOrFail($id);
        if ($event->isPublic()) {
            throw new PublicCatalogReadOnlyException;
        }

        return $event;
    }

    private function list(Request $request, bool $informative): JsonResponse
    {
        $tenant = $this->context->require();
        $f = $request->validate([
            'status' => ['sometimes', Rule::in(ExternalEvent::STATUSES)],
            'family' => ['sometimes', Rule::in(FactorDefinition::FAMILIES)],
            'event_type' => ['sometimes', 'string', 'max:60'],
            'published_from' => ['sometimes', 'date'],
            'published_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ExternalEvent::query()->where('is_informative_alert', $informative)->orderByDesc('id');
        foreach (['family', 'event_type'] as $column) {
            if (isset($f[$column])) {
                $query->where($column, $f[$column]);
            }
        }
        if (isset($f['published_from'])) {
            $query->where('published_at', '>=', $f['published_from']);
        }
        if (isset($f['published_to'])) {
            $query->where('published_at', '<', CarbonImmutable::parse($f['published_to'])->addDay());
        }
        if (isset($f['status'])) {
            $this->filterByEffectiveStatus($query, $f['status'], CarbonImmutable::now($tenant->timezone)->toDateString());
        }
        $page = $query->paginate((int) ($f['per_page'] ?? 25));

        return $this->paginated($page, fn (ExternalEvent $e) => $this->events->present($e, $tenant));
    }

    /** El estado efectivo incluye los PROPOSED/VERIFIED cuya vigencia terminó aunque el proceso que los marca aún no haya corrido. */
    private function filterByEffectiveStatus(Builder $query, string $status, string $today): void
    {
        $past = fn (Builder $q) => $q->whereNotNull('effective_to')->where('effective_to', '<', $today);
        match ($status) {
            ExternalEvent::STATUS_EXPIRED => $query->where(fn (Builder $q) => $q->where('status', ExternalEvent::STATUS_EXPIRED)
                ->orWhere(fn (Builder $e) => $e->whereIn('status', [ExternalEvent::STATUS_PROPOSED, ExternalEvent::STATUS_VERIFIED])->where($past))),
            ExternalEvent::STATUS_REJECTED => $query->where('status', ExternalEvent::STATUS_REJECTED),
            default => $query->where('status', $status)->whereNot($past),
        };
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'family' => [$req, Rule::in(FactorDefinition::FAMILIES)],
            'event_type' => [$req, 'string', 'max:60'],
            'title' => [$req, 'string', 'min:3', 'max:300'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Solo http(s): la URL se guarda como procedencia; el sistema nunca la descarga.
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500', $creating ? 'required_without:source_ref' : 'sometimes', $this->withoutCredentials(...)],
            'source_ref' => ['sometimes', 'nullable', 'string', 'max:200'],
            'authority' => ['sometimes', 'nullable', 'string', 'max:200'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:80'],
            'corroboration' => ['sometimes', Rule::in(ExternalEvent::CORROBORATIONS)],
            'affects' => ['sometimes', 'nullable', 'array:products,categories,regions,taxonomy_nodes'],
            'affects.products' => ['sometimes', 'array', 'max:500'],
            'affects.products.*' => ['integer', 'min:1'],
            'affects.categories' => ['sometimes', 'array', 'max:500'],
            'affects.categories.*' => ['integer', 'min:1'],
            'affects.regions' => ['sometimes', 'array', 'max:100'],
            'affects.regions.*' => ['string', 'max:80'],
            'affects.taxonomy_nodes' => ['sometimes', 'array', 'max:500'],
            'affects.taxonomy_nodes.*' => ['integer', 'min:1'],
            'is_informative_alert' => ['sometimes', 'boolean'],
        ]);
    }
}
