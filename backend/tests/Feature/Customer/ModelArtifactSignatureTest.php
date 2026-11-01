<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\Tenant;
use App\Services\Forecasting\ModelArtifactVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\SignsModelArtifacts;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * Firma Ed25519 de los artefactos al revertir modelos (ADR-V25-20; defecto HIGH de modelos_aws: con solo el SHA-256 de la base de
 * datos, quien escribiera una fila y un archivo ejecutaba código en el motor). El verificador del backend comprueba la firma con
 * las claves PÚBLICAS de confianza (sodium_crypto_sign_verify_detached) además del SHA-256; sin firma o con firma inválida se
 * rechaza, y el indicador de legado nunca acepta firmas inválidas.
 */
final class ModelArtifactSignatureTest extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;
    use SignsModelArtifacts;

    /** @var list<string> */
    private array $files = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->trustNewSigningKey();
        $this->dir = sys_get_temp_dir().'/firma-'.bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
        config(['engine.artifacts_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function verifier(): ModelArtifactVerifier
    {
        return app(ModelArtifactVerifier::class);
    }

    /** Escribe un artefacto en la ruta relativa del motor y devuelve [ruta absoluta, sha256]. @return array{string, string} */
    private function artifact(int $tenantId, string $content = 'modelo-serializado', string $name = 'h30_probability_logistic.joblib'): array
    {
        $dir = "{$this->dir}/{$tenantId}/customer/run-1";
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.$name, $content);

        return [$dir.'/'.$name, hash('sha256', $content)];
    }

    /** @param array<string, mixed> $over */
    private function model(Tenant $tenant, string $slot, string $status, string $version, string $file, string $sha, array $over = []): int
    {
        $stored = ltrim(substr($file, strlen($this->dir)), '/');

        return (int) DB::table('model_registry')->insertGetId(array_merge([
            'tenant_id' => $tenant->id, 'version' => $version, 'model_family' => 'customer_'.str_replace(':', '_', $slot), 'strategy_level' => 'B', 'scope' => 'tenant',
            'status' => $status, 'core' => 'customer', 'role' => 'primary', 'artifact_path' => $stored, 'artifact_sha256' => $sha,
            'hyperparameters' => json_encode(['slot' => $slot, 'kind' => 'complex', 'ranking_source' => 'popularity']),
            'artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'libraries' => ['lightgbm' => '4.7.0']]),
            'retired_at' => $status === 'retired' ? now()->subDay() : null, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function rollback(int $modelId, $user, Tenant $tenant)
    {
        return $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $modelId]);
    }

    // ───────────────────────── verificación del archivo ─────────────────────────

    public function test_a_valid_signature_from_a_trusted_key_is_verified(): void
    {
        [$file, $sha] = $this->artifact(7);
        $this->signArtifactFile($file, 7);
        $this->assertSame(['ok' => true, 'status' => 'signed', 'reason' => null, 'key_id' => 'test-ephemeral'], $this->verifier()->inspect($file, 7, $sha));
        // El SHA-256 del registro se compara sin distinguir mayúsculas.
        $this->assertTrue($this->verifier()->inspect($file, 7, strtoupper($sha))['ok']);
    }

    public function test_a_signature_produced_by_the_python_engine_is_verified_by_sodium(): void
    {
        // Compatibilidad entre lenguajes: el sobre lo generó predictily_engine.platform.signing (cryptography), no esta suite.
        $fixtures = __DIR__.'/../../Fixtures/engine-signed';
        $file = $this->dir.'/4242/customer/run-7/h30_probability_logistic.joblib';
        mkdir(dirname($file), 0777, true);
        copy($fixtures.'/h30_probability_logistic.joblib', $file);
        copy($fixtures.'/h30_probability_logistic.joblib.manifest.json', $file.'.manifest.json');
        $sha = hash_file('sha256', $file);

        config(['engine.artifact_signing.trusted_public_keys' => trim((string) file_get_contents($fixtures.'/trusted_public_keys.txt'))]);
        $this->assertSame(['ok' => true, 'status' => 'signed', 'reason' => null, 'key_id' => 'fixture-2026'], $this->verifier()->inspect($file, 4242, $sha));
        $this->assertSame(ModelArtifactVerifier::REASON_TENANT_MISMATCH, $this->verifier()->inspect($file, 4243, $sha)['reason'], 'el manifiesto liga la empresa');
        config(['engine.artifact_signing.trusted_public_keys' => 'otra-clave:'.base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
        $this->assertSame(ModelArtifactVerifier::REASON_UNTRUSTED_KEY, $this->verifier()->inspect($file, 4242, $sha)['reason']);
    }

    public function test_an_unsigned_artifact_is_rejected_by_default_and_accepted_only_with_the_explicit_flag(): void
    {
        [$file, $sha] = $this->artifact(7);                                       // sin manifiesto (legado)
        $this->assertSame(['ok' => false, 'status' => 'rejected', 'reason' => ModelArtifactVerifier::REASON_UNSIGNED, 'key_id' => null], $this->verifier()->inspect($file, 7, $sha));

        $this->signArtifactFile($file, 7, unsigned: true);                         // manifiesto «sin firma» del motor
        $this->assertSame(ModelArtifactVerifier::REASON_UNSIGNED, $this->verifier()->inspect($file, 7, $sha)['reason']);

        config(['engine.artifact_signing.allow_unsigned' => true]);
        $this->assertSame(['ok' => true, 'status' => 'unsigned_allowed', 'reason' => null, 'key_id' => null], $this->verifier()->inspect($file, 7, $sha));
        $this->assertSame(ModelArtifactVerifier::REASON_SHA256_MISMATCH, $this->verifier()->inspect($file, 7, str_repeat('0', 64))['reason'], 'el legado se verifica por SHA-256');
    }

    public function test_the_flag_only_accepts_the_literal_truth_values(): void
    {
        foreach ([[true, true], ['true', true], ['1', true], ['yes', true], ['on', true], [false, false], ['false', false], ['0', false], ['', false], ['quizá', false], [null, false]] as [$value, $expected]) {
            config(['engine.artifact_signing.allow_unsigned' => $value]);
            $this->assertSame($expected, $this->verifier()->allowsUnsigned(), var_export($value, true));
        }
    }

    public function test_an_invalid_signature_is_rejected_even_with_the_legacy_flag(): void
    {
        [$file, $sha] = $this->artifact(7);
        $attacker = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->signArtifactFile($file, 7, secret: $attacker);                      // declara la clave de confianza pero firma el atacante
        config(['engine.artifact_signing.allow_unsigned' => true]);
        $this->assertSame(ModelArtifactVerifier::REASON_SIGNATURE_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);

        // Firma buena con un bit alterado.
        $sidecar = $this->signArtifactFile($file, 7);
        $envelope = json_decode((string) file_get_contents($sidecar), true);
        $raw = base64_decode($envelope['signature']);
        $raw[0] = chr(ord($raw[0]) ^ 1);
        $envelope['signature'] = base64_encode($raw);
        file_put_contents($sidecar, json_encode($envelope));
        $this->assertSame(ModelArtifactVerifier::REASON_SIGNATURE_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);

        // Una firma de longitud incorrecta no rompe: se rechaza.
        $envelope['signature'] = base64_encode('corta');
        file_put_contents($sidecar, json_encode($envelope));
        $this->assertSame(ModelArtifactVerifier::REASON_SIGNATURE_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);
    }

    public function test_an_unknown_key_is_rejected_even_with_the_legacy_flag(): void
    {
        [$file, $sha] = $this->artifact(7);
        $pair = sodium_crypto_sign_keypair();
        $this->signArtifactFile($file, 7, secret: sodium_crypto_sign_secretkey($pair), keyId: 'clave-desconocida');   // firma VÁLIDA de otra clave
        config(['engine.artifact_signing.allow_unsigned' => true]);
        $this->assertSame(ModelArtifactVerifier::REASON_UNTRUSTED_KEY, $this->verifier()->inspect($file, 7, $sha)['reason']);
        config(['engine.artifact_signing.trusted_public_keys' => '']);
        $this->signArtifactFile($file, 7);
        $this->assertSame(ModelArtifactVerifier::REASON_UNTRUSTED_KEY, $this->verifier()->inspect($file, 7, $sha)['reason'], 'sin claves de confianza no se confía en nada');
    }

    public function test_a_manifest_that_does_not_describe_this_file_or_this_company_is_rejected(): void
    {
        [$file, $sha] = $this->artifact(7);
        $this->signArtifactFile($file, 8);                                          // firmado por la clave buena, pero para la empresa 8
        $this->assertSame(ModelArtifactVerifier::REASON_TENANT_MISMATCH, $this->verifier()->inspect($file, 7, $sha)['reason']);

        $this->signArtifactFile($file, 7, ['artifact' => ['sha256' => hash('sha256', 'otro')]]);
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_MISMATCH, $this->verifier()->inspect($file, 7, $sha)['reason']);

        $this->signArtifactFile($file, 7, ['artifact' => ['size_bytes' => 1]]);
        $this->assertSame(ModelArtifactVerifier::REASON_SIZE_MISMATCH, $this->verifier()->inspect($file, 7, $sha)['reason']);

        $this->signArtifactFile($file, 7, ['artifact' => ['file_name' => 'otro.joblib']]);
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_MISMATCH, $this->verifier()->inspect($file, 7, $sha)['reason']);

        $this->signArtifactFile($file, 7, ['key_id' => 'otra']);                      // el manifiesto firmado declara otra clave
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);

        $this->signArtifactFile($file, 7, ['schema' => 'otro/1']);
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);

        // El tenant_id debe ser un entero JSON: «7» como texto no vale.
        [$other, $otherSha] = $this->artifact(7, 'otro-contenido', 'otro.joblib');
        $sidecar = $this->signArtifactFile($other, 7);
        $envelope = json_decode((string) file_get_contents($sidecar), true);
        $manifest = json_decode($envelope['manifest'], true);
        $manifest['tenant_id'] = '7';
        $envelope['manifest'] = $this->canonicalJson($manifest);
        $envelope['signature'] = base64_encode(sodium_crypto_sign_detached($envelope['manifest'], $this->signingSecret));
        file_put_contents($sidecar, json_encode($envelope));
        $this->assertSame(ModelArtifactVerifier::REASON_TENANT_MISMATCH, $this->verifier()->inspect($other, 7, $otherSha)['reason']);
    }

    public function test_the_manifest_binds_the_core_like_the_engine_does(): void
    {
        [$file, $sha] = $this->artifact(7);
        $this->signArtifactFile($file, 7, ['core' => 'demand']);                    // firmado y de la empresa, pero de otro core
        $this->assertSame(ModelArtifactVerifier::REASON_CORE_MISMATCH, $this->verifier()->inspect($file, 7, $sha, 'customer')['reason']);
        $this->assertTrue($this->verifier()->inspect($file, 7, $sha, 'demand')['ok']);
        $this->assertTrue($this->verifier()->inspect($file, 7, $sha)['ok'], 'sin core indicado no se compara (compatibilidad de la API)');

        $this->signArtifactFile($file, 7, ['core' => 'customer']);
        $this->assertTrue($this->verifier()->inspect($file, 7, $sha, 'customer')['ok']);
        $this->assertSame(ModelArtifactVerifier::REASON_CORE_MISMATCH, $this->verifier()->inspect($file, 7, $sha, 'demand')['reason']);
    }

    public function test_a_tampered_file_is_rejected_by_the_sha256_before_looking_at_the_signature(): void
    {
        [$file, $sha] = $this->artifact(7);
        $this->signArtifactFile($file, 7);
        file_put_contents($file, 'modelo-alterado');
        $this->assertSame(ModelArtifactVerifier::REASON_SHA256_MISMATCH, $this->verifier()->inspect($file, 7, $sha)['reason']);
        // El atacante también actualiza la fila: el manifiesto firmado delata la alteración.
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_MISMATCH, $this->verifier()->inspect($file, 7, hash('sha256', 'modelo-alterado'))['reason']);
    }

    public function test_a_corrupt_or_oversized_manifest_is_never_treated_as_unsigned(): void
    {
        [$file, $sha] = $this->artifact(7);
        config(['engine.artifact_signing.allow_unsigned' => true]);
        foreach (['{no es json', '[]', '{"format":"otro"}', '{"format":"predictily-signed-manifest/1","manifest":3}', "\xff\xfe\x00"] as $body) {
            file_put_contents($file.'.manifest.json', $body);
            $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason'], bin2hex(substr($body, 0, 4)));
        }
        file_put_contents($file.'.manifest.json', str_repeat(' ', 262145));
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);
        // Quitar la firma dejando el id de clave tampoco equivale a «sin firma».
        $sidecar = $this->signArtifactFile($file, 7);
        $envelope = json_decode((string) file_get_contents($sidecar), true);
        $envelope['signature'] = null;
        file_put_contents($sidecar, json_encode($envelope));
        $this->assertSame(ModelArtifactVerifier::REASON_MANIFEST_INVALID, $this->verifier()->inspect($file, 7, $sha)['reason']);
    }

    public function test_trusted_keys_are_parsed_strictly_and_fail_closed(): void
    {
        $a = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $b = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $urlsafe = rtrim(strtr(base64_encode($a), '+/', '-_'), '=');
        config(['engine.artifact_signing.trusted_public_keys' => 'k1:'.base64_encode($a).", k2:{$urlsafe};k3:".base64_encode($b)."\nk4:".base64_encode($b)]);
        $keys = $this->verifier()->trustedKeys();
        $this->assertSame(['k1', 'k2', 'k3', 'k4'], array_keys($keys));
        $this->assertSame([$a, $a, $b, $b], array_values($keys));

        config(['engine.artifact_signing.trusted_public_keys' => 'k1:'.base64_encode($a).',k1:'.base64_encode($b).',mala,k2:no-es-base64!,k3:'.base64_encode('corta').',:'.base64_encode($a).',mal/id:'.base64_encode($a)]);
        $this->assertSame([], $this->verifier()->trustedKeys(), 'repetida con valores distintos, malformada o de longitud incorrecta: no se confía');

        config(['engine.artifact_signing.trusted_public_keys' => ['k1' => base64_encode($a), 'k2' => 'invalida']]);
        $this->assertSame(['k1' => $a], $this->verifier()->trustedKeys());
        config(['engine.artifact_signing.trusted_public_keys' => null]);
        $this->assertSame([], $this->verifier()->trustedKeys());
    }

    public function test_relative_paths_cannot_leave_the_artifacts_directory(): void
    {
        $outside = dirname($this->dir).'/fuera-'.bin2hex(random_bytes(4)).'.joblib';
        file_put_contents($outside, 'x');
        $this->files[] = $outside;
        $this->assertNull($this->verifier()->locate('../'.basename($outside)));
        $this->assertNull($this->verifier()->locate('7/../../'.basename($outside)));
        $this->assertNull($this->verifier()->locate("7/\0x"));
        // Un enlace simbólico dentro del directorio que apunta fuera no se sigue.
        @mkdir($this->dir.'/7', 0777, true);
        symlink($outside, $this->dir.'/7/enlace.joblib');
        $this->files[] = $this->dir.'/7/enlace.joblib';
        $this->assertNull($this->verifier()->locate('7/enlace.joblib'));
        [$file] = $this->artifact(7);
        $this->assertNotNull($this->verifier()->locate('7/customer/run-1/'.basename($file)));
    }

    // ───────────────────────── reversión (API) ─────────────────────────

    public function test_rollback_to_a_signed_artifact_is_accepted_and_reports_the_signature(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$file, $sha] = $this->artifact($tenant->id);
        $this->signArtifactFile($file, $tenant->id);
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1', $file, $sha);

        $res = $this->rollback($old, $user, $tenant)->assertOk();
        $this->assertTrue($res->json('data.artifact_sha256_verified'));
        $this->assertSame(['verified', 1, 1, 1, 0], [
            $res->json('data.artifact_verification.status'), $res->json('data.artifact_verification.registered'), $res->json('data.artifact_verification.checked'),
            $res->json('data.artifact_verification.signed'), $res->json('data.artifact_verification.unsigned_allowed'),
        ]);
        $this->assertStringContainsString('firma Ed25519 válida en 1', $res->json('data.artifact_verification.message'));
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'production']);
    }

    public function test_rollback_to_an_unsigned_artifact_is_rejected_and_nothing_changes(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$file, $sha] = $this->artifact($tenant->id);                               // visible, con SHA-256 correcto, sin firma
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1-legado', $file, $sha);
        $current = $this->model($tenant, 'h30:probability', 'production', 'v2', ...$this->signedPair($tenant));

        $this->rollback($old, $user, $tenant)->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_unsigned')
            ->assertJsonPath('context.model_id', $old)->assertJsonPath('context.reason', 'artifact_unsigned');
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'retired']);
        $this->assertDatabaseHas('model_registry', ['id' => $current, 'status' => 'production']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'models.customer_rolled_back']);

        // Solo con el indicador explícito de legado, y se informa.
        config(['engine.artifact_signing.allow_unsigned' => true]);
        $res = $this->rollback($old, $user, $tenant)->assertOk();
        $this->assertSame([1, 0, 1], [$res->json('data.artifact_verification.unsigned_allowed'), $res->json('data.artifact_verification.signed'), $res->json('data.artifact_verification.checked')]);
        $this->assertStringContainsString('ENGINE_ALLOW_UNSIGNED_ARTIFACTS', $res->json('data.artifact_verification.message'));
    }

    /** @return array{string, string} un artefacto firmado de la empresa y su SHA-256 */
    private function signedPair(Tenant $tenant): array
    {
        [$file, $sha] = $this->artifact($tenant->id, 'otro-modelo-firmado', 'firmado.joblib');
        $this->signArtifactFile($file, $tenant->id);

        return [$file, $sha];
    }

    public function test_rollback_to_an_artifact_with_an_invalid_signature_is_rejected_even_with_the_flag(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$file, $sha] = $this->artifact($tenant->id);
        $this->signArtifactFile($file, $tenant->id, secret: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1-forjado', $file, $sha);
        config(['engine.artifact_signing.allow_unsigned' => true]);

        $this->rollback($old, $user, $tenant)->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_signature_invalid')
            ->assertJsonPath('context.reason', 'artifact_signature_invalid');
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'retired']);
    }

    public function test_rollback_rejects_a_signed_artifact_of_another_company(): void
    {
        [$a, $userA] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        [$file, $sha] = $this->artifact($a->id);
        $this->signArtifactFile($file, $b->id);                                      // firma legítima, pero emitida para la empresa B
        $old = $this->model($a, 'h30:probability', 'retired', 'v1-ajeno', $file, $sha);

        $this->rollback($old, $userA, $a)->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_signature_invalid')
            ->assertJsonPath('context.reason', 'artifact_tenant_mismatch');
    }

    public function test_rollback_rejects_a_signed_artifact_of_another_core(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$file, $sha] = $this->artifact($tenant->id);
        $this->signArtifactFile($file, $tenant->id, ['core' => 'demand']);           // firma legítima de la empresa, pero de un modelo de demanda
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1-otro-core', $file, $sha);   // fila del Customer Core

        $this->rollback($old, $user, $tenant)->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_signature_invalid')
            ->assertJsonPath('context.reason', 'artifact_core_mismatch');
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'retired']);
    }

    public function test_the_unsigned_mark_left_by_the_engine_blocks_a_rollback_even_when_the_file_is_not_visible(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $compat = json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'libraries' => ['lightgbm' => '4.7.0'], 'signature' => ['status' => 'unsigned', 'algorithm' => null, 'key_id' => null, 'manifest_sha256' => str_repeat('a', 64)]]);
        $old = (int) DB::table('model_registry')->insertGetId([
            'tenant_id' => $tenant->id, 'version' => 'v1-no-visible', 'model_family' => 'customer_h30_probability', 'strategy_level' => 'B', 'scope' => 'tenant', 'status' => 'retired',
            'core' => 'customer', 'role' => 'primary', 'artifact_path' => $tenant->id.'/customer/run-9/no-visible.joblib', 'artifact_sha256' => str_repeat('b', 64),
            'hyperparameters' => json_encode(['slot' => 'h30:probability', 'kind' => 'complex']), 'artifact_compat' => $compat, 'retired_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->rollback($old, $user, $tenant)->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_unsigned');
        config(['engine.artifact_signing.allow_unsigned' => true]);
        $res = $this->rollback($old, $user, $tenant)->assertOk();
        $this->assertNull($res->json('data.artifact_sha256_verified'), 'no visible: se informa, lo verifica el motor al cargar');
    }

    public function test_an_artifact_that_is_not_visible_and_has_no_mark_keeps_being_reported_not_verified(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1', $this->dir.'/9/customer/run-1/no-existe.joblib', str_repeat('c', 64));
        $res = $this->rollback($old, $user, $tenant)->assertOk();
        $this->assertSame(['not_verifiable', 'artifact_files_not_found', 0, 0], [
            $res->json('data.artifact_verification.status'), $res->json('data.artifact_verification.reason'),
            $res->json('data.artifact_verification.signed'), $res->json('data.artifact_verification.checked'),
        ]);
    }
}
