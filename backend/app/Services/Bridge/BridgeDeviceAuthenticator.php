<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Models\BridgeDevice;
use App\Models\DataSource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Autenticación de las peticiones del dispositivo Bridge (ADR-V25-10). Cada llamada exige DOS factores, relidos de la base en cada
 * petición (la revocación es inmediata y no hay caché de credenciales):
 *
 *  1. el TOKEN del dispositivo (`Authorization: Bearer brt_…`), del que solo se guarda el SHA-256;
 *  2. la FIRMA Ed25519 de la petición con la clave privada del dispositivo (que nunca sale de él), con marca de tiempo (±5 min) y nonce de
 *     un solo uso (memoria anti-repetición): un token robado sin la clave privada no sirve, y una petición capturada no puede repetirse.
 *
 * Códigos: 401 `device_unauthorized` (token ausente o desconocido), `device_revoked`, `device_signature_invalid`, `device_clock_skew`,
 * `device_replay`; 423 `tenant_inactive`; 403 `data_source_unavailable`.
 */
final class BridgeDeviceAuthenticator
{
    public function __construct(private readonly BridgeNonceStore $nonces) {}

    /** @return array{device: BridgeDevice, tenant: Tenant, source: DataSource} */
    public function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null || preg_match('/^brt_[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new BridgeRejected('Credencial del dispositivo ausente o no válida.', 'device_unauthorized', 401);
        }
        $device = BridgeDevice::withoutTenantScope()->where('token_hash', hash('sha256', $token))->first();
        if ($device === null) {
            throw new BridgeRejected('Credencial del dispositivo ausente o no válida.', 'device_unauthorized', 401);
        }
        if ($device->isRevoked()) {
            throw new BridgeRejected('Este dispositivo fue revocado: no puede enviar datos.', 'device_revoked', 401);
        }
        $this->verifySignature($request, $device);

        $tenant = Tenant::query()->find($device->tenant_id);
        if ($tenant === null || $tenant->status !== 'active') {
            throw new BridgeRejected('La empresa no está activa.', 'tenant_inactive', 423);
        }
        $source = DataSource::withoutTenantScope()->where('tenant_id', $device->tenant_id)->whereKey($device->data_source_id)->first();
        if ($source === null) {
            throw new BridgeRejected('La fuente de datos del dispositivo ya no existe.', 'data_source_unavailable', 403);
        }

        return ['device' => $device, 'tenant' => $tenant, 'source' => $source];
    }

    /** Estado de un dispositivo para la URL prefirmada (sin firma de petición: la autoriza la firma de la URL). @return array{device: BridgeDevice, tenant: Tenant, source: DataSource} */
    public function authorizeById(int $deviceId, string $tenantUuid): array
    {
        $device = BridgeDevice::withoutTenantScope()->find($deviceId);
        $tenant = $device !== null ? Tenant::query()->find($device->tenant_id) : null;
        // La URL ya viene firmada por el servidor; aun así se exige que sea de ESTE dispositivo y de SU empresa.
        if ($device === null || $tenant === null || ! hash_equals($tenant->uuid, $tenantUuid)) {
            throw new BridgeRejected('La URL de subida no corresponde a un dispositivo válido.', 'device_unauthorized', 401);
        }
        if ($device->isRevoked()) {
            throw new BridgeRejected('Este dispositivo fue revocado: no puede enviar datos.', 'device_revoked', 401);
        }
        if ($tenant->status !== 'active') {
            throw new BridgeRejected('La empresa no está activa.', 'tenant_inactive', 423);
        }
        $source = DataSource::withoutTenantScope()->where('tenant_id', $device->tenant_id)->whereKey($device->data_source_id)->first();
        if ($source === null) {
            throw new BridgeRejected('La fuente de datos del dispositivo ya no existe.', 'data_source_unavailable', 403);
        }

        return ['device' => $device, 'tenant' => $tenant, 'source' => $source];
    }

    private function verifySignature(Request $request, BridgeDevice $device): void
    {
        $keyB64 = (string) $request->header('X-Bridge-Key', '');
        $signatureB64 = (string) $request->header('X-Bridge-Signature', '');
        $timestamp = (string) $request->header('X-Bridge-Timestamp', '');
        $nonce = (string) $request->header('X-Bridge-Nonce', '');
        $invalid = new BridgeRejected('La firma de la petición no es válida.', 'device_signature_invalid', 401);
        if ($keyB64 === '' || $signatureB64 === '' || preg_match('/^\d{9,12}$/', $timestamp) !== 1 || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1) {
            throw $invalid;
        }
        try {
            $publicKey = BridgeSignature::decode($keyB64);
            $signature = BridgeSignature::decode($signatureB64);
        } catch (InvalidArgumentException) {
            throw $invalid;
        }
        // La clave que acompaña a la petición debe ser la registrada (la huella es un compromiso de hash).
        if (! hash_equals($device->public_key_fingerprint, BridgeSignature::fingerprint($publicKey))) {
            throw $invalid;
        }
        $skew = (int) config('bridge.signature_skew_seconds', 300);
        if (abs(time() - (int) $timestamp) > $skew) {
            throw new BridgeRejected('El reloj del dispositivo difiere demasiado del del servidor.', 'device_clock_skew', 401, ['server_time' => time()]);
        }
        $message = BridgeSignature::message($request->method(), $request->getPathInfo(), (string) $request->server->get('QUERY_STRING', ''), $timestamp, $nonce, $request->getContent());
        if (! BridgeSignature::verify($publicKey, $signature, $message)) {
            throw $invalid;
        }
        // Nonce de un solo uso, DESPUÉS de verificar la firma (quien no tiene la clave no puede agotar nonces). En la base de datos: no depende de la caché (B-5).
        if (! $this->nonces->remember($device->id, $nonce)) {
            throw new BridgeRejected('Petición repetida.', 'device_replay', 401);
        }
    }
}
