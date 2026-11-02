"""Ejecución de un plan de reposición (core_runs: core = supply, operation = plan)."""

from __future__ import annotations

import math
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
from predictily_engine.quality.features import apply_supply_degradation
from predictily_engine.quality.persistence import insert_run_quality_report
from predictily_engine.quality.service import resolve_policy, supply_gate
from predictily_engine.supply.inputs import PlanInputError, PlanInputs, build_problem
from predictily_engine.supply.perishability import audit_plan
from predictily_engine.supply.policy import no_purchase_baseline, policy_rows
from predictily_engine.supply.position import committed_lines_by_series
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
    if report.get("stock_validity_issues") or report.get("perishability_issues"):
        # W5-D: sobrerreserva, físico negativo o snapshot obsoleto (V25-SUP-002) y vida útil menor que el período de revisión degradan la calidad
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
    products = repo.products()
    retro_notes: list[str] = []
    if retro:
        # D1 (V25-SUP-012/050): el precio del catálogo es el de HOY (posterior al corte). En un corte pasado el precio de venta es el neto observado hasta el
        # corte (lectura as-of); sin ventas recientes el precio queda desconocido (no se rellena con el del catálogo).
        observed = {int(r.product_id): float(r.price) for r in repo.recent_net_prices(as_of, 28).itertuples(index=False) if pd.notna(r.price)}
        products = products.assign(current_price=products["product_id"].map(observed))
        retro_notes.append("Corte pasado: el precio de venta es el neto observado en los 28 días previos al corte (lectura as-of); el precio del catálogo vigente "
                           "no se usa, y sin ventas recientes el precio queda desconocido.")
    w5 = _w5_inputs(repo, cfg, opts, int(fr["id"]), as_of, products)
    perish = w5.get("perishability")
    if retro and perish is not None and not perish.empty and bool(perish["is_perishable"].any()):
        retro_notes.append("Corte pasado: la perecibilidad del producto (perecible, vida útil) es la declarada hoy; el catálogo no versiona esos atributos. "
                           "Los lotes y las existencias sí son los de la última foto anterior o igual al corte.")
    inputs = PlanInputs(
        as_of=as_of, currency=str(tenant.get("currency") or "USD"), retroactive=retro,
        forecast=repo.series_forecast(int(fr["id"])), inventory=repo.latest_inventory(as_of),
        open_pos=repo.open_purchase_orders(as_of if retro else None), po_history=repo.po_history(as_of),
        products=products, lead_times=repo.lead_times_asof(as_of) if retro else repo.lead_times(), suppliers=repo.suppliers(),
        offers=repo.supplier_offers(), fx_rates=repo.exchange_rates(), conversions=repo.unit_conversions(),
        capacities=repo.location_capacities(), warehouses=repo.warehouses(), budgets=repo.purchase_budgets(),
        resources=repo.production_resources(), recipes=repo.production_recipes(), recipe_lines=repo.production_recipe_lines(),
        safety_stock=repo.safety_stock(int(fr["id"])), cost_by_product=repo.last_purchase_costs(as_of, retro),
        config=cfg, options=opts, retro_notes=retro_notes, **w5)
    return inputs, fr


def _w5_inputs(repo: SupplyRepository, cfg: dict[str, Any], opts: dict[str, Any], forecast_run_id: int, as_of, products: pd.DataFrame) -> dict[str, Any]:
    """Insumos de W5-D (perecibilidad, lotes, cuantiles persistidos, ajustes, compromisos y política de reposición). Cada uno se lee SOLO si su
    interruptor está activo; sin datos quedan vacíos y el plan es el de antes."""
    def get(key: str, default: Any) -> Any:
        v = opts.get(key)
        return v if v is not None else cfg.get(key, default)

    out: dict[str, Any] = {}
    if bool(get("perishability_enabled", True)):
        perish = repo.product_perishability()
        lots = repo.inventory_lots(as_of)
        out["perishability"], out["lots"] = perish, lots
        if not lots.empty or (not perish.empty and bool(perish["is_perishable"].any())):
            out["forecast_bands"] = repo.series_forecast_bands(forecast_run_id)
    if str(get("adjustments_mode", "apply")) == "apply":
        out["adjustments"] = repo.stock_adjustments(as_of)
    if str(get("commitments_mode", "reconcile")) == "reconcile":
        R = int(get("period_days", None) or get("review_period_days", 7))
        horizon_days = int(get("horizon_days", None) or get("planning_horizon_days", 84))
        T = max(2, math.ceil(horizon_days / R))
        units = {int(r.product_id): ("" if pd.isna(r.unit) else str(r.unit)) for r in products.itertuples(index=False)}
        lines, summary = committed_lines_by_series(repo.commitment_revisions(), repo.product_skus(), units, as_of, T * R)
        out["commitments"], out["commitments_summary"] = lines, summary
    out["replenishment_policy"] = repo.replenishment_policy(forecast_run_id)
    return out


def run_plan(engine, tenant_id: int, run: dict[str, Any], job_id: int | None, progress, t0: float) -> dict[str, Any]:
    run_id = int(run["id"])
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        tenant = repo.tenant()
        opts = run.get("options") or {}
        overrides = {k: v for k, v in (opts.get("config") or {}).items()}
        cfg, cfg_version, provenance = resolve_config("supply", tenant["settings"], tenant.get("sector"), overrides or None)
        resolve_policy(tenant)      # W2-C: una configuración `quality_gates` inválida se rechaza (422) antes de cargar datos
        progress("loading_data", 10)
        inputs, fr = load_inputs(repo, run, cfg)
        supplier_names, product_names = (repo.supplier_names(), repo.product_names()) if inputs else ({}, {})
    if inputs is None:
        return _finish_empty(engine, tenant_id, run_id, job_id, cfg, cfg_version, provenance, t0,
                             "No hay un pronóstico de demanda completado: sin demanda pronosticada no se puede planificar.")
    # Gate de calidad (W2-C · ADR-V25-05) ANTES de planificar: BLOCK termina con insufficient_data; DEGRADE deshabilita políticas que
    # dependen de plazos supuestos (ofertas implícitas, plazo observado); WARN continúa con avisos.
    qa = supply_gate(tenant, inputs)
    if qa.blocked:
        return _finish_empty(engine, tenant_id, run_id, job_id, cfg, cfg_version, provenance, t0, qa.block_message(), as_of=inputs.as_of,
                             forecast_run_id=int(fr["id"]), quality="insufficient_data", assessment=qa)
    qa.applied_changes = apply_supply_degradation(inputs, qa.disabled_features)
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
    ref_cost: dict[tuple[int, int], float | None] = {}
    ref_source: dict[tuple[int, int], str] = {}
    for n in problem.nodes:
        ref_cost[n.key], ref_source[n.key] = _ref_cost(problem, n)
    baseline = no_purchase_baseline(problem, ref_cost) if sol.evaluation is not None else None
    perish_nodes = problem.meta.get("perishability_nodes") or {}
    perishability = dict(report["perishability"])
    if perish_nodes and sol.evaluation is not None:
        perishability["audit"] = audit_plan(problem, sol, perish_nodes, ref_cost, float((problem.meta.get("perishability_config") or {}).get("receipt_life_fraction", 1.0)),
                                            cost_source=ref_source)
        summary_plan["expected_expiry_loss_units"] = perishability["audit"]["expected_expiry_loss_units"]
    if baseline is not None:
        summary_plan["no_purchase_unmet_units"] = baseline["unmet_units"]
    policy = policy_rows(inputs.replenishment_policy, {n.key for n in problem.nodes})
    details = {
        "report": {k: report[k] for k in ("excluded_nodes", "comparison_coverage", "offer_diagnostics", "receipts_summary",
                                          "production", "capacity_notes", "nodes_planned", "nodes_candidate", "period_days",
                                          "horizon_periods", "lead_time_evidence", "availability", "stock_validity_issues")},
        "perishability": perishability, "no_purchase_baseline": baseline, "replenishment_policy": policy,
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
    if qa.degraded and quality == "ok":    # W2-C: un gate DEGRADE nunca deja el plan en «ok»
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
    for name, frame in (("lots", inputs.lots), ("product_perishability", inputs.perishability[inputs.perishability["is_perishable"]] if not inputs.perishability.empty else None),
                        ("forecast_bands", inputs.forecast_bands), ("stock_adjustments", inputs.adjustments), ("committed_orders", inputs.commitments)):
        if frame is not None and not frame.empty:        # sin datos de W5-D la huella es la de antes
            snap.add_frame(name, frame, None, None)
    snap.add_value("config", {"config": cfg, "options": inputs.options.get("config") or {}})
    snap.add_value("quality_gate", {"outcome": qa.outcome, "disabled_features": qa.disabled_features, "rules_version": qa.rules_version})
    horizon_end = inputs.as_of + timedelta(days=problem.period_days * problem.n_periods)
    assumptions = report["assumptions"]
    limitations = report["limitations"] + outcome.notes + qa.limitation_strings() + qa.applied_changes
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
        run_summary["quality_gate"] = qa.manifest_block(insert_run_quality_report(conn, tenant_id, qa, core_run_id=run_id))   # W2-C
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


def _ref_cost(problem, node) -> tuple[float | None, str]:
    """Costo de referencia de la serie (sucursal × producto) y su origen. SOLO una oferta explícita del proveedor es un costo confiable para valorar mermas y
    margen perdido; una oferta implícita (último costo conocido, sin tabla de ofertas) no lo es y deja la serie sin valorar (`None`)."""
    offs = [o for o in problem.offers_for(node.branch_id, node.product_id) if not o.implicit]
    if offs:
        return min(o.unit_cost for o in offs), "supplier_offer"
    return None, "implicit_offer" if problem.offers_for(node.branch_id, node.product_id) else "no_offer"


def _finish_empty(engine, tenant_id: int, run_id: int, job_id: int | None, cfg, cfg_version, provenance, t0: float,
                  reason: str, as_of=None, forecast_run_id: int | None = None, quality: str = "insufficient_data",
                  assessment=None) -> dict[str, Any]:
    duration_ms = int((time.perf_counter() - t0) * 1000)
    summary = {"solver_status": "not_solved", "reason": reason, "duration_ms": duration_ms, "lines": 0}
    with engine.begin() as conn:
        if assessment is not None:    # W2-C: un gate BLOCK deja su informe de calidad (métricas y motivos) en la ejecución
            summary["quality_gate"] = assessment.manifest_block(insert_run_quality_report(conn, tenant_id, assessment, core_run_id=run_id))
            summary["reason_codes"] = assessment.reason_codes()
        complete_run(conn, tenant_id, run_id, job_id, result_type="optimized_plan", quality_status=quality,
                     uncertainty_source="none", as_of=as_of, horizon_start=None, horizon_end=None,
                     target_definition=TARGET_DEFINITION, config_version=cfg_version,
                     effective_config={"config": cfg, "provenance": provenance}, snapshot_id=None,
                     model_versions={"planner": PLANNER_VERSION}, assumptions=[], limitations=[reason],
                     summary=summary, duration_ms=duration_ms, make_current=False, core="supply", operation="plan")
    return {"run_id": run_id, "status": "completed", "quality_status": quality, "solver_status": "not_solved", "summary": summary}
