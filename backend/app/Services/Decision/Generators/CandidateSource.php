<?php

declare(strict_types=1);

namespace App\Services\Decision\Generators;

use App\Models\Tenant;
use App\Services\Decision\DecisionSettings;
use Carbon\CarbonImmutable;

/**
 * Fuente de candidatos del Decision Engine (V2.5 · W5-B): lee SALIDAS YA PERSISTIDAS (Customer, Demand/inventario, Supply) y las traduce a los
 * campos de §11 para `DecisionEngine::prepare()`. No calcula pronósticos ni planes, no escribe y se ejecuta con la empresa ya en contexto.
 *
 * Grupo = candidatos del mismo tipo que se puntúan y priorizan juntos (la prioridad es por posición dentro del grupo).
 *
 * @phpstan-type Group array{kind: string, label: string, as_of: CarbonImmutable, max: int|null, attributes: list<array<string, mixed>>}
 */
interface CandidateSource
{
    /**
     * @return array{groups: list<array{kind: string, label: string, as_of: CarbonImmutable, max: int|null, attributes: list<array<string, mixed>>}>, skipped: array<string, string>}
     *                                                                                                                                                                                `skipped`: motivo (código) por el que no se generó algo, para el informe del comando
     */
    public function collect(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $today): array;
}
