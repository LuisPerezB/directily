<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Conversation\Exceptions\AssistantDisabledException;
use App\Services\Conversation\Exceptions\ProviderException;
use App\Services\Conversation\Tools\ToolContext;
use App\Services\Conversation\Tools\ToolRegistry;
use App\Services\Conversation\Tools\ToolResult;
use App\Services\Plans\PlanLimitService;
use App\Support\Privacy\PiiMasker;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador de la capa conversacional OPCIONAL (ADR-V2-12). El modelo solo elige herramientas de la lista blanca y explica
 * lo que devuelven; no calcula. Garantías:
 *  - sin proveedor disponible → AssistantDisabledException (503) y ninguna llamada de red;
 *  - sin herramienta aplicable (o sin permiso) → respuesta fija «no puedo», nunca texto libre del modelo;
 *  - con herramientas, el texto del modelo se descarta si contiene cifras que no proceden de ellas (NumericGrounding);
 *  - tamaño de mensaje, rondas, llamadas, tiempo total, tasa y tope diario acotados;
 *  - no se registran mensajes, respuestas ni datos de las herramientas (solo metadatos en log y auditoría);
 *  - al proveedor solo llegan el mensaje (con PII enmascarada) y resultados de herramientas saneados por PiiMasker.
 */
final class AssistantService
{
    private const MAX_ANSWER_CHARS = 3000;

    public function __construct(
        private readonly ConversationManager $manager,
        private readonly ToolRegistry $tools,
        private readonly AssistantLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $plans,
    ) {}

    /** El plan puede desactivar el asistente con `features.assistant = false` (ausente = incluido). */
    public function planAllows(Tenant $tenant): bool
    {
        return (bool) ($this->plans->limits($tenant)['features']['assistant'] ?? true);
    }

    /** @return array{enabled: bool, reason: string|null, provider: string, model: string|null} */
    public function availability(Tenant $tenant): array
    {
        $provider = $this->manager->provider();
        $reason = $provider->unavailableReason();
        if ($reason === null && ! $this->planAllows($tenant)) {
            $reason = 'disabled_for_plan';
        }

        return ['enabled' => $reason === null, 'reason' => $reason, 'provider' => $provider->name(), 'model' => $provider->model()];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  contexto previo (solo user/assistant)
     *
     * @throws AssistantDisabledException
     * @throws PlanLimitExceededException
     * @throws Exceptions\AssistantRateLimitedException
     * @throws ProviderException
     */
    public function reply(ToolContext $context, string $message, array $history = []): AssistantReply
    {
        $provider = $this->manager->provider();
        if (! $provider->isAvailable()) {
            throw new AssistantDisabledException($provider->unavailableReason() ?? 'provider_disabled');
        }
        if (! $this->planAllows($context->tenant)) {
            throw new PlanLimitExceededException('features.assistant', null, null, 'El asistente conversacional no está incluido en su plan.');
        }
        $this->limiter->hit($context);

        $limits = (array) config('conversation.limits');
        $started = microtime(true);
        $deadline = $started + max(1, (int) ($limits['deadline_seconds'] ?? 45));
        $maxRounds = max(1, (int) ($limits['max_tool_rounds'] ?? 3));
        $maxCallsPerRound = max(1, (int) ($limits['max_tool_calls_per_round'] ?? 3));
        $specs = $this->tools->specsFor($context);
        $today = now($context->tenant->timezone ?: 'America/Guayaquil')->toDateString();

        $messages = [['role' => 'system', 'content' => SystemPrompt::build($today)]];
        $userTexts = [$message];
        foreach ($this->cleanHistory($history, $limits) as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => PiiMasker::mask($turn['content'])];
            $userTexts[] = $turn['content'];
        }
        $messages[] = ['role' => 'user', 'content' => PiiMasker::mask($message)];

        /** @var list<array<string, mixed>> $figures */
        $figures = [];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'rounds' => 0, 'tool_calls' => 0];
        $final = null;

        try {
            for ($round = 0; $round <= $maxRounds; $round++) {
                // Última ronda sin herramientas: obliga al modelo a responder con lo que ya tiene.
                $response = $provider->chat($messages, $round < $maxRounds ? $specs : []);
                $usage['rounds']++;
                $usage['prompt_tokens'] += (int) ($response->usage['prompt_tokens'] ?? 0);
                $usage['completion_tokens'] += (int) ($response->usage['completion_tokens'] ?? 0);

                if (! $response->hasToolCalls()) {
                    $final = $response->content;
                    break;
                }
                if ($round >= $maxRounds || microtime(true) > $deadline) {
                    break;
                }

                $calls = array_slice($response->toolCalls, 0, $maxCallsPerRound);
                $messages[] = ['role' => 'assistant', 'content' => (string) ($response->content ?? ''), 'tool_calls' => $calls];
                foreach ($calls as $call) {
                    $usage['tool_calls']++;
                    $result = $this->tools->execute($call['name'], $call['arguments'], $context);
                    $figures[] = ['tool' => $call['name'], 'arguments' => $call['arguments'] === [] ? (object) [] : $call['arguments']] + $result->toArray();
                    $messages[] = ['role' => 'tool', 'tool_name' => $call['name'], 'content' => $this->forProvider($result, (int) ($limits['max_tool_result_chars'] ?? 12000))];
                }
            }
        } catch (ProviderException $e) {
            $this->audit->log('assistant.provider_error', null, ['provider' => $provider->name(), 'reason' => $e->reason, 'http_status' => $e->httpStatus, 'tool_calls' => $usage['tool_calls']]);
            Log::warning('assistant.provider_error', ['tenant_id' => $context->tenant->getKey(), 'provider' => $provider->name(), 'reason' => $e->reason, 'http_status' => $e->httpStatus]);

            throw $e;
        }

        $reply = $this->decide($context, $provider->name(), $provider->model(), $message, $userTexts, $final, $figures, $usage);
        $this->record($context, $reply, $message, $started);

        return $reply;
    }

    /**
     * @param  list<string>  $userTexts
     * @param  list<array<string, mixed>>  $figures
     * @param  array<string, mixed>  $usage
     */
    private function decide(ToolContext $context, string $provider, ?string $model, string $message, array $userTexts, ?string $final, array $figures, array $usage): AssistantReply
    {
        $usable = array_values(array_filter($figures, fn (array $f) => in_array($f['status'], ['ok', 'no_data'], true)));
        $denied = array_values(array_filter($figures, fn (array $f) => in_array($f['status'], ['forbidden', 'plan_limit'], true)));
        $final = $final !== null ? trim($final) : null;

        if ($usable === []) {
            if ($denied !== []) {
                $reasons = implode(' ', array_values(array_unique(array_map(fn (array $f) => (string) ($f['message'] ?? ''), $denied))));

                return new AssistantReply('tool_denied', 'No puedo mostrar esa información con sus permisos o su plan actuales. '.$reasons, $figures, [], $provider, $model, $usage);
            }
            $descriptions = array_map(fn ($t) => $t->description(), $this->tools->availableFor($context));
            // Descripciones cortas: solo la primera frase.
            $descriptions = array_map(fn (string $d) => rtrim(explode(':', $d, 2)[0], '. '), $descriptions);

            return new AssistantReply('no_applicable_tool', SystemPrompt::noApplicableTool($descriptions), $figures, [], $provider, $model, $usage);
        }

        if ($final === null || $final === '') {
            return new AssistantReply('no_answer', 'No se obtuvo una explicación del asistente. Consulte las cifras calculadas adjuntas.', $figures, [], $provider, $model, $usage);
        }

        $sources = [array_map(fn (array $f) => [$f['arguments'] ?? [], $f['data'] ?? [], $f['envelope'] ?? []], $usable), $userTexts, $message];
        $ungrounded = NumericGrounding::ungrounded($final, $sources);
        if ($ungrounded !== []) {
            return new AssistantReply(
                'ungrounded_rejected',
                'El texto generado incluía cifras que no proceden de los resultados calculados, por lo que se descartó. Consulte las cifras calculadas adjuntas.',
                $figures,
                $ungrounded,
                $provider,
                $model,
                $usage,
            );
        }

        return new AssistantReply('answered', mb_substr(PiiMasker::mask($final), 0, self::MAX_ANSWER_CHARS), $figures, [], $provider, $model, $usage);
    }

    private function record(ToolContext $context, AssistantReply $reply, string $message, float $started): void
    {
        $statuses = array_count_values(array_map(fn (array $f) => (string) $f['status'], $reply->figures));
        $meta = [
            'status' => $reply->status,
            'provider' => $reply->provider,
            'model' => $reply->model,
            'tools' => array_values(array_unique(array_map(fn (array $f) => (string) $f['tool'], $reply->figures))),
            'tool_statuses' => $statuses,
            'rounds' => $reply->usage['rounds'],
            'tool_calls' => $reply->usage['tool_calls'],
            'prompt_tokens' => $reply->usage['prompt_tokens'],
            'completion_tokens' => $reply->usage['completion_tokens'],
            'message_chars' => mb_strlen($message),
            'ungrounded_count' => count($reply->ungroundedNumbers),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        $this->audit->log('assistant.message', null, $meta);
        // Solo metadatos: nunca el mensaje, la respuesta ni los datos de las herramientas.
        Log::info('assistant.message', ['tenant_id' => $context->tenant->getKey(), 'user_id' => $context->user?->getKey()] + $meta);
    }

    /**
     * Resultado de una herramienta para el proveedor: saneado (PiiMasker) y acotado en tamaño.
     */
    private function forProvider(ToolResult $result, int $maxChars): string
    {
        $payload = PiiMasker::sanitize($result->toArray());
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        foreach ([10, 5, 3, 1] as $rows) {
            $json = (string) json_encode(self::limitLists($payload, $rows, $truncated), $flags);
            if (mb_strlen($json) <= $maxChars) {
                return $truncated ? substr($json, 0, -1).',"_truncated":true}' : $json;
            }
        }

        return (string) json_encode(['status' => 'error', 'message' => 'El resultado es demasiado grande para resumirlo.'], $flags);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function limitLists(array $data, int $rows, ?bool &$truncated = false): array
    {
        $truncated = false;
        $walk = function (array $node) use (&$walk, $rows, &$truncated): array {
            if (array_is_list($node) && count($node) > $rows) {
                $node = array_slice($node, 0, $rows);
                $truncated = true;
            }
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $node[$k] = $walk($v);
                }
            }

            return $node;
        };

        return $walk($data);
    }

    /**
     * @param  list<mixed>  $history
     * @param  array<string, mixed>  $limits
     * @return list<array{role: string, content: string}>
     */
    private function cleanHistory(array $history, array $limits): array
    {
        $clean = [];
        foreach ($history as $turn) {
            if (! is_array($turn) || ! in_array($turn['role'] ?? null, ['user', 'assistant'], true) || ! is_string($turn['content'] ?? null)) {
                continue;
            }
            $content = trim(mb_substr($turn['content'], 0, (int) ($limits['max_history_chars'] ?? 1500)));
            if ($content !== '') {
                $clean[] = ['role' => $turn['role'], 'content' => $content];
            }
        }

        return array_slice($clean, -max(0, (int) ($limits['max_history_messages'] ?? 6)));
    }
}
