<?php

declare(strict_types=1);

namespace App\Services\Models\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * La acción exige una habilidad que el usuario no tiene, decidida por el estado del modelo y no por la ruta (HTTP 403, mismo cuerpo que el
 * middleware `tenant.ability`: `code = forbidden` y `required_ability`). Ejemplo: validar un modelo del pipeline propio o restaurar un
 * aprobado que aún no estuvo en producción exigen `models.approve`, no solo `forecast.run`.
 */
final class MissingAbilityException extends RuntimeException
{
    public function __construct(public readonly string $ability, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'forbidden', 'required_ability' => $this->ability], 403);
    }
}
