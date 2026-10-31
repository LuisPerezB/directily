<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Support;

use App\Services\Conversation\Contracts\ConversationProvider;
use App\Services\Conversation\Exceptions\ProviderException;
use App\Services\Conversation\ProviderResponse;
use Closure;
use LogicException;

/**
 * Proveedor falso SIN red para las pruebas: ejecuta un guion de respuestas (ProviderResponse, ProviderException o Closure
 * que recibe ($messages, $tools)) y registra todo lo que recibiría el proveedor real.
 */
final class FakeConversationProvider implements ConversationProvider
{
    /** @var list<array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param list<ProviderResponse|ProviderException|Closure> $script */
    public function __construct(public array $script = [], private readonly bool $available = true) {}

    public static function text(string $content): ProviderResponse
    {
        return new ProviderResponse($content);
    }

    /** @param array<string, mixed> $arguments */
    public static function tool(string $name, array $arguments = []): ProviderResponse
    {
        return new ProviderResponse('', [['name' => $name, 'arguments' => $arguments]], ['prompt_tokens' => 10, 'completion_tokens' => 5]);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): ?string
    {
        return 'fake-model';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function unavailableReason(): ?string
    {
        return $this->available ? null : 'missing_api_key';
    }

    public function chat(array $messages, array $tools): ProviderResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];
        $next = array_shift($this->script) ?? throw new LogicException('El guion del proveedor falso se agotó.');
        if ($next instanceof Closure) {
            $next = $next($messages, $tools);
        }
        if ($next instanceof ProviderException) {
            throw $next;
        }

        return $next;
    }

    /** Resultado de la última herramienta que recibió el modelo (JSON decodificado). */
    public static function lastToolResult(array $messages): array
    {
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? null) === 'tool') {
                return (array) json_decode((string) $m['content'], true);
            }
        }

        return [];
    }
}
