"""Regla única de ubicación de artefactos (P8): `artifact_path` es relativo a ENGINE_ARTIFACTS_DIR, con separador `/`, para que el
backend (otro servicio con el volumen montado en otra ruta) pueda resolverlo y verificar el SHA-256."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path

from predictily_engine.config import get_settings
from predictily_engine.customer.service import _artifact_compat, _save_artifact as save_customer_artifact, load_artifact
from predictily_engine.pipeline.forecast_run import _save_artifact as save_demand_artifact
from predictily_engine.platform.artifacts import resolve_stored_path, to_stored_path


def _root() -> Path:
    return Path(get_settings().artifacts_dir)


def test_demand_and_customer_artifacts_are_stored_relative_to_the_artifacts_dir():
    d_path, d_sha = save_demand_artifact(7, 11, "lightgbm_global", {"x": 1})
    c_path, c_sha = save_customer_artifact(7, 12, "h30_probability_logistic", {"y": 2})
    assert d_path == "7/run-11/lightgbm_global.joblib"
    assert c_path == "7/customer/run-12/h30_probability_logistic.joblib"
    for stored, sha in ((d_path, d_sha), (c_path, c_sha)):
        assert not os.path.isabs(stored) and ".." not in stored and "\\" not in stored
        assert not stored.startswith(str(_root())), "no incluye el directorio de artefactos: es lo que el backend resuelve con el suyo"
        assert hashlib.sha256((_root() / stored).read_bytes()).hexdigest() == sha
        assert resolve_stored_path(stored) == _root() / stored


def test_resolution_still_reads_rows_stored_with_the_previous_rules(tmp_path, monkeypatch):
    real = _root() / "9" / "legacy.joblib"
    real.parent.mkdir(parents=True, exist_ok=True)
    real.write_bytes(b"x")
    assert resolve_stored_path(str(real)) == real                              # absoluta (tal como se guardaba en Docker)
    # relativa al directorio de trabajo del motor e incluyendo el directorio (`storage/artifacts/9/...`)
    monkeypatch.chdir(tmp_path)
    legacy = Path("storage/artifacts/9/old.joblib")
    legacy.parent.mkdir(parents=True)
    legacy.write_bytes(b"y")
    assert resolve_stored_path("storage/artifacts/9/old.joblib").read_bytes() == b"y"
    assert to_stored_path(real) == "9/legacy.joblib"


def test_load_artifact_resolves_the_relative_path_and_verifies_the_sha256():
    stored, sha = save_customer_artifact(8, 3, "h7_probability_reference", {"model": "ok"})
    row = {"artifact_path": stored, "artifact_sha256": sha, "artifact_compat": _artifact_compat()}
    art, reason = load_artifact(row)
    assert reason is None and art == {"model": "ok"}
    assert load_artifact({**row, "artifact_sha256": "0" * 64}) == (None, "artifact_sha256_mismatch")
    assert load_artifact({**row, "artifact_path": "8/customer/run-3/no-existe.joblib"}) == (None, "artifact_missing")

