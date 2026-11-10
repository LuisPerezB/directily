<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * Para los trabajos que llaman al motor y llevan el middleware App\Jobs\Middleware\WaitForEngine (ADR-V26-01 · F6): cuenta cuántas
 * veces se aplazó el trabajo esperando a que el motor vuelva. Viaja con el trabajo en la cola.
 */
trait WaitsForEngine
{
    public int $engineDeferrals = 0;
}
