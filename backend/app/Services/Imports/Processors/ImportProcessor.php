<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

/**
 * Procesador por tipo de entidad. La MISMA ruta de código se usa para validar
 * (commit=false) y para importar (commit=true): lo que se escribe es exactamente lo validado.
 */
interface ImportProcessor
{
    /**
     * @return array{stats: array<string, mixed>, issues: list<array<string, mixed>>}
     */
    public function run(ImportRunContext $ctx): array;
}
