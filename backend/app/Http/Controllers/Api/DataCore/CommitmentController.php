<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Api\DataCore\Concerns\ImportsCanonicalRows;
use App\Http\Controllers\Controller;
use App\Models\CommercialCommitment;
use App\Services\DataCore\Commitments\CommitmentAsOf;
use App\Services\DataCore\Commitments\CommitmentCancellation;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Parsing\ValueParser;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compromisos comerciales (cotización / pedido / contrato). NO son ventas realizadas ni se suman a la demanda.
 * Escritura por el importador canónico (idempotente por tipo + identificador externo); `as_of` reconstruye el estado conocido a un corte.
 */
final class CommitmentController extends Controller
{
    use ImportsCanonicalRows;

    private const HEADERS = ['commitment_type', 'external_id', 'status', 'issued_at', 'known_at', 'valid_from', 'valid_to', 'expected_delivery_date', 'cancelled_at',
        'branch_code', 'customer_code', 'currency', 'line_number', 'product_sku', 'quantity', 'quantity_fulfilled', 'unit', 'unit_price', 'delivery_date'];

    public function __construct(private readonly TenantContext $context, private readonly CommitmentAsOf $asOf, private readonly CommitmentCancellation $cancellation) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(CommercialCommitment::TYPES)], 'status' => ['sometimes', Rule::in(CommercialCommitment::STATUSES)],
            'customer_id' => ['sometimes', 'integer', TenantRule::exists('customers')], 'product_id' => ['sometimes', 'integer', TenantRule::exists('products')],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'as_of' => ['sometimes', 'date'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($data['as_of'])) {
            $tenant = $this->context->require();
            $timezone = $tenant->timezone ?: 'America/Guayaquil';
            $cutoff = CommitmentAsOf::cutoff((string) $data['as_of'], $timezone);   // AAAA-MM-DD = fin de ese día en la zona de la empresa
            $view = $this->asOf->view($tenant->id, $cutoff, array_intersect_key($data, array_flip(['type', 'product_id', 'status'])));

            return response()->json(['data' => $view, 'meta' => [
                'as_of' => $cutoff->toIso8601String(),
                'timezone' => $timezone,
                'as_of_basis' => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $data['as_of'])) === 1 ? 'end_of_day_company_timezone' : 'instant',
                'rule' => 'last_version_with_effective_known_at_lte_as_of; effective_known_at=coalesce(known_at,ingested_at); known_basis=declared|ingestion; cancelled_when_cancelled_at_lte_as_of; pending=max(0,quantity-fulfilled)',
            ]]);
        }
        $query = CommercialCommitment::query()->with('items')->orderByDesc('issued_at')->orderByDesc('id');
        foreach (['status' => 'status', 'type' => 'commitment_type', 'customer_id' => 'customer_id'] as $param => $column) {
            if (isset($data[$param])) {
                $query->where($column, $data[$param]);
            }
        }
        if (isset($data['product_id'])) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $data['product_id']));
        }
        if (isset($data['from'])) {
            $query->where('issued_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->where('issued_at', '<', CarbonImmutable::parse($data['to'])->addDay()->toDateString());
        }
        $page = $query->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CommercialCommitment $c) => $this->present($c)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(CommercialCommitment $commitment): JsonResponse
    {
        $commitment->load('items', 'revisions');

        return response()->json(['data' => $this->present($commitment) + ['revisions' => $commitment->revisions->map(fn ($r) => [
            'revision' => $r->revision, 'status' => $r->status, 'known_at' => $r->known_at?->toIso8601String(), 'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'recorded_at' => $r->recorded_at?->toIso8601String(),
        ])]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commitment_type' => ['required', 'string', 'max:40'], 'external_id' => ['required', 'string', 'max:100'], 'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'issued_at' => ['required', 'string', 'max:40'], 'known_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'cancelled_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:50'], 'customer_code' => ['sometimes', 'nullable', 'string', 'max:100'], 'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.line_number' => ['sometimes', 'nullable', 'integer', 'min:1'], 'items.*.product_sku' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required'], 'items.*.quantity_fulfilled' => ['sometimes', 'nullable'], 'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['sometimes', 'nullable'], 'items.*.delivery_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $rows = array_map(fn (array $item) => array_map(fn (string $h) => array_key_exists($h, $item) ? $item[$h] : ($data[$h] ?? null), self::HEADERS), $data['items']);
        $job = $this->importRows(ImportEntity::Commitments, self::HEADERS, $rows);
        $stats = (array) $job->stats;
        $result = match (true) {
            ($stats['commitments_new'] ?? 0) > 0 => 'created',
            ($stats['commitments_updated'] ?? 0) > 0 => 'updated',
            default => 'unchanged',
        };
        $commitment = CommercialCommitment::query()->with('items')->where('commitment_type', $this->typeOf($data['commitment_type']))->where('external_id', $data['external_id'])->firstOrFail();

        return response()->json(['data' => $this->present($commitment), 'result' => $result], $result === 'created' ? 201 : 200);
    }

    public function cancel(Request $request, CommercialCommitment $commitment): JsonResponse
    {
        $data = $request->validate(['cancelled_at' => ['sometimes', 'nullable', 'date'], 'known_at' => ['sometimes', 'nullable', 'date']]);
        $cancelled = $this->cancellation->cancel(
            $commitment,
            isset($data['cancelled_at']) ? CarbonImmutable::parse($data['cancelled_at']) : null,
            isset($data['known_at']) ? CarbonImmutable::parse($data['known_at']) : null,
        );

        return response()->json(['data' => $this->present($cancelled->load('items'))]);
    }

    private function typeOf(string $raw): string
    {
        $k = ValueParser::key($raw);

        return match (true) {
            in_array($k, ['cotizacion', 'proforma', 'presupuesto', 'oferta', 'cot'], true) => 'quote',
            in_array($k, ['pedido', 'orden', 'orden_de_venta', 'pedido_confirmado', 'orden_de_compra', 'sales_order', 'oc'], true) => 'order',
            in_array($k, ['contrato', 'convenio', 'acuerdo_marco'], true) => 'contract',
            default => $k,
        };
    }

    /** @return array<string, mixed> */
    private function present(CommercialCommitment $c): array
    {
        $cancelled = $c->status === 'cancelled';
        $items = $c->items->map(fn ($i) => [
            'line_number' => $i->line_number, 'product_id' => $i->product_id, 'quantity' => $i->quantity, 'quantity_fulfilled' => $i->quantity_fulfilled,
            'pending' => $cancelled ? 0.0 : round(max(0.0, $i->quantity - $i->quantity_fulfilled), 4), 'unit' => $i->unit, 'unit_price' => $i->unit_price,
            'delivery_date' => $i->delivery_date?->toDateString(), 'source_quantity' => $i->source_quantity, 'source_unit' => $i->source_unit, 'conversion_factor' => $i->conversion_factor,
        ]);

        return [
            'id' => $c->id, 'commitment_type' => $c->commitment_type, 'external_id' => $c->external_id, 'status' => $c->status,
            'issued_at' => $c->issued_at?->toIso8601String(), 'known_at' => $c->known_at?->toIso8601String(), 'known_at_unknown' => $c->known_at === null,
            'valid_from' => $c->valid_from?->toDateString(), 'valid_to' => $c->valid_to?->toDateString(), 'expected_delivery_date' => $c->expected_delivery_date?->toDateString(),
            'cancelled_at' => $c->cancelled_at?->toIso8601String(), 'cancellation_date_unknown' => $cancelled && $c->cancelled_at === null,
            'currency' => $c->currency, 'customer_id' => $c->customer_id, 'branch_id' => $c->branch_id, 'data_source_id' => $c->data_source_id,
            'items' => $items, 'pending_total' => round((float) $items->sum('pending'), 4),
        ];
    }
}
