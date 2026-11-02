"""Almacén de objetos (ADR-V25-08): convención de claves compartida con el backend y controlador local. El controlador S3 se prueba en
`test_object_store_s3_emulator.py` (contra un EMULADOR)."""

from __future__ import annotations

import os
import stat

import pytest

from predictily_engine.platform import object_store as os_mod
from predictily_engine.platform.object_store import (
    InvalidObjectKey,
    LocalObjectStore,
    ObjectConflict,
    ObjectNotFound,
    ObjectStoreUnavailable,
    build_store,
    is_tenant_models_key,
    manifest_key,
    model_object_key,
    validate_key,
)

UUID_A = "54b8d9b9-b3f0-41c9-ad7b-ccf48d8e5a7f"
UUID_B = "0f1e2d3c-4b5a-4968-8776-655443322110"


def test_model_keys_are_generated_by_the_server_with_the_tenant_prefix():
    key = model_object_key(UUID_A, "customer", "t7-pub-customer-1a2b3c4d-h30_probability_logistic", "h30_probability_logistic.joblib")
    assert key == f"tenants/{UUID_A}/models/customer/t7-pub-customer-1a2b3c4d-h30_probability_logistic/h30_probability_logistic.joblib"
    assert manifest_key(key) == key + ".manifest.json"
    assert is_tenant_models_key(key, UUID_A) and is_tenant_models_key(manifest_key(key), UUID_A)
    assert not is_tenant_models_key(key, UUID_B)            # la clave de la empresa A no es de la B


@pytest.mark.parametrize("args", [
    (UUID_A, "forecast", "v1", "a.joblib"),                 # core desconocido
    (UUID_A, "customer", "../v1", "a.joblib"), (UUID_A, "customer", "v1/../../x", "a.joblib"), (UUID_A, "customer", "v1", "../a.joblib"),
    (UUID_A, "customer", "v 1", "a.joblib"), (UUID_A, "customer", "", "a.joblib"), (UUID_A, "customer", "v1", "a/b.joblib"),
    (UUID_A, "customer", "v..1", "a.joblib"), (UUID_A, "customer", "-v1", "a.joblib"),
    ("not-a-uuid", "customer", "v1", "a.joblib"), ("../" + UUID_A, "customer", "v1", "a.joblib"), ("", "customer", "v1", "a.joblib"),
])
def test_a_key_is_never_derived_from_unsafe_input(args):
    with pytest.raises(InvalidObjectKey):
        model_object_key(*args)


@pytest.mark.parametrize("key", ["", "/abs/path", "a//b", "a/../b", "a/b/", "a b", "a\\b", "ñandú/a", "a?b", "x" * 501])
def test_validate_key_rejects_what_the_backend_rejects(key):
    """Mismas reglas que `ModelApprovalGates::assertOwnKey`: sin `..`, `//`, barra inicial o final, ni caracteres fuera de [A-Za-z0-9._/-]."""
    with pytest.raises(InvalidObjectKey):
        validate_key(key)


@pytest.mark.parametrize("key", [
    f"tenants/{UUID_A}/models/customer/v1/a.joblib", f"tenants/{UUID_A}/models/demand/v1/a.joblib.manifest.json"])
def test_own_key_check_accepts_the_servers_keys(key):
    assert is_tenant_models_key(key, UUID_A)


@pytest.mark.parametrize("key", [
    f"tenants/{UUID_B}/models/customer/v1/a.joblib", f"tenants/{UUID_A}/bronze/sales/a.csv", f"tenants/{UUID_A}/models/../../{UUID_B}/models/x",
    f"tenants/{UUID_A}/models//a", f"tenants/{UUID_A}/models/a/", f"tenants/{UUID_A}/models/a b", f"/tenants/{UUID_A}/models/a", "models/a",
    None, 5, f"tenants/{UUID_A}/models"])
def test_own_key_check_rejects_everything_else(key):
    assert not is_tenant_models_key(key, UUID_A)


def test_local_store_put_get_exists_list_delete(tmp_path):
    store = LocalObjectStore(tmp_path)
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    assert not store.exists(key)
    stored = store.put(key, b"contenido")
    assert stored.size_bytes == 9 and len(stored.sha256) == 64
    assert store.exists(key) and store.get(key) == b"contenido"
    assert store.list_keys(f"tenants/{UUID_A}/models") == [key]
    assert store.list_keys(f"tenants/{UUID_B}/models") == []                       # otra empresa: nada
    store.delete(key)
    store.delete(key)                                                              # idempotente
    assert not store.exists(key)
    with pytest.raises(ObjectNotFound):
        store.get(key)


def test_local_store_layout_is_the_backend_local_disk_layout(tmp_path):
    """La raíz del controlador local es la del disco `local` del backend: `<raíz>/<clave>`; así `Storage::disk('local')->readStream($key)` lo lee."""
    key = model_object_key(UUID_A, "demand", "v9", "lightgbm_global.joblib")
    LocalObjectStore(tmp_path).put(key, b"x")
    assert (tmp_path / key).read_bytes() == b"x"


def test_writes_are_write_once_and_idempotent(tmp_path):
    store = LocalObjectStore(tmp_path)
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    store.put(key, b"uno")
    assert store.put(key, b"uno").sha256 == store.put(key, b"uno").sha256          # el mismo contenido converge
    with pytest.raises(ObjectConflict):
        store.put(key, b"otro")
    assert store.get(key) == b"uno"                                                # no se sobrescribió
    store.put(key, b"otro", overwrite=True)
    assert store.get(key) == b"otro"


def test_local_files_are_private_and_no_temp_files_remain(tmp_path):
    store = LocalObjectStore(tmp_path)
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    store.put(key, b"x")
    assert stat.S_IMODE(os.stat(tmp_path / key).st_mode) == 0o600
    assert not [p for p in tmp_path.rglob("*") if p.name.endswith(".tmp")]


def test_a_symlink_cannot_make_the_store_leave_its_root(tmp_path):
    root, outside = tmp_path / "root", tmp_path / "outside"
    root.mkdir()
    outside.mkdir()
    (outside / "secret.txt").write_text("secreto")
    store = LocalObjectStore(root)
    (root / "tenants").symlink_to(outside)
    with pytest.raises(InvalidObjectKey):
        store.get("tenants/secret.txt")
    with pytest.raises(InvalidObjectKey):
        store.put("tenants/escape.bin", b"x")
    assert not (outside / "escape.bin").exists()


def test_get_enforces_a_maximum_size(tmp_path):
    store = LocalObjectStore(tmp_path)
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    store.put(key, b"x" * 100)
    with pytest.raises(os_mod.ObjectStoreError):
        store.get(key, max_bytes=10)


def test_build_store_reads_the_environment_and_never_exposes_secrets(tmp_path, monkeypatch):
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "local")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ROOT", str(tmp_path))
    store = build_store()
    assert isinstance(store, LocalObjectStore) and store.describe() == {"driver": "local", "root": str(tmp_path), "root_source": "ENGINE_OBJECT_STORE_ROOT"}
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_BUCKET", "bucket-de-prueba")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ENDPOINT", "http://usuario:SECRETO-NO-DEBE-SALIR@127.0.0.1:5000")
    described = build_store().describe()
    assert described["bucket"] == "bucket-de-prueba" and described["endpoint_host"] == "127.0.0.1"
    assert "SECRETO" not in str(described) and "usuario" not in str(described)


def test_build_store_without_configuration_fails_with_an_actionable_message(monkeypatch):
    monkeypatch.delenv("ENGINE_OBJECT_STORE_ROOT", raising=False)
    monkeypatch.delenv("ENGINE_OBJECT_STORE_DRIVER", raising=False)
    monkeypatch.setattr(os_mod, "monorepo_default_root", lambda: None)
    with pytest.raises(ObjectStoreUnavailable, match="ENGINE_OBJECT_STORE_ROOT"):
        build_store()
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.delenv("ENGINE_OBJECT_STORE_BUCKET", raising=False)
    with pytest.raises(ObjectStoreUnavailable, match="BUCKET"):
        build_store()
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "ftp")
    with pytest.raises(ObjectStoreUnavailable):
        build_store()


def test_the_monorepo_default_root_is_the_backends_local_disk(monkeypatch, tmp_path):
    monkeypatch.delenv("ENGINE_OBJECT_STORE_ROOT", raising=False)
    monkeypatch.delenv("ENGINE_OBJECT_STORE_DRIVER", raising=False)
    monkeypatch.setattr(os_mod, "monorepo_default_root", lambda: tmp_path)
    store = build_store()
    assert store.describe()["root_source"] == "monorepo_backend_default" and store.root == tmp_path


def test_the_real_monorepo_default_points_at_the_backends_private_disk():
    real = os_mod.monorepo_default_root()
    if real is not None:
        assert real.parts[-4:] == ("backend", "storage", "app", "private")
