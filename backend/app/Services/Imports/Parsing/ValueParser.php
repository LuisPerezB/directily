<?php

declare(strict_types=1);

namespace App\Services\Imports\Parsing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conversión de valores crudos (CSV/XLSX/JSON) a tipos de dominio.
 * Formatos frecuentes en Ecuador: fechas d/m/Y, decimales con coma o punto.
 */
final class ValueParser
{
    private const AUTO_DATE_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd.m.Y', 'Y/m/d H:i:s', 'Y/m/d',
        'd/m/y', 'd-m-y',
    ];

    /** @param array<string, mixed> $options */
    public function __construct(private readonly string $timezone = 'America/Guayaquil', private readonly array $options = [])
    {
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    public function string(mixed $value, int $max = 255): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }
        $text = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value);
        // Excel suele convertir códigos numéricos en float (p. ej. 1001.0).
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $text = (string) (int) $value;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, $max);
    }

    /** Número decimal o null si no es interpretable. */
    public function number(mixed $value): ?float
    {
        if (self::isBlank($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_bool($value) || $value instanceof DateTimeInterface) {
            return null;
        }

        $text = trim((string) $value);
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $text, $m) === 1) { // (12,50) contable
            $negative = true;
            $text = $m[1];
        }
        $text = str_ireplace(['usd', '$', ' ', "\u{00A0}"], '', $text);
        if (str_ends_with($text, '-')) {
            $negative = true;
            $text = substr($text, 0, -1);
        }
        if (str_starts_with($text, '-')) {
            $negative = ! $negative;
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }
        if ($text === '' || preg_match('/^[0-9.,]+$/', $text) !== 1) {
            return null;
        }

        $decimal = (string) ($this->options['decimal_separator'] ?? 'auto');
        $hasDot = str_contains($text, '.');
        $hasComma = str_contains($text, ',');

        if ($decimal === ',') {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } elseif ($decimal === '.') {
            $text = str_replace(',', '', $text);
        } elseif ($hasDot && $hasComma) {
            // El separador que aparece al final es el decimal.
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(['.', ','], ['', '.'], $text)
                : str_replace(',', '', $text);
        } elseif ($hasComma) {
            $text = preg_match('/^\d{1,3}(,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif ($hasDot && substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text); // 1.234.567
        }

        if (! is_numeric($text)) {
            return null;
        }
        $number = (float) $text;

        return $negative ? -$number : $number;
    }

    /** Porcentaje (termina en %): devuelve fracción 0..1, o null si no es porcentaje. */
    public function percentage(mixed $value): ?float
    {
        if (! is_string($value) || ! str_ends_with(trim($value), '%')) {
            return null;
        }
        $number = $this->number(rtrim(trim($value), '%'));

        return $number === null ? null : $number / 100;
    }

    public function integer(mixed $value): ?int
    {
        $number = $this->number($value);
        if ($number === null || floor($number) !== $number) {
            return null;
        }

        return (int) $number;
    }

    /**
     * Fecha/fecha-hora en la zona horaria de la empresa.
     *
     * @return array{0: CarbonImmutable, 1: bool}|null [fecha, tieneHora]
     */
    public function dateTime(mixed $value): ?array
    {
        if (self::isBlank($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            // XLSX: OpenSpout entrega fecha "naive"; se interpreta en la zona de la empresa.
            $date = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $this->timezone);

            return $date ? [$date, $value->format('H:i:s') !== '00:00:00'] : null;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)) === 1)) {
            return $this->excelSerial((float) $value);
        }

        $text = trim((string) $value);
        $format = (string) ($this->options['date_format'] ?? 'auto');
        $formats = $format === 'auto' || $format === ''
            ? self::AUTO_DATE_FORMATS
            : [$format.' H:i:s', $format.' H:i', $format];

        foreach ($formats as $candidate) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$candidate, $text, $this->timezone);
            } catch (Throwable) {
                $date = null;
            }
            if ($date !== null && $date !== false) {
                $errors = CarbonImmutable::getLastErrors();
                if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    continue;
                }
                if ((int) $date->format('Y') < 1990 || (int) $date->format('Y') > 2100) {
                    continue;
                }

                return [$date->setTimezone($this->timezone), str_contains($candidate, 'H')];
            }
        }

        return null;
    }

    /** @return array{0: CarbonImmutable, 1: bool}|null */
    private function excelSerial(float $serial): ?array
    {
        if ($serial < 20000 || $serial > 80000) {
            return null;
        }
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $date = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $this->timezone)?->addDays($days)->addSeconds($seconds);

        return $date ? [$date, $seconds > 0] : null;
    }

    public function boolean(mixed $value, bool $default = true): bool
    {
        if (self::isBlank($value)) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $text = Str::lower(Str::ascii(trim((string) $value)));

        return match (true) {
            in_array($text, ['1', 'si', 's', 'yes', 'y', 'true', 'verdadero', 'activo', 'activa', 'x'], true) => true,
            in_array($text, ['0', 'no', 'n', 'false', 'falso', 'inactivo', 'inactiva'], true) => false,
            default => $default,
        };
    }

    public static function key(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($text)))) ?? '', '_');
    }

    /** Tipo de documento: invoice, sales_note, credit_note u other (null si no se reconoce). */
    public static function documentType(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return 'invoice';
        }
        $k = self::key($raw);

        return match (true) {
            in_array($k, ['invoice', 'factura', 'fac', 'fv', 'fact', 'factura_electronica', '01'], true) => 'invoice',
            in_array($k, ['sales_note', 'nota_de_venta', 'nota_venta', 'nv', '02', 'ticket', 'boleta'], true) => 'sales_note',
            in_array($k, ['credit_note', 'nota_de_credito', 'nota_credito', 'nc', 'devolucion', 'devoluciones', '04', 'return'], true) => 'credit_note',
            in_array($k, ['other', 'otro', 'otros'], true) => 'other',
            default => null,
        };
    }

    public static function documentStatus(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return 'completed';
        }
        $k = self::key($raw);

        return match (true) {
            in_array($k, ['anulada', 'anulado', 'void', 'voided', 'cancelada', 'cancelado', 'annulled', 'anulacion', 'si', 's', '1', 'true'], true) => 'voided',
            in_array($k, ['pendiente', 'pending', 'borrador', 'draft'], true) => 'pending',
            default => 'completed',
        };
    }

    public static function channel(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $k = self::key($raw);

        return match (true) {
            in_array($k, ['store', 'local', 'tienda', 'mostrador', 'almacen', 'pos', 'presencial'], true) => 'store',
            in_array($k, ['wholesale', 'mayoreo', 'mayorista', 'por_mayor', 'distribucion'], true) => 'wholesale',
            in_array($k, ['web', 'online', 'ecommerce', 'e_commerce', 'internet', 'tienda_online'], true) => 'web',
            in_array($k, ['phone', 'telefono', 'whatsapp', 'call_center', 'llamada'], true) => 'phone',
            in_array($k, ['field_sales', 'campo', 'vendedor', 'preventa', 'ruta', 'visita'], true) => 'field_sales',
            default => 'other',
        };
    }
}
