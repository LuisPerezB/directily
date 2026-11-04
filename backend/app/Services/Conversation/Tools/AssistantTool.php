<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Services\Conversation\Gateway\DataScope;

/**
 * Herramienta de lista blanca del asistente: SOLO LECTURA de resultados ya calculados, mediante los servicios internos de
 * la API, dentro de la empresa activa (TenantContext), con la misma habilidad y el mismo plan que la ruta equivalente.
 * Una herramienta nunca recibe tenant_id (ni cualquier clave no declarada en su esquema) ni dispara cálculos.
 *
 * Cada herramienta declara su contrato completo (V2.5 · W6-D; ADR-V25-17): nombre, esquema de argumentos, habilidad exigida, alcance de datos y el resumen
 * mínimo de su resultado para la auditoría. El gateway las ejecuta; ninguna se ejecuta por otro camino.
 */
interface AssistantTool
{
    /** Nombre estable expuesto al modelo (snake_case, ≤ 60 caracteres). */
    public function name(): string;

    public function description(): string;

    /** Esquema JSON de los argumentos (additionalProperties = false). */
    public function parameters(): array;

    /** Reglas de Laravel Validator para los argumentos (misma lista cerrada de claves que parameters()). */
    public function rules(): array;

    /** Habilidad exigida por la ruta equivalente de la API (p. ej. forecast.view). */
    public function ability(): string;

    /** Alcance de datos: decide, con el rol comercial del usuario, si puede ver este tipo de dato. */
    public function dataScope(): DataScope;

    /**
     * Resultado de la consulta; las excepciones de plan/validación las traduce el gateway. Las herramientas por cliente leen su alcance de `$context->requireScope()`.
     */
    public function execute(array $arguments, ToolContext $context): ToolResult;

    /**
     * Resumen del resultado para `gateway_tool_calls.result_summary`: estado, procedencia y tamaños. Sin valores de negocio ni texto de la empresa.
     *
     * @return array<string, mixed>
     */
    public function summarize(ToolResult $result): array;

    /** false si la configuración global la oculta (p. ej. herramientas con códigos de compradores). */
    public function isEnabled(): bool;
}
