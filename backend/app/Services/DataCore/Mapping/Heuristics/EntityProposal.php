<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

/** Resultado determinista de proponer el mapeo de una entidad: lo que se mapeó (con su puntaje), lo que quedó sin mapear y por qué, y las advertencias. */
final readonly class EntityProposal
{
    /**
     * @param  array<string, string|array{const: mixed}>  $fieldMap  campo → «alias.columna»
     * @param  array<string, array{score: float, band: string, evidence: list<string>, source: string}>  $fields
     * @param  array<string, mixed>  $spec  source_spec
     * @param  list<array{field: string, reason: string, required: bool, candidates: list<array{column: string, score: float}>}>  $unmapped
     * @param  array<string, array<string, string>>  $valueMaps  campo → valor de origen → canónico (solo lo aceptado)
     * @param  array<string, mixed>  $valueMapReport  detalle por campo: entradas con puntaje, sin resolver, reconocidos
     * @param  array<string, string>  $piiExcluded  «tabla.columna» → tipo de dato personal
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $alternatives
     */
    public function __construct(
        public string $entity,
        public array $fieldMap,
        public array $fields,
        public array $spec,
        public array $unmapped,
        public array $valueMaps,
        public array $valueMapReport,
        public array $piiExcluded,
        public array $warnings,
        public array $alternatives,
        public bool $complete,
        public string $status = 'ok',
    ) {}

    /** @return list<string> campos obligatorios sin mapear */
    public function missingRequired(): array
    {
        return array_values(array_map(fn (array $u) => $u['field'], array_filter($this->unmapped, fn (array $u) => $u['required'])));
    }

    /** Documento `confidence` de la plantilla (jsonb): puntajes heurísticos, no probabilidades. */
    public function confidenceDocument(): array
    {
        return [
            'method' => 'heuristic_v1',
            'kind' => 'heuristic_score',
            'note' => 'Puntaje heurístico entre 0 y 1 (nombre, tipo, tabla y, si hubo, muestra). NO es una probabilidad calibrada: se ajusta en el piloto.',
            'min_confidence' => (float) config('mapping.min_confidence', 0.60),
            'ambiguity_margin' => (float) config('mapping.ambiguity_margin', 0.10),
            'fields' => $this->fields,
            'unmapped' => $this->unmapped,
            'value_maps' => $this->valueMapReport,
            'pii_columns_excluded' => $this->piiExcluded,
            'warnings' => $this->warnings,
            'alternatives' => $this->alternatives,
        ];
    }
}
