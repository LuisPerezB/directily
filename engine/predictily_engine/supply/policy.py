"""Política de reposición explícita y escenario «sin compra» (V2.5 · W5-D, §10; docs/supply/SUPPLY_CORE.md §3).

- **Política**: stock de seguridad (SS) y punto de reorden (ROP) por serie, tal como los calculó y persistió la etapa 1 (`recommendations`):
  se EXPONEN con su fórmula, nivel de servicio, plazo, variabilidad del plazo y método de incertidumbre. No se recalculan aquí (una sola fuente).
- **Escenario «sin compra»**: el plan evaluado SIN ninguna compra nueva, con las mismas existencias y recepciones abiertas: qué se pierde si
  no se ordena (unidades no atendidas, fecha de primer faltante, margen perdido). Es el contrafactual que respalda cada compra del plan.
"""

from __future__ import annotations

from typing import Any

import pandas as pd
from scipy.stats import norm

from predictily_engine.supply.model import EPS, PlanProblem
from predictily_engine.supply.verify import evaluate

POLICY_FORMULAS = {
    "normal_z_sigma": "SS = z(NS) · σ_L  (σ_L = desviación de la demanda en L = plazo + revisión, desde los intervalos del pronóstico); ROP = demanda en L + SS",
    "empirical_quantile": "SS = cuantil NS de los errores de pronóstico acumulados en L (backtest); ROP = demanda en L + SS",
    "fraction_of_coverage_demand": "SS = fracción provisional × demanda en L (incertidumbre desconocida: política provisional explícita); ROP = demanda en L + SS",
}
LEAD_VARIABILITY = "Con variabilidad del plazo σ_plazo: SS = √(SS² + (z(NS) · d̄ · σ_plazo)²), con d̄ la demanda diaria media de la cobertura"


def policy_rows(policy: pd.DataFrame | None, planned: set[tuple[int, int]] | None = None, top: int = 200) -> list[dict[str, Any]]:
    """Política de reposición por serie (SS, ROP, nivel de servicio, plazo y su variabilidad) con la fórmula aplicada."""
    if policy is None or policy.empty:
        return []
    out = []
    for r in policy.itertuples(index=False):
        key = (int(r.branch_id), int(r.product_id))
        if planned is not None and key not in planned:
            continue
        sl = None if pd.isna(r.service_level) else float(r.service_level)
        ss_policy = r.safety_stock_policy if isinstance(r.safety_stock_policy, str) else None
        formula = POLICY_FORMULAS.get(ss_policy or "", "Sin política de stock de seguridad (cobertura del pronóstico insuficiente en la etapa 1)")
        lt_std = None if pd.isna(r.lead_time_std_days) else float(r.lead_time_std_days)
        if lt_std:
            formula += ". " + LEAD_VARIABILITY
        out.append({
            "branch_id": key[0], "product_id": key[1], "service_level": sl, "z": None if sl is None else round(float(norm.ppf(sl)), 4),
            "lead_time_days": None if pd.isna(r.lead_time_days) else int(r.lead_time_days), "lead_time_source": r.lead_time_source,
            "lead_time_std_days": lt_std, "review_period_days": None if pd.isna(r.review_period_days) else int(r.review_period_days),
            "demand_in_lead_time": None if pd.isna(r.demand_lead_time) else float(r.demand_lead_time),
            "demand_in_lead_time_std": None if pd.isna(r.demand_lead_time_std) else float(r.demand_lead_time_std),
            "safety_stock": None if pd.isna(r.safety_stock) else float(r.safety_stock),
            "reorder_point": None if pd.isna(r.reorder_point) else float(r.reorder_point),
            "safety_stock_policy": ss_policy, "uncertainty": r.uncertainty, "formula": formula})
    out.sort(key=lambda x: (x["branch_id"], x["product_id"]))
    return out[:top]


def no_purchase_baseline(problem: PlanProblem, ref_cost: dict[tuple[int, int], float | None] | None = None, top: int = 100) -> dict[str, Any]:
    """Evalúa el plan SIN compras ni producción nuevas (mismas existencias y recepciones abiertas). Margen perdido = unidades no atendidas ×
    max(precio − costo, 0) solo cuando precio y costo son conocidos; si no, `lost_margin` queda nulo para la serie (no se rellena con 0)."""
    ev = evaluate(problem, [], [])
    rows = []
    lost_total = 0.0
    lost_known = 0
    nodes = problem.node_index()
    for key, res in ev.nodes.items():
        node = nodes[key]
        unmet = float(res.unmet.sum())
        first = next((int(t) for t in range(node.horizon) if res.unmet[t] > 1e-9), None)
        price = node.price
        cost = (ref_cost or {}).get(key)
        margin = None if price is None or cost is None else max(float(price) - float(cost), 0.0)
        lost = None if margin is None else unmet * margin
        if lost is not None:
            lost_total += lost
            lost_known += 1
        rows.append({"branch_id": key[0], "product_id": key[1], "demand": round(float(node.demand.sum()), 4), "unmet_units": round(unmet, 4),
                     "first_unmet_period": first,
                     "first_unmet_date": None if first is None else (problem.period_start(first)).isoformat(),
                     "lost_margin": None if lost is None else round(lost, 4)})
    affected = [r for r in rows if r["unmet_units"] > EPS]
    affected.sort(key=lambda r: (-r["unmet_units"], r["branch_id"], r["product_id"]))
    unmet_total = float(sum(r["unmet_units"] for r in rows))
    demand_total = float(sum(r["demand"] for r in rows))
    return {"description": "Plan evaluado sin ninguna compra nueva (mismas existencias y recepciones abiertas): lo que ocurre si no se ordena.",
            "margin_rule": "el margen perdido se valora solo con precio conocido y el costo de una oferta explícita de la serie; con costo implícito o desconocido queda nulo",
            "series": len(rows), "series_with_shortfall": len(affected), "unmet_units": round(unmet_total, 4), "demand_units": round(demand_total, 4),
            "fill_rate": round(1 - unmet_total / demand_total, 4) if demand_total > EPS else None,
            "lost_margin": round(lost_total, 4), "lost_margin_series_known": lost_known, "lost_margin_series_unknown": len(rows) - lost_known,
            "series": affected[:top]}


__all__ = ["no_purchase_baseline", "policy_rows"]
