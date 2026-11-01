"""Ejecución del evaluador de escenarios (core_runs: core = scenario, operation = evaluate)."""

from __future__ import annotations

import math
import time
from datetime import timedelta
from typing import Any

import numpy as np
import pandas as pd
from sqlalchemy import text

from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.db.writer import jdump
from predictily_engine.inventory.recommendations import resolve_lead_time
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot
from predictily_engine.scenarios.evaluator import ScenarioData, evaluate_scenarios
from predictily_engine.supply.balance import build_receipts, lead_time_evidence, receipts_by_period
from predictily_engine.supply.runs import complete_run

TARGET_DEFINITION = {
    "metric": "scenario_comparison",
    "description": "Comparación de estrategias sobre los mismos cortes, unidades y horizonte: ventas atendibles = "
                   "min(demanda, disponible), inventario y margen coherentes. Simulación condicionada.",
}


def build_data(repo: SupplyRepository, tenant: dict[str, Any], opts: dict[str, Any], cfg: dict[str, Any]) -> tuple[ScenarioData | None, dict]:
    fr = repo.forecast_run_for_plan(opts.get("forecast_run_id"))
    if fr is None:
        return None, {"reason": "No hay un pronóstico de demanda completado."}
    as_of = pd.Timestamp(fr["cutoff_date"]).date()
    retro = fr["run_type"] == "historical"
    R = int(opts.get("period_days") or cfg.get("period_days", 7))
    horizon = int(opts.get("horizon_days") or cfg.get("horizon_days", 90))
    n = max(1, math.ceil(horizon / R))
    filters = opts.get("filters") or {}
    fc = repo.series_forecast(int(fr["id"]))
    products = repo.products()
    if filters.get("product_id") is not None:
        fc = fc[fc["product_id"] == int(filters["product_id"])]
    if filters.get("branch_id") is not None:
        fc = fc[fc["branch_id"] == int(filters["branch_id"])]
    if filters.get("category_id") is not None:
        ids = set(products[products["category_id"] == int(filters["category_id"])]["product_id"])
        fc = fc[fc["product_id"].isin(ids)]
    inv = repo.latest_inventory(as_of)
    inv_idx = inv.set_index(["branch_id", "product_id"]) if not inv.empty else None
    open_pos = repo.open_purchase_orders(as_of if retro else None)
    orders = {(int(b), int(p)): g for (b, p), g in open_pos.groupby(["branch_id", "product_id"])} if not open_pos.empty else {}
    lt = repo.lead_times()
    sup = repo.suppliers()
    ev = lead_time_evidence(repo.po_history(as_of), as_of)
    observed = {int(r.supplier_id): float(r.mean_lead) for r in ev.itertuples(index=False)} if not ev.empty else {}
    demand, opening, receipts = {}, {}, {}
    assumed_qty = 0.0
    skipped_no_inv = 0
    for (b, p), g in fc.groupby(["branch_id", "product_id"]):
        key = (int(b), int(p))
        if inv_idx is None or key not in inv_idx.index:
            skipped_no_inv += 1  # existencias desconocidas: no se asumen cero
            continue
        g = g.sort_values("horizon_step")
        steps = g["horizon_step"].to_numpy(dtype=int)
        covered = int(np.cumprod(steps == np.arange(1, len(steps) + 1)).sum())
        periods = min(n, covered // R)
        if periods < n:
            continue  # el escenario exige el mismo horizonte para todas las series (sin extrapolar)
        y = np.clip(np.nan_to_num(g["yhat"].to_numpy(dtype=float)[: n * R]), 0, None)
        demand[key] = y.reshape(n, R).sum(axis=1)
        row = inv_idx.loc[key]
        row = row.iloc[-1] if isinstance(row, pd.DataFrame) else row
        opening[key] = max(float(row["on_hand"]) - float(row.get("reserved") or 0.0), 0.0)
        lt_days, _s, _src = resolve_lead_time(key[1], key[0], lt, sup, int(cfg.get("default_lead_time_days", 7)))
        rc, _info = build_receipts(orders.get(key), float(row.get("in_transit") or 0.0), lt_days, as_of,
                                   str(opts.get("undated_receipt_policy", "lead_time_end")), None, observed)
        assumed_qty += sum(r.qty for r in rc if r.assumed)
        receipts[key] = receipts_by_period(rc, n, R)
    prices = repo.recent_net_prices(as_of, int(cfg.get("price_window_days", 28)))
    price = {int(r.product_id): float(r.price) for r in prices.itertuples(index=False) if pd.notna(r.price)}
    price_source = {p: "ventas recientes" for p in price}
    unit, cost, cost_source = {}, {}, {}
    last_costs = repo.last_purchase_costs(as_of, retro)
    for r in products.itertuples(index=False):
        pid = int(r.product_id)
        unit[pid] = None if pd.isna(r.unit) else str(r.unit)
        if pid not in price and pd.notna(r.current_price):
            price[pid], price_source[pid] = float(r.current_price), "precio actual del catálogo"
        if pid in last_costs:
            cost[pid], cost_source[pid] = last_costs[pid]["unit_cost"], last_costs[pid]["source"]
    cost_fc: dict[int, np.ndarray] = {}
    cfdf = repo.cost_forecast_rows(opts.get("cost_forecast_run_id"))
    if not cfdf.empty:
        for r in cfdf.itertuples(index=False):
            if r.method == "none" or not r.points:
                continue
            arr = np.zeros(n)
            pts = sorted(r.points, key=lambda x: x["target_date"])
            for tau in range(n):
                mid = as_of + timedelta(days=tau * R + R // 2)
                chosen = None
                for pt in pts:
                    if pd.Timestamp(pt["target_date"]).date() <= mid:
                        chosen = pt["yhat"]
                if chosen is None:
                    chosen = pts[0]["yhat"] if pts else None
                arr[tau] = chosen if chosen is not None else math.nan
            if not np.isnan(arr).any():
                cost_fc.setdefault(int(r.product_id), arr)
    plans = {}
    for s in opts.get("strategies") or []:
        pid = s.get("plan_run_id")
        if pid is not None and int(pid) not in plans:
            df = repo.plan_lines(int(pid))
            plans[int(pid)] = [
                {"branch_id": int(x.branch_id), "product_id": int(x.product_id), "quantity": float(x.quantity),
                 "expected_receipt_date": pd.Timestamp(x.expected_receipt_date).date(),
                 "unit_cost": None if pd.isna(x.unit_cost) else float(x.unit_cost)}
                for x in df.itertuples(index=False) if x.line_type == "purchase"]
    data = ScenarioData(as_of=as_of, currency=str(tenant.get("currency") or "USD"), period_days=R, n_periods=n,
                        demand=demand, opening=opening, receipts=receipts, receipts_assumed_qty=assumed_qty,
                        price=price, price_source=price_source, cost=cost, cost_source=cost_source, cost_forecast=cost_fc,
                        plans=plans, unit=unit, meta={"forecast_run_id": int(fr["id"]), "skipped_no_inventory": skipped_no_inv,
                                                      "retroactive": retro})
    return data, fr


def run_scenario_evaluation(engine, tenant_id: int, run: dict[str, Any], job_id: int | None, progress, t0: float) -> dict[str, Any]:
    run_id = int(run["id"])
    opts = run.get("options") or {}
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        tenant = repo.tenant()
        cfg, cfg_version, provenance = resolve_config("scenario", tenant["settings"], tenant.get("sector"), opts.get("config") or None)
        sup_cfg, _v, _p = resolve_config("supply", tenant["settings"], tenant.get("sector"))
        cfg = {**sup_cfg, **cfg}
        progress("loading_data", 20)
        data, fr = build_data(repo, tenant, opts, cfg)
    duration = lambda: int((time.perf_counter() - t0) * 1000)  # noqa: E731
    limitations: list[str] = []
    if data is None or not data.demand:
        reason = (fr or {}).get("reason") or "Ninguna serie con pronóstico que cubra el horizonte y con inventario conocido."
        limitations.append(reason)
        results = {"strategies": [], "reason": reason}
        quality = "insufficient_data"
        as_of = data.as_of if data else None
    else:
        progress("evaluating", 60)
        results = evaluate_scenarios(data, opts.get("strategies") or [{"key": "base", "name": "Base (sin cambios)"}],
                                     int(cfg.get("max_strategies", 8)))
        results["input_series"] = {"count": len(data.demand), "skipped_without_inventory": data.meta["skipped_no_inventory"]}
        as_of = data.as_of
        if data.meta["skipped_no_inventory"]:
            limitations.append(f"{data.meta['skipped_no_inventory']} serie(s) sin inventario conocido quedaron fuera (no se asumen cero).")
        missing_cost = sorted({p for r in results["strategies"] for p in r["products_without_cost"]})
        if missing_cost:
            limitations.append(f"Sin costo conocido para {len(missing_cost)} producto(s): su margen es desconocido, no cero.")
        if data.receipts_assumed_qty > 0:
            limitations.append("Recepciones sin fecha con fecha supuesta por la política (clasificadas como supuesto).")
        quality = "degraded" if limitations else "ok"
    horizon = int(opts.get("horizon_days") or cfg.get("horizon_days", 90))
    assumptions = ["El cambio de demanda es un supuesto del usuario y NO se deriva del cambio de precio (no es causal).",
                   "Ventas perdidas: la demanda no atendida no se recupera.",
                   "El costo de venta usa la trayectoria de costo del período (reposición), no costeo FIFO."]
    snap = DataSnapshot("scenario", as_of or pd.Timestamp.today().date())
    snap.add_value("request", opts)
    if data is not None:
        snap.add_value("demand", {f"{k[0]}:{k[1]}": [round(float(x), 4) for x in v] for k, v in sorted(data.demand.items())})
        snap.add_value("prices_costs", {"price": {str(k): v for k, v in sorted(data.price.items())},
                                        "cost": {str(k): v for k, v in sorted(data.cost.items())}})
    progress("persisting", 85)
    with engine.begin() as conn:
        snap_id = persist_snapshot(conn, tenant_id, snap)
        conn.execute(text("DELETE FROM scenario_evaluations WHERE tenant_id = :t AND core_run_id = :r"), {"t": tenant_id, "r": run_id})
        conn.execute(text(
            "INSERT INTO scenario_evaluations (tenant_id, core_run_id, user_id, name, horizon_days, request, results, created_at, updated_at) "
            "VALUES (:t, :r, (SELECT user_id FROM core_runs WHERE id = :r AND tenant_id = :t), :n, :h, CAST(:req AS jsonb), "
            "CAST(:res AS jsonb), now(), now())"),
            {"t": tenant_id, "r": run_id, "n": opts.get("name"), "h": horizon, "req": jdump(opts), "res": jdump(results)})
        complete_run(conn, tenant_id, run_id, job_id, result_type="conditional_simulation", quality_status=quality,
                     uncertainty_source="none", as_of=as_of, horizon_start=(as_of + timedelta(days=1)) if as_of else None,
                     horizon_end=(as_of + timedelta(days=horizon)) if as_of else None, target_definition=TARGET_DEFINITION,
                     config_version=cfg_version, effective_config={"config": cfg, "provenance": provenance}, snapshot_id=snap_id,
                     model_versions={"evaluator": "scenario-eval-2026.10"}, assumptions=assumptions, limitations=limitations,
                     summary={"strategies": len(results.get("strategies", [])), "assumed_inputs": results.get("assumed_inputs", []),
                              "ranking_by_gross_margin": results.get("ranking_by_gross_margin"), "duration_ms": duration()},
                     duration_ms=duration(), make_current=False, core="scenario", operation="evaluate", horizons=[horizon])
    return {"run_id": run_id, "status": "completed", "quality_status": quality,
            "summary": {"strategies": len(results.get("strategies", [])), "duration_ms": duration()}}
