<?php

declare(strict_types=1);

namespace App\Services\Decision\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Autorización del workflow de decisiones (HTTP 403): falta la habilidad que exige la transición (`code = forbidden`, con `required_ability`, mismo
 * cuerpo que el middleware `tenant.ability`) o la segregación de funciones lo prohíbe (`code = decision_segregation_of_duties`, con `context.roles`:
 * proposer, executor o approver). No es un conflicto de estado: no depende del recurso sino de quién actúa.
 */
final class DecisionForbiddenException extends RuntimeException
{
    /** @param list<string> $roles */
    private function __construct(string $message, public readonly string $errorCode, public readonly ?string $ability = null, public readonly array $roles = [])
    {
        parent::__construct($message);
    }

    public static function missingAbility(string $ability, string $message): self
    {
        return new self($message, 'forbidden', $ability);
    }

    /** @param list<string> $roles */
    public static function segregation(string $message, array $roles): self
    {
        return new self($message, 'decision_segregation_of_duties', null, $roles);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'required_ability' => $this->ability,
            'context' => $this->roles === [] ? null : ['roles' => $this->roles],
        ], fn ($v) => $v !== null), 403);
    }
}
