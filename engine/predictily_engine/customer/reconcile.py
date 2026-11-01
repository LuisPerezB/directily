"""Reconciliación de la vista por comprador con la demanda agregada vigente (producto, horizonte H).

El pronóstico agregado (Demanda) y el volumen esperado por comprador son DOS VISTAS de la misma demanda: nunca se
suman. Para cada producto y horizonte:

    agregado A  =  compradores modelados B  +  comprometido fuera del modelo C_out  +  residual R

- B = Σ_(comprador, producto) max(esperanza, comprometido): el comprometido (pedidos/contratos confirmados) ya forma
  parte de la demanda del comprador y la esperanza probabilística NO se vuelve a sumar (se descompone en
  comprometido + incremento probabilístico).
- C_out = comprometido sin predicción (comprador sin historial de ese producto o sin comprador identificado).
- R = A − B − C_out (≥ 0) es la demanda de ventas anónimas, compradores nuevos y población no modelada.
- Si B + C_out > A se informa `inconsistent` con el exceso; R = 0 y las probabilidades individuales NO se alteran.
- Si el agregado no cubre los H días o no existe, el estado es `aggregate_incomplete`/`aggregate_unavailable`: no se estima residual.
Todas las cantidades están en la unidad del producto (la misma de las ventas y de la demanda agregada).
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd


def reconcile_horizon(pred: pd.DataFrame, committed_out: pd.DataFrame, aggregate: pd.DataFrame, horizon: int,
                      tolerance: float = 0.10) -> pd.DataFrame:
    """pred: product_id, expected_quantity, committed_quantity (una fila por comprador × producto, ya con comprometido).
    committed_out: product_id, committed_quantity (sin predicción). aggregate: product_id, days, yhat."""
    p = pred.assign(committed_quantity=pred.get("committed_quantity", 0.0)).copy()
    p["committed_quantity"] = p["committed_quantity"].fillna(0.0)
    p["total_view"] = np.maximum(p["expected_quantity"].fillna(0.0), p["committed_quantity"])
    p["incremental"] = p["total_view"] - p["committed_quantity"]
    by_p = p.groupby("product_id", as_index=False).agg(
        buyers_total_quantity=("total_view", "sum"), buyers_committed_quantity=("committed_quantity", "sum"),
        buyers_incremental_quantity=("incremental", "sum"), buyers_expected_quantity=("expected_quantity", "sum"),
        n_buyers=("expected_quantity", "size"))
    co = committed_out.groupby("product_id", as_index=False).agg(committed_outside_model=("committed_quantity", "sum")) \
        if len(committed_out) else pd.DataFrame(columns=["product_id", "committed_outside_model"])
    ag = aggregate[["product_id", "days", "yhat"]].rename(columns={"days": "aggregate_days", "yhat": "aggregate_quantity"}) \
        if len(aggregate) else pd.DataFrame(columns=["product_id", "aggregate_days", "aggregate_quantity"])
    ids = pd.DataFrame({"product_id": sorted(set(by_p["product_id"]) | set(co["product_id"]) | set(ag["product_id"]))})
    r = ids.merge(by_p, on="product_id", how="left").merge(co, on="product_id", how="left").merge(ag, on="product_id", how="left")
    for col in ("buyers_total_quantity", "buyers_committed_quantity", "buyers_incremental_quantity", "buyers_expected_quantity",
                "committed_outside_model", "n_buyers"):
        r[col] = r[col].astype(float).fillna(0.0)
    r["horizon_days"] = horizon
    r["aggregate_days_expected"] = horizon
    modeled = r["buyers_total_quantity"] + r["committed_outside_model"]
    residual = np.full(len(r), np.nan)
    excess = np.full(len(r), np.nan)
    status = np.empty(len(r), dtype=object)
    for i, row in enumerate(r.itertuples(index=False)):
        if pd.isna(row.aggregate_quantity):
            status[i] = "aggregate_unavailable"
        elif row.aggregate_days < horizon:
            status[i] = "aggregate_incomplete"
        elif modeled.iloc[i] <= row.aggregate_quantity + 1e-9:
            status[i] = "ok"
            residual[i] = row.aggregate_quantity - modeled.iloc[i]
        else:
            status[i] = "inconsistent"
            residual[i] = 0.0
            excess[i] = modeled.iloc[i] - row.aggregate_quantity
    r["residual_quantity"] = residual
    r["excess_quantity"] = excess
    r["status"] = status
    r["excess_ratio"] = np.where(r["aggregate_quantity"] > 0, r["excess_quantity"] / r["aggregate_quantity"], np.nan)
    sev = np.where(r["excess_ratio"].astype(float).fillna(9.0) <= tolerance, "minor", "major")
    r["severity"] = pd.Series(np.where(r["status"] == "inconsistent", sev, None), index=r.index, dtype=object)
    r["residual_share"] = np.where((r["status"] == "ok") & (r["aggregate_quantity"] > 0), r["residual_quantity"] / r["aggregate_quantity"], np.nan)
    return r


def reconciliation_summary(rec: pd.DataFrame) -> dict[str, Any]:
    if rec.empty:
        return {"products": 0}
    counts = rec["status"].value_counts().to_dict()
    return {"products": int(len(rec)), "status_counts": {k: int(v) for k, v in counts.items()},
            "inconsistent_major": int(((rec["status"] == "inconsistent") & (rec["severity"] == "major")).sum())}
