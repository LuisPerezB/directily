<?php

declare(strict_types=1);

namespace App\Services\Conversation\Providers;

use App\Services\Conversation\Contracts\ConversationProvider;
use App\Services\Conversation\Exceptions\ProviderException;
use App\Services\Conversation\ProviderResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ollama Cloud (API nativa `POST {base_url}/api/chat`, autenticada con `Authorization: Bearer <clave>`).
 *
 * - Clave, endpoint y modelo salen de config/conversation.php (variables CONVERSATION_*); sin clave el proveedor no está
 *   disponible y NO se hace ninguna llamada.
 * - Sin streaming (`stream=false`), sin reintentos automáticos y con tiempos de conexión/respuesta acotados.
 * - Las excepciones nunca incluyen el cuerpo de la respuesta, la clave ni el contenido de la conversación.
 */
final class OllamaCloudProvider implements ConversationProvider
{
    /** @param array<string, mixed> $config sección `conversation.ollama` */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'ollama_cloud';
    }

    public function model(): ?string
    {
        $model = trim((string) ($this->config['model'] ?? ''));

        return $model !== '' ? $model : null;
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if (trim((string) ($this->config['api_key'] ?? '')) === '') {
            return 'missing_api_key';
        }
        if ($this->model() === null) {
            return 'missing_model';
        }
        if (! preg_match('#^https://[^/\s]+#i', (string) ($this->config['base_url'] ?? '')) && ! $this->allowsInsecureLocalUrl()) {
            return 'invalid_base_url';
        }

        return null;
    }

    public function chat(array $messages, array $tools): ProviderResponse
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new ProviderException('El proveedor conversacional no está configurado.', $reason);
        }

        $body = [
            'model' => $this->model(),
            'messages' => array_map($this->toOllamaMessage(...), $messages),
            'stream' => false,
            'options' => [
                'temperature' => (float) ($this->config['temperature'] ?? 0.1),
                'num_predict' => (int) ($this->config['max_output_tokens'] ?? 700),
            ],
        ];
        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $t): array => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters'],
            ]], $tools);
        }

        try {
            $response = Http::withToken((string) $this->config['api_key'])
                ->acceptJson()->asJson()
                ->connectTimeout(max(1, (int) ($this->config['connect_timeout_seconds'] ?? 5)))
                ->timeout(max(1, (int) ($this->config['timeout_seconds'] ?? 30)))
                ->post(rtrim((string) $this->config['base_url'], '/').'/api/chat', $body);
        } catch (ConnectionException) {
            throw new ProviderException('No se pudo conectar con el proveedor conversacional o expiró el tiempo de espera.', 'provider_unreachable');
        } catch (Throwable) {
            throw new ProviderException('Error inesperado al llamar al proveedor conversacional.', 'provider_error');
        }

        if ($response->failed()) {
            $status = $response->status();

            throw new ProviderException(
                "El proveedor conversacional respondió con estado HTTP {$status}.",
                in_array($status, [401, 403], true) ? 'provider_unauthorized' : ($status === 429 ? 'provider_rate_limited' : 'provider_error'),
                $status,
            );
        }

        $json = $response->json();
        $message = is_array($json) ? ($json['message'] ?? null) : null;
        if (! is_array($message)) {
            throw new ProviderException('Respuesta inválida del proveedor conversacional.', 'provider_invalid_response');
        }

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;
            if (! is_array($function) || ! is_string($function['name'] ?? null)) {
                continue;
            }
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            $calls[] = ['name' => $function['name'], 'arguments' => is_array($arguments) ? $arguments : []];
        }

        return new ProviderResponse(
            content: isset($message['content']) && is_string($message['content']) ? $message['content'] : null,
            toolCalls: $calls,
            usage: [
                'prompt_tokens' => isset($json['prompt_eval_count']) ? (int) $json['prompt_eval_count'] : null,
                'completion_tokens' => isset($json['eval_count']) ? (int) $json['eval_count'] : null,
            ],
        );
    }

    /** @param array<string, mixed> $message */
    private function toOllamaMessage(array $message): array
    {
        $out = ['role' => $message['role'], 'content' => (string) ($message['content'] ?? '')];
        if (($message['tool_calls'] ?? []) !== []) {
            $out['tool_calls'] = array_map(fn (array $c): array => ['function' => [
                'name' => $c['name'],
                // El arreglo vacío debe serializarse como objeto JSON ({}), no como lista.
                'arguments' => $c['arguments'] === [] ? new \stdClass : $c['arguments'],
            ]], $message['tool_calls']);
        }
        if (($message['tool_name'] ?? null) !== null) {
            $out['tool_name'] = $message['tool_name'];
        }

        return $out;
    }

    /** Solo en entornos local/testing se admite http:// (p. ej. un Ollama local de desarrollo). */
    private function allowsInsecureLocalUrl(): bool
    {
        return app()->environment(['local', 'testing']) && preg_match('#^http://[^/\s]+#i', (string) ($this->config['base_url'] ?? '')) === 1;
    }
}
