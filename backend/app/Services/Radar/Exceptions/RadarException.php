<?php

declare(strict_types=1);

namespace App\Services\Radar\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Error de negocio del Revenue Radar y la Agenda (se responde en JSON con `code` estable; el mensaje va en español). */
final class RadarException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** El Decision Engine (RecommendationSink) aún no está disponible: el Radar no escribe decision_recommendations por su cuenta. */
    public static function sinkUnavailable(): self
    {
        return new self('El motor de decisiones no está disponible: el Radar propone oportunidades solo a través de él.', 'decision_engine_unavailable', 503);
    }

    /** @param list<string> $allowed */
    public static function invalidTransition(string $from, string $to, array $allowed): self
    {
        $options = $allowed === [] ? 'es un estado final' : 'permitidos: '.implode(', ', $allowed);

        return new self("No se puede pasar de «{$from}» a «{$to}» ({$options}).", 'invalid_transition', 422, ['from' => $from, 'to' => $to, 'allowed' => $allowed]);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('La clave de idempotencia ya se usó con otro contenido en esta oportunidad.', 'idempotency_key_reused', 409);
    }

    /** Posponer más allá del vencimiento de la oportunidad la haría reaparecer ya vencida (la pospuesta es trabajo en curso, pero su `expires_at` manda). */
    public static function snoozeBeyondExpiry(string $snoozedUntil, string $expiresOn): self
    {
        return new self("No se puede posponer hasta {$snoozedUntil}: la oportunidad vence el {$expiresOn}. Elija una fecha de reactivación anterior o igual al vencimiento.", 'snooze_beyond_expiry', 422,
            ['snoozed_until' => $snoozedUntil, 'expires_on' => $expiresOn]);
    }

    /**
     * El seguimiento cerraría la oportunidad en la agenda (descartado, pedido recibido) pero el workflow no puede reflejarlo para este usuario por falta de habilidad o
     * por segregación de funciones: se rechaza (403) y NO cambia nada, para que la oportunidad no desaparezca de las listas mientras el workflow sigue donde estaba.
     */
    public static function workflowNotPermitted(string $reason, ?string $ability, string $state, string $impliedStatus): self
    {
        $message = $reason === 'decision_segregation_of_duties'
            ? "No puede registrar «{$state}»: equivale a pasar la oportunidad a «{$impliedStatus}» en el workflow y la segregación de funciones se lo impide (la propuso, la aprobó o le toca ejecutarla)."
            : "No puede registrar «{$state}»: equivale a pasar la oportunidad a «{$impliedStatus}» en el workflow y su rol no tiene la habilidad".($ability ? " {$ability}" : '').'.';

        return new self($message, $reason, 403, array_filter(['required_ability' => $ability, 'state' => $state, 'implied_workflow_status' => $impliedStatus]));
    }

    public static function closedForFeedback(string $workflowStatus): self
    {
        return new self("La oportunidad está en estado de workflow «{$workflowStatus}» y ya no admite seguimiento de agenda.", 'opportunity_closed', 422, ['status' => $workflowStatus]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
