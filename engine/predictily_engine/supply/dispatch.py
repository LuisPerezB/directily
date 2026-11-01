"""Despacho de ejecuciones de P4 por (core, operation)."""

from __future__ import annotations

import time
from typing import Any

from sqlalchemy import text

from predictily_engine.config import get_settings
from predictily_engine.costs.runner import run_cost_forecast
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import ProgressReporter
from predictily_engine.scenarios.runner import run_scenario_evaluation
from predictily_engine.supply.plan_runner import run_plan
from predictily_engine.supply.runs import RunExecuteRequest, claim_run, mark_failed, response
from predictily_engine.supply.simulation_runner import run_simulation

HANDLERS = {("supply", "plan"): run_plan, ("supply", "simulate"): run_simulation,
            ("cost", "forecast"): run_cost_forecast, ("scenario", "evaluate"): run_scenario_evaluation}


def execute_run(run_id: int, req: RunExecuteRequest) -> dict[str, Any]:
    settings = get_settings()
    engine = get_engine()
    t0 = time.perf_counter()
    with engine.begin() as conn:
        run = claim_run(conn, req.tenant_id, run_id, req.training_job_id, settings.stale_run_seconds)
    if run.pop("_already_completed", False):
        return response(run)
    handler = HANDLERS.get((run["core"], run["operation"]))
    progress = ProgressReporter(engine, req.tenant_id, req.training_job_id)
    if handler is None:
        mark_failed(engine, req.tenant_id, run_id, req.training_job_id, f"Operación no soportada: {run['core']}/{run['operation']}")
        raise ValueError(f"Operación no soportada: {run['core']}/{run['operation']}")
    try:
        handler(engine, req.tenant_id, run, req.training_job_id, progress, t0)
    except Exception as exc:
        mark_failed(engine, req.tenant_id, run_id, req.training_job_id, f"{exc.__class__.__name__}: {exc}",
                    int((time.perf_counter() - t0) * 1000))
        raise
    with engine.connect() as conn:
        row = conn.execute(text("SELECT * FROM core_runs WHERE id = :id AND tenant_id = :t"), {"id": run_id, "t": req.tenant_id}).mappings().first()
    return response(dict(row), int((time.perf_counter() - t0) * 1000))
