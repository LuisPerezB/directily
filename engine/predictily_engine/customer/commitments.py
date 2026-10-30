"""Demanda comprometida (pedidos y contratos confirmados) y su reconstrucción al corte.

Política (docs/architecture/v2/P2-comportamiento.md §7): solo `order` y `contract` en estado `confirmed` o
`partially_fulfilled`, conocidos al corte (`known_at ≤ corte`; sin `known_at` solo cuenta la ingestión cuando el
corte es el presente), no cancelados al corte y con entrega dentro de (corte, corte+H]. Pendiente = cantidad −
cumplida (nunca negativo). Las cotizaciones y los pedidos sin confirmar NO son demanda comprometida. Una línea
cuya unidad difiere de la del producto no se suma (sin conversiones silenciosas: P3 convierte con factores declarados).
La esperanza probabilística del comprador × producto NO se suma al comprometido: la demanda atribuida a la
relación es max(esperanza, comprometido).
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

COMMITTED_TYPES = ("order", "contract")
COMMITTED_STATUSES = ("confirmed", "partially_fulfilled")


def _d(v, tz: str = "America/Guayaquil") -> pd.Timestamp | None:
    """Fecha local (sin zona) de un instante o fecha; None si falta."""
    if v is None or (isinstance(v, float) and np.isnan(v)) or pd.isna(v):
        return None
    t = pd.Timestamp(v)
    if t.tzinfo is not None:
        t = t.tz_convert(tz).tz_localize(None)
    return t.normalize()


def state_as_of(current: pd.DataFrame, revisions: pd.DataFrame, sku_to_product: dict[str, int], as_of: date, today: date,
                tz: str = "America/Guayaquil") -> pd.DataFrame:
    """Líneas de compromisos tal como la fuente las conocía en `as_of`.

    - as_of ≥ hoy: estado actual de `commercial_commitments`/`items` (ya ingerido).
    - as_of < hoy: la última revisión con `known_at ≤ as_of` (snapshot de cabecera y líneas). Una revisión sin
      `known_at` es de conocimiento desconocido y no se usa hacia atrás. Los compromisos sin revisiones usan su
      estado actual solo si su `known_at` declarado es ≤ as_of (limitación: sin historial de cumplimiento).
    Devuelve una fila por línea con: commitment_id, customer_id, commitment_type, status, known_on, cancelled_on,
    expected_delivery_date, delivery_date, product_id, quantity, quantity_fulfilled, unit, product_unit, currency.
    """
    cols = ["commitment_id", "customer_id", "commitment_type", "status", "known_on", "cancelled_on", "expected_delivery_date",
            "delivery_date", "product_id", "quantity", "quantity_fulfilled", "unit", "product_unit", "currency", "unknown_knowledge"]
    if pd.Timestamp(as_of) >= pd.Timestamp(today):
        if current is None or current.empty:
            return pd.DataFrame(columns=cols)
        d = current.copy()
        d["known_on"] = d["known_on"].where(d["known_on"].notna(), d["ingested_on"])
        d["unknown_knowledge"] = current["known_on"].isna().to_numpy()
        return d[[c for c in cols if c in d.columns]].reindex(columns=cols)
    has_rev = revisions is not None and not revisions.empty
    cust = {}
    punit = {}
    if current is not None and len(current):
        cust = current.drop_duplicates("commitment_id").set_index("commitment_id")["customer_id"].to_dict()
        punit = current.drop_duplicates("product_id").set_index("product_id")["product_unit"].to_dict()
    rows = []
    with_revisions: set = set()
    if has_rev:
        with_revisions = set(revisions["commitment_id"].tolist())
        rev = revisions[revisions["known_on"].notna() & (pd.to_datetime(revisions["known_on"]) <= pd.Timestamp(as_of))]
        if not rev.empty:
            rev = rev.sort_values(["commitment_id", "revision"]).groupby("commitment_id").tail(1)
            for r in rev.itertuples(index=False):
                snap = r.snapshot if isinstance(r.snapshot, dict) else json.loads(r.snapshot)
                h, lines = snap["header"], snap["lines"]
                for ln in lines:
                    pid = sku_to_product.get(str(ln["product_sku"]).upper())
                    if pid is None:
                        continue
                    rows.append({
                        "commitment_id": r.commitment_id, "customer_id": cust.get(r.commitment_id), "commitment_type": h["commitment_type"],
                        "status": h["status"], "known_on": _d(h.get("known_at"), tz) or _d(r.known_on, tz), "cancelled_on": _d(h.get("cancelled_at"), tz),
                        "expected_delivery_date": _d(h.get("expected_delivery_date"), tz), "delivery_date": _d(ln.get("delivery_date"), tz),
                        "product_id": pid, "quantity": float(ln["quantity"]), "quantity_fulfilled": float(ln.get("quantity_fulfilled") or 0),
                        "unit": ln.get("unit"), "product_unit": punit.get(pid, ln.get("unit")), "currency": h.get("currency"),
                        "unknown_knowledge": False})
    # Compromisos SIN historial de revisiones (cargados fuera del importador): solo el estado actual, y solo si su `known_at`
    # declarado es ≤ corte. El cumplimiento y el estado son los actuales (sin historial no se pueden reconstruir): limitación.
    if current is not None and len(current):
        legacy = current[~current["commitment_id"].isin(with_revisions)]
        if len(legacy):  # el filtro de conocimiento (known_at ≤ corte) lo aplica committed_lines y deja el motivo registrado
            d = legacy.copy()
            d["unknown_knowledge"] = False
            rows.extend(d[[c for c in cols if c in d.columns]].reindex(columns=cols).to_dict("records"))
    return pd.DataFrame(rows, columns=cols)


def committed_lines(state: pd.DataFrame, as_of: date, horizon: int) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Filtra la demanda comprometida del horizonte y devuelve las líneas aceptadas + un resumen con motivos de exclusión."""
    end = pd.Timestamp(as_of) + pd.Timedelta(days=horizon)
    start = pd.Timestamp(as_of)
    summary: dict[str, Any] = {"horizon_days": horizon, "lines_total": int(len(state)), "excluded": {}, "quantity_by_reason": {}}
    if state is None or state.empty:
        summary.update(lines_committed=0, committed_quantity=0.0)
        return pd.DataFrame(columns=list(state.columns) + ["pending"] if state is not None else []), summary
    d = state.copy()
    for col in ("known_on", "cancelled_on", "expected_delivery_date", "delivery_date"):
        d[col] = pd.to_datetime(d[col])
    d["pending"] = np.maximum(d["quantity"] - d["quantity_fulfilled"].fillna(0.0), 0.0)
    d["due"] = d["delivery_date"].where(d["delivery_date"].notna(), d["expected_delivery_date"])
    reasons = pd.Series("", index=d.index, dtype=object)

    def mark(mask: pd.Series, reason: str) -> None:
        mask = mask & (reasons == "")
        reasons[mask] = reason
        summary["excluded"][reason] = int(mask.sum())
        summary["quantity_by_reason"][reason] = float(d.loc[mask, "pending"].sum())

    mark(~d["commitment_type"].isin(COMMITTED_TYPES), "not_order_or_contract")           # cotizaciones: intención, no compromiso
    mark(d["known_on"].isna() | (d["known_on"] > start), "unknown_or_after_cutoff")       # no conocido al corte
    mark(d["status"].isin(["cancelled"]) | (d["cancelled_on"].notna() & (d["cancelled_on"] <= start)), "cancelled")
    mark(d["status"].isin(["expired", "fulfilled"]), "expired_or_fulfilled")
    mark(~d["status"].isin(COMMITTED_STATUSES), "not_confirmed")                          # abiertos sin confirmar
    unit_bad = d["unit"].notna() & d["product_unit"].notna() & (d["unit"].astype(str).str.lower() != d["product_unit"].astype(str).str.lower())
    mark(unit_bad, "unit_mismatch")                                                         # sin conversiones silenciosas
    mark(d["pending"] <= 0, "nothing_pending")
    mark(d["due"].isna(), "undated_delivery")                                              # fecha desconocida: no se asume dentro de H
    mark(d["due"] <= start, "overdue_delivery")                                            # vencida: se informa aparte
    mark(d["due"] > end, "delivery_after_horizon")
    ok = d[reasons == ""]
    summary["lines_committed"] = int(len(ok))
    summary["committed_quantity"] = float(ok["pending"].sum())
    return ok, summary


def committed_by_relation(lines: pd.DataFrame) -> pd.DataFrame:
    """Pendiente comprometido por (comprador, producto); customer_id nulo = sin comprador atribuible."""
    if lines.empty:
        return pd.DataFrame(columns=["customer_id", "product_id", "committed_quantity", "n_commitments"])
    g = lines.assign(customer_id=lines["customer_id"].astype("float")).groupby(["customer_id", "product_id"], dropna=False, as_index=False).agg(
        committed_quantity=("pending", "sum"), n_commitments=("commitment_id", "nunique"))
    return g
