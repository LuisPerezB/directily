"""Serving del motor (W4-B): carga verificada de objetos publicados y caché con empresa + versión + esquema. Pruebas NEGATIVAS entre empresas en almacén y caché."""

from __future__ import annotations

import os

import pytest

from mlops_helpers import UUID_A, UUID_B, publish_object
from predictily_engine.customer.service import _artifact_compat, load_artifact
from predictily_engine.mlops import serving
from predictily_engine.mlops.serving import ARTIFACT_CACHE, ArtifactCache, CacheKey
from predictily_engine.platform import signing
from predictily_engine.platform.object_store import LocalObjectStore, ObjectStoreError, manifest_key


@pytest.fixture()
def store(tmp_path, monkeypatch):
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ROOT", str(tmp_path / "store"))
    ARTIFACT_CACHE.clear()
    yield LocalObjectStore(tmp_path / "store")
    ARTIFACT_CACHE.clear()


class SpyStore(LocalObjectStore):
    """Un almacén que registra a qué claves se accedió."""

    def __init__(self, root):
        super().__init__(root)
        self.reads: list[str] = []

    def get(self, key, *, max_bytes=None):
        self.reads.append(key)
        return super().get(key, max_bytes=max_bytes)


def test_a_published_object_is_verified_from_the_store_and_loaded(store, tmp_path):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    verification = serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store)
    assert verification.ok and verification.artifact.status == "signed" and verification.artifact.key_id == "test-ephemeral"
    obj, why = load_artifact(row, 1)
    assert why is None and obj["tenant"] == 1


def test_a_key_of_another_company_is_rejected_before_the_store_is_touched(tmp_path):
    spy = SpyStore(tmp_path / "store")
    row = publish_object(spy, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    foreign = {**row, "tenant_uuid": UUID_B}                  # el trabajo es de la empresa B y la clave es de la A
    spy.reads.clear()
    verification = serving.verify_row_artifact(foreign, tenant_id=2, core="customer", store=spy)
    assert verification.reason == "artifact_object_key_invalid" and spy.reads == []


@pytest.mark.parametrize("key", ["../../etc/passwd", "tenants/%s/bronze/x.csv", "tenants/%s/models//a", "/tenants/%s/models/a", "tenants/%s/models/a b"])
def test_unsafe_or_foreign_keys_never_reach_the_store(tmp_path, key):
    spy = SpyStore(tmp_path / "store")
    row = {**publish_object(spy, tmp_path, tenant_id=1, tenant_uuid=UUID_A), "published_object_key": key % UUID_A if "%s" in key else key}
    spy.reads.clear()
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=spy).reason == "artifact_object_key_invalid"
    assert spy.reads == []


def test_a_manifest_signed_for_another_company_is_rejected(store, tmp_path):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A, signed_for_tenant=2)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_tenant_mismatch"
    assert load_artifact(row, 1) == (None, "artifact_tenant_mismatch")


def test_altered_missing_or_unsigned_objects_are_rejected_without_deserializing(store, tmp_path, monkeypatch):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    key = row["published_object_key"]
    original = store.get(key)
    store.put(key, original + b"x", overwrite=True)                                         # alterado
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_sha256_mismatch"
    store.put(key, original, overwrite=True)
    store.delete(manifest_key(key))                                                        # sin manifiesto = sin firma (el indicador de legado está apagado)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_unsigned"
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "true")                        # el indicador es para legados locales: un publicado SIEMPRE va firmado
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_unsigned"
    monkeypatch.delenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS")
    store.delete(key)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_missing"
    assert serving.verify_row_artifact({**row, "artifact_sha256": None}, tenant_id=1, core="customer", store=store).reason == "artifact_sha256_mismatch"


def test_an_untrusted_signing_key_is_rejected(store, tmp_path, monkeypatch):
    other_id, other_private, _ = signing.generate_keypair("otra-clave")
    other = signing.SigningConfig(key_id=other_id, private_key=signing._read_private_key(other_private))
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A, config=other)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_untrusted_key"


def test_a_store_failure_is_a_rejection_not_a_crash(tmp_path):
    class Broken(LocalObjectStore):
        def get(self, key, *, max_bytes=None):
            raise ObjectStoreError("falló la lectura en el almacén S3 (EndpointConnectionError)")

    broken = Broken(tmp_path / "store")
    row = publish_object(LocalObjectStore(tmp_path / "store"), tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=broken).reason == "artifact_store_unavailable"


def test_verification_leaves_no_temporary_files(store, tmp_path, monkeypatch):
    scratch = tmp_path / "tmp"
    scratch.mkdir()
    monkeypatch.setattr("tempfile.tempdir", str(scratch))
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).ok
    assert serving.verify_row_artifact({**row, "artifact_sha256": "0" * 64}, tenant_id=1, core="customer", store=store).reason == "artifact_sha256_mismatch"
    assert list(scratch.iterdir()) == []


# ───────────────────────────── caché ─────────────────────────────

def test_the_cache_key_carries_company_version_schema_and_content():
    key = CacheKey(1, "customer", "t1-customer-v3-h30-probability-logistic", "cust-fs-2026.10-v1", "a" * 64)
    assert (key.tenant_id, key.model_version, key.feature_schema_version) == (1, "t1-customer-v3-h30-probability-logistic", "cust-fs-2026.10-v1")
    assert key != CacheKey(2, "customer", key.model_version, key.feature_schema_version, key.artifact_sha256)       # otra empresa
    assert key != CacheKey(1, "customer", "otra-version", key.feature_schema_version, key.artifact_sha256)          # otra versión
    assert key != CacheKey(1, "customer", key.model_version, "cust-fs-9999", key.artifact_sha256)                    # otro esquema de variables
    assert key != CacheKey(1, "customer", key.model_version, key.feature_schema_version, "b" * 64)                  # otro contenido


def test_two_companies_never_share_cache_entries_even_with_the_same_version_and_content(store, tmp_path):
    ARTIFACT_CACHE.clear()
    a = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A, version="v-igual", name="h7_probability_reference", obj={"dueño": "A"})
    b = publish_object(store, tmp_path, tenant_id=2, tenant_uuid=UUID_B, version="v-igual", name="h7_probability_reference", obj={"dueño": "B"})
    assert load_artifact(a, 1)[0] == {"dueño": "A"}
    assert len(ARTIFACT_CACHE) == 1 and {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {1}
    assert load_artifact(b, 2)[0] == {"dueño": "B"}                                       # no recibe lo cacheado por A
    assert {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {1, 2}
    # un trabajo de B con la fila de A (misma versión): nunca obtiene el objeto de A, ni del almacén ni de la caché
    assert load_artifact({**a, "tenant_id": 2, "tenant_uuid": UUID_B}, 2)[1] in ("artifact_object_key_invalid",)
    assert load_artifact(a, 1)[0] == {"dueño": "A"}
    removed = ARTIFACT_CACHE.invalidate_tenant(1)
    assert removed == 1 and {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {2}            # invalidar a A no toca a B


def test_a_cache_hit_skips_deserialization_but_verification_is_always_repeated(store, tmp_path, monkeypatch):
    calls = []
    real = signing.deserialize

    def counting(verified):
        calls.append(verified.sha256)
        return real(verified)

    monkeypatch.setattr(signing, "deserialize", counting)
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    first, _ = load_artifact(row, 1)
    second, _ = load_artifact(row, 1)
    assert first is second and len(calls) == 1                                           # el segundo no deserializa
    key = row["published_object_key"]
    store.put(key, store.get(key) + b"x", overwrite=True)                                # alterado DESPUÉS de cachear
    assert load_artifact(row, 1) == (None, "artifact_sha256_mismatch")                    # la verificación se repite siempre: la caché no lo oculta
    assert len(calls) == 1


def test_retiring_a_trusted_key_takes_effect_even_for_cached_artifacts(store, tmp_path, monkeypatch):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    assert load_artifact(row, 1)[1] is None and len(ARTIFACT_CACHE) == 1
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", "otra:" + signing.generate_keypair("otra")[2])      # revocación: la pública ya no es de confianza
    assert load_artifact(row, 1) == (None, "artifact_untrusted_key")


def test_a_different_feature_schema_does_not_reuse_a_cached_object(store, tmp_path):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    assert load_artifact(row, 1)[1] is None
    stale = {**row, "artifact_compat": {**_artifact_compat(), "feature_schema_version": "cust-fs-viejo"}}
    assert load_artifact(stale, 1) == (None, "feature_schema_incompatible")               # el esquema decide antes de la caché


def test_the_cache_is_bounded_and_can_be_disabled():
    cache = ArtifactCache(max_entries=2)
    keys = [CacheKey(1, "customer", f"v{i}", "s", "a" * 64) for i in range(3)]
    for i, k in enumerate(keys):
        cache.put(k, i)
    assert len(cache) == 2 and cache.get(keys[0]) == (False, None) and cache.get(keys[2]) == (True, 2)
    off = ArtifactCache(max_entries=0)
    off.put(keys[0], 1)
    assert len(off) == 0


def test_a_cache_key_that_does_not_match_the_verified_bytes_is_refused(store, tmp_path):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    verified = serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).artifact
    with pytest.raises(ValueError):
        serving.deserialize_cached(CacheKey(1, "customer", row["version"], "s", "0" * 64), verified)


def test_the_cache_is_also_bounded_by_bytes_and_frees_what_it_evicts():
    cache = ArtifactCache(max_entries=10, max_bytes=100)
    keys = [CacheKey(1, "customer", f"v{i}", "s", "a" * 64) for i in range(4)]
    cache.put(keys[0], "a", 40)
    cache.put(keys[1], "b", 40)
    assert cache.size_bytes == 80 and len(cache) == 2
    cache.put(keys[2], "c", 40)                                                                # 120 > 100: expulsa la más antigua
    assert len(cache) == 2 and cache.size_bytes == 80 and cache.get(keys[0]) == (False, None)
    cache.put(keys[3], "enorme", 101)                                                          # más grande que todo el tope: no se cachea
    assert cache.get(keys[3]) == (False, None) and cache.size_bytes == 80
    cache.put(keys[1], "b2", 10)                                                               # reemplazar una entrada ajusta los bytes
    assert cache.size_bytes == 50 and cache.get(keys[1]) == (True, "b2")
    assert cache.invalidate_tenant(1) == 2 and cache.size_bytes == 0 and len(cache) == 0


def test_the_cache_validates_its_environment(monkeypatch):
    assert serving.validate_cache_settings() is None
    for name, value, fragment in (("ENGINE_ARTIFACT_CACHE_ENTRIES", "-3", "entre 0 y 1024"), ("ENGINE_ARTIFACT_CACHE_ENTRIES", "mucho", "enteros"),
                                  ("ENGINE_ARTIFACT_CACHE_MAX_MB", "0", "entre 1 y"), ("ENGINE_ARTIFACT_CACHE_MAX_MB", "999999", "entre 1 y")):
        monkeypatch.setenv(name, value)
        assert fragment in serving.validate_cache_settings()
        monkeypatch.delenv(name)
    monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_ENTRIES", "0")                                   # 0 es válido: desactiva la caché
    assert serving.validate_cache_settings() is None


def test_the_cache_size_comes_from_the_environment(monkeypatch):
    monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_ENTRIES", "1")
    cache = ArtifactCache()
    assert cache.max_entries == 1
    monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_ENTRIES", "0")
    assert cache.max_entries == 0
    assert os.environ.get("ENGINE_ALLOW_UNSIGNED_ARTIFACTS") is None


# ───────────────────────────── fallos del almacén que NO tumban la corrida (revisión M2) ─────────────────────────────

def _no_server_paths(texts, *paths):
    blob = " ".join(str(t) for t in texts)
    return not any(str(p) in blob for p in paths) and "/private/" not in blob and "/var/folders" not in blob


def test_a_symlink_out_of_the_store_root_degrades_the_model_with_a_stable_reason(store, tmp_path, caplog):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    outside = tmp_path / "fuera"
    outside.mkdir()
    folder = (store.root / row["published_object_key"]).parent
    (outside / folder.name).mkdir()
    for f in folder.iterdir():                                                                 # el contenido real existe, pero FUERA de la raíz
        (outside / folder.name / f.name).write_bytes(f.read_bytes())
    import shutil

    shutil.rmtree(folder)
    folder.symlink_to(outside / folder.name)
    caplog.set_level("DEBUG")
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store).reason == "artifact_object_key_invalid"
    assert load_artifact(row, 1) == (None, "artifact_object_key_invalid")
    assert _no_server_paths([caplog.text], tmp_path, store.root)


def test_a_parent_that_is_a_file_is_a_missing_object_not_a_crash(store, tmp_path, caplog):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    import shutil

    folder = (store.root / row["published_object_key"]).parent
    shutil.rmtree(folder)
    folder.write_text("ahora soy un archivo")                                                   # el «directorio» de la versión es un archivo
    caplog.set_level("DEBUG")
    result = serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store)
    assert result.reason in ("artifact_missing", "artifact_store_unavailable")
    assert load_artifact(row, 1)[0] is None and _no_server_paths([caplog.text, result.reason], tmp_path, store.root)


@pytest.mark.skipif(os.geteuid() == 0, reason="root ignora los permisos de archivo")
def test_an_unreadable_object_degrades_the_model_and_leaks_no_server_path(store, tmp_path, caplog):
    row = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    object_file = store.root / row["published_object_key"]
    os.chmod(object_file, 0)
    try:
        caplog.set_level("DEBUG")
        result = serving.verify_row_artifact(row, tenant_id=1, core="customer", store=store)
        assert result.reason == "artifact_store_unavailable"
        obj, why = load_artifact(row, 1)
        assert obj is None and why == "artifact_store_unavailable" and _no_server_paths([caplog.text, why], tmp_path, store.root)
    finally:
        os.chmod(object_file, 0o600)


def test_the_local_driver_reports_filesystem_failures_without_paths(tmp_path):
    from predictily_engine.platform.object_store import ObjectStoreError

    store = LocalObjectStore(tmp_path / "raiz")
    (tmp_path / "raiz").mkdir()
    (tmp_path / "raiz" / "tenants").write_text("soy un archivo")
    key = f"tenants/{UUID_A}/models/customer/v1/a.joblib"
    from predictily_engine.platform.object_store import ObjectNotFound

    with pytest.raises(ObjectNotFound):
        store.get(key)                                                                         # NotADirectoryError → «no existe»
    assert store.exists(key) is False
    with pytest.raises(ObjectStoreError) as caught:
        store.put(key, b"x")                                                                   # no se puede crear el directorio
    assert str(tmp_path) not in str(caught.value) and "almacén local" in str(caught.value)


def test_a_store_that_raises_an_unexpected_os_error_degrades_instead_of_crashing(tmp_path, caplog):
    class Flaky(LocalObjectStore):
        def get(self, key, *, max_bytes=None):
            raise PermissionError(13, "Permission denied", str(tmp_path / "secreto" / "objeto.joblib"))

    flaky = Flaky(tmp_path / "store")
    row = publish_object(LocalObjectStore(tmp_path / "store"), tmp_path, tenant_id=1, tenant_uuid=UUID_A)
    caplog.set_level("DEBUG")
    assert serving.verify_row_artifact(row, tenant_id=1, core="customer", store=flaky).reason == "artifact_store_unavailable"
    assert "secreto" not in caplog.text


def test_get_store_reuses_one_store_and_one_client_until_the_configuration_changes(tmp_path, monkeypatch):
    from predictily_engine.platform import object_store as os_mod

    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "local")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ROOT", str(tmp_path / "a"))
    first = os_mod.get_store()
    assert os_mod.get_store() is first
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ROOT", str(tmp_path / "b"))
    assert os_mod.get_store() is not first
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_BUCKET", "bucket-de-prueba")
    s3 = os_mod.get_store()
    assert isinstance(s3, os_mod.S3ObjectStore) and os_mod.get_store() is s3
    sentinel = object()
    s3._client_obj = sentinel                                                                    # un solo cliente por almacén: no se construye otro por carga
    assert os_mod.get_store()._client() is sentinel


def test_horizon_support_names_the_refusal_honestly():
    from types import SimpleNamespace

    from predictily_engine.customer.service import _predict_horizon_support

    out = SimpleNamespace(horizons=[7, 30], served={7: {"role": "fallback", "calibration_status": "calibrated"}, 30: {"role": "fallback", "calibration_status": "calibrated"}})
    support = _predict_horizon_support([7, 30], out, {}, False, {7: ["probability", "quantity"]})
    assert [r["reason_code"] for r in support["7"]["reasons"]] == ["model_not_approved"] and support["7"]["status"] == "degraded"
    assert "no está aprobado" in support["7"]["reasons"][0]["message"] and "no superó" not in support["7"]["reasons"][0]["message"]
    assert [r["reason_code"] for r in support["30"]["reasons"]] == ["fallback_reference"]           # un respaldo normal conserva su motivo de siempre


def test_a_symlink_inside_the_root_cannot_make_one_company_read_the_bytes_of_another(store, tmp_path):
    """Defensa en profundidad (script de ataque de la revisión): un enlace dentro de la raíz hacia el directorio de OTRA empresa se rechaza al resolver la clave."""
    a = publish_object(store, tmp_path, tenant_id=1, tenant_uuid=UUID_A, version="v-a", name="h7_probability_reference", obj={"dueño": "A"})
    b = publish_object(store, tmp_path, tenant_id=2, tenant_uuid=UUID_B, version="v-b", name="h7_probability_reference", obj={"dueño": "B"})
    link = store.root / "tenants" / UUID_A / "models" / "customer" / "evil-link"
    link.symlink_to((store.root / b["published_object_key"]).parent)                           # el enlace vive dentro de la raíz y apunta a la carpeta de B
    evil = {**a, "published_object_key": f"tenants/{UUID_A}/models/customer/evil-link/{(store.root / b['published_object_key']).name}", "artifact_sha256": b["artifact_sha256"]}
    assert serving.verify_row_artifact(evil, tenant_id=1, core="customer", store=store).reason == "artifact_object_key_invalid"
    assert load_artifact(evil, 1) == (None, "artifact_object_key_invalid")
    assert load_artifact(a, 1)[0] == {"dueño": "A"}                                             # lo legítimo sigue funcionando
