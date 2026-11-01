<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Intento de escribir una fila del catálogo público (tenant_id NULL) fuera de una operación de plataforma (HTTP 403). */
final class PublicCatalogReadOnlyException extends RuntimeException
{
    public function __construct(string $message = 'El catálogo público es de solo lectura para las empresas: solo el propietario de la plataforma puede modificarlo.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'public_catalog_read_only'], 403);
    }
}
