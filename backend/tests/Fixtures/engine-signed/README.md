# Artefacto firmado por el motor (fixture)

Generado con `predictily_engine.platform.signing` (Python, `cryptography`) para comprobar la compatibilidad entre lenguajes: el
backend (PHP, `sodium_crypto_sign_verify_detached`) debe verificar una firma producida por el motor. Lo usa
`backend/tests/Feature/Customer/ModelArtifactSignatureTest.php::test_a_signature_produced_by_the_python_engine_is_verified_by_sodium`.

- `h30_probability_logistic.joblib`: artefacto (joblib) de la empresa 4242, core `customer`, corrida 7.
- `h30_probability_logistic.joblib.manifest.json`: sobre firmado (Ed25519) con la clave `fixture-2026`.
- `trusted_public_keys.txt`: clave PÚBLICA de confianza (`kid:clave`). La clave privada era efímera y no se conserva.

Son datos ficticios sin ningún secreto. Para regenerarlos con una clave nueva (desde `engine/`, con el venv activo):

```python
import hashlib, joblib
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from predictily_engine.platform import signing

out = Path("../backend/tests/Fixtures/engine-signed")
path = out / "h30_probability_logistic.joblib"
joblib.dump({"fixture": "artefacto de prueba firmado por el motor"}, path, compress=3)
data, kid, key = path.read_bytes(), "fixture-2026", Ed25519PrivateKey.generate()
manifest = signing.build_manifest(artifact_name=path.name, sha256=hashlib.sha256(data).hexdigest(), size_bytes=len(data), tenant_id=4242,
                                  core="customer", run_id=7, name="h30_probability_logistic", data_fingerprint="a" * 64,
                                  feature_schema_version="cust-fs-2026.10-v1", libraries={"scikit-learn": "1.9.1", "lightgbm": "4.7.0"}, key_id=kid)
signing.write_manifest(path, manifest, signing.SigningConfig(key_id=kid, private_key=key))
(out / "trusted_public_keys.txt").write_text(f"{kid}:{signing._b64(key.public_key().public_bytes_raw())}\n")
```
