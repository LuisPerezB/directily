<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deduce de la auditoría (`decision_events`) qué pasó con una recomendación (V2.5 · W5-C), sin suposiciones:
 *
 *  - La ACCIÓN cuenta como realizada solo cuando una persona la registró: la transición a `executed`, o —en tipos con cliente— un seguimiento de agenda
 *    «contactado», «interesado» o «pedido_recibido» (V2.5 · W5-A, `radar-agenda-map-v1`). `in_progress` por sí solo es «empezada», no «hecha».
 *    `action_at` es el primer momento en que quedó registrada: es una cota CONSERVADORA (la acción real pudo ser anterior; nunca se supone anterior al registro).
 *  - Acción registrada gana sobre el estado final: una recomendación contactada que luego se descartó o venció sigue siendo «contactada».
 *  - La PRIMERA acción registrada es la que cuenta (no la última). Una acción registrada después de cerrar la ventana no cuenta como atendida en esa ventana.
 *  - Sin acción: grupo de control (retenida a propósito) › rechazada › caducada › no contactada / no ejecutada.
 */
final class ActionResolver
{
    /**
     * @param  Collection<int, DecisionEvent>  $events  eventos de la recomendación
     * @param  CarbonImmutable|null  $windowEnd  fin del último día de la ventana (zona de la empresa): una acción registrada DESPUÉS no cuenta como atendida en esta ventana
     */
    public function resolve(DecisionRecommendation $rec, Collection $events, ?CarbonImmutable $windowEnd = null): ActionRecord
    {
        $customerFacing = OutcomeVocabulary::isCustomerKind((string) $rec->kind);
        $candidates = [];
        foreach ($events as $e) {
            if ($e->event === 'feedback' && $customerFacing && in_array($e->agenda_status, OutcomeVocabulary::AGENDA_CONTACT_STATES, true)) {
                $candidates[] = ['at' => $e->created_at, 'id' => (int) $e->id, 'source' => 'agenda:'.$e->agenda_status];
            } elseif ($e->event === 'transition' && $e->to_status === 'executed') {
                $candidates[] = ['at' => $e->created_at, 'id' => (int) $e->id, 'source' => 'workflow:executed'];
            }
        }
        usort($candidates, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['id']] <=> [$b['at']->getTimestamp(), $b['id']]);
        $first = $candidates[0] ?? null;
        $lateAction = null;
        if ($first !== null && $windowEnd !== null && $first['at']->greaterThan($windowEnd)) {
            // Registrada cuando la ventana ya había cerrado: no pudo influir en lo observado en ella, no es una acción atendida ni genera costo de contacto en esta medición.
            $lateAction = $first;
            $first = null;
        }

        $details = array_filter([
            'status_at_measurement' => (string) $rec->status,
            'agenda_status' => $rec->agenda_status,
            'experiment_group' => $rec->experiment_group,
        ], fn ($v) => $v !== null);
        $rejection = $events->first(fn (DecisionEvent $e) => $e->event === 'transition' && $e->to_status === 'rejected');
        if ($rejection !== null) {
            // Fecha del rechazo; el motivo (texto libre del usuario) queda en el evento de auditoría, no se copia aquí.
            $details['rejected_at'] = $rejection->created_at->toIso8601String();
        }
        $expiry = $events->first(fn (DecisionEvent $e) => $e->event === 'transition' && $e->to_status === 'expired');
        if ($expiry !== null) {
            $details['expired_at'] = $expiry->created_at->toIso8601String();
            $details['expiry_reason'] = (string) ($expiry->payload['reason_code'] ?? 'expired');
            if (isset($expiry->payload['superseded_by'])) {
                $details['superseded_by'] = (int) $expiry->payload['superseded_by'];
            }
        }

        if ($lateAction !== null) {
            $details['action_registered_after_window_at'] = $lateAction['at']->toIso8601String();
            $details['action_registered_after_window_source'] = $lateAction['source'];
        }
        if ($rec->experiment_group === 'control') {
            // El control no se contacta. Si hubiera una acción registrada sobre él, es contaminación: se informa, no se oculta.
            if ($first !== null) {
                $details['control_contaminated'] = true;
                $details['contamination_source'] = $first['source'];
            }

            return new ActionRecord(OutcomeVocabulary::CONTROL, null, null, null, $details);
        }
        if ($first !== null) {
            return new ActionRecord(
                $customerFacing ? OutcomeVocabulary::CONTACTED : OutcomeVocabulary::EXECUTED,
                $first['at']->toImmutable(), $first['source'], $first['id'], $details,
            );
        }
        $kind = match (true) {
            $rec->status === 'rejected' => OutcomeVocabulary::REJECTED,
            $rec->status === 'expired' => OutcomeVocabulary::EXPIRED,
            $customerFacing => OutcomeVocabulary::NOT_CONTACTED,
            default => OutcomeVocabulary::NOT_EXECUTED,
        };

        return new ActionRecord($kind, null, null, null, $details);
    }
}
