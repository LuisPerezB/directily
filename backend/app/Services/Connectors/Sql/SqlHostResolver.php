<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Resolución de nombres para la política de destino. Devuelve TODAS las direcciones (A y AAAA) como literales IP; una lista
 * vacía significa que el nombre no resuelve. Se puede sustituir en el contenedor (pruebas sin red).
 */
interface SqlHostResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
