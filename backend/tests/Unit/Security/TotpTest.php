<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Auth\Mfa\RecoveryCodes;
use App\Services\Auth\Mfa\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * V2.5 · W6-A: TOTP (RFC 6238) y HOTP (RFC 4226) contra los VECTORES DE PRUEBA de los propios RFC, con reloj fijo (el tiempo es un parámetro): sin reloj real no hay
 * pruebas intermitentes. Los valores esperados son los del Apéndice B de RFC 6238 (SHA-1, SHA-256 y SHA-512, 8 dígitos) y del Apéndice D de RFC 4226.
 */
final class TotpTest extends TestCase
{
    private const SHA1_KEY = '12345678901234567890';

    private const SHA256_KEY = '12345678901234567890123456789012';

    private const SHA512_KEY = '1234567890123456789012345678901234567890123456789012345678901234';

    /** RFC 4226 Apéndice D: HOTP con la clave "12345678901234567890", contadores 0 a 9, 6 dígitos. */
    public function test_hotp_matches_the_rfc_4226_test_vectors(): void
    {
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp(self::SHA1_KEY, $counter), "HOTP contador {$counter}");
        }
    }

    /** @return array<string, array{int, string, string, string}> */
    public static function rfc6238Vectors(): array
    {
        return [
            'T=59' => [59, '94287082', '46119246', '90693936'],
            'T=1111111109' => [1111111109, '07081804', '68084774', '25091201'],
            'T=1111111111' => [1111111111, '14050471', '67062674', '99943326'],
            'T=1234567890' => [1234567890, '89005924', '91819424', '93441116'],
            'T=2000000000' => [2000000000, '69279037', '90698825', '38618901'],
            'T=20000000000' => [20000000000, '65353130', '77737706', '47863826'],
        ];
    }

    /** RFC 6238 Apéndice B: TOTP de 8 dígitos, paso de 30 s, T0 = 0, con cada algoritmo y su clave. */
    #[DataProvider('rfc6238Vectors')]
    public function test_totp_matches_the_rfc_6238_test_vectors(int $time, string $sha1, string $sha256, string $sha512): void
    {
        $this->assertSame($sha1, Totp::code(Totp::base32Encode(self::SHA1_KEY), $time, 30, 8, 'sha1'));
        $this->assertSame($sha256, Totp::code(Totp::base32Encode(self::SHA256_KEY), $time, 30, 8, 'sha256'));
        $this->assertSame($sha512, Totp::code(Totp::base32Encode(self::SHA512_KEY), $time, 30, 8, 'sha512'));
    }

    public function test_the_six_digit_code_is_the_last_six_digits_of_the_eight_digit_one(): void
    {
        // Misma truncación dinámica: el código de 6 dígitos de T=59 con SHA-1 es el resto módulo 10^6 de 94287082.
        $this->assertSame('287082', Totp::code(Totp::base32Encode(self::SHA1_KEY), 59, 30, 6));
    }

    public function test_base32_follows_the_rfc_4648_vectors_and_round_trips(): void
    {
        foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $plain => $encoded) {
            $this->assertSame($encoded, Totp::base32Encode($plain));
            if ($plain !== '') {
                $this->assertSame($plain, Totp::base32Decode($encoded));
            }
        }
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb-oi=='), 'tolera minúsculas, espacios, guiones y relleno');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode(self::SHA1_KEY));
        $random = random_bytes(20);
        $this->assertSame($random, Totp::base32Decode(Totp::base32Encode($random)));
    }

    public function test_invalid_base32_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::base32Decode('NOT*BASE32!');
    }

    public function test_generated_secrets_are_160_bits_of_base32_and_never_repeat(): void
    {
        $a = Totp::generateSecret();
        $b = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertSame(20, strlen(Totp::base32Decode($a)));
        $this->assertNotSame($a, $b);
        $this->expectException(InvalidArgumentException::class);
        Totp::generateSecret(5);
    }

    public function test_verification_accepts_the_current_step_and_one_step_each_side_and_nothing_else(): void
    {
        $secret = Totp::base32Encode(self::SHA1_KEY);
        $now = 1_700_000_010;   // reloj fijo
        $stepNow = Totp::step($now);
        $codeAt = fn (int $offset): string => Totp::code($secret, ($stepNow + $offset) * 30);

        $this->assertSame($stepNow, Totp::verify($secret, $codeAt(0), $now), 'paso actual');
        $this->assertSame($stepNow - 1, Totp::verify($secret, $codeAt(-1), $now), 'un paso atrás (reloj atrasado ≤ 30 s)');
        $this->assertSame($stepNow + 1, Totp::verify($secret, $codeAt(1), $now), 'un paso adelante');
        $this->assertNull(Totp::verify($secret, $codeAt(-2), $now), 'dos pasos atrás: fuera de la ventana');
        $this->assertNull(Totp::verify($secret, $codeAt(2), $now), 'dos pasos adelante: fuera de la ventana');
        $this->assertSame($stepNow - 2, Totp::verify($secret, $codeAt(-2), $now, 2), 'con ventana 2 sí entra');
        $this->assertNull(Totp::verify($secret, $codeAt(0), $now, 1, 30, 6, 'sha1', $stepNow), 'un paso ya usado no se acepta otra vez (RFC 6238 §5.2)');
        $this->assertNull(Totp::verify($secret, $codeAt(-1), $now, 1, 30, 6, 'sha1', $stepNow), 'ni uno anterior al último usado');
        $this->assertSame($stepNow + 1, Totp::verify($secret, $codeAt(1), $now, 1, 30, 6, 'sha1', $stepNow), 'sí uno posterior');
    }

    public function test_malformed_codes_are_rejected_without_error(): void
    {
        $secret = Totp::base32Encode(self::SHA1_KEY);
        foreach (['', '12345', '1234567', 'abcdef', '12 345', '١٢٣٤٥٦', "123456\n1"] as $bad) {
            $this->assertNull(Totp::verify($secret, $bad, 1_700_000_010), var_export($bad, true));
        }
        // Espacios y guiones dentro de un código válido son aceptables (cómo lo escribe la gente).
        $code = Totp::code($secret, 1_700_000_010);
        $this->assertNotNull(Totp::verify($secret, substr($code, 0, 3).' '.substr($code, 3), 1_700_000_010));
    }

    public function test_the_time_is_a_parameter_so_the_epoch_and_negative_time_are_safe(): void
    {
        $secret = Totp::base32Encode(self::SHA1_KEY);
        $this->assertSame(0, Totp::step(0));
        $this->assertSame(0, Totp::step(-100), 'un instante negativo no produce un contador negativo');
        $this->assertSame(1, Totp::step(59));
        $this->assertSame(1, Totp::step(30));
        // En el instante 0 la ventana no genera pasos negativos: el código del paso 0 sí vale y no hay error.
        $this->assertSame(0, Totp::verify($secret, Totp::code($secret, 0), 0, 1));
    }

    public function test_the_provisioning_uri_follows_the_key_uri_format(): void
    {
        $uri = Totp::uri('Predictily', 'ana@example.com', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->assertStringStartsWith('otpauth://totp/Predictily:ana%40example.com?', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $this->assertSame(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'issuer' => 'Predictily', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30'], $query);
    }

    public function test_hotp_rejects_unsupported_parameters(): void
    {
        foreach ([[-1, 6, 'sha1'], [0, 5, 'sha1'], [0, 10, 'sha1'], [0, 6, 'md5']] as [$counter, $digits, $algo]) {
            try {
                Totp::hotp(self::SHA1_KEY, $counter, $digits, $algo);
                $this->fail('Debió rechazar el parámetro.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── Códigos de recuperación ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_recovery_codes_are_random_grouped_and_stored_only_as_keyed_hashes(): void
    {
        $generated = RecoveryCodes::generate(10);

        $this->assertCount(10, $generated['plain']);
        $this->assertCount(10, array_unique($generated['plain']));
        foreach ($generated['plain'] as $i => $plain) {
            $this->assertMatchesRegularExpression('/^[A-HJKMNP-TV-Z2-9]{4}(-[A-HJKMNP-TV-Z2-9]{4}){3}$/', $plain);
            $normalized = RecoveryCodes::normalize($plain);
            $this->assertSame(RecoveryCodes::hash($normalized), $generated['stored'][$i]['h']);
            $this->assertNotSame($normalized, $generated['stored'][$i]['h']);
            $this->assertNull($generated['stored'][$i]['u']);
            $this->assertStringNotContainsString($normalized, json_encode($generated['stored'], JSON_THROW_ON_ERROR), 'el código en claro no está en lo almacenado');
        }
    }

    public function test_a_recovery_code_matches_once_whatever_the_case_or_separators(): void
    {
        $generated = RecoveryCodes::generate(3);
        $plain = $generated['plain'][1];

        $this->assertSame(1, RecoveryCodes::find($generated['stored'], strtolower($plain)));
        $this->assertSame(1, RecoveryCodes::find($generated['stored'], str_replace('-', ' ', $plain)));
        $this->assertNull(RecoveryCodes::find($generated['stored'], 'AAAA-AAAA-AAAA-AAAA'));
        $this->assertNull(RecoveryCodes::find($generated['stored'], '123456'), 'un código TOTP no se confunde con uno de recuperación');
        $generated['stored'][1]['u'] = '2026-11-02T10:00:00+00:00';
        $this->assertNull(RecoveryCodes::find($generated['stored'], $plain), 'un código usado no vuelve a servir');
        $this->assertSame(2, RecoveryCodes::remaining($generated['stored']));
    }
}
