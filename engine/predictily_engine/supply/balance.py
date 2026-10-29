"""Balance fechado por producto/ubicación/período (instrucctions.md §6.F, E2-12).

Reutiliza las reglas de `inventory/recommendations.py` (existencias físicas utilizables = existencias − reservas;
las compras abiertas NO se suman al stock de hoy; cada orden llega en su fecha; una orden sin fecha o vencida
nunca se hace disponible hoy) y las completa con:

- política explícita para recepciones sin fecha / vencidas (`lead_time_end`, `observed_lead_time`, `exclude`);
- recepciones parciales: una orden `partially_received` aporta solo su saldo pendiente;
- cancelaciones: las órdenes `cancelled`/`draft` no aportan recepciones;
- evidencia de retrasos: plazo observado y retraso (recibido − esperado) por proveedor, solo con órdenes
  recibidas hasta el corte; alimenta la variabilidad del plazo;
- sin doble conteo: el tránsito del snapshot de inventario no se suma a las órdenes abiertas;
- vencimiento: capacidad opcional (`not_configured` si no hay lotes con fecha de vencimiento).

Identidad del balance (se verifica en las pruebas): apertura + recepciones − ventas = cierre y
demanda = ventas + demanda no atendida (ventas perdidas) en cada período.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from datetime import date

import numpy as np
import pandas as pd

from predictily_engine.supply.model import EPS, day_to_period

UNDATED_POLICIES = ("lead_time_end", "observed_lead_time", "exclude")
MIN_EVIDENCE_ORDERS = 5


@dataclass
class Receipt:
    day: int  # 1 = día siguiente al corte
    qty: float
    status: str  # dated | undated | overdue
    source: str  # purchase_order | snapshot
    order_id: int | None = None
    assumed: bool = False  # la fecha es un supuesto de la política, no un dato


def lead_time_evidence(po_history: pd.DataFrame | None, as_of: date, min_orders: int = MIN_EVIDENCE_ORDERS) -> pd.DataFrame:
    """Plazo observado (recibido − emitido) y retraso (recibido − esperado) por proveedor, con órdenes recibidas
    hasta `as_of`. Una recepción posterior al corte no existe para ese corte."""
    cols = ["supplier_id", "n_orders", "mean_lead", "std_lead", "n_delay", "mean_delay", "std_delay", "late_share"]
    if po_history is None or po_history.empty:
        return pd.DataFrame(columns=cols)
    df = po_history.copy()
    for c in ("ordered_at", "expected_at", "received_at"):
        df[c] = pd.to_datetime(df[c], errors="coerce")
    cut = pd.Timestamp(as_of)
    df = df[df["received_at"].notna() & (df["received_at"] <= cut) & df["ordered_at"].notna()
            & (df["status"] != "cancelled") & df["supplier_id"].notna()]
    if df.empty:
        return pd.DataFrame(columns=cols)
    df["lead"] = (df["received_at"] - df["ordered_at"]).dt.days.astype(float)
    df["delay"] = (df["received_at"] - df["expected_at"]).dt.days.astype(float)
    rows = []
    for sid, g in df.groupby("supplier_id"):
        lead = g["lead"].to_numpy()
        delay = g["delay"].dropna().to_numpy()
        if len(lead) < min_orders:
            continue
        rows.append({
            "supplier_id": int(sid), "n_orders": int(len(lead)), "mean_lead": float(lead.mean()),
            "std_lead": float(lead.std(ddof=1)) if len(lead) > 1 else None, "n_delay": int(len(delay)),
            "mean_delay": float(delay.mean()) if len(delay) else None,
            "std_delay": float(delay.std(ddof=1)) if len(delay) > 1 else None,
            "late_share": float((delay > 0).mean()) if len(delay) else None})
    return pd.DataFrame(rows, columns=cols)


def build_receipts(orders: pd.DataFrame | None, snapshot_in_transit: float, lead_time_days: int, as_of: date,
                   undated_policy: str = "lead_time_end", overdue_policy: str | None = None,
                   observed_lead: dict[int, float] | None = None) -> tuple[list[Receipt], dict]:
    """Recepciones previstas de una serie y metadatos de cómo se construyeron.

    `orders`: una fila por orden abierta con `qty_open`, `expected_at`, `ordered_at`, `supplier_id`,
    `purchase_order_id` (como `TenantRepository.open_purchase_orders`). Ninguna recepción cae el día 0 o antes.
    """
    if undated_policy not in UNDATED_POLICIES:
        raise ValueError(f"Política de órdenes sin fecha desconocida: {undated_policy}")
    overdue_policy = overdue_policy or undated_policy
    cutoff = pd.Timestamp(as_of).normalize()
    lead_day = max(1, int(lead_time_days))
    receipts: list[Receipt] = []
    info = {"source": "none", "excluded_qty": 0.0, "excluded_orders": 0, "snapshot_in_transit_ignored": 0.0,
            "undated_policy": undated_policy, "overdue_policy": overdue_policy, "unexplained_snapshot_transit": 0.0}
    snap = float(snapshot_in_transit or 0.0)
    has_orders = orders is not None and not orders.empty and (pd.to_numeric(orders["qty_open"], errors="coerce").fillna(0) > EPS).any()
    if has_orders:
        info["source"] = "purchase_orders"
        total_open = 0.0
        for r in orders.itertuples(index=False):
            qty = float(pd.to_numeric(r.qty_open, errors="coerce") or 0.0)
            if qty <= EPS:
                continue
            total_open += qty
            exp = getattr(r, "expected_at", pd.NaT)
            oid = getattr(r, "purchase_order_id", None)
            oid = None if oid is None or pd.isna(oid) else int(oid)
            if pd.isna(exp):
                status, policy = "undated", undated_policy
            elif pd.Timestamp(exp).normalize() <= cutoff:
                status, policy = "overdue", overdue_policy
            else:
                receipts.append(Receipt(int((pd.Timestamp(exp).normalize() - cutoff).days), qty, "dated",
                                        "purchase_order", oid))
                continue
            if policy == "exclude":
                info["excluded_qty"] += qty
                info["excluded_orders"] += 1
                continue
            day = lead_day
            if policy == "observed_lead_time":
                sup = getattr(r, "supplier_id", None)
                ordered = getattr(r, "ordered_at", pd.NaT)
                obs = (observed_lead or {}).get(int(sup)) if sup is not None and not pd.isna(sup) else None
                if obs is not None and not pd.isna(ordered):
                    day = max(1, int(math.ceil((pd.Timestamp(ordered).normalize() + pd.Timedelta(days=obs) - cutoff).days)))
            receipts.append(Receipt(day, qty, status, "purchase_order", oid, assumed=True))
        if snap > EPS:
            info["snapshot_in_transit_ignored"] = snap
            info["unexplained_snapshot_transit"] = max(0.0, snap - total_open)
    elif snap > EPS:
        info["source"] = "snapshot"
        if undated_policy == "exclude":
            info["excluded_qty"] += snap
        else:
            receipts.append(Receipt(lead_day, snap, "undated", "snapshot", None, assumed=True))
    return receipts, info


def receipts_by_period(receipts: list[Receipt], n_periods: int, period_days: int) -> np.ndarray:
    """Recepciones por período del planificador (ver supply/model.py para la regla conservadora)."""
    out = np.zeros(n_periods)
    for r in receipts:
        tau = day_to_period(r.day, period_days)
        if tau < n_periods:
            out[tau] += r.qty
    return out


def project_balance(physical: float, receipts: list[Receipt], daily_demand: np.ndarray, period_days: int = 1,
                    mode: str = "lost_sales") -> pd.DataFrame:
    """Balance proyectado por período (por defecto diario). Una fila por período:

    opening, receipts_dated, receipts_assumed (fechas supuestas por la política), demand, sales, unmet, closing.
    `lost_sales`: lo no atendido se pierde; `backorder`: lo no atendido se arrastra como saldo negativo.
    """
    d = np.asarray(daily_demand, dtype=float)
    n = len(d) // period_days
    rows = []
    prev = float(physical)
    for tau in range(n):
        lo, hi = tau * period_days, (tau + 1) * period_days
        dated = sum(r.qty for r in receipts if lo < r.day <= hi and not r.assumed)
        assumed = sum(r.qty for r in receipts if lo < r.day <= hi and r.assumed)
        dem = float(d[lo:hi].sum())
        avail = prev + dated + assumed
        if mode == "backorder":
            sales, unmet, closing = dem, 0.0, avail - dem
        else:
            sales = min(dem, max(avail, 0.0))
            unmet = dem - sales
            closing = avail - sales
        rows.append({"period": tau, "opening": prev, "receipts_dated": dated, "receipts_assumed": assumed,
                     "demand": dem, "sales": sales, "unmet": unmet, "closing": closing})
        prev = closing
    return pd.DataFrame(rows, columns=["period", "opening", "receipts_dated", "receipts_assumed", "demand", "sales",
                                       "unmet", "closing"])


def balance_identity_errors(bal: pd.DataFrame, tol: float = 1e-6) -> list[str]:
    """Violaciones de la identidad del balance (vacío = íntegro)."""
    errs = []
    for r in bal.itertuples(index=False):
        if abs(r.opening + r.receipts_dated + r.receipts_assumed - r.sales - r.closing) > tol:
            errs.append(f"período {r.period}: apertura + recepciones − ventas ≠ cierre")
        if abs(r.demand - r.sales - r.unmet) > tol:
            errs.append(f"período {r.period}: demanda ≠ ventas + no atendida")
    return errs


# ── Vencimiento (capacidad opcional) ──────────────────────────────────────────────────────

def expiry_projection(lots: pd.DataFrame | None, daily_demand: np.ndarray, as_of: date) -> dict:
    """Proyecta mermas por vencimiento con salida FEFO. Sin lotes con fecha ⇒ `not_configured` (no se inventa).

    `lots`: qty, expires_on (de una sola serie). Devuelve {status, expired_qty, expired_by_day, usable_stock}.
    """
    if lots is None or lots.empty or "expires_on" not in lots or lots["expires_on"].isna().all():
        return {"status": "not_configured", "reason": "No hay lotes con fecha de vencimiento para esta serie.",
                "expired_qty": None, "expired_by_day": None}
    df = lots.dropna(subset=["expires_on"]).copy()
    df["expires_on"] = pd.to_datetime(df["expires_on"])
    df = df.sort_values("expires_on")
    remaining = df["qty"].astype(float).to_numpy().copy()
    expiry_day = np.array([(e.normalize() - pd.Timestamp(as_of).normalize()).days for e in df["expires_on"]])
    n = len(daily_demand)
    expired = np.zeros(n)
    for day in range(1, n + 1):
        need = float(daily_demand[day - 1])
        for i in range(len(remaining)):  # FEFO: primero vence, primero sale
            if remaining[i] <= EPS or expiry_day[i] < day:
                continue
            take = min(remaining[i], need)
            remaining[i] -= take
            need -= take
            if need <= EPS:
                break
        for i in range(len(remaining)):
            if expiry_day[i] == day and remaining[i] > EPS:
                expired[day - 1] += remaining[i]
                remaining[i] = 0.0
    return {"status": "configured", "expired_qty": float(expired.sum()), "expired_by_day": expired.tolist(),
            "unsold_after_horizon": float(sum(remaining[i] for i in range(len(remaining)) if expiry_day[i] > n))}
