<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use RuntimeException;

/** El contenido de un lote Bridge no cumple el contrato (gzip, NDJSON, columnas, valores o tamaños). `errorCode` es estable; el mensaje nunca incluye valores del lote. */
final class BridgeBatchInvalid extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid_row')
    {
        parent::__construct($message);
    }
}
