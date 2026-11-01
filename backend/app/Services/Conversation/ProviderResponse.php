<?php

declare(strict_types=1);

namespace App\Services\Conversation;

/** Respuesta normalizada de un proveedor: texto y/o llamadas a herramientas, más el uso de tokens (si lo informa). */
final class ProviderResponse
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null}  $usage
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly array $usage = ['prompt_tokens' => null, 'completion_tokens' => null],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
