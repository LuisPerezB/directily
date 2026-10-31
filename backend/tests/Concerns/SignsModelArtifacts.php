<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Firma de artefactos de prueba con el MISMO formato que el motor (sobre JSON con el manifiesto canónico como texto y firma
 * Ed25519 sobre sus bytes; ADR-V25-20). Las claves son efímeras: se generan en cada prueba y solo se confía en su parte pública.
 */
trait SignsModelArtifacts
{
    private string $signingSecret = '';

    private string $signingKeyId = 'test-ephemeral';

    /** Genera un par Ed25519 efímero y lo declara de confianza en la configuración del backend (sin activar el indicador de legado). */
    protected function trustNewSigningKey(string $keyId = 'test-ephemeral'): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->signingSecret = sodium_crypto_sign_secretkey($pair);
        $this->signingKeyId = $keyId;
        config([
            'engine.artifact_signing.trusted_public_keys' => $keyId.':'.base64_encode(sodium_crypto_sign_publickey($pair)),
            'engine.artifact_signing.allow_unsigned' => false,
        ]);
    }

    /**
     * Escribe `<archivo>.manifest.json` junto al artefacto. `$overrides` sustituye campos del manifiesto (claves de primer nivel o
     * `artifact`); `$secret`/`$keyId` permiten firmar con otra clave (atacante). Devuelve la ruta del manifiesto.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function signArtifactFile(string $file, int $tenantId, array $overrides = [], ?string $secret = null, ?string $keyId = null, bool $unsigned = false): string
    {
        $keyId ??= $this->signingKeyId;
        $contents = (string) file_get_contents($file);
        $manifest = array_replace_recursive([
            'schema' => 'predictily-artifact-manifest/1',
            'artifact' => ['file_name' => basename($file), 'format' => 'joblib', 'sha256' => hash('sha256', $contents), 'size_bytes' => strlen($contents)],
            'tenant_id' => $tenantId, 'core' => 'customer', 'run_id' => 1, 'name' => 'prueba', 'created_at' => '2026-10-31T00:00:00Z',
            'libraries' => ['numpy' => '2.5.4'], 'feature_schema_version' => 'cust-fs-2026.10-v1', 'data_fingerprint' => str_repeat('a', 64),
            'key_id' => $unsigned ? null : $keyId,
        ], $overrides);
        $payload = $this->canonicalJson($manifest);
        $envelope = [
            'format' => 'predictily-signed-manifest/1',
            'algorithm' => $unsigned ? null : 'ed25519',
            'key_id' => $unsigned ? null : $keyId,
            'manifest' => $payload,
            'signature' => $unsigned ? null : base64_encode(sodium_crypto_sign_detached($payload, $secret ?? $this->signingSecret)),
        ];
        $sidecar = $file.'.manifest.json';
        file_put_contents($sidecar, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $sidecar;
    }

    /** JSON canónico como el del motor: claves ordenadas, separadores compactos, ASCII. */
    protected function canonicalJson(array $data): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($sort, $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return (string) json_encode($sort($data), JSON_UNESCAPED_SLASHES);
    }
}
