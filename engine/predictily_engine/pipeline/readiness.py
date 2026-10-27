"""Endpoint /v1/tenants/{tenant_id}/readiness: perfil de series y calidad de datos."""

from __future__ import annotations

import time
from typing import Any

from sqlalchemy import text

from predictily_engine.db.repository import TenantRepository
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import insert_quality_report, jdump, mark_failed, upsert_series_profile
from predictily_engine.pipeline.data import load_tenant_data
from predictily_engine.pipeline.errors import NotFoundError
from predictily_engine.profiling.readiness import compute_readiness
from predictily_engine.profiling.series import build_panel


def run_readiness(tenant_id: int, training_job_id: int | None = None) -> dict[str, Any]:
    engine = get_engine()
    t0 = time.perf_counter()
    with engine.connect() as conn:
        repo = TenantRepository(conn, tenant_id)
        try:
            repo.tenant()
        except LookupError as exc:
            raise NotFoundError(str(exc)) from exc
        if training_job_id is not None and repo.training_job(training_job_id) is None:
            raise NotFoundError("training_job inexistente para esta empresa")
    _job(engine, tenant_id, training_job_id, "running")
    try:
        with engine.connect() as conn:
            repo = TenantRepository(conn, tenant_id)
            last = repo.last_sale_date()
            if last is None:
                readiness = compute_readiness_empty()
                panel = None
            else:
                data = load_tenant_data(repo, last)
                panel = build_panel(data)
                readiness = compute_readiness(data, panel)
        with engine.begin() as conn:
            if panel is not None:
                upsert_series_profile(conn, tenant_id, panel.profile)
            dq_id = insert_quality_report(conn, tenant_id, readiness, None)
        result = {"tenant_id": tenant_id, **readiness, "data_quality_report_id": dq_id}
        _job(engine, tenant_id, training_job_id, "succeeded", result, int((time.perf_counter() - t0) * 1000))
        return result
    except Exception as exc:
        mark_failed(engine, tenant_id, None, training_job_id, f"{type(exc).__name__}: {exc}")
        raise


def compute_readiness_empty() -> dict[str, Any]:
    return {"score": 0.0, "series_total": 0, "series_by_class": {}, "history_days": 0, "date_from": None,
            "date_to": None, "missing_days": 0, "stockout_days_share": 0.0, "has_inventory": False,
            "has_costs": False, "recommended_strategy": "A",
            "issues": [{"code": "no_sales", "severity": "error", "count": 0,
                        "message": "No hay ventas válidas importadas."}]}


def _job(engine, tenant_id: int, job_id: int | None, status: str, result: dict | None = None,
         duration_ms: int | None = None) -> None:
    if job_id is None:
        return
    with engine.begin() as conn:
        if status == "running":
            conn.execute(text(
                "UPDATE training_jobs SET status = 'running', stage = 'profiling', progress = 10, "
                "started_at = COALESCE(started_at, now()), attempts = attempts + 1, updated_at = now() "
                "WHERE id = :id AND tenant_id = :tenant_id"), {"id": job_id, "tenant_id": tenant_id})
        else:
            conn.execute(text(
                "UPDATE training_jobs SET status = :status, stage = NULL, progress = 100, finished_at = now(), "
                "duration_ms = :dur, result = CAST(:result AS jsonb), updated_at = now() "
                "WHERE id = :id AND tenant_id = :tenant_id"),
                {"status": status, "dur": duration_ms, "result": jdump(result or {}), "id": job_id,
                 "tenant_id": tenant_id})
