"""Simulación histórica sobre los datos DEMO sintéticos (generador con semilla; la demanda latente es conocida).

Uso (desde engine/):
    python -m predictily_engine.supply.simulation_demo --company ferreteria_andina --days 120 --out ../docs/architecture/v2/evidence/p4-simulation-ferreteria.json
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import pandas as pd

from predictily_engine.supply.simulation import (
    CurrentPolicy,
    PlannerPolicy,
    ReferencePolicy,
    SimInputs,
    compare_policies,
)
from predictily_engine.synthetic import generate_company


def inputs_from_company(company, days: int = 120, review_days: int = 7, seed: int = 7, max_series: int | None = None) -> SimInputs:
    td = company.true_demand.copy()
    td["ds"] = pd.to_datetime(td["ds"])
    dates = pd.date_range(td["ds"].min(), td["ds"].max(), freq="D")
    key_df = td.groupby(["branch_code", "product_sku"]).size().reset_index()[["branch_code", "product_sku"]]
    prod = company.products.set_index("sku")
    branches = {c: i + 1 for i, c in enumerate(sorted(company.branches["code"]))}
    skus = {s: i + 1 for i, s in enumerate(sorted(company.products["sku"]))}
    keys, cols = [], []
    for r in key_df.itertuples(index=False):
        keys.append((branches[r.branch_code], skus[r.product_sku]))
        cols.append((r.branch_code, r.product_sku))
    if max_series:
        keys, cols = keys[:max_series], cols[:max_series]
    D, N = len(dates), len(keys)
    pos = {c: i for i, c in enumerate(cols)}
    demand = np.zeros((D, N))
    sold = np.zeros((D, N))
    onh = np.zeros((D, N))
    di = {d: i for i, d in enumerate(dates)}
    sel = td[[c in pos for c in zip(td["branch_code"], td["product_sku"])]]
    rows = np.array([di[d] for d in sel["ds"]])
    cols_idx = np.array([pos[c] for c in zip(sel["branch_code"], sel["product_sku"])])
    demand[rows, cols_idx] = sel["demand"].to_numpy()
    sold[rows, cols_idx] = sel["sold"].to_numpy()
    onh[rows, cols_idx] = sel["on_hand"].to_numpy()
    start = D - days
    unit_cost = np.array([float(prod.loc[c[1], "current_cost"]) for c in cols])
    price = np.array([float(prod.loc[c[1], "current_price"]) for c in cols])
    lt = company.lead_times
    lead, lead_std = np.zeros(N, dtype=int), np.zeros(N)
    for i, c in enumerate(cols):
        sub = lt[(lt["product_sku"] == c[1]) & ((lt["branch_code"] == c[0]) | (lt["branch_code"].fillna("") == ""))]
        sub = sub.sort_values("branch_code", ascending=False)  # la fila específica de sucursal gana
        row = sub.iloc[0] if len(sub) else None
        lead[i] = int(row["lead_time_days"]) if row is not None else 7
        lead_std[i] = float(row["lead_time_std_days"]) if row is not None else 1.0
    # existencias reales al inicio de la ventana (cierre del día anterior)
    init = onh[start - 1] if start > 0 else onh[0]
    return SimInputs(dates=dates, demand=demand, sold_hist=sold, start=start, n_days=days, init_inv=init, keys=keys,
                     unit_cost=unit_cost, price=price, lead=lead, lead_std=lead_std, review_days=review_days, seed=seed)


def run(company_slug: str, days: int, seed: int, max_series: int | None, with_budget: bool, budget_fraction: float = 0.85) -> dict:
    company = generate_company(company_slug, seed=42)
    inp = inputs_from_company(company, days=days, seed=seed, max_series=max_series)
    policies = [CurrentPolicy(), ReferencePolicy(), PlannerPolicy()]
    out = compare_policies(inp, policies)
    if with_budget:
        base_spend = out["policies"]["current"]["spend_per_review_mean"]
        name = f"planner_budget_{int(round(budget_fraction * 100))}pct_of_current"
        extra = compare_policies(inp, [PlannerPolicy(budget_per_review=budget_fraction * base_spend, name=name)])
        out["policies"].update(extra["policies"])
        out["budget_note"] = (f"{name}: tope de {budget_fraction * base_spend:,.0f} por revisión ({budget_fraction:.0%} del gasto medio de `current`). "
                              "El gasto de `current` es irregular; un tope sobre su media recorta las revisiones grandes.")
    out["company"] = {"slug": company_slug, "data": "FICTICIOS (generador sintético con semilla 42)"}
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--company", default="ferreteria_andina")
    ap.add_argument("--days", type=int, default=120)
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--max-series", type=int, default=None)
    ap.add_argument("--budget", action="store_true")
    ap.add_argument("--budget-fraction", type=float, default=0.85)
    ap.add_argument("--out", default=None)
    a = ap.parse_args()
    res = run(a.company, a.days, a.seed, a.max_series, a.budget, a.budget_fraction)
    text = json.dumps(res, ensure_ascii=False, indent=2, default=str)
    if a.out:
        Path(a.out).parent.mkdir(parents=True, exist_ok=True)
        Path(a.out).write_text(text)
    for name, r in res["policies"].items():
        print(name, {k: r[k] for k in ("fill_rate", "unmet_units", "avg_inventory_value", "days_of_inventory", "holding_cost",
                                        "lost_margin", "operating_cost_proxy", "avg_capital_committed", "purchase_outlay", "runtime_seconds")})


if __name__ == "__main__":
    main()
