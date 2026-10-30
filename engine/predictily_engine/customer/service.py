"""Ejecución de una corrida del Customer Core creada por Laravel (`core_runs` core='customer').

- `train`: dataset de cortes → protocolo temporal por horizonte → modelos + calibración + evaluación → registro
  (`model_registry` core='customer') con artefactos joblib y sha256. Solo se promueve lo que supera la regla versionada.
- `predict`: carga los modelos en producción, recalcula las variables al día (la recencia cambia sin ventas nuevas),
  persiste predicciones, ranking, actividad y reconciliación. No entrena.

Idempotencia (E2-18): una corrida completada devuelve su resumen; una en ejecución reciente responde 409; una vencida
se retoma reemplazando por completo sus resultados parciales (clave única por corrida), sin duplicar filas.
Aislamiento: todo se lee y escribe con `tenant_id`; los artefactos viven en `{artifacts}/{tenant}/customer/...`.
"""

from __future__ import annotations

import hashlib
import json
import logging
import time
from datetime import date, datetime, timedelta, timezone
from importlib import metadata
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

import joblib
import numpy as np
import pandas as pd
from pydantic import BaseModel, Field
from sqlalchemy import Connection, text

from predictily_engine.config import get_settings
from predictily_engine.customer import FEATURE_SCHEMA_VERSION, LABEL_DEFINITION_VERSION, SUPPORTED_HORIZONS
from predictily_engine.customer.commitments import state_as_of
from predictily_engine.customer.data import CustomerData, load_customer_data
from predictily_engine.customer.dataset import MODEL_FEATURES, build_dataset
from predictily_engine.customer.events import from_day, to_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer.outputs import evaluate_activity
from predictily_engine.customer.predict import PredictionOutput, predict_all
from predictily_engine.customer.reconcile import reconcile_horizon, reconciliation_summary
from predictily_engine.customer.train import HorizonResult, evaluate_coherence, train_horizon
from predictily_engine.db.customer_repository import CustomerRepository
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import ProgressReporter, jdump, none_if_nan
from predictily_engine.pipeline.errors import ConflictError, NotFoundError
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot

log = logging.getLogger(__name__)
LIBRARIES = ["scikit-learn", "lightgbm", "numpy", "pandas", "scipy", "joblib"]


class CustomerExecuteRequest(BaseModel):
    tenant_id: int = Field(gt=0)
    training_job_id: int | None = None


def library_versions() -> dict[str, str]:
    out = {}
    for lib in LIBRARIES:
        try:
            out[lib] = metadata.version(lib)
        except metadata.PackageNotFoundError:  # pragma: no cover
            out[lib] = "n/d"
    return out


def _as_date(v: Any) -> date | None:
    if v is None or (isinstance(v, str) and v == "") or (not isinstance(v, (list, dict)) and pd.isna(v)):
        return None
    if isinstance(v, datetime):
        return v.date()
    if isinstance(v, date):
        return v
    return date.fromisoformat(str(v)[:10])


# ───────────────────────────── control de la corrida ─────────────────────────────

def claim_run(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, stale_seconds: int) -> dict | None:
    """Toma la corrida. Devuelve la corrida si ya estaba completada (idempotencia). 404 si no es de esta empresa."""
    repo = CustomerRepository(conn, tenant_id)
    row = conn.execute(text(
        "SELECT * FROM core_runs WHERE id = :id AND tenant_id = :t AND core = 'customer' FOR UPDATE"
    ), {"id": run_id, "t": tenant_id}).mappings().first()
    if row is None:
        raise NotFoundError("La corrida de comportamiento no existe para esta empresa.")
    if job_id is not None:
        job = repo.training_job(job_id)
        if job is None or (pd.notna(job["core_run_id"]) and int(job["core_run_id"]) != run_id):
            raise NotFoundError("El training_job no existe para esta empresa o no corresponde a la corrida.")
    run = dict(row)
    if run["status"] == "completed":
        return run
    if run["status"] == "running" and run.get("started_at") is not None:
        age = (datetime.now(timezone.utc) - run["started_at"]).total_seconds()
        if age < stale_seconds:
            raise ConflictError("La corrida de comportamiento ya se está ejecutando.")
    conn.execute(text("UPDATE core_runs SET status = 'running', started_at = now(), error_message = NULL, updated_at = now() "
                      "WHERE id = :id AND tenant_id = :t"), {"id": run_id, "t": tenant_id})
    if job_id is not None:
        conn.execute(text(
            "UPDATE training_jobs SET status = 'running', started_at = COALESCE(started_at, now()), attempts = attempts + 1, "
            "progress = 1, stage = 'loading_data', error_message = NULL, updated_at = now() WHERE id = :id AND tenant_id = :t"
        ), {"id": job_id, "t": tenant_id})
    return None


def mark_run_failed(engine, tenant_id: int, run_id: int, job_id: int | None, message: str, duration_ms: int | None = None) -> None:
    message = message[:2000]
    with engine.begin() as conn:
        conn.execute(text("UPDATE core_runs SET status = 'failed', error_message = :m, completed_at = now(), is_current = false, "
                          "updated_at = now() WHERE id = :id AND tenant_id = :t"), {"m": message, "id": run_id, "t": tenant_id})
        if job_id is not None:
            conn.execute(text("UPDATE training_jobs SET status = 'failed', error_message = :m, finished_at = now(), duration_ms = :d, "
                              "updated_at = now() WHERE id = :id AND tenant_id = :t"), {"m": message, "d": duration_ms, "id": job_id, "t": tenant_id})


def _response(run: dict[str, Any], duration_ms: int | None = None) -> dict[str, Any]:
    summary = run.get("summary") or {}
    if isinstance(summary, str):
        summary = json.loads(summary)
    return {"run_id": int(run["id"]), "operation": run["operation"], "status": run["status"], "quality_status": run.get("quality_status"),
            "as_of": str(run["as_of"]) if run.get("as_of") else None, "horizons": run.get("horizons"),
            "summary": summary, "duration_ms": int(duration_ms if duration_ms is not None else (summary or {}).get("duration_ms") or 0)}


def execute_customer_run(run_id: int, req: CustomerExecuteRequest) -> dict[str, Any]:
    settings = get_settings()
    engine = get_engine()
    tenant_id, job_id = req.tenant_id, req.training_job_id
    t0 = time.perf_counter()
    with engine.begin() as conn:
        done = claim_run(conn, tenant_id, run_id, job_id, settings.stale_run_seconds)
    if done is not None:
        return _response(done)
    progress = ProgressReporter(engine, tenant_id, job_id)
    try:
        with engine.connect() as conn:
            repo = CustomerRepository(conn, tenant_id)
            run = repo.core_run(run_id)
            tenant = repo.tenant()
        cfg, cfg_version, provenance = resolve_config("customer", tenant["settings"], tenant.get("sector"))
        op = run["operation"]
        if op == "train":
            result = _run_train(engine, tenant, run, job_id, cfg, cfg_version, provenance, progress, settings, t0)
        elif op == "predict":
            result = _run_predict(engine, tenant, run, job_id, cfg, cfg_version, provenance, progress, settings, t0)
        else:
            raise ValueError(f"Operación no soportada por el Customer Core: {op}")
        return result
    except Exception as exc:
        log.exception("Fallo del Customer Core run=%s tenant=%s", run_id, tenant_id)
        mark_run_failed(engine, tenant_id, run_id, job_id, f"Error interno del motor ({type(exc).__name__}): {exc}",
                        int((time.perf_counter() - t0) * 1000))
        raise


# ───────────────────────────── utilidades de persistencia ─────────────────────────────

def _clean(v: Any) -> Any:
    if v is pd.NA or v is pd.NaT:
        return None
    v = none_if_nan(v)
    if isinstance(v, pd.Timestamp):
        return v.date()
    if isinstance(v, (list, dict)):
        return json.dumps(v, default=str)
    if isinstance(v, (np.bool_,)):
        return bool(v)
    return v


def _copy_frame(conn: Connection, table: str, df: pd.DataFrame, columns: list[str]) -> int:
    raw = conn.connection.driver_connection
    n = 0
    with raw.cursor() as cur:
        with cur.copy(f"COPY {table} ({', '.join(columns)}) FROM STDIN") as cp:
            for row in df[columns].itertuples(index=False, name=None):
                cp.write_row(tuple(_clean(v) for v in row))
                n += 1
    return n


def _save_artifact(tenant_id: int, run_id: int, name: str, obj: Any) -> tuple[str, str]:
    base = Path(get_settings().artifacts_dir) / str(tenant_id) / "customer" / f"run-{run_id}"
    base.mkdir(parents=True, exist_ok=True)
    path = base / f"{name}.joblib"
    joblib.dump(obj, path, compress=3)
    return str(path), hashlib.sha256(path.read_bytes()).hexdigest()


def _artifact_compat() -> dict[str, Any]:
    return {"feature_schema_version": FEATURE_SCHEMA_VERSION, "label_definition_version": LABEL_DEFINITION_VERSION,
            "feature_names_sha256": hashlib.sha256(json.dumps(MODEL_FEATURES).encode()).hexdigest(),
            "n_features": len(MODEL_FEATURES), "libraries": library_versions()}


def load_artifact(row: dict[str, Any]) -> tuple[dict[str, Any] | None, str | None]:
    """Carga un artefacto de producción verificando sha256 y compatibilidad. Devuelve (artefacto, motivo de rechazo)."""
    path = row.get("artifact_path")
    if not path or not Path(path).exists():
        return None, "artifact_missing"
    data = Path(path).read_bytes()
    if hashlib.sha256(data).hexdigest() != row.get("artifact_sha256"):
        return None, "artifact_sha256_mismatch"
    compat = row.get("artifact_compat") or {}
    if isinstance(compat, str):
        compat = json.loads(compat)
    cur = _artifact_compat()
    if compat.get("feature_schema_version") != cur["feature_schema_version"] or compat.get("feature_names_sha256") != cur["feature_names_sha256"]:
        return None, "feature_schema_incompatible"
    for lib in ("scikit-learn", "lightgbm"):
        a, b = (compat.get("libraries") or {}).get(lib), cur["libraries"].get(lib)
        if a and b and a.split(".")[:2] != b.split(".")[:2]:
            return None, f"{lib}_version_incompatible"
    return joblib.load(path), None


def _slot(h: int, target: str) -> str:
    return f"h{h}:{target}"


def _tenant_today(tenant: dict[str, Any]) -> date:
    tz = ZoneInfo(tenant.get("timezone") or "America/Guayaquil")
    return datetime.now(tz).date()


def _snapshot(data: CustomerData, cfg: dict[str, Any], cfg_version: str, as_of: date, extra: dict[str, Any] | None = None) -> DataSnapshot:
    snap = DataSnapshot(core="customer", as_of=as_of)
    snap.add_frame("purchases", data.purchases, ["customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount",
                                                "gross_amount", "discount_amount"], ["customer_id", "product_id", "branch_id", "ds", "currency"])
    snap.add_frame("returns", data.returns, ["customer_id", "product_id", "ds", "qty"], ["customer_id", "product_id", "ds"])
    snap.add_frame("availability", data.availability, ["branch_id", "product_id", "ds", "available"], ["branch_id", "product_id", "ds"])
    snap.add_frame("promotions", data.promotions[["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id",
                                                  "category_id"]] if len(data.promotions) else data.promotions, None, None)
    snap.add_value("config", cfg_version)
    snap.add_value("eligibility", {"exclude_generic_buyers": cfg["exclude_generic_buyers"], "generic_buyer_codes": cfg["generic_buyer_codes"],
                                   "min_relation_purchases": cfg["min_relation_purchases"],
                                   "candidate_max_recency_days": cfg["candidate_max_recency_days"], "feature_schema": FEATURE_SCHEMA_VERSION,
                                   "label_definition": LABEL_DEFINITION_VERSION})
    last = data.last_complete_day()
    snap.coverage = {"sales_complete_through": last.isoformat() if last else None,
                     "basis": "data_coverage" if data.coverage is not None and len(data.coverage) else "last_sale_date",
                     "data_through": data.through.isoformat() if data.through else None}
    for k, v in (extra or {}).items():
        snap.add_value(k, v)
    return snap


TARGET_DEFINITION = {
    "event": "purchase",
    "purchase_probability": "P(al menos una compra válida del producto por el comprador en (corte, corte+H]); excluye anuladas y notas de crédito; todas las sucursales (sin doble conteo).",
    "quantity_if_purchase": "Suma de TODAS las compras válidas de la ventana (no solo el siguiente ticket), condicionada a ≥ 1 compra. Devoluciones aparte.",
    "expected_quantity": "purchase_probability × quantity_if_purchase (incluye el cero de «no compra»). Coherente con las dos anteriores.",
    "expected_amount": "expected_quantity × último precio neto de la relación (supuesto explícito, neto de descuento, sin impuestos). No es una venta confirmada.",
    "population": "Relaciones comprador×producto con ≥1 compra previa al corte y última compra dentro de la recencia máxima; sin compradores genéricos ni ventas anónimas.",
    "availability_condition": "Probabilidades condicionadas a que el producto esté disponible en la sucursal habitual (ventanas con disponibilidad insuficiente se excluyen del entrenamiento).",
    "horizons_days": list(SUPPORTED_HORIZONS),
}

BASE_LIMITATIONS = [
    "No se ofrece ventana de próxima compra: la probabilidad binaria de H días no permite deducir una fecha (requeriría modelos de supervivencia).",
    "Compradores sin historial del producto, compradores nuevos, ventas anónimas y de consumidor final no reciben predicción individual: su demanda queda en el residual de la reconciliación.",
    "El monto esperado usa el último precio neto observado de la relación como supuesto; no incluye impuestos ni devoluciones.",
    "Datos sintéticos o de demostración comprueban el procedimiento; no validan precisión comercial.",
]


# ───────────────────────────── entrenamiento ─────────────────────────────

def _run_train(engine, tenant, run, job_id, cfg, cfg_version, provenance, progress, settings, t0) -> dict[str, Any]:
    tenant_id, run_id = int(tenant["id"]), int(run["id"])
    horizons = sorted({int(h) for h in (run.get("horizons") or cfg["horizons"]) if int(h) in SUPPORTED_HORIZONS and int(h) in cfg["horizons"]})
    if not horizons:
        raise ValueError("Ninguno de los horizontes solicitados es válido para el Customer Core (7, 30, 90).")
    seed = int((run.get("options") or {}).get("seed", cfg["seed"]))
    as_of_req = _as_date(run.get("as_of"))
    progress("loading_data", 3)
    with engine.connect() as conn:
        repo = CustomerRepository(conn, tenant_id)
        repo.base.analytic_reads()
        data = load_customer_data(repo, cfg, through=as_of_req, cap_to_complete=True)
    load_s = time.perf_counter() - t0
    last_complete = data.last_complete_day()
    results: dict[int, HorizonResult] = {}
    bundle = None
    activity_eval: dict[str, Any] = {}
    coherence: dict[str, Any] = {"evaluated": False}
    if data.purchases is None or len(data.purchases) == 0 or last_complete is None:
        for h in horizons:
            results[h] = HorizonResult(horizon=h, status="abstained",
                                       reason="La empresa no tiene compras válidas de compradores identificados.")
    else:
        fb = FeatureBuilder(data, cfg, max_horizon=max(horizons), last_day=int(to_day(last_complete)))
        progress("building_dataset", 5)
        first_sale = to_day(data.sales_first) if data.sales_first else None
        cov_from = data.complete_from()
        bundle = build_dataset(fb, cfg, tuple(horizons), last_complete=int(to_day(last_complete)),
                               first_sale_day=int(first_sale) if first_sale is not None else None,
                               coverage_from_day=int(to_day(cov_from)) if cov_from else None, progress=progress)
        n_jobs = max(1, int(settings.n_jobs))
        for i, h in enumerate(horizons):
            progress(f"training_h{h}", 30 + int(50 * i / len(horizons)))
            try:
                results[h] = train_horizon(bundle, h, cfg, seed, n_jobs)
            except Exception as exc:  # un horizonte fallido no impide los demás
                log.exception("Entrenamiento fallido h=%s tenant=%s", h, tenant_id)
                results[h] = HorizonResult(horizon=h, status="failed", reason=f"{type(exc).__name__}: {exc}"[:500])
        progress("evaluating", 82)
        coherence = evaluate_coherence(bundle, results)
        mature = [c for c in bundle.cutoffs]
        activity_eval = evaluate_activity(fb, mature, bundle.last_complete, cfg)

    progress("persisting", 92)
    served = [h for h, r in results.items() if r.status in ("trained", "reference_only")]
    snap = _snapshot(data, cfg, cfg_version, last_complete or date.today(),
                     {"horizons": horizons, "seed": seed})
    manifest = _manifest(results, bundle, data, cfg, coherence, last_complete)
    duration_ms = int((time.perf_counter() - t0) * 1000)
    quality = _train_quality(results, horizons)
    limitations = list(BASE_LIMITATIONS)
    for h, r in results.items():
        if r.status == "abstained":
            limitations.append(f"H={h}: abstención — {r.reason}")
        elif r.status == "failed":
            limitations.append(f"H={h}: el entrenamiento falló — {r.reason}")
        elif r.status == "reference_only":
            limitations.append(f"H={h}: datos insuficientes para entrenar y validar modelos complejos; se sirve la referencia calibrada (resultado degradado).")
    assumptions = [
        "Atribución a sucursal: la predicción es por comprador×producto sumando todas las sucursales (sin doble conteo); la «sucursal habitual» "
        "(la de la última compra de la relación) solo se usa para disponibilidad y calendario.",
        f"Etiquetas maduras: corte+H ≤ último día completo de ventas ({data.coverage_source() if data.sales_last else 'n/d'}).",
        "Censura: ventanas con disponibilidad < %.0f%% en la sucursal habitual se excluyen (ni negativo ni positivo)." % (100 * cfg["min_availability_share"]),
        "El holdout solo evalúa el procedimiento y aplica la regla de promoción versionada (%s)." % cfg["promotion_rule_version"],
    ]
    with engine.begin() as conn:
        snapshot_id = persist_snapshot(conn, tenant_id, snap)
        model_versions, promoted = _persist_models(conn, tenant, run, job_id, results, cfg, snapshot_id, seed, last_complete, data)
        _persist_evaluations(conn, tenant_id, run_id, results, coherence, activity_eval, model_versions)
        summary = _train_summary(results, bundle, coherence, activity_eval, duration_ms, load_s, promoted)
        conn.execute(text("UPDATE core_runs SET is_current = false WHERE tenant_id = :t AND core = 'customer' AND operation = 'train' AND is_current "
                          "AND id <> :id"), {"t": tenant_id, "id": run_id})
        conn.execute(text(
            """
            UPDATE core_runs SET status = 'completed', result_type = 'evaluation', quality_status = :quality,
                uncertainty_source = 'empirical_holdout', as_of = :as_of, horizons = CAST(:horizons AS jsonb),
                target_definition = CAST(:target AS jsonb), config_version = :cv, effective_config = CAST(:cfg AS jsonb),
                data_snapshot_id = :snap, model_versions = CAST(:mv AS jsonb), evaluation_manifest = CAST(:manifest AS jsonb),
                assumptions = CAST(:assumptions AS jsonb), limitations = CAST(:limitations AS jsonb), summary = CAST(:summary AS jsonb),
                is_current = :current, completed_at = now(), error_message = NULL, updated_at = now()
            WHERE id = :id AND tenant_id = :t
            """), {"quality": quality, "as_of": last_complete, "horizons": jdump(horizons), "target": jdump(TARGET_DEFINITION),
                   "cv": cfg_version, "cfg": jdump({"config": cfg, "provenance": provenance}), "snap": snapshot_id,
                   "mv": jdump(model_versions), "manifest": jdump(manifest), "assumptions": jdump(assumptions),
                   "limitations": jdump(limitations), "summary": jdump(summary), "current": run.get("run_type", "production") == "production",
                   "id": run_id, "t": tenant_id})
        if job_id is not None:
            conn.execute(text(
                "UPDATE training_jobs SET status = 'succeeded', stage = NULL, progress = 100, finished_at = now(), duration_ms = :d, "
                "result = CAST(:r AS jsonb), updated_at = now() WHERE id = :id AND tenant_id = :t"
            ), {"d": duration_ms, "r": jdump({"core_run_id": run_id, "operation": "train", "quality_status": quality,
                                              "horizons": {str(h): results[h].status for h in results}}), "id": job_id, "t": tenant_id})
    with engine.connect() as conn:
        final = CustomerRepository(conn, tenant_id).core_run(run_id)
    return _response(final, duration_ms)


def _train_quality(results: dict[int, HorizonResult], horizons: list[int]) -> str:
    ok = [r for r in results.values() if r.status in ("trained", "reference_only")]
    if not ok:
        return "insufficient_data"
    degraded = len(ok) < len(horizons) or any(
        r.status == "reference_only" or r.prob.get("served", {}).get("calibration_status") != "calibrated"
        or r.prob.get("served", {}).get("role") == "fallback" for r in ok)
    return "degraded" if degraded else "ok"


def _manifest(results: dict[int, HorizonResult], bundle, data: CustomerData, cfg: dict[str, Any], coherence: dict[str, Any],
              last_complete: date | None) -> dict[str, Any]:
    return {
        "protocol_version": "cust-protocol-2026.10-v1",
        "block_order": ["train", "calibration", "selection", "holdout"],
        "separation": "Entre bloques consecutivos hay H días de separación: las ventanas de resultado no se solapan entre bloques.",
        "labels_mature_through": last_complete.isoformat() if last_complete else None,
        "coverage_source": data.coverage_source() if data.sales_last else None,
        "cutoff_step_days_configured": cfg["cutoff_step_days"],
        "cutoff_step_days_effective": bundle.effective_step if bundle else None,
        "cutoffs": bundle.meta if bundle else None,
        "dataset_label_counts": {str(h): r.manifest.get("label_counts") for h, r in results.items()},
        "horizons": {str(h): {"status": r.status, "reason": r.reason, **{k: v for k, v in r.manifest.items() if k != "label_counts"}}
                     for h, r in results.items()},
        "coherence": coherence,
        "promotion_rule_version": cfg["promotion_rule_version"],
        "final_holdout_policy": "El holdout no participa en la elección de modelo, hiperparámetros, variables, calibrador ni umbrales.",
        "overlapping_origins": "Cortes con paso menor que H comparten parte de su ventana de resultado: las filas no son independientes.",
    }


def _train_summary(results, bundle, coherence, activity_eval, duration_ms, load_s, promoted) -> dict[str, Any]:
    horizons: dict[str, Any] = {}
    for h, r in results.items():
        item: dict[str, Any] = {"status": r.status, "reason": r.reason, "tier": r.manifest.get("tier"),
                                "duration_s": round(r.duration_s, 1)}
        if r.prob:
            sv, ref = r.prob["served"], r.prob["reference"]
            item["probability"] = {
                "served": sv["family"], "role": sv["role"], "calibration_status": sv["calibration_status"],
                "brier": sv["holdout"]["brier"], "log_loss": sv["holdout"]["log_loss"], "pr_auc": sv["holdout"]["pr_auc"],
                "ece": sv["holdout"]["ece"], "reference_brier": ref["holdout"]["brier"], "reference_log_loss": ref["holdout"]["log_loss"],
                "n_holdout": sv["holdout"]["n"], "n_holdout_positive": sv["holdout"]["n_positive"],
                "promotion_passed": r.prob["gate"]["passed"]}
            q = r.qty["served"]
            item["quantity"] = {"served": q["objective"], "mae": q["holdout"]["mae"], "bias": q["holdout"]["bias"],
                                "reference_mae": r.qty["reference"]["holdout"]["mae"], "n": q["holdout"]["n"],
                                "promotion_passed": r.qty["gate"]["passed"]}
            item["expected_quantity"] = {"served_bias": r.holdout["expected_quantity"]["served"]["bias"],
                                         "reference_bias": r.holdout["expected_quantity"]["reference"]["bias"]}
        horizons[str(h)] = item
    return {"horizons": horizons, "promoted": promoted, "coherence": {k: v for k, v in coherence.items() if k in ("evaluated", "rule", "pairs")},
            "dataset": bundle.meta if bundle else None, "duration_ms": duration_ms, "loading_s": round(load_s, 1),
            "inactivity": (activity_eval.get("inactivity") or {}).get("by_status") if activity_eval else None}


def _upsert_registry(conn: Connection, tenant_id: int, version: str, family: str, status: str, role: str, hp: dict[str, Any],
                     path: str | None, sha: str | None, run_id: int, job_id: int | None, trained_to: date | None, rows: int,
                     seed: int, notes: str | None, snapshot_hash: str | None, promote: bool) -> int:
    return int(conn.execute(text(
        """
        INSERT INTO model_registry (tenant_id, version, model_family, strategy_level, scope, status, hyperparameters, feature_set_version,
            training_data_to, training_data_hash, series_count, artifact_path, artifact_sha256, library_versions, random_seed,
            training_job_id, trained_at, promoted_at, notes, created_at, updated_at, core, role, core_run_id, validated_at, artifact_compat)
        VALUES (:t, :version, :family, 'B', 'tenant', CAST(:status AS varchar), CAST(:hp AS jsonb), :fsv, :to, :hash, :rows, :path, :sha,
            CAST(:libs AS jsonb), :seed, :job, now(), CASE WHEN :promote THEN now() END, :notes, now(), now(), 'customer', :role, :run,
            CASE WHEN CAST(:status AS varchar) IN ('validated', 'production') THEN now() END, CAST(:compat AS jsonb))
        ON CONFLICT (tenant_id, version) DO UPDATE SET status = EXCLUDED.status, role = EXCLUDED.role,
            hyperparameters = EXCLUDED.hyperparameters, artifact_path = EXCLUDED.artifact_path, artifact_sha256 = EXCLUDED.artifact_sha256,
            artifact_compat = EXCLUDED.artifact_compat, training_job_id = EXCLUDED.training_job_id, trained_at = now(),
            promoted_at = EXCLUDED.promoted_at, notes = EXCLUDED.notes, validated_at = EXCLUDED.validated_at, retired_at = NULL,
            updated_at = now()
        RETURNING id
        """), {"t": tenant_id, "version": version, "family": family[:40], "status": status, "hp": jdump(hp), "fsv": FEATURE_SCHEMA_VERSION,
               "to": trained_to, "hash": snapshot_hash, "rows": rows, "path": path, "sha": sha, "libs": jdump(library_versions()),
               "seed": seed, "job": job_id, "promote": promote, "notes": notes, "role": role, "run": run_id,
               "compat": jdump(_artifact_compat())}).scalar_one())


def _persist_models(conn: Connection, tenant: dict[str, Any], run: dict[str, Any], job_id: int | None,
                    results: dict[int, HorizonResult], cfg: dict[str, Any], snapshot_id: int, seed: int,
                    last_complete: date | None, data: CustomerData) -> tuple[dict[str, Any], list[str]]:
    """Registra modelos y promueve SOLO los servidos que superaron el protocolo (y solo en corridas de producción)."""
    tenant_id, run_id = int(tenant["id"]), int(run["id"])
    version_no = int(run["version"])
    production_run = run.get("run_type", "production") == "production"
    snap_hash = conn.execute(text("SELECT hash FROM data_snapshots WHERE id = :i AND tenant_id = :t"), {"i": snapshot_id, "t": tenant_id}).scalar()
    versions: dict[str, Any] = {}
    promoted: list[str] = []
    for h, r in sorted(results.items()):
        entry: dict[str, Any] = {"status": r.status}
        if r.status == "failed":
            _upsert_registry(conn, tenant_id, f"t{tenant_id}-customer-v{version_no}-h{h}-failed", "customer_failed", "failed", "primary",
                             {"slot": _slot(h, "probability"), "horizon_days": h}, None, None, run_id, job_id, last_complete, 0, seed,
                             (r.reason or "")[:500], snap_hash, False)
            versions[str(h)] = entry
            continue
        if r.status == "abstained":
            versions[str(h)] = entry
            continue
        trained_rows = sum(r.manifest.get("block_rows", {}).get(k, 0) for k in ("train",)) or sum((r.manifest.get("block_rows") or {}).values())
        for target, label in (("probability", "prob"), ("quantity", "qty")):
            art_served = r.artifacts[label]
            art_ch = r.artifacts.get(f"{label}_challenger")
            info = r.prob if target == "probability" else r.qty
            fam_key = "family" if target == "probability" else "objective"
            to_store = [(art_served, True)]
            if art_ch is not None and art_ch is not art_served:
                to_store.append((art_ch, False))
            art_ref = r.artifacts.get(f"{label}_reference")
            if art_ref is not None and art_ref is not art_served:
                to_store.append((art_ref, False))  # respaldo validado: se usa si falla el artefacto en producción
            slot_models: list[dict[str, Any]] = []
            for art, is_served in to_store:
                family = art[fam_key] if fam_key in art else art["objective"]
                art["meta"] = _artifact_meta(r, target, family, is_served)
                name = f"h{h}_{target}_{family}"
                path, sha = _save_artifact(tenant_id, run_id, name, art)
                served_role = "primary" if art["kind"] == "complex" else "fallback"
                if is_served and production_run:
                    status = "production"
                elif is_served:
                    status = "validated"
                else:
                    status = "validated"
                version = f"t{tenant_id}-customer-v{version_no}-h{h}-{target}-{family}"
                hp = {"slot": _slot(h, target), "horizon_days": h, "target": target, "family": family, "tier": r.manifest.get("tier"),
                      "kind": art["kind"], "served": bool(is_served), "calibration_method": art.get("calibrator").method if art.get("calibrator") else None,
                      "gate": info.get("gate"), "neg_rate": art.get("neg_rate"), "scale": art.get("scale"),
                      "holdout": (info["served"]["holdout"] if is_served else (info.get("challenger") or {}).get("holdout")) if info else None}
                rid = _upsert_registry(conn, tenant_id, version, f"customer_{target}_{family}", status, served_role, hp,
                                       path, sha, run_id, job_id, last_complete, int(trained_rows), seed,
                                       None if (is_served or art["kind"] == "reference") else
                                       "Evaluado y validado; no superó la regla de promoción (%s)." % cfg["promotion_rule_version"],
                                       snap_hash, status == "production")
                slot_models.append({"id": rid, "version": version, "family": family, "status": status, "served": is_served})
                if status == "production":
                    # retira la producción anterior de ESTE horizonte y objetivo
                    conn.execute(text(
                        "UPDATE model_registry SET status = 'retired', retired_at = now(), updated_at = now() "
                        "WHERE tenant_id = :t AND core = 'customer' AND status = 'production' AND id <> :id "
                        "AND hyperparameters->>'slot' = :slot"), {"t": tenant_id, "id": rid, "slot": _slot(h, target)})
                    promoted.append(f"{_slot(h, target)}:{family}")
            entry[target] = slot_models
        versions[str(h)] = entry
    return versions, promoted


def _artifact_meta(r: HorizonResult, target: str, family: str, served: bool) -> dict[str, Any]:
    info = r.prob if target == "probability" else r.qty
    meta: dict[str, Any] = {"horizon_days": r.horizon, "target": target, "family": family, "served": served, "tier": r.manifest.get("tier"),
                            "trained_with_blocks": r.manifest.get("blocks"), "promotion_rule_version": (info.get("gate") or {}).get("rule_version")}
    if target == "probability" and info.get("served"):
        meta["calibration_status"] = info["served"]["calibration_status"]
        meta["holdout_ece"] = info["served"]["holdout"].get("ece")
    return meta


def _metric_columns(m: dict[str, Any] | None) -> dict[str, Any]:
    m = m or {}
    return {"mae": m.get("mae"), "rmse": m.get("rmse"), "bias": m.get("bias"), "wape": m.get("wape"), "n_obs": int(m.get("n") or 0)}


def _persist_evaluations(conn: Connection, tenant_id: int, run_id: int, results: dict[int, HorizonResult], coherence: dict[str, Any],
                         activity_eval: dict[str, Any], model_versions: dict[str, Any]) -> None:
    conn.execute(text("DELETE FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r AND core = 'customer'"), {"t": tenant_id, "r": run_id})
    rows: list[dict[str, Any]] = []

    def add(family: str, etype: str, key: str, h: int | None, metrics: dict[str, Any], baseline: bool, selected: bool,
            cols: dict[str, Any] | None = None, extra: dict[str, Any] | None = None, reg: int | None = None) -> None:
        c = cols or {}
        rows.append({"t": tenant_id, "reg": reg, "family": family[:40], "etype": etype[:20], "key": key[:80], "h": h,
                     "bl": baseline, "sel": selected, "wape": c.get("wape"), "mae": c.get("mae"), "rmse": c.get("rmse"),
                     "bias": c.get("bias"), "c80": c.get("coverage_80"), "n": int(c.get("n_obs") or 0),
                     "extra": jdump(extra or {}), "metrics": jdump(metrics), "run": run_id})

    for h, r in sorted(results.items()):
        if r.status not in ("trained", "reference_only"):
            continue
        reg = model_versions.get(str(h), {})
        pm = {m["family"]: m["id"] for m in reg.get("probability", [])}
        qm = {m["family"]: m["id"] for m in reg.get("quantity", [])}
        key = f"h{h}:probability"
        sv, ref = r.prob["served"], r.prob["reference"]
        same = sv["family"] == "reference"
        add(f"customer_prob_reference", "holdout", key, h, ref["holdout"], True, same,
            {"n_obs": ref["holdout"]["n"]}, {"calibration_method": ref["calibration_method"], "uncalibrated": ref["holdout_uncalibrated"]}, pm.get("reference"))
        if not same:
            add(f"customer_prob_{sv['family']}", "holdout", key, h, sv["holdout"], False, True, {"n_obs": sv["holdout"]["n"]},
                {"calibration_method": sv["calibration_method"], "calibration_status": sv["calibration_status"], "gate": r.prob["gate"],
                 "bootstrap": r.prob.get("brier_difference_bootstrap")}, pm.get(sv["family"]))
        elif "challenger" in r.prob:
            ch = r.prob["challenger"]
            add(f"customer_prob_{ch['family']}", "holdout", key, h, ch["holdout"], False, False, {"n_obs": ch["holdout"]["n"]},
                {"calibration_method": ch["calibration_method"], "gate": r.prob["gate"], "bootstrap": r.prob.get("brier_difference_bootstrap"),
                 "not_promoted": True}, pm.get(ch["family"]))
        for name, info in (r.prob.get("candidates") or {}).items():
            if info.get("selection"):
                add(f"customer_prob_{name}", "selection", key, h, info["selection"], name == "reference", False, {"n_obs": info["selection"]["n"]},
                    {"calibration_method": info["calibration_method"]})
        # cantidad condicionada
        qkey = f"h{h}:quantity_if_purchase"
        qs, qr = r.qty["served"], r.qty["reference"]
        qsame = qs["objective"] == "reference"
        add("customer_qty_reference", "holdout", qkey, h, qr["holdout"], True, qsame, {**_metric_columns(qr["holdout"]), "coverage_80": qr["interval"].get("coverage")},
            {"scale": qr["scale"], "interval": qr["interval"]}, qm.get("reference"))
        if not qsame:
            add(f"customer_qty_{qs['objective']}", "holdout", qkey, h, qs["holdout"], False, True,
                {**_metric_columns(qs["holdout"]), "coverage_80": qs["interval"].get("coverage")}, {"gate": r.qty.get("gate"), "interval": qs["interval"]},
                qm.get(qs["objective"]))
        for name, info in (r.qty.get("candidates") or {}).items():
            add(f"customer_qty_{name}", "selection", qkey, h, info["selection"], False, False, _metric_columns(info["selection"]), {"scale": info["scale"]})
        # esperanza incondicional y monto (incluyen compradores que no compraron)
        ek = r.holdout["expected_quantity"]
        add("customer_expected_qty_reference", "holdout", f"h{h}:expected_quantity", h, ek["reference"], True, False, _metric_columns(ek["reference"]), {"definition": ek["definition"]})
        add("customer_expected_qty_served", "holdout", f"h{h}:expected_quantity", h, ek["served"], False, True, _metric_columns(ek["served"]),
            {"definition": ek["definition"], "n_rows_non_buyers": ek["n_rows_non_buyers"]})
        am = r.holdout["expected_amount"]
        add("customer_expected_amount_reference", "holdout", f"h{h}:expected_amount", h, am["reference"], True, False, _metric_columns(am["reference"]), {"definition": am["definition"]})
        add("customer_expected_amount_served", "holdout", f"h{h}:expected_amount", h, am["served"], False, True, _metric_columns(am["served"]),
            {"definition": am["definition"], "price_assumption": am["price_assumption"]})
        add("customer_ranking", "holdout", f"h{h}:ranking", h, r.holdout["ranking"], False, True, {"n_obs": r.holdout["ranking"]["served"]["n_units"]})
    add("customer_coherence", "holdout", "coherence", None, coherence, False, True)
    if activity_eval.get("inactivity"):
        add("customer_inactivity", "holdout", "inactivity", None, activity_eval["inactivity"], False, True, {"n_obs": activity_eval["inactivity"]["n_customer_cutoffs"]})
    if activity_eval.get("frequency"):
        add("customer_frequency", "holdout", "frequency", None, activity_eval["frequency"], False, True, {"n_obs": activity_eval["frequency"]["n_customer_cutoffs"]})
    if rows:
        conn.execute(text(
            """
            INSERT INTO model_evaluations (tenant_id, model_registry_id, forecast_run_id, model_family, evaluation_type, level, level_key,
                horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80, n_obs, n_windows, extra, created_at,
                core, core_run_id, metrics)
            VALUES (:t, :reg, NULL, :family, :etype, 'overall', :key, :h, :bl, :sel, :wape, :mae, :rmse, :bias, :c80, :n, 0,
                CAST(:extra AS jsonb), now(), 'customer', :run, CAST(:metrics AS jsonb))
            """), rows)


# ───────────────────────────── predicción ─────────────────────────────

def _run_predict(engine, tenant, run, job_id, cfg, cfg_version, provenance, progress, settings, t0) -> dict[str, Any]:
    tenant_id, run_id = int(tenant["id"]), int(run["id"])
    requested = sorted({int(h) for h in (run.get("horizons") or cfg["horizons"]) if int(h) in SUPPORTED_HORIZONS and int(h) in cfg["horizons"]})
    today = _tenant_today(tenant)
    as_of_req = _as_date(run.get("as_of")) or today
    progress("loading_models", 3)
    limitations = list(BASE_LIMITATIONS)
    assumptions: list[str] = []
    with engine.connect() as conn:
        repo = CustomerRepository(conn, tenant_id)
        prod = repo.production_models()
        refs_df = repo.validated_references()
    artifacts: dict[int, dict[str, Any]] = {}
    references: dict[str, dict[str, Any]] = {}
    for row in refs_df.to_dict("records"):
        hp_ = row["hyperparameters"]
        hp_ = json.loads(hp_) if isinstance(hp_, str) else (hp_ or {})
        references.setdefault(hp_.get("slot", ""), row)
    model_versions: dict[str, Any] = {}
    rejected: list[str] = []
    slots: dict[str, dict[str, Any]] = {}
    for row in prod.to_dict("records"):
        hp = row["hyperparameters"]
        hp = json.loads(hp) if isinstance(hp, str) else (hp or {})
        slots[hp.get("slot", "")] = row
    for h in requested:
        rp, rq = slots.get(_slot(h, "probability")), slots.get(_slot(h, "quantity"))
        if rp is None or rq is None:
            rejected.append(f"H={h}: no hay modelo en producción (ejecute el entrenamiento); abstención.")
            continue
        ap, why_p = load_artifact(rp)
        aq, why_q = load_artifact(rq)
        if ap is None or aq is None:
            # el ganador falló: se sirve la referencia validada del mismo horizonte (respaldo identificado)
            refs = {}
            for target in ("probability", "quantity"):
                cand = references.get(_slot(h, target))
                refs[target] = load_artifact(cand) if cand is not None else (None, "no_validated_reference")
            if ap is None and refs["probability"][0] is not None:
                ap, rp = refs["probability"][0], references[_slot(h, "probability")]
                rejected.append(f"H={h}: el artefacto de probabilidad en producción falló ({why_p}); se sirve la referencia validada (respaldo).")
            if aq is None and refs["quantity"][0] is not None:
                aq, rq = refs["quantity"][0], references[_slot(h, "quantity")]
                rejected.append(f"H={h}: el artefacto de cantidad en producción falló ({why_q}); se sirve la referencia validada (respaldo).")
        if ap is None or aq is None:
            rejected.append(f"H={h}: artefacto no utilizable ({why_p or why_q}); abstención.")
            continue
        artifacts[h] = {"prob": ap, "qty": aq}
        model_versions[str(h)] = {"probability": rp["version"], "quantity": rq["version"], "probability_id": int(rp["id"]),
                                  "quantity_id": int(rq["id"]), "role": rp["role"]}
    limitations += rejected
    progress("loading_data", 10)
    with engine.connect() as conn:
        repo = CustomerRepository(conn, tenant_id)
        repo.base.analytic_reads()
        data = load_customer_data(repo, cfg, through=as_of_req)
        coverage_declared = data.coverage is not None and len(data.coverage) > 0
        complete = data.last_complete_day() if coverage_declared else None
        commit_cur = repo.commitments() if artifacts else pd.DataFrame()
        commit_rev = repo.commitment_revisions() if artifacts and as_of_req < today else pd.DataFrame()
        sku_map = repo.product_skus() if artifacts else {}
    # fecha efectiva de las variables: si la cobertura declarada termina antes, los días siguientes son AUSENTES (no cero)
    as_of = as_of_req
    stale = False
    if complete is not None and complete < as_of_req:
        as_of = complete
        stale = True
        limitations.append(f"Datos de ventas completos solo hasta {complete.isoformat()} (cobertura declarada): las variables se calculan a esa fecha; "
                           f"los días posteriores son ausentes, no cero.")
    elif complete is None and data.sales_last is not None and (as_of_req - data.sales_last).days > int(cfg["stale_data_days"]):
        stale = True
        limitations.append(f"Última venta registrada: {data.sales_last.isoformat()} ({(as_of_req - data.sales_last).days} días antes de la fecha de predicción) y "
                           f"no hay cobertura declarada: la recencia puede estar sobreestimada si la carga de datos se retrasó.")
    if complete is None:
        assumptions.append("Cobertura de ventas no declarada: se asume completa hasta la fecha de predicción.")
    quality = "insufficient_data"
    out: PredictionOutput | None = None
    rec_all: list[pd.DataFrame] = []
    forecast_run_id = None
    if artifacts and data.purchases is not None and len(data.purchases):
        as_of_day = int(to_day(as_of))
        fb = FeatureBuilder(data, cfg, max_horizon=max(artifacts), extend_to=as_of_day)
        products_active = ({int(r.product_id): bool(r.is_active) and not bool(getattr(r, "deleted", False))
                            for r in data.products.itertuples(index=False)} if len(data.products) else None)
        state = pd.DataFrame()
        if len(commit_cur):
            state = state_as_of(commit_cur, commit_rev, sku_map, as_of, today, tenant.get("timezone") or "America/Guayaquil")
        progress("predicting", 40)
        out = predict_all(fb, artifacts, as_of_day, cfg, state if len(state) else None, products_active)
        progress("reconciling", 70)
        with engine.connect() as conn:
            repo = CustomerRepository(conn, tenant_id)
            fr = repo.current_forecast_run()
            forecast_run_id = int(fr["id"]) if fr else None
            for h in out.horizons:
                pred_h = out.predictions[out.predictions["horizon_days"] == h]
                pids = sorted(set(pred_h["product_id"]) | set(out.committed_relations.get(h, pd.DataFrame(columns=["product_id"]))["product_id"]))
                agg = repo.aggregate_demand(forecast_run_id, pids, as_of, h) if forecast_run_id else pd.DataFrame(columns=["product_id", "days", "yhat"])
                c_rel = out.committed_relations.get(h, pd.DataFrame(columns=["customer_id", "product_id", "committed_quantity"]))
                keyset = set(zip(pred_h["customer_id"], pred_h["product_id"]))
                if len(c_rel):
                    outside = c_rel[[not (pd.notna(c) and (int(c), int(p)) in keyset) for c, p in zip(c_rel["customer_id"], c_rel["product_id"])]]
                else:
                    outside = c_rel
                rec = reconcile_horizon(pred_h[["product_id", "expected_quantity", "committed_quantity"]], outside[["product_id", "committed_quantity"]], agg, h,
                                        float(cfg["reconciliation_tolerance"]))
                rec_all.append(rec)
        if not fr:
            limitations.append("No hay una ejecución de demanda agregada vigente: la reconciliación queda sin agregado (`aggregate_unavailable`).")
        served_cal = [out.served[h]["calibration_status"] for h in out.horizons]
        fallback = any(out.served[h]["role"] == "fallback" for h in out.horizons)
        if len(out.horizons) == len(requested) and not stale and all(s == "calibrated" for s in served_cal) and not fallback:
            quality = "ok"
        else:
            quality = "degraded"
        for h in out.horizons:
            if out.served[h]["role"] == "fallback":
                limitations.append(f"H={h}: se sirve la referencia frecuencia/recencia (rol fallback): el modelo complejo no superó la regla de promoción o no hubo datos para validarlo.")
            if out.served[h]["calibration_status"] != "calibrated":
                limitations.append(f"H={h}: calibración en estado «{out.served[h]['calibration_status']}» en el holdout.")
        if not out.horizons:
            quality = "insufficient_data"
    elif artifacts:
        limitations.append("La empresa no tiene compras válidas de compradores identificados: abstención.")
    duration_ms_pre = int((time.perf_counter() - t0) * 1000)
    progress("persisting", 90)
    snap = _snapshot(data, cfg, cfg_version, as_of, {"models": {h: v.get("probability_id") for h, v in model_versions.items()},
                                                    "quantity_models": {h: v.get("quantity_id") for h, v in model_versions.items()},
                                                    "commitments_rows": int(len(commit_cur)), "as_of_requested": as_of_req.isoformat()})
    horizons_out = sorted(out.horizons) if out is not None else []
    summary: dict[str, Any] = {"horizons": horizons_out, "as_of_requested": as_of_req.isoformat(), "as_of_effective": as_of.isoformat(), "stale_data": stale,
                               "excluded_from_individual_prediction": data.excluded, "duration_ms": duration_ms_pre}
    if out is not None:
        summary |= {"rows": out.summary.get("rows"), "candidates": out.summary.get("candidates"), "coherence": out.summary.get("coherence"),
                    "quantity_coherence": out.summary.get("quantity_coherence"), "served": out.served,
                    "committed": out.committed, "customers_with_activity": int(len(out.activity))}
        if rec_all:
            rec_df = pd.concat(rec_all, ignore_index=True)
            summary["reconciliation"] = {str(h): reconciliation_summary(rec_df[rec_df["horizon_days"] == h]) for h in horizons_out}
    with engine.begin() as conn:
        snapshot_id = persist_snapshot(conn, tenant_id, snap)
        # reemplazo total de lo escrito por un intento anterior (idempotencia ante reintentos)
        for table in ("customer_predictions", "customer_product_rankings", "customer_activity", "customer_reconciliations"):
            conn.execute(text(f"DELETE FROM {table} WHERE tenant_id = :t AND core_run_id = :r"), {"t": tenant_id, "r": run_id})
        if out is not None and len(out.predictions):
            _write_prediction_rows(conn, tenant_id, run_id, out, rec_all, forecast_run_id, as_of)
        retained = int(cfg["retain_prediction_runs"])
        for table in ("customer_predictions", "customer_product_rankings", "customer_activity", "customer_reconciliations"):
            conn.execute(text(
                f"DELETE FROM {table} WHERE tenant_id = :t AND core_run_id IN (SELECT id FROM core_runs WHERE tenant_id = :t "
                f"AND core = 'customer' AND operation = 'predict' AND id <> :r ORDER BY id DESC OFFSET :n)"), {"t": tenant_id, "r": run_id, "n": max(retained - 1, 0)})
        conn.execute(text("UPDATE core_runs SET is_current = false WHERE tenant_id = :t AND core = 'customer' AND operation = 'predict' AND is_current AND id <> :id"),
                     {"t": tenant_id, "id": run_id})
        duration_ms = int((time.perf_counter() - t0) * 1000)
        summary["duration_ms"] = duration_ms
        h_end = as_of + timedelta(days=max(horizons_out)) if horizons_out else None
        conn.execute(text(
            """
            UPDATE core_runs SET status = 'completed', result_type = 'forecast', quality_status = :quality,
                uncertainty_source = :unc, as_of = :as_of, horizon_start = :hs, horizon_end = :he, horizons = CAST(:horizons AS jsonb),
                target_definition = CAST(:target AS jsonb), config_version = :cv, effective_config = CAST(:cfg AS jsonb),
                data_snapshot_id = :snap, model_versions = CAST(:mv AS jsonb), assumptions = CAST(:assumptions AS jsonb),
                limitations = CAST(:limitations AS jsonb), summary = CAST(:summary AS jsonb), is_current = :current,
                completed_at = now(), error_message = NULL, updated_at = now()
            WHERE id = :id AND tenant_id = :t
            """), {"quality": quality, "unc": "empirical_holdout" if horizons_out else "unknown", "as_of": as_of,
                   "hs": as_of + timedelta(days=1) if horizons_out else None, "he": h_end, "horizons": jdump(horizons_out or requested),
                   "target": jdump(TARGET_DEFINITION), "cv": cfg_version, "cfg": jdump({"config": cfg, "provenance": provenance}),
                   "snap": snapshot_id, "mv": jdump(model_versions), "assumptions": jdump(assumptions + [
                       "Probabilidades calibradas con datos temporalmente separados; no se multiplican entre horizontes (P7 ≤ P30 ≤ P90 por máximo acumulado).",
                       "El comprometido (pedidos/contratos confirmados) no se vuelve a sumar a la esperanza: demanda del comprador = max(esperanza, comprometido)."]),
                   "limitations": jdump(limitations), "summary": jdump(summary),
                   "current": run.get("run_type", "production") == "production" and bool(horizons_out), "id": run_id, "t": tenant_id})
        if job_id is not None:
            conn.execute(text(
                "UPDATE training_jobs SET status = 'succeeded', stage = NULL, progress = 100, finished_at = now(), duration_ms = :d, "
                "result = CAST(:r AS jsonb), updated_at = now() WHERE id = :id AND tenant_id = :t"
            ), {"d": duration_ms, "r": jdump({"core_run_id": run_id, "operation": "predict", "quality_status": quality, "horizons": horizons_out,
                                              "rows": summary.get("rows", 0)}), "id": job_id, "t": tenant_id})
    with engine.connect() as conn:
        final = CustomerRepository(conn, tenant_id).core_run(run_id)
    return _response(final, duration_ms)


PRED_COLUMNS = ["tenant_id", "core_run_id", "customer_id", "product_id", "horizon_days", "as_of", "purchase_probability", "probability_raw",
                "probability_coherence_adjusted", "calibration_status", "quantity_if_purchase", "quantity_lo", "quantity_hi",
                "expected_quantity", "committed_quantity", "incremental_quantity", "total_view_quantity", "assumed_unit_price",
                "expected_amount", "currency", "median_interval_days", "days_since_last_purchase", "n_purchases", "model_family",
                "model_role", "quality_flags"]
ACT_COLUMNS = ["tenant_id", "core_run_id", "customer_id", "as_of", "last_purchase_date", "days_since_last_purchase", "n_purchase_days",
               "purchase_frequency_per_30d", "median_interval_days", "mean_interval_days", "interval_p75_days", "interval_p90_days",
               "n_intervals", "inactivity_risk", "inactivity_status", "behavior_change", "frequency_ratio", "behavior_p_value",
               "spend_recent", "spend_prior", "spend_ratio", "currency"]
RANK_COLUMNS = ["tenant_id", "core_run_id", "customer_id", "product_id", "horizon_days", "rank", "purchase_probability",
                "expected_quantity", "expected_amount", "currency"]
REC_COLUMNS = ["tenant_id", "core_run_id", "forecast_run_id", "product_id", "horizon_days", "aggregate_quantity", "aggregate_days",
               "buyers_total_quantity", "buyers_committed_quantity", "buyers_incremental_quantity", "buyers_expected_quantity", "n_buyers",
               "committed_outside_model", "residual_quantity", "excess_quantity", "excess_ratio", "residual_share", "status", "severity"]


def _write_prediction_rows(conn: Connection, tenant_id: int, run_id: int, out: PredictionOutput, rec_all: list[pd.DataFrame],
                           forecast_run_id: int | None, as_of: date) -> None:
    p = out.predictions.copy()
    p["tenant_id"], p["core_run_id"] = tenant_id, run_id
    for col in ("days_since_last_purchase", "n_purchases"):
        p[col] = p[col].round().astype("Int64")
    p["quality_flags"] = [json.dumps(f) if f else None for f in p["quality_flags"]]
    _copy_frame(conn, "customer_predictions", p, PRED_COLUMNS)
    r = out.rankings.copy()
    r["tenant_id"], r["core_run_id"] = tenant_id, run_id
    _copy_frame(conn, "customer_product_rankings", r, RANK_COLUMNS)
    a = out.activity.copy()
    if len(a):
        a["tenant_id"], a["core_run_id"], a["as_of"] = tenant_id, run_id, as_of
        a["days_since_last_purchase"] = a["days_since_last_purchase"].round().astype("Int64")
        a["currency"] = None
        _copy_frame(conn, "customer_activity", a, ACT_COLUMNS)
    if rec_all:
        rec = pd.concat(rec_all, ignore_index=True)
        rec["tenant_id"], rec["core_run_id"], rec["forecast_run_id"] = tenant_id, run_id, forecast_run_id
        rec["aggregate_days"] = rec["aggregate_days"].astype("Float64").round().astype("Int64")
        rec["n_buyers"] = rec["n_buyers"].astype(int)
        _copy_frame(conn, "customer_reconciliations", rec, REC_COLUMNS)
