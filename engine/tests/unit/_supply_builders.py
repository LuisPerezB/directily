"""Constructores mínimos de problemas de planificación para las pruebas (sin base de datos)."""

from __future__ import annotations

from datetime import date

import numpy as np

from predictily_engine.supply.model import (
    BudgetSpec,
    CapacitySpec,
    NodeSpec,
    OfferSpec,
    PlanProblem,
    RecipeSpec,
    ResourceSpec,
)

AS_OF = date(2026, 10, 26)


def node(product=10, branch=1, opening=0.0, demand=(70.0,) * 4, known=None, penalty=4.0, holding=0.0, ss=0.0,
         space=None, derived=False) -> NodeSpec:
    d = np.array(demand, dtype=float)
    return NodeSpec(branch, product, "u", opening, d, np.zeros(len(d)) if known is None else np.array(known, dtype=float),
                    penalty, holding, safety_stock=ss, space=space, derived=derived)


def offer(key="o1", product=10, cost=2.0, lead=1, lot=1.0, moq=0.0, fixed=0.0, capacity=None, supplier=1,
          valid_from=None, valid_to=None) -> OfferSpec:
    return OfferSpec(key=key, offer_id=int(key[1:]) if key[1:].isdigit() else None, supplier_id=supplier,
                     product_id=product, branch_id=None, unit_cost=cost, base_unit_cost=cost, lot=lot, moq=moq,
                     fixed_cost=fixed, capacity=capacity, default_lead=lead, valid_from=valid_from, valid_to=valid_to)


def problem(nodes, offers, budgets=(), capacities=(), recipes=(), resources=(), period=7, periods=None,
            mode="soft", objective="min_cost", gap=1e-9, ss_weight=0.9, time_limit=30.0) -> PlanProblem:
    n = periods or max(x.horizon for x in nodes)
    return PlanProblem(as_of=AS_OF, period_days=period, n_periods=n, currency="USD", nodes=list(nodes),
                       offers=list(offers), recipes=list(recipes), resources=list(resources), budgets=list(budgets),
                       capacities=list(capacities), objective=objective, coverage_mode=mode, mip_gap=gap,
                       ss_weight=ss_weight, time_limit=time_limit)


def budget(amount, start_offset=0, end_offset=0, branch=None, bid=1) -> BudgetSpec:
    from datetime import timedelta

    return BudgetSpec(bid, branch, AS_OF + timedelta(days=start_offset), AS_OF + timedelta(days=end_offset), amount)


__all__ = ["AS_OF", "node", "offer", "problem", "budget", "CapacitySpec", "RecipeSpec", "ResourceSpec", "BudgetSpec"]
