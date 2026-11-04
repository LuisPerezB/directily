<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Services\Imports\Parsing\ValueParser;

/**
 * Detecta, celda por celda y SIN interpretar de nuevo el valor, las normalizaciones seguras que el importador ya aplica (ADR-V25-05):
 *  - `whitespace_trimmed`: espacios sobrantes (inicio, fin, repetidos);
 *  - `decimal_separator_normalized`: número con coma decimal o separadores de miles; el valor propuesto es EXACTAMENTE el que produce
 *    `ValueParser::number` (la función del importador, con las mismas opciones de la carga), no una réplica. Una sola coma seguida de
 *    exactamente tres dígitos («1,234») es AMBIGUA (¿1,234 o 1234?): el importador la lee como miles y se informa como WARNING
 *    `decimal_separator_ambiguous`, salvo que la carga declare el separador decimal;
 *  - `date_format_normalized`: fecha en un formato INEQUÍVOCO distinto del ISO (año primero, o día mayor que 12, o formato declarado);
 *  - `code_case_normalized`: códigos (documento, SKU, sucursal…) con minúsculas, que se comparan en mayúsculas.
 * Una fecha d/m/a con día y mes ≤ 12 y distintos es AMBIGUA: el importador la lee como día/mes (convención de la empresa) y se informa
 * como advertencia agregada `date_format_ambiguous`, no como corrección segura.
 *
 * Solo mira texto (los valores tipados de XLSX ya vienen interpretados). Es barato: una o dos expresiones regulares por celda.
 */
final class AutoFixDetector
{
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    private const DMY = '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})([ T]\d{1,2}:\d{2}(:\d{2})?)?$/';

    private const YMD = '/^\d{4}[\/.]\d{1,2}[\/.]\d{1,2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/';

    private const CODE_FIELD = '/(^|_)(sku|branch_code|document_number|supplier_code|code)$/';

    /**
     * @param  array<string, mixed>  $row  campo canónico => valor crudo
     * @param  array<string, string>  $types  campo => tipo (string, number, integer, date, datetime, boolean)
     * @param  bool  $explicitDateFormat  la carga declara un formato de fecha: toda fecha no ISO es inequívoca
     * @param  ?ValueParser  $parser  el de la carga (mismas opciones: separador decimal declarado, formato de fecha); sin él, uno con los valores por omisión
     * @return list<array{0: string, 1: string, 2: string, 3: string}> [código, campo, original, propuesto]
     */
    public function detect(array $row, array $types, bool $explicitDateFormat = false, ?ValueParser $parser = null): array
    {
        $parser ??= new ValueParser;
        $explicitDecimal = in_array((string) ($parser->option('decimal_separator') ?? 'auto'), ['.', ','], true);
        $found = [];
        foreach ($row as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $type = $types[$field] ?? 'string';
            if (preg_match('/^\s|\s$|\s{2}|[\t\r\n\x{00A0}]/u', $value) === 1) {
                $found[] = ['whitespace_trimmed', $field, $value, trim(preg_replace('/\s+/u', ' ', $value) ?? $value)];
            }
            $text = trim($value);
            if ($type === 'number' || $type === 'integer') {
                if (str_contains($text, ',') && preg_match('/^[+\-(]?\s*(usd|\$)?\s*\d[\d.,\s]*\)?$/i', $text) === 1 && ($number = $parser->number($text)) !== null) {
                    $proposed = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
                    $proposed = $proposed === '' || $proposed === '-0' ? '0' : $proposed;
                    $bare = preg_replace('/[^\d,.]/', '', $text) ?? $text;
                    $ambiguous = ! $explicitDecimal && preg_match('/^\d{1,3},\d{3}$/', $bare) === 1;
                    $found[] = [$ambiguous ? 'decimal_separator_ambiguous' : 'decimal_separator_normalized', $field, $value, $proposed];
                }
            } elseif ($type === 'date' || $type === 'datetime') {
                $this->date($field, $value, $text, $explicitDateFormat, $found);
            } elseif (preg_match(self::CODE_FIELD, $field) === 1 && $field !== 'customer_code' && preg_match('/\p{Ll}/u', $text) === 1) {
                $found[] = ['code_case_normalized', $field, $value, mb_strtoupper($text)];
            }
        }

        return $found;
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $found */
    private function date(string $field, string $value, string $text, bool $explicit, array &$found): void
    {
        if ($text === '' || preg_match(self::ISO_DATE, $text) === 1) {
            return;
        }
        if (preg_match(self::YMD, $text) === 1) {
            $found[] = ['date_format_normalized', $field, $value, 'ISO (año-mes-día)'];

            return;
        }
        if (preg_match(self::DMY, $text, $m) !== 1) {
            return; // formato que el importador valida (puede ser un error invalid_date) o serie de Excel: no se cuenta como corrección
        }
        [$day, $month] = [(int) $m[1], (int) $m[2]];
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return;
        }
        if ($explicit || $day > 12 || $day === $month) {
            $found[] = ['date_format_normalized', $field, $value, 'ISO (año-mes-día)'];
        } else {
            $found[] = ['date_format_ambiguous', $field, $value, 'ISO (año-mes-día) leyendo día/mes'];
        }
    }
}
