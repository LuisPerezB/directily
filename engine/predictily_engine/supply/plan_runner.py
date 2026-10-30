"""Ejecución de un plan de reposición (core_runs: core = supply, operation = plan)."""

from __future__ import annotations

import time
from datetime import timedelta
from importlib import metadata
from typing import Any

import pandas as pd
import scipy
from sqlalchemy import Connection, text

from predictily_engine.db.supply_repository import SupplyRepository, write_plan
from predictily_engine.db.writer import jdump
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot
from predictily_engine.supply.inputs import PlanInputError, PlanInputs, build_problem
from predictily_engine.supply.present import present_solution, summarize
from predictily_engine.supply.runs import complete_run
from predictily_engine.supply.service import solve_plan

PLANNER_VERSION = "supply-milp-2026.10"
TARGET_DEFINITION = {
    "metric": "replenishment_plan",
    "description": "Compras sugeridas por producto × sucursal × proveedor y fecha de emisión que minimizan el objetivo "
                   "sujeto a presupuesto, capacidades, lotes y mínimos. Sugerencias: no emiten pedidos ni comprometen fondos.",
}


def _quality(status: str, report: dict[str, Any], n_nodes: int, excluded: int, solution_status: str) -> str:
    if n_nodes == 0:
        return "insufficient_data"
    if status != "optimal" or excluded > 0 or report["uncertainty_source"] in ("unknown", "none"):
        return "degraded"
    return "ok"


def load_inputs(repo: SupplyRepository, run: dict[str, Any], cfg: dict[str, Any]) -> tuple[PlanInputs | None, dict[str, Any]]:
    opts = run.get("options") or {}
    fr = repo.forecast_run_for_plan(opts.get("forecast_run_id"))
    if fr is None:
        return None, {"reason": "no_forecast"}
    as_of = pd.Timestamp(fr["cutoff_date"]).date()
    retro = fr["run_type"] == "historical"
    tenant = repo.tenant()
    inputs = PlanInputs(
        as_of=as_of, currency=str(tenant.get("currency") or "USD"), retroactive=retro,
        forecast=repo.series_forecast(int(fr["id"])), inventory=repo.latest_inventory(as_of),
        open_pos=repo.open_purchase_orders(as_of if retro else None), po_history=repo.po_history(as_of),
        products=repo.products(), lead_times=repo.lead_times(), suppliers=repo.suppliers(),
        offers=repo.supplier_offers(), fx_rates=repo.exchange_rates(), conversions=repo.unit_conversions(),
        capacities=repo.location_capacities(), warehouses=repo.warehouses(), budgets=repo.purchase_budgets(),
        resources=repo.production_resources(), recipes=repo.production_recipes(), recipe_lines=repo.production_recipe_lines(),
        safety_stock=repo.safety_stock(int(fr["id"])), cost_by_product=repo.last_purchase_costs(as_of, retro),
        config=cfg, options=opts)
    return inputs, fr


def run_plan(engine, tenant_id: int, run: dict[str, Any], job_id: int | None, progress, t0: float) -> dict[str, Any]:
    run_id = int(run["id"])
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        tenant = repo.tenant()
        opts = run.get("options") or {}
        overrides = {k: v for k, v in (opts.get("config") or {}).items()}
        cfg, cfg_version, provenance = resolve_config("supply", tenant["settings"], tenant.get("sector"), overrides or None)
        progress("loading_data", 10)
        inputs, fr = load_inputs(repo, run, cfg)
        supplier_names, product_names = (repo.supplier_names(), repo.product_names()) if inputs else ({}, {})
    if inputs is None:
        return _finish_empty(engine, tenant_id, run_id, job_id, cfg, cfg_version, provenance, t0,
                             "No hay un pronóstico de demanda completado: sin demanda pronosticada no se puede planificar.")
    try:
        progress("building_model", 30)
        problem, report = build_problem(inputs)
    except PlanInputError as exc:
        return _finish_empty(engine, tenant_id, run_id, job_id, cfg, cfg_version, provenance, t0, str(exc),
                             as_of=inputs.as_of, forecast_run_id=int(fr["id"]), quality="insufficient_data")
    progress("solving", 50)
    outcome = solve_plan(problem, diagnose_unmet=bool(cfg.get("diagnose_unmet", True)),
                         diagnosis_time_limit=min(10.0, float(cfg.get("solver_time_limit_seconds", 30)) / 3))
    sol = outcome.solution
    progress("persisting", 85)
    presented = present_solution(problem, sol, supplier_names, product_names) if sol.evaluation is not None else {
        "lines": [], "shortfalls": [], "constraints": [], "balance": []}
    summary_plan = summarize(problem, sol) if sol.evaluation is not None else {"solver_status": outcome.status}
    summary_plan["solver_status"] = outcome.status
    if outcome.status == "infeasible":
        summary_plan.update({"currency": problem.currency, "objective_value": None})

    alt_block = None
    if outcome.alternative is not None and outcome.alternative.evaluation is not None:
        alt_present = present_solution(problem, outcome.alternative, supplier_names, product_names)
        alt_block = {"description": "Plan de máxima cobertura factible: respeta todas las restricciones y declara lo no atendido.",
                     "summary": summarize(problem, outcome.alternative), "lines": alt_present["lines"][:300],
                     "shortfalls": alt_present["shortfalls"][:300]}
    duration_ms = int((time.perf_counter() - t0) * 1000)
    details = {
        "report": {k: report[k] for k in ("excluded_nodes", "comparison_coverage", "offer_diagnostics", "receipts_summary",
                                          "production", "capacity_notes", "nodes_planned", "nodes_candidate", "period_days",
                                          "horizon_periods", "lead_time_evidence")},
        "offers": report["offers"], "constraints": presented["constraints"], "balance": presented["balance"],
        "diagnosis": outcome.diagnosis, "infeasibility": outcome.infeasibility, "alternative_plan": alt_block,
        "solver": {"status": outcome.status, "detail": sol.detail, "notes": sol.notes + outcome.notes, "components": sol.n_components,
                   "n_vars": sol.n_vars, "n_constraints": sol.n_constraints, "n_integer": sol.n_integer,
                   "mip_gap": sol.mip_gap, "dual_bound": sol.dual_bound, "seconds": round(sol.seconds, 3),
                   "scipy": scipy.__version__, "highs": "HiGHS (scipy.optimize.milp)"},
        "periods": [{"period": t, "start": problem.period_start(t).isoformat(), "end": problem.period_end(t).isoformat()}
                    for t in range(problem.n_periods)],
    }
    plan = {"objective": problem.objective, "coverage_mode": problem.coverage_mode, "solver_status": outcome.status,
            "mip_gap": sol.mip_gap, "objective_value": sol.objective_value, "solve_seconds": round(sol.seconds, 3),
            "currency": problem.currency, "total_purchase_cost": summary_plan.get("total_purchase_cost"),
            "total_holding_cost": summary_plan.get("total_holding_cost"),
            "total_shortage_penalty": summary_plan.get("total_shortage_penalty"),
            "unmet_units_total": summary_plan.get("unmet_units_total"), "demand_units_total": summary_plan.get("demand_units_total"),
            "details": details}
    excluded = len(report["excluded_nodes"])
    quality = _quality(outcome.status, report, len(problem.nodes), excluded, outcome.status)
    if outcome.status == "infeasible":
        quality = "degraded"
    snap = DataSnapshot("supply", inputs.as_of)
    snap.add_frame("forecast", inputs.forecast, ["branch_id", "product_id", "horizon_step", "yhat"], ["branch_id", "product_id", "horizon_step"])
    snap.add_frame("inventory", inputs.inventory, ["branch_id", "product_id", "on_hand", "reserved", "in_transit"], ["branch_id", "product_id"])
    snap.add_frame("open_purchase_orders", inputs.open_pos, None, ["purchase_order_id", "product_id"] if "purchase_order_id" in inputs.open_pos else None)
    snap.add_frame("offers", inputs.offers, None, ["offer_id"] if "offer_id" in inputs.offers else None)
    snap.add_frame("exchange_rates", inputs.fx_rates, None, None)
    snap.add_frame("budgets", inputs.budgets, None, None)
    snap.add_frame("capacities", pd.concat([inputs.capacities, inputs.warehouses], ignore_index=True) if not inputs.warehouses.empty or not inputs.capacities.empty else None, None, None)
    snap.add_frame("recipes", inputs.recipes, None, None)
    snap.add_value("config", {"config": cfg, "options": inputs.options.get("config") or {}})
    horizon_end = inputs.as_of + timedelta(days=problem.period_days * problem.n_periods)
    assumptions = report["assumptions"]
    limitations = report["limitations"] + outcome.notes
    if sol.status in ("time_limit",):
        limitations.append("Se agotó el límite de tiempo: el plan es la mejor solución factible encontrada, NO probada óptima.")
    if outcome.status == "heuristic_fallback":
        limitations.append("Plan del respaldo heurístico factible (el solver no entregó una solución utilizable).")
    if outcome.status == "infeasible":
        limitations.append(outcome.infeasibility["message"])
    n_lines = len(presented["lines"])
    run_summary = {**summary_plan, "excluded_nodes": excluded, "uncertainty_source": report["uncertainty_source"],
                   "duration_ms": duration_ms, "forecast_run_id": int(fr["id"]), "lines": n_lines,
                   "production": report["production"]["status"], "comparison_coverage": {
                       k: report["comparison_coverage"][k] for k in ("offers_received", "offers_comparable", "offers_excluded", "comparable_share")}}
    with engine.begin() as conn:
        snap_id = persist_snapshot(conn, tenant_id, snap)
        write_plan(conn, tenant_id, run_id, int(fr["id"]), plan, presented["lines"], presented["shortfalls"])
        complete_run(conn, tenant_id, run_id, job_id, result_type="optimized_plan", quality_status=quality,
                     uncertainty_source=report["uncertainty_source"], as_of=inputs.as_of,
                     horizon_start=inputs.as_of + timedelta(days=1), horizon_end=horizon_end,
                     target_definition={**TARGET_DEFINITION, "objective": problem.objective, "coverage_mode": problem.coverage_mode,
                                        "period_days": problem.period_days, "currency": problem.currency},
                     config_version=cfg_version, effective_config={"config": cfg, "provenance": provenance},
                     snapshot_id=snap_id, model_versions={"planner": PLANNER_VERSION, "scipy": scipy.__version__,
                                                          "numpy": metadata.version("numpy"), "forecast_run_id": int(fr["id"])},
                     assumptions=assumptions, limitations=limitations, summary=run_summary, duration_ms=duration_ms,
                     make_current=inputs.retroactive is False, core="supply", operation="plan",
                     horizons=[problem.period_days * problem.n_periods])
    return {"run_id": run_id, "status": "completed", "quality_status": quality, "solver_status": outcome.status,
            "summary": run_summary}


def _finish_empty(engine, tenant_id: int, run_id: int, job_id: int | None, cfg, cfg_version, provenance, t0: float,
                  reason: str, as_of=None, forecast_run_id: int | None = None, quality: str = "insufficient_data") -> dict[str, Any]:
    duration_ms = int((time.perf_counter() - t0) * 1000)
    summary = {"solver_status": "not_solved", "reason": reason, "duration_ms": duration_ms, "lines": 0}
    with engine.begin() as conn:
        complete_run(conn, tenant_id, run_id, job_id, result_type="optimized_plan", quality_status=quality,
                     uncertainty_source="none", as_of=as_of, horizon_start=None, horizon_end=None,
                     target_definition=TARGET_DEFINITION, config_version=cfg_version,
                     effective_config={"config": cfg, "provenance": provenance}, snapshot_id=None,
                     model_versions={"planner": PLANNER_VERSION}, assumptions=[], limitations=[reason],
                     summary=summary, duration_ms=duration_ms, make_current=False, core="supply", operation="plan")
    return {"run_id": run_id, "status": "completed", "quality_status": quality, "solver_status": "not_solved", "summary": summary}
