<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge\Support;

use App\Services\Bridge\BridgeEnrolmentService;
use App\Services\Bridge\BridgeSignature;

/**
 * Dispositivo Bridge simulado en PHP: el MISMO protocolo que el agente Python (par Ed25519 propio, token, firma de cada petición, lotes
 * NDJSON gzip) para probar el backend sin proceso externo. Las pruebas de extremo a extremo con el agente real están en BridgeAgentEndToEndTest.
 */
final class FakeBridgeDevice
{
    public string $secretKey;

    public string $publicKey;

    public ?string $token = null;

    public ?int $deviceId = null;

    public function __construct(?string $seed = null)
    {
        $pair = sodium_crypto_sign_seed_keypair($seed ?? random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    public function publicKeyB64(): string
    {
        return BridgeSignature::encode($this->publicKey);
    }

    public function fingerprint(): string
    {
        return BridgeSignature::fingerprint($this->publicKey);
    }

    /** @return array<string, mixed> */
    public function enrolPayload(string $code, ?int $timestamp = null, string $name = 'Servidor ERP bodega'): array
    {
        $timestamp ??= time();
        $normalized = BridgeEnrolmentService::normalize($code);
        $proof = sodium_crypto_sign_detached(
            BridgeSignature::enrolMessage(BridgeEnrolmentService::hashCode($normalized), $this->publicKeyB64(), $timestamp),
            $this->secretKey,
        );

        return [
            'code' => $code, 'device_name' => $name, 'public_key' => $this->publicKeyB64(), 'proof' => BridgeSignature::encode($proof),
            'timestamp' => $timestamp, 'agent_version' => '0.1.0-test', 'platform' => 'linux',
        ];
    }

    /**
     * Encabezados de una petición firmada (para `$this->call`, con claves de servidor `HTTP_*`).
     *
     * @return array<string, string>
     */
    public function signedServerVars(string $method, string $path, string $body = '', string $query = '', ?int $timestamp = null, ?string $nonce = null, ?string $token = null): array
    {
        $timestamp ??= time();
        $nonce ??= BridgeSignature::encode(random_bytes(12));
        $signature = sodium_crypto_sign_detached(BridgeSignature::message($method, $path, $query, $timestamp, $nonce, $body), $this->secretKey);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token),
            'HTTP_X_BRIDGE_KEY' => $this->publicKeyB64(),
            'HTTP_X_BRIDGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_BRIDGE_NONCE' => $nonce,
            'HTTP_X_BRIDGE_SIGNATURE' => BridgeSignature::encode($signature),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * Lote NDJSON gzip determinista.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{bytes: string, sha256: string, size_bytes: int, row_count: int, columns: array<string, string>}
     */
    public static function batch(array $rows): array
    {
        $lines = '';
        $columns = [];
        foreach ($rows as $row) {
            $lines .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
            foreach ($row as $key => $value) {
                $columns[$key] = $columns[$key] ?? match (true) {
                    is_int($value) => 'int', is_float($value) => 'float', is_bool($value) => 'bool', is_string($value) => 'string', default => 'unknown',
                };
            }
        }
        $bytes = (string) gzencode($lines, 6);

        return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'row_count' => count($rows), 'columns' => $columns];
    }

    /**
     * Filas de ventas canónicas ficticias: 3 documentos, 4 líneas.
     *
     * @return list<array<string, mixed>>
     */
    public static function salesRows(string $prefix = 'BR'): array
    {
        $d = fn (int $ago) => now('America/Guayaquil')->subDays($ago)->format('Y-m-d');

        return [
            ['document_number' => "{$prefix}-001", 'document_type' => 'FACTURA', 'status' => 'EMITIDA', 'issued_at' => $d(10).' 09:15:00', 'branch_code' => '001', 'line_number' => 1, 'product_sku' => 'CEM-50', 'product_name' => 'Cemento 50kg', 'category' => 'Cemento', 'quantity' => 10, 'unit_price' => '8.5000', 'discount' => '0.0000', 'tax' => '10.2000', 'net_amount' => '85.0000', 'unit_cost' => '6.9000', 'customer_code' => null, 'channel' => 'LOCAL', 'source_recorded_at' => $d(10).' 09:16:00'],
            ['document_number' => "{$prefix}-001", 'document_type' => 'FACTURA', 'status' => 'EMITIDA', 'issued_at' => $d(10).' 09:15:00', 'branch_code' => '001', 'line_number' => 2, 'product_sku' => 'VAR-12', 'product_name' => 'Varilla 12mm', 'category' => 'Hierro', 'quantity' => 5, 'unit_price' => '7.2000', 'discount' => '1.0000', 'tax' => '5.2500', 'net_amount' => '35.0000', 'unit_cost' => '5.1000', 'customer_code' => null, 'channel' => 'LOCAL', 'source_recorded_at' => $d(10).' 09:16:00'],
            ['document_number' => "{$prefix}-002", 'document_type' => 'FACTURA', 'status' => 'EMITIDA', 'issued_at' => $d(5).' 11:00:00', 'branch_code' => '001', 'line_number' => 1, 'product_sku' => 'CEM-50', 'product_name' => 'Cemento 50kg', 'category' => 'Cemento', 'quantity' => 4, 'unit_price' => '8.5000', 'discount' => '0.0000', 'tax' => '4.0800', 'net_amount' => '34.0000', 'unit_cost' => '6.9000', 'customer_code' => null, 'channel' => 'MAYOREO', 'source_recorded_at' => $d(5).' 11:01:00'],
            ['document_number' => "{$prefix}-003", 'document_type' => 'FACTURA', 'status' => 'EMITIDA', 'issued_at' => $d(2).' 15:45:00', 'branch_code' => '001', 'line_number' => 1, 'product_sku' => 'PIN-01', 'product_name' => 'Pintura galón', 'category' => 'Pinturas', 'quantity' => 3, 'unit_price' => '15.0000', 'discount' => '0.0000', 'tax' => '5.4000', 'net_amount' => '45.0000', 'unit_cost' => '11.0000', 'customer_code' => null, 'channel' => 'LOCAL', 'source_recorded_at' => $d(2).' 15:46:00'],
        ];
    }
}
