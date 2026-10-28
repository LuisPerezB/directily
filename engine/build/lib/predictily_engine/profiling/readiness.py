"""Evaluación de preparación de datos para pronosticar (readiness).

El puntaje (0–100) es orientativo: resume historia disponible, cobertura de series,
días sin ventas, disponibilidad de inventario y costos, y quiebres de stock. Cada
descuento del puntaje se explica en `issues`.
"""

from __future__ import annotations

from typing import Any

import pandas as pd

from predictily_engine.calendar.ecuador import rest_days
from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.series import Panel


def compute_readiness(data: TenantData, panel: Panel) -> dict[str, Any]:
    issues: list[dict[str, Any]] = []
    prof = panel.profile
    active = prof[prof["is_active"]] if not prof.empty else prof
    if data.sales.empty or prof.empty:
        return {
            "score": 0.0, "series_total": 0, "series_by_class": {}, "history_days": 0, "date_from": None,
            "date_to": None, "missing_days": 0, "stockout_days_share": 0.0, "has_inventory": False,
            "has_costs": False, "recommended_strategy": "A",
            "issues": [{"code": "no_sales", "severity": "error", "message": "No hay ventas válidas importadas.",
                        "count": 0}],
        }
    date_from = data.sales["ds"].min()
    date_to = data.sales["ds"].max()
    history_days = int((date_to - date_from).days) + 1

    # días sin ninguna venta (excluye domingos y feriados nacionales, que pueden ser cierres)
    all_days = pd.date_range(date_from, date_to, freq="D")
    hol = set()
    for y in range(date_from.year, date_to.year + 1):
        hol.update(rest_days(y).keys())
    expected = [d for d in all_days if d.dayofweek != 6 and d.date() not in hol]
    sold_days = set(data.sales.loc[data.sales["units"] > 0, "ds"].dt.normalize())
    missing = [d for d in expected if d not in sold_days]
    missing_share = len(missing) / max(len(expected), 1)

    long_share = float((active["history_days"] >= 90).mean()) if len(active) else 0.0
    inv = data.inventory
    has_inventory = not inv.empty
    recent_inventory = has_inventory and (pd.Timestamp(data.cutoff) - inv["ds"].max()).days <= 30
    stats = data.sales_stats or {}
    lines = int(stats.get("lines") or 0)
    has_costs = lines > 0 and (int(stats.get("lines_with_cost") or 0) / lines) >= 0.5
    total_days = float(active["history_days"].sum()) if len(active) else 0.0
    stockout_share = float(active["stockout_days"].sum() / total_days) if total_days else 0.0

    score = 0.0
    score += min(history_days / 365, 1.0) * 30
    score += long_share * 20
    score += (1 - min(missing_share * 3, 1.0)) * 15
    score += 15 if recent_inventory else (7 if has_inventory else 0)
    score += 10 if has_costs else 0
    score += (1 - min(stockout_share * 5, 1.0)) * 10

    if history_days < 90:
        issues.append({"code": "short_history", "severity": "warning", "count": history_days,
                       "message": f"Solo hay {history_days} días de historia; con menos de 90 días los modelos "
                                  "solo pueden capturar patrones semanales y el backtesting es limitado."})
    elif history_days < 365:
        issues.append({"code": "no_full_year", "severity": "info", "count": history_days,
                       "message": "Menos de un año de historia: la estacionalidad anual (Navidad, décimos, "
                                  "temporada escolar) se estima con poca evidencia."})
    short = int((active["history_days"] < 28).sum())
    if short:
        issues.append({"code": "short_series", "severity": "info", "count": short,
                       "message": f"{short} series con menos de 28 días usan el promedio de su historia (sin evaluación)."})
    if missing_share > 0.05:
        issues.append({"code": "missing_days", "severity": "warning", "count": len(missing),
                       "message": f"{len(missing)} días hábiles sin ninguna venta registrada: verifique si faltan datos."})
    if not has_inventory:
        issues.append({"code": "no_inventory", "severity": "warning", "count": 0,
                       "message": "Sin datos de inventario: no se pueden detectar quiebres de stock (ventas censuradas) "
                                  "ni generar recomendaciones de abastecimiento."})
    elif not recent_inventory:
        issues.append({"code": "stale_inventory", "severity": "warning", "count": 0,
                       "message": "El último inventario tiene más de 30 días: las recomendaciones pueden no ser vigentes."})
    if not has_costs:
        issues.append({"code": "no_costs", "severity": "info", "count": 0,
                       "message": "Menos de la mitad de las ventas tiene costo histórico: el margen y la inversión "
                                  "estimada pueden quedar incompletos."})
    if stockout_share > 0.05:
        issues.append({"code": "high_stockouts", "severity": "warning", "count": int(active["stockout_days"].sum()),
                       "message": f"{stockout_share:.0%} de los días-serie tuvieron quiebre de stock: las ventas "
                                  "subestiman la demanda en esos días (se excluyen de la evaluación)."})
    by_class = active["demand_class"].value_counts().to_dict() if len(active) else {}
    intermittent = by_class.get("intermittent", 0) + by_class.get("lumpy", 0)
    if len(active) and intermittent / len(active) > 0.5:
        issues.append({"code": "intermittent_majority", "severity": "info", "count": int(intermittent),
                       "message": "La mayoría de las series tiene demanda intermitente: el error diario por producto "
                                  "será alto; los totales semanales/mensuales son más confiables."})
    inactive = int((~prof["is_active"]).sum())
    if inactive:
        issues.append({"code": "inactive_series", "severity": "info", "count": inactive,
                       "message": f"{inactive} series sin ventas en 180 días o con producto inactivo no se pronostican."})
    strategy = "B" if len(active) >= 10 and history_days >= 180 else "A"
    return {
        "score": round(score, 1),
        "series_total": int(len(active)),
        "series_by_class": {k: int(v) for k, v in by_class.items()},
        "history_days": history_days,
        "date_from": date_from.date(),
        "date_to": date_to.date(),
        "missing_days": len(missing),
        "stockout_days_share": round(stockout_share, 4),
        "has_inventory": bool(has_inventory),
        "has_costs": bool(has_costs),
        "recommended_strategy": strategy,
        "issues": issues,
    }
