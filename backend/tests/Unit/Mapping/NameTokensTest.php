<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Services\DataCore\Mapping\Heuristics\NameTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameTokensTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function names(): array
    {
        return [
            'snake_case' => ['numero_factura', ['numero', 'factura']],
            'espacios, mayúsculas y tildes' => ['Fecha Emisión', ['fecha', 'emision']],
            'camelCase' => ['fechaEmision', ['fecha', 'emision']],
            'PascalCase con siglas' => ['NumeroFactura', ['numero', 'factura']],
            'abreviaturas de ERP' => ['COD_ART', ['codigo', 'articulo']],
            'cant' => ['cant', ['cantidad']],
            'nro' => ['nro_doc', ['numero', 'documento']],
            'nombres pegados' => ['codproducto', ['codigo', 'producto']],
            'nombres pegados 2' => ['fechaemision', ['fecha', 'emision']],
            'plural' => ['unidades_vendidas', ['unidad', 'vendida']],
            'plural con vocabulario' => ['nombres', ['nombre']],
            'dígitos pegados se separan' => ['precio2', ['precio', '2']],
            'preposiciones fuera' => ['fecha_de_emision', ['fecha', 'emision']],
            'sufijo at' => ['updated_at', ['modificacion']],
            'solo símbolos' => ['___', []],
            'cadena de inyección no produce nada reconocible' => ['cantidad"; DROP TABLE x;--', ['cantidad', 'drop', 'table', 'x']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('names')]
    public function test_tokenizes_names(string $name, array $expected): void
    {
        $this->assertSame($expected, NameTokens::of($name));
    }

    public function test_is_deterministic_and_folds_accents_and_case(): void
    {
        $this->assertSame(NameTokens::of('Número_Línea'), NameTokens::of('NUMERO_LINEA'));
        // Tildes y mayúsculas se pliegan: «Cántidad» y «cantidad» son el mismo nombre para el léxico (dos columnas así quedan AMBIGUAS: no se elige una a ciegas).
        $this->assertSame(['cantidad'], NameTokens::of('Cántidad'));
        $this->assertSame(['cantidad'], NameTokens::of('CANTIDAD'));
    }
}
