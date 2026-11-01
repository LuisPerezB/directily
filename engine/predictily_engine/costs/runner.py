"""Ejecución del pronóstico de costos (core_runs: core = cost, operation = forecast)."""

from __future__ import annotations

import time
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd
from sqlalchemy import text

from predictily_engine.costs.forecast import (
    build_purchase_series,
    build_sales_cost_series,
    forecast_series,
)
from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.db.writer import jdump
from predictily_engine.platform.config import resolve_config
from predictily_engine.platform.snapshot import DataSnapshot, persist_snapshot
from predictily_engine.supply.runs import complete_run

TARGET_DEFINITION = {
    "metric": "unit_cost_forecast",
    "description": "Costo unitario de compra por producto y proveedor (no cantidades). Referencia: último valor.",
    "granularity": "month",
}


def run_cost_forecast(engine, tenant_id: int, run: dict[str, Any], job_id: int | None, progress, t0: float) -> dict[str, Any]:
    run_id = int(run["id"])
    opts = run.get("options") or {}
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        tenant = repo.tenant()
        cfg, cfg_version, provenance = resolve_config("cost", tenant["settings"], tenant.get("sector"), opts.get("config") or None)
        progress("loading_data", 10)
        as_of = date.fromisoformat(str(opts["as_of"])[:10]) if opts.get("as_of") else (repo.last_sale_date() or date.today())
        retro = bool(opts.get("historical", False))
        history = repo.purchase_cost_history()
        products = repo.products()
        sales_cost = repo.sales_cost_history(as_of)
        currency = str(tenant.get("currency") or "USD")
    policy = str(opts.get("null_known_at_policy") or cfg.get("null_known_at_policy", "strict"))
    progress("forecasting", 40)
    series, stats = build_purchase_series(history, as_of, retro, policy)
    with_purchase = {s.product_id for s in series}
    unit_by_product = {int(r.product_id): (None if pd.isna(r.unit) else str(r.unit)) for r in products.itertuples(index=False)}
    series += build_sales_cost_series(sales_cost, as_of, currency, with_purchase, unit_by_product)
    max_series = int(cfg.get("max_series", 2000))
    limitations: list[str] = []
    if len(series) > max_series:
        limitations.append(f"Se pronosticaron {max_series} de {len(series)} series (límite configurado).")
        series = series[:max_series]
    horizon_months = max(1, int(np.ceil(max(cfg.get("horizons", [30, 90])) / int(cfg.get("period_days", 30)))))
    results = [forecast_series(s, as_of, horizon_months, cfg) for s in series]
    quality_counts: dict[str, int] = {}
    for r in results:
        quality_counts[r["quality_status"]] = quality_counts.get(r["quality_status"], 0) + 1
    if stats["unknown_availability_excluded"]:
        limitations.append(f"{stats['unknown_availability_excluded']} costo(s) sin known_at excluidos de una ejecución retroactiva "
                           "(disponibilidad desconocida; política `strict`).")
    if stats["after_cutoff"]:
        limitations.append(f"{stats['after_cutoff']} costo(s) conocidos después del corte no se usaron.")
    if stats["assumed_valid_from"]:
        limitations.append(f"{stats['assumed_valid_from']} costo(s) sin known_at se asumieron conocidos en su fecha efectiva "
                           "(política `assume_valid_from`).")
    served = [r for r in results if r["method"] is not None]
    comparable = [r for r in served if r["metrics"] and r["metrics"][r["method"]]["holdout"]["mae"] is not None
                  and r["metrics"]["last_value"]["holdout"]["mae"] is not None]
    summary = {
        "series": len(results), "forecasted": len(served), "abstained": len(results) - len(served),
        "quality_counts": quality_counts, "availability": stats, "duration_ms": 0,
        "methods_served": {m: sum(1 for r in served if r["method"] == m) for m in {r["method"] for r in served}},
        "holdout": {
            "series_with_holdout": len(comparable),
            "served_mae_mean": float(np.mean([r["metrics"][r["method"]]["holdout"]["mae"] for r in comparable])) if comparable else None,
            "reference_mae_mean": float(np.mean([r["metrics"]["last_value"]["holdout"]["mae"] for r in comparable])) if comparable else None,
            "note": "El holdout son los últimos períodos de cada serie; no participó en la elección del método.",
        },
    }
    assumptions = [
        "Se pronostican costos unitarios por producto, proveedor, moneda y unidad; nunca se mezclan monedas ni unidades.",
        "Granularidad mensual: el costo del mes es el promedio de las observaciones disponibles al corte.",
        "Un mes sin observación conserva el último costo conocido (el costo se mantiene hasta que cambia).",
        f"Referencia simple: último valor. Otros métodos: {', '.join(m for m in cfg['methods'] if m != 'last_value')}; "
        f"solo se sirven si mejoran el MAE de selección en ≥ {cfg.get('min_improvement', 0.03):.0%}.",
        "Disponibilidad: known_at ≤ corte; known_at NULL se acepta solo en ejecuciones vigentes o si el costo viene de una compra real.",
        "Las variables externas (clima, materias primas, tasas) no se incorporan salvo como trayectorias declaradas en escenarios.",
    ]
    duration_ms = int((time.perf_counter() - t0) * 1000)
    summary["duration_ms"] = duration_ms
    uncertainty = "empirical_holdout" if any(r.get("uncertainty_source") == "empirical_holdout" for r in served) else "unknown"
    if not results:
        quality = "insufficient_data"
        limitations.append("No hay historial de costos de compra ni de ventas con disponibilidad conocida al corte.")
    elif not served:
        quality = "insufficient_data"
    elif quality_counts.get("ok", 0) == len(results):
        quality = "ok"
    else:
        quality = "degraded"
    snap = DataSnapshot("cost", as_of)
    snap.add_frame("purchase_cost_history", history, None, ["cost_id"] if "cost_id" in history else None)
    snap.add_frame("sales_cost", sales_cost, None, ["product_id", "period"] if not sales_cost.empty else None)
    snap.add_value("config", {"config": cfg, "policy": policy, "historical": retro})
    progress("persisting", 85)
    with engine.begin() as conn:
        snap_id = persist_snapshot(conn, tenant_id, snap)
        conn.execute(text("DELETE FROM cost_forecasts WHERE tenant_id = :t AND core_run_id = :r"), {"t": tenant_id, "r": run_id})
        for r in results:
            conn.execute(text(
                """
                INSERT INTO cost_forecasts (tenant_id, core_run_id, product_id, supplier_id, cost_source, currency, unit, method,
                    reference_method, quality_status, n_obs, last_observed_on, last_observed_cost, points, metrics, availability,
                    limitations, created_at)
                VALUES (:t, :r, :p, :s, :src, :cur, :u, :m, :ref, :q, :n, :lo, :lc, CAST(:pts AS jsonb), CAST(:met AS jsonb),
                        CAST(:av AS jsonb), CAST(:lim AS jsonb), now())
                """), {"t": tenant_id, "r": run_id, "p": r["product_id"], "s": r["supplier_id"], "src": r["cost_source"],
                       "cur": r["currency"], "u": r["unit"], "m": r["method"] or "none", "ref": r["reference_method"],
                       "q": r["quality_status"], "n": r["n_obs"], "lo": r["last_observed_on"], "lc": r["last_observed_cost"],
                       "pts": jdump(r["points"]), "met": jdump(r["metrics"]), "av": jdump(r["availability"]),
                       "lim": jdump(r["limitations"])})
        complete_run(conn, tenant_id, run_id, job_id, result_type="forecast", quality_status=quality,
                     uncertainty_source=uncertainty, as_of=as_of, horizon_start=as_of + timedelta(days=1),
                     horizon_end=as_of + timedelta(days=int(max(cfg.get("horizons", [30, 90])))),
                     target_definition=TARGET_DEFINITION, config_version=cfg_version,
                     effective_config={"config": cfg, "provenance": provenance, "null_known_at_policy": policy},
                     snapshot_id=snap_id, model_versions={"methods": cfg["methods"], "reference": "last_value",
                                                          "version": "cost-forecast-2026.10"},
                     assumptions=assumptions, limitations=limitations, summary=summary, duration_ms=duration_ms,
                     make_current=not retro, core="cost", operation="forecast",
                     horizons=list(cfg.get("horizons", [30, 90])))
    return {"run_id": run_id, "status": "completed", "quality_status": quality, "summary": summary}
