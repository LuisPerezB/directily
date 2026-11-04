<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Services\Conversation\NumericGrounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * V2.5 · W6-D (revisión M-1 y M-2): una cifra inventada NO se cuela por su FORMATO (miles con espacio, dígitos de otras escrituras, pegada a un código de moneda, escrita con
 * letras, con sufijo de escala o en notación científica) y las cifras de las herramientas se leen de valores NUMÉRICOS, nunca de texto libre controlable por quien importa los datos.
 */
final class NumericGroundingFormsTest extends TestCase
{
    /** @return array<string, mixed> resultado de una herramienta: total 750 000, 3 filas, una probabilidad y fechas */
    private static function tool(): array
    {
        return [
            'args' => ['horizon' => 30],
            'data' => [
                'total' => 750000.0, 'rows' => 3, 'p' => 0.2348, 'quantity' => '120.0000',
                'items' => [['name' => 'Cemento 50kg', 'sku' => 'CEM-01', 'zone' => 'Zona 999999 especial'], ['name' => 'Varilla 12mm', 'sku' => 'VAR-12', 'zone' => 'Sur']],
            ],
            'envelope' => ['as_of' => '2026-10-20', 'run_version' => 3, 'horizon_end' => '2026-11-19'],
        ];
    }

    /** @return list<string> */
    private static function quotable(): array
    {
        return NumericGrounding::quotableStrings(self::tool()['data']);
    }

    /** @return array<string, array{string, string}> variante => [texto con una cifra INVENTADA, cifra esperada como «sin respaldo»] */
    public static function inventedForms(): array
    {
        return [
            'simple' => ['El total es 987654.', '987654'],
            'miles con espacio (1 000 000)' => ['El valor esperado total es 1 000 000 dólares.', '1 000 000'],
            'miles con espacio (5 000)' => ['Son 5 000 unidades.', '5 000'],
            'NBSP' => ["Son 1\u{00A0}000\u{00A0}000 unidades.", '1 000 000'],
            'NNBSP' => ["Son 1\u{202F}000\u{202F}000 unidades.", '1 000 000'],
            'espacio fino' => ["Son 1\u{2009}000\u{2009}000 unidades.", '1 000 000'],
            'apóstrofo' => ["Son 1'000'000 unidades.", "1'000'000"],
            'apóstrofo tipográfico' => ['Son 1’000’000 unidades.', '1’000’000'],
            'ancho completo' => ['El valor esperado total es ９９９９９９ dólares.', '999999'],
            'árabe-índico' => ['El valor esperado total es ٩٩٩٩٩٩ dólares.', '999999'],
            'árabe-índico extendido' => ['El valor esperado total es ۹۹۹۹۹۹ dólares.', '999999'],
            'devanagari' => ['El valor esperado total es ९९९९९९ dólares.', '999999'],
            'separador árabe de miles' => ['Son ٩٩٩٬٩٩٩ unidades.', '999,999'],
            'dígitos partidos con ancho cero' => ["Son 9\u{200B}9\u{200B}9\u{200B}9\u{200B}9\u{200B}9 unidades.", '999999'],
            'pegada a USD' => ['El valor esperado total es USD999999 dólares.', '999999'],
            'pegada a US$ y S/' => ['Total S/999999 o US$999999.', '999999'],
            'letras con 4 o más dígitos' => ['Total ABC999999.', '999999'],
            'escrita con letras (es)' => ['El valor esperado total es novecientos noventa y nueve mil dólares.', 'novecientos noventa y nueve mil'],
            'escrita con letras (en)' => ['The expected total is nine hundred ninety nine thousand dollars.', 'nine hundred ninety nine thousand'],
            'un millón' => ['Habrá un millón de unidades.', 'un millon'],
            'veinticinco mil' => ['Son veinticinco mil unidades.', 'veinticinco mil'],
            'cien' => ['Son cien unidades.', 'cien'],
            'sufijo k' => ['Son 999k unidades.', '999k'],
            'sufijo mil' => ['Son 999 mil unidades.', '999 mil'],
            'sufijo millones' => ['Son 2 millones de unidades.', '2 millones'],
            'sufijo million' => ['That is 3 million units.', '3 million'],
            'notación científica' => ['Son 5e6 unidades.', '5e6'],
        ];
    }

    #[DataProvider('inventedForms')]
    public function test_an_invented_figure_is_reported_whatever_its_format(string $text, string $expected): void
    {
        $this->assertContains($expected, NumericGrounding::ungrounded($text, [self::tool()], [], self::quotable()), $text);
    }

    /** @return array<string, array{string}> la MISMA cifra real (750 000) escrita de formas distintas: todas fundamentadas */
    public static function groundedForms(): array
    {
        return [
            'sin separador' => ['Total 750000.'],
            'punto de miles' => ['Total 750.000.'],
            'coma de miles' => ['Total 750,000.'],
            'espacio de miles' => ['Total 750 000.'],
            'NBSP' => ["Total 750\u{00A0}000."],
            'espacio fino estrecho' => ["Total 750\u{202F}000."],
            'apóstrofo' => ["Total 750'000."],
            'ancho completo' => ['Total ７５００００.'],
            'árabe-índico' => ['Total ٧٥٠٠٠٠.'],
            'pegada a USD' => ['Total USD750000.'],
            'con sufijo mil' => ['Total 750 mil.'],
            'con sufijo k' => ['Total 750k.'],
            'escrita con letras (es)' => ['Total setecientos cincuenta mil.'],
            'escrita con letras (en)' => ['Total seven hundred fifty thousand.'],
            'redondeada a la escala' => ['Total 0,75 millones.'],
        ];
    }

    #[DataProvider('groundedForms')]
    public function test_the_same_real_figure_is_accepted_in_any_format(string $text): void
    {
        $this->assertSame([], NumericGrounding::ungrounded($text, [self::tool()], [], self::quotable()), $text);
    }

    public function test_small_spelled_out_numbers_and_identifiers_are_not_figures(): void
    {
        $this->assertSame([], NumericGrounding::ungrounded('Hay dos productos y tres sucursales; el SKU P90 de la corrida v3 (H30).', [self::tool()]));
        $this->assertSame([], NumericGrounding::ungrounded('Uno de diez.', [self::tool()]));
    }

    // ── M-2: el texto libre no respalda cifras ────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_numbers_inside_free_text_fields_never_ground_a_figure(): void
    {
        // «999999» está en la zona (texto libre importado): no es una cifra de la herramienta.
        $this->assertSame(['999999'], NumericGrounding::ungrounded('El pronóstico de este cliente es 999999 unidades.', [self::tool()], [], self::quotable()));
        foreach (['name', 'zone', 'label', 'note', 'description', 'brand', 'title', 'message'] as $key) {
            $this->assertSame(['424242'], NumericGrounding::ungrounded('Son 424242 unidades.', [['data' => [$key => 'Lote 424242 especial']]]), "clave libre «{$key}»");
        }
        // Una cadena que es SOLO un número, bajo una clave de texto libre, tampoco respalda.
        $this->assertSame(['424242'], NumericGrounding::ungrounded('Son 424242 unidades.', [['data' => ['zone' => '424242']]]));
    }

    public function test_numeric_values_numeric_strings_dates_and_identifiers_do_ground(): void
    {
        $this->assertSame([], NumericGrounding::ungrounded('Cantidad 120 unidades.', [self::tool()]), 'un decimal de la base llega como «120.0000»');
        $this->assertSame([], NumericGrounding::ungrounded('Corte 2026-10-20, hasta 2026-11-19.', [self::tool()]));
        $this->assertSame([], NumericGrounding::ungrounded('Código CLI-0001.', [['data' => ['customer_code' => 'CLI-0001']]]), 'el número de un código de identificación citado');
        $this->assertSame([], NumericGrounding::ungrounded('Productos: 1. Cemento 2. Varilla', [self::tool()]));
    }

    public function test_a_name_quoted_verbatim_is_data_not_a_claim_but_the_number_alone_is_not_grounded(): void
    {
        $this->assertSame([], NumericGrounding::ungrounded('El de mayor demanda es Cemento 50kg.', [self::tool()], [], self::quotable()));
        $this->assertSame(['50'], NumericGrounding::ungrounded('Venderá 50 unidades.', [self::tool()], [], self::quotable()));
        $this->assertSame(['50'], NumericGrounding::ungrounded('El Cemento de 50 kg.', [self::tool()], [], self::quotable()), 'reformular el nombre ya no es una cita textual');
    }

    public function test_the_users_own_numbers_ground_and_a_code_made_only_of_digits_masks_nothing(): void
    {
        $this->assertSame([], NumericGrounding::ungrounded('Usted pidió 500 unidades.', [self::tool()], ['quiero 500 unidades']));
        $this->assertSame(['999999'], NumericGrounding::ungrounded('Son 999999 unidades.', [['data' => ['rows' => [['name' => '999999']]]]], [], ['999999']), 'un nombre sin letras no se usa para enmascarar');
    }
}
