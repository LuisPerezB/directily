"""Ayudas de las pruebas de W4-B: objetos publicados firmados, paquetes sintéticos y entorno de firma."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from predictily_engine.mlops import bundle
from predictily_engine.platform import signing
from predictily_engine.platform.object_store import ObjectStore, manifest_key, model_object_key

UUID_A = "54b8d9b9-b3f0-41c9-ad7b-ccf48d8e5a7f"
UUID_B = "0f1e2d3c-4b5a-4968-8776-655443322110"


def publish_object(store: ObjectStore, tmp: Path, *, tenant_id: int, tenant_uuid: str, core: str = "customer", version: str = "t1-pub-customer-aaaa1111-h7_probability_reference",
                   name: str = "h7_probability_reference", obj: Any = None, signed_for_tenant: int | None = None, config: signing.SigningConfig | None = None,
                   feature_schema: str | None = None) -> dict[str, Any]:
    """Firma un objeto, lo sube al almacén con la clave del servidor y devuelve una fila de registro (dict) que lo describe como PUBLICADO.

    `signed_for_tenant`: empresa que firma el manifiesto (para probar manifiestos de otra empresa); por defecto `tenant_id`.
    """
    from predictily_engine.customer.service import _artifact_compat

    work = tmp / f"stage-{version}"
    record = bundle.write_signed_artifact(obj if obj is not None else {"modelo": name, "tenant": tenant_id}, work, name,
                                          tenant_id=signed_for_tenant or tenant_id, core=core, data_fingerprint="f" * 64,
                                          feature_schema_version=feature_schema or _artifact_compat()["feature_schema_version"], libraries={"lightgbm": "4.7.0"},
                                          config=config or signing.load_config())
    key = model_object_key(tenant_uuid, core, version, record.file)
    store.put(key, (work / record.file).read_bytes())
    store.put(manifest_key(key), signing.manifest_path(work / record.file).read_bytes())
    return {"id": abs(hash(version)) % 10**6, "tenant_id": tenant_id, "tenant_uuid": tenant_uuid, "version": version, "status": "production", "role": "primary",
            "model_family": f"customer_probability_{name}", "hyperparameters": {"slot": "h7:probability", "kind": "reference"}, "artifact_path": None,
            "artifact_sha256": record.sha256, "artifact_compat": _artifact_compat(), "published_object_key": key, "published_by": 11, "published_env": "local",
            "published_at": "2026-11-01T09:00:00+00:00", "approved_at": None, "approved_by": None, "forecast_run_id": None, "core_run_id": None,
            "training_job_id": None}


def synthetic_draft(directory: Path, *, tenant_id: int = 1, core: str = "customer", n_models: int = 2, config: signing.SigningConfig | None = None,
                    gate: str = "PASS") -> dict[str, Any]:
    """Un borrador `train.json` con artefactos reales firmados, sin entrenar nada (para probar empaquetar, firmar, verificar y publicar)."""
    from predictily_engine.customer.service import _artifact_compat

    config = config or signing.load_config()
    models = []
    for i in range(n_models):
        name = f"h{7 * (i + 1)}_probability_reference"
        record = bundle.write_signed_artifact({"modelo": name}, directory / bundle.ARTIFACT_DIR, name, tenant_id=tenant_id, core=core, data_fingerprint="a" * 64,
                                              feature_schema_version=_artifact_compat()["feature_schema_version"], libraries={"lightgbm": "4.7.0"}, config=config)
        models.append({"file": record.file, "name": name, "sha256": record.sha256, "size_bytes": record.size_bytes, "signature": record.signature,
                       "registry": {"model_family": "customer_probability_reference", "role": "fallback", "strategy_level": "B", "demand_class": None,
                                    "hyperparameters": {"slot": f"h{7 * (i + 1)}:probability", "kind": "reference", "served": True}, "series_count": 0,
                                    "purpose": "Compra de un producto por comprador", "granularity": "customer_sku", "slot": f"h{7 * (i + 1)}:probability",
                                    "served": True, "notes": None, "feature_set_version": _artifact_compat()["feature_schema_version"],
                                    "training_data_to": "2026-10-31", "training_data_from": None, "training_data_hash": "a" * 64, "random_seed": 7,
                                    "library_versions": {"lightgbm": "4.7.0"}, "artifact_compat": _artifact_compat()}})
    draft = {"schema": "predictily-train-draft/1", "tenant_id": tenant_id, "core": core, "created_at": "2026-11-01T09:00:00Z", "engine_version": "0.1.0", "seed": 7,
             "elapsed_seconds": 1.0, "source": {"kind": "database"}, "data_fingerprint": "a" * 64,
             "feature_schema_version": _artifact_compat()["feature_schema_version"], "libraries": {"lightgbm": "4.7.0"},
             "data_gate": {"outcome": gate, "module": core, "rules_version": "r" * 64, "as_of": "2026-10-31", "reason_codes": []},
             "metrics": {"schema": "predictily-metrics-summary/1", "core": core, "scope": "holdout", "horizons": {"7": {"probability": {"brier": 0.17, "ece": 0.05}}}},
             "details": {}, "models": models, "signed": True, "notes": []}
    bundle.write_json_atomic(directory / bundle.TRAIN_FILE, draft)
    return draft


def read(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))
