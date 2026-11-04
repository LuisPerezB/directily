<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\ExternalEvent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxonomyNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eventos externos manuales con procedencia (V2.1 §24.6; ADR-V25-25). Flujo PROPOSED → VERIFIED | REJECTED; EXPIRED al vencer la vigencia.
 *
 * Reglas:
 * - Un evento nuevo siempre nace PROPOSED y a nombre de la empresa. Se registra quién lo propuso (`proposed_by`).
 * - SEGREGACIÓN: quien lo propuso no puede verificarlo ni rechazarlo (otro usuario con permiso). Si no tiene proponente (conector o LLM, no
 *   disponibles en este paquete), cualquier revisor autorizado puede resolverlo.
 * - Sin `published_at` no puede verificarse para uso predictivo (INVALID como feature); tampoco sin URL, autoridad y jurisdicción (procedencia completa).
 * - Solo un evento PROPOSED se edita o se borra; uno resuelto es inmutable (la corrección es proponer otro).
 * - `available_at` (cuándo pudo usarlo el sistema) se fija al verificar: un evento tecleado hoy no existía para un backtest de ayer, aunque se
 *   haya publicado hace meses.
 * - Las alertas informativas (is_informative_alert) siguen el mismo flujo, se listan aparte y no son features predictivas.
 * Sin integraciones externas ni LLM: `extracted_by` es siempre «manual».
 */
final class ExternalEventService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data campos validados */
    public function propose(User $user, array $data): ExternalEvent
    {
        $this->assertPublishedNotFuture($data['published_at'] ?? null);
        $this->assertAffectsVisible($data['affects'] ?? []);
        $event = new ExternalEvent;
        $event->fill($this->attributes($data));
        $event->forceFill(['status' => ExternalEvent::STATUS_PROPOSED, 'extracted_by' => 'manual', 'proposed_by' => $user->id]);
        $event->save();
        $this->audit->log('intelligence.event_proposed', $event, ['family' => $event->family, 'is_informative_alert' => $event->is_informative_alert, 'has_published_at' => $event->published_at !== null]);

        return $event->refresh();
    }

    /**
     * Edita un evento propuesto. Serializa con verify/reject (bloqueo de la fila): una edición queda antes o después de una verificación, nunca en medio.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, ExternalEvent $event, array $data): ExternalEvent
    {
        if (array_key_exists('published_at', $data)) {
            $this->assertPublishedNotFuture($data['published_at']);
        }
        if (array_key_exists('affects', $data)) {
            $this->assertAffectsVisible($data['affects'] ?? []);
        }

        return DB::transaction(function () use ($actor, $event, $data): ExternalEvent {
            $event = $this->lock($event);
            $this->assertStatus($event, [ExternalEvent::STATUS_PROPOSED], 'Solo un evento propuesto se puede editar; uno resuelto es inmutable.');
            $this->assertProposerOwnsProposal($event, $actor);
            $event->fill($this->attributes($data))->save();
            $this->audit->log('intelligence.event_updated', $event, ['changed' => array_keys($data)]);

            return $event->refresh();
        });
    }

    public function delete(User $actor, ExternalEvent $event): void
    {
        DB::transaction(function () use ($actor, $event): void {
            $event = $this->lock($event);
            $this->assertStatus($event, [ExternalEvent::STATUS_PROPOSED, ExternalEvent::STATUS_REJECTED], 'Solo se borra un evento propuesto o rechazado; uno verificado o vencido se conserva como evidencia.');
            if ($event->status === ExternalEvent::STATUS_PROPOSED) {
                $this->assertProposerOwnsProposal($event, $actor);
            }
            $this->audit->log('intelligence.event_deleted', $event, ['status' => $event->status, 'family' => $event->family]);
            $event->delete();
        });
    }

    /**
     * Verifica un evento propuesto. El revisor debe indicar la versión que REVISÓ (`$reviewToken`, devuelta por la API en `review_token`): si el contenido cambió
     * después de su lectura se rechaza (409 `event_changed_since_review`) en vez de verificar algo que no vio. La comparación se hace con la fila bloqueada, de
     * modo que una edición concurrente tampoco se cuela entre la comprobación y la verificación.
     */
    public function verify(Tenant $tenant, User $reviewer, ExternalEvent $event, ?string $reviewToken, ?string $note = null, ?string $corroboration = null): ExternalEvent
    {
        return DB::transaction(function () use ($tenant, $reviewer, $event, $reviewToken, $note, $corroboration): ExternalEvent {
            $event = $this->lock($event);
            $this->assertStatus($event, [ExternalEvent::STATUS_PROPOSED], 'Solo se puede verificar un evento propuesto.');
            $this->assertSegregation($event, $reviewer);
            $this->assertReviewedVersion($event, $reviewToken);
            $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
            if ($event->isPastValidity($today)) {
                throw new IntelligenceRuleException('La vigencia del evento ya terminó: no puede verificarse (vence como EXPIRED).', 'event_expired', 422);
            }
            $missing = $this->missingProvenance($event);
            if ($missing !== []) {
                throw new IntelligenceRuleException(
                    in_array('published_at', $missing, true)
                        ? 'Sin fecha de publicación (published_at) el evento no puede verificarse para uso predictivo: sería INVALID como feature.'
                        : 'La procedencia está incompleta: falta '.implode(', ', $missing).'.',
                    in_array('published_at', $missing, true) ? 'published_at_required' : 'provenance_incomplete',
                    422,
                    ['missing' => $missing],
                );
            }
            $now = CarbonImmutable::now();
            // Disponible para el sistema desde que se verifica o desde que se publicó, lo que ocurra DESPUÉS (la publicación puede estar unos minutos en el futuro por desfase de reloj).
            $available = $event->published_at !== null && $event->published_at->greaterThan($now) ? CarbonImmutable::instance($event->published_at) : $now;
            $event->forceFill([
                'status' => ExternalEvent::STATUS_VERIFIED, 'verified_by' => $reviewer->id, 'verified_at' => $now, 'available_at' => $available,
                'review_note' => $note, 'corroboration' => $corroboration ?? $event->corroboration,
            ])->save();
            $this->audit->log('intelligence.event_verified', $event, ['family' => $event->family, 'corroboration' => $event->corroboration, 'is_informative_alert' => $event->is_informative_alert]);

            return $event->refresh();
        });
    }

    /** Rechaza un evento propuesto; exige, igual que verify, la versión que el revisor leyó (`review_token`). */
    public function reject(User $reviewer, ExternalEvent $event, string $reason, ?string $reviewToken): ExternalEvent
    {
        return DB::transaction(function () use ($reviewer, $event, $reason, $reviewToken): ExternalEvent {
            $event = $this->lock($event);
            $this->assertStatus($event, [ExternalEvent::STATUS_PROPOSED], 'Solo se puede rechazar un evento propuesto.');
            $this->assertSegregation($event, $reviewer);
            $this->assertReviewedVersion($event, $reviewToken);
            $event->forceFill(['status' => ExternalEvent::STATUS_REJECTED, 'verified_by' => $reviewer->id, 'verified_at' => now(), 'review_note' => $reason])->save();
            $this->audit->log('intelligence.event_rejected', $event, ['family' => $event->family]);

            return $event->refresh();
        });
    }

    /**
     * Huella (40 hex) del CONTENIDO revisable del evento y su estado: lo que un revisor ve al leerlo. Cambia si cambia cualquiera de esos campos; no cambia por
     * guardar sin cambios ni por el orden de las claves de `affects`.
     */
    public static function reviewToken(ExternalEvent $e): string
    {
        $payload = [
            'family' => $e->family, 'event_type' => $e->event_type, 'title' => $e->title, 'summary' => $e->summary, 'source_url' => $e->source_url, 'source_ref' => $e->source_ref,
            'authority' => $e->authority, 'published_at' => $e->published_at?->clone()->utc()->format('Y-m-d\TH:i:s.u'),
            'effective_from' => $e->effective_from?->toDateString(), 'effective_to' => $e->effective_to?->toDateString(), 'jurisdiction' => $e->jurisdiction,
            'corroboration' => $e->corroboration, 'affects' => self::sortKeys((array) ($e->affects ?? [])), 'is_informative_alert' => (bool) $e->is_informative_alert, 'status' => $e->status,
        ];

        return substr(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 40);
    }

    /** @param array<int|string, mixed> $value */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortKeys($v);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** Relee la fila con bloqueo (`FOR UPDATE`) dentro de la transacción: el estado y el contenido que se comprueban son los que se van a escribir. */
    private function lock(ExternalEvent $event): ExternalEvent
    {
        return ExternalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    private function assertReviewedVersion(ExternalEvent $event, ?string $reviewToken): void
    {
        if ($reviewToken === null || trim($reviewToken) === '') {
            throw new IntelligenceRuleException('Indique la versión revisada (review_token del evento, en el cuerpo o en If-Match): verificar o rechazar exige haber visto el contenido actual.', 'review_token_required', 422);
        }
        if (! hash_equals(self::reviewToken($event), trim($reviewToken))) {
            throw new IntelligenceRuleException('El evento cambió después de que usted lo leyó: vuelva a revisarlo antes de verificarlo o rechazarlo.', 'event_changed_since_review', 409, ['current_review_token' => self::reviewToken($event)]);
        }
    }

    /**
     * Marca EXPIRED los eventos PROPOSED o VERIFIED cuya vigencia terminó (día local de su empresa; los públicos, hora de Ecuador). No toca los
     * rechazados. Devuelve cuántos cambió. Es independiente del contexto de empresa (comando programado).
     */
    public function expireDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return DB::update(
            "UPDATE external_events SET status = 'EXPIRED', updated_at = ?::timestamptz
             WHERE status IN ('PROPOSED', 'VERIFIED') AND effective_to IS NOT NULL
               AND effective_to < timezone(COALESCE((SELECT t.timezone FROM tenants t WHERE t.id = external_events.tenant_id), 'America/Guayaquil'), ?::timestamptz)::date",
            [$now->toIso8601String(), $now->toIso8601String()],
        );
    }

    /**
     * Estado efectivo: un evento PROPOSED/VERIFIED con vigencia terminada es EXPIRED aunque el proceso que lo marca aún no haya corrido.
     *
     * @return array<string, mixed>
     */
    public function present(ExternalEvent $e, Tenant $tenant): array
    {
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $pastValidity = in_array($e->status, [ExternalEvent::STATUS_PROPOSED, ExternalEvent::STATUS_VERIFIED], true) && $e->isPastValidity($today);
        $effective = $pastValidity ? ExternalEvent::STATUS_EXPIRED : $e->status;
        $usable = $effective === ExternalEvent::STATUS_VERIFIED && $e->published_at !== null && ! $e->is_informative_alert;

        return [
            'id' => $e->id,
            'scope' => $e->isPublic() ? 'public' : 'tenant',
            'family' => $e->family,
            'event_type' => $e->event_type,
            'title' => $e->title,
            'summary' => $e->summary,
            'source_url' => $e->source_url,
            'source_ref' => $e->source_ref,
            'authority' => $e->authority,
            'published_at' => $e->published_at?->toIso8601String(),
            'available_at' => $e->available_at?->toIso8601String(),
            'effective_from' => $e->effective_from?->toDateString(),
            'effective_to' => $e->effective_to?->toDateString(),
            'jurisdiction' => $e->jurisdiction,
            'corroboration' => $e->corroboration,
            'status' => $e->status,
            'effective_status' => $effective,
            'extracted_by' => $e->extracted_by,
            'affects' => (object) ($e->affects ?? []),
            'is_informative_alert' => $e->is_informative_alert,
            // Evento que el motor puede tratar como feature: verificado, con fecha de publicación, vigente y no informativo.
            'usable_for_prediction' => $usable,
            // Versión revisable: el revisor la devuelve al verificar o rechazar para demostrar que vio el contenido actual.
            'review_token' => self::reviewToken($e),
            'validity_state' => $e->published_at === null ? 'INVALID' : ($usable ? 'AVAILABLE' : 'NOT_USABLE'),
            'proposed_by' => $e->proposed_by,
            'reviewed_by' => $e->verified_by,
            'reviewed_at' => $e->verified_at?->toIso8601String(),
            'review_note' => $e->review_note,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function missingProvenance(ExternalEvent $event): array
    {
        $missing = [];
        if ($event->published_at === null) {
            $missing[] = 'published_at';
        }
        foreach (['source_url' => 'source_url', 'authority' => 'authority', 'jurisdiction' => 'jurisdiction'] as $column => $label) {
            if (trim((string) $event->getAttribute($column)) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Solo quien propuso el evento lo edita o retira mientras está en revisión. Si otro usuario pudiera cambiar su contenido y luego verificarlo, la
     * segregación proponer/verificar sería ilusoria: la corrección ajena se hace proponiendo otro evento. Un evento sin proponente (conector o LLM,
     * que hoy no existen) lo puede editar cualquier usuario autorizado.
     */
    private function assertProposerOwnsProposal(ExternalEvent $event, User $actor): void
    {
        if ($event->proposed_by !== null && (int) $event->proposed_by !== (int) $actor->id) {
            throw new IntelligenceRuleException('Solo quien propuso el evento puede editarlo o retirarlo mientras espera revisión; proponga otro evento si necesita corregirlo.', 'only_proposer_can_change', 403);
        }
    }

    private function assertSegregation(ExternalEvent $event, User $reviewer): void
    {
        if ($event->proposed_by !== null && (int) $event->proposed_by === (int) $reviewer->id) {
            throw new IntelligenceRuleException('Segregación de funciones: quien propuso el evento no puede verificarlo ni rechazarlo; debe hacerlo otro usuario autorizado.', 'segregation_of_duties', 403);
        }
    }

    /** @param list<string> $allowed */
    private function assertStatus(ExternalEvent $event, array $allowed, string $message): void
    {
        if (! in_array($event->status, $allowed, true)) {
            throw new IntelligenceRuleException($message, 'invalid_event_state', 409, ['status' => $event->status]);
        }
    }

    private function assertPublishedNotFuture(mixed $publishedAt): void
    {
        if ($publishedAt !== null && CarbonImmutable::parse((string) $publishedAt)->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
            throw new IntelligenceRuleException('La fecha de publicación no puede estar en el futuro.', 'published_at_in_future', 422);
        }
    }

    /**
     * Los productos, categorías y nodos que el evento dice afectar deben ser visibles para la empresa (propios o, en nodos, públicos): sin
     * referencias a datos de otra empresa.
     *
     * @param  array<string, mixed>  $affects
     */
    private function assertAffectsVisible(array $affects): void
    {
        $checks = ['products' => Product::class, 'categories' => ProductCategory::class, 'taxonomy_nodes' => TaxonomyNode::class];
        foreach ($checks as $key => $model) {
            $ids = array_values(array_unique(array_map('intval', (array) ($affects[$key] ?? []))));
            if ($ids !== [] && $model::query()->whereIn('id', $ids)->count() !== count($ids)) {
                throw new IntelligenceRuleException("«affects.{$key}» contiene identificadores que no existen en la empresa.", 'affects_not_found', 422, ['field' => "affects.{$key}"]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'family', 'event_type', 'title', 'summary', 'source_url', 'source_ref', 'authority', 'published_at', 'effective_from', 'effective_to',
            'jurisdiction', 'corroboration', 'affects', 'is_informative_alert',
        ]));
        // Columnas jsonb NOT NULL con valor por defecto: un null explícito se guarda como vacío.
        if (array_key_exists('affects', $attributes)) {
            $attributes['affects'] = $attributes['affects'] ?? [];
        }

        return $attributes;
    }
}
