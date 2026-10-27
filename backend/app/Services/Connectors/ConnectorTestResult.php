<?php

declare(strict_types=1);

namespace App\Services\Connectors;

final readonly class ConnectorTestResult
{
    public function __construct(public bool $ok, public string $message, public array $details = [])
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'details' => $this->details];
    }
}
