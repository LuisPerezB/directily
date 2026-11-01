"""Simulación histórica de operación sobre los datos de una empresa (core_runs: supply, operation = simulate)."""

from __future__ import annotations

import time
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.inventory.recommendations import resolve_lead_time
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot
from predictily_engine.supply.runs import complete_run
from predictily_engine.supply.simulation import (
    CurrentPolicy,
    PlannerPolicy,
    ReferencePolicy,
    SimInputs,
    compare_policies,
)

TARGET_DEFINITION = {
    "metric": "historical_operation_simulation",
    "description": "Re-juego de decisiones de reposición en cortes históricos con información disponible a cada fecha; "
                   "mide demanda no atendida, sobrestock, nivel de servicio, días de inventario, costos y capital.",
}
MIN_HISTORY_DAYS = 90
MAX_SERIES = 400


def build_inputs(repo: SupplyRepository, tenant: dict[str, Any], opts: dict[str, Any], cfg: dict[str, Any]) -> tuple[SimInputs | None, dict]:
    as_of = date.fromisoformat(str(opts["as_of"])[:10]) if opts.get("as_of") else repo.last_sale_date()
    if as_of is None:
        return None, {"reason": "No hay ventas registradas."}
    days = int(opts.get("days") or 90)
    review = int(opts.get("review_days") or cfg.get("review_period_days", 7))
    start_date = as_of - timedelta(days=days - 1)
    sales = repo.daily_sales(as_of)
    if sales.empty:
        return None, {"reason": "No hay ventas válidas."}
    sales["ds"] = pd.to_datetime(sales["ds"])
    first = sales["ds"].min().date()
    if (start_date - first).days < MIN_HISTORY_DAYS:
        return None, {"reason": f"Se requieren al menos {MIN_HISTORY_DAYS} días de historia antes de la ventana simulada."}
    dates = pd.date_range(first, as_of, freq="D")
    inv0 = repo.inventory(start_date - timedelta(days=1), start_date - timedelta(days=1))
    if inv0.empty:
        return None, {"reason": "No hay un snapshot de inventario al inicio de la ventana: las existencias iniciales son desconocidas."}
    inv0 = inv0.set_index(["branch_id", "product_id"])
    recent = sales[sales["ds"] >= pd.Timestamp(start_date - timedelta(days=180))]
    keys_all = sorted({(int(b), int(p)) for b, p in recent[["branch_id", "product_id"]].drop_duplicates().itertuples(index=False)} & set(inv0.index))
    products = repo.products().set_index("product_id")
    prices = repo.recent_net_prices(as_of, 28)
    price_map = {int(r.product_id): float(r.price) for r in prices.itertuples(index=False) if pd.notna(r.price)}
    costs = repo.last_purchase_costs(as_of, True)
    lt, sup = repo.lead_times(), repo.suppliers()
    keys, uc, pr, lead, lstd, excluded = [], [], [], [], [], []
    for k in keys_all:
        b, p = k
        row = inv0.loc[k]
        row = row.iloc[-1] if isinstance(row, pd.DataFrame) else row
        cost = costs.get(p, {}).get("unit_cost")
        if cost is None and pd.notna(row.get("unit_cost")):
            cost = float(row["unit_cost"])
        if cost is None and p in products.index and pd.notna(products.loc[p, "current_cost"]):
            cost = float(products.loc[p, "current_cost"])
        if cost is None:
            excluded.append({"branch_id": b, "product_id": p, "reason": "sin costo conocido"})
            continue
        price = price_map.get(p) or (float(products.loc[p, "current_price"]) if p in products.index and pd.notna(products.loc[p, "current_price"]) else cost)
        d, s, _src = resolve_lead_time(p, b, lt, sup, int(cfg.get("default_lead_time_days", 7)))
        keys.append(k)
        uc.append(cost)
        pr.append(price)
        lead.append(d)
        lstd.append(s or 0.0)
    if not keys:
        return None, {"reason": "Ninguna serie tiene existencias iniciales, costo y ventas.", "excluded": excluded}
    if len(keys) > MAX_SERIES:
        excluded.append({"reason": f"Se simularon {MAX_SERIES} de {len(keys)} series (límite)."})
        keys, uc, pr, lead, lstd = keys[:MAX_SERIES], uc[:MAX_SERIES], pr[:MAX_SERIES], lead[:MAX_SERIES], lstd[:MAX_SERIES]
    pos = {k: i for i, k in enumerate(keys)}
    D = len(dates)
    mat = np.zeros((D, len(keys)))
    di = {d: i for i, d in enumerate(dates)}
    sel = sales[[(int(b), int(p)) in pos for b, p in zip(sales["branch_id"], sales["product_id"])]]
    mat[[di[d] for d in sel["ds"]], [pos[(int(b), int(p))] for b, p in zip(sel["branch_id"], sel["product_id"])]] = sel["units"].to_numpy()
    init = np.array([max(float(inv0.loc[k]["on_hand"] if not isinstance(inv0.loc[k], pd.DataFrame) else inv0.loc[k].iloc[-1]["on_hand"]) -
                         float((inv0.loc[k]["reserved"] if not isinstance(inv0.loc[k], pd.DataFrame) else inv0.loc[k].iloc[-1]["reserved"]) or 0.0), 0.0)
                     for k in keys])
    inp = SimInputs(dates=dates, demand=mat, sold_hist=mat.copy(), start=D - days, n_days=days, init_inv=init, keys=keys,
                    unit_cost=np.array(uc), price=np.array(pr), lead=np.array(lead, dtype=int), lead_std=np.array(lstd),
                    review_days=review, service_level=float(cfg.get("service_level", 0.95)),
                    holding_rate=float(cfg.get("holding_cost_rate_annual", 0.20)), seed=int(opts.get("seed", 7)),
                    demand_basis="observed_sales_censored")
    return inp, {"excluded": excluded, "as_of": as_of}


def run_simulation(engine, tenant_id: int, run: dict[str, Any], job_id: int | None, progress, t0: float) -> dict[str, Any]:
    run_id = int(run["id"])
    opts = run.get("options") or {}
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        tenant = repo.tenant()
        cfg, cfg_version, provenance = resolve_config("supply", tenant["settings"], tenant.get("sector"), opts.get("config") or None)
        progress("loading_data", 15)
        inp, info = build_inputs(repo, tenant, opts, cfg)
    limitations = ["No reemplaza la validación con datos reales: la demanda es la venta observada (censurada por quiebres de stock)."]
    if inp is None:
        reason = info.get("reason", "Datos insuficientes.")
        result, quality, as_of = {"reason": reason}, "insufficient_data", None
        limitations.append(reason)
    else:
        progress("simulating", 40)
        policies = [CurrentPolicy(), ReferencePolicy(), PlannerPolicy(time_limit=float(cfg.get("solver_time_limit_seconds", 30)))]
        result = compare_policies(inp, policies)
        result["excluded_series"] = info["excluded"][:50]
        quality, as_of = "degraded", info["as_of"]
        if info["excluded"]:
            limitations.append(f"{len(info['excluded'])} serie(s) excluidas (ver detalle).")
    duration_ms = int((time.perf_counter() - t0) * 1000)
    snap = DataSnapshot("supply", as_of or date.today())
    snap.add_value("request", opts)
    if inp is not None:
        snap.add_value("keys", [list(k) for k in inp.keys])
        snap.add_value("matrix", [round(float(x), 3) for x in inp.demand.sum(axis=1)[-400:]])
    with engine.begin() as conn:
        snap_id = persist_snapshot(conn, tenant_id, snap)
        complete_run(conn, tenant_id, run_id, job_id, result_type="evaluation", quality_status=quality, uncertainty_source="none",
                     as_of=as_of, horizon_start=(as_of - timedelta(days=int(opts.get("days") or 90) - 1)) if as_of else None,
                     horizon_end=as_of, target_definition=TARGET_DEFINITION, config_version=cfg_version,
                     effective_config={"config": cfg, "provenance": provenance}, snapshot_id=snap_id,
                     model_versions={"simulation": "supply-sim-2026.10", "forecaster": "weekday_level_28d"},
                     assumptions=(result.get("caveats") or []), limitations=limitations,
                     summary={**{k: v for k, v in result.items() if k != "caveats"}, "duration_ms": duration_ms},
                     duration_ms=duration_ms, make_current=False, core="supply", operation="simulate")
    return {"run_id": run_id, "status": "completed", "quality_status": quality, "summary": {"duration_ms": duration_ms}}
