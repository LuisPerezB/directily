"""Paquete, firma y verificación (W4-B): firmar al empaquetar, verificar antes de publicar; nada se deserializa."""

from __future__ import annotations

import json
import os

import pytest

from mlops_helpers import read, synthetic_draft
from predictily_engine import cli
from predictily_engine.mlops import bundle
from predictily_engine.platform import signing


@pytest.fixture()
def draft_dir(tmp_path):
    directory = tmp_path / "train"
    synthetic_draft(directory, tenant_id=7)
    return directory


def run(*argv: str) -> int:
    return cli.main(list(argv))


def test_package_creates_a_signed_manifest_with_versions_fingerprint_and_gate(draft_dir, capsys):
    assert run("package", "--dir", str(draft_dir)) == 0
    manifest, reason = bundle.read_package(draft_dir)
    assert reason is None and manifest["schema"] == "predictily-model-package/1"
    assert manifest["tenant_id"] == 7 and manifest["core"] == "customer" and manifest["key_id"] == "test-ephemeral"
    assert manifest["libraries"]["lightgbm"] == "4.7.0" and manifest["data_fingerprint"] == "a" * 64 and manifest["data_gate"]["outcome"] == "PASS"
    assert len(manifest["bundle_id"]) == 16 and [m["file"] for m in manifest["models"]] == ["h7_probability_reference.joblib", "h14_probability_reference.joblib"]
    assert all(len(m["manifest_sha256"]) == 64 and m["key_id"] == "test-ephemeral" for m in manifest["models"])
    envelope = read(draft_dir / bundle.PACKAGE_FILE)
    assert envelope["format"] == "predictily-signed-package/1" and envelope["algorithm"] == "ed25519" and json.loads(envelope["manifest"]) == manifest
    capsys.readouterr()
    assert run("verify", "--dir", str(draft_dir), "--require-package", "--tenant-id", "7") == 0
    report = json.loads(capsys.readouterr().out)
    assert report["ok"] and report["package"] == "verified" and all(a["status"] == "signed" for a in report["artifacts"])


def test_the_package_is_not_rewritten_without_force(draft_dir, capsys):
    assert run("package", "--dir", str(draft_dir)) == 0
    first = bundle.read_package(draft_dir)[0]["bundle_id"]
    assert run("package", "--dir", str(draft_dir)) == 6                             # conflicto
    assert bundle.read_package(draft_dir)[0]["bundle_id"] == first
    assert run("package", "--dir", str(draft_dir), "--force") == 0
    assert bundle.read_package(draft_dir)[0]["bundle_id"] != first


def test_package_refuses_unsigned_artifacts_and_sign_fixes_them(tmp_path, monkeypatch, capsys):
    """Entrenar sin clave deja artefactos sin firma: no se empaquetan hasta firmarlos (ADR-V25-20)."""
    unsigned = signing.SigningConfig(key_id=None, private_key=None, trusted=signing.load_config().trusted)
    directory = tmp_path / "train"
    synthetic_draft(directory, tenant_id=7, config=unsigned)
    assert run("package", "--dir", str(directory)) == 1                             # el artefacto no verifica como firmado
    assert "no verifica como firmado" in capsys.readouterr().err
    assert run("sign", "--dir", str(directory)) == 0
    assert run("package", "--dir", str(directory)) == 0
    assert run("verify", "--dir", str(directory), "--require-package") == 0
    assert all(m["signature"]["status"] == "signed" for m in read(directory / bundle.TRAIN_FILE)["models"])


def test_package_with_sign_flag_signs_what_is_missing(tmp_path):
    unsigned = signing.SigningConfig(key_id=None, private_key=None)
    directory = tmp_path / "train"
    synthetic_draft(directory, tenant_id=7, config=unsigned)
    assert run("package", "--dir", str(directory), "--sign") == 0


def test_sign_and_package_need_a_private_key(draft_dir, monkeypatch, capsys):
    monkeypatch.delenv("ENGINE_SIGNING_PRIVATE_KEY")
    monkeypatch.delenv("ENGINE_SIGNING_KEY_ID")
    assert run("sign", "--dir", str(draft_dir)) == 3                                # configuración
    assert run("package", "--dir", str(draft_dir)) == 3
    err = capsys.readouterr().err
    assert "ENGINE_SIGNING_PRIVATE_KEY" in err


def test_sign_refuses_an_artifact_that_changed_after_training(draft_dir):
    path = draft_dir / "artifacts" / "h7_probability_reference.joblib"
    path.write_bytes(path.read_bytes() + b"x")
    assert run("sign", "--dir", str(draft_dir), "--force") == 6                     # el archivo ya no es el entrenado


def test_a_gate_that_is_not_usable_blocks_packaging(tmp_path, capsys):
    directory = tmp_path / "train"
    synthetic_draft(directory, tenant_id=7, gate="BLOCK")
    assert run("package", "--dir", str(directory)) == 2
    assert "gate de datos" in capsys.readouterr().err


def test_verify_detects_every_kind_of_tampering_without_deserializing(draft_dir, capsys):
    assert run("package", "--dir", str(draft_dir)) == 0
    capsys.readouterr()
    artifact = draft_dir / "artifacts" / "h14_probability_reference.joblib"
    original = artifact.read_bytes()

    artifact.write_bytes(original + b"x")                                           # artefacto alterado
    assert run("verify", "--dir", str(draft_dir), "--require-package") == 1
    out = json.loads(capsys.readouterr().out)
    assert not out["ok"] and "artifact_sha256_mismatch" in out["reasons"]
    artifact.write_bytes(original)

    sidecar = signing.manifest_path(artifact)                                       # manifiesto del artefacto borrado = sin firma
    saved = sidecar.read_bytes()
    sidecar.unlink()
    assert run("verify", "--dir", str(draft_dir), "--require-package") == 1
    assert "artifact_unsigned" in json.loads(capsys.readouterr().out)["reasons"]
    sidecar.write_bytes(saved)

    package = draft_dir / bundle.PACKAGE_FILE                                       # paquete alterado: la firma ya no corresponde
    envelope = read(package)
    envelope["manifest"] = envelope["manifest"].replace('"core":"customer"', '"core":"demand"')
    package.write_text(json.dumps(envelope))
    assert run("verify", "--dir", str(draft_dir), "--require-package") == 1
    assert json.loads(capsys.readouterr().out)["package"] == "package_signature_invalid"


def test_verify_rejects_a_package_of_another_company_and_an_untrusted_key(draft_dir, monkeypatch, capsys):
    assert run("package", "--dir", str(draft_dir)) == 0
    capsys.readouterr()
    assert run("verify", "--dir", str(draft_dir), "--tenant-id", "8") == 1
    assert json.loads(capsys.readouterr().out)["package"] == "package_tenant_mismatch"
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", "otra:" + signing.generate_keypair("otra")[2])
    assert run("verify", "--dir", str(draft_dir), "--require-package") == 1
    assert json.loads(capsys.readouterr().out)["package"] == "package_untrusted_key"


def test_verify_without_a_package_checks_the_draft_artifacts(draft_dir, capsys):
    assert run("verify", "--dir", str(draft_dir)) == 0
    assert json.loads(capsys.readouterr().out)["package"] == "absent"
    assert run("verify", "--dir", str(draft_dir), "--require-package") == 1


def test_missing_directory_is_not_found(tmp_path, capsys):
    assert run("verify", "--dir", str(tmp_path / "no-existe")) == 5
    assert "train.json" in capsys.readouterr().err


def test_train_does_not_overwrite_an_existing_output_directory(draft_dir):
    """`save_training` se niega a escribir sobre un entrenamiento previo."""
    class Dummy:
        pass

    with pytest.raises(bundle.CliError) as caught:
        bundle.save_training(draft_dir, Dummy(), "customer")
    assert caught.value.code == 6


def test_sanitize_makes_values_json_safe():
    import numpy as np

    out = bundle._sanitize({"a": float("nan"), "b": np.float64(1.5), "c": np.int64(3), "d": {1: float("inf")}, "e": (1, 2), "f": np.bool_(True)})
    assert out == {"a": None, "b": 1.5, "c": 3, "d": {"1": None}, "e": [1, 2], "f": True}
    json.dumps(out, allow_nan=False)
    assert os.name == "posix"
