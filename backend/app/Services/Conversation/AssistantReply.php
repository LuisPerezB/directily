<?php

declare(strict_types=1);

namespace App\Services\Conversation;

/**
 * Respuesta del asistente. `answer` es la explicación en texto; las cifras y su procedencia están en `figures`, tal como las
 * devolvieron las herramientas (con su envoltura ADR-V2-03).
 *
 * status: answered | no_applicable_tool | tool_denied | no_answer | ungrounded_rejected
 */
final class AssistantReply
{
    public const NOTICE = 'El texto solo explica resultados ya calculados por Predictily; las cifras y su procedencia (corrida, fecha de corte, horizonte, calidad) están en «figures», tal como las devolvió cada herramienta. El asistente no calcula ni estima.';

    /**
     * @param  list<array<string, mixed>>  $figures
     * @param  list<string>  $ungroundedNumbers
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly string $status,
        public readonly string $answer,
        public readonly array $figures,
        public readonly array $ungroundedNumbers,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly array $usage,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'answer' => $this->answer,
            'figures' => $this->figures,
            'grounding' => ['checked' => true, 'ungrounded_numbers' => $this->ungroundedNumbers],
            'provider' => ['name' => $this->provider, 'model' => $this->model],
            'usage' => $this->usage,
            'notice' => self::NOTICE,
        ];
    }
}
