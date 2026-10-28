"""Recomendaciones básicas de abastecimiento (revisión periódica, order-up-to).

Para cada serie con inventario conocido:
- disponible = existencias − reservado + en tránsito (snapshot, o compras abiertas).
- L = plazo de proveedor + período de revisión; D_L = Σ pronóstico de los próximos L días.
- Error de la demanda en L días: distribución EMPÍRICA de (real − pronóstico) acumulado en L
  días del backtest (misma clase de demanda, normalizada por escala). Si no hay evidencia
  suficiente se usa una aproximación normal con σ derivada de los intervalos diarios.
- Stock de seguridad = cuantil(nivel de servicio) del error × escala; punto de reorden
  = D_L + SS; cantidad sugerida = max(0, reorden − disponible) redondeada hacia arriba.
- Probabilidad de quiebre = P(demanda en L > disponible). Cobertura en días y fecha estimada
  de agotamiento a partir del pronóstico acumulado.
Predictily NO emite pedidos ni compromete fondos: son sugerencias con supuestos explícitos.
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd
from scipy.stats import norm

MIN_EMPIRICAL = 15


def resolve_lead_time(product_id: int, branch_id: int, lead_times: pd.DataFrame, suppliers: pd.DataFrame,
                      default_days: int) -> tuple[int, float | None, str]:
    """Prioridad: producto+sucursal > producto > proveedor > valor por defecto de la empresa."""
    lt = lead_times if lead_times is not None else pd.DataFrame()
    if not lt.empty:
        prod = lt[lt["product_id"] == product_id]
        pb = prod[prod["branch_id"] == branch_id]
        if not pb.empty:
            r = pb.iloc[-1]
            return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "product_branch"
        p_only = prod[prod["branch_id"].isna()]
        if not p_only.empty:
            r = p_only.iloc[-1]
            return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "product"
        supplier_ids = prod["supplier_id"].dropna().unique()
        for sid in supplier_ids:
            s_rows = lt[(lt["supplier_id"] == sid) & lt["product_id"].isna() & lt["branch_id"].isna()]
            if not s_rows.empty:
                r = s_rows.iloc[-1]
                return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "supplier"
            if suppliers is not None and not suppliers.empty:
                srow = suppliers[suppliers["supplier_id"] == sid]
                if not srow.empty and pd.notna(srow.iloc[0]["default_lead_time_days"]):
                    return int(srow.iloc[0]["default_lead_time_days"]), None, "supplier"
    return int(default_days), None, "tenant_default"


def _f(v) -> float | None:
    try:
        return None if v is None or pd.isna(v) else float(v)
    except TypeError:
        return None


class LeadTimeErrorModel:
    """Distribución empírica del error acumulado en L días por clase de demanda."""

    def __init__(self, selected_bt: pd.DataFrame, cls_map: pd.Series) -> None:
        self.bt = selected_bt.assign(demand_class=selected_bt["unique_id"].map(cls_map))
        self.max_step = int(selected_bt["step"].max()) if len(selected_bt) else 0
        self._cache: dict[tuple[str, int], np.ndarray] = {}

    def samples(self, demand_class: str, L: int) -> np.ndarray:
        L = min(L, self.max_step)
        key = (demand_class, L)
        if key in self._cache:
            return self._cache[key]
        if L <= 0 or self.bt.empty:
            self._cache[key] = np.array([])
            return self._cache[key]
        sub = self.bt[self.bt["step"] <= L]
        agg = sub.groupby(["unique_id", "window", "demand_class"]).agg(
            a=("actual", "sum"), p=("yhat", "sum"), n=("step", "size"), oos=("stockout", "any"),
            scale=("scale", "first")).reset_index()
        agg = agg[(agg["n"] == L) & ~agg["oos"]]
        e_cls = ((agg["a"] - agg["p"]) / agg["scale"])[agg["demand_class"] == demand_class].to_numpy()
        if len(e_cls) < MIN_EMPIRICAL:
            e_cls = ((agg["a"] - agg["p"]) / agg["scale"]).to_numpy()
        self._cache[key] = e_cls if len(e_cls) >= MIN_EMPIRICAL else np.array([])
        return self._cache[key]


def risk_level(prob: float | None, available: float, demand_l: float) -> str:
    if available <= 0 and demand_l > 0:
        return "critical"
    if prob is None:
        return "low"
    if prob >= 0.6:
        return "critical"
    if prob >= 0.3:
        return "high"
    if prob >= 0.1:
        return "medium"
    return "low"


def build_recommendations(
    series_fc: pd.DataFrame,  # unique_id, branch_id, product_id, step, ds, yhat, lo_80, hi_80
    latest_inventory: pd.DataFrame,
    lead_times: pd.DataFrame,
    suppliers: pd.DataFrame,
    open_pos: pd.DataFrame,
    unit_costs: pd.Series,  # index (branch_id, product_id) -> costo unitario conocido
    error_model: LeadTimeErrorModel,
    profile: pd.DataFrame,
    scales: pd.Series,  # unique_id -> escala
    settings: dict,
    cutoff: pd.Timestamp,
) -> pd.DataFrame:
    if latest_inventory is None or latest_inventory.empty or series_fc.empty:
        return pd.DataFrame()
    service_level = float(settings.get("service_level", 0.95))
    review = int(settings.get("review_period_days", 7))
    default_lt = int(settings.get("default_lead_time_days", 7))
    z = float(norm.ppf(service_level))
    cls_map = profile.set_index("unique_id")["demand_class"]
    inv = latest_inventory.set_index(["branch_id", "product_id"])
    po = (open_pos.groupby(["branch_id", "product_id"])["qty_open"].sum()
          if open_pos is not None and not open_pos.empty else pd.Series(dtype=float))
    rows = []
    for uid, fc in series_fc.sort_values("step").groupby("unique_id", sort=False):
        b, p = int(fc["branch_id"].iat[0]), int(fc["product_id"].iat[0])
        if (b, p) not in inv.index:
            continue
        snap = inv.loc[(b, p)]
        on_hand = float(snap["on_hand"])
        reserved = float(snap.get("reserved") or 0.0)
        in_transit = float(snap.get("in_transit") or 0.0)
        if in_transit <= 0 and (b, p) in po.index:
            in_transit = float(po.loc[(b, p)])
        available = on_hand - reserved + in_transit
        lt_days, lt_std, lt_source = resolve_lead_time(p, b, lead_times, suppliers, default_lt)
        L = max(1, min(lt_days + review, len(fc)))
        yhat = fc["yhat"].to_numpy(dtype=float)
        demand_l = float(yhat[:L].sum())
        scale = float(scales.get(uid, max(yhat[:28].mean() if len(yhat) else 0.05, 0.05)))
        samples = error_model.samples(str(cls_map.get(uid, "smooth")), L)
        if len(samples) >= MIN_EMPIRICAL:
            errs = samples * scale
            safety = max(0.0, float(np.quantile(errs, service_level)))
            std_l = float(np.std(errs))
            # Regla de sucesión de Laplace: con una muestra finita de errores nunca se
            # informa certeza absoluta (0 % o 100 %) de quiebre.
            hits = int(np.sum(demand_l + errs > available))
            prob = (hits + 1) / (len(errs) + 2)
            method = "empirical_backtest"
        else:
            sd = (fc["hi_80"].to_numpy(dtype=float)[:L] - fc["lo_80"].to_numpy(dtype=float)[:L]) / (2 * 1.2816)
            sd = np.nan_to_num(sd, nan=0.0)
            std_l = float(math.sqrt(float((sd ** 2).sum())))
            safety = z * std_l
            prob = float(1 - norm.cdf((available - demand_l) / std_l)) if std_l > 0 else float(available < demand_l)
            method = "normal_from_intervals"
        reorder_point = demand_l + safety
        suggested = float(math.ceil(max(0.0, reorder_point - available) - 1e-9))
        avg30 = float(yhat[:30].mean()) if len(yhat) else 0.0
        days_cover = (max(available, 0.0) / avg30) if avg30 > 1e-6 else None
        cum = np.cumsum(yhat)
        if available <= 0:
            stockout_date = (cutoff + pd.Timedelta(days=1)).date()
        else:
            idx = np.argmax(cum > available) if (cum > available).any() else None
            stockout_date = (cutoff + pd.Timedelta(days=int(idx) + 1)).date() if idx is not None else None
        unit_cost = unit_costs.get((b, p)) if unit_costs is not None else None
        if (unit_cost is None or pd.isna(unit_cost)) and pd.notna(snap.get("unit_cost")):
            unit_cost = float(snap["unit_cost"])
        unit_cost = None if unit_cost is None or pd.isna(unit_cost) else float(unit_cost)
        demand_90 = float(yhat.sum())
        level = risk_level(prob, available, demand_l)
        if available <= 0 and demand_l > 0 or level in ("high", "critical"):
            rtype = "stockout_risk"
        elif suggested > 0:
            rtype = "reorder"
        elif days_cover is not None and days_cover > 120 and available > 1.5 * demand_90:
            rtype = "overstock"
        else:
            rtype = "no_action"
        snap_date = pd.Timestamp(snap["ds"]).date()
        rows.append({
            "unique_id": uid, "branch_id": b, "product_id": p, "type": rtype,
            "inventory_snapshot_date": snap_date, "on_hand": on_hand, "reserved": reserved,
            "in_transit": in_transit, "available": available, "lead_time_days": lt_days,
            "lead_time_source": lt_source, "review_period_days": review, "service_level": service_level,
            "demand_lead_time": round(demand_l, 4), "demand_lead_time_std": round(std_l, 4),
            "safety_stock": round(safety, 4), "reorder_point": round(reorder_point, 4),
            "suggested_qty": suggested, "unit_cost": unit_cost,
            "estimated_investment": round(suggested * unit_cost, 4) if unit_cost is not None else None,
            "days_of_cover": round(days_cover, 2) if days_cover is not None else None,
            "stockout_probability": round(min(max(prob, 0.0), 1.0), 4),
            "stockout_date_estimate": stockout_date, "risk_level": level,
            "assumptions": {
                "method": method,
                "horizon_days_covered": L,
                "lead_time_std_days": lt_std,
                "service_level": service_level,
                "snapshot_stale": bool((cutoff.date() - snap_date).days > 14),
                "in_transit_source": "snapshot" if float(snap.get("in_transit") or 0) > 0 else
                ("purchase_orders" if (b, p) in po.index else "none"),
                "note": "Sugerencia basada en pronóstico; no ejecuta pedidos ni compromete fondos.",
            },
        })
    return pd.DataFrame(rows)
