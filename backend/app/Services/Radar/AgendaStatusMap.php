<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\DecisionRecommendation;
use InvalidArgumentException;

/**
 * Estados heredados de la Agenda Comercial V2.2 y su correspondencia con el workflow Enterprise (V2.5 · W5-A; V25-AGD-006 y -007; ADR-V25-12 y -13).
 *
 * Los seis estados heredados viven en `decision_recommendations.agenda_status` (NULL = «pendiente» aún sin gestión) y cada cambio queda como un evento
 * `feedback` inmutable en `decision_events` (historial completo: de dónde viene y a dónde va). El workflow (`status`: suggested → assigned → reviewed →
 * approved | rejected → in_progress → executed → measured → closed, + expired) es de W5-B; esta tabla dice a qué estado del workflow equivale cada estado
 * heredado (`implied_workflow_status`) SIN perder el estado heredado ni su historial.
 *
 * La matriz de transiciones evita saltos absurdos desde la agenda (p. ej. reabrir un pedido recibido). Un `pedido_recibido` o un `descartado` son finales:
 * volver a intentarlo es una oportunidad nueva en la siguiente ejecución del Radar.
 */
final class AgendaStatusMap
{
    /** Versión de la tabla (se registra en cada evento de feedback). */
    public const VERSION = 'radar-agenda-map-v1';

    public const PENDIENTE = 'pendiente';

    public const DEFAULT_STATE = self::PENDIENTE;

    /** Orden de presentación (también el de la matriz). */
    public const STATES = ['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado'];

    /** Estados finales de la agenda: salen de la lista de trabajo. */
    public const CLOSED_STATES = ['pedido_recibido', 'descartado'];

    /**
     * Tabla del mapeo. `workflow` = estado de workflow que implica el estado heredado (`null` = no cambia el workflow); `note` = la regla en palabras.
     *
     * @var array<string, array{workflow: string|null, note: string}>
     */
    public const MAP = [
        'pendiente' => ['workflow' => 'suggested', 'note' => 'Sin gestión: la recomendación sigue sugerida (assigned si ya tiene vendedor asignado).'],
        'contactado' => ['workflow' => 'in_progress', 'note' => 'Se contactó al cliente: la acción está en curso.'],
        'interesado' => ['workflow' => 'in_progress', 'note' => 'Contactado con interés: sigue en curso; el interés queda en el evento (payload.interest = true).'],
        'pedido_recibido' => ['workflow' => 'executed', 'note' => 'Se recibió el pedido: la acción se ejecutó; la medición del resultado (measured) la hace el servicio de outcomes, no la agenda.'],
        'pospuesto' => ['workflow' => null, 'note' => 'Se pospone: el workflow no cambia; la fecha de reactivación queda en el evento (payload.snoozed_until) y la oportunidad sale de la agenda hasta entonces.'],
        'descartado' => ['workflow' => 'rejected', 'note' => 'Se descarta: equivale a rechazada.'],
    ];

    /**
     * Transiciones permitidas entre estados heredados (origen → destinos). Vacío = estado final.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pendiente' => ['contactado', 'pedido_recibido', 'pospuesto', 'descartado'],
        'contactado' => ['interesado', 'pedido_recibido', 'pospuesto', 'descartado'],
        'interesado' => ['contactado', 'pedido_recibido', 'pospuesto', 'descartado'],
        'pedido_recibido' => [],
        'pospuesto' => ['pendiente', 'contactado', 'pedido_recibido', 'descartado'],
        'descartado' => [],
    ];

    /** Estados del workflow en los que aún se admite feedback de agenda (los rechazados, medidos, cerrados o vencidos, no). */
    public const FEEDBACK_WORKFLOW_STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed'];

    /** Estados del workflow que sacan una oportunidad de TODA lista de trabajo por defecto (vencida, ya medida o cerrada). */
    public const TERMINAL_WORKFLOW_STATUSES = ['expired', 'measured', 'closed'];

    /** Estado heredado efectivo de una recomendación: NULL se lee como «pendiente». */
    public static function effective(?string $agendaStatus): string
    {
        return $agendaStatus === null || $agendaStatus === '' ? self::DEFAULT_STATE : $agendaStatus;
    }

    /** @return list<string> destinos permitidos desde el estado efectivo */
    public static function allowedFrom(?string $agendaStatus): array
    {
        return self::TRANSITIONS[self::effective($agendaStatus)] ?? [];
    }

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    public static function isClosed(?string $agendaStatus): bool
    {
        return in_array(self::effective($agendaStatus), self::CLOSED_STATES, true);
    }

    /**
     * Estado de workflow que implica un estado heredado. `pendiente` con vendedor asignado equivale a `assigned`. `null` = el workflow no cambia.
     *
     * @throws InvalidArgumentException si el estado no es uno de los seis heredados
     */
    public static function impliedWorkflowStatus(string $legacy, bool $assigned = false): ?string
    {
        if (! isset(self::MAP[$legacy])) {
            throw new InvalidArgumentException("Estado heredado desconocido: {$legacy}");
        }
        $status = self::MAP[$legacy]['workflow'];

        return $legacy === self::PENDIENTE && $assigned ? 'assigned' : $status;
    }

    /** @return list<array{legacy: string, workflow: string|null, note: string, allowed_next: list<string>}> la tabla para documentación y API */
    public static function table(): array
    {
        $rows = [];
        foreach (self::STATES as $legacy) {
            $rows[] = ['legacy' => $legacy, 'workflow' => self::MAP[$legacy]['workflow'], 'note' => self::MAP[$legacy]['note'], 'allowed_next' => self::TRANSITIONS[$legacy]];
        }

        return $rows;
    }

    /** ¿La recomendación admite feedback de agenda por su estado de workflow? */
    public static function acceptsFeedback(DecisionRecommendation $rec): bool
    {
        return in_array($rec->status, self::FEEDBACK_WORKFLOW_STATUSES, true);
    }
}
