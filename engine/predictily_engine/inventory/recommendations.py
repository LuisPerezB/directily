"""Recomendaciones de abastecimiento (revisión periódica, order-up-to) con saldo diario.

Para cada serie sucursal×producto con inventario conocido (validación 2026-10-28, F1–F3):

Existencias y recepciones (F1)
- `available` = existencias físicas disponibles HOY = existencias − reservado. Las compras
  abiertas NO se suman a lo disponible: llegan en su fecha.
- Recepciones previstas: cada orden de compra abierta llega en su `expected_at`. Una orden
  sin fecha, o vencida (fecha ≤ corte y aún sin recibir), se asume de forma conservadora al
  final del plazo del proveedor (día `lead_time_days`). El `in_transit` del snapshot de
  inventario (cantidad sin fecha) solo se usa si la serie no tiene órdenes abiertas, con el
  mismo supuesto; si hay órdenes, se usan ellas (tienen fecha) y el valor del snapshot se
  registra como no utilizado para no contarlo dos veces.
- Saldo diario proyectado = disponible + recepciones acumuladas − demanda pronosticada
  acumulada. La fecha estimada de agotamiento es el primer día con saldo < 0, aunque después
  llegue una orden.

Cobertura (F2)
- L = plazo + revisión: lo que debe cubrir la compra de hoy, independiente del horizonte
  pedido (el pipeline pronostica internamente al menos L días, con tope). Si el pronóstico
  recibido es más corto que L NO se extrapola: la recomendación se publica sin cantidades
  (`suggested_qty`, `demand_lead_time`, `safety_stock`, `reorder_point` = null) y con
  `assumptions.coverage_insufficient = true`.
- Posición de inventario = disponible + recepciones que llegan dentro de L. Punto de reorden
  (nivel objetivo) = D_L + SS; compra sugerida = ⌈max(0, reorden − posición)⌉.

Probabilidad e incertidumbre (F3)
- El saldo solo baja entre recepciones; basta revisar los puntos de control (víspera de cada
  recepción dentro de L y el día L). Probabilidad de quiebre = máximo de las probabilidades
  marginales en esos puntos (exacta si los errores acumulados son comonótonos; si no, cota
  inferior de la unión).
- "empirical": distribución EMPÍRICA del error acumulado del backtest (misma clase de demanda
  o todas, normalizada por escala); regla de sucesión de Laplace para no informar 0 % ni
  100 %. Si L supera los días evaluados, el error se ensancha con √(L / días evaluados).
- "intervals": aproximación normal con σ diaria derivada de los intervalos 80 %.
- "zero_variability": intervalos finitos de ancho cero (variabilidad realmente nula).
- "unknown": sin errores empíricos e intervalos NaN. `stockout_probability` y
  `demand_lead_time_std` quedan null; stock de seguridad provisional = fracción explícita de
  la demanda en la cobertura (`unknown_uncertainty_safety_factor`, 0,5 por defecto) y riesgo
  por reglas deterministas del saldo proyectado (ver `projected_risk_level`).

Predictily NO emite pedidos ni compromete fondos: son sugerencias con supuestos explícitos.
"""

from __future__ import annotations

import math
import re
from collections.abc import Iterable

import numpy as np
import pandas as pd
from scipy.stats import norm

MIN_EMPIRICAL = 15
# Tope del horizonte interno que el pipeline pronostica para cubrir plazo + revisión.
MAX_INTERNAL_HORIZON = 180
# Política conservadora explícita cuando la incertidumbre es desconocida: SS = factor × D_L.
UNKNOWN_SAFETY_FACTOR = 0.5
Z80 = float(norm.ppf(0.90))
MAX_RECEIPTS_LISTED = 20
NULLABLE_NUMERIC = ["demand_lead_time", "demand_lead_time_std", "safety_stock", "reorder_point", "suggested_qty",
                    "unit_cost", "estimated_investment", "days_of_cover", "stockout_probability"]
_EPS = 1e-9
_RISK_ORDER = ("low", "medium", "high", "critical")
_UID = re.compile(r"b(\d+)-p(\d+)")


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


def required_coverage_days(latest_inventory: pd.DataFrame, lead_times: pd.DataFrame, suppliers: pd.DataFrame,
                           settings: dict, unique_ids: Iterable[str] | None = None) -> int:
    """Máximo de plazo + revisión entre las series con inventario (0 si no hay inventario).

    El pipeline lo usa para pronosticar internamente al menos esa cobertura (F2).
    """
    if latest_inventory is None or latest_inventory.empty:
        return 0
    review = int(settings.get("review_period_days", 7))
    default_lt = int(settings.get("default_lead_time_days", 7))
    keys = {(int(b), int(p)) for b, p in latest_inventory[["branch_id", "product_id"]].itertuples(index=False)}
    if unique_ids is not None:
        wanted = set()
        for uid in unique_ids:
            m = _UID.fullmatch(str(uid))
            if m:
                wanted.add((int(m.group(1)), int(m.group(2))))
        keys &= wanted
    best = 0
    for b, p in keys:
        best = max(best, resolve_lead_time(p, b, lead_times, suppliers, default_lt)[0] + review)
    return best


class LeadTimeErrorModel:
    """Distribución empírica del error acumulado en L días por clase de demanda."""

    def __init__(self, selected_bt: pd.DataFrame, cls_map: pd.Series) -> None:
        self.bt = selected_bt.assign(demand_class=selected_bt["unique_id"].map(cls_map))
        self.max_step = int(selected_bt["step"].max()) if len(selected_bt) else 0
        self._cache: dict[tuple[str, int], np.ndarray] = {}

    def samples(self, demand_class: str, L: int) -> np.ndarray:
        return self.samples_for(demand_class, L)[0]

    def samples_for(self, demand_class: str, L: int) -> tuple[np.ndarray, int]:
        """Errores normalizados de la suma de L días y los días realmente evaluados (≤ L)."""
        L = min(L, self.max_step)
        key = (demand_class, L)
        if key in self._cache:
            return self._cache[key], L
        if L <= 0 or self.bt.empty:
            self._cache[key] = np.array([])
            return self._cache[key], L
        sub = self.bt[self.bt["step"] <= L]
        agg = sub.groupby(["unique_id", "window", "demand_class"]).agg(
            a=("actual", "sum"), p=("yhat", "sum"), n=("step", "size"), oos=("stockout", "any"),
            scale=("scale", "first")).reset_index()
        agg = agg[(agg["n"] == L) & ~agg["oos"]]
        e_cls = ((agg["a"] - agg["p"]) / agg["scale"])[agg["demand_class"] == demand_class].to_numpy()
        if len(e_cls) < MIN_EMPIRICAL:
            e_cls = ((agg["a"] - agg["p"]) / agg["scale"]).to_numpy()
        self._cache[key] = e_cls if len(e_cls) >= MIN_EMPIRICAL else np.array([])
        return self._cache[key], L


def risk_level(prob: float | None, available: float, demand_l: float) -> str:
    """Nivel de riesgo a partir de una probabilidad de quiebre CONOCIDA.

    Con incertidumbre desconocida no se llama con prob=None: se usa `projected_risk_level`.
    """
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


def projected_risk_level(stockout_day: int | None, lead_time_days: int, coverage_days: int,
                         min_balance: float | None = None, safety_stock: float | None = None) -> str:
    """Riesgo por reglas deterministas sobre el saldo proyectado (sin probabilidad).

    - critical: el saldo se agota antes de que pueda llegar una compra hecha hoy, o el mismo
      día (día de agotamiento ≤ plazo; incluye "sin existencias hoy" con demanda).
    - high: el saldo se agota dentro de la cobertura (plazo + revisión).
    - medium: el saldo proyectado cae por debajo del stock de seguridad dentro de la cobertura.
    - low: en otro caso.
    """
    if stockout_day is not None and stockout_day <= max(lead_time_days, 1):
        return "critical"
    if stockout_day is not None and stockout_day <= coverage_days:
        return "high"
    if min_balance is not None and safety_stock is not None and min_balance < safety_stock - _EPS:
        return "medium"
    return "low"


def _max_level(*levels: str) -> str:
    return max(levels, key=_RISK_ORDER.index)


def _orders_by_series(open_pos: pd.DataFrame | None) -> dict[tuple[int, int], pd.DataFrame]:
    if open_pos is None or open_pos.empty:
        return {}
    po = open_pos.copy()
    po["qty_open"] = pd.to_numeric(po["qty_open"], errors="coerce").fillna(0.0)
    po = po[po["qty_open"] > _EPS]
    if po.empty:
        return {}
    po["expected_at"] = pd.to_datetime(po["expected_at"]) if "expected_at" in po else pd.NaT
    return {(int(b), int(p)): g for (b, p), g in po.groupby(["branch_id", "product_id"])}


def _receipts(orders: pd.DataFrame | None, snapshot_in_transit: float, lead_time_days: int,
              cutoff: pd.Timestamp) -> tuple[list[dict], str]:
    """Recepciones previstas relativas al corte: día (1 = día siguiente al corte), cantidad, fecha."""
    assumed_day = max(1, int(lead_time_days))
    out: list[dict] = []
    if orders is not None and not orders.empty:
        for r in orders.itertuples(index=False):
            exp = getattr(r, "expected_at", pd.NaT)
            if pd.isna(exp):
                day, status = assumed_day, "undated"
            elif pd.Timestamp(exp) <= cutoff:
                day, status = assumed_day, "overdue"
            else:
                day, status = int((pd.Timestamp(exp).normalize() - cutoff.normalize()).days), "dated"
            out.append({"day": day, "qty": float(r.qty_open), "status": status, "source": "purchase_order"})
        return out, "purchase_orders"
    if snapshot_in_transit > _EPS:
        out.append({"day": assumed_day, "qty": float(snapshot_in_transit), "status": "undated", "source": "snapshot"})
        return out, "snapshot"
    return out, "none"


def build_recommendations(
    series_fc: pd.DataFrame,  # unique_id, branch_id, product_id, step, ds, yhat, lo_80, hi_80
    latest_inventory: pd.DataFrame,
    lead_times: pd.DataFrame,
    suppliers: pd.DataFrame,
    open_pos: pd.DataFrame,  # branch_id, product_id, qty_open, expected_at (una fila por orden)
    unit_costs: pd.Series,  # index (branch_id, product_id) -> costo unitario conocido
    error_model: LeadTimeErrorModel,
    profile: pd.DataFrame,
    scales: pd.Series,  # unique_id -> escala
    settings: dict,
    cutoff: pd.Timestamp,
) -> pd.DataFrame:
    if latest_inventory is None or latest_inventory.empty or series_fc.empty:
        return pd.DataFrame()
    cutoff = pd.Timestamp(cutoff)
    service_level = float(settings.get("service_level", 0.95))
    review = int(settings.get("review_period_days", 7))
    default_lt = int(settings.get("default_lead_time_days", 7))
    unknown_factor = float(settings.get("unknown_uncertainty_safety_factor", UNKNOWN_SAFETY_FACTOR))
    z = float(norm.ppf(service_level))
    cls_map = profile.set_index("unique_id")["demand_class"]
    inv = latest_inventory.set_index(["branch_id", "product_id"])
    orders_by_series = _orders_by_series(open_pos)
    rows = []
    for uid, fc in series_fc.sort_values("step").groupby("unique_id", sort=False):
        b, p = int(fc["branch_id"].iat[0]), int(fc["product_id"].iat[0])
        if (b, p) not in inv.index:
            continue
        snap = inv.loc[(b, p)]
        on_hand = float(snap["on_hand"])
        reserved = float(snap.get("reserved") or 0.0)
        snap_transit = float(snap.get("in_transit") or 0.0)
        physical = on_hand - reserved
        lt_days, lt_std, lt_source = resolve_lead_time(p, b, lead_times, suppliers, default_lt)
        L = max(1, lt_days + review)

        # Días de pronóstico contiguos disponibles desde el día 1 (nunca se extrapola más allá).
        steps = fc["step"].to_numpy(dtype=int)
        covered = int(np.cumprod(steps == np.arange(1, len(steps) + 1)).sum())
        yhat = np.clip(np.nan_to_num(fc["yhat"].to_numpy(dtype=float)[:covered], nan=0.0), 0, None)
        lo = fc["lo_80"].to_numpy(dtype=float)[:covered] if "lo_80" in fc else np.full(covered, np.nan)
        hi = fc["hi_80"].to_numpy(dtype=float)[:covered] if "hi_80" in fc else np.full(covered, np.nan)
        coverage_ok = covered >= L
        Lc = min(L, covered)

        # ── Recepciones y saldo diario proyectado ──
        receipts, transit_source = _receipts(orders_by_series.get((b, p)), snap_transit, lt_days, cutoff)
        in_transit = float(sum(r["qty"] for r in receipts))
        in_cov = float(sum(r["qty"] for r in receipts if r["day"] <= L))
        position = physical + in_cov
        daily_r = np.zeros(covered)
        for r in receipts:
            if r["day"] <= covered:
                daily_r[r["day"] - 1] += r["qty"]
        cum_d = np.cumsum(yhat)
        cum_r = np.cumsum(daily_r)
        balance = physical + cum_r - cum_d
        neg = np.flatnonzero(balance < -_EPS)
        stockout_day = int(neg[0]) + 1 if len(neg) else None
        demand_cov = float(cum_d[Lc - 1]) if Lc else 0.0
        min_balance = float(balance[:Lc].min()) if Lc else physical
        next_receipt = min((r["day"] for r in receipts if stockout_day is not None and r["day"] > stockout_day),
                           default=None)
        checkpoints = sorted({r["day"] - 1 for r in receipts if 2 <= r["day"] <= Lc} | ({Lc} if Lc else set()))

        scale = float(scales.get(uid, max(yhat[:28].mean() if len(yhat) else 0.05, 0.05)))
        demand_l = std_l = safety = reorder_point = suggested = prob = None
        lower_bound = None
        empirical_days = None
        lt_variability = None
        limitations: list[str] = []
        if not coverage_ok:
            method, uncertainty, ss_policy = "coverage_insufficient", "not_evaluated", None
            lower_bound = float(math.ceil(max(0.0, demand_cov - position) - 1e-9))
            limitations.append(
                f"El pronóstico disponible cubre {covered} de los {L} días necesarios (plazo {lt_days} + revisión "
                f"{review}); no se calcula la compra sugerida para no extrapolar la demanda. Con la demanda de los "
                f"días cubiertos se necesitan al menos {lower_bound:.0f} unidades.")
            level = _max_level(projected_risk_level(stockout_day, lt_days, L), "medium")
            risk_rule = "projected_balance"
        else:
            demand_l = float(cum_d[L - 1])
            cls = str(cls_map.get(uid, "smooth"))
            samples_l, days_l = error_model.samples_for(cls, L)
            sd = np.clip((hi[:L] - lo[:L]) / (2 * Z80), 0, None)
            if len(samples_l) >= MIN_EMPIRICAL:
                method, uncertainty, ss_policy = "empirical_backtest", "empirical", "empirical_quantile"
                empirical_days = days_l

                def errs_at(t: int) -> np.ndarray:
                    # Errores de la suma de t días; si la muestra cubre d ≠ t días (backtest más
                    # corto o respaldo con la muestra de L) se reescala con √(t/d).
                    s, d = error_model.samples_for(cls, t)
                    if len(s) < MIN_EMPIRICAL:
                        s, d = samples_l, days_l
                    return s * scale * math.sqrt(t / d)

                errs = errs_at(L)
                safety = max(0.0, float(np.quantile(errs, service_level)))
                std_l = float(np.std(errs))
                # Regla de sucesión de Laplace: con una muestra finita de errores nunca se
                # informa certeza absoluta (0 % o 100 %) de quiebre.
                probs = []
                for t in checkpoints:
                    e = errs if t == L else errs_at(t)
                    hits = int(np.sum(cum_d[t - 1] + e > physical + cum_r[t - 1]))
                    probs.append((hits + 1) / (len(e) + 2))
                prob = max(probs)
                if days_l < L:
                    limitations.append(f"Los errores del backtest cubren {days_l} días; para {L} días se ensanchan "
                                       f"con √({L}/{days_l}).")
            elif np.isfinite(sd).all() and len(sd) == L:
                cum_var = np.cumsum(sd ** 2)
                std_l = float(math.sqrt(float(cum_var[L - 1])))
                safety = z * std_l
                if std_l > 0:
                    method, uncertainty, ss_policy = "normal_from_intervals", "intervals", "normal_z_sigma"
                else:
                    method, uncertainty, ss_policy = "zero_variability", "zero_variability", "normal_z_sigma"
                probs = []
                for t in checkpoints:
                    sd_t = math.sqrt(float(cum_var[t - 1]))
                    gap = physical + cum_r[t - 1] - cum_d[t - 1]
                    probs.append(float(1 - norm.cdf(gap / sd_t)) if sd_t > 0 else float(gap < -_EPS))
                prob = max(probs)
            else:
                method, uncertainty, ss_policy = "unknown_uncertainty_policy", "unknown", "fraction_of_coverage_demand"
                safety = unknown_factor * demand_l
                limitations.append(
                    "Sin errores históricos ni intervalos: la incertidumbre es desconocida. No se informa "
                    f"probabilidad de quiebre; stock de seguridad provisional = {unknown_factor:.0%} de la demanda "
                    "en la cobertura y riesgo según el saldo proyectado.")
            # Variabilidad del plazo del proveedor (σ_L, días): suma independiente al error de la
            # demanda, σ² = σ_demanda² + (d̄·σ_L)², con d̄ la demanda diaria media de la cobertura.
            # La probabilidad de quiebre sigue reflejando solo la incertidumbre de la demanda.
            if lt_std is not None and lt_std > 0 and L > 0:
                lt_variability = (demand_l / L) * float(lt_std)
                if std_l is not None:
                    std_l = math.sqrt(std_l ** 2 + lt_variability ** 2)
                safety = math.sqrt(safety ** 2 + (z * lt_variability) ** 2)
            reorder_point = demand_l + safety
            suggested = float(math.ceil(max(0.0, reorder_point - position) - 1e-9))
            if prob is None:
                level = projected_risk_level(stockout_day, lt_days, L, min_balance, safety)
                risk_rule = "projected_balance"
            else:
                supply_day1 = physical + (daily_r[0] if covered else 0.0)
                level = _max_level(risk_level(prob, supply_day1, demand_l),
                                   projected_risk_level(stockout_day, lt_days, L))
                risk_rule = "probability_with_projection_floor"

        undated = [r for r in receipts if r["status"] != "dated"]
        if undated:
            limitations.append(f"{len(undated)} recepción(es) sin fecha o vencida(s): se asume llegada al final del "
                               f"plazo del proveedor (día {max(1, lt_days)}).")
        if next_receipt is not None:
            limitations.append(
                f"El saldo proyectado se agota el {(cutoff + pd.Timedelta(days=stockout_day)).date()}, antes de la "
                f"recepción prevista del {(cutoff + pd.Timedelta(days=next_receipt)).date()}.")
            if stockout_day is not None and stockout_day <= max(1, lt_days):
                # Orientación de acción: comprar más no llega a tiempo para este faltante.
                limitations.append(
                    f"Un pedido nuevo tardaría unos {max(1, lt_days)} días y no evitaría este faltante: conviene "
                    "acelerar la recepción pendiente o reasignar existencias entre sucursales.")

        avg30 = float(yhat[:30].mean()) if len(yhat) else 0.0
        days_cover = (max(physical, 0.0) / avg30) if avg30 > 1e-6 else None
        stockout_date = (cutoff + pd.Timedelta(days=stockout_day)).date() if stockout_day is not None else None
        unit_cost = unit_costs.get((b, p)) if unit_costs is not None else None
        if (unit_cost is None or pd.isna(unit_cost)) and pd.notna(snap.get("unit_cost")):
            unit_cost = float(snap["unit_cost"])
        unit_cost = None if unit_cost is None or pd.isna(unit_cost) else float(unit_cost)
        demand_90 = float(yhat[:90].sum())
        supply_total = max(physical, 0.0) + in_transit
        if level in ("high", "critical"):
            rtype = "stockout_risk"
        elif not coverage_ok:
            rtype = "reorder" if (lower_bound or 0) > 0 else "no_action"
        elif suggested and suggested > 0:
            rtype = "reorder"
        elif avg30 > 1e-6 and supply_total / avg30 > 120 and supply_total > 1.5 * demand_90:
            rtype = "overstock"
        else:
            rtype = "no_action"
        snap_date = pd.Timestamp(snap["ds"]).date()
        rows.append({
            "unique_id": uid, "branch_id": b, "product_id": p, "type": rtype,
            "inventory_snapshot_date": snap_date, "on_hand": on_hand, "reserved": reserved,
            "in_transit": in_transit, "available": physical, "lead_time_days": lt_days,
            "lead_time_source": lt_source, "review_period_days": review, "service_level": service_level,
            "demand_lead_time": _r(demand_l), "demand_lead_time_std": _r(std_l),
            "safety_stock": _r(safety), "reorder_point": _r(reorder_point),
            "suggested_qty": suggested, "unit_cost": unit_cost,
            "estimated_investment": round(suggested * unit_cost, 4) if unit_cost is not None and suggested is not None
            else None,
            "days_of_cover": round(days_cover, 2) if days_cover is not None else None,
            "stockout_probability": round(min(max(prob, 0.0), 1.0), 4) if prob is not None else None,
            "stockout_date_estimate": stockout_date, "risk_level": level,
            "assumptions": {
                "method": method,
                "uncertainty": uncertainty,
                "safety_stock_policy": ss_policy,
                "safety_stock_factor": unknown_factor if ss_policy == "fraction_of_coverage_demand" else None,
                "probability_method": "max_checkpoint_marginal" if prob is not None else None,
                "risk_rule": risk_rule,
                "coverage_days_needed": L,
                "coverage_days_available": covered,
                "coverage_insufficient": not coverage_ok,
                "horizon_days_covered": Lc,
                "suggested_qty_lower_bound": lower_bound,
                "empirical_error_days": empirical_days,
                "physical_available": round(physical, 4),
                "inventory_position": round(position, 4),
                "in_transit_within_coverage": round(in_cov, 4),
                "in_transit_after_coverage": round(in_transit - in_cov, 4),
                "receipts": [{"date": (cutoff + pd.Timedelta(days=r["day"])).date().isoformat(), "day": r["day"],
                              "qty": round(r["qty"], 4), "dated": r["status"] == "dated", "status": r["status"],
                              "source": r["source"]} for r in sorted(receipts, key=lambda x: x["day"])
                             ][:MAX_RECEIPTS_LISTED],
                "undated_arrival_assumption": "lead_time_end" if undated else None,
                "stockout_before_receipt": next_receipt is not None,
                "lead_time_std_days": lt_std,
                "lead_time_variability_units": _r(lt_variability),
                "service_level": service_level,
                "snapshot_stale": bool((cutoff.date() - snap_date).days > 14),
                "in_transit_source": transit_source,
                "snapshot_in_transit_ignored": round(snap_transit, 4)
                if transit_source == "purchase_orders" and snap_transit > _EPS else None,
                "limitations": limitations,
                "note": "Sugerencia basada en pronóstico; no ejecuta pedidos ni compromete fondos.",
            },
        })
    out = pd.DataFrame(rows)
    if not out.empty:
        # Nulos explícitos como NaN numérico (aunque todas las filas sean nulas) para operar y persistir.
        out[NULLABLE_NUMERIC] = out[NULLABLE_NUMERIC].astype(float)
    return out


def _r(v: float | None) -> float | None:
    return None if v is None else round(float(v), 4)
