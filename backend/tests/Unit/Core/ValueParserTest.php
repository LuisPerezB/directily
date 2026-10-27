<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Services\Imports\Parsing\ValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ValueParserTest extends TestCase
{
    /** @return array<string, array{mixed, ?float, string}> */
    public static function numbers(): array
    {
        return [
            'entero' => ['12', 12.0, 'auto'],
            'punto decimal' => ['12.50', 12.5, 'auto'],
            'coma decimal' => ['12,50', 12.5, 'auto'],
            'miles con coma' => ['1,234', 1234.0, 'auto'],
            'miles punto + coma decimal' => ['1.234,56', 1234.56, 'auto'],
            'miles coma + punto decimal' => ['1,234.56', 1234.56, 'auto'],
            'varios puntos de miles' => ['1.234.567', 1234567.0, 'auto'],
            'símbolo dólar' => ['$ 25.90', 25.9, 'auto'],
            'negativo contable' => ['(10,50)', -10.5, 'auto'],
            'negativo final' => ['10-', -10.0, 'auto'],
            'float nativo' => [3.25, 3.25, 'auto'],
            'texto' => ['abc', null, 'auto'],
            'vacío' => ['', null, 'auto'],
            'separador explícito coma' => ['1.234,5', 1234.5, ','],
            'separador explícito punto' => ['1,234.5', 1234.5, '.'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_number_parsing(mixed $raw, ?float $expected, string $decimal): void
    {
        $this->assertSame($expected, (new ValueParser('America/Guayaquil', ['decimal_separator' => $decimal]))->number($raw));
    }

    public function test_dates_in_common_formats_and_tenant_timezone(): void
    {
        $p = new ValueParser('America/Guayaquil');
        foreach (['2026-03-15', '15/03/2026', '15-03-2026', '2026/03/15', '15.03.2026'] as $raw) {
            $this->assertSame('2026-03-15', $p->dateTime($raw)[0]->toDateString(), $raw);
        }
        [$dt, $hasTime] = $p->dateTime('2026-03-15 23:30:00');
        $this->assertTrue($hasTime);
        $this->assertSame('2026-03-16 04:30:00', $dt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15', $p->dateTime(46096)[0]->toDateString()); // serial de Excel
        $this->assertNull($p->dateTime('31/02/2026'));
        $this->assertNull($p->dateTime('mañana'));
        $this->assertSame('2026-03-04', (new ValueParser('America/Guayaquil', ['date_format' => 'm/d/Y']))->dateTime('03/04/2026')[0]->toDateString());
    }

    public function test_domain_normalizations(): void
    {
        $this->assertSame('invoice', ValueParser::documentType('Factura'));
        $this->assertSame('credit_note', ValueParser::documentType('Nota de Crédito'));
        $this->assertSame('sales_note', ValueParser::documentType('NV'));
        $this->assertNull(ValueParser::documentType('cotización'));
        $this->assertSame('voided', ValueParser::documentStatus('ANULADA'));
        $this->assertSame('completed', ValueParser::documentStatus('Autorizada'));
        $this->assertSame('wholesale', ValueParser::channel('Mayoreo'));
        $this->assertSame('other', ValueParser::channel('feria'));
        $p = new ValueParser;
        $this->assertSame(0.15, $p->percentage('15%'));
        $this->assertFalse($p->boolean('No'));
        $this->assertSame('1001', $p->string(1001.0));
    }
}
