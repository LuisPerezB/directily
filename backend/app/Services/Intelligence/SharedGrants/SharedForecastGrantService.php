<?php

declare(strict_types=1);

namespace App\Services\Intelligence\SharedGrants;

use App\Models\ForecastRun;
use App\Models\ForecastValue;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SharedForecastGrant;
use App\Models\SharedForecastGrantAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Concesiones de pronóstico entre empresas autorizadas (V2.1 §10; V2.5 §24.10; T14; ADR-V25-25). DISEÑADO Y DESACTIVADO: con la bandera
 * `intelligence.shared_forecast_grants.enabled` apagada (por defecto) este servicio se niega a operar y las rutas responden 404.
 *
 * Reglas del contrato:
 * - Emisor ≠ receptor; el receptor es una empresa activa; el emisor solo concede productos o categorías SUYOS.
 * - Periodo, granularidad y contenido permitido acotan lo que el receptor lee: solo `point_forecast`, `interval_80` e `interval_95`. NUNCA ventas,
 *   clientes, precios, ingresos ni costos del emisor (no están en la lista permitida y la API rechaza pedirlos).
 * - Vence (`expires_at`) y se revoca (`revoked_at`): tras cualquiera de los dos el receptor no lee nada.
 * - Cada intento queda en la bitácora: `read` o `denied_expired | denied_revoked | denied_scope`. Un tercero (ni emisor ni receptor) recibe un 404
 *   idéntico al de una concesión inexistente y su intento se registra como `denied_scope`.
 * - Un pronóstico compartido NO es un pedido confirmado: la respuesta lo declara (`is_confirmed_order = false`, `counts_as_committed_demand = false`)
 *   y no escribe en compromisos comerciales ni en ninguna tabla del receptor. Reconciliar con pedidos confirmados evitando sumas dobles es del consumidor.
 *
 * El cruce entre empresas está confinado a `read()`: consulta con `withoutTenantScope()` pero siempre con `tenant_id` = emisor de la concesión y la
 * ejecución pinneada en `forecast_version`; solo proyecta las columnas permitidas.
 */
final class SharedForecastGrantService
{
    public const NOT_A_CONFIRMED_ORDER = 'Pronóstico compartido por otra empresa: NO es un pedido confirmado ni demanda comprometida. No lo sume a pedidos o compromisos del receptor; reconcilie contra los pedidos efectivamente confirmados para evitar doble conteo (V2.1 §10).';

    /** Contenido concedible → columnas de forecast_values que lo componen. */
    private const CONTENT_COLUMNS = [
        'point_forecast' => ['yhat'],
        'interval_80' => ['lo_80', 'hi_80'],
        'interval_95' => ['lo_95', 'hi_95'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Fail-closed: la función solo existe si la bandera es un booleano reconocible y verdadero. Cualquier otra cosa (texto como «no» u «off», vacío, null, un
     * valor irreconocible) la deja apagada; nunca se usa un `(bool)` directo, para el que «no» sería verdadero.
     */
    public static function isEnabled(): bool
    {
        return filter_var(config('intelligence.shared_forecast_grants.enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    public function enabled(): bool
    {
        return self::isEnabled();
    }

    /** Bandera apagada ⇒ la funcionalidad no existe: 404 idéntico al de una ruta inexistente. */
    public function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new NotFoundHttpException('Ruta no encontrada.');
        }
    }

    // ── Emisor ──

    /** @param array<string, mixed> $data campos validados */
    public function create(Tenant $issuer, User $user, array $data): SharedForecastGrant
    {
        $this->ensureEnabled();
        $recipient = Tenant::query()->where('uuid', $data['recipient_tenant_uuid'])->first();
        if ($recipient === null || $recipient->status !== 'active') {
            throw new IntelligenceRuleException('El receptor no es una empresa activa de Predictily.', 'recipient_not_found', 422);
        }
        if ($recipient->id === $issuer->id) {
            throw new IntelligenceRuleException('El emisor y el receptor deben ser empresas distintas.', 'recipient_must_differ', 422);
        }
        $subjects = array_values(array_unique(array_map('intval', $data['subjects'])));
        $this->assertSubjectsBelongToIssuer($data['scope'], $subjects);
        $this->assertContentAllowed($data['allowed_content']);
        $expiresAt = CarbonImmutable::parse((string) $data['expires_at']);
        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new IntelligenceRuleException('El vencimiento debe ser posterior a la fecha actual.', 'expires_in_the_past', 422);
        }
        $version = $this->resolveForecastVersion($data['forecast_version'] ?? null);

        $grant = new SharedForecastGrant;
        $grant->forceFill([
            'issuer_tenant_id' => $issuer->id, 'recipient_tenant_id' => $recipient->id, 'scope' => $data['scope'], 'subjects' => $subjects,
            'period_from' => $data['period_from'], 'period_to' => $data['period_to'], 'granularity' => $data['granularity'],
            'allowed_content' => array_values(array_unique($data['allowed_content'])), 'forecast_version' => $version, 'issued_at' => now(),
            'expires_at' => $expiresAt, 'purpose' => $data['purpose'], 'status' => SharedForecastGrant::STATUS_DRAFT, 'created_by' => $user->id,
        ])->save();
        $this->audit->log('intelligence.shared_grant_created', null, [
            'grant_id' => $grant->id, 'recipient_tenant_id' => $recipient->id, 'scope' => $grant->scope, 'subjects_count' => count($subjects),
            'granularity' => $grant->granularity, 'allowed_content' => $grant->allowed_content,
        ], $issuer->id, $user->id);

        return $grant->refresh();
    }

    /** Borrador → activa: desde aquí el receptor puede leer, dentro del alcance y hasta el vencimiento. */
    public function activate(Tenant $issuer, User $user, SharedForecastGrant $grant): SharedForecastGrant
    {
        $this->ensureEnabled();
        $this->assertIssuer($issuer, $grant);
        if ($grant->status !== SharedForecastGrant::STATUS_DRAFT) {
            throw new IntelligenceRuleException('Solo una concesión en borrador se puede activar.', 'invalid_grant_state', 409, ['status' => $grant->status]);
        }
        if ($grant->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new IntelligenceRuleException('El vencimiento ya pasó: cree una concesión nueva.', 'expires_in_the_past', 422);
        }
        $recipient = Tenant::query()->find($grant->recipient_tenant_id);
        if ($recipient === null || $recipient->status !== 'active') {
            throw new IntelligenceRuleException('El receptor ya no es una empresa activa.', 'recipient_not_found', 422);
        }
        $version = $this->resolveForecastVersion($grant->forecast_version);
        if ($version === null) {
            throw new IntelligenceRuleException('El emisor no tiene un pronóstico completado para compartir.', 'no_forecast_to_share', 422);
        }
        $grant->forceFill(['status' => SharedForecastGrant::STATUS_ACTIVE, 'issued_at' => now(), 'forecast_version' => $version])->save();
        $this->audit->log('intelligence.shared_grant_activated', null, ['grant_id' => $grant->id, 'recipient_tenant_id' => $grant->recipient_tenant_id], $issuer->id, $user->id);

        return $grant->refresh();
    }

    /** Revocación inmediata: a partir de aquí cada lectura del receptor se rechaza y se registra como `denied_revoked`. */
    public function revoke(Tenant $issuer, User $user, SharedForecastGrant $grant): SharedForecastGrant
    {
        $this->ensureEnabled();
        $this->assertIssuer($issuer, $grant);
        if (! in_array($grant->status, [SharedForecastGrant::STATUS_DRAFT, SharedForecastGrant::STATUS_ACTIVE], true)) {
            throw new IntelligenceRuleException('La concesión ya está revocada o vencida.', 'invalid_grant_state', 409, ['status' => $grant->status]);
        }
        $grant->forceFill(['status' => SharedForecastGrant::STATUS_REVOKED, 'revoked_at' => now(), 'revoked_by' => $user->id])->save();
        $this->audit->log('intelligence.shared_grant_revoked', null, ['grant_id' => $grant->id, 'recipient_tenant_id' => $grant->recipient_tenant_id], $issuer->id, $user->id);

        return $grant->refresh();
    }

    /** Marca como vencidas las concesiones activas cuyo vencimiento pasó. No depende del contexto de empresa (comando programado). */
    public function expireDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return DB::table('shared_forecast_grants')->where('status', SharedForecastGrant::STATUS_ACTIVE)->where('expires_at', '<=', $now)
            ->update(['status' => SharedForecastGrant::STATUS_EXPIRED, 'updated_at' => $now]);
    }

    // ── Receptor ──

    /**
     * Lectura del pronóstico compartido por el receptor. Todo intento deja una fila en la bitácora antes de responder o rechazar.
     *
     * @param  array{subject_id?: int|null, from?: string|null, to?: string|null, granularity?: string|null, include?: list<string>|null}  $filters
     * @return array{grant: array<string, mixed>, data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function read(Tenant $viewer, User $user, int $grantId, array $filters = []): array
    {
        $this->ensureEnabled();
        $grant = SharedForecastGrant::withoutParties()->find($grantId);
        if ($grant === null) {
            $this->audit->log('intelligence.shared_grant_denied', null, ['grant_id' => $grantId, 'reason' => 'not_found'], $viewer->id, $user->id);
            throw new NotFoundHttpException('Recurso no encontrado.');
        }
        // Un tercero (o el propio emisor por esta vía) no es el receptor: 404 idéntico al de una concesión inexistente, e intento registrado.
        // Un borrador tampoco es visible para el receptor.
        if ($grant->recipient_tenant_id !== $viewer->id || $grant->status === SharedForecastGrant::STATUS_DRAFT) {
            $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_DENIED_SCOPE);
            $this->audit->log('intelligence.shared_grant_denied', null, ['grant_id' => $grant->id, 'reason' => 'not_recipient'], $viewer->id, $user->id);
            throw new NotFoundHttpException('Recurso no encontrado.');
        }
        $now = CarbonImmutable::now();
        $grant->loadMissing('issuer:id,uuid,name');
        $status = $grant->effectiveStatus($now);
        if ($status === SharedForecastGrant::STATUS_REVOKED) {
            $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_DENIED_REVOKED);
            throw new IntelligenceRuleException('El emisor revocó esta concesión.', 'grant_revoked', 403);
        }
        if ($status === SharedForecastGrant::STATUS_EXPIRED) {
            $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_DENIED_EXPIRED);
            throw new IntelligenceRuleException('La concesión venció.', 'grant_expired', 403);
        }
        // Fail-closed: si la empresa emisora ya no está activa (suspendida o con eliminación en curso) no se sirve su pronóstico.
        if (Tenant::query()->whereKey($grant->issuer_tenant_id)->value('status') !== 'active') {
            $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_DENIED_SCOPE);
            throw new IntelligenceRuleException('El emisor no está disponible.', 'issuer_unavailable', 403);
        }
        $violations = $this->scopeViolations($grant, $filters);
        if ($violations !== []) {
            $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_DENIED_SCOPE);
            throw new IntelligenceRuleException('La solicitud excede lo concedido.', 'outside_grant_scope', 403, ['outside' => $violations]);
        }

        $data = $this->forecastRows($grant, $filters);
        $this->logAccess($grant, $viewer, $user, SharedForecastGrantAccess::ACTION_READ);

        return [
            'grant' => $this->presentForRecipient($grant, $now),
            'data' => $data,
            'meta' => [
                'kind' => 'shared_projection',
                'is_confirmed_order' => false,
                'counts_as_committed_demand' => false,
                'notice' => self::NOT_A_CONFIRMED_ORDER,
                'granularity' => $grant->granularity,
                'period_from' => $grant->period_from->toDateString(),
                'period_to' => $grant->period_to->toDateString(),
                'forecast_version' => $grant->forecast_version,
                'content' => $grant->allowed_content,
                'forecast_available' => $data !== [],
                'period_rule' => 'Solo se sirven periodos (día, semana o mes) totalmente contenidos en el periodo concedido y en el pedido; un periodo que lo excede en cualquier extremo no se sirve.',
            ],
        ];
    }

    /** Concesiones dirigidas a la empresa (sin borradores): lo que ve el receptor. */
    public function receivedBy(Tenant $viewer): Builder
    {
        return SharedForecastGrant::query()->where('recipient_tenant_id', $viewer->id)->where('status', '<>', SharedForecastGrant::STATUS_DRAFT)->with('issuer:id,uuid,name')->orderByDesc('id');
    }

    /** Concesiones emitidas por la empresa. */
    public function issuedBy(Tenant $issuer): Builder
    {
        return SharedForecastGrant::query()->where('issuer_tenant_id', $issuer->id)->with('recipient:id,uuid')->orderByDesc('id');
    }

    public function assertIssuer(Tenant $tenant, SharedForecastGrant $grant): void
    {
        if ($grant->issuer_tenant_id !== $tenant->id) {
            // Para el receptor la vía de administración no existe: mismo 404 que si no pudiera ver la concesión.
            throw new NotFoundHttpException('Recurso no encontrado.');
        }
    }

    /** @return array<string, mixed> */
    public function presentForIssuer(SharedForecastGrant $g, CarbonImmutable $now): array
    {
        $counts = SharedForecastGrantAccess::query()->where('grant_id', $g->id)->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action')->all();

        return [
            'id' => $g->id,
            'recipient' => ['uuid' => $g->relationLoaded('recipient') ? $g->recipient?->uuid : null],
            'scope' => $g->scope, 'subjects' => $g->subjects, 'period_from' => $g->period_from->toDateString(), 'period_to' => $g->period_to->toDateString(),
            'granularity' => $g->granularity, 'allowed_content' => $g->allowed_content, 'forecast_version' => $g->forecast_version,
            'issued_at' => $g->issued_at->toIso8601String(), 'expires_at' => $g->expires_at->toIso8601String(), 'purpose' => $g->purpose,
            'status' => $g->status, 'effective_status' => $g->effectiveStatus($now), 'revoked_at' => $g->revoked_at?->toIso8601String(),
            'accesses' => ['read' => (int) ($counts['read'] ?? 0), 'denied' => (int) array_sum(array_diff_key($counts, ['read' => 1]))],
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> el receptor no recibe los identificadores internos del emisor, solo su alcance y vigencia */
    public function presentForRecipient(SharedForecastGrant $g, CarbonImmutable $now): array
    {
        return [
            'id' => $g->id,
            'issuer' => $g->relationLoaded('issuer') && $g->issuer !== null ? ['uuid' => $g->issuer->uuid, 'name' => $g->issuer->name] : null,
            'scope' => $g->scope, 'subjects_count' => count($g->subjects), 'period_from' => $g->period_from->toDateString(), 'period_to' => $g->period_to->toDateString(),
            'granularity' => $g->granularity, 'allowed_content' => $g->allowed_content, 'forecast_version' => $g->forecast_version,
            'issued_at' => $g->issued_at->toIso8601String(), 'expires_at' => $g->expires_at->toIso8601String(), 'purpose' => $g->purpose,
            'status' => $g->effectiveStatus($now), 'revoked_at' => $g->revoked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> bitácora hacia el emisor: sin identificadores del receptor ni de terceros (solo si fue el receptor u otra empresa) */
    public function presentAccess(SharedForecastGrantAccess $a, SharedForecastGrant $grant): array
    {
        return [
            'id' => $a->id, 'action' => $a->action, 'accessed_at' => $a->accessed_at->toIso8601String(),
            'actor' => $a->accessed_by_tenant_id === $grant->recipient_tenant_id ? 'recipient' : ($a->accessed_by_tenant_id === $grant->issuer_tenant_id ? 'issuer' : 'other_company'),
        ];
    }

    // ── Internos ──

    /** @param list<int> $subjects */
    private function assertSubjectsBelongToIssuer(string $scope, array $subjects): void
    {
        $max = (int) config('intelligence.shared_forecast_grants.max_subjects', 200);
        if (count($subjects) > $max) {
            throw new IntelligenceRuleException("Una concesión admite como máximo {$max} elementos.", 'too_many_subjects', 422);
        }
        // Product/ProductCategory tienen BelongsToTenant: con el emisor en contexto solo existen sus propios identificadores.
        $found = $scope === 'product'
            ? Product::query()->whereIn('id', $subjects)->count()
            : ProductCategory::query()->whereIn('id', $subjects)->count();
        if ($found !== count($subjects)) {
            throw new IntelligenceRuleException('Algún elemento a conceder no existe en la empresa emisora.', 'subjects_not_found', 422);
        }
    }

    /** @param list<string> $content */
    private function assertContentAllowed(array $content): void
    {
        $allowed = (array) config('intelligence.shared_forecast_grants.allowed_content', []);
        $invalid = array_values(array_diff($content, $allowed));
        if ($content === [] || $invalid !== []) {
            throw new IntelligenceRuleException(
                'Contenido no concedible: '.($invalid === [] ? 'vacío' : implode(', ', $invalid)).'. Solo se pueden conceder '.implode(', ', $allowed).'; las ventas y los clientes del emisor nunca se comparten.',
                'content_not_allowed', 422, ['allowed' => $allowed],
            );
        }
    }

    /** Versión (correlativo) de una ejecución completada de producción del emisor; sin valor, la vigente. */
    private function resolveForecastVersion(mixed $requested): ?string
    {
        $query = ForecastRun::query()->where('status', ForecastRun::STATUS_COMPLETED)->where('run_type', ForecastRun::TYPE_PRODUCTION);
        if ($requested !== null && $requested !== '') {
            $version = $query->where('version', (int) $requested)->value('version');
            if ($version === null) {
                throw new IntelligenceRuleException('La versión de pronóstico indicada no existe o no está completada en la empresa emisora.', 'forecast_version_not_found', 422);
            }

            return (string) $version;
        }
        $current = (clone $query)->where('is_current', true)->value('version');

        return $current === null ? null : (string) $current;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string> partes de la solicitud que exceden lo concedido
     */
    private function scopeViolations(SharedForecastGrant $grant, array $filters): array
    {
        $out = [];
        if (($filters['subject_id'] ?? null) !== null && ! in_array((int) $filters['subject_id'], array_map('intval', $grant->subjects), true)) {
            $out[] = 'subject';
        }
        if (($filters['from'] ?? null) !== null && CarbonImmutable::parse((string) $filters['from'])->toDateString() < $grant->period_from->toDateString()) {
            $out[] = 'period';
        }
        if (($filters['to'] ?? null) !== null && CarbonImmutable::parse((string) $filters['to'])->toDateString() > $grant->period_to->toDateString()) {
            $out[] = 'period';
        }
        if (($filters['granularity'] ?? null) !== null && $filters['granularity'] !== $grant->granularity) {
            $out[] = 'granularity';
        }
        if (! empty($filters['include']) && array_diff((array) $filters['include'], $grant->allowed_content) !== []) {
            $out[] = 'content';
        }

        return array_values(array_unique($out));
    }

    /**
     * Filas del pronóstico del EMISOR dentro del alcance: solo la ejecución pinneada, el nivel (producto o categoría) y los elementos concedidos,
     * el periodo y la granularidad, y solo las columnas del contenido permitido. No lee ventas, clientes, precios ni ingresos.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function forecastRows(SharedForecastGrant $grant, array $filters): array
    {
        $run = ForecastRun::withoutTenantScope()->where('tenant_id', $grant->issuer_tenant_id)->where('version', (int) $grant->forecast_version)
            ->where('status', ForecastRun::STATUS_COMPLETED)->where('run_type', ForecastRun::TYPE_PRODUCTION)->first(['id', 'tenant_id']);
        if ($run === null) {
            return [];
        }
        $isProduct = $grant->scope === 'product';
        $subjectColumn = $isProduct ? 'product_id' : 'category_id';
        $subjects = array_map('intval', $grant->subjects);
        if (($filters['subject_id'] ?? null) !== null) {
            $subjects = [(int) $filters['subject_id']];
        }
        $from = CarbonImmutable::parse((string) ($filters['from'] ?? $grant->period_from->toDateString()))->toDateString();
        $to = CarbonImmutable::parse((string) ($filters['to'] ?? $grant->period_to->toDateString()))->toDateString();
        $wanted = ! empty($filters['include']) ? array_values((array) $filters['include']) : $grant->allowed_content;
        $columns = array_values(array_unique(array_merge(...array_map(fn (string $c) => self::CONTENT_COLUMNS[$c] ?? [], $wanted))));

        $rows = ForecastValue::withoutTenantScope()
            ->where('tenant_id', $grant->issuer_tenant_id)->where('forecast_run_id', $run->id)
            ->where('level', $isProduct ? 'product' : 'category')->where('granularity', $grant->granularity)
            ->whereIn($subjectColumn, $subjects)
            // Solo periodos TOTALMENTE contenidos en lo concedido y en lo pedido: una semana o un mes cuyo inicio está dentro pero cuyo final excede el periodo
            // (o que empieza antes) incluye días no concedidos. `target_date` es el primer día del periodo y `period_days` su duración.
            ->where('target_date', '>=', $from)->whereRaw('(target_date + (period_days::int - 1)) <= ?', [$to])
            ->orderBy($subjectColumn)->orderBy('target_date')
            ->get(array_merge([$subjectColumn, 'target_date', 'period_days'], $columns));

        $labels = $isProduct
            ? Product::withoutTenantScope()->where('tenant_id', $grant->issuer_tenant_id)->whereIn('id', $subjects)->get(['id', 'sku', 'name'])->keyBy('id')
            : ProductCategory::withoutTenantScope()->where('tenant_id', $grant->issuer_tenant_id)->whereIn('id', $subjects)->get(['id', 'name'])->keyBy('id');

        return $rows->map(function (ForecastValue $v) use ($grant, $isProduct, $subjectColumn, $labels, $wanted): array {
            $label = $labels->get($v->{$subjectColumn});
            $row = [
                'kind' => 'shared_projection',
                'subject' => array_filter(['type' => $grant->scope, 'id' => $v->{$subjectColumn}, 'sku' => $isProduct ? $label?->sku : null, 'name' => $label?->name], fn ($x) => $x !== null),
                'period_start' => $v->target_date->toDateString(),
                'period_days' => (int) $v->period_days,
            ];
            if (in_array('point_forecast', $wanted, true)) {
                $row['point_forecast'] = $v->yhat;
            }
            if (in_array('interval_80', $wanted, true)) {
                $row['interval_80'] = ['lo' => $v->lo_80, 'hi' => $v->hi_80];
            }
            if (in_array('interval_95', $wanted, true)) {
                $row['interval_95'] = ['lo' => $v->lo_95, 'hi' => $v->hi_95];
            }

            return $row;
        })->values()->all();
    }

    private function logAccess(SharedForecastGrant $grant, Tenant $viewer, User $user, string $action): void
    {
        SharedForecastGrantAccess::query()->create([
            'grant_id' => $grant->id, 'accessed_by_tenant_id' => $viewer->id, 'user_id' => $user->id, 'action' => $action, 'accessed_at' => now(),
        ]);
    }
}
