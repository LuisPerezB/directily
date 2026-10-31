<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;

/**
 * Verificación de integridad (SHA-256) de los artefactos de modelos al revertir (demanda y comportamiento de compra).
 *
 * UNA regla de ubicación para todo el sistema: el motor guarda `model_registry.artifact_path` RELATIVO a `ENGINE_ARTIFACTS_DIR`
 * (con `/`, p. ej. `24/customer/run-35/h90_quantity_gamma.joblib`). El backend lo resuelve contra `engine.artifacts_dir`
 * (la misma variable `ENGINE_ARTIFACTS_DIR`, apuntando al mismo volumen compartido). Si el backend no ve el archivo NO lo da por
 * bueno ni por malo: lo informa explícitamente (`status` y `reason`) y deja la verificación al motor, que valida el SHA-256 al
 * cargar el artefacto. Una discrepancia con el archivo visible sí impide la reversión (409 `rollback_artifact_mismatch`).
 *
 * Filas anteriores se siguen leyendo: ruta absoluta, o relativa al directorio de trabajo del motor e incluyendo el propio
 * directorio (`storage/artifacts/<empresa>/…`).
 */
final class ModelArtifactVerifier
{
    public const VERIFIED = 'verified';

    public const PARTIAL = 'partially_verified';

    public const NOT_VERIFIABLE = 'not_verifiable';

    public const NO_ARTIFACTS = 'no_artifacts';

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

    /** Ruta del archivo en ESTE servicio, o null si no se puede ubicar (sin configuración, fuera del directorio o inexistente). */
    public function locate(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }
        if (str_starts_with($stored, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $stored) === 1) {
            return is_file($stored) ? $stored : null;   // fila anterior con ruta absoluta
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
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  iterable<ModelRegistry>  $models
     * @return array{verified: ?bool, status: string, registered: int, checked: int, reason: ?string, message: string}
     *
     * @throws DomainStateException si el SHA-256 de un archivo visible no coincide
     */
    public function verify(iterable $models): array
    {
        $registered = 0;
        $checked = 0;
        foreach ($models as $model) {
            if ($model->artifact_path === null || $model->artifact_sha256 === null) {
                continue;
            }
            $registered++;
            $file = $this->locate((string) $model->artifact_path);
            if ($file === null) {
                continue;
            }
            if (! hash_equals((string) $model->artifact_sha256, hash_file('sha256', $file))) {
                throw new DomainStateException('El artefacto del modelo '.$model->version.' no coincide con su huella SHA-256: no se puede restaurar.', 'rollback_artifact_mismatch', [
                    'model_id' => $model->id,
                ]);
            }
            $checked++;
        }
        if ($registered === 0) {
            return $this->result(null, self::NO_ARTIFACTS, 0, 0, null, 'Ningún modelo restaurado registra un artefacto (se recalculan al predecir): no hay SHA-256 que verificar.');
        }
        if ($checked === $registered) {
            return $this->result(true, self::VERIFIED, $registered, $checked, null, "SHA-256 verificado en {$checked} de {$registered} artefacto(s) registrado(s).");
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
            "No se pudo verificar el SHA-256 de {$registered} artefacto(s) ({$checked} verificado(s)): {$why}. El motor lo verifica al cargar el artefacto.");
    }

    /** @return array{verified: ?bool, status: string, registered: int, checked: int, reason: ?string, message: string} */
    private function result(?bool $verified, string $status, int $registered, int $checked, ?string $reason, string $message): array
    {
        return ['verified' => $verified, 'status' => $status, 'registered' => $registered, 'checked' => $checked, 'reason' => $reason, 'message' => $message];
    }
}
