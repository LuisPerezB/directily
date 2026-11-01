<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Un alias o cambio de código no es válido (código en uso, solapamiento de vigencia, tipo no soportado...). Se responde 422 con un código estable. */
final class AliasConflictException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly string $field = 'source_code')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
