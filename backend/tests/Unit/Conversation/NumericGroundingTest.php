<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation;

use App\Services\Conversation\NumericGrounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** El texto del modelo solo puede citar cifras de las herramientas (regla 2 de §6.A: ningún LLM calcula). */
final class NumericGroundingTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function tool(): array
    {
        return [
            'args' => ['horizon' => 30],
            'data' => ['kpis' => ['forecast_units' => 1234.5678, 'change_pct' => 0.2348, 'stockout_risk_products' => 4], 'top' => [['sku' => 'CEM-01'], ['sku' => 'VAR-12']]],
            'envelope' => ['as_of' => '2026-10-20', 'run_version' => 3, 'quality_status' => 'ok'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function grounded(): array
    {
        return [
            'miles es-EC y decimales' => ['Se pronostican 1.234,57 unidades.'],
            'miles en-US' => ['Forecast 1,234.57 units.'],
            'entero redondeado' => ['Unas 1235 unidades.'],
            'porcentaje' => ['Subiría 23,5 % respecto al período previo.'],
            'porcentaje con punto' => ['Cambio de 23.48%.'],
            'fecha ISO' => ['Corte al 2026-10-20.'],
            'horizonte del argumento' => ['A 30 días.'],
            'cantidad de elementos de una lista' => ['Hay 2 productos destacados.'],
            'conteo de la herramienta' => ['4 productos con riesgo de quiebre.'],
            'marcadores de lista' => ["1. Cemento\n2. Varilla"],
            'identificadores con letras' => ['Intervalo P10–P90 de la corrida v3 (H30).'],
            'sin cifras' => ['No hay cifras que citar.'],
        ];
    }

    #[DataProvider('grounded')]
    public function test_numbers_from_the_tool_results_are_accepted(string $text): void
    {
        $this->assertSame([], NumericGrounding::ungrounded($text, [self::tool()]), $text);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function invented(): array
    {
        return [
            'cifra inventada' => ['Venderá 987654 unidades.', ['987654']],
            'suma hecha por el modelo' => ['En total 1234,57 más 4 son 1238,57.', ['1238,57']],
            'porcentaje inventado' => ['Subirá un 41 %.', ['41']],
            'redondeo incorrecto' => ['Unas 1.300 unidades.', ['1.300']],
            'varias' => ['Entre 100 y 200 unidades.', ['100', '200']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('invented')]
    public function test_numbers_not_present_in_the_results_are_reported(string $text, array $expected): void
    {
        $this->assertSame($expected, NumericGrounding::ungrounded($text, [self::tool()]), $text);
    }

    public function test_user_message_numbers_are_allowed_and_no_sources_means_every_number_is_ungrounded(): void
    {
        $this->assertSame([], NumericGrounding::ungrounded('Consultó 500 unidades.', [self::tool(), 'quiero saber de 500 unidades']));
        $this->assertSame(['7'], NumericGrounding::ungrounded('Son 7 días.', []));
    }
}
