"""Controlador S3 del almacén de objetos contra un EMULADOR (moto): NO es AWS real.

Estado honesto: el controlador S3 está IMPLEMENTADO y VERIFICADO SOLO CONTRA UN EMULADOR S3 (moto, en memoria); contra AWS real está BLOQUEADO (no hay cuenta del
propietario). boto3 y moto no son dependencias del motor: esta prueba se OMITE con un mensaje explícito si no están instalados. Se ejecuta en un venv aparte:

    python3 -m venv .venv-s3 && .venv-s3/bin/pip install boto3 'moto[s3]' pytest cryptography pydantic-settings
    PYTHONPATH=engine .venv-s3/bin/python -m pytest --noconftest -p no:cacheprovider engine/tests/unit/test_object_store_s3_emulator.py
"""

from __future__ import annotations

import pytest

boto3 = pytest.importorskip("boto3", reason="boto3 no está instalado: controlador S3 implemented_untested en este entorno (extra opcional «s3»)")
moto = pytest.importorskip("moto", reason="moto (EMULADOR de S3) no está instalado: la prueba del controlador S3 se omite")

from moto import mock_aws  # noqa: E402

from predictily_engine.platform.object_store import (  # noqa: E402
    InvalidObjectKey,
    ObjectConflict,
    ObjectNotFound,
    S3ObjectStore,
    build_store,
    is_tenant_models_key,
    model_object_key,
)

UUID_A = "54b8d9b9-b3f0-41c9-ad7b-ccf48d8e5a7f"
UUID_B = "0f1e2d3c-4b5a-4968-8776-655443322110"
BUCKET = "predictily-emulador"


@pytest.fixture()
def emulator(monkeypatch):
    """EMULADOR: S3 en memoria de moto. Credenciales falsas del emulador; nada sale a AWS."""
    monkeypatch.setenv("AWS_ACCESS_KEY_ID", "emulador-no-real")
    monkeypatch.setenv("AWS_SECRET_ACCESS_KEY", "emulador-no-real")
    monkeypatch.setenv("AWS_DEFAULT_REGION", "us-east-1")
    with mock_aws():
        boto3.client("s3", region_name="us-east-1").create_bucket(Bucket=BUCKET)
        yield


def test_s3_driver_against_the_emulator_put_get_exists_list_delete(emulator):
    """EMULADOR: el mismo contrato que el controlador local."""
    store = S3ObjectStore(BUCKET, region="us-east-1")
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    assert not store.exists(key)
    stored = store.put(key, b"contenido")
    assert stored.size_bytes == 9
    assert store.exists(key) and store.get(key) == b"contenido"
    assert store.list_keys(f"tenants/{UUID_A}/models") == [key]
    assert store.list_keys(f"tenants/{UUID_B}/models") == []
    store.delete(key)
    assert not store.exists(key)
    with pytest.raises(ObjectNotFound):
        store.get(key)


def test_s3_driver_is_write_once_and_idempotent(emulator):
    store = S3ObjectStore(BUCKET, region="us-east-1")
    key = model_object_key(UUID_A, "demand", "v1", "a.joblib")
    store.put(key, b"uno")
    store.put(key, b"uno")
    with pytest.raises(ObjectConflict):
        store.put(key, b"otro")
    assert store.get(key) == b"uno"


def test_s3_driver_applies_server_side_encryption_and_keys_stay_tenant_scoped(emulator):
    store = S3ObjectStore(BUCKET, region="us-east-1", sse="AES256")
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    store.put(key, b"x")
    head = boto3.client("s3", region_name="us-east-1").head_object(Bucket=BUCKET, Key=key)
    assert head["ServerSideEncryption"] == "AES256"
    assert is_tenant_models_key(key, UUID_A) and not is_tenant_models_key(key, UUID_B)
    with pytest.raises(InvalidObjectKey):
        store.get("tenants/../x")


def test_s3_errors_never_leak_credentials(emulator):
    store = S3ObjectStore("bucket-que-no-existe", region="us-east-1")
    with pytest.raises(Exception) as caught:
        store.put(model_object_key(UUID_A, "customer", "v1", "a.joblib"), b"x")
    assert "emulador-no-real" not in str(caught.value)


def test_build_store_selects_the_s3_driver_from_the_environment(emulator, monkeypatch):
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_BUCKET", BUCKET)
    monkeypatch.setenv("ENGINE_OBJECT_STORE_REGION", "us-east-1")
    store = build_store()
    assert isinstance(store, S3ObjectStore)
    key = model_object_key(UUID_A, "customer", "v1", "a.joblib")
    store.put(key, b"x")
    assert store.get(key) == b"x"
