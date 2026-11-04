<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Templates;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Error de negocio de las plantillas de mapeo, con código estable y estado HTTP (403, 404, 409 o 422). */
final class MappingTemplateException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter(['message' => $this->getMessage(), 'code' => $this->errorCode, 'context' => $this->context ?: null], fn ($v) => $v !== null), $this->status);
    }
}
