<?php

declare(strict_types=1);

namespace App\Services\Conversation\Contracts;

use App\Services\Conversation\Exceptions\ProviderException;
use App\Services\Conversation\ProviderResponse;

/**
 * Proveedor LLM de la capa conversacional (ADR-V2-12). Solo razona sobre el texto y elige herramientas de la lista blanca:
 * no accede a la base de datos ni al motor, y su salida nunca se usa como cifra (ver NumericGrounding).
 *
 * Mensajes: [{role: system|user|assistant|tool, content, tool_calls?: [{name, arguments}], tool_name?}].
 * Herramientas: [{name, description, parameters: <JSON Schema>}].
 */
interface ConversationProvider
{
    /** Identificador estable del proveedor (ollama_cloud, none, ...). */
    public function name(): string;

    /** Modelo configurado (null si no aplica). */
    public function model(): ?string;

    /** false = sin configuración suficiente (p. ej. falta la clave): el servicio queda desactivado. */
    public function isAvailable(): bool;

    /** Motivo de indisponibilidad (null si está disponible): missing_api_key, provider_disabled, ... */
    public function unavailableReason(): ?string;

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     *
     * @throws ProviderException si el proveedor falla, expira o responde algo inválido
     */
    public function chat(array $messages, array $tools): ProviderResponse;
}
