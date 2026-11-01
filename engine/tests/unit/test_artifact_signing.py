"""Firma Ed25519 de artefactos de modelos (ADR-V25-20; defecto HIGH de modelos_aws: `joblib.load` con solo SHA-256 permite
ejecución de código). Se verifica que NADA se deserializa sin firma válida de una clave de confianza y que un pickle malicioso con
SHA-256 coherente no ejecuta su carga útil. Sin base de datos: el conftest genera un par de claves efímero por sesión."""

from __future__ import annotations

import hashlib
import itertools
import json
import os
import shutil
import stat
import types
from pathlib import Path

import joblib
import pytest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

from predictily_engine import cli
from predictily_engine.config import get_settings
from predictily_engine.customer.service import _artifact_compat, _save_artifact as save_customer_artifact, _upsert_registry, load_artifact
from predictily_engine.pipeline.forecast_run import _save_artifact as save_demand_artifact
from predictily_engine.platform import artifacts, signing
from predictily_engine.platform.signing import SigningConfig

ROOT = Path(get_settings().artifacts_dir)


class Payload:
    """Pickle malicioso: al deserializarse escribe un archivo marcador (efecto lateral observable)."""

    def __init__(self, marker: Path) -> None:
        self.marker = marker

    def __reduce__(self):
        return (Path.write_text, (self.marker, "payload ejecutado"))


_TENANTS = itertools.count(9100, 10)


@pytest.fixture
def tenant():
    """Una empresa por prueba, con 10 ids propios (el directorio de artefactos es compartido por la sesión)."""
    return next(_TENANTS)


@pytest.fixture
def marker(tmp_path) -> Path:
    return tmp_path / "MARCADOR_EJECUTADO.txt"


def row_for(stored: str, sha: str) -> dict:
    return {"artifact_path": stored, "artifact_sha256": sha, "artifact_compat": _artifact_compat(), "version": "t-test"}


def attacker_config(key_id: str = "test-ephemeral") -> SigningConfig:
    return SigningConfig(key_id=key_id, private_key=Ed25519PrivateKey.generate())


def write_malicious(tenant: int, marker: Path, name: str = "evil") -> tuple[Path, str]:
    """Pickle malicioso en la ruta correcta de la empresa, con el SHA-256 coherente en el «registro»."""
    path = ROOT / str(tenant) / "customer" / "run-1" / f"{name}.joblib"
    path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(Payload(marker), path, compress=3)
    return path, hashlib.sha256(path.read_bytes()).hexdigest()


def sidecar(path: Path) -> Path:
    return signing.manifest_path(path)


def forge_manifest(path: Path, tenant: int, config: SigningConfig, **overrides) -> None:
    data = path.read_bytes()
    manifest = signing.build_manifest(artifact_name=path.name, sha256=hashlib.sha256(data).hexdigest(), size_bytes=len(data), tenant_id=tenant,
                                      core="customer", run_id=1, name="forjado", data_fingerprint=None, feature_schema_version=None,
                                      libraries=None, key_id=config.key_id)
    manifest.update(overrides)
    signing.write_manifest(path, manifest, config)


# ───────────────────────────── guardar y cargar ─────────────────────────────

def test_a_signed_artifact_loads_and_its_manifest_binds_tenant_core_run_and_data(tenant):
    stored, sha = save_customer_artifact(tenant, 12, "h30_probability_logistic", {"y": 2})
    assert stored == f"{tenant}/customer/run-12/h30_probability_logistic.joblib"
    art, reason = load_artifact(row_for(stored, sha), tenant)
    assert reason is None and art == {"y": 2}

    envelope = json.loads(sidecar(ROOT / stored).read_text())
    assert (envelope["format"], envelope["algorithm"], envelope["key_id"]) == (signing.ENVELOPE_FORMAT, "ed25519", "test-ephemeral")
    manifest = json.loads(envelope["manifest"])
    assert manifest["tenant_id"] == tenant and manifest["core"] == "customer" and manifest["run_id"] == 12
    assert manifest["name"] == "h30_probability_logistic" and manifest["key_id"] == "test-ephemeral"
    assert manifest["artifact"] == {"file_name": "h30_probability_logistic.joblib", "format": "joblib", "sha256": sha,
                                    "size_bytes": (ROOT / stored).stat().st_size}
    assert manifest["feature_schema_version"] and manifest["libraries"]["numpy"] and manifest["created_at"].endswith("Z")
    assert "data_fingerprint" in manifest
    # JSON canónico: claves ordenadas y compacto; la firma cubre exactamente esos bytes.
    assert envelope["manifest"] == json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=True)


def test_the_manifest_carries_data_fingerprint_and_versions_when_given(tenant):
    path = ROOT / str(tenant) / "run-3" / "lightgbm_global.joblib"
    saved = artifacts.save_signed_artifact({"m": 1}, path, tenant_id=tenant, core="demand", run_id=3, name="lightgbm_global",
                                           data_fingerprint="f" * 64, feature_schema_version="fs-test", libraries={"lightgbm": "4.7.0", "numpy": "2.5.4"})
    manifest = json.loads(json.loads(sidecar(path).read_text())["manifest"])
    assert (manifest["data_fingerprint"], manifest["feature_schema_version"], manifest["core"], manifest["run_id"]) == ("f" * 64, "fs-test", "demand", 3)
    assert manifest["libraries"] == {"lightgbm": "4.7.0", "numpy": "2.5.4"}
    info = saved.signature
    assert (info.status, info.key_id) == ("signed", "test-ephemeral")
    assert info.as_compat() == {"status": "signed", "algorithm": "ed25519", "key_id": "test-ephemeral", "manifest_sha256": info.manifest_sha256}
    assert info.manifest_sha256 == hashlib.sha256(sidecar(path).read_bytes()).hexdigest()


def test_demand_artifacts_are_signed_with_the_demand_core(tenant):
    stored, sha = save_demand_artifact(tenant, 11, "lightgbm_global", {"x": 1})
    assert stored == f"{tenant}/run-11/lightgbm_global.joblib"
    ok = artifacts.verify_stored_artifact(stored, tenant_id=tenant, core="demand", expected_sha256=sha)
    assert ok.ok and ok.artifact.status == "signed"
    # Un artefacto de demanda no se sirve como modelo del Customer Core.
    assert artifacts.verify_stored_artifact(stored, tenant_id=tenant, core="customer", expected_sha256=sha).reason == signing.REASON_CORE_MISMATCH


# ───────────────────────────── alteración y clave desconocida ─────────────────────────────

def test_altered_bytes_are_rejected_before_deserializing(tenant, marker, monkeypatch):
    stored, sha = save_customer_artifact(tenant, 1, "h7_probability_logistic", {"ok": True})
    path = ROOT / stored
    monkeypatch.setattr(signing.joblib, "load", lambda *a, **k: pytest.fail("se deserializó un artefacto no verificado"))
    path.write_bytes(path.read_bytes()[:-8] + b"alterado")
    # Fila intacta: el SHA-256 del registro ya no coincide.
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_sha256_mismatch")
    # El atacante también actualiza la fila: el manifiesto firmado no coincide con los bytes.
    forged_sha = hashlib.sha256(path.read_bytes()).hexdigest()
    assert load_artifact(row_for(stored, forged_sha), tenant) == (None, "artifact_manifest_mismatch")


def test_a_truncated_or_extended_file_is_rejected_by_size_even_with_the_right_manifest_digest(tenant):
    stored, sha = save_customer_artifact(tenant, 2, "h7_quantity_gamma", {"ok": True})
    manifest = json.loads(json.loads(sidecar(ROOT / stored).read_text())["manifest"])
    # Un manifiesto de confianza que declara otro tamaño (p. ej. archivo con bytes añadidos) se rechaza.
    config = signing.load_config()
    path = ROOT / stored
    data = path.read_bytes()
    forged = signing.build_manifest(artifact_name=path.name, sha256=hashlib.sha256(data).hexdigest(), size_bytes=len(data) + 1, tenant_id=tenant, core="customer",
                                    run_id=2, name=manifest["name"], data_fingerprint=None, feature_schema_version=None, libraries=None, key_id=config.key_id)
    signing.write_manifest(path, forged, config)
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_size_mismatch")


def test_malicious_pickle_with_coherent_sha_and_no_signature_is_rejected_without_side_effects(tenant, marker):
    path, sha = write_malicious(tenant, marker)
    stored = artifacts.to_stored_path(path)
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_unsigned")
    assert not marker.exists(), "el payload se ejecutó: se deserializó antes de verificar"


def test_malicious_pickle_with_invalid_signature_is_rejected_without_side_effects(tenant, marker):
    path, sha = write_malicious(tenant, marker)
    stored = artifacts.to_stored_path(path)
    # Firma generada con una clave del atacante, declarando el id de la clave de confianza.
    forge_manifest(path, tenant, attacker_config("test-ephemeral"))
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_signature_invalid")
    assert not marker.exists()
    # Firma de confianza válida pero adulterada (un bit).
    envelope = json.loads(sidecar(path).read_text())
    good = signing.load_config()
    signing.write_manifest(path, json.loads(envelope["manifest"]), good)
    tampered = json.loads(sidecar(path).read_text())
    raw = bytearray(signing._b64decode(tampered["signature"]))
    raw[0] ^= 0x01
    tampered["signature"] = signing._b64(bytes(raw))
    sidecar(path).write_text(json.dumps(tampered))
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_signature_invalid")
    assert not marker.exists()


def test_an_unknown_signing_key_is_rejected_without_side_effects(tenant, marker):
    path, sha = write_malicious(tenant, marker)
    forge_manifest(path, tenant, attacker_config("clave-desconocida"))        # firma VÁLIDA, pero de una clave que no es de confianza
    assert load_artifact(row_for(artifacts.to_stored_path(path), sha), tenant) == (None, "artifact_untrusted_key")
    assert not marker.exists()


def test_a_corrupt_manifest_is_never_treated_as_an_unsigned_artifact(tenant, marker, monkeypatch):
    path, sha = write_malicious(tenant, marker)
    stored = artifacts.to_stored_path(path)
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "true")               # ni siquiera con el indicador de legado
    for body in (b"{no es json", b"[]", b'{"format": "otro"}', b'{"format": "predictily-signed-manifest/1", "manifest": 3}', b"\xff\xfe\x00"):
        sidecar(path).write_bytes(body)
        assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_manifest_invalid"), body
    sidecar(path).write_bytes(b" " * (signing.MAX_ENVELOPE_BYTES + 1))
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_manifest_invalid")
    assert not marker.exists()


def test_the_manifest_is_bound_to_its_own_file(tenant):
    a_stored, a_sha = save_customer_artifact(tenant, 3, "h7_probability_a", {"n": "a"})
    b_stored, b_sha = save_customer_artifact(tenant, 3, "h7_probability_b", {"n": "b"})
    # El manifiesto firmado de A copiado junto a B (mismos bytes de manifiesto, otro archivo) no vale para B.
    shutil.copy(sidecar(ROOT / a_stored), sidecar(ROOT / b_stored))
    assert load_artifact(row_for(b_stored, b_sha), tenant) == (None, "artifact_manifest_mismatch")
    # Ni siquiera con el mismo contenido bajo otro nombre de archivo.
    renamed = ROOT / str(tenant) / "customer" / "run-3" / "otro_nombre.joblib"
    shutil.copy(ROOT / a_stored, renamed)
    shutil.copy(sidecar(ROOT / a_stored), sidecar(renamed))
    assert load_artifact(row_for(artifacts.to_stored_path(renamed), a_sha), tenant) == (None, "artifact_manifest_mismatch")


# ───────────────────────────── aislamiento entre empresas ─────────────────────────────

def test_a_signed_artifact_of_another_company_is_rejected(tenant):
    other = tenant + 1
    stored, sha = save_customer_artifact(other, 5, "h7_probability_logistic", {"de": "otra empresa"})
    # (1) La fila de esta empresa apunta a la ruta de la otra: la ruta sale del directorio de su empresa.
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_path_invalid")
    assert load_artifact({**row_for(stored, sha), "tenant_id": tenant}) == (None, "artifact_path_invalid")
    # (2) Copiado (artefacto + manifiesto firmado) dentro del directorio de esta empresa: el manifiesto liga a la otra.
    mine = ROOT / str(tenant) / "customer" / "run-5" / "h7_probability_logistic.joblib"
    mine.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(ROOT / stored, mine)
    shutil.copy(sidecar(ROOT / stored), sidecar(mine))
    assert load_artifact(row_for(artifacts.to_stored_path(mine), sha), tenant) == (None, "artifact_tenant_mismatch")
    # Sirviendo a su propia empresa sí carga.
    assert load_artifact(row_for(stored, sha), other)[0] == {"de": "otra empresa"}


def test_stored_paths_cannot_leave_the_company_directory(tenant, tmp_path):
    outside = tmp_path / "fuera.joblib"
    joblib.dump({"x": 1}, outside)
    sha = hashlib.sha256(outside.read_bytes()).hexdigest()
    link_dir = ROOT / str(tenant)
    link_dir.mkdir(parents=True, exist_ok=True)
    (link_dir / "enlace.joblib").symlink_to(outside)
    for stored in (str(outside), f"{tenant}/../../../etc/passwd", f"{tenant}/../{tenant + 1}/x.joblib", "../fuera.joblib",
                   f"{tenant}/enlace.joblib", f"{tenant}", "", "\x00"):
        reason = load_artifact(row_for(stored, sha), tenant)[1]
        assert reason in ("artifact_path_invalid", "artifact_missing"), (stored, reason)
    with pytest.raises(artifacts.ArtifactPathError):
        artifacts.confine_stored_path(str(outside), tenant)


def test_legacy_stored_paths_inside_the_company_directory_still_resolve(tenant):
    stored, sha = save_customer_artifact(tenant, 7, "h30_quantity_gamma", {"legado": 1})
    absolute = str(ROOT / stored)                                  # filas antiguas: ruta absoluta (dentro del directorio)
    assert load_artifact(row_for(absolute, sha), tenant)[0] == {"legado": 1}
    assert artifacts.tenant_of_stored_path(stored) == tenant and artifacts.tenant_of_stored_path(absolute) == tenant
    assert artifacts.tenant_of_stored_path("/etc/passwd") is None


def test_legacy_rows_relative_to_the_working_directory_including_the_artifacts_dir_are_still_confined_and_readable(tmp_path, monkeypatch):
    """Filas anteriores: `storage/artifacts/<empresa>/…` relativa al cwd del motor con ENGINE_ARTIFACTS_DIR=./storage/artifacts."""
    root = tmp_path / "storage" / "artifacts"
    monkeypatch.setattr(artifacts, "artifacts_root", lambda: root)
    monkeypatch.chdir(tmp_path)
    old = root / "9" / "old.joblib"
    old.parent.mkdir(parents=True)
    old.write_bytes(b"x")
    assert artifacts.confine_stored_path("storage/artifacts/9/old.joblib", 9) == old.resolve()
    assert artifacts.confine_stored_path("9/old.joblib", 9) == old.resolve()
    with pytest.raises(artifacts.ArtifactPathError):
        artifacts.confine_stored_path("storage/artifacts/9/old.joblib", 10)              # de otra empresa
    with pytest.raises(artifacts.ArtifactPathError):
        artifacts.confine_stored_path("storage/artifacts/9/../../../etc/passwd", 9)


# ───────────────────────────── artefactos sin firma y el indicador de legado ─────────────────────────────

def unsigned_artifact(tenant: int, monkeypatch, obj=None) -> tuple[str, str]:
    """Artefacto guardado SIN clave privada configurada (queda marcado como sin firma)."""
    with monkeypatch.context() as m:
        m.delenv("ENGINE_SIGNING_PRIVATE_KEY")
        return save_customer_artifact(tenant, 8, "h7_probability_legacy", obj if obj is not None else {"legado": True})


def test_without_a_private_key_the_artifact_is_saved_marked_unsigned(tenant, monkeypatch, caplog):
    monkeypatch.delenv("ENGINE_SIGNING_PRIVATE_KEY")
    path = ROOT / str(tenant) / "customer" / "run-8" / "h7_quantity_gamma.joblib"
    with caplog.at_level("WARNING", logger="predictily_engine.platform.artifacts"):
        saved = artifacts.save_signed_artifact({"a": 1}, path, tenant_id=tenant, core="customer", run_id=8, name="h7_quantity_gamma")
    assert any("SIN firma" in r.getMessage() and f"empresa={tenant}" in r.getMessage() for r in caplog.records)
    assert saved.signature.status == "unsigned" and saved.signature.key_id is None
    assert saved.signature.as_compat()["algorithm"] is None
    envelope = json.loads(sidecar(path).read_text())
    assert (envelope["signature"], envelope["key_id"], envelope["algorithm"]) == (None, None, None)
    assert json.loads(envelope["manifest"])["artifact"]["sha256"] == saved.sha256        # trazabilidad, no confianza


def test_an_unsigned_artifact_is_rejected_by_default_and_loaded_only_with_the_explicit_flag(tenant, monkeypatch):
    stored, sha = unsigned_artifact(tenant, monkeypatch)
    row = row_for(stored, sha)
    assert load_artifact(row, tenant) == (None, "artifact_unsigned")                          # indicador desactivado (por defecto)
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "false")
    assert load_artifact(row, tenant) == (None, "artifact_unsigned")
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "true")
    art, reason = load_artifact(row, tenant)                                                  # legado verificable por SHA-256
    assert reason is None and art == {"legado": True}
    # El legado se sigue verificando por SHA-256 y por empresa.
    assert load_artifact(row_for(stored, "0" * 64), tenant) == (None, "artifact_sha256_mismatch")
    assert load_artifact({**row, "artifact_sha256": None}, tenant) == (None, "artifact_sha256_mismatch")
    assert load_artifact(row, tenant + 1) == (None, "artifact_path_invalid")


def test_a_legacy_file_without_any_manifest_follows_the_same_policy(tenant, monkeypatch):
    path = ROOT / str(tenant) / "customer" / "run-1" / "h7_probability_antiguo.joblib"
    path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump({"antiguo": 1}, path, compress=3)                                             # como lo escribía el motor anterior
    sha = hashlib.sha256(path.read_bytes()).hexdigest()
    row = row_for(artifacts.to_stored_path(path), sha)
    assert not sidecar(path).exists()
    assert load_artifact(row, tenant) == (None, "artifact_unsigned")
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "1")
    assert load_artifact(row, tenant)[0] == {"antiguo": 1}


def test_the_legacy_flag_never_accepts_invalid_signatures_unknown_keys_or_other_companies(tenant, marker, monkeypatch):
    monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", "true")
    path, sha = write_malicious(tenant, marker)
    stored = artifacts.to_stored_path(path)
    forge_manifest(path, tenant, attacker_config("test-ephemeral"))                          # firma inválida con id de confianza
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_signature_invalid")
    forge_manifest(path, tenant, attacker_config("clave-desconocida"))                       # clave desconocida
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_untrusted_key")
    forge_manifest(path, tenant, signing.load_config(), tenant_id=tenant + 5)                # firmado por la clave buena, pero de otra empresa
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_tenant_mismatch")
    envelope = json.loads(sidecar(path).read_text())                                         # una firma quitada con key_id presente
    envelope["signature"] = None
    sidecar(path).write_text(json.dumps(envelope))
    assert load_artifact(row_for(stored, sha), tenant) == (None, "artifact_manifest_invalid")
    assert not marker.exists()


# ───────────────────────────── TOCTOU y API ─────────────────────────────

def test_deserialization_uses_the_verified_bytes_not_the_file_on_disk(tenant, marker):
    stored, sha = save_customer_artifact(tenant, 9, "h7_probability_logistic", {"verificado": True})
    verified = artifacts.verify_stored_artifact(stored, tenant_id=tenant, core="customer", expected_sha256=sha)
    assert verified.ok
    # El archivo se sustituye entre la verificación y la carga: el cargado sigue siendo el verificado.
    joblib.dump(Payload(marker), ROOT / stored, compress=3)
    assert artifacts.deserialize(verified.artifact) == {"verificado": True}
    assert not marker.exists()


def test_only_verified_artifacts_can_be_deserialized():
    with pytest.raises(TypeError):
        artifacts.deserialize(b"cualquier cosa")                                              # type: ignore[arg-type]
    with pytest.raises(TypeError):
        artifacts.deserialize({"data": b"x"})                                                 # type: ignore[arg-type]


def test_an_unreadable_registry_digest_is_a_mismatch_not_a_crash(tenant):
    stored, sha = save_customer_artifact(tenant, 6, "h7_probability_logistic", {"ok": 1})
    for bad in ("ñ" * 64, "", 12345, b"bytes", "A" * 64):
        assert load_artifact(row_for(stored, bad), tenant) == (None, "artifact_sha256_mismatch"), bad
    assert load_artifact(row_for(stored, sha.upper()), tenant)[0] == {"ok": 1}          # el hexadecimal en mayúsculas es el mismo SHA-256


def test_a_missing_artifact_keeps_its_stable_reason(tenant):
    row = row_for(f"{tenant}/customer/run-1/no-existe.joblib", "a" * 64)
    assert load_artifact(row, tenant) == (None, "artifact_missing")
    assert load_artifact({**row, "artifact_path": None}, tenant) == (None, "artifact_missing")


def test_rejections_are_logged_without_paths_or_payload_data(tenant, marker, caplog):
    path, sha = write_malicious(tenant, marker)
    with caplog.at_level("WARNING", logger="predictily_engine.customer.service"):
        load_artifact(row_for(artifacts.to_stored_path(path), sha), tenant)
    messages = " ".join(r.getMessage() for r in caplog.records)
    assert "artifact_unsigned" in messages and f"empresa={tenant}" in messages
    assert str(ROOT) not in messages and "MARCADOR" not in messages and "payload" not in messages


# ───────────────────────────── configuración de claves ─────────────────────────────

def test_trusted_keys_parsing_is_strict_and_fails_closed(caplog):
    _, _, pub_a = signing.generate_keypair("a")
    _, _, pub_b = signing.generate_keypair("b")
    keys, warnings = signing.parse_trusted_keys(f"k1:{pub_a}, k2:{pub_b};k3:{pub_a}\nk4:{pub_b}")
    assert set(keys) == {"k1", "k2", "k3", "k4"} and warnings == []
    keys, warnings = signing.parse_trusted_keys(f"k1:{pub_a},k1:{pub_b},mala,k2:no-es-base64!,k3:{pub_a[:10]},:{pub_a},mal/id:{pub_a}")
    assert keys == {}                                                    # repetida con valores distintos, malformada o inválida: no se confía en nada
    assert len(warnings) == 6 and all(pub_a not in w and pub_b not in w for w in warnings), warnings
    assert signing.parse_trusted_keys(None) == ({}, []) and signing.parse_trusted_keys("  ") == ({}, [])
    same, warnings = signing.parse_trusted_keys(f"k1:{pub_a},k1:{pub_a}")
    assert set(same) == {"k1"} and warnings == []                        # repetida con el MISMO valor es inocua


def test_private_key_can_be_read_from_a_file_and_misconfiguration_is_loud_but_never_leaks_the_key(tmp_path, monkeypatch):
    kid, private, public = signing.generate_keypair("k-archivo")
    key_file = tmp_path / "k.key"
    signing.write_private_key_file(key_file, private)
    assert stat.S_IMODE(key_file.stat().st_mode) == 0o600
    monkeypatch.setenv("ENGINE_SIGNING_KEY_ID", kid)
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", f"file:{key_file}")
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", f"{kid}:{public}")
    cfg = signing.load_config()
    assert cfg.key_id == kid and cfg.private_key is not None and kid in cfg.trusted and cfg.allow_unsigned is False
    assert "Ed25519" not in repr(cfg) and private not in repr(cfg)                   # la representación no expone la clave

    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", "esto-no-es-una-clave")
    with pytest.raises(signing.SigningConfigError) as exc:
        signing.load_config()
    assert "esto-no-es-una-clave" not in str(exc.value)
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", f"file:{tmp_path / 'no-existe'}")
    with pytest.raises(signing.SigningConfigError):
        signing.load_config()
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", private)
    monkeypatch.delenv("ENGINE_SIGNING_KEY_ID")
    with pytest.raises(signing.SigningConfigError):
        signing.load_config()                                                          # clave privada sin id: error explícito, no firma a medias


def test_the_flag_defaults_to_false_and_only_explicit_truthy_values_enable_it(monkeypatch):
    assert os.environ.get("ENGINE_ALLOW_UNSIGNED_ARTIFACTS") is None, "las pruebas no activan el indicador de legado globalmente"
    assert signing.load_config().allow_unsigned is False
    for value, expected in (("true", True), ("TRUE", True), ("1", True), ("yes", True), ("on", True), ("false", False), ("0", False), ("", False), ("quizá", False)):
        monkeypatch.setenv("ENGINE_ALLOW_UNSIGNED_ARTIFACTS", value)
        assert signing.load_config().allow_unsigned is expected, value


# ───────────────────────────── registro y CLI ─────────────────────────────

def test_the_registry_row_records_the_signature_summary_in_artifact_compat():
    captured: dict = {}

    class Conn:
        def execute(self, statement, params):
            captured.update(params)
            return types.SimpleNamespace(scalar_one=lambda: 77)

    info = {"status": "signed", "algorithm": "ed25519", "key_id": "test-ephemeral", "manifest_sha256": "ab" * 32}
    rid = _upsert_registry(Conn(), 5, "t5-customer-v1-h7-probability-logistic", "customer_probability_logistic", "validated", "primary", {"slot": "h7:probability"},
                           "5/customer/run-1/x.joblib", "c" * 64, 1, None, None, 10, 42, None, "d" * 64, False, signature=info)
    assert rid == 77
    compat = json.loads(captured["compat"])
    assert compat["signature"] == info and compat["feature_schema_version"] and compat["libraries"]
    _upsert_registry(Conn(), 5, "v", "f", "failed", "primary", {}, None, None, 1, None, None, 0, 42, None, None, False)
    assert "signature" not in json.loads(captured["compat"])                        # filas sin artefacto no llevan firma


def test_keygen_creates_a_0600_private_file_prints_only_the_public_key_and_refuses_to_overwrite(tenant, tmp_path, capsys, monkeypatch):
    target = tmp_path / "firma.key"
    assert cli.main(["keygen", "--key-id", "k-cli", "--private-out", str(target)]) == 0
    out = capsys.readouterr().out
    private = target.read_text().strip()
    assert stat.S_IMODE(target.stat().st_mode) == 0o600
    assert private not in out and "ENGINE_SIGNING_KEY_ID=k-cli" in out and f"ENGINE_SIGNING_PRIVATE_KEY=file:{target.resolve()}" in out
    public = out.split("ENGINE_TRUSTED_PUBLIC_KEYS=k-cli:")[1].split()[0]
    assert len(signing._b64decode(public)) == 32 and signing.public_key_fingerprint(public) in out
    assert cli.main(["keygen", "--key-id", "k-cli", "--private-out", str(target)]) == 2          # no sobrescribe
    assert "ya existe" in capsys.readouterr().err and target.read_text().strip() == private
    assert cli.main(["keygen", "--key-id", "k-cli", "--private-out", str(target), "--force"]) == 0
    assert target.read_text().strip() != private
    assert cli.main(["keygen", "--key-id", "id con espacios", "--private-out", str(tmp_path / "x.key")]) == 2

    # La clave generada por el CLI (archivo 0600) sirve para firmar y verificar de punta a punta.
    monkeypatch.setenv("ENGINE_SIGNING_KEY_ID", "k-cli")
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", f"file:{target}")
    derived = signing._b64(signing.load_config().private_key.public_key().public_bytes_raw())
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", f"k-cli:{derived}")
    stored, sha = save_customer_artifact(tenant, 1, "h7_probability_cli", {"cli": True})
    assert load_artifact(row_for(stored, sha), tenant)[0] == {"cli": True}
    assert json.loads(sidecar(ROOT / stored).read_text())["key_id"] == "k-cli"


def test_verify_artifact_command_reports_the_verification_without_deserializing(tenant, marker, capsys):
    stored, sha = save_customer_artifact(tenant, 4, "h7_probability_logistic", {"v": 1})
    assert cli.main(["verify-artifact", "--path", stored, "--tenant-id", str(tenant), "--core", "customer", "--sha256", sha]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report == {"ok": True, "reason": None, "status": "signed", "key_id": "test-ephemeral"}
    assert cli.main(["verify-artifact", "--path", stored, "--tenant-id", str(tenant + 1)]) == 1
    assert json.loads(capsys.readouterr().out)["reason"] == "artifact_path_invalid"
    path, evil_sha = write_malicious(tenant, marker, "evil-cli")
    assert cli.main(["verify-artifact", "--path", artifacts.to_stored_path(path), "--tenant-id", str(tenant), "--sha256", evil_sha]) == 1
    assert json.loads(capsys.readouterr().out)["reason"] == "artifact_unsigned" and not marker.exists()
