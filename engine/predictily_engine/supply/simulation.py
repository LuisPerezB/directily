"""Simulación histórica de operación (E2-20, instrucctions.md §6.F último párrafo).

Re-juega decisiones de reposición en cortes históricos usando SOLO la información disponible a cada fecha:
en cada revisión el pronóstico se calcula con las ventas observadas hasta ese día (bajo la propia trayectoria de la
política evaluada), nunca con las posteriores. Compara políticas sobre la MISMA demanda, las MISMAS existencias
iniciales y los MISMOS retrasos aleatorios de proveedor (números aleatorios comunes).

Políticas: `current` (revisión periódica de la etapa 1, `inventory.recommendations`), `reference` (mín-máx simple
con días de cobertura fijos) y `planner` (MILP de esta etapa, solo se ejecuta la emisión del día).

Medidas: demanda no atendida, nivel de servicio (por unidades y por días), sobrestock respecto de la demanda realizada
de los próximos `overstock_days`, días de inventario, costos de mantenimiento y adquisición, margen perdido y
capital comprometido (inventario + órdenes en camino).

NO reemplaza la validación con datos reales: con datos sintéticos la demanda verdadera es conocida; con datos de una
empresa la demanda es la venta observada (censurada por quiebres) y el faltante queda subestimado.
"""

from __future__ import annotations

import math
import time
from dataclasses import dataclass, field
from typing import Any, Callable

import numpy as np
import pandas as pd
from scipy.stats import norm

from predictily_engine.inventory.recommendations import LeadTimeErrorModel, build_recommendations
from predictily_engine.supply.model import BudgetSpec, NodeSpec, OfferSpec, PlanProblem
from predictily_engine.supply.service import solve_plan

Z80 = float(norm.ppf(0.90))
EMPTY_BT = pd.DataFrame(columns=["unique_id", "window", "step", "actual", "yhat", "stockout", "scale"])


@dataclass
class SimInputs:
    dates: pd.DatetimeIndex
    demand: np.ndarray  # (D, N) demanda de referencia para evaluar
    sold_hist: np.ndarray  # (D, N) ventas observadas antes de la ventana (censuradas)
    start: int
    n_days: int
    init_inv: np.ndarray
    keys: list[tuple[int, int]]
    unit_cost: np.ndarray
    price: np.ndarray
    lead: np.ndarray  # plazo nominal (días)
    lead_std: np.ndarray
    review_days: int = 7
    service_level: float = 0.95
    overstock_days: int = 60
    holding_rate: float = 0.20
    order_cost: float = 0.0
    seed: int = 7
    demand_basis: str = "true_demand_synthetic"


# ───────────────────────────── pronosticador ligero (solo pasado) ─────────────────────────────

def weekday_forecast(hist: np.ndarray, last_date: pd.Timestamp, H: int) -> tuple[np.ndarray, np.ndarray]:
    """Pronóstico (H, N) = nivel de 28 días × factor de día de semana de 8 semanas; σ diaria por serie.

    Usa únicamente `hist` (ventas observadas hasta `last_date`). Devuelve (yhat, sigma).
    """
    t, n = hist.shape
    w = min(28, t)
    level = hist[t - w:].mean(axis=0) if w else np.zeros(n)
    lookback = min(56, t)
    block = hist[t - lookback:]
    dows = ((last_date - pd.Timedelta(days=lookback - 1)) + pd.to_timedelta(np.arange(lookback), unit="D")).dayofweek.to_numpy()
    overall = np.maximum(block.mean(axis=0), 1e-9)
    factors = np.ones((7, n))
    for dw in range(7):
        sel = block[dows == dw]
        if len(sel):
            factors[dw] = np.clip(sel.mean(axis=0) / overall, 0.3, 3.0)
    factors[:, block.mean(axis=0) < 1e-6] = 1.0
    fdow = ((last_date + pd.to_timedelta(np.arange(1, H + 1), unit="D")).dayofweek).to_numpy()
    yhat = level[None, :] * factors[fdow]
    # σ diaria: desviación de los errores de la misma regla aplicada a los últimos días (nivel previo, sin ver el día).
    sigma = np.zeros(n)
    if t > 35:
        cs = np.cumsum(np.vstack([np.zeros((1, n)), hist]), axis=0)
        errs = []
        for j in range(t - min(56, t - 29), t):
            lvl = (cs[j] - cs[j - 28]) / 28.0
            errs.append(hist[j] - lvl)
        sigma = np.std(np.array(errs), axis=0)
    return np.clip(yhat, 0, None), sigma


# ───────────────────────────── contexto y políticas ─────────────────────────────

@dataclass
class Pipeline:
    """Órdenes en camino por serie: lista de (día de llegada, cantidad)."""

    orders: list[list[tuple[int, float]]]

    @classmethod
    def empty(cls, n: int) -> "Pipeline":
        return cls([[] for _ in range(n)])

    def on_order(self, i: int) -> float:
        return float(sum(q for _, q in self.orders[i]))


@dataclass
class DecisionContext:
    inp: SimInputs
    day: int  # índice del día de decisión (cierre del día)
    hist: np.ndarray  # (day+1, N) ventas observadas bajo la política
    inv: np.ndarray
    pipeline: Pipeline
    forecast: np.ndarray  # (H, N)
    sigma: np.ndarray
    horizon: int


class Policy:
    name = "policy"

    def decide(self, ctx: DecisionContext) -> np.ndarray:  # pragma: no cover - interfaz
        raise NotImplementedError


class CurrentPolicy(Policy):
    """Revisión periódica de la etapa 1 (`inventory.recommendations.build_recommendations`) sobre el pronóstico ligero."""

    name = "current"

    def decide(self, ctx: DecisionContext) -> np.ndarray:
        inp = ctx.inp
        n = len(inp.keys)
        cutoff = inp.dates[ctx.day]
        H = ctx.horizon
        rows = []
        uids = [f"b{b}-p{p}" for b, p in inp.keys]
        steps = np.arange(1, H + 1)
        ds = pd.date_range(cutoff + pd.Timedelta(days=1), periods=H)
        fc = pd.DataFrame({
            "unique_id": np.repeat(uids, H), "branch_id": np.repeat([k[0] for k in inp.keys], H),
            "product_id": np.repeat([k[1] for k in inp.keys], H), "step": np.tile(steps, n),
            "ds": np.tile(ds, n), "yhat": ctx.forecast.T.reshape(-1),
            "lo_80": np.clip(ctx.forecast - Z80 * ctx.sigma[None, :], 0, None).T.reshape(-1),
            "hi_80": (ctx.forecast + Z80 * ctx.sigma[None, :]).T.reshape(-1)})
        inv = pd.DataFrame({"branch_id": [k[0] for k in inp.keys], "product_id": [k[1] for k in inp.keys],
                            "ds": cutoff, "on_hand": ctx.inv, "reserved": 0.0, "in_transit": 0.0,
                            "unit_cost": inp.unit_cost})
        po = []
        for i, (b, p) in enumerate(inp.keys):
            for arr_day, q in ctx.pipeline.orders[i]:
                po.append({"branch_id": b, "product_id": p, "qty_open": q, "expected_at": cutoff + pd.Timedelta(days=arr_day - ctx.day)})
        open_pos = pd.DataFrame(po, columns=["branch_id", "product_id", "qty_open", "expected_at"])
        lts = pd.DataFrame({"supplier_id": np.nan, "product_id": [k[1] for k in inp.keys], "branch_id": [k[0] for k in inp.keys],
                            "lead_time_days": inp.lead.astype(int), "lead_time_std_days": inp.lead_std})
        profile = pd.DataFrame({"unique_id": uids, "demand_class": "smooth"})
        scales = pd.Series(np.maximum(ctx.forecast[:28].mean(axis=0), 0.05), index=uids)
        recs = build_recommendations(
            fc, inv, lts, pd.DataFrame(columns=["supplier_id", "default_lead_time_days"]), open_pos,
            pd.Series(inp.unit_cost, index=pd.MultiIndex.from_tuples(inp.keys)),
            LeadTimeErrorModel(EMPTY_BT, pd.Series(dtype=object)), profile, scales,
            {"service_level": inp.service_level, "review_period_days": inp.review_days, "default_lead_time_days": 7}, cutoff)
        out = np.zeros(n)
        if recs.empty:
            return out
        idx = {u: i for i, u in enumerate(uids)}
        for r in recs.itertuples(index=False):
            q = r.suggested_qty
            if q is not None and not math.isnan(q):
                out[idx[r.unique_id]] = max(0.0, float(q))
        return out


class ReferencePolicy(Policy):
    """Mín-máx simple: S = d̄₂₈·(L + R + días de seguridad); se pide si la posición ≤ d̄₂₈·(L + días de seguridad)."""

    name = "reference"

    def __init__(self, safety_days: int = 7) -> None:
        self.safety_days = safety_days

    def decide(self, ctx: DecisionContext) -> np.ndarray:
        inp = ctx.inp
        t = ctx.hist.shape[0]
        dbar = ctx.hist[max(0, t - 28):].mean(axis=0)
        position = ctx.inv + np.array([ctx.pipeline.on_order(i) for i in range(len(inp.keys))])
        reorder = dbar * (inp.lead + self.safety_days)
        order_up_to = dbar * (inp.lead + inp.review_days + self.safety_days)
        qty = np.where(position <= reorder, order_up_to - position, 0.0)
        return np.ceil(np.clip(qty, 0, None) - 1e-9)


class PlannerPolicy(Policy):
    """MILP de esta etapa por serie; se ejecuta solo la emisión de hoy. Stock de seguridad = z·σ_L (misma regla normal)."""

    def __init__(self, budget_per_review: float | None = None, penalty_multiplier: float = 2.0, name: str = "planner",
                 time_limit: float = 20.0) -> None:
        self.budget = budget_per_review
        self.mult = penalty_multiplier
        self.name = name
        self.time_limit = time_limit
        self.solve_seconds = 0.0
        self.statuses: dict[str, int] = {}

    def decide(self, ctx: DecisionContext) -> np.ndarray:
        inp = ctx.inp
        n = len(inp.keys)
        R = inp.review_days
        z = float(norm.ppf(inp.service_level))
        cutoff = inp.dates[ctx.day].date()
        nodes, offers = [], []
        for i, (b, p) in enumerate(inp.keys):
            L = int(inp.lead[i])
            periods = min(math.ceil((L + 2 * R) / R) + 1, ctx.horizon // R)
            if periods < 2:
                continue
            fc = ctx.forecast[: periods * R, i]
            demand = fc.reshape(periods, R).sum(axis=1)
            cover = L + R
            sd = float(np.sqrt(cover) * ctx.sigma[i])
            lt_var = (float(ctx.forecast[:cover, i].mean()) * float(inp.lead_std[i]))
            ss = z * math.sqrt(sd ** 2 + lt_var ** 2)
            if demand.sum() <= 1e-9 and ss <= 1e-9 and ctx.inv[i] <= 0:
                continue
            known = np.zeros(periods)
            for arr_day, q in ctx.pipeline.orders[i]:
                d = arr_day - ctx.day
                tau = 0 if d <= 1 else math.ceil((d - 1) / R)
                if tau < periods:
                    known[tau] += q
            cost = float(inp.unit_cost[i])
            nodes.append(NodeSpec(b, p, "u", float(ctx.inv[i]), demand, known, penalty=self.mult * cost,
                                  holding=inp.holding_rate / 365.0 * R * cost, safety_stock=ss, meta={"series": i}))
            offers.append(OfferSpec(key=f"o{i}", offer_id=None, supplier_id=None, product_id=p, branch_id=b,
                                    unit_cost=cost, base_unit_cost=cost, lot=1.0, default_lead=L,
                                    lead_by_branch={b: L}, implicit=True))
        out = np.zeros(n)
        if not nodes:
            return out
        budgets = [BudgetSpec(None, None, cutoff, cutoff, float(self.budget))] if self.budget is not None else []
        problem = PlanProblem(as_of=cutoff, period_days=R, n_periods=max(nd.horizon for nd in nodes), currency="USD",
                              nodes=nodes, offers=offers, budgets=budgets, objective="min_cost", coverage_mode="soft",
                              time_limit=self.time_limit, mip_gap=1e-3)
        t0 = time.perf_counter()
        outcome = solve_plan(problem, diagnose_unmet=False)
        self.solve_seconds += time.perf_counter() - t0
        self.statuses[outcome.status] = self.statuses.get(outcome.status, 0) + 1
        for ln in outcome.solution.purchases:
            if ln.t == 0:
                i = next(nd.meta["series"] for nd in nodes if nd.key == (ln.branch_id, ln.product_id))
                out[i] += ln.quantity
        return out


# ───────────────────────────── simulación ─────────────────────────────

def simulate_policy(inp: SimInputs, policy: Policy, forecast_horizon: int | None = None) -> dict[str, Any]:
    n = len(inp.keys)
    D = inp.demand.shape[0]
    end = inp.start + inp.n_days
    if end > D:
        raise ValueError("La ventana excede el historial disponible.")
    rng = np.random.default_rng(inp.seed)
    # Retrasos comunes a todas las políticas: por día de emisión y serie.
    delay = np.rint(rng.normal(0.0, 1.0, size=(D, n)) * inp.lead_std[None, :]).astype(int)
    H = forecast_horizon or int(inp.lead.max() + 2 * inp.review_days + 7)
    hist = inp.sold_hist.copy()
    inv = inp.init_inv.astype(float).copy()
    pipe = Pipeline.empty(n)
    sold = np.zeros((inp.n_days, n))
    unmet = np.zeros((inp.n_days, n))
    inv_path = np.zeros((inp.n_days, n))
    onorder_value = np.zeros(inp.n_days)
    purchases_value = 0.0
    orders_count = 0
    order_units = 0.0
    orders_by_review: list[float] = []
    review_days_idx = []
    t0 = time.perf_counter()
    for k in range(inp.n_days):
        d = inp.start + k
        # llegadas
        for i in range(n):
            if pipe.orders[i]:
                due = [q for a, q in pipe.orders[i] if a <= d]
                if due:
                    inv[i] += sum(due)
                    pipe.orders[i] = [(a, q) for a, q in pipe.orders[i] if a > d]
        dem = inp.demand[d]
        s = np.minimum(dem, inv)
        sold[k] = s
        unmet[k] = dem - s
        inv = inv - s
        hist[d] = s  # lo que la empresa observa bajo esta política
        inv_path[k] = inv
        onorder_value[k] = sum(pipe.on_order(i) * inp.unit_cost[i] for i in range(n))
        if k % inp.review_days == 0 and k < inp.n_days - 1:
            yhat, sigma = weekday_forecast(hist[: d + 1], inp.dates[d], H)
            ctx = DecisionContext(inp, d, hist[: d + 1], inv.copy(), pipe, yhat, sigma, H)
            qty = policy.decide(ctx)
            review_days_idx.append(k)
            spent = 0.0
            for i in np.flatnonzero(qty > 0):
                L = max(1, int(inp.lead[i]) + int(delay[d, i]))
                pipe.orders[i].append((d + L, float(qty[i])))
                spent += qty[i] * inp.unit_cost[i]
                orders_count += 1
                order_units += float(qty[i])
            purchases_value += spent
            orders_by_review.append(spent)
    metrics = _metrics(inp, sold, unmet, inv_path, onorder_value, purchases_value, orders_count, order_units)
    metrics["policy"] = policy.name
    metrics["runtime_seconds"] = round(time.perf_counter() - t0, 2)
    metrics["spend_per_review_mean"] = round(float(np.mean(orders_by_review)), 2) if orders_by_review else 0.0
    metrics["series_weekly"] = _weekly(inp, sold, unmet, inv_path)
    if isinstance(policy, PlannerPolicy):
        metrics["solver"] = {"seconds": round(policy.solve_seconds, 2), "statuses": policy.statuses}
    return metrics


def _metrics(inp, sold, unmet, inv_path, onorder_value, purchases_value, orders_count, order_units) -> dict[str, Any]:
    n_days = sold.shape[0]
    dem = sold + unmet
    d0, d1 = inp.start, inp.start + n_days
    # Sobrestock: existencias por encima de la demanda realizada de los próximos `overstock_days` (con vista completa).
    O = inp.overstock_days
    cs = np.cumsum(np.vstack([np.zeros((1, dem.shape[1])), inp.demand[d0:]]), axis=0)
    valid = max(0, n_days - O)
    overstock_ud = 0.0
    overstock_val = 0.0
    if valid > 0:
        future = cs[O + 1: O + 1 + valid] - cs[1: 1 + valid]  # demanda realizada de los días k+1..k+O
        excess = np.clip(inv_path[:valid] - future, 0, None)
        overstock_ud = float(excess.sum())
        overstock_val = float((excess * inp.unit_cost[None, :]).sum())
    value_path = inv_path * inp.unit_cost[None, :]
    avg_inv_value = float(value_path.sum(axis=1).mean())
    daily_cogs = float((sold * inp.unit_cost[None, :]).sum() / n_days)
    margin = np.maximum(inp.price - inp.unit_cost, 0.0)
    lost_margin = float((unmet * margin[None, :]).sum())
    holding = float(value_path.sum() * inp.holding_rate / 365.0)
    ordering = float(orders_count * inp.order_cost)
    demand_days = dem > 1e-9
    return {
        "demand_units": round(float(dem.sum()), 2), "sold_units": round(float(sold.sum()), 2),
        "unmet_units": round(float(unmet.sum()), 2),
        "fill_rate": round(float(sold.sum() / max(dem.sum(), 1e-9)), 4),
        "stockout_day_share": round(float(((unmet > 1e-9) & demand_days).sum() / max(demand_days.sum(), 1)), 4),
        "overstock_unit_days": round(overstock_ud, 1), "overstock_value_days": round(overstock_val, 1),
        "avg_overstock_value": round(overstock_val / max(valid, 1), 2),
        "avg_inventory_value": round(avg_inv_value, 2),
        "days_of_inventory": round(avg_inv_value / daily_cogs, 2) if daily_cogs > 0 else None,
        "holding_cost": round(holding, 2), "ordering_cost": round(ordering, 2),
        "purchase_outlay": round(purchases_value, 2), "orders": int(orders_count), "ordered_units": round(order_units, 2),
        "lost_margin": round(lost_margin, 2),
        "operating_cost_proxy": round(holding + ordering + lost_margin, 2),
        "avg_capital_committed": round(avg_inv_value + float(onorder_value.mean()), 2),
        "peak_capital_committed": round(float((value_path.sum(axis=1) + onorder_value).max()), 2),
        "ending_inventory_value": round(float(value_path[-1].sum()), 2),
        "overstock_window_days": int(valid),
    }


def _weekly(inp, sold, unmet, inv_path) -> list[dict[str, Any]]:
    out = []
    n_days = sold.shape[0]
    for w in range(0, n_days, 7):
        s = slice(w, min(w + 7, n_days))
        dem = float((sold[s] + unmet[s]).sum())
        out.append({"week_start": inp.dates[inp.start + w].date().isoformat(), "demand": round(dem, 1),
                    "sold": round(float(sold[s].sum()), 1), "unmet": round(float(unmet[s].sum()), 1),
                    "inventory_value_end": round(float((inv_path[s][-1] * inp.unit_cost).sum()), 1)})
    return out


def compare_policies(inp: SimInputs, policies: list[Policy], forecast_horizon: int | None = None) -> dict[str, Any]:
    results = {p.name: simulate_policy(inp, p, forecast_horizon) for p in policies}
    base = results.get("current") or next(iter(results.values()))
    deltas = {}
    for name, r in results.items():
        deltas[name] = {k: round(r[k] - base[k], 4) for k in
                        ("unmet_units", "fill_rate", "overstock_value_days", "avg_inventory_value", "holding_cost",
                         "lost_margin", "operating_cost_proxy", "avg_capital_committed", "purchase_outlay")
                        if r.get(k) is not None and base.get(k) is not None}
    return {
        "window": {"start": inp.dates[inp.start].date().isoformat(), "end": inp.dates[inp.start + inp.n_days - 1].date().isoformat(),
                   "days": inp.n_days, "review_days": inp.review_days, "series": len(inp.keys)},
        "demand_basis": inp.demand_basis, "policies": results, "delta_vs_current": deltas,
        "parameters": {"service_level": inp.service_level, "overstock_days": inp.overstock_days,
                       "holding_rate_annual": inp.holding_rate, "order_cost": inp.order_cost, "seed": inp.seed,
                       "lead_time_noise": "normal(0, lead_std) redondeado, común a todas las políticas"},
        "caveats": [
            "No reemplaza la validación con datos reales: " + (
                "la demanda de evaluación es la demanda latente de un generador sintético." if inp.demand_basis == "true_demand_synthetic"
                else "la demanda es la venta observada (censurada por quiebres): el faltante está subestimado."),
            "El pronóstico de cada revisión es ligero (nivel de 28 días × día de semana) y usa solo ventas hasta esa fecha; "
            "no es el pipeline de producción.",
            "Existencias iniciales reales de la ventana; sin órdenes en camino iniciales (supuesto).",
            "Mantenimiento = tasa anual × valor de existencias (supuesto); costo por orden = " + str(inp.order_cost) + " (supuesto).",
            "Margen perdido = unidades no atendidas × (precio − costo) de catálogo.",
        ],
    }
