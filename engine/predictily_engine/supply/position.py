"""Posición de inventario de una serie: validez del stock físico, ajustes, compromisos y reservas (V2.5 · W5-D, §10).

Componentes del balance de abastecimiento, SEPARADOS entre sí (docs/supply/SUPPLY_CORE.md §1):

    existencia física válida = on_hand del snapshot + ajustes POSTERIORES al snapshot          (stock físico)
    reservado                = unidades que el ERP aparta para pedidos                          (compromiso con inventario)
    disponible               = física válida − reservado (si no está ya dentro de la demanda)   (lo que puede vender un pedido nuevo)
    comprometido             = pedidos/contratos confirmados con entrega en el horizonte         (demanda firme, por período)
    en tránsito              = órdenes de compra abiertas con su fecha                           (recepciones, `balance.build_receipts`)
    ajustes                  = mermas, conteos y correcciones con fecha y motivo                 (nunca ventas ni demanda)

**Validez del físico** (V25-SUP-002/032): un físico negativo, una sobrerreserva (reservado > físico) y un snapshot antiguo se DECLARAN; la
existencia utilizable sigue acotada en cero (nunca un inventario negativo silencioso) y la cantidad recortada queda registrada.

**Sin doble conteo de compromisos** (V25-SUP-003/025/037). El pronóstico de demanda son ventas esperadas y los pedidos confirmados SON ventas
futuras, así que están dentro del pronóstico. Por período τ: demanda neta = max(pronóstico_τ, comprometido_τ) = comprometido + residual
probabilístico; NUNCA pronóstico + comprometido. Lo reservado que respalda pedidos confirmados del horizonte NO se descuenta de la existencia
(esas unidades ya salen como demanda); solo se descuenta el reservado no respaldado por pedidos. Ninguna predicción incrementa `reserved`
(las predicciones no reservan stock). Sin pedidos confirmados cargados se conserva la regla anterior (todo lo reservado se descuenta) y se declara
el posible solapamiento con el pronóstico.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.commitments import committed_lines, state_as_of

EPS = 1e-9
COMMITMENT_COLUMNS = ["branch_id", "product_id", "commitment_id", "due", "pending"]


def stock_validity(on_hand: float, reserved: float, snapshot_date: date | None, as_of: date, stale_days: int) -> dict[str, Any]:
    """Banderas de validez del stock físico. No corrige nada: declara (la existencia utilizable la acota el llamador)."""
    out: dict[str, Any] = {"negative_physical": on_hand < -EPS, "negative_qty": max(-on_hand, 0.0),
                           "over_reserved": reserved > max(on_hand, 0.0) + EPS, "over_reserved_qty": max(reserved - max(on_hand, 0.0), 0.0),
                           "snapshot_date": snapshot_date.isoformat() if snapshot_date else None, "snapshot_age_days": None, "snapshot_stale": False}
    if snapshot_date is not None:
        age = (as_of - snapshot_date).days
        out["snapshot_age_days"] = age
        out["snapshot_stale"] = age > int(stale_days)
    return out


def committed_lines_by_series(revisions: pd.DataFrame | None, sku_to_product: dict[str, int], product_unit: dict[int, str], as_of: date,
                              horizon_days: int) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Pedidos/contratos CONFIRMADOS con entrega en (corte, corte + horizonte], tal como se conocían al corte (regla de visibilidad de P3,
    `customer.commitments`), con su sucursal de cabecera. Las líneas sin sucursal no son atribuibles a una serie: se informan aparte y NO se
    descuentan de ninguna serie. Devuelve (líneas, resumen)."""
    cols = COMMITMENT_COLUMNS
    summary: dict[str, Any] = {"horizon_days": int(horizon_days), "lines_committed": 0, "committed_quantity": 0.0, "unattributed_quantity": 0.0,
                               "unattributed_lines": 0, "excluded": {}}
    if revisions is None or revisions.empty:
        return pd.DataFrame(columns=cols), summary
    state, stats = state_as_of(revisions, sku_to_product, product_unit, as_of)
    lines, cl = committed_lines(state, as_of, int(horizon_days), stats)
    summary["excluded"] = {k: int(v) for k, v in (cl.get("excluded") or {}).items() if v}
    summary["commitments_not_visible_at_cutoff"] = int(cl.get("commitments_not_visible_at_cutoff", 0))
    if lines.empty:
        return pd.DataFrame(columns=cols), summary
    branch = revisions.drop_duplicates("commitment_id").set_index("commitment_id")["branch_id"] if "branch_id" in revisions else pd.Series(dtype=float)
    out = pd.DataFrame({"branch_id": lines["commitment_id"].map(branch), "product_id": lines["product_id"].astype(int),
                        "commitment_id": lines["commitment_id"].astype(int), "due": pd.to_datetime(lines["due"]),
                        "pending": lines["pending"].astype(float)})
    unattributed = out[out["branch_id"].isna()]
    summary["unattributed_lines"] = int(len(unattributed))
    summary["unattributed_quantity"] = float(unattributed["pending"].sum())
    out = out[out["branch_id"].notna()].copy()
    out["branch_id"] = out["branch_id"].astype(int)
    summary["lines_committed"] = int(len(out))
    summary["committed_quantity"] = float(out["pending"].sum())
    return out[cols].reset_index(drop=True), summary


def committed_by_period(lines: pd.DataFrame | None, branch_id: int, product_id: int, as_of: date, period_days: int, n_periods: int) -> np.ndarray:
    """Pendiente comprometido de la serie por período del plan (período τ = días τ·R + 1 … (τ+1)·R después del corte)."""
    out = np.zeros(n_periods)
    if lines is None or lines.empty:
        return out
    sel = lines[(lines["branch_id"] == branch_id) & (lines["product_id"] == product_id)]
    for r in sel.itertuples(index=False):
        d = int((pd.Timestamp(r.due).normalize() - pd.Timestamp(as_of).normalize()).days)
        tau = (d - 1) // int(period_days)
        if 0 <= tau < n_periods:
            out[tau] += float(r.pending)
    return out


def reconcile_demand(forecast: np.ndarray, committed: np.ndarray) -> dict[str, Any]:
    """Demanda neta por período = max(pronóstico, comprometido) = comprometido + residual probabilístico (nunca la suma de ambos)."""
    f = np.asarray(forecast, dtype=float)
    c = np.asarray(committed, dtype=float)
    net = np.maximum(f, c)
    return {"net": net, "committed": c, "residual": net - c, "committed_above_forecast": np.maximum(c - f, 0.0),
            "double_count_avoided": float(np.minimum(f, c).sum())}


def net_daily(daily: np.ndarray, committed: np.ndarray, period_days: int) -> np.ndarray:
    """Demanda diaria NETA de pedidos confirmados: dentro de cada período del plan el total pasa a max(pronóstico, comprometido) conservando la forma
    diaria del pronóstico (escala proporcional; reparto uniforme si el pronóstico del período es 0). Es la misma regla de `reconcile_demand` llevada al
    día, para que la merma FEFO y el tope por vida útil usen la demanda reconciliada y no el pronóstico crudo."""
    out = np.array(daily, dtype=float, copy=True)
    R = int(period_days)
    for tau, c in enumerate(np.asarray(committed, dtype=float)):
        lo, hi = tau * R, min((tau + 1) * R, len(out))
        if hi <= lo:
            break
        total = float(out[lo:hi].sum())
        if c <= EPS or total >= c:
            continue
        if total > EPS:
            out[lo:hi] *= c / total
        else:
            out[lo:hi] = c / (hi - lo)
    return out


def adjustments_after_snapshot(adjustments: pd.DataFrame | None, branch_id: int, product_id: int, snapshot_date: date | None,
                               as_of: date) -> tuple[float, list[dict[str, Any]]]:
    """Ajustes de la serie ocurridos DESPUÉS del snapshot y hasta el corte (con signo, fecha y motivo). Un ajuste anterior o igual a la fecha
    del snapshot ya está en `on_hand` y no se vuelve a aplicar."""
    if adjustments is None or adjustments.empty or snapshot_date is None:
        return 0.0, []
    sel = adjustments[(adjustments["branch_id"] == branch_id) & (adjustments["product_id"] == product_id)]
    if sel.empty:
        return 0.0, []
    on = pd.to_datetime(sel["occurred_on"])
    sel = sel[(on > pd.Timestamp(snapshot_date)) & (on <= pd.Timestamp(as_of))]
    detail = [{"date": pd.Timestamp(r.occurred_on).date().isoformat(), "quantity": float(r.quantity),
               "reason": None if pd.isna(r.reference) else str(r.reference)} for r in sel.itertuples(index=False)]
    return float(sel["quantity"].sum()), detail
