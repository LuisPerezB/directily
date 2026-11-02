<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\Workflow;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Radar\Exceptions\RadarException;
use App\Support\Privacy\PiiMasker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seguimiento (feedback) de una oportunidad del Radar con los estados heredados de la Agenda V2.2 (V2.5 · W5-A; V25-AGD-005, -006, -007 y -020).
 *
 * - Auditable e inmutable: cada cambio inserta UN evento `feedback` en `decision_events` (quién, cuándo, estado previo y nuevo, nota) —el historial nunca se
 *   edita— y una fila en `audit_logs`. El estado heredado vigente vive en `decision_recommendations.agenda_status`.
 * - Idempotente: la clave del evento es SHA-256(«radar.feedback», recomendación, clave del cliente). Repetir la misma petición devuelve el mismo evento (HTTP 200,
 *   `replayed`); la misma clave con otro contenido es un conflicto (409). Concurrencia: la fila de la recomendación se bloquea durante el cambio.
 * - Transición válida según {@see AgendaStatusMap::TRANSITIONS}; sin transiciones desde estados finales; solo mientras el workflow admite seguimiento.
 * - Mapeo al workflow sin perder historial: el evento registra el estado de workflow que implica el estado heredado ({@see AgendaStatusMap::MAP}) y el servicio
 *   de transiciones de W5-B aplica esa transición como cualquier otra ({@see self::syncWorkflow()}); su resultado va en un SEGUNDO evento (`comment`,
 *   `payload.kind = workflow_sync`), así el evento de seguimiento queda tal como se insertó (solo se inserta, nunca se actualiza).
 * - Un seguimiento que CIERRA la oportunidad en la agenda (descartado, pedido recibido) y cuya transición implícita el usuario no puede hacer (habilidad o segregación)
 *   se rechaza con 403 y no cambia nada: la oportunidad no desaparece de las listas mientras el workflow sigue donde estaba.
 * - `snoozed_until` no puede pasar del vencimiento de la oportunidad (422): la pospuesta es trabajo en curso, pero su `expires_at` manda.
 * - La nota es texto libre del usuario: se enmascaran correos y números de 10/13 dígitos (PiiMasker) y se acota a 500 caracteres.
 * - Nunca envía mensajes: registra lo que un humano hizo.
 */
final class AgendaFeedbackService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{event: DecisionEvent, recommendation: DecisionRecommendation, replayed: bool, workflow_sync: array<string, mixed>|null}
     *
     * @throws RadarException
     */
    public function apply(int $recommendationId, User $actor, string $state, ?string $note, string $clientKey, ?string $snoozedUntil = null): array
    {
        $note = $note === null || trim($note) === '' ? null : mb_substr(PiiMasker::mask(trim($note)), 0, 500);
        $snooze = $state === 'pospuesto' ? $this->snoozeDate($snoozedUntil) : null;

        try {
            return DB::transaction(fn () => $this->run($recommendationId, $actor, $state, $note, $clientKey, $snooze));
        } catch (UniqueConstraintViolationException) {
            // Dos peticiones con la misma clave a la vez: la perdedora relee y repite el resultado de la ganadora.
            return DB::transaction(fn () => $this->run($recommendationId, $actor, $state, $note, $clientKey, $snooze));
        }
    }

    /** @return array{event: DecisionEvent, recommendation: DecisionRecommendation, replayed: bool, workflow_sync: array<string, mixed>|null} */
    private function run(int $id, User $actor, string $state, ?string $note, string $clientKey, ?string $snooze): array
    {
        // Las del grupo de control no existen para el Radar (404): se reservan para medición.
        $rec = DecisionRecommendation::query()->whereIn('kind', RadarPolicy::KINDS)
            ->where(fn (Builder $w) => $w->whereNull('experiment_group')->orWhere('experiment_group', '<>', 'control'))
            ->lockForUpdate()->findOrFail($id);

        $key = self::eventKey((int) $rec->id, $clientKey);
        $existing = DecisionEvent::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->agenda_status !== $state || $existing->reason !== $note || ($existing->payload['snoozed_until'] ?? null) !== $snooze) {
                throw RadarException::idempotencyKeyReused();
            }

            return ['event' => $existing, 'recommendation' => $rec, 'replayed' => true, 'workflow_sync' => self::syncOf($existing)];
        }

        if (! AgendaStatusMap::acceptsFeedback($rec)) {
            throw RadarException::closedForFeedback((string) $rec->status);
        }
        $from = AgendaStatusMap::effective($rec->agenda_status);
        if (! AgendaStatusMap::canTransition($from, $state)) {
            throw RadarException::invalidTransition($from, $state, AgendaStatusMap::allowedFrom($from));
        }

        if ($snooze !== null && $rec->expires_at !== null) {
            $expiresOn = $rec->expires_at->setTimezone($this->context->require()->timezone ?: 'America/Guayaquil')->toDateString();
            if ($snooze > $expiresOn) {
                throw RadarException::snoozeBeyondExpiry($snooze, $expiresOn);
            }
        }
        $implied = AgendaStatusMap::impliedWorkflowStatus($state, $rec->assigned_user_id !== null);
        if (in_array($state, AgendaStatusMap::CLOSED_STATES, true)) {
            $blocked = app(Workflow::class)->impliedBlockedByAuthorization((int) $rec->id, $implied, $actor, $actor->roleIn($this->context->require()), ['reason' => $note]);
            if ($blocked !== null) {
                throw RadarException::workflowNotPermitted($blocked['reason'], $blocked['ability'], $state, (string) $implied);
            }
        }
        $payload = array_filter([
            'from_agenda_status' => $from,
            'to_agenda_status' => $state,
            'map_version' => AgendaStatusMap::VERSION,
            'implied_workflow_status' => $implied,
            'workflow_status_at_event' => $rec->status,
            'interest' => $state === 'interesado' ? true : null,
            'snoozed_until' => $snooze,
        ], fn ($v) => $v !== null);

        $event = DecisionEvent::query()->create([
            'recommendation_id' => $rec->id, 'event' => 'feedback',
            'from_status' => $rec->status, 'to_status' => $rec->status,   // el workflow no cambia desde la agenda (ver syncWorkflow)
            'agenda_status' => $state, 'actor_user_id' => $actor->getKey(), 'actor_kind' => 'user',
            'reason' => $note, 'payload' => $payload, 'idempotency_key' => $key,
        ]);
        $rec->forceFill(['agenda_status' => $state])->save();
        $sync = $this->syncWorkflow($rec, $event, $implied);

        CoreServices::audit('radar.feedback', $rec, [
            'recommendation_id' => (int) $rec->id, 'event_id' => (int) $event->id, 'from' => $from, 'to' => $state, 'has_note' => $note !== null,
            'implied_workflow_status' => $implied, 'snoozed_until' => $snooze,
        ]);

        return ['event' => $event, 'recommendation' => $rec, 'replayed' => false, 'workflow_sync' => $sync];
    }

    /**
     * Enganche con el workflow de W5-B. Pide al servicio de transiciones la que implica el estado heredado (`radar-agenda-map-v1`: contactado e interesado → in_progress,
     * pedido_recibido → executed, descartado → rejected; pospuesto no mueve nada) y la aplica COMO CUALQUIER OTRA: con las habilidades del rol del usuario, la segregación de
     * funciones, la aprobación de acciones sensibles y el bloqueo optimista, y con su propio evento de auditoría. El seguimiento nunca falla ni se pierde por esto: si el
     * workflow no admite la transición desde su estado actual (p. ej. suggested → in_progress sin pasar por la revisión, o una acción sensible sin aprobar), el workflow NO se
     * mueve y el motivo queda en un segundo evento (`comment`, `payload.kind = workflow_sync`) en la misma transacción: el evento de seguimiento ya insertado NO se modifica.
     * Un seguimiento del vendedor asignado jamás cuenta como aprobación. No duplica reglas aquí.
     *
     * @return array<string, mixed> resultado de la sincronización (`sync`, `reason`, `status_before`, `status_after`, `workflow_event_id`, `required_ability`)
     */
    private function syncWorkflow(DecisionRecommendation $rec, DecisionEvent $event, ?string $impliedWorkflowStatus): array
    {
        $actor = $event->actor_user_id === null ? null : User::query()->find($event->actor_user_id);
        $before = (string) $rec->status;
        $outcome = $actor === null
            ? ['sync' => 'skipped', 'reason' => 'no_actor', 'from' => $before, 'to' => $impliedWorkflowStatus, 'event_id' => null, 'required_ability' => null]
            : app(Workflow::class)->applyImplied(
                (int) $rec->id, $impliedWorkflowStatus, $actor, $actor->roleIn($this->context->require()), 'agenda-feedback:'.$event->id,
                ['expected_version' => (int) $event->id, 'reason' => $event->reason],
            );
        $rec->refresh();   // el workflow pudo moverse: quien llamó recibe esta misma instancia ya actualizada
        $payload = array_filter([
            'kind' => 'workflow_sync', 'feedback_event_id' => (int) $event->id, 'map_version' => AgendaStatusMap::VERSION, 'implied_workflow_status' => $impliedWorkflowStatus,
            'workflow_sync' => $outcome['sync'], 'workflow_sync_reason' => $outcome['reason'], 'workflow_status_before' => $before, 'workflow_status_after' => (string) $rec->status,
            'workflow_event_id' => $outcome['event_id'], 'workflow_required_ability' => $outcome['required_ability'],
        ], fn ($v) => $v !== null);
        DecisionEvent::query()->create([
            'recommendation_id' => $rec->id, 'event' => 'comment', 'from_status' => $before, 'to_status' => (string) $rec->status, 'actor_user_id' => null, 'actor_kind' => 'system',
            'reason' => 'Resultado de la sincronización del seguimiento de agenda con el workflow.', 'payload' => $payload,
            'idempotency_key' => hash('sha256', 'radar.workflow_sync:'.$event->id),
        ]);

        return $payload;
    }

    /** Resultado de la sincronización de un evento de seguimiento (el comentario que lo sigue), o null si no lo hay. @return array<string, mixed>|null */
    public static function syncOf(DecisionEvent $feedback): ?array
    {
        $comment = DecisionEvent::query()->where('idempotency_key', hash('sha256', 'radar.workflow_sync:'.$feedback->id))->first();

        return $comment === null ? null : (array) $comment->payload;
    }

    public static function eventKey(int $recommendationId, string $clientKey): string
    {
        return hash('sha256', "radar.feedback\0{$recommendationId}\0{$clientKey}");
    }

    /** Fecha de reactivación: obligatoria al posponer, posterior a hoy (zona de la empresa) y a lo sumo en un año. */
    private function snoozeDate(?string $value): string
    {
        $tz = $this->context->require()->timezone ?: 'America/Guayaquil';
        $today = CarbonImmutable::now($tz)->startOfDay();
        try {
            $date = $value === null ? false : CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value || $date->lessThanOrEqualTo($today) || $date->greaterThan($today->addYear())) {
            throw ValidationException::withMessages(['snoozed_until' => 'Indique la fecha de reactivación (AAAA-MM-DD): posterior a hoy y a lo sumo en un año.']);
        }

        return $date->toDateString();
    }
}
