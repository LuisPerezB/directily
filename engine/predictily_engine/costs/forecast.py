"""Pronóstico de costos unitarios (compra) con referencia simple, holdout temporal y disponibilidad por `known_at`.

Principios (instrucctions.md §6.G):
- Se pronostican COSTOS UNITARIOS, nunca cantidades. Las cantidades solo ponderan el promedio mensual del costo de
  ventas; cambiar cantidades no cambia el pronóstico de compras.
- Disponibilidad: una observación existe para el corte solo si `known_at` ≤ corte. `known_at` NULL:
  ejecución vigente → se acepta; retroactiva → solo si el costo proviene de una compra real (se conoce en su fecha
  `valid_from`); si no, se excluye (política `strict`) o se asume `valid_from` degradando la calidad
  (`assume_valid_from`).
- Series separadas por proveedor, moneda y unidad: nunca se mezclan.
- Siempre se compara una referencia simple (último valor) con otros métodos en un holdout temporal posterior a la
  selección; solo se sirve otro método si mejora el MAE de selección en al menos `min_improvement`.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.supply.availability import known_by, to_date

METHODS = ("last_value", "mean_3", "drift", "holt_damped")
REFERENCE = "last_value"


# ───────────────────────────── métodos ─────────────────────────────

def _f_last(y: np.ndarray, h: int) -> float:
    return float(y[-1])


def _f_mean3(y: np.ndarray, h: int) -> float:
    return float(np.mean(y[-3:]))


def _f_drift(y: np.ndarray, h: int) -> float:
    if len(y) < 2:
        return float(y[-1])
    slope = (y[-1] - y[0]) / (len(y) - 1)
    return float(y[-1] + h * slope)


def _holt_fit(y: np.ndarray) -> tuple[float, float, float, float, float]:
    """Holt amortiguado con rejilla pequeña de (α, β, φ) minimizando el SSE de un paso (en la muestra de ajuste)."""
    best = None
    for a in (0.2, 0.5, 0.8):
        for b in (0.05, 0.2):
            for phi in (0.8, 0.95):
                level, trend = float(y[0]), float(y[1] - y[0]) if len(y) > 1 else 0.0
                sse = 0.0
                for t in range(1, len(y)):
                    pred = level + phi * trend
                    sse += (y[t] - pred) ** 2
                    new_level = a * y[t] + (1 - a) * pred
                    trend = b * (new_level - level) + (1 - b) * phi * trend
                    level = new_level
                if best is None or sse < best[0]:
                    best = (sse, level, trend, phi, a)
    return best[1], best[2], best[3], best[4], best[0]


def _f_holt(y: np.ndarray, h: int) -> float:
    if len(y) < 4:
        return float(y[-1])
    level, trend, phi, _a, _sse = _holt_fit(y)
    damp = sum(phi ** k for k in range(1, h + 1))
    return float(level + damp * trend)


FUNCS = {"last_value": _f_last, "mean_3": _f_mean3, "drift": _f_drift, "holt_damped": _f_holt}


# ───────────────────────────── series ─────────────────────────────

@dataclass
class CostSeries:
    product_id: int
    supplier_id: int | None
    source: str  # purchase_history | sales_cost
    currency: str
    unit: str | None
    periods: list[date]  # primer día de cada mes con observación (ordenadas)
    values: list[float]
    counts: list[int]
    availability: dict[str, Any] = field(default_factory=dict)
    limitations: list[str] = field(default_factory=list)
    degraded: bool = False


def month_start(d: date) -> date:
    return date(d.year, d.month, 1)


def add_months(d: date, n: int) -> date:
    m = d.month - 1 + n
    return date(d.year + m // 12, m % 12 + 1, 1)


def months_between(a: date, b: date) -> int:
    return (b.year - a.year) * 12 + (b.month - a.month)


def build_purchase_series(history: pd.DataFrame, as_of: date, retroactive: bool,
                          null_policy: str = "strict") -> tuple[list[CostSeries], dict[str, Any]]:
    """Series mensuales por (producto, proveedor, moneda, unidad) con las observaciones disponibles al corte."""
    stats = {"rows": 0, "used": 0, "after_cutoff": 0, "unknown_availability_excluded": 0, "assumed_valid_from": 0,
             "future_effective": 0}
    if history is None or history.empty:
        return [], stats
    rows = []
    for r in history.itertuples(index=False):
        stats["rows"] += 1
        vf = to_date(r.valid_from)
        if vf is None:
            continue
        fallback = vf if str(r.source) == "purchase_order" else None
        ok, why = known_by(r.known_at, as_of, retroactive, fallback)
        assumed = False
        if not ok and why == "unknown_availability" and null_policy == "assume_valid_from":
            ok, assumed = vf <= as_of, True
        if not ok:
            stats["after_cutoff" if why == "after_cutoff" else "unknown_availability_excluded"] += 1
            continue
        if vf > as_of:
            stats["future_effective"] += 1  # vigencia futura: no es un costo observado al corte
            continue
        if assumed:
            stats["assumed_valid_from"] += 1
        rows.append((int(r.product_id), None if pd.isna(r.supplier_id) else int(r.supplier_id), str(r.currency),
                     None if r.unit is None or pd.isna(r.unit) else str(r.unit), vf, float(r.unit_cost), assumed, why))
    stats["used"] = len(rows)
    if not rows:
        return [], stats
    df = pd.DataFrame(rows, columns=["product_id", "supplier_id", "currency", "unit", "vf", "cost", "assumed", "why"])
    df["period"] = df["vf"].map(month_start)
    out: list[CostSeries] = []
    for (pid, sid, cur, unit), g in df.groupby(["product_id", "supplier_id", "currency", "unit"], dropna=False, sort=True):
        agg = g.groupby("period").agg(v=("cost", "mean"), n=("cost", "size")).sort_index()
        s = CostSeries(int(pid), None if pd.isna(sid) else int(sid), "purchase_history", str(cur),
                       None if pd.isna(unit) else str(unit), list(agg.index), agg["v"].astype(float).tolist(),
                       agg["n"].astype(int).tolist())
        s.availability = {"observations": int(len(g)), "assumed_valid_from": int(g["assumed"].sum()),
                          "known_at_missing_accepted_current_run": int((g["why"] == "current_run").sum()),
                          "evidence_valid_from": int((g["why"] == "fallback_evidence").sum())}
        if s.availability["assumed_valid_from"]:
            s.degraded = True
            s.limitations.append(f"{s.availability['assumed_valid_from']} observación(es) sin known_at se asumieron "
                                 "conocidas en su fecha efectiva: si se cargaron después, el resultado histórico puede estar sesgado.")
        if s.availability["known_at_missing_accepted_current_run"]:
            s.limitations.append(f"{s.availability['known_at_missing_accepted_current_run']} observación(es) sin known_at "
                                 "aceptadas porque la ejecución es vigente (no retroactiva).")
        out.append(s)
    return out, stats


def build_sales_cost_series(sales_cost: pd.DataFrame, as_of: date, currency: str, products_with_purchase: set[int],
                            unit_by_product: dict[int, str | None]) -> list[CostSeries]:
    """Costo histórico de ventas por mes (ponderado por cantidad) para productos SIN historial de compras."""
    if sales_cost is None or sales_cost.empty:
        return []
    out = []
    df = sales_cost[~sales_cost["product_id"].isin(products_with_purchase)].copy()
    df["period"] = pd.to_datetime(df["period"]).dt.date
    df = df[df["period"] <= month_start(as_of)]
    for pid, g in df.groupby("product_id"):
        g = g.sort_values("period")
        s = CostSeries(int(pid), None, "sales_cost", currency, unit_by_product.get(int(pid)), list(g["period"]),
                       g["unit_cost"].astype(float).tolist(), [1] * len(g))
        s.availability = {"observations": int(len(g)), "rule": "El costo de una venta se conoce en la fecha de la venta."}
        s.limitations.append("Costo histórico de ventas (no de compras): refleja el costo registrado al vender, "
                             "no precios de proveedor vigentes.")
        out.append(s)
    return out


# ───────────────────────────── evaluación ─────────────────────────────

def _errors(y: np.ndarray, method: str, targets: range, steps: int) -> list[tuple[int, int, float, float]]:
    """(objetivo, paso, pronóstico, real) con origen = objetivo − paso (usa y[:origen+1])."""
    fn = FUNCS[method]
    out = []
    for i in targets:
        for h in range(1, steps + 1):
            origin = i - h
            if origin < 2:
                continue
            out.append((i, h, fn(y[: origin + 1], h), float(y[i])))
    return out


def _metrics(errs: list[tuple[int, int, float, float]]) -> dict[str, Any]:
    if not errs:
        return {"n": 0, "mae": None, "wape": None, "bias": None}
    f = np.array([e[2] for e in errs])
    a = np.array([e[3] for e in errs])
    mae = float(np.mean(np.abs(f - a)))
    denom = float(np.sum(np.abs(a)))
    return {"n": int(len(errs)), "mae": mae, "wape": float(np.sum(np.abs(f - a)) / denom) if denom > 0 else None,
            "bias": float(np.sum(f - a) / denom) if denom > 0 else None}


def forecast_series(series: CostSeries, as_of: date, horizon_months: int, cfg: dict[str, Any]) -> dict[str, Any]:
    """Pronostica una serie. Devuelve el dict listo para persistir (puntos, métricas, calidad, limitaciones)."""
    min_obs = int(cfg.get("min_observations", 6))
    holdout = int(cfg.get("holdout_periods", 3))
    min_train = int(cfg.get("min_train_periods", 3))
    min_improve = float(cfg.get("min_improvement", 0.03))
    level = float(cfg.get("interval_level", 0.8))
    methods = [m for m in cfg.get("methods", METHODS) if m in FUNCS]
    if REFERENCE not in methods:
        methods = [REFERENCE] + methods
    # Serie continua en meses: huecos se rellenan con el último valor conocido (el costo se mantiene hasta que cambia).
    first, last = series.periods[0], series.periods[-1]
    grid = [add_months(first, k) for k in range(months_between(first, last) + 1)]
    lookup = dict(zip(series.periods, series.values))
    y_list, filled = [], 0
    for p in grid:
        if p in lookup:
            y_list.append(lookup[p])
        else:
            y_list.append(y_list[-1])
            filled += 1
    y = np.array(y_list, dtype=float)
    n_obs = len(series.values)
    limitations = list(series.limitations)
    base = {"product_id": series.product_id, "supplier_id": series.supplier_id, "cost_source": series.source,
            "currency": series.currency, "unit": series.unit, "n_obs": n_obs, "last_observed_on": last,
            "last_observed_cost": float(series.values[-1]), "availability": series.availability,
            "reference_method": REFERENCE}
    if filled:
        limitations.append(f"{filled} mes(es) sin observación se completaron con el último costo conocido (el costo se "
                           "mantiene hasta que cambia); no son observaciones.")
    stale = months_between(last, month_start(as_of))
    if stale > 3:
        limitations.append(f"La última observación tiene {stale} meses de antigüedad.")
    if n_obs < min_obs:
        return {**base, "method": None, "quality_status": "insufficient_data", "points": [], "metrics": None,
                "limitations": limitations + [f"Solo {n_obs} período(s) con costo observado; se requieren {min_obs}. "
                                              "No se pronostica (abstención)."]}
    n = len(y)
    steps_eval = max(1, min(horizon_months, 3))
    test_targets = range(max(n - holdout, min_train + 1), n)
    sel_targets = range(min_train + 1, max(n - holdout, min_train + 1))
    metrics: dict[str, Any] = {}
    sel_mae: dict[str, float | None] = {}
    for m in methods:
        sel = _metrics(_errors(y, m, sel_targets, steps_eval))
        tst = _metrics(_errors(y, m, test_targets, steps_eval))
        metrics[m] = {"selection": sel, "holdout": tst}
        sel_mae[m] = sel["mae"]
    chosen = REFERENCE
    selection_note = None
    ref_mae = sel_mae.get(REFERENCE)
    if ref_mae is not None and metrics[REFERENCE]["selection"]["n"] >= 3:
        cand = [(sel_mae[m], m) for m in methods if m != REFERENCE and sel_mae[m] is not None]
        if cand:
            best_mae, best = min(cand)
            if ref_mae > 0 and (ref_mae - best_mae) / ref_mae >= min_improve:
                chosen = best
            elif ref_mae == 0:
                selection_note = "La referencia no tuvo error en selección."
            else:
                selection_note = ("Ningún método mejoró la referencia (último valor) en al menos "
                                  f"{min_improve:.0%} en la ventana de selección: se sirve la referencia.")
    else:
        selection_note = ("Historia insuficiente para seleccionar entre métodos antes del holdout: se sirve la referencia "
                          "(último valor); las métricas del holdout son informativas.")
    if selection_note:
        limitations.append(selection_note)
    # Errores del método servido (todos los orígenes) → intervalo empírico por paso; sin muestra suficiente: sin intervalo.
    all_targets = range(min_train + 1, n)
    served_errs = _errors(y, chosen, all_targets, 3)
    points = []
    cur_month = month_start(as_of)
    unc = "unknown"
    for k in range(1, horizon_months + 1):
        target = add_months(cur_month, k)
        h = months_between(last, target)
        if h < 1:
            continue
        yhat = FUNCS[chosen](y, h)
        ref = FUNCS[REFERENCE](y, h)
        errs_h = np.array([abs(e[2] - e[3]) for e in served_errs if e[1] == min(h, 3)])
        lo = hi = None
        if len(errs_h) >= 8:
            q = float(np.quantile(errs_h, level)) * math.sqrt(max(h, 1) / min(h, 3))
            lo, hi = max(0.0, yhat - q), yhat + q
            unc = "empirical_holdout"
        points.append({"step": k, "target_date": target.isoformat(), "months_ahead": h, "yhat": round(yhat, 6),
                       "reference": round(ref, 6), "lo": None if lo is None else round(lo, 6),
                       "hi": None if hi is None else round(hi, 6), "interval_level": level if lo is not None else None})
    if unc == "unknown":
        limitations.append("Sin errores históricos suficientes: el intervalo queda desconocido (no se asume ancho cero).")
    quality = "degraded" if (series.degraded or selection_note or stale > 3 or filled > 0) else "ok"
    return {**base, "method": chosen, "quality_status": quality, "points": points, "metrics": metrics,
            "limitations": limitations, "uncertainty_source": unc,
            "selection": {"protocol": f"selección en objetivos {min_train + 1}..{max(n - holdout, min_train + 1) - 1}; "
                                      f"holdout temporal en los últimos {holdout} períodos (no participan en la elección)",
                          "min_improvement": min_improve}}
