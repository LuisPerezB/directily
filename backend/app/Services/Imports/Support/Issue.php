<?php

declare(strict_types=1);

namespace App\Services\Imports\Support;

/**
 * Error o advertencia de validación de una fila (códigos en docs/06 §2.6).
 *
 * `dqClass` es la clase de calidad del hallazgo (ADR-V25-05): hoy solo se usa `REVIEW_REQUIRED` (el valor no se reconoce y
 * un humano debe mapearlo por fuente; el registro no se escribe). No se persiste como columna: se deriva del código
 * (`DQ_CLASS_BY_CODE`) tanto al emitir el hallazgo como al leer `import_errors`.
 */
final readonly class Issue
{
    public const DQ_REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /** Códigos cuyo hallazgo exige revisión humana => clase de calidad. */
    public const DQ_CLASS_BY_CODE = [
        'unknown_document_type' => self::DQ_REVIEW_REQUIRED,
        'unknown_document_status' => self::DQ_REVIEW_REQUIRED,
    ];

    public function __construct(
        public string $code,
        public string $severity, // error | warning | info
        public string $message,
        public ?string $column = null,
        public mixed $rawValue = null,
        public ?string $dqClass = null,
    ) {}

    public static function error(string $code, string $message, ?string $column = null, mixed $raw = null): self
    {
        return new self($code, 'error', $message, $column, $raw, self::dqClassFor($code));
    }

    public static function warning(string $code, string $message, ?string $column = null, mixed $raw = null): self
    {
        return new self($code, 'warning', $message, $column, $raw, self::dqClassFor($code));
    }

    /** Clase de calidad asociada a un código de hallazgo (null = sin clase especial). */
    public static function dqClassFor(string $code): ?string
    {
        return self::DQ_CLASS_BY_CODE[$code] ?? null;
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
            'dq_class' => $this->dqClass,
        ], fn ($v) => $v !== null);
    }
}
