<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use Carbon\CarbonImmutable;

/**
 * Ventana de observación de una recomendación (V2.5 · W5-C; decisión del arquitecto): FIJA desde el horizonte, no elegida después.
 *
 *   from   = as_of + 1 día          to = as_of + horizon_days
 *   cutoff = to + settling_lag_days  (fecha local de conocimiento: se lee lo que Predictily conocía al cierre de ese día)
 *
 * Se mide solo cuando `cutoff` ya pasó (hoy > cutoff). Antes de eso no se escribe nada: la recomendación sigue «prevista» (forecast).
 */
final class OutcomeWindow
{
    public function __construct(public readonly CarbonImmutable $asOf, public readonly int $horizonDays, public readonly int $lagDays) {}

    public static function for(string|\DateTimeInterface $asOf, int $horizonDays, int $lagDays): self
    {
        $date = $asOf instanceof \DateTimeInterface ? $asOf->format('Y-m-d') : substr($asOf, 0, 10);

        return new self(CarbonImmutable::parse($date)->startOfDay(), $horizonDays, $lagDays);
    }

    public function from(): CarbonImmutable
    {
        return $this->asOf->addDay();
    }

    public function to(): CarbonImmutable
    {
        return $this->asOf->addDays($this->horizonDays);
    }

    public function cutoff(): CarbonImmutable
    {
        return $this->to()->addDays($this->lagDays);
    }

    /** ¿Ya se puede medir? `$today` es la fecha local de la empresa. */
    public function measurableOn(CarbonImmutable $today): bool
    {
        return $today->toDateString() > $this->cutoff()->toDateString();
    }

    /** ¿La ventana ya cerró? (puede faltar el margen de espera) */
    public function closedOn(CarbonImmutable $today): bool
    {
        return $today->toDateString() > $this->to()->toDateString();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf->toDateString(), 'horizon_days' => $this->horizonDays, 'from' => $this->from()->toDateString(), 'to' => $this->to()->toDateString(),
            'settling_lag_days' => $this->lagDays, 'knowledge_cutoff' => $this->cutoff()->toDateString(),
        ];
    }
}
