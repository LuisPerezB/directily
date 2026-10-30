"""Control de ejecuciones de los cores de decisiones (core_runs: supply | cost | scenario).

Mismo patrón que el resto de los cores: Laravel crea la corrida (`core_runs`, estado `queued`) y el job
(`training_jobs`); el motor la toma con bloqueo de fila, la ejecuta e informa el cierre. Una corrida ya
completada se devuelve sin recalcular (idempotencia ante reintentos).
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from pydantic import BaseModel
from sqlalchemy import Connection, text

from predictily_engine.db.writer import jdump
from predictily_engine.pipeline.errors import ConflictError, NotFoundError


class RunExecuteRequest(BaseModel):
    tenant_id: int
    training_job_id: int | None = None


def claim_run(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, stale_seconds: int) -> dict[str, Any]:
    row = conn.execute(text(
        "SELECT * FROM core_runs WHERE id = :id AND tenant_id = :t AND core IN ('supply', 'cost', 'scenario') FOR UPDATE"
    ), {"id": run_id, "t": tenant_id}).mappings().first()
    if row is None:
        raise NotFoundError("La corrida no existe para esta empresa.")
    run = dict(row)
    for k in ("options", "summary", "effective_config", "assumptions", "limitations"):
        if isinstance(run.get(k), str):
            run[k] = json.loads(run[k])
    if job_id is not None:
        job = conn.execute(text("SELECT id, core_run_id FROM training_jobs WHERE id = :j AND tenant_id = :t"),
                           {"j": job_id, "t": tenant_id}).mappings().first()
        if job is None or (job["core_run_id"] is not None and int(job["core_run_id"]) != run_id):
            raise NotFoundError("El training_job no existe para esta empresa o no corresponde a la corrida.")
    if run["status"] == "completed":
        run["_already_completed"] = True
        return run
    if run["status"] == "running" and run.get("started_at") is not None:
        age = (datetime.now(timezone.utc) - run["started_at"]).total_seconds()
        if age < stale_seconds:
            raise ConflictError("La corrida ya se está ejecutando.")
    conn.execute(text("UPDATE core_runs SET status = 'running', started_at = now(), error_message = NULL, updated_at = now() "
                      "WHERE id = :id AND tenant_id = :t"), {"id": run_id, "t": tenant_id})
    if job_id is not None:
        conn.execute(text(
            "UPDATE training_jobs SET status = 'running', started_at = COALESCE(started_at, now()), attempts = attempts + 1, "
            "progress = 1, stage = 'loading_data', error_message = NULL, updated_at = now() WHERE id = :id AND tenant_id = :t"
        ), {"id": job_id, "t": tenant_id})
    return run


def mark_failed(engine, tenant_id: int, run_id: int, job_id: int | None, message: str, duration_ms: int | None = None) -> None:
    message = message[:2000]
    with engine.begin() as conn:
        conn.execute(text("UPDATE core_runs SET status = 'failed', error_message = :m, completed_at = now(), is_current = false, "
                          "updated_at = now() WHERE id = :id AND tenant_id = :t"), {"m": message, "id": run_id, "t": tenant_id})
        if job_id is not None:
            conn.execute(text("UPDATE training_jobs SET status = 'failed', error_message = :m, finished_at = now(), duration_ms = :d, "
                              "updated_at = now() WHERE id = :id AND tenant_id = :t"),
                         {"m": message, "d": duration_ms, "id": job_id, "t": tenant_id})


def complete_run(conn: Connection, tenant_id: int, run_id: int, job_id: int | None, *, result_type: str, quality_status: str,
                 uncertainty_source: str, as_of, horizon_start, horizon_end, target_definition: dict, config_version: str,
                 effective_config: dict, snapshot_id: int | None, model_versions: dict, assumptions: list[str],
                 limitations: list[str], summary: dict, duration_ms: int, make_current: bool, core: str,
                 operation: str, horizons: list[int] | None = None) -> None:
    if make_current:
        conn.execute(text("UPDATE core_runs SET is_current = false WHERE tenant_id = :t AND core = :c AND operation = :o "
                          "AND is_current AND id <> :id"), {"t": tenant_id, "c": core, "o": operation, "id": run_id})
    conn.execute(text(
        """
        UPDATE core_runs SET status = 'completed', result_type = :rt, quality_status = :q, uncertainty_source = :u,
            as_of = :as_of, horizon_start = :hs, horizon_end = :he, horizons = CAST(:horizons AS jsonb),
            target_definition = CAST(:target AS jsonb), config_version = :cv, effective_config = CAST(:cfg AS jsonb),
            data_snapshot_id = :snap, model_versions = CAST(:mv AS jsonb), assumptions = CAST(:assumptions AS jsonb),
            limitations = CAST(:limitations AS jsonb), summary = CAST(:summary AS jsonb), is_current = :cur,
            completed_at = now(), error_message = NULL, updated_at = now()
        WHERE id = :id AND tenant_id = :t
        """), {"rt": result_type, "q": quality_status, "u": uncertainty_source, "as_of": as_of, "hs": horizon_start,
               "he": horizon_end, "horizons": jdump(horizons or []), "target": jdump(target_definition), "cv": config_version,
               "cfg": jdump(effective_config), "snap": snapshot_id, "mv": jdump(model_versions),
               "assumptions": jdump(assumptions), "limitations": jdump(limitations), "summary": jdump(summary),
               "cur": make_current, "id": run_id, "t": tenant_id})
    if job_id is not None:
        conn.execute(text(
            "UPDATE training_jobs SET status = 'succeeded', stage = NULL, progress = 100, finished_at = now(), duration_ms = :d, "
            "result = CAST(:r AS jsonb), updated_at = now() WHERE id = :id AND tenant_id = :t"
        ), {"d": duration_ms, "r": jdump({"core_run_id": run_id, "core": core, "operation": operation,
                                          "quality_status": quality_status}), "id": job_id, "t": tenant_id})


def response(run: dict[str, Any], duration_ms: int | None = None) -> dict[str, Any]:
    summary = run.get("summary") or {}
    if isinstance(summary, str):
        summary = json.loads(summary)
    return {"run_id": int(run["id"]), "core": run["core"], "operation": run["operation"], "status": run["status"],
            "quality_status": run.get("quality_status"), "as_of": str(run["as_of"]) if run.get("as_of") else None,
            "summary": summary, "duration_ms": int(duration_ms if duration_ms is not None else summary.get("duration_ms") or 0)}
