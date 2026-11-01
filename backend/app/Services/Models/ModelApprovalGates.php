<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Exceptions\DomainStateException;
use App\Models\DataQualityReport;
use App\Models\ModelPromotionDecision;
use App\Models\ModelRegistry;
use App\Models\Tenant;
use App\Services\Customer\CustomerRollbackService;
use App\Services\Forecasting\ModelArtifactVerifier;
use App\Support\Horizons\HorizonCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compuertas que debe pasar un modelo para validarse, aprobarse o promoverse (ADR-V25-07; todas fallan CERRADO):
 *
 *  1. FIRMA: el artefacto registrado se verifica con ModelArtifactVerifier::inspect (SHA-256 del registro, manifiesto firmado, clave de
 *     confianza, firma Ed25519, empresa/core/archivo). Debe estar FIRMADO: un legado sin firma aceptado por ENGINE_ALLOW_UNSIGNED_ARTIFACTS
 *     no se aprueba. Un modelo publicado se verifica sobre el objeto del almacén (lo que se serviría) y, si no registra `published_object_key`,
 *     NO se aprueba (falla cerrado); el resto, sobre ENGINE_ARTIFACTS_DIR.
 *     Si el archivo no es visible desde el backend no se puede verificar y NO se aprueba. Un modelo sin artefacto (estadístico que se
 *     reajusta en cada corrida) no tiene qué firmar: la compuerta NO APLICA y queda dicho en la evidencia.
 *  2. DATOS (W2-C): el gate de calidad de datos de la ejecución del modelo (`data_quality_reports` scope run → gate_results) no puede ser BLOCK. Sin
 *     informe de ejecución se admite el resultado declarado por quien publicó (`artifact_compat.data_gate.outcome`, de validate-data); sin
 *     ninguna evidencia no se aprueba.
 *  3. POLÍTICA (W3): si la decisión de promoción asociada (`promotion_decision_id`) es `reject`, los gates de la política no se anulan con una aprobación.
 *  4. COMPATIBILIDAD (Customer): slot válido y esquema de variables igual al del motor.
 */
final class ModelApprovalGates
{
    private const OUTCOMES = ['PASS', 'WARN', 'DEGRADE', 'BLOCK'];

    public function __construct(
        private readonly ModelArtifactVerifier $artifacts,
        private readonly TenantContext $context,
        private readonly ModelServingPolicy $policy,
    ) {}

    /**
     * @return array{signature: array<string, mixed>, data_gate: array<string, mixed>, policy_decision: ?array<string, mixed>, compatibility: array<string, mixed>}
     *
     * @throws DomainStateException 409 con el código de la compuerta que falló
     */
    public function evaluate(ModelRegistry $model): array
    {
        return [
            'signature' => $this->signature($model),
            'data_gate' => $this->dataGate($model),
            'policy_decision' => $this->policyDecision($model),
            'compatibility' => $this->compatibility($model),
        ];
    }

    /** @return array<string, mixed> */
    public function signature(ModelRegistry $model): array
    {
        $key = $model->published_object_key;
        if (($key === null || $key === '') && $this->policy->isPublished($model)) {
            // Un modelo publicado se sirve desde el objeto del almacén: sin su clave no hay qué verificar, y no se verifica otro archivo en su lugar.
            throw new DomainStateException(
                'El modelo '.$model->version.' figura como publicado pero no registra la clave de su objeto (published_object_key): no se puede verificar la firma y no se aprueba.',
                'approval_artifact_not_verifiable',
                ['model_id' => $model->id, 'reason' => 'published_without_object_key'],
            );
        }
        $hasArtifact = ($model->artifact_path !== null && $model->artifact_path !== '') || ($key !== null && $key !== '');
        if (! $hasArtifact) {
            return ['status' => 'not_applicable', 'reason' => 'El modelo no registra artefacto (se reajusta en cada corrida): no hay nada que firmar ni deserializar.'];
        }
        if ($model->artifact_sha256 === null || $model->artifact_sha256 === '') {
            throw new DomainStateException('El modelo '.$model->version.' no registra la huella SHA-256 de su artefacto: no se puede verificar la firma.', 'approval_artifact_without_checksum', ['model_id' => $model->id]);
        }
        $core = is_string($model->core ?? null) && $model->core !== '' ? $model->core : null;
        $runId = $core === 'demand' && is_numeric($model->forecast_run_id ?? null) ? (int) $model->forecast_run_id : null;

        if ($key !== null && $key !== '') {
            $tmp = $this->fetchPublished($model, $key);
            try {
                $inspection = $this->artifacts->inspect($tmp['file'], (int) $model->tenant_id, (string) $model->artifact_sha256, $core, $runId);
            } finally {
                $this->removeDir($tmp['dir']);
            }
            $source = 'object_store';
        } else {
            $file = $this->artifacts->locate((string) $model->artifact_path);
            if ($file === null) {
                throw new DomainStateException(
                    'El artefacto del modelo '.$model->version.' no es visible desde el backend (ENGINE_ARTIFACTS_DIR): sin verificar la firma no se aprueba.',
                    'approval_artifact_not_verifiable',
                    ['model_id' => $model->id],
                );
            }
            $inspection = $this->artifacts->inspect($file, (int) $model->tenant_id, (string) $model->artifact_sha256, $core, $runId);
            $source = 'artifacts_dir';
        }

        if (! $inspection['ok']) {
            throw $this->rejection($model, (string) $inspection['reason']);
        }
        if ($inspection['status'] !== 'signed') {
            throw new DomainStateException('El artefacto del modelo '.$model->version.' no está firmado (Ed25519): un legado sin firma no se aprueba; reentrénelo o vuelva a firmarlo.', 'approval_artifact_unsigned', ['model_id' => $model->id]);
        }

        return ['status' => 'verified', 'source' => $source, 'key_id' => $inspection['key_id'], 'sha256_prefix' => substr((string) $model->artifact_sha256, 0, 12)];
    }

    /** @return array<string, mixed> */
    public function dataGate(ModelRegistry $model): array
    {
        $module = (string) $model->core;
        $report = null;
        if ($model->forecast_run_id !== null) {
            $report = DataQualityReport::query()->where('scope', 'run')->where('forecast_run_id', $model->forecast_run_id)->orderByDesc('id')->first();
        } elseif ($model->core_run_id !== null) {
            $report = DataQualityReport::query()->where('scope', 'run')->where('core_run_id', $model->core_run_id)->orderByDesc('id')->first();
        }
        $outcome = null;
        $source = 'run_report';
        $reportId = null;
        if ($report !== null) {
            $results = (array) ($report->gate_results ?? []);
            $entry = $results[$module] ?? (count($results) === 1 ? reset($results) : null);
            $outcome = is_array($entry) ? ($entry['outcome'] ?? null) : null;
            $reportId = $report->id;
        }
        if (! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)) {
            $declared = $model->artifact_compat['data_gate']['outcome'] ?? null;
            if (is_string($declared) && in_array($declared, self::OUTCOMES, true)) {
                $outcome = $declared;
                $source = 'declared_by_publisher';
                $reportId = null;
            } else {
                $outcome = null;
            }
        }
        if ($outcome === null) {
            throw new DomainStateException(
                'No hay evidencia del gate de calidad de datos de la ejecución del modelo '.$model->version.' (informe de la ejecución o resultado declarado al publicar): sin evidencia no se aprueba.',
                'approval_data_gate_missing',
                ['model_id' => $model->id],
            );
        }
        if ($outcome === 'BLOCK') {
            throw new DomainStateException('El gate de calidad de datos de la ejecución del modelo '.$model->version.' es BLOCK: no se puede aprobar.', 'approval_data_gate_blocked', ['model_id' => $model->id, 'report_id' => $reportId]);
        }

        return array_filter(['outcome' => $outcome, 'module' => $module, 'source' => $source, 'report_id' => $reportId], fn ($v) => $v !== null);
    }

    /** @return ?array<string, mixed> */
    public function policyDecision(ModelRegistry $model): ?array
    {
        if ($model->promotion_decision_id === null) {
            return null;
        }
        $decision = ModelPromotionDecision::query()->find($model->promotion_decision_id);
        if ($decision === null) {
            return null;
        }
        if ($decision->decision === 'reject') {
            throw new DomainStateException(
                'La política de promoción rechazó a este candidato (decisión «reject»): una aprobación no anula los gates de la política.',
                'approval_policy_rejected',
                ['model_id' => $model->id, 'promotion_decision_id' => $decision->id],
            );
        }

        return ['id' => $decision->id, 'policy' => $decision->policy, 'decision' => $decision->decision, 'scope_key' => $decision->scope_key, 'is_effective' => (bool) $decision->is_effective];
    }

    /** @return array<string, mixed> */
    public function compatibility(ModelRegistry $model): array
    {
        if ($model->core !== 'customer') {
            return ['status' => 'not_applicable'];
        }
        $slot = (string) ($model->hyperparameters['slot'] ?? '');
        if (preg_match('/^h('.implode('|', HorizonCatalog::customer()).'):(probability|quantity)$/', $slot) !== 1) {
            throw new DomainStateException('El modelo no declara un horizonte y objetivo (slot) válidos.', 'model_without_slot', ['model_id' => $model->id, 'slot' => $slot ?: null]);
        }
        $schema = $model->artifact_compat['feature_schema_version'] ?? null;
        if ($schema !== CustomerRollbackService::FEATURE_SCHEMA_VERSION) {
            throw new DomainStateException('El esquema de variables del artefacto no es compatible con el motor actual.', 'model_incompatible', ['artifact' => $schema, 'engine' => CustomerRollbackService::FEATURE_SCHEMA_VERSION]);
        }

        return ['status' => 'compatible', 'slot' => $slot, 'feature_schema_version' => $schema];
    }

    /**
     * Descarga el objeto publicado y su manifiesto a un directorio temporal PRIVADO. La clave debe ser del servidor y de la empresa activa
     * (`tenants/<uuid>/models/...`): una clave de otra empresa o con rutas relativas se rechaza antes de tocar el almacén.
     *
     * @return array{dir: string, file: string}
     */
    private function fetchPublished(ModelRegistry $model, string $key): array
    {
        $tenant = $this->context->require();
        $this->assertOwnKey($tenant, $key);
        $disk = Storage::disk((string) config('mlops.published_disk', 'local'));
        $sidecarKey = $key.ModelArtifactVerifier::MANIFEST_SUFFIX;
        try {
            $missing = ! $disk->exists($key) || ! $disk->exists($sidecarKey);
        } catch (Throwable) {
            $missing = true;
        }
        if ($missing) {
            throw new DomainStateException('El objeto publicado del modelo '.$model->version.' no está en el almacén de objetos: sin verificar la firma no se aprueba.', 'approval_artifact_not_verifiable', ['model_id' => $model->id]);
        }
        $dir = sys_get_temp_dir().'/predictily-approval-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new DomainStateException('No se pudo preparar la verificación del artefacto.', 'approval_artifact_not_verifiable', ['model_id' => $model->id]);
        }
        try {
            $file = $dir.'/'.basename($key);
            foreach ([[$key, $file], [$sidecarKey, $file.ModelArtifactVerifier::MANIFEST_SUFFIX]] as [$from, $to]) {
                $in = $disk->readStream($from);
                $out = fopen($to, 'wb');
                if (! is_resource($in) || $out === false) {
                    throw new DomainStateException('No se pudo leer el objeto publicado del modelo '.$model->version.'.', 'approval_artifact_not_verifiable', ['model_id' => $model->id]);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } catch (Throwable $e) {
            $this->removeDir($dir);
            throw $e;
        }

        return ['dir' => $dir, 'file' => $file];
    }

    private function assertOwnKey(Tenant $tenant, string $key): void
    {
        $prefix = 'tenants/'.$tenant->uuid.'/models/';
        if (! str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, '//') || preg_match('~^[A-Za-z0-9._/\-]+$~', $key) !== 1 || str_ends_with($key, '/')) {
            throw new DomainStateException('La clave del objeto publicado no pertenece a la empresa: no se verifica ni se aprueba.', 'approval_object_key_invalid', []);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function rejection(ModelRegistry $model, string $reason): DomainStateException
    {
        return match ($reason) {
            ModelArtifactVerifier::REASON_SHA256_MISMATCH => new DomainStateException('El artefacto del modelo '.$model->version.' no coincide con su huella SHA-256: no se aprueba.', 'approval_artifact_mismatch', ['model_id' => $model->id]),
            ModelArtifactVerifier::REASON_UNSIGNED => new DomainStateException('El artefacto del modelo '.$model->version.' no está firmado (Ed25519): no se aprueba.', 'approval_artifact_unsigned', ['model_id' => $model->id]),
            default => new DomainStateException('El artefacto del modelo '.$model->version.' no tiene una firma Ed25519 válida de una clave de confianza: no se aprueba.', 'approval_artifact_signature_invalid', ['model_id' => $model->id, 'reason' => $reason]),
        };
    }
}
