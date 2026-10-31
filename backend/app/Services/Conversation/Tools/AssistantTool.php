<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

/**
 * Herramienta de lista blanca del asistente: SOLO LECTURA de resultados ya calculados, mediante los servicios internos de
 * la API, dentro de la empresa activa (TenantContext), con la misma habilidad y el mismo plan que la ruta equivalente.
 * Una herramienta nunca recibe tenant_id (ni cualquier clave no declarada en su esquema) ni dispara cálculos.
 */
interface AssistantTool
{
    /** Nombre estable expuesto al modelo (snake_case). */
    public function name(): string;

    public function description(): string;

    /** Esquema JSON de los argumentos (additionalProperties = false). */
    public function parameters(): array;

    /** Reglas de Laravel Validator para los argumentos (misma lista cerrada de claves que parameters()). */
    public function rules(): array;

    /** Habilidad exigida por la ruta equivalente de la API (p. ej. forecast.view). */
    public function ability(): string;

    /** Resultado de la consulta; las excepciones de plan/validación las traduce el registro. */
    public function execute(array $arguments, ToolContext $context): ToolResult;

    /** false si la configuración global la oculta (p. ej. herramientas con códigos de compradores). */
    public function isEnabled(): bool;
}
