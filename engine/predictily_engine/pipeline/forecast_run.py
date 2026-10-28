"""Ejecución de un forecast_run creado por Laravel (endpoint /v1/forecast-runs/{id}/execute).

1. Valida que run y training_job pertenezcan a la empresa indicada (404 si no).
2. Idempotencia: un run completado devuelve su resumen; uno en ejecución reciente → 409.
3. Carga datos de UNA empresa, ejecuta el pipeline puro y persiste todo en una transacción.
4. Ante error, marca run y job como failed con un mensaje comprensible.
"""

from __future__ import annotations

import hashlib
import json
import logging
import time
from datetime import date, datetime, timezone
from importlib import metadata
from pathlib import Path
from typing import Any

import joblib
import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.config import FEATURE_SET_VERSION, get_settings
from predictily_engine.db.repository import TenantRepository
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import (
    ProgressReporter,
    copy_forecast_values,
    ensure_feature_registry,
    insert_quality_report,
    jdump,
    mark_failed,
    mark_running,
    none_if_nan,
    upsert_alert,
    upsert_series_profile,
)
from predictily_engine.features.builder import feature_registry
from predictily_engine.models.registry import FAMILIES
from predictily_engine.pipeline.core import InsufficientDataError, PipelineResult, run_pipeline
from predictily_engine.pipeline.data import load_tenant_data
from predictily_engine.pipeline.errors import ConflictError, NotFoundError
from predictily_engine.pipeline.schemas import ExecuteRequest

log = logging.getLogger(__name__)

LIBRARIES = ["statsforecast", "mlforecast", "lightgbm", "pandas", "numpy", "scikit-learn", "holidays"]
MAX_STOCKOUT_ALERTS = 30


def library_versions() -> dict[str, str]:
    out = {}
    for lib in LIBRARIES:
        try:
            out[lib] = metadata.version(lib)
        except metadata.PackageNotFoundError:  # pragma: no cover
            out[lib] = "n/d"
    from predictily_engine import __version__
    out["predictily_engine"] = __version__
    return out


def _json(v: Any) -> Any:
    if isinstance(v, str):
        try:
            return json.loads(v)
        except ValueError:
            return v
    return v


def _response(run: dict[str, Any], duration_ms: int | None = None) -> dict[str, Any]:
    summary = _json(run.get("summary")) or {}
    metrics = summary.get("metrics", {})
    return {
        "run_id": int(run["id"]),
        "status": run["status"],
        "series_count": int(summary.get("series_count") or 0),
        "cutoff_date": run.get("cutoff_date"),
        "selection": _json(run.get("selection")) or {},
        "metrics": {"selected_wape": metrics.get("selected_wape"), "baseline_wape": metrics.get("baseline_wape"),
                    "total_selected_wape": metrics.get("total_selected_wape"),
                    "total_baseline_wape": metrics.get("total_baseline_wape")},
        "warnings": summary.get("warnings", []),
        "duration_ms": int(duration_ms if duration_ms is not None else summary.get("duration_ms") or 0),
    }


def claim_run(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, stale_seconds: int) -> dict | None:
    """Toma el run para ejecutarlo. Devuelve el run si ya estaba completado (idempotencia)."""
    repo = TenantRepository(conn, tenant_id)
    run = conn.execute(text(
        "SELECT * FROM forecast_runs WHERE id = :run_id AND tenant_id = :tenant_id FOR UPDATE"
    ), {"run_id": run_id, "tenant_id": tenant_id}).mappings().first()
    if run is None:
        raise NotFoundError("El forecast_run no existe para esta empresa.")
    if job_id is not None:
        job = repo.training_job(job_id)
        if job is None or (job["forecast_run_id"] is not None and int(job["forecast_run_id"]) != run_id):
            raise NotFoundError("El training_job no existe para esta empresa o no corresponde al run.")
    run = dict(run)
    if run["status"] == "completed":
        return run
    if run["status"] == "running" and run.get("started_at") is not None:
        age = (datetime.now(timezone.utc) - run["started_at"]).total_seconds()
        if age < stale_seconds:
            raise ConflictError("El pronóstico ya se está ejecutando.")
    mark_running(conn, tenant_id, run_id, job_id)
    return None


def execute_forecast_run(run_id: int, req: ExecuteRequest) -> dict[str, Any]:
    settings = get_settings()
    engine = get_engine()
    tenant_id = req.tenant_id
    job_id = req.training_job_id
    t0 = time.perf_counter()
    with engine.begin() as conn:
        done = claim_run(conn, tenant_id, run_id, job_id, settings.stale_run_seconds)
    if done is not None:
        return _response(done)

    progress = ProgressReporter(engine, tenant_id, job_id)
    try:
        progress("loading_data", 3)
        with engine.connect() as conn:
            repo = TenantRepository(conn, tenant_id)
            repo.analytic_reads()
            run = repo.forecast_run(run_id) or {}
            options = _json(run.get("options")) or {}
            cutoff = req.cutoff_date or _as_date(options.get("cutoff_date")) or _as_date(run.get("cutoff_date"))
            last = repo.last_sale_date()
            if last is None:
                raise InsufficientDataError("La empresa no tiene ventas válidas importadas.")
            if cutoff is None or cutoff > last:
                cutoff = last
            data = load_tenant_data(repo, cutoff)
        load_s = time.perf_counter() - t0
        log.info("run=%s tenant=%s datos cargados en %.1fs", run_id, tenant_id, load_s)
        seed = req.options.seed if req.options.seed is not None else settings.random_seed
        result = run_pipeline(
            data, horizon=max(req.horizons), seed=seed, n_jobs=settings.n_jobs,
            min_improvement=settings.min_improvement,
            max_windows=req.options.max_backtest_windows or settings.max_backtest_windows,
            max_series_lgbm=settings.max_series_lgbm, progress=progress,
            force_families=req.options.force_models, horizons=tuple(req.horizons),
        )
        progress("persisting", 92)
        result.summary.setdefault("timings_seconds", {})["loading_data"] = round(load_s, 1)
        t_persist = time.perf_counter()
        duration_ms = int((time.perf_counter() - t0) * 1000)
        with engine.begin() as conn:
            persist_results(conn, tenant_id, run_id, job_id, result, seed, duration_ms)
        log.info("run=%s tenant=%s pipeline %.1fs, persistencia %.1fs", run_id, tenant_id,
                 result.timings.get("total", 0.0), time.perf_counter() - t_persist)
        with engine.connect() as conn:
            final = TenantRepository(conn, tenant_id).forecast_run(run_id)
        return _response(final, duration_ms)
    except InsufficientDataError as exc:
        mark_failed(engine, tenant_id, run_id, job_id, str(exc), int((time.perf_counter() - t0) * 1000))
        raise
    except Exception as exc:
        log.exception("Fallo del pipeline run=%s tenant=%s", run_id, tenant_id)
        mark_failed(engine, tenant_id, run_id, job_id,
                    f"Error interno del motor ({type(exc).__name__}): {exc}"[:2000],
                    int((time.perf_counter() - t0) * 1000))
        raise


def _as_date(v: Any) -> date | None:
    if v in (None, ""):
        return None
    if isinstance(v, datetime):
        return v.date()
    if isinstance(v, date):
        return v
    return date.fromisoformat(str(v)[:10])


def _save_artifact(tenant_id: int, run_id: int, family: str, obj: Any) -> tuple[str, str]:
    base = Path(get_settings().artifacts_dir) / str(tenant_id) / f"run-{run_id}"
    base.mkdir(parents=True, exist_ok=True)
    path = base / f"{family}.joblib"
    joblib.dump(obj, path, compress=3)
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    return str(path), digest


def persist_results(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, result: PipelineResult,
                    seed: int, duration_ms: int) -> None:
    run = conn.execute(text(
        "SELECT id, version, run_type FROM forecast_runs WHERE id = :id AND tenant_id = :tenant_id"
    ), {"id": run_id, "tenant_id": tenant_id}).mappings().one()
    production = run["run_type"] == "production"
    upsert_series_profile(conn, tenant_id, result.panel.profile)
    ensure_feature_registry(conn, feature_registry())

    # ── Registro de modelos ──
    if production:
        conn.execute(text(
            "UPDATE model_registry SET status = 'archived', retired_at = now(), updated_at = now() "
            "WHERE tenant_id = :tenant_id AND status IN ('production', 'fallback')"
        ), {"tenant_id": tenant_id})
    versions = library_versions()
    registry_ids: dict[str, int] = {}
    for fam, info in result.families.items():
        status = info["status"]
        notes = None
        if not production:
            notes = "Ejecución histórica (corte retroactivo): no reemplaza modelos de producción."
            status = "candidate" if status in ("production", "fallback") else status
        artifact_path = artifact_sha = None
        if fam == "lightgbm_global" and result.lgbm is not None:
            artifact_path, artifact_sha = _save_artifact(tenant_id, run_id, fam, result.lgbm.mlf)
        elif FAMILIES[fam].kind == "statistical":
            notes = (notes + " " if notes else "") + "Modelo estadístico determinista: se reentrena con la configuración registrada."
        hp = dict(info["hyperparameters"])
        if fam == "lightgbm_global" and result.lgbm is not None:
            hp = {k: v for k, v in result.lgbm.params.items()}
        classes = info.get("demand_classes") or []
        rid = conn.execute(text(
            """
            INSERT INTO model_registry (tenant_id, version, model_family, strategy_level, scope, demand_class, status,
                hyperparameters, feature_set_version, training_data_from, training_data_to, training_data_hash,
                series_count, artifact_path, artifact_sha256, library_versions, random_seed, training_job_id,
                forecast_run_id, trained_at, promoted_at, notes, created_at, updated_at)
            VALUES (:tenant_id, :version, :family, :level, 'tenant', :demand_class, :status, CAST(:hp AS jsonb), :fsv,
                :dfrom, :dto, :hash, :series_count, :apath, :asha, CAST(:libs AS jsonb), :seed, :job_id, :run_id, now(),
                CASE WHEN :promoted THEN now() END, :notes, now(), now())
            RETURNING id
            """
        ), {
            "tenant_id": tenant_id, "version": f"t{tenant_id}-r{run['version']}-{fam}", "family": fam,
            "level": info["level"], "demand_class": classes[0] if len(classes) == 1 else None, "status": status,
            "hp": jdump(hp), "fsv": FEATURE_SET_VERSION, "dfrom": result.data_from, "dto": result.data_to,
            "hash": result.training_data_hash, "series_count": info["series_count"], "apath": artifact_path,
            "asha": artifact_sha, "libs": jdump(versions), "seed": seed, "job_id": job_id, "run_id": run_id,
            "notes": notes, "promoted": status in ("production", "fallback"),
        }).scalar_one()
        registry_ids[fam] = int(rid)

    # ── Evaluaciones (backtest) ──
    if result.evaluations:
        conn.execute(text(
            """
            INSERT INTO model_evaluations (tenant_id, model_registry_id, forecast_run_id, model_family, evaluation_type,
                level, level_key, horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80,
                coverage_95, n_obs, n_windows, cutoffs, extra, created_at)
            VALUES (:tenant_id, :rid, :run_id, :model_family, :evaluation_type, :level, :level_key, :horizon_days,
                :is_baseline, :is_selected, :wape, :mae, :rmse, :bias, :coverage_80, :coverage_95, :n_obs, :n_windows,
                CAST(:cutoffs AS jsonb), CAST(:extra AS jsonb), now())
            """
        ), [{
            **{k: none_if_nan(v) for k, v in e.items() if k not in ("cutoffs",)},
            "tenant_id": tenant_id, "run_id": run_id, "rid": registry_ids.get(e["model_family"]),
            "cutoffs": jdump(e.get("cutoffs", [])),
            "extra": jdump({"composite": e["model_family"] == "selection"}),
        } for e in result.evaluations])

    # ── Valores pronosticados ──
    copy_forecast_values(conn, tenant_id, run_id, result.forecast_values, registry_ids)

    # ── Recomendaciones ──
    recs = result.recommendations
    if recs is not None and not recs.empty:
        cols = ["branch_id", "product_id", "type", "inventory_snapshot_date", "on_hand", "reserved", "in_transit",
                "available", "lead_time_days", "lead_time_source", "review_period_days", "service_level",
                "demand_lead_time", "demand_lead_time_std", "safety_stock", "reorder_point", "suggested_qty",
                "unit_cost", "estimated_investment", "days_of_cover", "stockout_probability",
                "stockout_date_estimate", "risk_level"]
        conn.execute(text(
            f"""
            INSERT INTO recommendations (tenant_id, forecast_run_id, {', '.join(cols)}, status, assumptions,
                created_at, updated_at)
            VALUES (:tenant_id, :run_id, {', '.join(':' + c for c in cols)}, 'open', CAST(:assumptions AS jsonb),
                now(), now())
            """
        ), [{**{c: none_if_nan(r[c]) for c in cols}, "tenant_id": tenant_id, "run_id": run_id,
             "branch_id": int(r["branch_id"]), "product_id": int(r["product_id"]),
             "assumptions": jdump(r["assumptions"])} for r in recs.to_dict("records")])

    # ── Calidad de datos ──
    dq_id = insert_quality_report(conn, tenant_id, result.readiness, run_id)

    # ── Alertas (solo ejecuciones de producción) ──
    if production:
        _alerts(conn, tenant_id, run_id, run["version"], result, dq_id)

    # ── Cierre del run y del job ──
    summary = dict(result.summary)
    summary["duration_ms"] = duration_ms
    summary["data_quality_report_id"] = dq_id
    summary["library_versions"] = versions
    summary["training_data_hash"] = result.training_data_hash
    selection_json = result.selection.as_json(registry_ids)
    if production:
        conn.execute(text("UPDATE forecast_runs SET is_current = false, updated_at = now() "
                          "WHERE tenant_id = :tenant_id AND is_current"), {"tenant_id": tenant_id})
    conn.execute(text(
        """
        UPDATE forecast_runs SET status = 'completed', cutoff_date = :cutoff, data_from = :dfrom, data_to = :dto,
            feature_set_version = :fsv, selection = CAST(:selection AS jsonb), summary = CAST(:summary AS jsonb),
            is_current = :current, completed_at = now(), error_message = NULL, updated_at = now()
        WHERE id = :run_id AND tenant_id = :tenant_id
        """
    ), {"cutoff": result.cutoff, "dfrom": result.data_from, "dto": result.data_to, "fsv": FEATURE_SET_VERSION,
        "selection": jdump(selection_json), "summary": jdump(summary), "current": production, "run_id": run_id,
        "tenant_id": tenant_id})
    if job_id is not None:
        main_family = max(result.families.items(), key=lambda kv: kv[1]["series_count"])[0] if result.families else None
        conn.execute(text(
            """
            UPDATE training_jobs SET status = 'succeeded', stage = NULL, progress = 100, finished_at = now(),
                duration_ms = :dur, result = CAST(:result AS jsonb), model_registry_id = :rid, updated_at = now()
            WHERE id = :id AND tenant_id = :tenant_id
            """
        ), {"dur": duration_ms, "result": jdump({"forecast_run_id": run_id, "series_count": summary["series_count"],
                                                 "metrics": summary["metrics"], "models": summary["models"],
                                                 "warnings": result.warnings}),
            "rid": registry_ids.get(main_family), "id": job_id, "tenant_id": tenant_id})


def _alerts(conn: Connection, tenant_id: int, run_id: int, version: int, result: PipelineResult, dq_id: int) -> None:
    recs = result.recommendations
    current_keys: set[str] = set()
    if recs is not None and not recs.empty:
        risky = recs[recs["risk_level"].isin(["high", "critical"])].sort_values("stockout_probability", ascending=False)
        names = {}
        rows = conn.execute(text(
            "SELECT p.id, p.name, b.id AS bid, b.name AS bname FROM products p CROSS JOIN branches b "
            "WHERE p.tenant_id = :tenant_id AND b.tenant_id = :tenant_id AND p.id = ANY(:pids) AND b.id = ANY(:bids)"
        ), {"tenant_id": tenant_id, "pids": [int(x) for x in risky["product_id"].unique()],
            "bids": [int(x) for x in risky["branch_id"].unique()]}).mappings().all()
        for r in rows:
            names[(int(r["bid"]), int(r["id"]))] = (r["name"], r["bname"])
        for r in risky.head(MAX_STOCKOUT_ALERTS).to_dict("records"):
            b, p = int(r["branch_id"]), int(r["product_id"])
            key = f"stockout_risk:{b}:{p}"
            current_keys.add(key)
            pname, bname = names.get((b, p), (f"Producto {p}", f"Sucursal {b}"))
            cover = r.get("days_of_cover")
            cover_txt = f"{cover:.1f} días de cobertura" if cover is not None and pd.notna(cover) else "sin cobertura"
            upsert_alert(
                conn, tenant_id, type_="stockout_risk",
                severity="critical" if r["risk_level"] == "critical" else "warning",
                title=f"Riesgo de quiebre: {pname} ({bname})",
                message=(f"Probabilidad estimada de agotarse antes de reponer: {r['stockout_probability']:.0%} "
                         f"({cover_txt}; plazo {int(r['lead_time_days'])} días). Compra sugerida: "
                         f"{r['suggested_qty']:.0f} unidades. Es una sugerencia: Predictily no emite pedidos."),
                payload={"forecast_run_id": run_id, "branch_id": b, "product_id": p,
                         "stockout_probability": r["stockout_probability"], "suggested_qty": r["suggested_qty"],
                         "days_of_cover": cover, "risk_level": r["risk_level"]},
                dedup_key=key, related_type="recommendation", related_id=None,
            )
    # Riesgos que ya no aplican se marcan como resueltos.
    open_keys = conn.execute(text(
        "SELECT id, dedup_key FROM alerts WHERE tenant_id = :tenant_id AND type = 'stockout_risk' AND status = 'open'"
    ), {"tenant_id": tenant_id}).mappings().all()
    stale = [int(a["id"]) for a in open_keys if a["dedup_key"] not in current_keys]
    if stale:
        conn.execute(text("UPDATE alerts SET status = 'resolved', updated_at = now() "
                          "WHERE tenant_id = :tenant_id AND id = ANY(:ids)"), {"tenant_id": tenant_id, "ids": stale})
    score = result.readiness.get("score")
    if score is not None and score < 60:
        upsert_alert(conn, tenant_id, type_="data_quality", severity="warning",
                     title="La calidad de los datos limita la precisión",
                     message=f"Puntaje de preparación de datos: {score:.0f}/100. Revise el reporte de calidad.",
                     payload={"score": score, "data_quality_report_id": dq_id,
                              "issues": [i["code"] for i in result.readiness.get("issues", [])]},
                     dedup_key="data_quality:readiness", related_type="data_quality_report", related_id=dq_id)
    m = result.summary.get("metrics", {})
    sel, base = m.get("selected_wape"), m.get("baseline_wape")
    detail = (f" Error de backtest (WAPE diario por producto-sucursal): {sel:.1%} frente a {base:.1%} del método de "
              f"referencia." if sel is not None and base is not None else "")
    upsert_alert(conn, tenant_id, type_="run_completed", severity="info",
                 title=f"Pronóstico versión {version} generado",
                 message=f"Se pronosticaron {result.summary['series_count']} series hasta 90 días.{detail}",
                 payload={"forecast_run_id": run_id, "version": version}, dedup_key="run_completed",
                 related_type="forecast_run", related_id=run_id)
