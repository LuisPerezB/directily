"""Cifrado en reposo (AES-256-GCM), clave maestra, almacén de secretos y permisos de archivos."""

from __future__ import annotations

import os
import stat
from pathlib import Path

import pytest

from predictily_bridge import crypto
from predictily_bridge.device import DeviceHome, NotEnrolled
from predictily_bridge.fsutil import InsecurePermissions, ensure_private_dir, read_private, write_private
from predictily_bridge.keystore import ENV_VAR, Keystore, KeystoreError
from predictily_bridge.securestore import SecureStore

posix = pytest.mark.skipif(os.name != "posix", reason="permisos POSIX")


def test_seal_unseal_roundtrip_and_tamper_detection() -> None:
    key = crypto.new_master_key()
    blob = crypto.seal(key, b"secreto de negocio", b"ctx")
    assert b"secreto" not in blob
    assert crypto.unseal(key, blob, b"ctx") == b"secreto de negocio"
    tampered = bytearray(blob)
    tampered[-1] ^= 1
    with pytest.raises(crypto.CryptoError):
        crypto.unseal(key, bytes(tampered), b"ctx")
    with pytest.raises(crypto.CryptoError):
        crypto.unseal(crypto.new_master_key(), blob, b"ctx")  # otra clave
    with pytest.raises(crypto.CryptoError):
        crypto.unseal(key, blob, b"otro-contexto")  # el contexto se autentica
    with pytest.raises(crypto.CryptoError):
        crypto.unseal(key, b"basura", b"ctx")


def test_nonces_are_never_reused() -> None:
    key = crypto.new_master_key()
    assert len({crypto.seal(key, b"x")[4:16] for _ in range(200)}) == 200


@posix
def test_master_key_file_is_0600_in_a_0700_dir_and_stable(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv(ENV_VAR, raising=False)
    home = tmp_path / "h"
    ks = Keystore(home, use_keyring=False)
    assert ks.backend == "file"
    key = ks.master_key()
    assert len(key) == 32
    assert stat.S_IMODE((home / "master.key").stat().st_mode) == 0o600
    assert stat.S_IMODE(home.stat().st_mode) == 0o700
    assert Keystore(home, use_keyring=False).master_key() == key


def test_master_key_from_environment_has_precedence(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    key = crypto.new_master_key()
    monkeypatch.setenv(ENV_VAR, crypto.b64u(key))
    ks = Keystore(tmp_path / "h", use_keyring=False)
    assert ks.backend == "env" and ks.master_key() == key
    monkeypatch.setenv(ENV_VAR, crypto.b64u(b"corta"))
    with pytest.raises(KeystoreError):
        Keystore(tmp_path / "h2", use_keyring=False).master_key()


def test_securestore_is_encrypted_atomic_and_bound_to_its_file_name(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv(ENV_VAR, raising=False)
    ks = Keystore(tmp_path, use_keyring=False)
    a = SecureStore(tmp_path / "secrets.enc", ks)
    a.save({"device_token": "brt_SUPERSECRETO", "x": 1})
    raw = (tmp_path / "secrets.enc").read_bytes()
    assert b"SUPERSECRETO" not in raw and b"device_token" not in raw
    assert a.load() == {"device_token": "brt_SUPERSECRETO", "x": 1}
    # Copiar un archivo cifrado sobre otro falla (el nombre es parte del contexto autenticado).
    write_private(tmp_path / "state.enc", raw)
    with pytest.raises(crypto.CryptoError):
        SecureStore(tmp_path / "state.enc", ks).load()
    assert SecureStore(tmp_path / "nuevo.enc", ks).load() == {}
    assert not list(tmp_path.glob("*.tmp")), "escritura atómica: no quedan temporales"


@posix
def test_insecure_permissions_are_refused(tmp_path: Path) -> None:
    d = tmp_path / "abierto"
    d.mkdir(mode=0o755)
    with pytest.raises(InsecurePermissions):
        ensure_private_dir(d)
    f = tmp_path / "f"
    write_private(f, b"x")
    f.chmod(0o644)
    with pytest.raises(InsecurePermissions):
        read_private(f)


def test_device_home_keeps_token_and_private_key_only_encrypted(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv(ENV_VAR, raising=False)
    home = DeviceHome(tmp_path / "bridge", use_keyring=False)
    with pytest.raises(NotEnrolled):
        home.load_identity()
    key = crypto.DeviceKey.generate()
    token = "brt_" + "A" * 43
    home.save_enrolment(key=key, token=token, info={"device_id": 7, "fingerprint": key.fingerprint, "server": "https://x"})
    identity = DeviceHome(tmp_path / "bridge", use_keyring=False).load_identity()
    assert identity.token == token and identity.key.fingerprint == key.fingerprint
    for path in (tmp_path / "bridge").rglob("*"):
        if path.is_file():
            data = path.read_bytes()
            assert token.encode() not in data, f"{path.name} contiene el token en claro"
            assert crypto.b64u(key.seed).encode() not in data, f"{path.name} contiene la clave privada en claro"
    assert home.device_info()["device_id"] == 7
    home.set_secret("source_password", "clave-del-erp-123")
    assert DeviceHome(tmp_path / "bridge", use_keyring=False).secret("source_password") == "clave-del-erp-123"
    assert b"clave-del-erp-123" not in (tmp_path / "bridge" / "secrets.enc").read_bytes()
