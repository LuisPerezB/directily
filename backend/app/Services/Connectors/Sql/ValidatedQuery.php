<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Consulta que superó SqlQueryGuard. `parts` es el texto SIN comentarios y sin `;` final, con el único parámetro
 * permitido (`:since`) como marcador: lo que se ejecuta es exactamente lo que se analizó.
 */
final readonly class ValidatedQuery
{
    /** @param list<string|null> $parts null = posición de :since */
    public function __construct(public array $parts, public bool $usesSince) {}

    /** @param string|null $sinceLiteral literal SQL ya entrecomillado (lo genera el dialecto); null si la consulta no usa :since */
    public function render(?string $sinceLiteral = null): string
    {
        $sql = '';
        foreach ($this->parts as $part) {
            $sql .= $part ?? ($sinceLiteral ?? throw new SqlQueryRejected('La consulta usa :since pero no se proporcionó valor.', 'since_missing'));
        }

        return trim($sql);
    }
}
