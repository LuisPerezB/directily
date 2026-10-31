<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;
use SodiumException;

/**
 * Verificación de integridad y AUTENTICIDAD de los artefactos de modelos al revertir (demanda y comportamiento de compra):
 * SHA-256 y firma Ed25519 del manifiesto (ADR-V25-20; defecto HIGH de modelos_aws: con solo el SHA-256 de la base de datos, quien
 * escribiera una fila y un archivo ejecutaba código en el motor al cargar el pickle).
 *
 * UNA regla de ubicación para todo el sistema: el motor guarda `model_registry.artifact_path` RELATIVO a `ENGINE_ARTIFACTS_DIR`
 * (con `/`, p. ej. `24/customer/run-35/h90_quantity_gamma.joblib`). El backend lo resuelve contra `engine.artifacts_dir`
 * (la misma variable `ENGINE_ARTIFACTS_DIR`, apuntando al mismo volumen compartido) y una ruta relativa nunca sale de él (ni `..`
 * ni enlaces simbólicos). Si el backend no ve el archivo NO lo da por bueno ni por malo: lo informa
 * explícitamente (`status` y `reason`) y deja la verificación al motor, que valida firma y SHA-256 antes de deserializar; la marca
 * `artifact_compat.signature.status = unsigned` que el motor dejó al guardar sí basta para rechazar. Una discrepancia con el
 * archivo visible impide la reversión (409): SHA-256 (`rollback_artifact_mismatch`), sin firma (`rollback_artifact_unsigned`) o con
 * firma no válida / clave desconocida / manifiesto de otra empresa (`rollback_artifact_signature_invalid`).
 *
 * Firma: `<artefacto>.manifest.json` es un sobre JSON {format, algorithm, key_id, manifest, signature}; la firma Ed25519
 * (`sodium_crypto_sign_verify_detached`) cubre los BYTES EXACTOS del texto `manifest`, que el motor genera en JSON canónico. Las
 * claves públicas de confianza vienen de `engine.artifact_signing.trusted_public_keys` (`ENGINE_TRUSTED_PUBLIC_KEYS`, `kid:clave`).
 * Un artefacto sin firma solo se acepta con `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` (legado, verificable por SHA-256); una firma
 * inválida, una clave desconocida o un manifiesto corrupto NUNCA se aceptan, con ningún indicador.
 *
 * Filas anteriores se siguen leyendo: ruta absoluta o relativa al directorio de trabajo del motor e incluyendo el propio directorio
 * (`storage/artifacts/<empresa>/…`). Una ruta absoluta de una fila manipulada solo permite comprobar un archivo ajeno, que no se
 * acepta sin un manifiesto firmado de la empresa a su lado (confinar las absolutas queda pendiente: defecto MEDIUM de modelos_aws).
 */
final class ModelArtifactVerifier
{
    public const VERIFIED = 'verified';

    public const PARTIAL = 'partially_verified';

    public const NOT_VERIFIABLE = 'not_verifiable';

    public const NO_ARTIFACTS = 'no_artifacts';

    public const MANIFEST_SUFFIX = '.manifest.json';

    public const ENVELOPE_FORMAT = 'predictily-signed-manifest/1';

    public const MANIFEST_SCHEMA = 'predictily-artifact-manifest/1';

    // Motivos estables (los mismos códigos que el motor).
    public const REASON_UNSIGNED = 'artifact_unsigned';

    public const REASON_MANIFEST_INVALID = 'artifact_manifest_invalid';

    public const REASON_UNTRUSTED_KEY = 'artifact_untrusted_key';

    public const REASON_SIGNATURE_INVALID = 'artifact_signature_invalid';

    public const REASON_MANIFEST_MISMATCH = 'artifact_manifest_mismatch';

    public const REASON_SIZE_MISMATCH = 'artifact_size_mismatch';

    public const REASON_TENANT_MISMATCH = 'artifact_tenant_mismatch';

    public const REASON_SHA256_MISMATCH = 'artifact_sha256_mismatch';

    public const REASON_VERIFIER_UNAVAILABLE = 'signature_verifier_unavailable';

    private const MAX_ENVELOPE_BYTES = 262144;

    private const KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    /** Directorio de artefactos que ve el backend (null = no configurado). */
    public function directory(): ?string
    {
        $dir = config('engine.artifacts_dir');
        if (! is_string($dir) || trim($dir) === '') {
            return null;
        }
        $dir = trim($dir);
        $absolute = str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir) === 1;
        $path = $absolute ? $dir : base_path($dir);
        $real = realpath($path);

        return $real !== false ? str_replace('\\', '/', $real) : null;
    }

    /**
     * Ruta del archivo en ESTE servicio, o null si no se puede ubicar (sin configuración o inexistente). Una ruta relativa nunca
     * se resuelve fuera del directorio de artefactos (ni `..` ni enlaces simbólicos); la absoluta es solo de filas anteriores.
     */
    public function locate(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '' || str_contains($stored, "\0")) {
            return null;
        }
        if (str_starts_with($stored, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $stored) === 1) {
            return is_file($stored) ? $stored : null;   // fila anterior con ruta absoluta (su autenticidad la garantiza la firma, ver inspect())
        }
        $parts = explode('/', str_replace('\\', '/', $stored));
        if (in_array('..', $parts, true) || in_array('', $parts, true)) {
            return null;   // nunca se sale del directorio de artefactos
        }
        $dir = $this->directory();
        if ($dir === null) {
            return null;
        }
        $candidates = [$dir.'/'.implode('/', $parts)];
        // Fila anterior: relativa al directorio de trabajo del motor e incluyendo el directorio (`storage/artifacts/24/...`).
        $dirParts = explode('/', trim($dir, '/'));
        for ($k = min(count($dirParts), count($parts) - 1); $k >= 1; $k--) {
            if (array_slice($dirParts, -$k) === array_slice($parts, 0, $k)) {
                $candidates[] = $dir.'/'.implode('/', array_slice($parts, $k));
                break;
            }
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $this->within($dir, $candidate);   // un enlace simbólico que salga del directorio no se sigue
            }
        }

        return null;
    }

    /**
     * @param  iterable<ModelRegistry>  $models
     * @return array{verified: ?bool, status: string, registered: int, checked: int, reason: ?string, message: string, signed: int, unsigned_allowed: int}
     *
     * @throws DomainStateException si el SHA-256 o la firma de un archivo visible no son válidos, o si el motor marcó el artefacto como sin firma
     */
    public function verify(iterable $models): array
    {
        $registered = 0;
        $checked = 0;
        $signed = 0;
        $unsignedAllowed = 0;
        foreach ($models as $model) {
            if ($model->artifact_path === null || $model->artifact_sha256 === null) {
                continue;
            }
            $registered++;
            $file = $this->locate((string) $model->artifact_path);
            if ($file === null) {
                // No visible desde el backend: la marca explícita que dejó el motor al guardar sin clave de firma basta para rechazar.
                if (($model->artifact_compat['signature']['status'] ?? null) === 'unsigned' && ! $this->allowsUnsigned()) {
                    throw $this->unsigned($model);
                }

                continue;
            }
            $inspection = $this->inspect($file, (int) $model->tenant_id, (string) $model->artifact_sha256);
            if (! $inspection['ok']) {
                throw $this->rejection($model, $inspection['reason']);
            }
            if ($inspection['status'] === 'signed') {
                $signed++;
            } else {
                $unsignedAllowed++;
            }
            $checked++;
        }
        $detail = $this->signatureDetail($signed, $unsignedAllowed);
        if ($registered === 0) {
            return $this->result(null, self::NO_ARTIFACTS, 0, 0, null, 'Ningún modelo restaurado registra un artefacto (se recalculan al predecir): no hay SHA-256 ni firma que verificar.', 0, 0);
        }
        if ($checked === $registered) {
            return $this->result(true, self::VERIFIED, $registered, $checked, null, "SHA-256 verificado en {$checked} de {$registered} artefacto(s) registrado(s){$detail}.", $signed, $unsignedAllowed);
        }
        $reason = $this->directory() === null
            ? (config('engine.artifacts_dir') ? 'artifacts_dir_not_accessible' : 'artifacts_dir_not_configured')
            : 'artifact_files_not_found';
        $why = match ($reason) {
            'artifacts_dir_not_configured' => 'el backend no tiene configurado ENGINE_ARTIFACTS_DIR (el volumen de artefactos del motor)',
            'artifacts_dir_not_accessible' => 'el directorio configurado en ENGINE_ARTIFACTS_DIR no existe o no es legible desde el backend',
            default => 'el backend no encuentra los archivos en ENGINE_ARTIFACTS_DIR',
        };

        return $this->result(null, $checked > 0 ? self::PARTIAL : self::NOT_VERIFIABLE, $registered, $checked, $reason,
            "No se pudo verificar el SHA-256 ni la firma de {$registered} artefacto(s) ({$checked} verificado(s){$detail}): {$why}. El motor los verifica al cargar el artefacto.", $signed, $unsignedAllowed);
    }

    /**
     * Verifica un archivo visible: SHA-256 del registro, manifiesto firmado, clave de confianza, firma Ed25519 sobre los bytes
     * del manifiesto y coherencia (SHA-256, tamaño, nombre de archivo y empresa). No deserializa nada.
     *
     * @return array{ok: bool, status: string, reason: ?string, key_id: ?string}
     */
    public function inspect(string $file, int $tenantId, string $expectedSha256): array
    {
        $digest = hash_file('sha256', $file);
        if ($digest === false || ! hash_equals(strtolower($expectedSha256), $digest)) {
            return $this->rejected(self::REASON_SHA256_MISMATCH);
        }
        $sidecar = $file.self::MANIFEST_SUFFIX;
        if (! is_file($sidecar)) {
            return $this->unsignedOutcome();
        }
        $raw = file_get_contents($sidecar, false, null, 0, self::MAX_ENVELOPE_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_ENVELOPE_BYTES) {
            return $this->rejected(self::REASON_MANIFEST_INVALID);
        }
        $envelope = json_decode($raw, true, 8);
        if (! is_array($envelope) || ($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT || ! is_string($envelope['manifest'] ?? null)) {
            return $this->rejected(self::REASON_MANIFEST_INVALID);
        }
        $keyId = $envelope['key_id'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if ($signature === null && $keyId === null) {
            return $this->unsignedOutcome();   // marcado sin firma por el motor (entrenado sin clave privada)
        }
        if (($envelope['algorithm'] ?? null) !== 'ed25519' || ! is_string($keyId) || preg_match(self::KEY_ID_PATTERN, $keyId) !== 1 || ! is_string($signature)) {
            return $this->rejected(self::REASON_MANIFEST_INVALID);
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return $this->rejected(self::REASON_VERIFIER_UNAVAILABLE);   // sin la extensión sodium no se puede confiar en nada
        }
        $publicKey = $this->trustedKeys()[$keyId] ?? null;
        if ($publicKey === null) {
            return $this->rejected(self::REASON_UNTRUSTED_KEY);
        }
        $rawSignature = $this->base64($signature);
        try {
            $valid = $rawSignature !== null && strlen($rawSignature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($rawSignature, $envelope['manifest'], $publicKey);
        } catch (SodiumException) {
            $valid = false;
        }
        if (! $valid) {
            return $this->rejected(self::REASON_SIGNATURE_INVALID);
        }

        // El manifiesto lo firmó una clave de confianza: se comprueba que describe ESTE archivo y ESTA empresa.
        $manifest = json_decode($envelope['manifest'], true, 16);
        $artifact = is_array($manifest) ? ($manifest['artifact'] ?? null) : null;
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== self::MANIFEST_SCHEMA || ! is_array($artifact)
            || ($manifest['key_id'] ?? null) !== $keyId || ! is_string($artifact['sha256'] ?? null)) {
            return $this->rejected(self::REASON_MANIFEST_INVALID);
        }
        if (! hash_equals($artifact['sha256'], $digest)) {
            return $this->rejected(self::REASON_MANIFEST_MISMATCH);
        }
        if (($artifact['size_bytes'] ?? null) !== filesize($file)) {
            return $this->rejected(self::REASON_SIZE_MISMATCH);
        }
        if (($artifact['file_name'] ?? null) !== basename($file)) {
            return $this->rejected(self::REASON_MANIFEST_MISMATCH);
        }
        if (($manifest['tenant_id'] ?? null) !== $tenantId) {
            return $this->rejected(self::REASON_TENANT_MISMATCH);
        }

        return ['ok' => true, 'status' => 'signed', 'reason' => null, 'key_id' => $keyId];
    }

    /** ¿Se aceptan artefactos legados SIN firma? Solo con ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true (por defecto no). */
    public function allowsUnsigned(): bool
    {
        return filter_var(config('engine.artifact_signing.allow_unsigned', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Claves públicas de confianza: kid => clave binaria (32 bytes). Acepta «kid:clave» separados por comas, punto y coma o
     * espacios, o un arreglo kid => base64. Falla cerrado: una entrada inválida o un kid repetido con valores distintos no confía.
     *
     * @return array<string, string>
     */
    public function trustedKeys(): array
    {
        $configured = config('engine.artifact_signing.trusted_public_keys');
        $pairs = [];
        if (is_array($configured)) {
            foreach ($configured as $kid => $value) {
                if (is_string($kid) && is_string($value)) {
                    $pairs[] = [$kid, $value];
                } elseif (is_string($value) && str_contains($value, ':')) {
                    $pairs[] = explode(':', $value, 2);
                }
            }
        } elseif (is_string($configured)) {
            foreach (preg_split('/[,;\s]+/', trim($configured), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                if (str_contains($entry, ':')) {
                    $pairs[] = explode(':', $entry, 2);
                }
            }
        }
        $keys = [];
        $conflicted = [];
        foreach ($pairs as [$kid, $value]) {
            $raw = preg_match(self::KEY_ID_PATTERN, (string) $kid) === 1 ? $this->base64((string) $value) : null;
            if ($raw === null || strlen($raw) !== 32) {
                continue;
            }
            if (isset($keys[$kid]) && $keys[$kid] !== $raw) {
                $conflicted[$kid] = true;
            }
            $keys[$kid] = $raw;
        }

        return array_diff_key($keys, $conflicted);
    }

    /** Base64 estándar o URL-safe, con o sin relleno (igual que el motor). */
    private function base64(string $value): ?string
    {
        $text = strtr(trim($value), '-_', '+/');
        $text .= str_repeat('=', (4 - strlen($text) % 4) % 4);
        $raw = base64_decode($text, true);

        return $raw === false ? null : $raw;
    }

    /** El archivo (ya existente) solo se devuelve si su ruta real queda dentro del directorio de artefactos. */
    private function within(?string $dir, string $file): ?string
    {
        $real = realpath($file);
        if ($dir === null || $real === false) {
            return null;
        }
        $real = str_replace('\\', '/', $real);

        return str_starts_with($real, rtrim($dir, '/').'/') ? $real : null;
    }

    /** @return array{ok: bool, status: string, reason: ?string, key_id: ?string} */
    private function rejected(string $reason): array
    {
        return ['ok' => false, 'status' => 'rejected', 'reason' => $reason, 'key_id' => null];
    }

    /**
     * Sin firma: solo se acepta con el indicador explícito de legado (verificable por el SHA-256 ya comprobado).
     *
     * @return array{ok: bool, status: string, reason: ?string, key_id: ?string}
     */
    private function unsignedOutcome(): array
    {
        return $this->allowsUnsigned()
            ? ['ok' => true, 'status' => 'unsigned_allowed', 'reason' => null, 'key_id' => null]
            : $this->rejected(self::REASON_UNSIGNED);
    }

    private function rejection(ModelRegistry $model, ?string $reason): DomainStateException
    {
        return match ($reason) {
            self::REASON_SHA256_MISMATCH => new DomainStateException('El artefacto del modelo '.$model->version.' no coincide con su huella SHA-256: no se puede restaurar.', 'rollback_artifact_mismatch', [
                'model_id' => $model->id,
            ]),
            self::REASON_UNSIGNED => $this->unsigned($model),
            default => new DomainStateException('El artefacto del modelo '.$model->version.' no tiene una firma Ed25519 válida de una clave de confianza: no se puede restaurar.', 'rollback_artifact_signature_invalid', [
                'model_id' => $model->id, 'reason' => $reason,
            ]),
        };
    }

    private function unsigned(ModelRegistry $model): DomainStateException
    {
        return new DomainStateException(
            'El artefacto del modelo '.$model->version.' no está firmado (Ed25519) y no se puede restaurar. Reentrénelo con una clave de firma configurada; solo en un entorno local con artefactos legados puede aceptarse con ENGINE_ALLOW_UNSIGNED_ARTIFACTS.',
            'rollback_artifact_unsigned',
            ['model_id' => $model->id, 'reason' => self::REASON_UNSIGNED],
        );
    }

    private function signatureDetail(int $signed, int $unsignedAllowed): string
    {
        if ($signed + $unsignedAllowed === 0) {
            return '';
        }

        return ", firma Ed25519 válida en {$signed}".($unsignedAllowed > 0 ? " y {$unsignedAllowed} legado(s) sin firma aceptado(s) por ENGINE_ALLOW_UNSIGNED_ARTIFACTS" : '');
    }

    /** @return array{verified: ?bool, status: string, registered: int, checked: int, reason: ?string, message: string, signed: int, unsigned_allowed: int} */
    private function result(?bool $verified, string $status, int $registered, int $checked, ?string $reason, string $message, int $signed, int $unsignedAllowed): array
    {
        return ['verified' => $verified, 'status' => $status, 'registered' => $registered, 'checked' => $checked, 'reason' => $reason, 'message' => $message, 'signed' => $signed, 'unsigned_allowed' => $unsignedAllowed];
    }
}
