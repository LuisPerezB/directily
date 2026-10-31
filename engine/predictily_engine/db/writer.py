"""Escritura de resultados del motor en PostgreSQL.

El motor solo escribe en sus tablas (contrato docs/06 §4): training_jobs, forecast_runs
(estado/resultados), forecasting_series, forecast_features, model_registry,
model_evaluations, forecast_values, forecast_actuals, recommendations, alerts y
data_quality_reports. Todas las sentencias incluyen tenant_id.
"""

from __future__ import annotations

import json
import math
from datetime import date, datetime
from typing import Any

import numpy as np
import pandas as pd
from sqlalchemy import Connection, Engine, text

FV_COLUMNS = [
    "tenant_id", "forecast_run_id", "node_key", "level", "branch_id", "product_id", "category_id", "granularity",
    "target_date", "period_days", "horizon_step", "yhat", "lo_80", "hi_80", "lo_95", "hi_95", "model_registry_id",
    "model_family", "unit_price_assumed", "revenue_yhat",
]
# Columnas de la etapa 2 (migración 2026_02_05_000100): si la BD aún no las tiene se omiten (con aviso) en
# lugar de romper la escritura; el resultado queda sin P50, sin estado de calidad por fila y sin etiquetas.
FV_STAGE2_COLUMNS = ["p50", "quality_status", "case_label"]

_COLUMN_CACHE: dict[tuple[str, str], frozenset[str]] = {}


def table_columns(conn: Connection, table: str) -> frozenset[str]:
    """Columnas de `table` en la BD de la conexión (cacheado por BD y tabla)."""
    key = (conn.engine.url.render_as_string(hide_password=True), table)
    if key not in _COLUMN_CACHE:
        rows = conn.execute(text("SELECT column_name FROM information_schema.columns "
                                 "WHERE table_schema = current_schema() AND table_name = :t"), {"t": table}).scalars().all()
        _COLUMN_CACHE[key] = frozenset(rows)
    return _COLUMN_CACHE[key]


def jdump(value: Any) -> str:
    return json.dumps(_clean(value), ensure_ascii=False, default=str)


def _clean(v: Any) -> Any:
    if isinstance(v, dict):
        return {str(k): _clean(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [_clean(x) for x in v]
    if isinstance(v, (np.integer,)):
        return int(v)
    if isinstance(v, (np.floating, float)):
        f = float(v)
        return None if math.isnan(f) or math.isinf(f) else f
    if isinstance(v, (np.bool_,)):
        return bool(v)
    if isinstance(v, (pd.Timestamp, datetime, date)):
        return v.isoformat()[:10] if isinstance(v, (date, pd.Timestamp)) and not isinstance(v, datetime) else v.isoformat()
    return v


def none_if_nan(v: Any) -> Any:
    if v is None:
        return None
    if isinstance(v, (float, np.floating)) and (math.isnan(v) or math.isinf(v)):
        return None
    if isinstance(v, np.integer):
        return int(v)
    if isinstance(v, np.floating):
        return float(v)
    if v is pd.NaT:
        return None
    return v


class ProgressReporter:
    """Actualiza stage/progress de training_jobs en transacciones cortas (visible para Laravel)."""

    def __init__(self, engine: Engine, tenant_id: int, job_id: int | None) -> None:
        self.engine, self.tenant_id, self.job_id = engine, tenant_id, job_id
        self.last: tuple[str, int] | None = None

    def __call__(self, stage: str, pct: int) -> None:
        if self.job_id is None or self.last == (stage, pct):
            return
        self.last = (stage, pct)
        with self.engine.begin() as conn:
            conn.execute(text(
                "UPDATE training_jobs SET stage = :stage, progress = GREATEST(progress, :pct), updated_at = now() "
                "WHERE id = :id AND tenant_id = :tenant_id"
            ), {"stage": stage, "pct": int(min(max(pct, 0), 99)), "id": self.job_id, "tenant_id": self.tenant_id})


def mark_running(conn: Connection, tenant_id: int, run_id: int, job_id: int | None) -> None:
    # `training_jobs.attempts` NO se toca aquí (ni en ninguna ruta del motor): lo cuenta solo el backend, una vez por intento real de
    # la cola (`TrainingJobRecorder::markAttempt`). Si también lo sumara el motor, un trabajo ejecutado una vez informaría 2.
    conn.execute(text(
        "UPDATE forecast_runs SET status = 'running', started_at = now(), error_message = NULL, updated_at = now() "
        "WHERE id = :run_id AND tenant_id = :tenant_id"
    ), {"run_id": run_id, "tenant_id": tenant_id})
    if job_id is not None:
        conn.execute(text(
            "UPDATE training_jobs SET status = 'running', started_at = COALESCE(started_at, now()), "
            "progress = 1, stage = 'loading_data', error_message = NULL, updated_at = now() "
            "WHERE id = :id AND tenant_id = :tenant_id"
        ), {"id": job_id, "tenant_id": tenant_id})


def mark_failed(engine: Engine, tenant_id: int, run_id: int | None, job_id: int | None, message: str,
                duration_ms: int | None = None, alert: bool = True) -> None:
    message = message[:2000]
    with engine.begin() as conn:
        if run_id is not None:
            conn.execute(text(
                "UPDATE forecast_runs SET status = 'failed', error_message = :msg, updated_at = now() "
                "WHERE id = :run_id AND tenant_id = :tenant_id"
            ), {"msg": message, "run_id": run_id, "tenant_id": tenant_id})
        if job_id is not None:
            conn.execute(text(
                "UPDATE training_jobs SET status = 'failed', error_message = :msg, finished_at = now(), "
                "duration_ms = :dur, updated_at = now() WHERE id = :id AND tenant_id = :tenant_id"
            ), {"msg": message, "dur": duration_ms, "id": job_id, "tenant_id": tenant_id})


def upsert_series_profile(conn: Connection, tenant_id: int, profile: pd.DataFrame,
                          lifecycle: dict[str, str] | None = None) -> None:
    """Perfil por serie. `lifecycle`: unique_id → caso especial vigente (nuevo, descontinuado, ...); las series
    sin caso especial quedan en NULL. Se omite si la BD aún no tiene la columna (migración 2026_02_05)."""
    if profile.empty:
        return
    has_lifecycle = "lifecycle" in table_columns(conn, "forecasting_series")
    rows = [{
        "tenant_id": tenant_id, "branch_id": int(r.branch_id), "product_id": int(r.product_id),
        "unique_id": r.unique_id, "first_sale_date": r.first_sale_date, "last_sale_date": r.last_sale_date,
        "history_days": int(r.history_days), "nonzero_days": int(r.nonzero_days),
        "total_units": float(r.total_units), "adi": none_if_nan(r.adi), "cv2": none_if_nan(r.cv2),
        "demand_class": r.demand_class, "stockout_days": int(r.stockout_days),
        "censored_days": int(r.censored_days), "is_active": bool(r.is_active),
        **({"lifecycle": (lifecycle or {}).get(r.unique_id)} if has_lifecycle else {}),
    } for r in profile.itertuples(index=False)]
    lc_col, lc_val, lc_set = (", lifecycle", ", :lifecycle", ", lifecycle = EXCLUDED.lifecycle") if has_lifecycle \
        else ("", "", "")
    conn.execute(text(
        f"""
        INSERT INTO forecasting_series (tenant_id, branch_id, product_id, unique_id, first_sale_date, last_sale_date,
            history_days, nonzero_days, total_units, adi, cv2, demand_class, stockout_days, censored_days, is_active{lc_col},
            last_profiled_at, created_at, updated_at)
        VALUES (:tenant_id, :branch_id, :product_id, :unique_id, :first_sale_date, :last_sale_date, :history_days,
            :nonzero_days, :total_units, :adi, :cv2, :demand_class, :stockout_days, :censored_days, :is_active{lc_val},
            now(), now(), now())
        ON CONFLICT (tenant_id, branch_id, product_id) DO UPDATE SET
            unique_id = EXCLUDED.unique_id, first_sale_date = EXCLUDED.first_sale_date,
            last_sale_date = EXCLUDED.last_sale_date, history_days = EXCLUDED.history_days,
            nonzero_days = EXCLUDED.nonzero_days, total_units = EXCLUDED.total_units, adi = EXCLUDED.adi,
            cv2 = EXCLUDED.cv2, demand_class = EXCLUDED.demand_class, stockout_days = EXCLUDED.stockout_days,
            censored_days = EXCLUDED.censored_days, is_active = EXCLUDED.is_active{lc_set},
            last_profiled_at = now(), updated_at = now()
        """
    ), rows)


def insert_quality_report(conn: Connection, tenant_id: int, readiness: dict, run_id: int | None) -> int:
    summary = {k: v for k, v in readiness.items() if k != "issues"}
    return int(conn.execute(text(
        "INSERT INTO data_quality_reports (tenant_id, forecast_run_id, scope, score, summary, issues, created_at) "
        "VALUES (:tenant_id, :run_id, 'forecasting_readiness', :score, CAST(:summary AS jsonb), "
        "CAST(:issues AS jsonb), now()) RETURNING id"
    ), {"tenant_id": tenant_id, "run_id": run_id, "score": readiness.get("score"), "summary": jdump(summary),
        "issues": jdump(readiness.get("issues", []))}).scalar_one())


def ensure_feature_registry(conn: Connection, rows: list[dict]) -> None:
    """Registra las definiciones estándar del feature set (tenant_id NULL) una sola vez."""
    if not rows:
        return
    version = rows[0]["feature_set_version"]
    exists = conn.execute(text(
        "SELECT COUNT(*) FROM forecast_features WHERE tenant_id IS NULL AND feature_set_version = :v"
    ), {"v": version}).scalar_one()
    if exists:
        return
    conn.execute(text(
        "INSERT INTO forecast_features (tenant_id, feature_set_version, name, kind, description, config, is_active, "
        "created_at) VALUES (NULL, :feature_set_version, :name, :kind, :description, CAST(:config AS jsonb), true, now())"
    ), [{**r, "config": jdump(r.get("config", {}))} for r in rows])


def copy_forecast_values(conn: Connection, tenant_id: int, run_id: int, fv: pd.DataFrame,
                         registry_ids: dict[str, int]) -> int:
    df = fv.copy()
    df["tenant_id"] = tenant_id
    df["forecast_run_id"] = run_id
    df["model_registry_id"] = df["model_family"].map(registry_ids)
    for col in ("branch_id", "product_id", "category_id", "model_registry_id", "period_days", "horizon_step"):
        df[col] = pd.to_numeric(df[col]).round().astype("Int64")
    present = table_columns(conn, "forecast_values")
    cols = FV_COLUMNS + [c for c in FV_STAGE2_COLUMNS if c in present and c in df]
    df = df[cols].astype(object).where(df[cols].notna(), None)
    raw = conn.connection.driver_connection
    n = 0
    with raw.cursor() as cur:
        with cur.copy(f"COPY forecast_values ({', '.join(cols)}) FROM STDIN") as cp:
            for row in df.itertuples(index=False, name=None):
                cp.write_row(tuple(_copy_value(v) for v in row))
                n += 1
    return n


def _copy_value(v: Any) -> Any:
    if v is pd.NA:
        return None
    v = none_if_nan(v)
    if isinstance(v, pd.Timestamp):
        return v.date()
    return v


def upsert_alert(conn: Connection, tenant_id: int, *, type_: str, severity: str, title: str, message: str,
                 payload: dict, dedup_key: str, related_type: str | None = None, related_id: int | None = None) -> None:
    existing = conn.execute(text(
        "SELECT id FROM alerts WHERE tenant_id = :tenant_id AND dedup_key = :k AND status = 'open' ORDER BY id DESC LIMIT 1"
    ), {"tenant_id": tenant_id, "k": dedup_key}).scalar()
    params = {"tenant_id": tenant_id, "type": type_, "severity": severity, "title": title[:200], "message": message,
              "payload": jdump(payload), "k": dedup_key, "rt": related_type, "rid": related_id}
    if existing:
        conn.execute(text(
            "UPDATE alerts SET severity = :severity, title = :title, message = :message, "
            "payload = CAST(:payload AS jsonb), related_type = :rt, related_id = :rid, updated_at = now() "
            "WHERE id = :id AND tenant_id = :tenant_id"
        ), {**params, "id": existing})
    else:
        conn.execute(text(
            "INSERT INTO alerts (tenant_id, type, severity, title, message, payload, related_type, related_id, dedup_key, "
            "status, created_at, updated_at) VALUES (:tenant_id, :type, :severity, :title, :message, "
            "CAST(:payload AS jsonb), :rt, :rid, :k, 'open', now(), now())"
        ), params)
