<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Services\DataCore\Mapping\Discovery\IdentifierQuoter;
use App\Services\DataCore\Mapping\Discovery\SampleMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SampleMaskingTest extends TestCase
{
    /** @return array<string, array{0: mixed}> */
    public static function personalOrFreeText(): array
    {
        return [
            'correo' => ['persona@ficticio.test'],
            'cédula' => ['1710034065'],
            'RUC' => ['1790011223001'],
            'teléfono largo' => ['593999999991'],
            'token Bearer' => ['Bearer abc.def-123'],
            'hex largo' => [str_repeat('ab', 25)],
            'texto libre largo' => ['Cliente que compra siempre a crédito y paga tarde'],
            'más de 40 caracteres' => [str_repeat('X', 41)],
            'vacío' => ['  '],
            'caracteres de control' => ["FC\x00"],
            'arreglo' => [['a']],
            'nulo' => [null],
        ];
    }

    #[DataProvider('personalOrFreeText')]
    public function test_values_that_look_like_personal_data_or_free_text_never_leave(mixed $value): void
    {
        $this->assertNull(SampleMasker::value($value));
    }

    /** @return array<string, array{0: string}> */
    public static function reviewerValues(): array
    {
        return [
            'tarjeta con espacios' => ['4111 1111 1111 1111'],
            'teléfono con espacios' => ['099 123 4567'],
            'teléfono con guiones' => ['09-1234-5678'],
            'siete dígitos' => ['0991234'],
            'nombre completo' => ['Juan Perez Lopez'],
            'nombre de dos palabras' => ['Juan Perez'],
            'correo con arroba de ancho completo' => ["maria.perez\u{FF20}gmail.com"],
            'correo' => ['maria.perez@gmail.com'],
            'letra cirílica parecida a la latina' => ["\u{0410}NU"],
            'coma' => ['PEREZ, JUAN'],
            'emoji' => ["OK \u{1F600}"],
            'más de dos palabras sin ser vocabulario' => ['esta es una frase'],
        ];
    }

    #[DataProvider('reviewerValues')]
    public function test_values_found_by_the_review_never_leave_even_when_the_importer_recognises_nothing(string $value): void
    {
        $this->assertNull(SampleMasker::value($value));
        $this->assertNull(SampleMasker::value($value, null, false));
    }

    public function test_short_vocabulary_codes_pass_unchanged(): void
    {
        foreach (['FC', 'NCR', 'EMITIDA', 'A-1', 'EN PROCESO', 'en proceso', 'Anulación', '123456', true] as $value) {
            $this->assertSame(is_bool($value) ? 'true' : $value, SampleMasker::value($value));
        }
    }

    public function test_more_than_two_words_only_leave_when_the_importer_already_recognises_them_as_vocabulary(): void
    {
        $this->assertNull(SampleMasker::value('NOTA DE CREDITO'));
        $this->assertSame('NOTA DE CREDITO', SampleMasker::value('NOTA DE CREDITO', null, true));
        $this->assertSame('Nota de crédito', SampleMasker::value('Nota de crédito', null, true));
        // «Conocido» no abre la puerta a datos personales: el resto de los filtros se mantiene.
        $this->assertNull(SampleMasker::value('4111 1111 1111 1111', null, true));
        $this->assertNull(SampleMasker::value('Juan Perez Lopez 1234567', null, true));
        $this->assertNull(SampleMasker::value('NOTA DE CREDITO DE JUAN PEREZ LOPEZ', null, true));
    }

    public function test_the_maximum_length_applies_even_to_recognised_vocabulary_and_can_be_lowered(): void
    {
        // Tres palabras de 20 letras: cada palabra cabe en la lista permitida, pero el valor completo (62 caracteres) no es un código.
        $long = str_repeat('A', 20).' '.str_repeat('B', 20).' '.str_repeat('C', 20);
        $this->assertNull(SampleMasker::value($long, null, true));
        $this->assertNull(SampleMasker::value('NOTA DE CREDITO', 10, true), 'el tope se puede bajar por llamada');
        $this->assertSame('NOTA DE CREDITO', SampleMasker::value('NOTA DE CREDITO', 15, true));
    }

    public function test_identifiers_are_quoted_per_dialect_and_unsafe_ones_are_rejected_not_sanitized(): void
    {
        $this->assertSame('"col ""x"""', IdentifierQuoter::quote('pgsql', 'col "x"'));
        $this->assertSame('`col ``x```', IdentifierQuoter::quote('mysql', 'col `x`'));
        $this->assertSame('[col [x]]]', IdentifierQuoter::quote('sqlsrv', 'col [x]'));
        $this->assertSame('"comercial"."det_venta"', IdentifierQuoter::table('pgsql', 'comercial', 'det_venta'));
        $this->assertSame('`det_venta`', IdentifierQuoter::table('mysql', 'ignorado', 'det_venta'), 'en MySQL el esquema es la base de la conexión');
        foreach (['', "a\nb", "a\x00b", 'a\\b', str_repeat('x', 129), ' espacio ', "a\tb"] as $unsafe) {
            $this->assertFalse(IdentifierQuoter::isSafe($unsafe), json_encode($unsafe));
        }
        $this->expectException(\InvalidArgumentException::class);
        IdentifierQuoter::quote('pgsql', "a\nb");
    }
}
