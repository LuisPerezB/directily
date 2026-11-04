<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use IntlChar;
use Normalizer;

/**
 * Verificación de que las cifras del texto del modelo proceden de las herramientas (regla 2 de §6.A: ningún LLM calcula).
 *
 * Un número del texto es «fundamentado» si coincide —con el redondeo con el que se escribió— con algún número ADMITIDO. Qué se admite (V2.5 · W6-D, correcciones de la revisión):
 *  - los valores NUMÉRICOS de las herramientas (enteros, decimales, cadenas puramente numéricas que dejó un campo `decimal`), las claves numéricas de sus mapas y el tamaño de sus listas;
 *  - de las cadenas, SOLO las fechas/periodos ISO completos y los números de campos de identificación conocidos (`id`, `*_id`, `sku`, `*_code`, versiones…): NUNCA el texto libre
 *    (nombres, zonas, notas), que es controlable por quien importa los datos; un «999999» escondido en una zona no respalda ninguna cifra;
 *  - lo que escribió el usuario (`$trusted`) y los argumentos numéricos/booleanos que el modelo pasó (p. ej. el horizonte).
 * Un nombre de los datos CITADO TEXTUALMENTE en la respuesta (p. ej. «Cemento 50kg») se enmascara antes de extraer cifras: sus números son del dato, no una afirmación del modelo.
 *
 * Formas que NO eluden la comprobación: el texto se normaliza (NFKC, dígitos de cualquier escritura → ASCII, sin caracteres de formato) y se unen los grupos de miles con
 * espacio, NBSP, espacio fino o apóstrofo («1 000 000»); un número pegado a un código de moneda o con ≥ 4 dígitos pegados a letras («USD750000») cuenta; los sufijos
 * k / mil / millones / million escalan el valor («750 mil» = 750 000); la notación científica cuenta; y las cifras ESCRITAS CON LETRAS en español o inglés mayores que diez
 * («setecientos cincuenta mil», «seven hundred fifty thousand») se evalúan como el número que expresan. Se omiten los marcadores de lista («1.», «2)») y los
 * identificadores cortos pegados a letras (P90, H30, v1).
 *
 * Es una comprobación conservadora, LÉXICA, no una prueba formal: no verifica la semántica (unidad, entidad ni horizonte) y puede rechazar de más (p. ej. «12 100» se lee
 * como 12 100 = doce mil cien). Ante un número no fundamentado el orquestador descarta el texto del modelo y devuelve solo las cifras de las herramientas.
 */
final class NumericGrounding
{
    private const EPS = 1e-9;

    /** Claves cuyo valor es texto libre: nunca respaldan cifras. */
    private const FREE_TEXT_KEY = '/^(name|label|title|zone|segment|brand|category|note|notes|message|description|text|unit|supplier|explanation|reason|justification|basis|status_text)$/i';

    /** Claves de identificación cuyos números pueden respaldar el identificador citado (p. ej. «CLI-0001»). */
    private const ID_KEY = '/^(id|.*_id|sku|code|.*_code|external_code|version|.*_version|rank|period)$/i';

    /** Claves de cadenas citables textualmente en la respuesta (se enmascaran antes de extraer cifras). */
    public const QUOTABLE_KEYS = ['name', 'sku', 'code', 'external_code', 'customer_code', 'segment_code', 'zone', 'brand', 'unit', 'label', 'title', 'category'];

    /** Primer punto de código de cada bloque Unicode de dígitos decimales (Nd) más comunes; NFKC ya cubre los de ancho completo. */
    private const DIGIT_BLOCKS = [0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0x1090, 0x17E0, 0x1810, 0x1946, 0x19D0, 0xA620, 0xA8D0, 0xA900, 0xFF10];

    /** Valor de cada palabra-número (español sin tildes e inglés). */
    private const WORDS = [
        'cero' => 0, 'uno' => 1, 'un' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
        'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
        'veinte' => 20, 'veintiun' => 21, 'veintiuno' => 21, 'veintiuna' => 21, 'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24, 'veinticinco' => 25, 'veintiseis' => 26,
        'veintisiete' => 27, 'veintiocho' => 28, 'veintinueve' => 29, 'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80, 'noventa' => 90,
        'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'doscientas' => 200, 'trescientos' => 300, 'trescientas' => 300, 'cuatrocientos' => 400, 'cuatrocientas' => 400,
        'quinientos' => 500, 'quinientas' => 500, 'seiscientos' => 600, 'seiscientas' => 600, 'setecientos' => 700, 'setecientas' => 700, 'ochocientos' => 800, 'ochocientas' => 800,
        'novecientos' => 900, 'novecientas' => 900,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
        'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
        'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    /** Multiplicadores: cien/hundred multiplican lo acumulado; mil/millón/thousand/million/billion cierran un grupo. */
    private const SCALES = ['hundred' => 100, 'thousand' => 1_000, 'mil' => 1_000, 'million' => 1_000_000, 'millions' => 1_000_000, 'millon' => 1_000_000, 'millones' => 1_000_000, 'billion' => 1_000_000_000, 'billions' => 1_000_000_000];

    /** Sufijos que escalan un número ya escrito con cifras. */
    private const SUFFIXES = ['k' => 1_000, 'mil' => 1_000, 'thousand' => 1_000, 'million' => 1_000_000, 'millions' => 1_000_000, 'millon' => 1_000_000, 'millones' => 1_000_000, 'billion' => 1_000_000_000, 'billions' => 1_000_000_000, 'bn' => 1_000_000_000];

    /**
     * @param  list<mixed>  $sources  datos de las herramientas (arreglos/objetos) y, por compatibilidad, cadenas de nivel superior = texto del usuario
     * @param  list<string>  $trusted  textos que escribió el usuario (sus cifras se admiten)
     * @param  list<string>  $quotable  cadenas de los datos que, citadas TEXTUALMENTE en la respuesta, no cuentan como cifras del modelo
     * @return list<string> números del texto sin respaldo, tal como están escritos (normalizados)
     */
    public static function ungrounded(string $text, array $sources, array $trusted = [], array $quotable = []): array
    {
        $allowed = [];
        foreach ($sources as $source) {
            if (is_string($source)) {
                $trusted[] = $source;
            } else {
                self::collect($source, $allowed, null);
            }
        }
        foreach ($trusted as $userText) {
            foreach (self::tokens(self::fold((string) $userText)) as $token) {
                foreach ($token['candidates'] as [$value]) {
                    $allowed[] = [$value, false];
                }
            }
        }
        $allowed = array_values(array_unique($allowed, SORT_REGULAR));

        $folded = self::fold($text);
        foreach (self::quotables($quotable) as $name) {
            $folded = str_ireplace($name, ' ', $folded);
        }

        $missing = [];
        $blanked = $folded;
        foreach (self::tokens($folded) as $token) {
            if (! self::isGrounded($token, $allowed)) {
                $missing[$token['raw']] = $token['raw'];
            }
            // Lo ya leído como cifra (y su sufijo «mil», «millones»…) no se vuelve a leer como cifra escrita con letras.
            $blanked = substr_replace($blanked, str_repeat(' ', $token['end'] - $token['start']), $token['start'], $token['end'] - $token['start']);
        }
        foreach (self::spelledNumbers($blanked) as $spelled) {
            if (! self::isValueAllowed($spelled['value'], $allowed)) {
                $missing[$spelled['raw']] = $spelled['raw'];
            }
        }

        return array_values($missing);
    }

    /**
     * Cadenas citables (nombres, códigos, zonas…) de un resultado de herramienta: solo las de claves de nombre, de 3 a 120 caracteres.
     *
     * @return list<string>
     */
    public static function quotableStrings(mixed $data): array
    {
        $out = [];
        $walk = function (mixed $node, ?string $key) use (&$walk, &$out): void {
            if ($node instanceof \stdClass) {
                $node = (array) $node;
            }
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    $walk($v, is_string($k) ? $k : $key);
                }
            } elseif (is_string($node) && $key !== null && in_array(strtolower($key), self::QUOTABLE_KEYS, true)) {
                $len = mb_strlen($node);
                if ($len >= 3 && $len <= 120) {
                    $out[$node] = $node;
                }
            }
        };
        $walk($data, null);

        return array_values($out);
    }

    /** @return list<string> normalizadas, de la más larga a la más corta */
    private static function quotables(array $quotable): array
    {
        $names = [];
        foreach ($quotable as $q) {
            $f = trim(self::fold((string) $q));
            // Solo nombres con letras: un código puramente numérico no enmascara nada (sus cifras las respalda, si procede, la clave de identificación).
            if (mb_strlen($f) >= 3 && preg_match('/\p{L}/u', $f) === 1) {
                $names[$f] = $f;
            }
        }
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    // ── normalización y extracción ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Texto comparable: NFKC, sin caracteres de formato, dígitos de cualquier escritura en ASCII y separadores árabes en sus equivalentes. */
    private static function fold(string $text): string
    {
        $text = preg_replace('/\p{Cf}+/u', '', $text) ?? $text;
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }
        $text = strtr($text, ["\u{066B}" => '.', "\u{066C}" => ',', "\u{2212}" => '-']);

        return preg_replace_callback('/\p{Nd}/u', function (array $m): string {
            if (preg_match('/^[0-9]$/', $m[0]) === 1) {
                return $m[0];
            }
            if (class_exists(IntlChar::class)) {
                $v = IntlChar::charDigitValue(mb_ord($m[0]));
                if ($v >= 0) {
                    return (string) $v;
                }
            }
            $cp = mb_ord($m[0]);
            foreach (self::DIGIT_BLOCKS as $base) {
                if ($cp >= $base && $cp <= $base + 9) {
                    return (string) ($cp - $base);
                }
            }

            return $m[0];
        }, $text) ?? $text;
    }

    /** Palabras en minúsculas y sin tildes, para las cifras escritas con letras. */
    private static function ascii(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists(Normalizer::class)) {
            $d = Normalizer::normalize($text, Normalizer::FORM_D);
            if (is_string($d)) {
                $text = preg_replace('/\p{Mn}+/u', '', $d) ?? $text;
            }
        }

        return $text;
    }

    /**
     * @return list<array{raw: string, candidates: list<array{0: float, 1: int}>, scale: int, sci: bool, start: int, end: int}>
     */
    private static function tokens(string $folded): array
    {
        // Marcadores de lista al inicio de línea («1. », «2) »).
        $text = preg_replace_callback('/^\s*\d{1,2}[.)]\s/mu', fn (array $m): string => str_repeat(' ', strlen($m[0])), $folded) ?? $folded;   // misma longitud: los desplazamientos siguen valiendo
        $re = '/(?<![\d.,])(?:(?<sci>\d+(?:[.,]\d+)?[eE][+-]?\d+)|(?<grp>\d{1,3}(?:[ \x{00A0}\x{202F}\x{2009}\x{2007}\'’]\d{3})+(?:[.,]\d+)?)|(?<num>\d+(?:[.,]\d+)*))/u';
        if (preg_match_all($re, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }
        $tokens = [];
        foreach ($matches as $m) {
            [$raw, $offset] = $m[0];
            $end = $offset + strlen($raw);
            $kind = ($m['sci'][0] ?? '') !== '' ? 'sci' : (($m['grp'][0] ?? '') !== '' ? 'grp' : 'num');
            // Identificadores cortos pegados a letras (P90, H30, v1): no son cifras. Con ≥ 4 dígitos o tras un código de moneda sí cuentan (USD750000).
            $previous = $offset > 0 ? mb_substr(substr($text, 0, $offset), -1) : '';
            $digits = strlen(preg_replace('/\D/', '', $raw) ?? '');
            if ($kind === 'num' && $previous !== '' && preg_match('/\p{L}/u', $previous) === 1 && $digits <= 3) {
                continue;
            }
            $clean = $kind === 'grp' ? (string) preg_replace('/[ \x{00A0}\x{202F}\x{2009}\x{2007}\'’]/u', '', $raw) : $raw;
            $scale = 1;
            $shown = $raw;
            if ($kind !== 'sci' && preg_match('/^\s?([a-z]+)\b/iu', substr($text, $end, 24), $suffix) === 1) {
                $word = self::ascii($suffix[1]);
                if (isset(self::SUFFIXES[$word]) && ($word !== 'k' || substr($text, $end, 1) === $suffix[1][0])) {
                    $scale = self::SUFFIXES[$word];
                    $shown = $raw.(str_starts_with(substr($text, $end), ' ') ? ' ' : '').$suffix[1];
                }
            }
            $candidates = $kind === 'sci' ? [[(float) str_replace(',', '.', $clean), 0]] : self::candidates($clean);
            if ($scale !== 1) {
                $candidates = array_map(fn (array $c) => [$c[0], $c[1]], $candidates);
            }
            $spanEnd = $end + ($scale !== 1 ? strlen($shown) - strlen($raw) : 0);
            $tokens[] = ['raw' => $shown, 'candidates' => $candidates, 'scale' => $scale, 'sci' => $kind === 'sci', 'start' => $offset, 'end' => $spanEnd];
        }

        return $tokens;
    }

    /** @param array{raw: string, candidates: list<array{0: float, 1: int}>, scale: int, sci?: bool} $token @param list<array{0: float, 1: bool}> $allowed */
    private static function isGrounded(array $token, array $allowed): bool
    {
        foreach ($token['candidates'] as [$value, $decimals]) {
            if (($token['sci'] ?? false) === true) {
                if (self::isValueAllowed($value, $allowed)) {
                    return true;
                }

                continue;
            }
            foreach ($allowed as [$a, $percentable]) {
                if ($token['scale'] > 1) {
                    // «2,3 millones» redondea a la escala escrita: el dato 2 312 345 lo respalda; un dato mucho menor, no.
                    $scaled = $a / $token['scale'];
                    if ($a >= $token['scale'] * 0.5 && (abs(round($scaled, $decimals) - $value) < self::EPS || abs(floor($scaled * 10 ** $decimals + 1e-9) / 10 ** $decimals - $value) < self::EPS)) {
                        return true;
                    }

                    continue;
                }
                if (abs(round($a, $decimals) - $value) < self::EPS || ($percentable && abs(round($a * 100, $decimals) - $value) < self::EPS)) {
                    return true;
                }
                // Truncado en lugar de redondeado (p. ej. 0,2399 → 0,23).
                $factor = 10 ** $decimals;
                if (abs(floor($a * $factor + 1e-9) / $factor - $value) < self::EPS) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array{0: float, 1: bool}> $allowed */
    private static function isValueAllowed(float $value, array $allowed): bool
    {
        foreach ($allowed as [$a]) {
            if (abs($a - $value) < max(self::EPS, abs($value) * 1e-9)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Interpretaciones posibles del número escrito (separadores de miles y decimales de es/en).
     *
     * @return list<array{0: float, 1: int}> [valor, decimales escritos]
     */
    private static function candidates(string $token): array
    {
        $hasDot = str_contains($token, '.');
        $hasComma = str_contains($token, ',');
        $out = [];
        if (! $hasDot && ! $hasComma) {
            return [[(float) $token, 0]];
        }
        if ($hasDot && $hasComma) {
            $decimalSep = strrpos($token, '.') > strrpos($token, ',') ? '.' : ',';
            $thousandsSep = $decimalSep === '.' ? ',' : '.';
            $normalized = str_replace($decimalSep, '.', str_replace($thousandsSep, '', $token));
            $decimals = strlen(substr($token, (int) strrpos($token, $decimalSep) + 1));

            return [[(float) $normalized, $decimals]];
        }
        $sep = $hasDot ? '.' : ',';
        $parts = explode($sep, $token);
        $last = end($parts);
        // Interpretación decimal (solo si hay un único separador).
        if (count($parts) === 2) {
            $out[] = [(float) ($parts[0].'.'.$parts[1]), strlen($parts[1])];
        }
        // Interpretación de miles (grupos de 3 dígitos).
        if (strlen($last) === 3 && strlen($parts[0]) <= 3 && array_slice($parts, 1) === array_filter(array_slice($parts, 1), fn ($p) => strlen($p) === 3)) {
            $out[] = [(float) implode('', $parts), 0];
        }

        return $out !== [] ? $out : [[(float) str_replace($sep, '', $token), 0]];
    }

    // ── cifras escritas con letras ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Frases de palabras-número (español/inglés) cuyo valor supera diez: «setecientos cincuenta mil», «seven hundred fifty thousand», «un millón».
     *
     * @return list<array{raw: string, value: float}>
     */
    private static function spelledNumbers(string $folded): array
    {
        $ascii = self::ascii($folded);
        if (preg_match_all('/[a-z]+/u', $ascii, $m) === false) {
            return [];
        }
        $words = $m[0];
        $out = [];
        $run = [];
        $flush = function () use (&$run, &$out): void {
            if ($run !== []) {
                $value = self::spelledValue($run);
                if ($value > 10) {
                    $out[] = ['raw' => implode(' ', $run), 'value' => $value];
                }
            }
            $run = [];
        };
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $w = $words[$i];
            $isNumber = isset(self::WORDS[$w]) || isset(self::SCALES[$w]);
            if ($isNumber) {
                $run[] = $w;
            } elseif (($w === 'y' || $w === 'and') && $run !== [] && isset($words[$i + 1]) && (isset(self::WORDS[$words[$i + 1]]) || isset(self::SCALES[$words[$i + 1]]))) {
                $run[] = $w;
            } else {
                $flush();
            }
        }
        $flush();

        return $out;
    }

    /** @param list<string> $run */
    private static function spelledValue(array $run): float
    {
        $total = 0.0;
        $current = 0.0;
        foreach ($run as $w) {
            if ($w === 'y' || $w === 'and') {
                continue;
            }
            if (isset(self::WORDS[$w])) {
                $v = self::WORDS[$w];
                // «cien»/«ciento» y las centenas se suman a lo acumulado; los demás también.
                $current += $v;
            } elseif ($w === 'hundred') {
                $current = ($current === 0.0 ? 1.0 : $current) * 100;
            } else {
                $scale = (float) self::SCALES[$w];
                $total += ($current === 0.0 ? 1.0 : $current) * $scale;
                $current = 0.0;
            }
        }

        return $total + $current;
    }

    // ── fuentes admitidas ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Solo valores NUMÉRICOS (y, con clave de identificación o formato de fecha ISO, los números de esa cadena). Las razones numéricas (decimales con |v| ≤ 1: probabilidades,
     * WAPE, variaciones) admiten su forma porcentual (×100); enteros y cantidades no.
     *
     * @param  list<array{0: float, 1: bool}>  $allowed
     */
    private static function collect(mixed $value, array &$allowed, ?string $key): void
    {
        if (is_float($value)) {
            $allowed[] = [$value, abs($value) <= 1.0];
        } elseif (is_int($value)) {
            $allowed[] = [(float) $value, false];
        } elseif (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                return;
            }
            $freeText = $key !== null && preg_match(self::FREE_TEXT_KEY, $key) === 1;
            if (! $freeText && preg_match('/^-?\d+(?:\.\d+)?$/', $text) === 1) {
                // Un campo decimal de la base llega como cadena numérica («120.0000»).
                $allowed[] = [(float) $text, abs((float) $text) <= 1.0];
            } elseif (preg_match('/^\d{4}-\d{2}(?:-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?)?$/', $text) === 1) {
                foreach (self::digitRuns($text) as $n) {
                    $allowed[] = [$n, false];
                }
            } elseif (! $freeText && $key !== null && preg_match(self::ID_KEY, $key) === 1 && preg_match('~^[A-Za-z0-9._\-/#:]{1,40}$~', $text) === 1) {
                foreach (self::digitRuns($text) as $n) {
                    $allowed[] = [$n, false];
                }
            }
        } elseif ($value instanceof \stdClass) {
            // Mapa JSON de la envoltura (p. ej. model_version): sus claves y valores son cifras de la herramienta como cualquier arreglo.
            self::collect((array) $value, $allowed, $key);
        } elseif (is_array($value)) {
            // El tamaño de una lista es un dato que la herramienta ya entrega implícitamente (p. ej. «3 productos»).
            $allowed[] = [(float) count($value), false];
            foreach ($value as $k => $item) {
                if (is_int($k) || (is_string($k) && ctype_digit($k))) {
                    $allowed[] = [(float) $k, false];   // claves numéricas de mapas (horizonte → valor)
                }
                self::collect($item, $allowed, is_string($k) && ! ctype_digit($k) ? $k : $key);
            }
        }
    }

    /** @return list<float> */
    private static function digitRuns(string $text): array
    {
        $out = [];
        if (preg_match_all('/\d+/', $text, $m) !== false) {
            foreach ($m[0] as $token) {
                $out[] = (float) $token;
            }
        }

        return $out;
    }

    /**
     * Compatibilidad: números de un texto (p. ej. el del usuario).
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        return array_map(fn (array $t) => $t['raw'], self::tokens(self::fold($text)));
    }
}
