"""Ejecución de un forecast_run creado por Laravel (endpoint /v1/forecast-runs/{id}/execute).

1. Valida que run y training_job pertenezcan a la empresa indicada (404 si no).
2. Idempotencia: un run completado devuelve su resumen; uno en ejecución reciente → 409.
3. Carga datos de UNA empresa, ejecuta el pipeline puro y persiste todo en una transacción.
4. Ante error, marca run y job como failed con un mensaje comprensible.
"""

from __future__ import annotations

import json
import logging
import time
from datetime import date, datetime, timezone
from importlib import metadata
from pathlib import Path
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.config import FEATURE_SET_VERSION, get_settings
from predictily_engine.demand.coverage import SalesCoverage
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
from predictily_engine.pipeline.errors import ConflictError, InvalidConfigurationError, NotFoundError
from predictily_engine.pipeline.schemas import ExecuteRequest
from predictily_engine.platform.artifacts import SavedArtifact, save_signed_artifact
from predictily_engine.platform.config import effective_config_record
from predictily_engine.platform.snapshot import persist_snapshot
from predictily_engine.quality.persistence import insert_run_quality_report, record_blocked_forecast_run
from predictily_engine.quality.service import resolve_policy
from predictily_engine.quality.signals import collect_signals
from predictily_engine.selection.promotion import champions_from_selection

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
        "quality_status": summary.get("quality_status"),
        "horizon_support": {h: v.get("status") for h, v in (summary.get("horizon_support") or {}).items()},
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
            # Ejecución vigente = no se pidió un corte histórico (anterior al último dato). Sigue siéndolo aunque la cobertura declarada
            # limite el corte más abajo: su período final conoce hasta HOY (la carga nocturna del último día cuenta; ADR-V25-23).
            live_run = cutoff >= last
            # Configuración efectiva (core → perfil sectorial → empresa): se valida y se registra en el run.
            tenant = repo.tenant()
            try:
                config_record = effective_config_record("demand", tenant.get("settings"), tenant.get("sector"))
            except ValueError as exc:
                raise InvalidConfigurationError(f"Configuración de demanda inválida: {exc}") from exc
            core_config = config_record["effective_config"]
            # W3-B: política de promoción (bloque `promotion`: core → perfil → empresa) y champion de cada alcance (la selección servida de
            # la ejecución de producción vigente ANTES de esta). Una configuración inválida se rechaza, no se aplica.
            try:
                promotion_record = effective_config_record("promotion", tenant.get("settings"), tenant.get("sector"))
            except ValueError as exc:
                raise InvalidConfigurationError(f"Configuración de promoción de modelos inválida: {exc}") from exc
            # Solo una ejecución de PRODUCCIÓN toma como champion lo servido por la vigente: una corrida histórica (corte pasado) se compara con el
            # baseline, porque la vigente es POSTERIOR a su corte (usarla sería mirar el futuro) y la corrida histórica no releva a nadie.
            production_run = (run.get("run_type") or "production") == "production"
            champions = load_champions(repo, production_run)
            champions_source = "previous_production_run" if champions else ("none_historical_run" if not production_run else "none")
            # Umbrales de los gates de calidad (W2-C): core → perfil → empresa; una configuración inválida se rechaza, no se aplica.
            quality_policy = resolve_policy(tenant)
            # Cobertura declarada de la fuente de ventas: el corte no la supera y lo posterior es dato ausente.
            branches = repo.branches()
            active_branches = (set(branches.loc[branches["is_active"].astype(bool), "branch_id"].astype(int))
                               if "is_active" in branches else set(branches["branch_id"].astype(int)))
            coverage = SalesCoverage.resolve(conn, tenant_id, repo.sales_coverage(), active_branches)
            cutoff = coverage.limit_cutoff(cutoff)
            data = coverage.apply(load_tenant_data(repo, cutoff, live=live_run))
            data.coverage_limitations = coverage.limitations()
            # Señales de calidad que viven en la base (informes de ingestión, alias, desfase), con el MISMO corte y conocimiento que los datos.
            quality_signals = collect_signals(conn, tenant_id, cutoff, tenant.get("timezone"), quality_policy.config,
                                              knowledge=data.promotions_known_on)
        load_s = time.perf_counter() - t0
        log.info("run=%s tenant=%s datos cargados en %.1fs", run_id, tenant_id, load_s)
        seed = req.options.seed if req.options.seed is not None else settings.random_seed
        # Valores por defecto de la configuración; el entorno del operador y las opciones de la ejecución
        # solo los sobrescriben si se indican, y quedan registrados en la configuración efectiva.
        min_improvement = settings.min_improvement if settings.min_improvement is not None \
            else core_config["min_improvement"]
        max_windows = (req.options.max_backtest_windows or settings.max_backtest_windows
                       or core_config["max_backtest_windows"])
        result = run_pipeline(
            data, horizon=max(req.horizons), seed=seed, n_jobs=settings.n_jobs,
            min_improvement=min_improvement, max_windows=max_windows,
            max_series_lgbm=settings.max_series_lgbm, progress=progress,
            force_families=req.options.force_models, horizons=tuple(req.horizons), config_record=config_record,
            quality_signals=quality_signals, promotion_record=promotion_record, champions=champions,
            champions_source=champions_source,
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
    except (InsufficientDataError, InvalidConfigurationError) as exc:
        mark_failed(engine, tenant_id, run_id, job_id, str(exc), int((time.perf_counter() - t0) * 1000))
        # W2-C: un gate BLOCK deja constancia (informe de calidad de la ejecución con sus motivos); no oculta el error original.
        record_blocked_forecast_run(engine, tenant_id, run_id, getattr(exc, "assessment", None))
        raise
    except Exception as exc:
        log.exception("Fallo del pipeline run=%s tenant=%s", run_id, tenant_id)
        mark_failed(engine, tenant_id, run_id, job_id,
                    f"Error interno del motor ({type(exc).__name__}): {exc}"[:2000],
                    int((time.perf_counter() - t0) * 1000))
        raise


def _known(v: Any) -> bool:
    """Valor numérico presente (no None/NaN): las recomendaciones admiten nulos explícitos."""
    return v is not None and not pd.isna(v)


def _as_date(v: Any) -> date | None:
    if v in (None, ""):
        return None
    if isinstance(v, datetime):
        return v.date()
    if isinstance(v, date):
        return v
    return date.fromisoformat(str(v)[:10])


def _save_signed_artifact(tenant_id: int, run_id: int, family: str, obj: Any, *, data_fingerprint: str | None = None) -> SavedArtifact:
    """Guarda el artefacto y lo FIRMA (ADR-V25-20): manifiesto Ed25519 junto al archivo; sin clave privada queda `unsigned`."""
    path = Path(get_settings().artifacts_dir) / str(tenant_id) / f"run-{run_id}" / f"{family}.joblib"
    return save_signed_artifact(obj, path, tenant_id=tenant_id, core="demand", run_id=run_id, name=family, data_fingerprint=data_fingerprint,
                                feature_schema_version=FEATURE_SET_VERSION, libraries=library_versions())


def _save_artifact(tenant_id: int, run_id: int, family: str, obj: Any) -> tuple[str, str]:
    saved = _save_signed_artifact(tenant_id, run_id, family, obj)
    return saved.stored_path, saved.sha256      # ruta relativa a ENGINE_ARTIFACTS_DIR (ver platform/artifacts.py)


# W3-A · persistencia de `model_evaluations`. Capacidad numérica de las columnas escalares (migración 2026_01_01_000700): un valor que no
# cabe (p. ej. un WAPE enorme con un denominador pequeño) se guarda NULO —nunca revienta la transacción de toda la ejecución— y la
# columna queda anotada en `extra.column_overflow`; el valor completo sigue en el jsonb `metrics` cuando la fila lo trae.
EVALUATION_COLUMN_LIMITS = {"wape": 1e6, "bias": 1e6, "mae": 1e10, "rmse": 1e10, "coverage_80": 100.0, "coverage_95": 100.0}


def _fit_evaluation_columns(e: dict[str, Any]) -> tuple[dict[str, Any], list[str]]:
    """Valores escalares de la fila con NaN/Inf → NULL y los que exceden la capacidad de su columna → NULL (con la lista de columnas)."""
    fitted, overflow = {}, []
    for k, v in e.items():
        if k in ("cutoffs", "extra", "metrics"):
            continue
        v = none_if_nan(v)
        limit = EVALUATION_COLUMN_LIMITS.get(k)
        if limit is not None and v is not None and abs(float(v)) >= limit - 1e-6:
            overflow.append(k)
            v = None
        fitted[k] = v
    return fitted, overflow


def persist_evaluations(conn: Connection, tenant_id: int, run_id: int, evaluations: list[dict[str, Any]],
                        registry_ids: dict[str, int]) -> int:
    """Inserta las filas de `model_evaluations` de una ejecución de Demanda (backtest, ventanas de selección, segmentos). Devuelve cuántas."""
    if not evaluations:
        return 0
    payload = []
    for e in evaluations:
        scalars, overflow = _fit_evaluation_columns(e)
        extra = {"composite": e["model_family"] == "selection", **(e.get("extra") or {})}   # evaluation_scope: 'holdout' | 'selection_windows'
        if overflow:
            extra["column_overflow"] = overflow
        payload.append({**scalars, "tenant_id": tenant_id, "run_id": run_id, "rid": registry_ids.get(e["model_family"]),
                        "cutoffs": jdump(e.get("cutoffs", [])), "metrics": jdump(e.get("metrics") or {}), "extra": jdump(extra)})
    conn.execute(text(
        """
        INSERT INTO model_evaluations (tenant_id, model_registry_id, forecast_run_id, model_family, evaluation_type,
            level, level_key, horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80,
            coverage_95, n_obs, n_windows, cutoffs, extra, core, metrics, created_at)
        VALUES (:tenant_id, :rid, :run_id, :model_family, :evaluation_type, :level, :level_key, :horizon_days,
            :is_baseline, :is_selected, :wape, :mae, :rmse, :bias, :coverage_80, :coverage_95, :n_obs, :n_windows,
            CAST(:cutoffs AS jsonb), CAST(:extra AS jsonb), 'demand', CAST(:metrics AS jsonb), now())
        """
    ), payload)
    return len(payload)


def factor_registry_fields(result: PipelineResult, family: str) -> tuple[str, dict[str, Any] | None]:
    """`(feature_set_version, artifact_compat.factors)` de una fila del registro de modelos (W6-E · H2). Solo el LightGBM SERVIDO con factores lleva el linaje de
    los factores (claves, versiones de definición, huella de los valores visibles) y una versión de features con su marca corta; el resto de las filas, y
    TODAS con el pipeline apagado, quedan exactamente como siempre."""
    compat = result.factor_compat if family == "lightgbm_global" and result.lgbm is not None else None
    if compat is None:
        return FEATURE_SET_VERSION, None
    return f"{FEATURE_SET_VERSION}+factors:{compat['fingerprint'][:12]}", compat


def persist_results(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, result: PipelineResult,
                    seed: int, duration_ms: int) -> None:
    run = conn.execute(text(
        "SELECT id, version, run_type FROM forecast_runs WHERE id = :id AND tenant_id = :tenant_id"
    ), {"id": run_id, "tenant_id": tenant_id}).mappings().one()
    production = run["run_type"] == "production"
    lifecycle = result.lifecycle
    labels = {} if lifecycle is None else {**lifecycle.labels.to_dict(), **lifecycle.abstain}
    upsert_series_profile(conn, tenant_id, result.panel.profile, labels)
    ensure_feature_registry(conn, feature_registry())

    # ── Registro de modelos (ADR-V2-07): estados candidate | validated | production | retired | failed + rol ──
    if production:
        # Al completar una ejecución de producción, los modelos de producción anteriores pasan a `retired`
        # (siguen disponibles para la reversión: POST /models/demand/rollback).
        conn.execute(text(
            "UPDATE model_registry SET status = 'retired', retired_at = now(), updated_at = now() "
            "WHERE tenant_id = :tenant_id AND core = 'demand' AND status IN ('production', 'fallback')"
        ), {"tenant_id": tenant_id})
    versions = library_versions()
    registry_ids: dict[str, int] = {}
    for fam, info in result.families.items():
        status, role = info["status"], info.get("role", "primary")
        notes = None
        if not production:
            notes = "Ejecución histórica (corte retroactivo): no reemplaza modelos de producción."
            if status == "production":
                status = "candidate"
        if status == "failed":
            notes = (notes + " " if notes else "") + f"Falló el entrenamiento final: {info.get('error')}"
        artifact_path = artifact_sha = artifact_signature = None
        if fam == "lightgbm_global" and result.lgbm is not None:
            saved = _save_signed_artifact(tenant_id, run_id, fam, result.lgbm.mlf, data_fingerprint=result.training_data_hash)
            artifact_path, artifact_sha, artifact_signature = saved.stored_path, saved.sha256, saved.signature.as_compat()
        elif FAMILIES[fam].kind == "statistical":
            notes = (notes + " " if notes else "") + "Modelo estadístico determinista: se reentrena con la configuración registrada."
        hp = dict(info["hyperparameters"])
        if fam == "lightgbm_global" and result.lgbm is not None:
            hp = {k: v for k, v in result.lgbm.params.items()}
        classes = info.get("demand_classes") or []
        compat = {"input_schema": "demand-panel-v1", "feature_set_version": FEATURE_SET_VERSION, "libraries": versions,
                  "artifact_kind": "mlforecast_joblib" if artifact_path else "statistical_refit",
                  "frequency": "D", "season_length": 7}
        row_fsv, factor_compat = factor_registry_fields(result, fam)
        if factor_compat is not None:      # W6-E (H2): el modelo SERVIDO usa factores externos: su linaje (claves, versiones de definición, huella) viaja con el registro
            compat["factors"] = factor_compat
        if artifact_signature is not None:
            compat["signature"] = artifact_signature      # firma Ed25519 del manifiesto (signed | unsigned), ADR-V25-20
        evaluated = status in ("production", "validated", "candidate") and info.get("evaluated", False)
        rid = conn.execute(text(
            """
            INSERT INTO model_registry (tenant_id, version, model_family, strategy_level, scope, demand_class, status,
                core, role, validated_at, artifact_compat,
                hyperparameters, feature_set_version, training_data_from, training_data_to, training_data_hash,
                series_count, artifact_path, artifact_sha256, library_versions, random_seed, training_job_id,
                forecast_run_id, trained_at, promoted_at, notes, created_at, updated_at)
            VALUES (:tenant_id, :version, :family, :level, 'tenant', :demand_class, :status,
                'demand', :role, CASE WHEN :evaluated THEN now() END, CAST(:compat AS jsonb),
                CAST(:hp AS jsonb), :fsv,
                :dfrom, :dto, :hash, :series_count, :apath, :asha, CAST(:libs AS jsonb), :seed, :job_id, :run_id, now(),
                CASE WHEN :promoted THEN now() END, :notes, now(), now())
            RETURNING id
            """
        ), {
            "tenant_id": tenant_id, "version": f"t{tenant_id}-r{run['version']}-{fam}", "family": fam,
            "level": info["level"], "demand_class": classes[0] if len(classes) == 1 else None, "status": status,
            "role": role, "evaluated": bool(evaluated), "compat": jdump(compat),
            "hp": jdump(hp), "fsv": row_fsv, "dfrom": result.data_from, "dto": result.data_to,
            "hash": result.training_data_hash, "series_count": info["series_count"], "apath": artifact_path,
            "asha": artifact_sha, "libs": jdump(versions), "seed": seed, "job_id": job_id, "run_id": run_id,
            "notes": notes, "promoted": status == "production",
        }).scalar_one()
        registry_ids[fam] = int(rid)

    # ── Evaluaciones (backtest, ventanas de selección y segmentos por horizonte) ──
    persist_evaluations(conn, tenant_id, run_id, result.evaluations, registry_ids)

    # ── Valores pronosticados ──
    copy_forecast_values(conn, tenant_id, run_id, result.forecast_values, registry_ids)

    # ── W3-B · decisiones de promoción de ambas políticas y predicciones del challenger (shadow; no se sirven) ──
    # Aislados con un punto de guardado: el registro de decisiones y el shadow no pueden tumbar la transacción de lo servido; si fallan, la
    # ejecución se publica igual y la limitación queda explícita.
    _isolated(conn, result, "promotion_decisions_persist_failed", "No se pudieron registrar las decisiones de promoción de esta ejecución",
              persist_promotion_decisions, conn, tenant_id, run_id, result, registry_ids)
    _isolated(conn, result, "shadow_persist_failed", "No se pudieron guardar las predicciones del challenger (shadow) de esta ejecución",
              persist_shadow_forecasts, conn, tenant_id, run_id, result, registry_ids)

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
    # W2-C: métricas de dataset y resultado de los gates de la ejecución (scope `run`), junto al informe de preparación.
    gate_report_id = insert_run_quality_report(conn, tenant_id, result.quality, forecast_run_id=run_id) if result.quality is not None else None

    # ── Alertas (solo ejecuciones de producción) ──
    if production:
        _alerts(conn, tenant_id, run_id, run["version"], result, dq_id)

    # ── Cierre del run y del job ──
    summary = dict(result.summary)
    summary["duration_ms"] = duration_ms
    summary["data_quality_report_id"] = dq_id
    if gate_report_id is not None:
        summary["run_quality_report_id"] = gate_report_id
        summary["quality_gate"] = {**summary.get("quality_gate", {}), "report_id": gate_report_id}
        result.manifest["data_quality_gate"] = {**result.manifest.get("data_quality_gate", {}), "report_id": gate_report_id}
    summary["library_versions"] = versions
    summary["training_data_hash"] = result.training_data_hash
    snapshot_id = persist_snapshot(conn, tenant_id, result.snapshot) if result.snapshot is not None else None
    summary["data_snapshot_id"] = snapshot_id
    selection_json = result.selection.as_json(registry_ids, result.planned_selection)
    if production:
        conn.execute(text("UPDATE forecast_runs SET is_current = false, updated_at = now() "
                          "WHERE tenant_id = :tenant_id AND is_current"), {"tenant_id": tenant_id})
    conn.execute(text(
        """
        UPDATE forecast_runs SET status = 'completed', cutoff_date = :cutoff, data_from = :dfrom, data_to = :dto,
            feature_set_version = :fsv, selection = CAST(:selection AS jsonb), summary = CAST(:summary AS jsonb),
            config_version = :config_version, effective_config = CAST(:effective_config AS jsonb),
            data_snapshot_id = :snapshot_id, evaluation_manifest = CAST(:manifest AS jsonb),
            is_current = :current, completed_at = now(), error_message = NULL, updated_at = now()
        WHERE id = :run_id AND tenant_id = :tenant_id
        """
    ), {"cutoff": result.cutoff, "dfrom": result.data_from, "dto": result.data_to, "fsv": FEATURE_SET_VERSION,
        "selection": jdump(selection_json), "summary": jdump(summary), "current": production, "run_id": run_id,
        "tenant_id": tenant_id, "config_version": result.config_version,
        "effective_config": jdump(result.effective_config), "snapshot_id": snapshot_id,
        "manifest": jdump(result.manifest)})
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


def load_champions(repo: TenantRepository, production: bool = True) -> dict[str, str]:
    """W3-B · champion de cada alcance: la familia servida por clase en la ejecución de producción vigente anterior (`forecast_runs.selection`).
    Sin ejecución vigente —o en una corrida histórica, `production=False`, cuyo corte es anterior a la vigente— no hay champion y la referencia es
    el baseline."""
    if not production:
        return {}
    current = repo.current_run()
    return {} if current is None else champions_from_selection(_json(current.get("selection")))


def _isolated(conn: Connection, result: PipelineResult, code: str, message: str, fn: Any, *args: Any) -> Any:
    """Ejecuta `fn(*args)` dentro de un punto de guardado (`begin_nested`): un fallo revierte solo lo suyo, deja la limitación `code` en el resultado
    (resumen y `limitation_codes`) y la persistencia de la ejecución continúa."""
    try:
        with conn.begin_nested():
            return fn(*args)
    except Exception as exc:
        log.exception("%s (run=%s)", code, args[2] if len(args) > 2 else "?")
        item = {"code": code, "message": f"{message} ({type(exc).__name__}). Lo servido no cambia."}
        result.limitations.append(item)
        codes = result.summary.setdefault("limitation_codes", [])
        if code not in codes:
            codes.append(code)
        return None


def persist_promotion_decisions(conn: Connection, tenant_id: int, run_id: int, result: PipelineResult, registry_ids: dict[str, int]) -> int:
    """W3-B · una fila de `model_promotion_decisions` por política (legacy | v25) y alcance (global, clase, horizonte), con `is_effective` en las
    que gobernaron lo servido y los gates con su evidencia. El candidato referencia el registro de modelos de ESTA ejecución; el champion, el de la
    ejecución vigente anterior (FK compuestas por empresa). Idempotente por ejecución. Devuelve cuántas filas escribió."""
    promotion = result.promotion
    if promotion is None:
        return 0
    conn.execute(text("DELETE FROM model_promotion_decisions WHERE tenant_id = :t AND forecast_run_id = :r AND core = 'demand'"),
                 {"t": tenant_id, "r": run_id})
    previous = conn.execute(text("SELECT id FROM forecast_runs WHERE tenant_id = :t AND is_current AND id <> :r ORDER BY id DESC LIMIT 1"),
                            {"t": tenant_id, "r": run_id}).scalar()      # se consulta ANTES de que esta ejecución releve a la vigente
    previous_ids: dict[str, int] = {}
    if previous is not None:
        previous_ids = {str(f): int(i) for f, i in conn.execute(text(
            "SELECT model_family, MAX(id) FROM model_registry WHERE tenant_id = :t AND core = 'demand' AND forecast_run_id = :p "
            "GROUP BY model_family"), {"t": tenant_id, "p": previous}).all()}
    payload = [{
        "t": tenant_id, "r": run_id, "scope": d["scope_key"], "policy": d["policy"], "version": d["policy_version"],
        "cand": d["candidate_family"], "champ": d["champion_family"], "cand_id": registry_ids.get(d["candidate_family"]),
        # Solo un champion REAL (lo servido por la ejecución vigente anterior) referencia su registro; el baseline como referencia no es ese modelo.
        "champ_id": previous_ids.get(d["champion_family"]) if d["metrics"].get("champion_source") == "previous_run" else None, "decision": d["decision"], "eff": bool(d["is_effective"]),
        "gates": jdump(d["gates"]), "metrics": jdump(d["metrics"]), "reason": d["reason"],
    } for d in promotion.rows()]
    if payload:
        conn.execute(text(
            """
            INSERT INTO model_promotion_decisions (tenant_id, core, forecast_run_id, scope_key, policy, policy_version, candidate_family,
                champion_family, candidate_model_registry_id, champion_model_registry_id, decision, is_effective, gates, metrics, reason,
                decided_at, created_at)
            VALUES (:t, 'demand', :r, :scope, :policy, :version, :cand, :champ, :cand_id, :champ_id, :decision, :eff,
                CAST(:gates AS jsonb), CAST(:metrics AS jsonb), :reason, now(), now())
            """), payload)
    return len(payload)


SHADOW_COPY_COLUMNS = ["tenant_id", "forecast_run_id", "model_registry_id", "model_family", "node_key", "level", "branch_id", "product_id",
                       "target_date", "horizon_step", "yhat", "lo_80", "hi_80"]


def persist_shadow_forecasts(conn: Connection, tenant_id: int, run_id: int, result: PipelineResult, registry_ids: dict[str, int]) -> int:
    """W3-B · predicciones del challenger en `shadow_forecast_values` (NO se sirven: no entran a `forecast_values`). Idempotente por ejecución."""
    shadow = result.shadow
    if shadow is None or shadow.empty:
        return 0
    conn.execute(text("DELETE FROM shadow_forecast_values WHERE tenant_id = :t AND forecast_run_id = :r"), {"t": tenant_id, "r": run_id})
    df = shadow.copy()
    df["tenant_id"], df["forecast_run_id"] = tenant_id, run_id
    df["model_registry_id"] = df["model_family"].map(registry_ids)
    for col in ("branch_id", "product_id", "model_registry_id", "horizon_step"):
        df[col] = pd.to_numeric(df[col]).round().astype("Int64")
    df = df[SHADOW_COPY_COLUMNS].astype(object).where(df[SHADOW_COPY_COLUMNS].notna(), None)
    raw = conn.connection.driver_connection
    n = 0
    with raw.cursor() as cur:
        with cur.copy(f"COPY shadow_forecast_values ({', '.join(SHADOW_COPY_COLUMNS)}) FROM STDIN") as cp:
            for row in df.itertuples(index=False, name=None):
                cp.write_row(tuple(_shadow_cell(v) for v in row))
                n += 1
    return n


def _shadow_cell(v: Any) -> Any:
    if v is pd.NA:
        return None
    v = none_if_nan(v)
    return v.date() if isinstance(v, pd.Timestamp) else v


def _alerts(conn: Connection, tenant_id: int, run_id: int, version: int, result: PipelineResult, dq_id: int) -> None:
    recs = result.recommendations
    current_keys: set[str] = set()
    if recs is not None and not recs.empty:
        risky = recs[recs["risk_level"].isin(["high", "critical"])]
        # Críticas primero; la probabilidad puede ser nula (incertidumbre desconocida, F3).
        risky = risky.assign(_critical=risky["risk_level"].eq("critical")).sort_values(
            ["_critical", "stockout_probability"], ascending=[False, False], na_position="last")
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
            prob, qty, when = r.get("stockout_probability"), r.get("suggested_qty"), r.get("stockout_date_estimate")
            prob_txt = (f"Probabilidad estimada de agotarse antes de reponer: {prob:.0%}" if _known(prob) else
                        "Probabilidad de quiebre no disponible (incertidumbre desconocida o pronóstico insuficiente)")
            when_txt = f" Agotamiento estimado: {when}." if when is not None and pd.notna(when) else ""
            qty_txt = (f"Compra sugerida: {qty:.0f} unidades." if _known(qty) else
                       "Compra sugerida no calculada: el pronóstico no cubre plazo + revisión.")
            upsert_alert(
                conn, tenant_id, type_="stockout_risk",
                severity="critical" if r["risk_level"] == "critical" else "warning",
                title=f"Riesgo de quiebre: {pname} ({bname})",
                message=(f"{prob_txt} ({cover_txt}; plazo {int(r['lead_time_days'])} días).{when_txt} {qty_txt} "
                         "Es una sugerencia: Predictily no emite pedidos."),
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
                 message=(f"Se pronosticaron {result.summary['series_count']} series hasta "
                          f"{result.summary.get('horizon_days', 90)} días.{detail}"),
                 payload={"forecast_run_id": run_id, "version": version}, dedup_key="run_completed",
                 related_type="forecast_run", related_id=run_id)
