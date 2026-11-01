"""Evaluador de escenarios: compara estrategias sobre los mismos cortes, unidades y horizontes.

Cada entrada se clasifica como `observed` (dato histórico al corte), `forecasted` (salida de un modelo) o
`assumed` (supuesto del usuario). El resultado es una SIMULACIÓN CONDICIONADA, no una estimación causal: el
cambio de demanda es un supuesto y NO se deriva del cambio de precio.

Coherencia (por producto × ubicación y período):
    ventas atendibles = min(demanda, disponible)             disponible = existencias + recepciones
    existencias_cierre = disponible − ventas atendibles ≥ 0   (ventas perdidas: lo no atendido no se recupera)
    margen bruto = Σ ventas atendibles × (precio − costo)     (nunca sobre la demanda no atendida)
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np

OBSERVED, FORECASTED, ASSUMED = "observed", "forecasted", "assumed"
EPS = 1e-9


@dataclass
class ScenarioData:
    as_of: date
    currency: str
    period_days: int
    n_periods: int
    demand: dict[tuple[int, int], np.ndarray]  # pronóstico de la media por período
    opening: dict[tuple[int, int], float]
    receipts: dict[tuple[int, int], np.ndarray]  # recepciones conocidas por período
    receipts_assumed_qty: float = 0.0
    price: dict[int, float | None] = field(default_factory=dict)
    price_source: dict[int, str] = field(default_factory=dict)
    cost: dict[int, float | None] = field(default_factory=dict)
    cost_source: dict[int, str] = field(default_factory=dict)
    cost_forecast: dict[int, np.ndarray] = field(default_factory=dict)  # costo unitario por período
    plans: dict[int, list[dict]] = field(default_factory=dict)  # plan_run_id -> líneas de compra
    unit: dict[int, str | None] = field(default_factory=dict)
    meta: dict[str, Any] = field(default_factory=dict)


# Variaciones como FRACCIONES (0,05 = 5 %): [mínimo, máximo] del escalar y de cada elemento de una lista por período.
# Mismos límites que valida el backend (`ScenarioEvaluationController::PCT_LIMITS`); aquí son la última barrera: un valor
# fuera de rango (p. ej. −3 = −300 %) daría demanda, precio o costo negativos, es decir, resultados sin sentido.
PCT_LIMITS: dict[str, tuple[float, float]] = {
    "price_change_pct": (-0.9, 5.0), "discount_pct": (0.0, 0.95), "cost_change_pct": (-0.9, 5.0), "demand_change_pct": (-0.95, 5.0)}


def _series(value, n: int, name: str) -> np.ndarray:
    """Escalar o lista por período → arreglo de longitud n (validado contra `PCT_LIMITS`)."""
    if value is None:
        return np.zeros(n)
    scalar = isinstance(value, (int, float)) and not isinstance(value, bool)
    arr = np.array([float(value)] if scalar else [float(x) for x in value], dtype=float)
    lo, hi = PCT_LIMITS.get(name, (-math.inf, math.inf))
    if arr.size and (not np.isfinite(arr).all() or arr.min() < lo or arr.max() > hi):
        raise ValueError(f"{name} es una fracción (0,05 = 5 %) y debe estar entre {lo:g} y {hi:g}; se recibió {value!r}.")
    if arr.size == 0:
        return np.zeros(n)
    if scalar:
        return np.full(n, arr[0])
    if len(arr) < n:  # se mantiene el último valor declarado
        arr = np.concatenate([arr, np.full(n - len(arr), arr[-1])])
    return arr[:n]


def _plan_receipts(data: ScenarioData, plan_id: int, fill: float, delta_days: int) -> tuple[dict[tuple[int, int], np.ndarray], float]:
    out: dict[tuple[int, int], np.ndarray] = {}
    outlay = 0.0
    R, n = data.period_days, data.n_periods
    for ln in data.plans.get(plan_id, []):
        key = (int(ln["branch_id"]), int(ln["product_id"]))
        d = (ln["expected_receipt_date"] - data.as_of).days + int(delta_days)
        tau = 0 if d <= 1 else math.ceil((d - 1) / R)
        qty = float(ln["quantity"]) * fill
        if tau < n and key in data.demand:
            out.setdefault(key, np.zeros(n))[tau] += qty
        unit_cost = ln.get("unit_cost")
        if unit_cost is not None:
            outlay += qty * float(unit_cost)
    return out, outlay


def evaluate_strategy(data: ScenarioData, strat: dict[str, Any]) -> dict[str, Any]:
    n, R = data.n_periods, data.period_days
    price_chg = _series(strat.get("price_change_pct"), n, "price_change_pct")
    disc = _series(strat.get("discount_pct"), n, "discount_pct")
    cost_chg = _series(strat.get("cost_change_pct"), n, "cost_change_pct")
    dem_chg = _series(strat.get("demand_change_pct"), n, "demand_change_pct")
    fill = float(strat.get("supply_fill_rate", 1.0))
    delta_days = int(strat.get("lead_time_delta_days", 0))
    use_cf = bool(strat.get("use_cost_forecast", False))
    plan_id = strat.get("plan_run_id")
    plan_rcpt, outlay_plan_base = ({}, 0.0)
    if plan_id is not None:
        plan_rcpt, outlay_plan_base = _plan_receipts(data, int(plan_id), fill, delta_days)
    extra: dict[tuple[int, int], np.ndarray] = {}
    for e in strat.get("extra_supply") or []:
        k = (int(e["branch_id"]), int(e["product_id"]))
        if k in data.demand and 0 <= int(e["period"]) < n:
            extra.setdefault(k, np.zeros(n))[int(e["period"])] += float(e["units"])

    per_node: list[dict[str, Any]] = []
    totals = {"demand": 0.0, "sales": 0.0, "unmet": 0.0, "revenue": 0.0, "cogs": 0.0, "margin": 0.0,
              "revenue_with_cost": 0.0, "end_units": 0.0, "end_value": 0.0}
    revenue_unknown_cost = 0.0
    products_without_cost: set[int] = set()
    products_without_price: set[int] = set()
    by_period = np.zeros((n, 4))  # demanda, ventas, ingresos, margen
    checks = {"sales_le_demand": True, "sales_le_available": True, "inventory_nonnegative": True, "balance_identity": True}
    units = {data.unit.get(p) for (_, p) in data.demand}
    homogeneous = len(units) == 1
    for key, base_demand in data.demand.items():
        b, p = key
        demand = base_demand * (1.0 + dem_chg)
        receipts = data.receipts.get(key, np.zeros(n)).copy()
        if key in plan_rcpt:
            receipts += plan_rcpt[key]
        if key in extra:
            receipts += extra[key]
        base_price = data.price.get(p)
        base_cost = data.cost.get(p)
        cf = data.cost_forecast.get(p) if use_cf else None
        inv = float(data.opening.get(key, 0.0))
        node_sales = node_unmet = node_rev = node_cogs = 0.0
        for tau in range(n):
            avail = inv + float(receipts[tau])
            sales = min(float(demand[tau]), max(avail, 0.0))
            unmet = float(demand[tau]) - sales
            close = avail - sales
            if sales > demand[tau] + 1e-6:
                checks["sales_le_demand"] = False
            if sales > avail + 1e-6:
                checks["sales_le_available"] = False
            if close < -1e-6:
                checks["inventory_nonnegative"] = False
            if abs(inv + float(receipts[tau]) - sales - close) > 1e-6:
                checks["balance_identity"] = False
            inv = close
            price = None if base_price is None else base_price * (1.0 + price_chg[tau]) * (1.0 - disc[tau])
            if cf is not None:
                cost = float(cf[tau]) * (1.0 + cost_chg[tau])
            else:
                cost = None if base_cost is None else base_cost * (1.0 + cost_chg[tau])
            if price is None:
                products_without_price.add(p)
            else:
                rev = sales * price
                node_rev += rev
                totals["revenue"] += rev
                by_period[tau, 2] += rev
                if cost is None:
                    revenue_unknown_cost += rev
                    products_without_cost.add(p)
                else:
                    totals["revenue_with_cost"] += rev
                    totals["cogs"] += sales * cost
                    totals["margin"] += rev - sales * cost
                    by_period[tau, 3] += rev - sales * cost
                    node_cogs += sales * cost
            node_sales += sales
            node_unmet += unmet
            totals["demand"] += float(demand[tau])
            totals["sales"] += sales
            totals["unmet"] += unmet
            by_period[tau, 0] += float(demand[tau])
            by_period[tau, 1] += sales
        end_cost = (None if (cf is None and base_cost is None) else (float(cf[-1]) if cf is not None else base_cost))
        totals["end_units"] += inv
        if end_cost is not None:
            totals["end_value"] += inv * end_cost
        per_node.append({"branch_id": b, "product_id": p, "unit": data.unit.get(p), "demand": round(float(demand.sum()), 4),
                         "attainable_sales": round(node_sales, 4), "unmet": round(node_unmet, 4),
                         "ending_inventory": round(inv, 4), "revenue": round(node_rev, 2)})
    outlay = 0.0
    if plan_id is not None:
        outlay = outlay_plan_base * (1.0 + float(np.mean(cost_chg))) if cost_chg.any() else outlay_plan_base
    margin_known = totals["revenue_with_cost"] > 0
    return {
        "key": strat.get("key"), "name": strat.get("name"),
        "metrics": {
            "demand_units": round(totals["demand"], 4) if homogeneous else None,
            "attainable_sales_units": round(totals["sales"], 4) if homogeneous else None,
            "unmet_units": round(totals["unmet"], 4) if homogeneous else None,
            "fill_rate": round(totals["sales"] / totals["demand"], 4) if totals["demand"] > EPS else None,
            "revenue": round(totals["revenue"], 2), "cogs": round(totals["cogs"], 2) if margin_known else None,
            "gross_margin": round(totals["margin"], 2) if margin_known else None,
            "gross_margin_pct": round(totals["margin"] / totals["revenue_with_cost"], 4) if margin_known else None,
            "revenue_without_known_cost": round(revenue_unknown_cost, 2),
            "ending_inventory_units": round(totals["end_units"], 4) if homogeneous else None,
            "ending_inventory_value": round(totals["end_value"], 2),
            "plan_purchase_outlay": round(outlay, 2) if plan_id is not None else None,
            "units_note": None if homogeneous else "Productos con unidades distintas: no se suman unidades; solo importes.",
        },
        "periods": [{"period": t, "demand": round(float(by_period[t, 0]), 4) if homogeneous else None,
                     "attainable_sales": round(float(by_period[t, 1]), 4) if homogeneous else None,
                     "revenue": round(float(by_period[t, 2]), 2), "gross_margin": round(float(by_period[t, 3]), 2)}
                    for t in range(n)],
        "products_without_cost": sorted(products_without_cost), "products_without_price": sorted(products_without_price),
        "coherence": checks, "by_series": per_node[:500],
        "inputs": _classify(data, strat, use_cf, plan_id, fill),
    }


def _classify(data: ScenarioData, strat: dict[str, Any], use_cf: bool, plan_id, fill: float) -> list[dict[str, Any]]:
    inputs = [
        {"input": "demand_base", "classification": FORECASTED,
         "source": f"pronóstico de demanda (ejecución {data.meta.get('forecast_run_id')})"},
        {"input": "opening_inventory", "classification": OBSERVED, "source": "inventario al corte (existencias − reservas)"},
        {"input": "receipts_dated", "classification": OBSERVED, "source": "órdenes de compra abiertas con fecha prevista"},
        {"input": "price_base", "classification": OBSERVED,
         "source": "precio neto promedio reciente de ventas (respaldo: precio actual del catálogo)"},
        {"input": "unit_cost_base", "classification": FORECASTED if use_cf else OBSERVED,
         "source": "pronóstico de costos de compra" if use_cf else "último costo conocido al corte"},
    ]
    if data.receipts_assumed_qty > EPS:
        inputs.append({"input": "receipts_undated", "classification": ASSUMED,
                       "source": f"{data.receipts_assumed_qty:.0f} u. de órdenes sin fecha/vencidas con fecha supuesta por la política"})
    for key, label in (("demand_change_pct", "demand_change"), ("price_change_pct", "price_change"),
                       ("discount_pct", "discount"), ("cost_change_pct", "cost_change")):
        v = strat.get(key)
        if v not in (None, 0, 0.0, [], [0]) and (not isinstance(v, list) or any(x != 0 for x in v)):
            inputs.append({"input": label, "classification": ASSUMED, "source": "trayectoria declarada por el usuario"})
    if plan_id is not None:
        inputs.append({"input": "plan_receipts", "classification": ASSUMED,
                       "source": f"se supone que el plan de reposición {plan_id} se ejecuta y llega "
                                 f"({fill:.0%} de lo sugerido); las recomendaciones no emiten pedidos"})
    if strat.get("extra_supply"):
        inputs.append({"input": "extra_supply", "classification": ASSUMED, "source": "oferta adicional declarada"})
    if int(strat.get("lead_time_delta_days", 0)):
        inputs.append({"input": "lead_time_delta_days", "classification": ASSUMED, "source": "retraso/adelanto declarado"})
    return inputs


def evaluate_scenarios(data: ScenarioData, strategies: list[dict[str, Any]], max_strategies: int = 8) -> dict[str, Any]:
    if not strategies:
        raise ValueError("Se requiere al menos una estrategia.")
    if len(strategies) > max_strategies:
        raise ValueError(f"Máximo {max_strategies} estrategias.")
    results = []
    for i, s in enumerate(strategies):
        s = dict(s)
        s.setdefault("key", f"s{i + 1}")
        s.setdefault("name", s["key"])
        results.append(evaluate_strategy(data, s))
    base = results[0]["metrics"]
    for r in results:
        m = r["metrics"]
        r["delta_vs_first"] = {
            k: (None if m.get(k) is None or base.get(k) is None else round(m[k] - base[k], 4))
            for k in ("attainable_sales_units", "unmet_units", "revenue", "gross_margin", "ending_inventory_value")}
    ranked = sorted([r for r in results if r["metrics"]["gross_margin"] is not None],
                    key=lambda r: -r["metrics"]["gross_margin"])
    assumed = sorted({i["input"] for r in results for i in r["inputs"] if i["classification"] == ASSUMED})
    return {
        "common_basis": {"as_of": data.as_of.isoformat(), "horizon_days": data.n_periods * data.period_days,
                         "period_days": data.period_days, "currency": data.currency,
                         "series": len(data.demand), "forecast_run_id": data.meta.get("forecast_run_id"),
                         "note": "Todas las estrategias parten del mismo corte, el mismo pronóstico base, las mismas "
                                 "unidades y el mismo horizonte."},
        "strategies": results,
        "ranking_by_gross_margin": [r["key"] for r in ranked],
        "assumed_inputs": assumed,
        "classification_summary": {c: sorted({i["input"] for r in results for i in r["inputs"] if i["classification"] == c})
                                   for c in (OBSERVED, FORECASTED, ASSUMED)},
        "disclaimer": "Simulación condicionada a las entradas declaradas. El cambio de demanda es un supuesto y NO se "
                      "deriva del cambio de precio; no es una estimación causal.",
    }
