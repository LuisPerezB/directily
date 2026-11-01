"""Demanda comprometida (pedidos y contratos confirmados) a un corte.

Estado a un corte = la regla de P3 (`CommitmentAsOf`, docs/architecture/v2/P3-datos.md §2.3):
1. versiones visibles = revisiones con `effective_known_at = COALESCE(known_at, ingested_at) ≤ corte` (fin del día local de `as_of`). La ingestión
   (`ingested_at` = `recorded_at` de la revisión) es una COTA SUPERIOR del conocimiento: con `known_at` declarado manda éste (`known_basis = declared`); sin él,
   la ingestión (`known_basis = ingestion`). Un histórico importado hoy sin `known_at` no se usa en cortes pasados, pero sí cuenta en el corte de hoy.
   Sin ninguna versión visible el compromiso NO existe a ese corte;
2. se usa la última versión visible (cabecera, líneas y cantidades cumplidas tal como se conocían);
3. cancelado al corte si `cancelled_at ≤ corte`; estado cancelado SIN fecha ⇒ fecha de cancelación desconocida, pendiente 0;
4. pendiente por línea = max(0, cantidad − cumplida), 0 si está cancelado.

Sobre esa vista el Customer Core solo cuenta como comprometido: `order`/`contract` en estado `confirmed`/`partially_fulfilled`,
con entrega en `(corte, corte+H]` (fecha de la línea o de la cabecera). Cotizaciones y abiertos sin confirmar no son compromiso. Una línea
cuya unidad difiere de la del producto no se suma (sin conversiones silenciosas: P3 convierte con factores declarados al importar).
La esperanza probabilística del comprador×producto NO se suma al comprometido: la demanda atribuida a la relación es max(esperanza, comprometido).
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

COMMITTED_TYPES = ("order", "contract")
COMMITTED_STATUSES = ("confirmed", "partially_fulfilled")
STATE_COLUMNS = ["commitment_id", "customer_id", "commitment_type", "status", "known_on", "known_basis", "cancelled_on", "expected_delivery_date",
                 "delivery_date", "product_id", "quantity", "quantity_fulfilled", "unit", "product_unit", "currency"]


def _day(v) -> pd.Timestamp | None:
    """Fecha local (sin hora) de un instante/fecha; None si falta."""
    if v is None or (isinstance(v, float) and np.isnan(v)) or pd.isna(v):
        return None
    return pd.Timestamp(v).normalize()


def state_as_of(revisions: pd.DataFrame, sku_to_product: dict[str, int], product_unit: dict[int, str],
                as_of: date) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Líneas de compromisos tal como la fuente las conocía a `as_of` (regla P3) + estadísticas de visibilidad.

    `revisions`: una fila por revisión con commitment_id, revision, status, known_local (declarado, puede faltar), recorded_local (ingestión),
    cancelled_local (instantes en hora local de la empresa, sin zona), snapshot {header, lines}, commitment_type, customer_id, currency.
    """
    stats: dict[str, Any] = {"commitments_total": 0, "not_visible_unknown_or_later": 0, "cancellation_date_unknown": 0, "known_by_ingestion": 0}
    if revisions is None or revisions.empty:
        return pd.DataFrame(columns=STATE_COLUMNS), stats
    end = pd.Timestamp(as_of) + pd.Timedelta(days=1)                    # fin del día de `as_of` (exclusivo)
    declared = pd.to_datetime(revisions["known_local"])
    ingested = pd.to_datetime(revisions["recorded_local"]) if "recorded_local" in revisions else pd.Series(pd.NaT, index=revisions.index)
    effective = declared.where(declared.notna(), ingested)          # la ingestión es la cota superior del conocimiento
    revisions = revisions.assign(effective_known=effective, known_basis=np.where(declared.notna(), "declared", "ingestion"))
    stats["commitments_total"] = int(revisions["commitment_id"].nunique())
    visible = revisions[revisions["effective_known"].notna() & (revisions["effective_known"] < end)]
    stats["not_visible_unknown_or_later"] = stats["commitments_total"] - int(visible["commitment_id"].nunique())
    if visible.empty:
        return pd.DataFrame(columns=STATE_COLUMNS), stats
    last = visible.sort_values(["commitment_id", "revision"]).groupby("commitment_id").tail(1)
    stats["known_by_ingestion"] = int((last["known_basis"] == "ingestion").sum())
    rows = []
    for r in last.itertuples(index=False):
        snap = r.snapshot if isinstance(r.snapshot, dict) else json.loads(r.snapshot)
        header, lines = snap.get("header", {}), snap.get("lines", [])
        cancelled_ts = pd.Timestamp(r.cancelled_local) if pd.notna(r.cancelled_local) else None
        cancelled_now = cancelled_ts is not None and cancelled_ts < end
        status = str(header.get("status") or r.status)
        if cancelled_now:
            status = "cancelled"
        elif status == "cancelled" and cancelled_ts is not None:
            # cancelación posterior al corte: a esa fecha seguía vigente
            done = sum(float(ln.get("quantity_fulfilled") or 0) for ln in lines)
            status = "partially_fulfilled" if done > 0 else ("open" if r.commitment_type == "quote" else "confirmed")
        elif status == "cancelled":
            stats["cancellation_date_unknown"] += 1                      # estado cancelado sin fecha: pendiente 0
        for ln in lines:
            pid = sku_to_product.get(str(ln["product_sku"]).upper())
            if pid is None:
                continue
            rows.append({
                "commitment_id": int(r.commitment_id), "customer_id": r.customer_id if pd.notna(r.customer_id) else None,
                "commitment_type": r.commitment_type, "status": status, "known_on": _day(r.effective_known), "known_basis": r.known_basis,
                "cancelled_on": _day(cancelled_ts) if cancelled_now else None, "expected_delivery_date": _day(header.get("expected_delivery_date")),
                "delivery_date": _day(ln.get("delivery_date")), "product_id": pid, "quantity": float(ln["quantity"]),
                "quantity_fulfilled": float(ln.get("quantity_fulfilled") or 0), "unit": ln.get("unit"),
                "product_unit": product_unit.get(pid, ln.get("unit")), "currency": r.currency})
    return pd.DataFrame(rows, columns=STATE_COLUMNS), stats


def committed_lines(state: pd.DataFrame, as_of: date, horizon: int, pre_excluded: dict[str, int] | None = None) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Filtra la demanda comprometida del horizonte y devuelve las líneas aceptadas + un resumen con los motivos de exclusión.

    `pre_excluded`: estadísticas de `state_as_of` (compromisos no visibles al corte / cancelación sin fecha), que se suman al resumen.
    """
    pre = pre_excluded or {}
    end = pd.Timestamp(as_of) + pd.Timedelta(days=horizon)
    start = pd.Timestamp(as_of)
    summary: dict[str, Any] = {"horizon_days": horizon, "lines_total": int(len(state)), "excluded": {}, "quantity_by_reason": {},
                               "commitments_not_visible_at_cutoff": int(pre.get("not_visible_unknown_or_later", 0)),
                               "commitments_known_by_ingestion": int(pre.get("known_by_ingestion", 0))}
    summary["excluded"]["unknown_or_after_cutoff"] = int(pre.get("not_visible_unknown_or_later", 0))
    if state is None or state.empty:
        summary.update(lines_committed=0, committed_quantity=0.0)
        return pd.DataFrame(columns=STATE_COLUMNS + ["pending"]), summary
    d = state.copy()
    for col in ("known_on", "cancelled_on", "expected_delivery_date", "delivery_date"):
        d[col] = pd.to_datetime(d[col])
    d["pending"] = np.maximum(d["quantity"] - d["quantity_fulfilled"].fillna(0.0), 0.0)
    d["due"] = d["delivery_date"].where(d["delivery_date"].notna(), d["expected_delivery_date"])
    reasons = pd.Series("", index=d.index, dtype=object)

    def mark(mask: pd.Series, reason: str) -> None:
        mask = mask & (reasons == "")
        reasons[mask] = reason
        summary["excluded"][reason] = summary["excluded"].get(reason, 0) + int(mask.sum())
        summary["quantity_by_reason"][reason] = float(d.loc[mask, "pending"].sum())

    mark(~d["commitment_type"].isin(COMMITTED_TYPES), "not_order_or_contract")           # cotizaciones: intención, no compromiso
    mark(d["known_on"].isna() | (d["known_on"] > start), "unknown_or_after_cutoff")       # defensa: la regla P3 ya lo excluye
    mark(d["status"].eq("cancelled") & d["cancelled_on"].isna(), "cancellation_date_unknown")
    mark(d["status"].eq("cancelled"), "cancelled")
    mark(d["status"].isin(["expired", "fulfilled"]), "expired_or_fulfilled")
    mark(~d["status"].isin(COMMITTED_STATUSES), "not_confirmed")                          # abiertos sin confirmar
    unit_bad = d["unit"].notna() & d["product_unit"].notna() & (d["unit"].astype(str).str.lower() != d["product_unit"].astype(str).str.lower())
    mark(unit_bad, "unit_mismatch")                                                         # sin conversiones silenciosas
    mark(d["pending"] <= 0, "nothing_pending")
    mark(d["due"].isna(), "undated_delivery")                                              # fecha desconocida: no se asume dentro de H
    mark(d["due"] <= start, "overdue_delivery")                                            # vencida: se informa aparte
    mark(d["due"] > end, "delivery_after_horizon")
    ok = d[reasons == ""]
    summary["lines_known_by_ingestion"] = int((ok["known_basis"] == "ingestion").sum()) if "known_basis" in ok else 0
    summary["lines_committed"] = int(len(ok))
    summary["committed_quantity"] = float(ok["pending"].sum())
    return ok, summary


def committed_by_relation(lines: pd.DataFrame) -> pd.DataFrame:
    """Pendiente comprometido por (comprador, producto); customer_id nulo = sin comprador atribuible."""
    if lines.empty:
        return pd.DataFrame(columns=["customer_id", "product_id", "committed_quantity", "n_commitments"])
    return lines.assign(customer_id=lines["customer_id"].astype("float")).groupby(["customer_id", "product_id"], dropna=False, as_index=False).agg(
        committed_quantity=("pending", "sum"), n_commitments=("commitment_id", "nunique"))
