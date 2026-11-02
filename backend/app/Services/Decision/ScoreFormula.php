<?php

declare(strict_types=1);

namespace App\Services\Decision;

/**
 * Fórmula versionada del score de una recomendación (V2.5 · W5-B; ADR-V25-13; §11). Documentada en docs/decision/ECONOMIC_PRIORITIZATION.md.
 *
 * score = valor − gasto de la acción
 *   valor  = contribución esperada            si el costo unitario es confiable
 *          = importe bruto × peso             si NO lo es (marca `cost_unreliable`; el peso es de ranking, no una cifra económica)
 *          = (sin valor → score NULL)         si no hay ni contribución ni importe
 *   gasto  = costo de la acción                solo en los tipos de `expense_kinds` (contacto); en reposición el desembolso es capital
 *            comprometido, no un gasto, y se informa aparte (`action_cost`).
 *
 * El valor esperado de contacto es E(nº de pedidos) × contribución condicionada por pedido (ver DecisionEngine). Ese valor NO implica
 * ventas incrementales causadas por la acción: sin evidencia de uplift, la etiqueta es `potential_unproven`.
 *
 * `score_formula_version` = SHA-256 del JSON canónico de los parámetros EFECTIVOS (los de la empresa incluidos): si cambia un parámetro
 * cambia la versión, y cada recomendación conserva la versión con la que se calculó.
 */
final class ScoreFormula
{
    public const ID = 'expected-net-value';

    /** Cambia cuando cambia la ESTRUCTURA de la fórmula (no sus parámetros). */
    public const REVISION = 1;

    public const BASIS_CONTRIBUTION = 'contribution';

    public const BASIS_GROSS_WEIGHTED = 'gross_weighted_cost_unreliable';

    /** @param array<string, mixed> $parameters */
    private function __construct(private readonly array $parameters) {}

    public static function fromSettings(DecisionSettings $settings): self
    {
        return new self([
            'id' => self::ID,
            'revision' => self::REVISION,
            'unreliable_cost_weight' => $settings->unreliableCostWeight(),
            'expense_kinds' => ['contact', 'customer_risk', 'recovery'],
            'priority_bands' => [0.10, 0.30, 0.60, 0.85],
            'decimals' => 6,
        ]);
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function version(): string
    {
        return hash('sha256', self::canonicalJson($this->parameters));
    }

    /**
     * @return array{score: float|null, value: float|null, basis: string|null, expense: float, weight: float|null, cost_unreliable: bool}
     */
    public function evaluate(string $kind, ?float $contribution, ?float $gross, bool $costReliable, ?float $actionCost): array
    {
        $weight = (float) $this->parameters['unreliable_cost_weight'];
        [$value, $basis, $usedWeight] = match (true) {
            $costReliable && $contribution !== null => [$contribution, self::BASIS_CONTRIBUTION, null],
            $gross !== null => [$gross * $weight, self::BASIS_GROSS_WEIGHTED, $weight],
            default => [null, null, null],
        };
        $expense = in_array($kind, (array) $this->parameters['expense_kinds'], true) && $actionCost !== null ? $actionCost : 0.0;
        $decimals = (int) $this->parameters['decimals'];

        return [
            'score' => $value === null ? null : round($value - $expense, $decimals),
            'value' => $value === null ? null : round($value, $decimals),
            'basis' => $basis,
            'expense' => round($expense, $decimals),
            'weight' => $usedWeight,
            'cost_unreliable' => $basis === self::BASIS_GROSS_WEIGHTED,
        ];
    }

    /**
     * Prioridad 1 (la más alta) a 5 por posición dentro de un lote del mismo tipo y horizonte, según la fracción del lote con mejor score que la
     * recomendación: menos del 10 % → 1, menos del 30 % → 2, menos del 60 % → 3, menos del 85 % → 4, el resto → 5. Un score nulo o no positivo es 5. Es determinista para el mismo lote (el empate se resuelve por clave).
     *
     * @param  array<string|int, float|null>  $scores  score por clave
     * @return array<string|int, int>
     */
    public function priorities(array $scores): array
    {
        $ranked = array_filter($scores, fn ($s) => $s !== null && $s > 0);
        uksort($ranked, fn ($a, $b) => ($ranked[$b] <=> $ranked[$a]) ?: strcmp((string) $a, (string) $b));
        $bands = (array) $this->parameters['priority_bands'];
        $n = count($ranked);
        $out = [];
        $i = 0;
        foreach (array_keys($ranked) as $key) {
            $above = $i / $n;   // fracción del lote con mejor score que este
            $priority = 5;
            foreach ($bands as $index => $limit) {
                if ($above < $limit) {
                    $priority = $index + 1;
                    break;
                }
            }
            $out[$key] = $priority;
            $i++;
        }
        foreach (array_keys($scores) as $key) {
            $out[$key] ??= 5;
        }

        return $out;
    }

    /** JSON canónico: claves ordenadas recursivamente, sin espacios; base de las huellas (fórmula e idempotencia). */
    public static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => self::sortKeys($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => self::sortKeys($v), $value);
    }
}
