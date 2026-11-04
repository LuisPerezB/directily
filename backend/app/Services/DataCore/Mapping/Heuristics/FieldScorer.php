<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\DataCore\Mapping\Discovery\TypeClass;

/**
 * Puntaje HEURÍSTICO (0..1) de que una columna sea el origen de un campo canónico. Combina cuatro señales con pesos fijos:
 *
 *   nombre  0,55  coincidencia de tokens con el léxico del campo (exacta, parcial con palabras de más, palabra genérica), con penalizaciones
 *                 por tokens de otro concepto («costo» en un precio, «cliente» en un SKU)
 *   tipo    0,25  compatibilidad del tipo del catálogo con el tipo del campo
 *   tabla   0,10  la tabla tiene el rol que se espera del campo (línea de ventas, producto, inventario…)
 *   muestra 0,10  confirmación estadística (proporción de fechas, números, valores distintos) SI se leyó una muestra; si no, se omite y los otros
 *                 pesos se reescalan (la propuesta lo declara en la evidencia)
 *
 * NO es una probabilidad: los pesos y los umbrales son juicio de ingeniería, sin calibrar contra mapeos reales de clientes (se ajustan en el piloto).
 */
final class FieldScorer
{
    public const W_NAME = 0.55;

    public const W_TYPE = 0.25;

    public const W_CONTEXT = 0.10;

    public const W_SAMPLE = 0.10;

    /** Campos de texto libre: una columna numérica no los puede contener. */
    private const TEXT_ONLY = ['product_name', 'name', 'category', 'brand', 'address', 'channel', 'currency', 'document_type', 'customer_segment', 'customer_province', 'province', 'canton',
        'commercial_zone', 'promo_type', 'country', 'source', 'scope', 'code_name'];

    /**
     * @param  list<string>  $columnTokens
     * @return array{score: float, evidence: list<string>}|null null = la columna no puede ser el origen del campo
     */
    public function score(FieldRules $rule, TypeClass $type, array $columnTokens, float $context, ?ColumnProfile $profile): ?array
    {
        [$name, $nameEvidence] = $this->nameScore($rule, $columnTokens);
        if ($name <= 0.0) {
            return null;
        }
        $typeScore = $this->typeScore($rule, $type);
        if ($typeScore <= 0.0) {
            return null;
        }
        // Un campo que identifica registros no se propone si la muestra tiene la forma de datos personales o de texto libre (correos, teléfonos, fechas, frases), sea cual sea su nombre.
        if ($rule->identity && $profile !== null && $profile->looksPersonalOrFreeText()) {
            return null;
        }
        $parts = [[self::W_NAME, $name], [self::W_TYPE, $typeScore], [self::W_CONTEXT, $context]];
        $evidence = [...$nameEvidence, 'type:'.$type->value.'→'.$rule->type, 'context:'.($context >= 1.0 ? 'rol_esperado' : ($context >= 0.5 ? 'neutro' : 'rol_distinto'))];
        $sample = $profile === null ? null : $this->sampleScore($rule, $type, $profile);
        if ($sample !== null) {
            $parts[] = [self::W_SAMPLE, $sample[0]];
            $evidence[] = $sample[1];
        } else {
            $evidence[] = 'sample:no_leida';
        }
        $weights = array_sum(array_column($parts, 0));
        $score = array_sum(array_map(fn (array $p) => $p[0] * $p[1], $parts)) / $weights;

        // Nunca 1,00: un puntaje heurístico no es certeza.
        return ['score' => round(min(0.99, $score), 3), 'evidence' => $evidence];
    }

    /**
     * @param  list<string>  $columnTokens
     * @return array{0: float, 1: list<string>}
     */
    public function nameScore(FieldRules $rule, array $columnTokens): array
    {
        $tokens = array_values(array_unique($columnTokens));
        if ($tokens === []) {
            return [0.0, []];
        }
        $best = 0.0;
        $bestPattern = [];
        $bestKind = '';
        foreach ($rule->patterns as $pattern) {
            if (array_diff($pattern, $tokens) !== []) {
                continue;
            }
            $extra = count($tokens) - count($pattern);
            $weak = count($pattern) === 1 && in_array($pattern[0], Lexicon::WEAK, true);
            $value = match (true) {
                $extra === 0 && ! $weak => 1.0,
                $extra === 0 => 0.70,
                $weak => max(0.15, 0.40 - 0.08 * ($extra - 1)),
                default => max(0.45, 0.88 - 0.12 * ($extra - 1)),
            };
            if ($value > $best) {
                $best = $value;
                $bestPattern = $pattern;
                $bestKind = $extra === 0 ? ($weak ? 'exacta_generica' : 'exacta') : ($weak ? 'parcial_generica' : 'parcial');
            }
        }
        if ($best <= 0.0) {
            return [0.0, []];
        }
        $evidence = ['name:'.$bestKind.'('.implode('_', $bestPattern).')'];

        // Un campo de identidad exige un token de código o identificador (código, id, RUC, número…): «cliente_ciudad» o «cliente_observaciones» no son un código de cliente. El nombre
        // exacto sin ese token («cliente») sí se acepta, pero con el puntaje topado.
        if ($rule->requires !== [] && array_intersect($rule->requires, $tokens) === []) {
            if (count($tokens) !== count($bestPattern)) {
                return [0.0, []];
            }
            $best = min($best, 0.70);
            $evidence[] = 'tope:nombre_sin_codigo';
        }

        // Tokens de otro concepto del negocio (p. ej. «cliente» en un código de producto).
        if ($rule->own !== []) {
            $foreign = [];
            foreach (Lexicon::CONCEPTS as $concept => $words) {
                if (! in_array($concept, $rule->own, true) && array_intersect($words, $tokens) !== [] && array_intersect($words, $bestPattern) === []) {
                    $foreign[] = $concept;
                }
            }
            $mine = false;
            foreach ($rule->own as $concept) {
                $mine = $mine || array_intersect(Lexicon::CONCEPTS[$concept] ?? [], $tokens) !== [];
            }
            if ($foreign !== [] && ! $mine) {
                $best *= 0.35;
                $evidence[] = 'penalizacion:concepto_'.implode('+', $foreign);
            }
        }
        $penalty = array_values(array_diff(array_intersect($rule->penalize, $tokens), $bestPattern));
        if ($penalty !== []) {
            $best *= 0.40;
            $evidence[] = 'penalizacion:'.implode('+', $penalty);
        }

        return [round($best, 3), $evidence];
    }

    public function typeScore(FieldRules $rule, TypeClass $type): float
    {
        $textOnly = in_array($rule->key, self::TEXT_ONLY, true);

        return match ($rule->type) {
            'string' => match ($type) {
                TypeClass::Text => 1.0,
                TypeClass::Integer => $textOnly ? 0.0 : (str_contains($rule->key, 'status') ? 0.5 : 0.75),
                TypeClass::Boolean => $rule->key === 'status' ? 0.7 : 0.0,
                TypeClass::Decimal => 0.0,
                default => 0.0,
            },
            'date', 'datetime' => match ($type) {
                TypeClass::Date => $rule->type === 'date' ? 1.0 : 0.9,
                TypeClass::DateTime => $rule->type === 'datetime' ? 1.0 : 0.95,
                TypeClass::Text => 0.45,
                TypeClass::Integer => 0.15,
                default => 0.0,
            },
            'number' => match ($type) {
                TypeClass::Decimal, TypeClass::Integer => 1.0,
                TypeClass::Text => 0.4,
                default => 0.0,
            },
            'integer' => match ($type) {
                TypeClass::Integer => 1.0,
                TypeClass::Decimal => 0.7,
                TypeClass::Text => 0.35,
                default => 0.0,
            },
            'boolean' => match ($type) {
                TypeClass::Boolean => 1.0,
                TypeClass::Integer, TypeClass::Text => 0.6,
                default => 0.0,
            },
            default => 0.0,
        };
    }

    /** @return array{0: float, 1: string}|null */
    private function sampleScore(FieldRules $rule, TypeClass $type, ColumnProfile $profile): ?array
    {
        if ($profile->sampledRows === 0) {
            return null;
        }
        $label = fn (string $what, float $ratio): string => sprintf('sample:%s=%.2f', $what, $ratio);
        if (in_array($rule->type, ['date', 'datetime'], true) && $type === TypeClass::Text) {
            return [$profile->dateRatio >= 0.95 ? 1.0 : ($profile->dateRatio >= 0.6 ? 0.5 : 0.0), $label('fechas', $profile->dateRatio)];
        }
        if (in_array($rule->type, ['number', 'integer'], true) && $type === TypeClass::Text) {
            return [$profile->numericRatio >= 0.95 ? 1.0 : ($profile->numericRatio >= 0.6 ? 0.5 : 0.0), $label('numeros', $profile->numericRatio)];
        }
        if ($rule->type === 'boolean' && $type === TypeClass::Text) {
            return [$profile->booleanRatio >= 0.95 ? 1.0 : 0.2, $label('booleanos', $profile->booleanRatio)];
        }
        if ($rule->categorical && $profile->distinct !== null) {
            return [1.0, 'sample:vocabulario('.count($profile->distinct).'_valores)'];
        }
        if ($rule->categorical) {
            return [0.3, 'sample:demasiados_valores_para_un_vocabulario'];
        }

        return [$profile->nonNullRatio >= 0.5 ? 0.8 : 0.3, $label('no_nulos', $profile->nonNullRatio)];
    }
}
