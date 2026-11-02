"""Simulación histórica de operación sobre los datos de una empresa (core_runs: supply, operation = simulate)."""

from __future__ import annotations

import time
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.inventory.lots import lots_asof, reconcile_lots
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot
from predictily_engine.supply.asof_inputs import asof_costs, asof_series_tables
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
    """Insumos de la simulación sobre los datos de la empresa, TODOS as-of (V2.5 · W5-D; docs/supply/SUPPLY_CORE.md §4).

    - Ventas: la «verdad» de la ventana es lo conocido hasta `asof_knowledge` (hoy si la ventana termina en el último dato; el propio corte en una
      reconstrucción histórica). En cada revisión las ventas PREVIAS a la ventana son las conocidas ese día (`pre_history`): un documento tardío
      no altera decisiones anteriores a su llegada.
    - Costo de compra, precio neto reciente, plazos (`valid_from`) y existencias/lotes: los vigentes en cada revisión (matrices `*_t`). Ya no se
      usa el costo ni el precio del catálogo vigente (información posterior al corte).
    """
    as_of = date.fromisoformat(str(opts["as_of"])[:10]) if opts.get("as_of") else repo.last_sale_date()
    if as_of is None:
        return None, {"reason": "No hay ventas registradas."}
    days = int(opts.get("days") or 90)
    review = int(opts.get("review_days") or cfg.get("review_period_days", 7))
    start_date = as_of - timedelta(days=days - 1)
    knowledge = repo.asof_knowledge(as_of)
    sales = repo.daily_sales(as_of, knowledge)
    if sales.empty:
        return None, {"reason": "No hay ventas válidas."}
    sales["ds"] = pd.to_datetime(sales["ds"])
    first = sales["ds"].min().date()
    if (start_date - first).days < MIN_HISTORY_DAYS:
        return None, {"reason": f"Se requieren al menos {MIN_HISTORY_DAYS} días de historia antes de la ventana simulada."}
    dates = pd.date_range(first, as_of, freq="D")
    cut0 = start_date - timedelta(days=1)
    inv0 = repo.latest_inventory(cut0)
    if inv0.empty:
        return None, {"reason": "No hay un snapshot de inventario al inicio de la ventana: las existencias iniciales son desconocidas."}
    stale_days = int(cfg.get("inventory_stale_days", 30))
    inv0["_age"] = [(cut0 - pd.Timestamp(d).date()).days for d in inv0["ds"]]
    stale_inv = inv0[inv0["_age"] > stale_days]
    inv0 = inv0[inv0["_age"] <= stale_days].set_index(["branch_id", "product_id"])
    recent = sales[sales["ds"] >= pd.Timestamp(start_date - timedelta(days=180))]
    keys_all = sorted({(int(b), int(p)) for b, p in recent[["branch_id", "product_id"]].drop_duplicates().itertuples(index=False)} & set(inv0.index))
    suppliers = repo.suppliers()
    cost_history = repo.purchase_cost_history()
    default_lead = int(cfg.get("default_lead_time_days", 7))
    excluded: list[dict[str, Any]] = [{"branch_id": int(r.branch_id), "product_id": int(r.product_id), "reason": f"existencias iniciales con más de {stale_days} días de antigüedad"}
                                      for r in stale_inv.itertuples(index=False)][:50]
    # Costo de respaldo: 1.º el del snapshot de existencias del inicio de la ventana (también as-of); 2.º el costo del CATÁLOGO vigente, que NO está versionado
    # (es información posterior al inicio de la ventana): solo para que una empresa sin historial de compras ni costo en el snapshot no pierda sus series, y se
    # declara en los supuestos de la simulación.
    fallback_cost: dict[tuple[int, int], float] = {}
    catalog_keys: set[tuple[int, int]] = set()
    catalog_cost = {int(r.product_id): float(r.current_cost) for r in repo.products().itertuples(index=False) if pd.notna(r.current_cost)}
    for k_ in keys_all:
        row = inv0.loc[k_]
        row = row.iloc[-1] if isinstance(row, pd.DataFrame) else row
        if pd.notna(row.get("unit_cost")):
            fallback_cost[k_] = float(row["unit_cost"])
        elif k_[1] in catalog_cost:
            fallback_cost[k_] = catalog_cost[k_[1]]
            catalog_keys.add(k_)
    tables = asof_series_tables(
        keys=keys_all, dates=dates, start=len(dates) - days, n_days=days, review_days=review, cost_history=cost_history,
        price_at=lambda cut: {int(r.product_id): float(r.price) for r in repo.recent_net_prices(cut, 28, knowledge=cut).itertuples(index=False) if pd.notna(r.price)},
        lead_times_at=repo.lead_times_asof, suppliers=suppliers, default_lead=default_lead, fallback_cost=fallback_cost)
    keep = [i for i, ok in enumerate(tables["keep"]) if ok]
    excluded += tables["excluded"]
    keys = [keys_all[i] for i in keep]
    if not keys:
        return None, {"reason": "Ninguna serie tiene existencias iniciales, costo y ventas.", "excluded": excluded}
    if len(keys) > MAX_SERIES:
        excluded.append({"reason": f"Se simularon {MAX_SERIES} de {len(keys)} series (límite)."})
        keep, keys = keep[:MAX_SERIES], keys[:MAX_SERIES]
    pos = {k: i for i, k in enumerate(keys)}
    D = len(dates)
    start_idx = D - days
    mat = np.zeros((D, len(keys)))
    di = {d: i for i, d in enumerate(dates)}
    sel = sales[[(int(b), int(p)) in pos for b, p in zip(sales["branch_id"], sales["product_id"])]]
    mat[[di[d] for d in sel["ds"]], [pos[(int(b), int(p))] for b, p in zip(sel["branch_id"], sel["product_id"])]] = sel["units"].to_numpy()
    rows = [inv0.loc[k] if not isinstance(inv0.loc[k], pd.DataFrame) else inv0.loc[k].iloc[-1] for k in keys]
    on_hand = np.array([float(r["on_hand"]) for r in rows])
    reserved = np.array([float(r["reserved"] or 0.0) for r in rows])
    init = np.maximum(on_hand - reserved, 0.0)
    shelf, init_lots, notes = None, None, []
    if bool(cfg.get("perishability_enabled", True)):
        shelf, init_lots, init, notes = _perishability_at_start(repo, cfg, keys, rows, on_hand, reserved, init, cut0, start_idx)
    t_cost, t_price = tables["cost_t"][:, keep][:, : len(keys)], tables["price_t"][:, keep][:, : len(keys)]
    t_lead, t_std = tables["lead_t"][:, keep][:, : len(keys)], tables["lead_std_t"][:, keep][:, : len(keys)]
    inp = SimInputs(dates=dates, demand=mat, sold_hist=mat.copy(), start=start_idx, n_days=days, init_inv=init, keys=keys,
                    unit_cost=t_cost[0], price=t_price[0], lead=t_lead[0], lead_std=t_std[0],
                    review_days=review, service_level=float(cfg.get("service_level", 0.95)),
                    holding_rate=float(cfg.get("holding_cost_rate_annual", 0.20)), seed=int(opts.get("seed", 7)),
                    demand_basis="observed_sales_censored", unit_cost_t=t_cost, price_t=t_price, lead_t=t_lead, lead_std_t=t_std,
                    pre_history=_pre_history_factory(repo, as_of, knowledge, dates, start_idx, pos, di), shelf_life=shelf, init_lots=init_lots,
                    notes=notes)
    first_cut = dates[start_idx].date()
    known_first = asof_costs(cost_history, first_cut)
    used_catalog = sorted(k_ for k_ in catalog_keys if k_ in pos and k_[1] not in known_first)
    if used_catalog:
        inp.notes.append(f"{len(used_catalog)} serie(s) sin historial de compras ni costo en el snapshot usan el costo del catálogo vigente (NO VERSIONADO): es una "
                         "información posterior al inicio de la ventana, de modo que su costo, valoración y margen no son as-of.")
    if tables["series_without_price"]:
        inp.notes.append(f"{tables['series_without_price']} serie(s) sin ventas recientes al inicio de la ventana: su precio se toma de la primera venta posterior "
                         "conocida o, si nunca hubo, el margen perdido es 0 (no se usa el precio del catálogo vigente).")
    return inp, {"excluded": excluded, "as_of": as_of, "knowledge": knowledge}


def _pre_history_factory(repo: SupplyRepository, as_of: date, knowledge: date, dates: pd.DatetimeIndex, start: int, pos: dict, di: dict):
    """Ventas de los días previos a la ventana tal como se conocían al cierre del día `d` (lectura as-of de W1). `None` si coinciden con las
    finales (la gran mayoría de las cargas históricas: no hay documentos tardíos ni corregidos)."""
    versions = repo.sales_versions(as_of, knowledge)
    cache: dict[int, np.ndarray | None] = {}
    window_start = dates[start]

    def pre_history(d: int) -> np.ndarray | None:
        if d in cache:
            return cache[d]
        cut = dates[d]
        if not versions.differs_from_truth(cut):
            cache[d] = None
            return None
        cells = versions.sales_cells(cut, cut)
        cells = cells[pd.to_datetime(cells["ds"]) < window_start]
        out = np.zeros((start, len(pos)))
        for r in cells.itertuples(index=False):
            key = (int(r.branch_id), int(r.product_id))
            if key in pos:
                out[di[pd.Timestamp(r.ds)], pos[key]] += float(r.units)
        cache[d] = out
        return out

    return pre_history


def _perishability_at_start(repo, cfg, keys, rows, on_hand, reserved, init, cut0: date, start_idx: int):
    """Vida útil por serie y lotes con vencimiento CONOCIDOS al inicio de la ventana (foto de lotes ≤ corte). Las unidades vencidas al corte se
    quitan de la existencia inicial (están en el estante pero no se pueden vender)."""
    perish = repo.product_perishability()
    lots = lots_asof(repo.inventory_lots(cut0), cut0)
    if (perish.empty or not bool(perish["is_perishable"].any())) and lots.empty:
        return None, None, init, []
    pinfo = perish.set_index("product_id") if not perish.empty else pd.DataFrame()
    lots_idx = {(int(b), int(p)): g for (b, p), g in lots.groupby(["branch_id", "product_id"])} if not lots.empty else {}
    shelf = np.zeros(len(keys))
    init_lots: list[list[tuple[int, float]]] = [[] for _ in keys]
    init = init.copy()
    expired_total = 0.0
    max_age = int(cfg.get("perishability_lots_max_age_days", 7))
    for i, key in enumerate(keys):
        if not pinfo.empty and key[1] in pinfo.index and bool(pinfo.loc[key[1], "is_perishable"]) and pd.notna(pinfo.loc[key[1], "shelf_life_days"]):
            shelf[i] = float(pinfo.loc[key[1], "shelf_life_days"])
        pos = reconcile_lots(lots_idx.get(key), on_hand[i], reserved[i], cut0, pd.Timestamp(rows[i]["ds"]).date(), max_age,
                             str(cfg.get("perishability_excess_drop", "latest_first")), str(cfg.get("perishability_expired_stock_mode", "in_on_hand")))
        if pos.status == "configured":
            init_lots[i] = [(start_idx - 1 + d, q) for d, q, _c in pos.lots]
            init[i] = max(init[i] - pos.expired_deducted, 0.0)
            expired_total += pos.expired_deducted
    notes = []
    if shelf.any() or any(init_lots):
        notes.append("Perecibilidad: las series con vida útil declarada o lotes con vencimiento conocidos al inicio de la ventana pierden por vencimiento lo que no se "
                     "vende antes (FEFO); las compras nuevas llegan con la vida útil completa. La métrica `expired_units` mide la merma de cada política.")
    if expired_total > 1e-9:
        notes.append(f"{expired_total:g} u. vencidas al inicio de la ventana se excluyeron de la existencia inicial.")
    return shelf, init_lots, init, notes


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
