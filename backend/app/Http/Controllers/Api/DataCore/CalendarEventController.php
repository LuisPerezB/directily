<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Exceptions\DomainStateException;
use App\Http\Controllers\Api\DataCore\Concerns\ImportsCanonicalRows;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CalendarEvent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Audit\AuditLogger;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Parsing\ValueParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Calendario con alcance y estado de conocimiento. Lectura: eventos de la empresa + calendario público. Escritura: solo
 * eventos propios, siempre por el importador (revisiones, `known_at`). Eliminar = cancelar (nueva revisión): el historial se conserva.
 */
final class CalendarEventController extends Controller
{
    use ImportsCanonicalRows;

    private const HEADERS = ['scope', 'scope_ref', 'code', 'name', 'category', 'starts_on', 'ends_on', 'status', 'known_at', 'source'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'scope' => ['sometimes', Rule::in(CalendarEvent::SCOPES)], 'category' => ['sometimes', Rule::in(CalendarEvent::CATEGORIES)],
            'status' => ['sometimes', Rule::in(CalendarEvent::STATUSES)], 'include_public' => ['sometimes', 'boolean'],
            'as_of' => ['sometimes', 'date'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ]);
        $query = CalendarEvent::query()->orderBy('starts_on')->orderBy('code')->orderBy('revision');
        if (! $request->boolean('include_public', true)) {
            $query->own();
        }
        foreach (['scope', 'category'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (isset($data['from'])) {
            $query->where('ends_on', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->where('starts_on', '<=', $data['to']);
        }
        $asOf = isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : null;
        $events = $this->reduce($query->get(), $asOf);
        if (isset($data['status'])) {
            $events = $events->filter(fn (CalendarEvent $e) => $e->status === $data['status']);
        }

        return response()->json([
            'data' => $events->take((int) ($data['limit'] ?? 1000))->values()->map(fn (CalendarEvent $e) => $this->present($e)),
            'meta' => ['as_of' => $asOf?->toIso8601String(), 'rule' => $asOf === null ? 'latest_revision' : 'latest_revision_known_at_or_before_as_of_unknown_known_at_excluded'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(CalendarEvent::SCOPES)],
            'scope_ref' => ['sometimes', 'nullable', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(CalendarEvent::CATEGORIES)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::in(CalendarEvent::STATUSES)],
            'known_at' => ['sometimes', 'nullable', 'date'],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $job = $this->importRows(ImportEntity::CalendarEvents, self::HEADERS, [$this->row($data)]);
        $event = $this->latestOf($data['scope'], $data['code'], $data['starts_on'], $this->storedRef($data['scope'], $data['scope_ref'] ?? null), true);
        $this->audit->log('calendar_event.saved', $event, ['code' => $data['code'], 'scope' => $data['scope']]);

        return response()->json(['data' => $this->present($event), 'result' => array_intersect_key((array) $job->stats, array_flip(['events_new', 'events_revised', 'events_known_at_updated', 'events_unchanged']))], 201);
    }

    /** Cambia nombre, categoría, fin, estado o `known_at` de un evento propio: crea una REVISIÓN nueva. La identidad no cambia. */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = CalendarEvent::query()->own()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'category' => ['sometimes', Rule::in(CalendarEvent::CATEGORIES)],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'], 'status' => ['sometimes', Rule::in(CalendarEvent::STATUSES)],
            'known_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $this->saveRevision($event, $data);

        return response()->json(['data' => $this->present($this->latestOf($event->scope, $event->code, $event->starts_on->toDateString(), $event->scope_ref, true))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $event = CalendarEvent::query()->own()->findOrFail($id);
        $this->saveRevision($event, ['status' => 'cancelled']);
        $this->audit->log('calendar_event.cancelled', $event, ['code' => $event->code]);

        return response()->json(['data' => $this->present($this->latestOf($event->scope, $event->code, $event->starts_on->toDateString(), $event->scope_ref, true))]);
    }

    /** @param array<string, mixed> $changes */
    private function saveRevision(CalendarEvent $event, array $changes): void
    {
        $latest = $this->latestOf($event->scope, $event->code, $event->starts_on->toDateString(), $event->scope_ref, true);
        if ($latest->id !== $event->id) {
            throw new DomainStateException('Solo la última revisión del evento puede modificarse.', 'stale_revision');
        }
        $merged = array_merge([
            'scope' => $event->scope, 'scope_ref' => $this->refForImport($event), 'code' => $event->code, 'name' => $event->name, 'category' => $event->category,
            'starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString(), 'status' => $event->status,
            // El cambio lo comunica quien llama ahora: salvo que indique otra fecha, Predictily lo conoce desde este momento.
            'known_at' => now()->toIso8601String(), 'source' => $event->source,
        ], $changes);
        $this->importRows(ImportEntity::CalendarEvents, self::HEADERS, [$this->row($merged)]);
    }

    /** @param array<string, mixed> $d @return list<scalar|null> */
    private function row(array $d): array
    {
        return array_map(fn (string $h) => $d[$h] ?? null, self::HEADERS);
    }

    private function latestOf(string $scope, string $code, string $startsOn, ?string $scopeRef, bool $stored = false): CalendarEvent
    {
        $query = CalendarEvent::query()->own()->where('scope', $scope)->where('code', $code)->where('starts_on', $startsOn)->orderByDesc('revision');
        if ($stored) {
            $scopeRef === null ? $query->whereNull('scope_ref') : $query->where('scope_ref', $scopeRef);
        }

        return $query->firstOrFail();
    }

    /** Referencia tal como queda guardada (misma normalización que el importador). */
    private function storedRef(string $scope, ?string $ref): ?string
    {
        if ($ref === null || $scope === 'tenant') {
            return null;
        }

        return match ($scope) {
            'country' => strtoupper($ref),
            'branch' => ($id = Branch::query()->whereRaw('upper(code) = ?', [mb_strtoupper($ref)])->value('id')) !== null ? 'branch:'.$id : null,
            'product' => ($id = Product::query()->whereRaw('upper(sku) = ?', [mb_strtoupper($ref)])->value('id')) !== null ? 'product:'.$id : null,
            'category' => ($id = ProductCategory::query()->whereRaw('upper(name) = ?', [mb_strtoupper($ref)])->value('id')) !== null ? 'category:'.$id : null,
            'sector' => 'sector:'.ValueParser::key($ref),
            default => ValueParser::key($ref),
        };
    }

    /** Referencia en el formato que entiende el importador (código de sucursal, SKU, nombre de categoría). */
    private function refForImport(CalendarEvent $event): ?string
    {
        if ($event->scope_ref === null) {
            return null;
        }
        if (preg_match('/^(branch|product|category):(\d+)$/', $event->scope_ref, $m) === 1) {
            return match ($m[1]) {
                'branch' => Branch::query()->whereKey((int) $m[2])->value('code'),
                'product' => Product::query()->whereKey((int) $m[2])->value('sku'),
                'category' => ProductCategory::query()->whereKey((int) $m[2])->value('name'),
            };
        }

        return preg_replace('/^sector:/', '', $event->scope_ref);
    }

    /**
     * Una revisión por evento: la última, o la última conocida al corte (revisiones sin `known_at` no cuentan).
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @return Collection<int, CalendarEvent>
     */
    private function reduce(Collection $events, ?CarbonImmutable $asOf): Collection
    {
        return $events->groupBy(fn (CalendarEvent $e) => implode('|', [$e->tenant_id ?? 0, $e->scope, $e->scope_ref ?? '', $e->code, $e->starts_on->toDateString()]))
            ->map(function (Collection $revisions) use ($asOf) {
                if ($asOf !== null) {
                    $revisions = $revisions->filter(fn (CalendarEvent $e) => $e->known_at !== null && $e->known_at->lessThanOrEqualTo($asOf));
                }

                return $revisions->sortByDesc('revision')->first();
            })->filter()->sortBy(fn (CalendarEvent $e) => $e->starts_on->toDateString().$e->code)->values();
    }

    /** @return array<string, mixed> */
    private function present(CalendarEvent $e): array
    {
        return [
            'id' => $e->id, 'is_public' => $e->tenant_id === null, 'scope' => $e->scope, 'scope_ref' => $e->scope_ref, 'code' => $e->code, 'name' => $e->name,
            'category' => $e->category, 'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on->toDateString(), 'status' => $e->status,
            'known_at' => $e->known_at?->toIso8601String(), 'known_at_unknown' => $e->known_at === null, 'source' => $e->source, 'revision' => $e->revision,
        ];
    }
}
