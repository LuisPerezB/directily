<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Minimización de datos de clientes (LOPDP).
 * Si el código del cliente parece una cédula (10 dígitos) o RUC (13 dígitos), no se guarda
 * en claro: external_code = "id:" + HMAC truncado y tax_id_hash = HMAC completo.
 * "9999999999999" es el consumidor final en Ecuador.
 */
final class CustomerIdentity
{
    public const FINAL_CONSUMER = '9999999999999';

    /** @return array{external_code: string, customer_type: string, tax_id_hash: ?string} */
    public static function fromCode(string $code): array
    {
        $clean = preg_replace('/[\s\-.]/', '', trim($code)) ?? trim($code);

        if ($clean === self::FINAL_CONSUMER || $clean === '9999999999') {
            return ['external_code' => 'CONSUMIDOR_FINAL', 'customer_type' => 'final_consumer', 'tax_id_hash' => null];
        }

        if (preg_match('/^\d{10}$|^\d{13}$/', $clean) === 1) {
            $hash = self::hmac($clean);

            return [
                'external_code' => 'id:'.substr($hash, 0, 32),
                'customer_type' => strlen($clean) === 10 ? 'person' : (str_ends_with($clean, '001') && in_array($clean[2], ['6', '9'], true) ? 'company' : 'unknown'),
                'tax_id_hash' => $hash,
            ];
        }

        return ['external_code' => mb_substr(trim($code), 0, 100), 'customer_type' => 'unknown', 'tax_id_hash' => null];
    }

    public static function hmac(string $taxId): string
    {
        return hash_hmac('sha256', $taxId, 'predictily-customer-id|'.config('app.key'));
    }
}
