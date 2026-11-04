"""Degradación del Customer Core a agregado (V2.5 §25.2, ADR-V25-05/-11): «si los IDs son inestables, deshabilitar recomendaciones
individualizadas y conservar Demand Core general».

Cuando el gate de calidad de W2-C da DEGRADE (IDs de comprador o de producto inestables, o demasiadas ventas de compradores genéricos/anónimos)
o BLOCK, el Customer Core NO entrena ni predice a nivel comprador: no se escribe ninguna fila individual (`customer_predictions`,
`customer_product_rankings`, `customer_activity`), cada horizonte queda `insufficient_data` con el código del gate y la ejecución lleva el
bloque `individual_recommendations` con su estado. En lugar de recomendaciones individuales se publica un RANKING AGREGADO de productos que no
identifica ni inventa compradores:
- fuente preferida: la demanda agregada vigente (Demand Core), suma pronosticada en el horizonte por producto;
- sin demanda vigente: unidades vendidas en los últimos N días a compradores identificados (parcial: excluye genéricos y anónimos; se declara).
"""

from __future__ import annotations

from datetime import date, timedelta
from typing import Any

import pandas as pd

AGGREGATE_RANKING_VERSION = "cust-aggregate-ranking-v1"


def ranking_from_demand(agg: pd.DataFrame, top_n: int, horizon_days: int, forecast_run_id: int) -> dict[str, Any]:
    """Top productos por demanda agregada pronosticada (suma en (as_of, as_of + H])."""
    items: list[dict[str, Any]] = []
    if agg is not None and len(agg):
        d = agg.sort_values(["yhat", "product_id"], ascending=[False, True], kind="mergesort").head(top_n)
        items = [{"rank": i + 1, "product_id": int(r.product_id), "forecast_units": float(r.yhat), "forecast_days": int(r.days)}
                 for i, r in enumerate(d.itertuples(index=False))]
    return {"version": AGGREGATE_RANKING_VERSION, "source": "demand_forecast", "forecast_run_id": int(forecast_run_id),
            "horizon_days": int(horizon_days), "unit": "unidades pronosticadas (todas las ventas, agregado)", "partial": False, "items": items}


def ranking_from_purchases(purchases: pd.DataFrame, as_of: date, days: int, top_n: int) -> dict[str, Any]:
    """Top productos por unidades vendidas a compradores IDENTIFICADOS en (as_of − días, as_of] (parcial: sin genéricos ni anónimos)."""
    items: list[dict[str, Any]] = []
    if purchases is not None and len(purchases):
        p = purchases[(purchases["ds"] > pd.Timestamp(as_of - timedelta(days=days))) & (purchases["ds"] <= pd.Timestamp(as_of))]
        if len(p):
            g = p.groupby("product_id").agg(units=("qty", "sum"), orders=("ds", "size"), buyers=("customer_id", "nunique")).reset_index()
            g = g.sort_values(["units", "product_id"], ascending=[False, True], kind="mergesort").head(top_n)
            items = [{"rank": i + 1, "product_id": int(r.product_id), "units": float(r.units), "orders": int(r.orders), "buyers": int(r.buyers)}
                     for i, r in enumerate(g.itertuples(index=False))]
    return {"version": AGGREGATE_RANKING_VERSION, "source": "identified_purchases_units", "window_days": int(days), "unit": "unidades vendidas",
            "partial": True, "partial_note": "Solo compradores identificados no genéricos; las ventas anónimas y de consumidor final no están en este ranking.",
            "items": items}


def individual_recommendations_block(qa: Any, aggregate_ranking: dict[str, Any] | None) -> dict[str, Any]:
    """Estado de las recomendaciones individuales en la ejecución: deshabilitadas por el gate, con el motivo y el ranking agregado."""
    return {"status": "disabled", "reason_code": qa.individual_stop_code, "message": qa.individual_stop_message(),
            "gate_outcome": qa.outcome, "reason_codes": qa.reason_codes(), "disabled_features": list(qa.disabled_features),
            "individual_rows_written": 0,
            "note": ("No se escriben filas individuales (comprador × producto): ni predicciones, ni ranking por comprador, ni riesgo de inactividad. "
                     "Se conserva el pronóstico agregado de demanda."),
            "aggregate_ranking": aggregate_ranking}
