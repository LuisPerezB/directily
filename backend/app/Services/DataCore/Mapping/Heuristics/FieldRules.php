<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

/** Reglas de reconocimiento de un campo canónico: patrones de nombre (bolsas de tokens), tokens que lo desmienten y su tabla de origen esperada. */
final readonly class FieldRules
{
    /**
     * @param  list<list<string>>  $patterns  cada patrón es un conjunto de tokens que deben estar TODOS en el nombre de la columna
     * @param  list<string>  $penalize  tokens que indican otro concepto (p. ej. «costo» en un precio de venta)
     * @param  list<string>  $own  conceptos propios del campo (los de otros conceptos en el nombre lo penalizan)
     * @param  list<string>  $requires  el nombre debe traer al menos uno (código, id, número…) salvo que sea exactamente el patrón («cliente»), y entonces su puntaje se topa
     * @param  bool  $identity  campo que identifica registros (código de cliente, SKU, sucursal…): una muestra con forma de dato personal o de texto libre lo descarta
     */
    public function __construct(
        public string $key,
        public string $type,
        public bool $required,
        public array $patterns,
        public array $penalize,
        public array $own,
        public string $home,
        public bool $categorical,
        public array $requires = [],
        public bool $identity = false,
    ) {}
}
