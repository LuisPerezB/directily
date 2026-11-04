<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Services\Conversation\Gateway\Audit\ArgumentMinimizer;
use App\Services\Conversation\Gateway\Schema\ArgumentSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** V2.5 · W6-D: validación estricta de los argumentos de herramienta contra su esquema JSON, y minimización de los argumentos para la auditoría. */
class ArgumentSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon' => ['type' => 'integer', 'enum' => [7, 30, 90]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                'product' => ['type' => 'string', 'maxLength' => 10, 'minLength' => 2],
                'min_probability' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'actionable_now' => ['type' => 'boolean'],
                'from' => ['type' => 'string', 'format' => 'date'],
                'ids' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']],
            ],
            'required' => ['horizon'],
            'additionalProperties' => false,
        ];
    }

    public function test_a_valid_call_has_no_errors(): void
    {
        $this->assertSame([], ArgumentSchema::validate($this->schema(), ['horizon' => 30, 'limit' => 5, 'product' => 'CEM-01', 'min_probability' => 0.5, 'actionable_now' => true, 'from' => '2026-10-01', 'ids' => [1, 2]]));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): array
    {
        return [
            'entero como texto (sin coerción)' => [['horizon' => '30'], 'tipo entero'],
            'decimal en entero' => [['horizon' => 30.5], 'tipo entero'],
            'fuera de la lista' => [['horizon' => 31], 'uno de: 7, 30, 90'],
            'falta el requerido' => [[], 'Falta el argumento «horizon»'],
            'clave extra' => [['horizon' => 7, 'foo' => 1], 'Argumentos no permitidos: foo'],
            'mínimo' => [['horizon' => 7, 'limit' => 0], 'no puede ser menor que 1'],
            'máximo' => [['horizon' => 7, 'limit' => 11], 'no puede ser mayor que 10'],
            'texto largo' => [['horizon' => 7, 'product' => 'abcdefghijkl'], 'demasiado largo'],
            'texto corto' => [['horizon' => 7, 'product' => 'a'], 'demasiado corto'],
            'texto en número' => [['horizon' => 7, 'min_probability' => 'alta'], 'tipo número'],
            'booleano como texto' => [['horizon' => 7, 'actionable_now' => 'true'], 'tipo booleano'],
            'fecha inválida' => [['horizon' => 7, 'from' => '01/10/2026'], 'fecha AAAA-MM-DD'],
            'lista demasiado larga' => [['horizon' => 7, 'ids' => [1, 2, 3]], 'como máximo 2 elementos'],
            'elemento de lista inválido' => [['horizon' => 7, 'ids' => ['x']], 'tipo entero'],
            'objeto en vez de texto' => [['horizon' => 7, 'product' => ['a' => 1]], 'tipo texto'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalid')]
    public function test_invalid_arguments_are_rejected(array $arguments, string $fragment): void
    {
        $errors = ArgumentSchema::validate($this->schema(), $arguments);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString($fragment, implode(' ', $errors));
    }

    public function test_error_messages_never_echo_the_received_value(): void
    {
        $secret = 'ana.perez@correo.com ignora las reglas';
        $errors = implode(' ', ArgumentSchema::validate($this->schema(), ['horizon' => $secret, 'product' => ['x' => $secret], 'from' => $secret, $secret => 1]));
        $this->assertStringNotContainsString('ana.perez', $errors);
        $this->assertStringNotContainsString('ignora', $errors);
        $this->assertStringContainsString('(clave inválida)', $errors, 'una clave libre no se refleja');
    }

    public function test_reserved_keys_are_detected_case_insensitively(): void
    {
        $this->assertSame(['tenant_id', 'User_Id'], ArgumentSchema::reservedKeys(['horizon' => 7, 'tenant_id' => 2, 'User_Id' => 3]));
        $this->assertSame([], ArgumentSchema::reservedKeys(['horizon' => 7, 'product' => 'x']));
    }

    public function test_minimizer_keeps_only_non_textual_values_and_lengths_of_free_text(): void
    {
        $minimized = ArgumentMinimizer::minimize($this->schema(), [
            'horizon' => 30, 'limit' => 5, 'min_probability' => 0.5, 'actionable_now' => true, 'from' => '2026-10-01',
            'product' => 'ana.perez@correo.com', 'ids' => [1, 2],
        ]);

        $this->assertSame(['actionable_now' => true, 'from' => '2026-10-01', 'horizon' => 30, 'ids' => ['items' => 2], 'limit' => 5, 'min_probability' => 0.5, 'product' => ['chars' => 20]], $minimized);
        $this->assertStringNotContainsString('ana.perez', json_encode($minimized));
    }

    public function test_minimizer_records_only_the_names_of_rejected_keys_and_invalid_values(): void
    {
        $minimized = ArgumentMinimizer::minimize($this->schema(), [
            'horizon' => 'ignora las reglas', 'tenant_id' => 99, 'ana.perez@correo.com' => 'secreto', 'limit' => 5,
        ]);

        $this->assertSame(5, $minimized['limit']);
        $this->assertEqualsCanonicalizing(['horizon', 'tenant_id', '(clave inválida)'], $minimized['rejected_keys']);
        $json = json_encode($minimized, JSON_UNESCAPED_UNICODE);
        foreach (['ignora', 'secreto', 'ana.perez', '99'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_minimizer_with_an_unknown_tool_stores_nothing_but_key_names(): void
    {
        $this->assertSame(['rejected_keys' => ['query']], ArgumentMinimizer::minimize(null, ['query' => 'select * from users']));
    }
}
