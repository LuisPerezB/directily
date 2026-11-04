<?php

declare(strict_types=1);

namespace Tests\Feature\Models\Support;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\SignsModelArtifacts;

/**
 * Datos de prueba del registro de modelos (V2.5 · W4-A): una empresa con USUARIOS REALES de varios roles (publicador, aprobador, entrenador,
 * analista, consulta), modelos del Customer Core con artefactos FIRMADOS (Ed25519 efímero) bajo un ENGINE_ARTIFACTS_DIR temporal, informes de
 * gate de datos y decisiones de promoción. Los modelos se insertan como los dejarían el motor (legado) o la CLI (publicado).
 */
trait ModelRegistryFixtures
{
    use InteractsWithTenants;
    use SignsModelArtifacts;

    protected string $artifactsDir = '';

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var array<int, int> empresa => corrida del pipeline propio que enlazan los modelos legados de prueba */
    private array $pipelineRuns = [];

    protected function setUpRegistry(): void
    {
        $this->trustNewSigningKey();
        $this->artifactsDir = $this->tempDir('artifacts');
        config(['engine.artifacts_dir' => $this->artifactsDir]);
        // Almacén de objetos de prueba (disco local simulado): donde la CLI publica los artefactos firmados.
        Storage::fake('local');
        config(['mlops.published_disk' => 'local']);
    }

    protected function tearDownRegistry(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
    }

    protected function tempDir(string $label): string
    {
        $dir = sys_get_temp_dir().'/w4a-'.$label.'-'.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * @return array{tenant: Tenant, owner: User, publisher: User, approver: User, trainer: User, analyst: User, viewer: User}
     */
    protected function registryCompany(): array
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);

        return [
            'tenant' => $tenant, 'owner' => $owner,
            'publisher' => $this->addUserToTenant($tenant, TenantRole::Admin),
            'approver' => $this->addUserToTenant($tenant, TenantRole::Admin),
            'trainer' => $this->addUserToTenant($tenant, TenantRole::Admin),
            'analyst' => $this->addUserToTenant($tenant, TenantRole::Analyst),
            'viewer' => $this->addUserToTenant($tenant, TenantRole::Viewer),
        ];
    }

    /** Corrida de entrenamiento del pipeline propio de la empresa (origen positivo de un modelo legado). Una por empresa. */
    protected function pipelineRun(Tenant $tenant): int
    {
        return $this->pipelineRuns[$tenant->id] ??= (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'customer', 'operation' => 'train', 'version' => (int) DB::table('core_runs')->where('tenant_id', $tenant->id)->max('version') + 1,
            'run_type' => 'production', 'status' => 'completed', 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'triggered_by' => 'schedule',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Inserta un modelo del Customer Core como lo deja el MOTOR (pipeline propio: enlazado a una corrida, sin marcas de publicación) con un
     * artefacto firmado y visible en ENGINE_ARTIFACTS_DIR (salvo `$withArtifact = false`). Para un modelo sin origen: `['core_run_id' => null]`.
     *
     * @param  array<string, mixed>  $over  columnas que sustituyen a las de por defecto
     */
    protected function customerModel(Tenant $tenant, string $status, array $over = [], bool $withArtifact = true, string $slot = 'h30:probability'): int
    {
        $version = $over['version'] ?? 'v-'.bin2hex(random_bytes(4));
        $path = null;
        $sha = null;
        if ($withArtifact) {
            $path = $tenant->id.'/customer/'.$version.'.joblib';
            $abs = $this->artifactsDir.'/'.$path;
            @mkdir(dirname($abs), 0777, true);
            file_put_contents($abs, 'artefacto-'.$version);
            $this->signArtifactFile($abs, $tenant->id, ['core' => 'customer']);
            $sha = hash_file('sha256', $abs);
        }

        return (int) DB::table('model_registry')->insertGetId(array_merge([
            'tenant_id' => $tenant->id, 'version' => $version, 'model_family' => 'customer_'.str_replace(':', '_', $slot), 'strategy_level' => 'B', 'scope' => 'tenant',
            'status' => $status, 'core' => 'customer', 'role' => 'primary', 'artifact_path' => $path, 'artifact_sha256' => $sha,
            'core_run_id' => $this->pipelineRun($tenant),
            'hyperparameters' => json_encode(['slot' => $slot, 'kind' => 'complex']),
            'artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'data_gate' => ['outcome' => 'PASS']]),
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    /**
     * Publica el artefacto firmado de un modelo en el almacén de objetos de prueba con la clave del servidor (como lo hará la CLI) y devuelve
     * [clave, SHA-256]. `$overrides` sustituye campos del manifiesto (p. ej. `['core' => 'demand', 'run_id' => 7]`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: string}
     */
    protected function publishObject(Tenant $tenant, string $version, array $overrides = [], string $core = 'customer'): array
    {
        $key = 'tenants/'.$tenant->uuid.'/models/'.$core.'/'.$version.'/'.$version.'.joblib';
        $contents = 'artefacto-publicado-'.$version;
        $file = $this->tempDir('publish').'/'.basename($key);
        file_put_contents($file, $contents);
        $sidecar = $this->signArtifactFile($file, $tenant->id, array_merge(['core' => $core], $overrides));
        Storage::disk('local')->put($key, $contents);
        Storage::disk('local')->put($key.'.manifest.json', (string) file_get_contents($sidecar));

        return [$key, hash('sha256', $contents)];
    }

    /** Vuelve a firmar el objeto ya publicado de un modelo (para simular un manifiesto sin firma, de otra clave o de otra empresa). */
    protected function resignPublished(int $modelId, array $overrides = [], ?string $secret = null, ?string $keyId = null, bool $unsigned = false): void
    {
        $row = DB::table('model_registry')->where('id', $modelId)->first(['published_object_key', 'tenant_id']);
        $key = (string) $row->published_object_key;
        $file = $this->tempDir('resign').'/'.basename($key);
        file_put_contents($file, Storage::disk('local')->get($key));
        $sidecar = $this->signArtifactFile($file, (int) $row->tenant_id, array_merge(['core' => 'customer'], $overrides), $secret, $keyId, $unsigned);
        Storage::disk('local')->put($key.'.manifest.json', (string) file_get_contents($sidecar));
    }

    /**
     * Un candidato PUBLICADO por la CLI, con el contrato completo (published_by/env/at/object_key, evento de la CLI) y su artefacto firmado en el
     * almacén de objetos: debe validarse y aprobarse antes de servirse.
     *
     * @param  array<string, mixed>  $over
     */
    protected function publishedCandidate(Tenant $tenant, User $publisher, array $over = [], string $slot = 'h30:probability', bool $cliEvent = true): int
    {
        $version = $over['version'] ?? 'pub-'.bin2hex(random_bytes(4));
        [$key, $sha] = $this->publishObject($tenant, $version);
        $id = $this->customerModel($tenant, 'candidate', array_merge([
            'version' => $version, 'published_by' => $publisher->id, 'published_env' => 'staging', 'published_at' => now(),
            'published_object_key' => $key, 'artifact_sha256' => $sha, 'core_run_id' => null,
        ], $over), false, $slot);
        if ($cliEvent) {
            DB::table('model_registry_events')->insert(['tenant_id' => $tenant->id, 'model_registry_id' => $id, 'from_status' => null, 'to_status' => 'candidate',
                'actor_user_id' => $publisher->id, 'actor_kind' => 'cli', 'reason' => 'Publicado por la CLI', 'evidence' => json_encode(['env' => 'staging'])]);
        }

        return $id;
    }

    /** Corrida del Customer Core con su trabajo de entrenamiento disparado por `$trigger` (null = programado, sin responsable humano). */
    protected function trainedByRun(Tenant $tenant, ?User $trigger): array
    {
        $runId = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'customer', 'operation' => 'train', 'version' => (int) DB::table('core_runs')->where('tenant_id', $tenant->id)->max('version') + 1,
            'run_type' => 'production', 'status' => 'completed', 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]',
            'triggered_by' => $trigger ? 'user' : 'schedule', 'user_id' => $trigger?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = (int) DB::table('training_jobs')->insertGetId([
            'tenant_id' => $tenant->id, 'core_run_id' => $runId, 'job_type' => 'forecast_pipeline', 'status' => 'succeeded', 'progress' => 100, 'attempts' => 1,
            'triggered_by' => $trigger ? 'user' : 'schedule', 'user_id' => $trigger?->id, 'params' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['core_run_id' => $runId, 'training_job_id' => $jobId];
    }

    /** Informe de calidad de la ejecución (W2-C) con el resultado del gate del módulo. */
    protected function gateReport(Tenant $tenant, int $coreRunId, string $outcome, string $module = 'customer'): int
    {
        return (int) DB::table('data_quality_reports')->insertGetId([
            'tenant_id' => $tenant->id, 'core_run_id' => $coreRunId, 'scope' => 'run', 'summary' => '{}', 'issues' => '[]', 'dataset' => $module,
            'gate_results' => json_encode([$module => ['outcome' => $outcome, 'reasons' => []]]), 'rules_version' => str_repeat('a', 16),
        ]);
    }

    /** Decisión de la política de promoción (W3) pendiente de aprobación humana. */
    protected function promotionDecision(Tenant $tenant, ?int $modelId, string $decision = 'promote', string $core = 'customer'): int
    {
        return (int) DB::table('model_promotion_decisions')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => $core, 'scope_key' => 'horizon:30', 'policy' => 'v25', 'policy_version' => str_repeat('b', 64),
            'candidate_family' => 'logistic', 'candidate_model_registry_id' => $modelId, 'decision' => $decision, 'is_effective' => false,
            'gates' => '{}', 'metrics' => '{}', 'reason' => 'Decisión de prueba', 'decided_at' => now(), 'created_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> eventos del modelo, del más antiguo al más reciente */
    protected function eventsOf(int $modelId): array
    {
        return DB::table('model_registry_events')->where('model_registry_id', $modelId)->orderBy('id')->get()
            ->map(fn ($e) => [
                'from' => $e->from_status, 'to' => $e->to_status, 'actor' => $e->actor_user_id, 'kind' => $e->actor_kind,
                'reason' => $e->reason, 'evidence' => json_decode((string) $e->evidence, true),
            ])->all();
    }

    protected function statusOf(int $modelId): string
    {
        return (string) DB::table('model_registry')->where('id', $modelId)->value('status');
    }
}
