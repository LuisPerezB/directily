<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation;

use App\Services\Conversation\ConversationManager;
use App\Services\Conversation\Exceptions\AssistantDisabledException;
use App\Services\Conversation\Exceptions\ProviderException;
use App\Services\Conversation\Providers\NullProvider;
use App\Services\Conversation\Providers\OllamaCloudProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Proveedor Ollama Cloud con HTTP simulado (sin red) y selección del proveedor desde la configuración. */
final class OllamaCloudProviderTest extends TestCase
{
    private const KEY = 'clave-ficticia-de-prueba-123';

    /** @return array<string, mixed> */
    private function config(array $override = []): array
    {
        return array_merge([
            'base_url' => 'https://ollama.example.test', 'api_key' => self::KEY, 'model' => 'modelo-de-prueba',
            'timeout_seconds' => 5, 'connect_timeout_seconds' => 2, 'max_output_tokens' => 100, 'temperature' => 0.1,
        ], $override);
    }

    public function test_without_a_key_the_provider_is_unavailable_and_makes_no_request(): void
    {
        Http::fake();
        $provider = new OllamaCloudProvider($this->config(['api_key' => null]));
        $this->assertFalse($provider->isAvailable());
        $this->assertSame('missing_api_key', $provider->unavailableReason());
        try {
            $provider->chat([['role' => 'user', 'content' => 'hola']], []);
            $this->fail('Debió lanzar ProviderException.');
        } catch (ProviderException $e) {
            $this->assertSame('missing_api_key', $e->reason);
        }
        Http::assertNothingSent();
        $this->assertSame('missing_api_key', (new OllamaCloudProvider($this->config(['api_key' => '   '])))->unavailableReason());
        $this->assertSame('missing_model', (new OllamaCloudProvider($this->config(['model' => ''])))->unavailableReason());
    }

    public function test_request_shape_authentication_and_tool_call_parsing(): void
    {
        Http::fake(['ollama.example.test/*' => Http::response([
            'model' => 'modelo-de-prueba',
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'dashboard_summary', 'arguments' => ['horizon' => 30]]],
                ['function' => ['name' => 'forecast_accuracy', 'arguments' => '{"x":1}']],
                ['function' => ['arguments' => []]],
            ]],
            'done' => true, 'prompt_eval_count' => 12, 'eval_count' => 7,
        ])]);
        $provider = new OllamaCloudProvider($this->config());
        $tools = [['name' => 'dashboard_summary', 'description' => 'Resumen', 'parameters' => ['type' => 'object', 'properties' => ['horizon' => ['type' => 'integer']], 'additionalProperties' => false]]];
        $messages = [
            ['role' => 'system', 'content' => 'reglas'],
            ['role' => 'user', 'content' => 'resumen'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'forecast_accuracy', 'arguments' => []]]],
            ['role' => 'tool', 'tool_name' => 'forecast_accuracy', 'content' => '{"status":"ok"}'],
        ];

        $response = $provider->chat($messages, $tools);

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === 'https://ollama.example.test/api/chat'
                && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $body['model'] === 'modelo-de-prueba' && $body['stream'] === false
                && $body['options']['num_predict'] === 100 && $body['options']['temperature'] === 0.1
                && $body['tools'][0]['type'] === 'function' && $body['tools'][0]['function']['name'] === 'dashboard_summary'
                && $body['messages'][2]['tool_calls'][0]['function']['name'] === 'forecast_accuracy'
                && $body['messages'][3]['tool_name'] === 'forecast_accuracy'
                && str_contains($r->body(), '"arguments":{}');   // argumentos vacíos como objeto JSON, no como lista
        });
        $this->assertTrue($response->hasToolCalls());
        $this->assertSame([['name' => 'dashboard_summary', 'arguments' => ['horizon' => 30]], ['name' => 'forecast_accuracy', 'arguments' => ['x' => 1]]], $response->toolCalls);
        $this->assertSame(['prompt_tokens' => 12, 'completion_tokens' => 7], $response->usage);
    }

    public function test_text_response_is_returned(): void
    {
        Http::fake(['*' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Hola.'], 'done' => true])]);
        $response = (new OllamaCloudProvider($this->config()))->chat([['role' => 'user', 'content' => 'hola']], []);
        $this->assertSame('Hola.', $response->content);
        $this->assertFalse($response->hasToolCalls());
        Http::assertSent(fn (Request $r) => ! array_key_exists('tools', $r->data()));
    }

    public function test_errors_never_leak_the_body_the_key_or_the_conversation(): void
    {
        $secretBody = ['error' => 'detalle interno '.self::KEY.' mensaje del usuario: ana@correo.com'];
        Http::fake(['*' => Http::sequence()->push($secretBody, 401)->push($secretBody, 429)->push($secretBody, 500)]);
        foreach ([401 => 'provider_unauthorized', 429 => 'provider_rate_limited', 500 => 'provider_error'] as $status => $reason) {
            try {
                (new OllamaCloudProvider($this->config()))->chat([['role' => 'user', 'content' => 'ana@correo.com']], []);
                $this->fail('Debió lanzar ProviderException.');
            } catch (ProviderException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($status, $e->httpStatus);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertStringNotContainsString('ana@correo.com', $e->getMessage());
            }
        }

        Http::fake(['*' => Http::response(['unexpected' => true])]);
        $this->expectException(ProviderException::class);
        (new OllamaCloudProvider($this->config()))->chat([['role' => 'user', 'content' => 'hola']], []);
    }

    public function test_timeouts_become_provider_exceptions(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout con '.self::KEY));
        try {
            (new OllamaCloudProvider($this->config()))->chat([['role' => 'user', 'content' => 'hola']], []);
            $this->fail('Debió lanzar ProviderException.');
        } catch (ProviderException $e) {
            $this->assertSame('provider_unreachable', $e->reason);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_plain_http_is_only_accepted_outside_production(): void
    {
        $this->assertNull((new OllamaCloudProvider($this->config(['base_url' => 'http://127.0.0.1:11434'])))->unavailableReason());   // testing
        $this->app['env'] = 'production';
        $this->assertSame('invalid_base_url', (new OllamaCloudProvider($this->config(['base_url' => 'http://ollama.example.test'])))->unavailableReason());
        $this->assertNull((new OllamaCloudProvider($this->config()))->unavailableReason());
    }

    public function test_manager_selects_the_provider_from_configuration(): void
    {
        $manager = new ConversationManager;
        config(['conversation.enabled' => true, 'conversation.provider' => 'ollama_cloud', 'conversation.ollama.api_key' => null]);
        $this->assertInstanceOf(OllamaCloudProvider::class, $manager->provider());
        $this->assertFalse($manager->isEnabled());

        config(['conversation.ollama.api_key' => self::KEY]);
        $this->assertTrue($manager->isEnabled());

        config(['conversation.provider' => 'none']);
        $this->assertInstanceOf(NullProvider::class, $manager->provider());
        config(['conversation.provider' => 'proveedor-inexistente']);
        $this->assertSame('unknown_provider', $manager->provider()->unavailableReason());
        config(['conversation.provider' => 'ollama_cloud', 'conversation.enabled' => false]);
        $this->assertSame('disabled_by_config', $manager->provider()->unavailableReason());

        // Otros proveedores se incorporan sin tocar el orquestador.
        config(['conversation.enabled' => true, 'conversation.provider' => 'otro']);
        $manager->extend('otro', fn () => new NullProvider('otro_proveedor'));
        $this->assertSame('otro_proveedor', $manager->provider()->unavailableReason());

        $this->expectException(AssistantDisabledException::class);
        (new NullProvider)->chat([], []);
    }
}
