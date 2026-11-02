"""W5-D · comparación antes/después de los planes de abastecimiento de productos NO perecibles (datos ficticios, sin BD).

No es una prueba (pytest no lo recoge): es la herramienta con que se verificó que el plan de un no perecible no cambió (docs/supply/SUPPLY_CORE.md §9).
Uso: PYTHONPATH=<raíz del paquete a evaluar> python supply_before_after.py <salida.json> [--no-sim]
Con PYTHONPATH apuntando a la copia de d54076e (scratchpad/w5d_base/engine) se obtiene la línea base; con la del árbol de trabajo,
el resultado actual. Las dos salidas deben ser idénticas con la configuración por omisión.
"""
from __future__ import annotations

import json
import sys

import numpy as np
import pandas as pd

import predictily_engine
from predictily_engine.supply.inputs import PlanInputs, build_problem
from predictily_engine.supply.present import present_solution, summarize
from predictily_engine.supply.service import solve_plan
from predictily_engine.supply.simulation import weekday_forecast
from predictily_engine.synthetic import generate_company


def build(company_slug: str, n_products: int, with_budget: bool, with_capacity: bool):
    c = generate_company(company_slug, seed=42, n_products=n_products)
    td = c.true_demand.copy()
    td["ds"] = pd.to_datetime(td["ds"])
    branches = {code: i + 1 for i, code in enumerate(sorted(c.branches["code"]))}
    skus = {s: i + 1 for i, s in enumerate(sorted(c.products["sku"]))}
    prod = c.products.set_index("sku")
    last = td["ds"].max()
    fc_rows, inv_rows = [], []
    for (b, s), g in td.groupby(["branch_code", "product_sku"]):
        g = g.sort_values("ds")
        hist = g["sold"].to_numpy(dtype=float)[:, None]
        yhat, _sig = weekday_forecast(hist, last, 90)
        for d in range(90):
            fc_rows.append({"branch_id": branches[b], "product_id": skus[s], "ds": last + pd.Timedelta(days=d + 1), "yhat": float(yhat[d, 0])})
        inv_rows.append({"branch_id": branches[b], "product_id": skus[s], "on_hand": float(g["on_hand"].iloc[-1]), "reserved": 0.0, "in_transit": 0.0})
    products = pd.DataFrame([{"product_id": skus[s], "unit": "u", "current_price": float(prod.loc[s, "current_price"]), "current_cost": float(prod.loc[s, "current_cost"])}
                             for s in sorted(skus, key=skus.get)])
    lt = c.lead_times
    lts = []
    for r in lt.itertuples(index=False):
        if r.product_sku in skus:
            lts.append({"supplier_id": np.nan, "product_id": skus[r.product_sku], "branch_id": branches[r.branch_code] if isinstance(r.branch_code, str) and r.branch_code in branches else np.nan,
                        "lead_time_days": int(r.lead_time_days), "lead_time_std_days": float(r.lead_time_std_days)})
    costs = {skus[s]: {"unit_cost": float(prod.loc[s, "current_cost"]), "supplier_id": None, "source": "catalogo"} for s in skus}
    budgets = pd.DataFrame([{"budget_id": 1, "branch_id": None, "period_start": last.date(), "period_end": last.date(), "amount": 1500.0, "currency": "USD"}]) if with_budget else pd.DataFrame()
    warehouses = pd.DataFrame()
    caps = pd.DataFrame()
    if with_capacity:
        caps = pd.DataFrame([{"branch_id": b, "warehouse_id": None, "capacity": 3000.0, "capacity_unit": "u", "valid_from": None, "valid_to": None} for b in branches.values()])
    return PlanInputs(
        as_of=last.date(), currency="USD", forecast=pd.DataFrame(fc_rows), inventory=pd.DataFrame(inv_rows), products=products,
        lead_times=pd.DataFrame(lts), cost_by_product=costs, budgets=budgets, capacities=caps,
        config={"review_period_days": 7, "planning_horizon_days": 56, "default_lead_time_days": 7, "solver_mip_gap": 1e-4,
                "solver_time_limit_seconds": 90})


def normalise(problem, out, report):
    sol = out.solution
    presented = present_solution(problem, sol, {}, {}) if sol.evaluation is not None else {"lines": [], "shortfalls": [], "constraints": [], "balance": []}
    summ = summarize(problem, sol) if sol.evaluation is not None else {}
    summ = {k: (round(v, 6) if isinstance(v, float) else v) for k, v in summ.items() if k not in ("solve_seconds", "mip_gap", "dual_bound", "objective_value", "n_vars", "n_constraints", "n_integer")}
    lines = [{k: v for k, v in ln.items() if k not in ("justification",)} for ln in presented["lines"]]
    nodes = [{"key": list(n.key), "opening": round(n.opening, 6), "demand": [round(float(x), 6) for x in n.demand], "known": [round(float(x), 6) for x in n.known_receipts],
              "ss": round(n.safety_stock, 6), "penalty": round(n.penalty, 6), "holding": round(n.holding, 8), "space": n.space} for n in problem.nodes]
    return {"status": out.status, "summary": summ, "lines": lines, "shortfalls": presented["shortfalls"], "nodes": nodes,
            "excluded": report["excluded_nodes"], "assumptions": report["assumptions"]}


def main() -> None:
    out_path = sys.argv[1]
    result = {"package": predictily_engine.__file__, "plans": {}}
    for name, slug, n, bud, cap in [("andina_base", "ferreteria_andina", 3, False, False), ("andina_presupuesto", "ferreteria_andina", 3, True, False),
                                    ("costa_capacidad", "distribuidora_costa", 3, False, True)]:
        inp = build(slug, n, bud, cap)
        problem, report = build_problem(inp)
        out = solve_plan(problem, diagnose_unmet=False)
        result["plans"][name] = normalise(problem, out, report)
    if "--no-sim" not in sys.argv:
        from predictily_engine.supply.simulation_demo import run

        sim = run("ferreteria_andina", days=42, seed=7, max_series=4, with_budget=False)
        keep = ("fill_rate", "unmet_units", "avg_inventory_value", "days_of_inventory", "holding_cost", "lost_margin", "operating_cost_proxy",
                "avg_capital_committed", "peak_capital_committed", "purchase_outlay", "orders", "ordered_units", "demand_units", "sold_units",
                "overstock_unit_days", "stockout_day_share")
        result["simulation"] = {name: {k: r[k] for k in keep} for name, r in sim["policies"].items()}
    json.dump(result, open(out_path, "w"), indent=1, sort_keys=True, default=str)
    print("ok", out_path, {k: len(v["lines"]) for k, v in result["plans"].items()})


if __name__ == "__main__":
    main()
