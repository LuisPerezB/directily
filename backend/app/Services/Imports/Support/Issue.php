<?php

declare(strict_types=1);

namespace App\Services\Imports\Support;

/** Error o advertencia de validación de una fila (códigos en docs/06 §2.6). */
final readonly class Issue
{
    public function __construct(
        public string $code,
        public string $severity, // error | warning | info
        public string $message,
        public ?string $column = null,
        public mixed $rawValue = null,
    ) {}

    public static function error(string $code, string $message, ?string $column = null, mixed $raw = null): self
    {
        return new self($code, 'error', $message, $column, $raw);
    }

    public static function warning(string $code, string $message, ?string $column = null, mixed $raw = null): self
    {
        return new self($code, 'warning', $message, $column, $raw);
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    /** @return array<string, mixed> */
    public function toArray(?int $index = null): array
    {
        return array_filter([
            'index' => $index,
            'field' => $this->column,
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
        ], fn ($v) => $v !== null);
    }
}
