<?php

declare(strict_types=1);

namespace App\Services\Radar;

/** Resultado de proponer las oportunidades de una corrida al Decision Engine (idempotente: repetir con los mismos insumos no crea nada nuevo). */
final class MaterializationResult
{
    /**
     * @param  list<int>  $horizons
     * @param  array<string, int>  $byKind  oportunidades NUEVAS por tipo
     * @param  list<string>  $errors  primeros errores (acotados), sin datos del cliente
     */
    public function __construct(
        public readonly ?int $runId,
        public readonly ?string $asOf,
        public readonly array $horizons,
        public readonly int $candidates,
        public readonly int $created,
        public readonly int $existing,
        public readonly int $failed,
        public readonly array $byKind,
        public readonly array $errors,
        public readonly string $policy,
    ) {}

    /** `completed` si todo se propuso; `partial` si alguna falló; `no_run` si aún no hay predicción vigente. */
    public function status(): string
    {
        return match (true) {
            $this->runId === null => 'no_run',
            $this->failed > 0 => 'partial',
            default => 'completed',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status(), 'run_id' => $this->runId, 'as_of' => $this->asOf, 'horizons' => $this->horizons, 'policy' => $this->policy,
            'candidates' => $this->candidates, 'created' => $this->created, 'existing' => $this->existing, 'failed' => $this->failed,
            'created_by_kind' => (object) $this->byKind, 'errors' => $this->errors,
        ];
    }
}
