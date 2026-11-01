<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

/**
 * Resultado de una herramienta TAL COMO lo devuelven los servicios internos: `data` (cifras) y `envelope` (procedencia,
 * ADR-V2-03). Estados: ok | no_data (aún no hay resultados calculados) | forbidden | plan_limit | invalid_arguments |
 * not_found | tool_not_allowed | error.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>|null  $envelope
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $data = null,
        public readonly ?array $envelope = null,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {}

    /** @param array<string, mixed> $data @param array<string, mixed>|null $envelope @param array<string, mixed> $meta */
    public static function ok(array $data, ?array $envelope = null, array $meta = []): self
    {
        return new self('ok', $data, $envelope, null, $meta);
    }

    /** @param array<string, mixed>|null $envelope @param array<string, mixed>|null $data */
    public static function noData(string $message, ?array $envelope = null, ?array $data = null): self
    {
        return new self('no_data', $data, $envelope, $message);
    }

    public static function failure(string $status, string $message): self
    {
        return new self($status, null, null, $message);
    }

    /** ok y no_data son respuestas válidas con las que el modelo puede explicar; el resto son rechazos. */
    public function isUsable(): bool
    {
        return in_array($this->status, ['ok', 'no_data'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
            'envelope' => $this->envelope,
        ], fn ($v) => $v !== null);
    }
}
