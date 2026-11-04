<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use InvalidArgumentException;

/**
 * Firma de petición del dispositivo Bridge (Ed25519, libsodium). El agente genera su par de claves LOCALMENTE; el servidor solo conoce la
 * huella (SHA-256 de la clave pública) y la clave pública que acompaña a cada petición (`X-Bridge-Key`), que debe coincidir con la huella
 * guardada. Mensaje firmado (idéntico en el agente Python; vectores compartidos en bridge/tests/fixtures/signature_vectors.json):
 *
 *   PREDICTILY-BRIDGE-V1 \n MÉTODO \n RUTA \n CONSULTA \n TIMESTAMP \n NONCE \n SHA256(cuerpo) en hexadecimal
 *
 * Claves y firmas viajan en base64 URL-safe sin relleno.
 */
final class BridgeSignature
{
    public const PREFIX = 'PREDICTILY-BRIDGE-V1';

    public const ENROL_PREFIX = 'PREDICTILY-BRIDGE-ENROL-V1';

    public static function encode(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** @throws InvalidArgumentException */
    public static function decode(string $text): string
    {
        try {
            return sodium_base642bin($text, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new InvalidArgumentException('Base64 URL-safe no válido.');
        }
    }

    public static function fingerprint(string $publicKeyBytes): string
    {
        return hash('sha256', $publicKeyBytes);
    }

    public static function message(string $method, string $path, string $query, int|string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [self::PREFIX, strtoupper($method), $path, $query, (string) $timestamp, $nonce, hash('sha256', $body)]);
    }

    public static function enrolMessage(string $codeHash, string $publicKeyB64, int|string $timestamp): string
    {
        return implode("\n", [self::ENROL_PREFIX, $codeHash, $publicKeyB64, (string) $timestamp]);
    }

    /** Clave pública Ed25519 válida (32 bytes). */
    public static function isPublicKey(string $bytes): bool
    {
        return strlen($bytes) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    public static function verify(string $publicKeyBytes, string $signatureBytes, string $message): bool
    {
        if (strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes);
    }
}
