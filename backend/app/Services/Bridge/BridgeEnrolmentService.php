<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Models\BridgeDevice;
use App\Models\BridgeEnrolmentCode;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alta de dispositivos Bridge (ADR-V25-10, decisión del arquitecto b).
 *
 *  1. Un administrador de la empresa genera un CÓDIGO DE REGISTRO de un solo uso, ligado a UNA fuente de datos (conector
 *     `predictily_bridge`). Del código solo se guarda el SHA-256; se muestra una vez y vence (60 minutos por defecto).
 *  2. El agente genera su par Ed25519 localmente y canjea el código con su clave pública y una PRUEBA DE POSESIÓN (firma de código, clave y
 *     hora): el servidor guarda la huella (SHA-256 de la clave pública) y el hash del TOKEN del dispositivo, que se devuelve UNA vez.
 *  3. El canje consume el código de forma atómica (UPDATE condicionado): dos canjes simultáneos del mismo código no pueden tener éxito ambos.
 *     Código desconocido, vencido o ya usado responden igual (sin oráculo).
 */
final class BridgeEnrolmentService
{
    public const CONNECTOR_KEY = 'predictily_bridge';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const CODE_LENGTH = 20;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /**
     * Genera el código de registro (100 bits). Devuelve el código en claro SOLO para mostrarlo una vez.
     *
     * @return array{code: string, expires_at: CarbonImmutable, id: int}
     */
    public function issue(DataSource $source, User $creator, ?string $deviceNameHint = null, ?int $ttlMinutes = null): array
    {
        if ($source->connector_key !== self::CONNECTOR_KEY) {
            throw new BridgeRejected('Solo las fuentes con el conector «Predictily Bridge» admiten dispositivos.', 'not_a_bridge_source', 422);
        }
        $max = (int) config('bridge.enrolment_max_ttl_minutes', 1440);
        $ttl = max(1, min($ttlMinutes ?? (int) config('bridge.enrolment_ttl_minutes', 60), $max));
        $plain = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $plain .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expiresAt = now()->addMinutes($ttl)->toImmutable();
        $row = new BridgeEnrolmentCode;
        $row->forceFill([
            'data_source_id' => $source->id,
            'code_hash' => self::hashCode($plain),
            'device_name_hint' => $deviceNameHint !== null ? mb_substr($deviceNameHint, 0, 120) : null,
            'created_by' => $creator->id,
            'expires_at' => $expiresAt,
        ])->save();
        $this->audit->log('bridge.enrolment_issued', $row, ['data_source_id' => $source->id, 'expires_at' => $expiresAt->toIso8601String()], userId: $creator->id);

        return ['code' => self::display($plain), 'expires_at' => $expiresAt, 'id' => $row->id];
    }

    /**
     * Canjea el código: crea el dispositivo y su token. Se invoca SIN empresa activa (ruta pública acotada por IP).
     *
     * @param  array{code: string, device_name: string, public_key: string, proof: string, timestamp: int|string, agent_version?: ?string, platform?: ?string}  $data
     * @return array{device: BridgeDevice, token: string, tenant: Tenant, source: DataSource}
     *
     * @throws BridgeRejected
     */
    public function enrol(array $data): array
    {
        $invalid = new BridgeRejected('El código de registro no es válido, ya fue usado o venció.', 'invalid_enrolment_code', 422);
        try {
            $plain = self::normalize($data['code']);
        } catch (InvalidArgumentException) {
            throw $invalid;
        }
        try {
            $publicKey = BridgeSignature::decode($data['public_key']);
            $proof = BridgeSignature::decode($data['proof']);
        } catch (InvalidArgumentException) {
            throw new BridgeRejected('La clave pública o la prueba no son base64 URL-safe válidos.', 'invalid_public_key', 422);
        }
        if (! BridgeSignature::isPublicKey($publicKey)) {
            throw new BridgeRejected('La clave pública debe ser una clave Ed25519 de 32 bytes.', 'invalid_public_key', 422);
        }
        $skew = (int) config('bridge.signature_skew_seconds', 300);
        if (abs(time() - (int) $data['timestamp']) > $skew) {
            throw new BridgeRejected('El reloj del dispositivo difiere demasiado del del servidor.', 'device_clock_skew', 422, ['server_time' => time()]);
        }
        $codeHash = self::hashCode($plain);
        if (! BridgeSignature::verify($publicKey, $proof, BridgeSignature::enrolMessage($codeHash, $data['public_key'], $data['timestamp']))) {
            throw new BridgeRejected('La prueba de posesión de la clave no es válida.', 'invalid_proof', 422);
        }

        $code = BridgeEnrolmentCode::withoutTenantScope()->where('code_hash', $codeHash)->first();
        if ($code === null) {
            throw $invalid;
        }
        $tenant = Tenant::query()->find($code->tenant_id);
        if ($tenant === null || $tenant->status !== 'active') {
            throw $invalid;
        }

        return $this->context->runAs($tenant, function () use ($code, $tenant, $data, $publicKey, $invalid): array {
            return DB::transaction(function () use ($code, $tenant, $data, $publicKey, $invalid): array {
                // Consumo atómico: solo uno de los canjes simultáneos obtiene la fila.
                $consumed = DB::table('bridge_enrolment_codes')->where('id', $code->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
                if ($consumed !== 1) {
                    throw $invalid;
                }
                $source = DataSource::query()->find($code->data_source_id);
                if ($source === null || $source->connector_key !== self::CONNECTOR_KEY) {
                    throw $invalid;
                }
                $fingerprint = BridgeSignature::fingerprint($publicKey);
                if (BridgeDevice::query()->where('public_key_fingerprint', $fingerprint)->whereNull('revoked_at')->exists()) {
                    throw new BridgeRejected('Esa clave pública ya está registrada en un dispositivo activo.', 'public_key_in_use', 409);
                }
                $token = 'brt_'.BridgeSignature::encode(random_bytes(32));
                $device = new BridgeDevice;
                $device->forceFill([
                    'data_source_id' => $source->id,
                    'name' => mb_substr(trim((string) $data['device_name']) !== '' ? (string) $data['device_name'] : ($code->device_name_hint ?? 'Bridge'), 0, 120),
                    'public_key_fingerprint' => $fingerprint,
                    'token_hash' => hash('sha256', $token),
                    'agent_version' => isset($data['agent_version']) ? mb_substr((string) $data['agent_version'], 0, 40) : null,
                    'platform' => isset($data['platform']) ? mb_substr((string) $data['platform'], 0, 40) : null,
                    'registered_at' => now(),
                    'last_seen_at' => now(),
                ])->save();
                DB::table('bridge_enrolment_codes')->where('id', $code->id)->update(['consumed_by_device_id' => $device->id]);
                // La fuente queda activa cuando tiene su primer dispositivo (nace «inactive» hasta entonces).
                if ($source->status === 'inactive') {
                    $source->forceFill(['status' => 'active', 'last_error' => null])->save();
                }
                $this->audit->log('bridge.device_enrolled', $device, ['data_source_id' => $source->id, 'fingerprint_prefix' => substr($fingerprint, 0, 12), 'platform' => $device->platform], $tenant->id);

                return ['device' => $device, 'token' => $token, 'tenant' => $tenant, 'source' => $source];
            });
        });
    }

    public static function hashCode(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    /** `BRG-XXXX-XXXX-XXXX-XXXX-XXXX` → 20 caracteres Crockford en mayúsculas (O→0, I/L→1). @throws InvalidArgumentException */
    public static function normalize(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', trim($code)) ?? '');
        if (str_starts_with($code, 'BRG')) {
            $code = substr($code, 3);
        }
        $code = strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (preg_match('/^['.self::ALPHABET.']{'.self::CODE_LENGTH.'}$/', $code) !== 1) {
            throw new InvalidArgumentException('Código de registro con formato no válido.');
        }

        return $code;
    }

    private static function display(string $plain): string
    {
        return 'BRG-'.implode('-', str_split($plain, 4));
    }
}
