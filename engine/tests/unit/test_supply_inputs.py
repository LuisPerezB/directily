"""Construcción del problema de planificación: exclusiones explícitas (lo desconocido no se asume), supuestos de
penalización, stock de seguridad, presupuestos con tasa de cambio y producción genérica `not_configured`/configurada."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.inputs import PlanInputError, PlanInputs, build_problem
from predictily_engine.supply.service import solve_plan

AS_OF = date(2026, 10, 26)


def forecast(days=84, daily=10.0, products=(10,), branch=1):
    rows = []
    for p in products:
        rows += [{"branch_id": branch, "product_id": p, "ds": pd.Timestamp(AS_OF) + pd.Timedelta(days=d), "yhat": daily} for d in range(1, days + 1)]
    return pd.DataFrame(rows)


def inv(products=(10,), on_hand=50.0, branch=1):
    return pd.DataFrame({"branch_id": branch, "product_id": list(products), "on_hand": on_hand, "reserved": 0.0, "in_transit": 0.0})


PRODUCTS = pd.DataFrame({"product_id": [10, 11], "unit": ["u", "u"], "current_price": [5.0, None], "current_cost": [2.0, None]})
COSTS = {10: {"unit_cost": 2.0, "supplier_id": 1, "source": "t"}}


def make(**kw):
    base = dict(as_of=AS_OF, forecast=forecast(), inventory=inv(), products=PRODUCTS, cost_by_product=COSTS,
                config={"review_period_days": 7, "planning_horizon_days": 84, "default_lead_time_days": 7})
    base.update(kw)
    return PlanInputs(**base)


def reasons(report):
    return {(e["branch_id"], e["product_id"]): e["reason"] for e in report["excluded_nodes"]}


def test_unknown_inventory_is_excluded_not_assumed_zero():
    problem, rep = build_problem(make(inventory=pd.DataFrame(columns=["branch_id", "product_id", "on_hand", "reserved", "in_transit"])))
    assert problem.nodes == [] and reasons(rep) == {(1, 10): "no_inventory"}


def test_forecast_shorter_than_lead_time_plus_review_is_not_extrapolated():
    problem, rep = build_problem(make(forecast=forecast(days=10)))   # plazo 7 + revisión 7 = 14 días necesarios
    assert problem.nodes == [] and reasons(rep) == {(1, 10): "coverage_insufficient"}
    assert "no se extrapola" in rep["excluded_nodes"][0]["detail"]


def test_product_without_offer_or_known_cost_cannot_be_planned():
    problem, rep = build_problem(make(forecast=forecast(products=(10, 11)), inventory=inv((10, 11))))
    assert [n.product_id for n in problem.nodes] == [10] and reasons(rep) == {(1, 11): "no_offer"}
    assert any(e["reasons"] == ["no_cost_known"] for e in rep["offers"] if e.get("product_id") == 11)
    problem, rep = build_problem(make(config={"use_implicit_offers": False, "review_period_days": 7, "planning_horizon_days": 84}))
    assert reasons(rep) == {(1, 10): "no_offer"}


def test_capacity_in_another_unit_requires_an_explicit_conversion():
    caps = pd.DataFrame({"branch_id": [1], "capacity": [100.0], "capacity_unit": ["pallet"], "valid_from": [None], "valid_to": [None]})
    problem, rep = build_problem(make(capacities=caps))
    assert reasons(rep) == {(1, 10): "no_capacity_conversion"}
    conv = pd.DataFrame({"product_id": [10], "from_unit": ["u"], "to_unit": ["pallet"], "factor": [0.01], "valid_from": [None]})
    problem, rep = build_problem(make(capacities=caps, conversions=conv))
    assert problem.nodes[0].space == pytest.approx(0.01) and problem.capacities[0].capacity == 100.0


def test_penalty_assumption_sources_and_priorities():
    problem, rep = build_problem(make(options={"priorities": {"10": 3.0}}))
    n = problem.nodes[0]
    assert n.meta["penalty_source"] == "multiplier" and n.penalty == pytest.approx(2.0 * 2.0 * 3.0)   # k · costo · prioridad
    problem, _ = build_problem(make(options={"shortage_penalties": {"10": 9.0}}))
    assert problem.nodes[0].penalty == pytest.approx(9.0) and problem.nodes[0].meta["penalty_source"] == "explicit"
    problem, _ = build_problem(make(options={"objective": "max_margin"}))
    assert problem.nodes[0].penalty == pytest.approx(5.0) and problem.nodes[0].meta["penalty_source"] == "price"   # precio de venta
    with pytest.raises(PlanInputError):
        build_problem(make(options={"shortage_penalty_multiplier": 1.0}))


def test_safety_stock_and_uncertainty_source_follow_the_stage1_estimate():
    ss = pd.DataFrame({"branch_id": [1], "product_id": [10], "safety_stock": [25.0], "uncertainty": ["empirical"]})
    problem, rep = build_problem(make(safety_stock=ss))
    assert problem.nodes[0].safety_stock == 25.0 and rep["uncertainty_source"] == "empirical_holdout"
    problem, rep = build_problem(make(safety_stock=ss.assign(uncertainty="unknown")))
    assert rep["uncertainty_source"] == "unknown"
    problem, rep = build_problem(make())
    assert problem.nodes[0].safety_stock == 0.0 and rep["uncertainty_source"] == "none"
    assert any("determinista" in x for x in rep["limitations"])


def test_budget_in_another_currency_needs_an_explicit_rate():
    bud = pd.DataFrame({"budget_id": [1], "branch_id": [None], "period_start": [AS_OF], "period_end": [AS_OF], "amount": [100.0], "currency": ["EUR"]})
    with pytest.raises(PlanInputError, match="tasa de cambio"):
        build_problem(make(budgets=bud))
    fx = pd.DataFrame({"from_currency": ["EUR"], "to_currency": ["USD"], "rate": [1.1], "valid_on": [date(2026, 10, 1)], "known_at": [None], "source": ["m"]})
    problem, _ = build_problem(make(budgets=bud, fx_rates=fx))
    assert problem.budgets[0].amount == pytest.approx(110.0)


def test_end_to_end_plan_from_inputs_with_budget_never_exceeds_it():
    bud = pd.DataFrame({"budget_id": [1], "branch_id": [None], "period_start": [AS_OF], "period_end": [AS_OF], "amount": [150.0], "currency": ["USD"]})
    problem, rep = build_problem(make(budgets=bud, forecast=forecast(daily=20.0), inventory=inv(on_hand=0.0)))
    out = solve_plan(problem)
    assert out.solution.evaluation.feasible
    today = sum(ln.cost for ln in out.solution.purchases if ln.t == 0)
    assert today <= 150.0 + 1e-6
    assert out.solution.evaluation.unmet_units > 0 and out.diagnosis is not None   # el presupuesto limita y se atribuye
    assert out.diagnosis["causes"][0]["family"] == "budget"


def test_production_is_not_configured_without_recipes_and_replenishment_still_works():
    problem, rep = build_problem(make())
    assert rep["production"]["status"] == "not_configured" and problem.recipes == []
    assert solve_plan(problem).solution.evaluation.feasible


def test_multilevel_recipes_are_skipped_with_a_note_and_inputs_become_derived_nodes():
    products = pd.DataFrame({"product_id": [10, 20, 30, 40], "unit": ["u"] * 4, "current_price": [5.0] * 4, "current_cost": [2.0] * 4})
    recipes = pd.DataFrame([
        {"recipe_id": 1, "code": "R1", "output_product_id": 10, "branch_id": None, "output_quantity": 10.0, "yield_rate": 0.9, "production_days": 7,
         "resource_id": None, "resource_usage_per_batch": 0.0, "cost_per_batch": 1.0, "max_batches_per_period": None, "is_active": True},
        {"recipe_id": 2, "code": "R2", "output_product_id": 20, "branch_id": None, "output_quantity": 5.0, "yield_rate": 1.0, "production_days": 7,
         "resource_id": None, "resource_usage_per_batch": 0.0, "cost_per_batch": 1.0, "max_batches_per_period": None, "is_active": True}])
    lines = pd.DataFrame([{"recipe_id": 1, "input_product_id": 20, "quantity_per_batch": 2.0},     # insumo = salida de R2 → varios niveles
                          {"recipe_id": 2, "input_product_id": 30, "quantity_per_batch": 1.0}])
    costs = {p: {"unit_cost": 2.0, "supplier_id": 1, "source": "t"} for p in (10, 20, 30, 40)}
    problem, rep = build_problem(make(products=products, recipes=recipes, recipe_lines=lines, cost_by_product=costs,
                                      forecast=forecast(products=(10, 20)), inventory=inv((10, 20, 30))))
    assert [r.code for r in problem.recipes] == ["R2"]
    assert any("varios niveles" in x for x in rep["limitations"])
    derived = {n.product_id for n in problem.nodes if n.derived}
    assert derived == {30} and rep["production"]["status"] == "configured"
    assert problem.recipes[0].output_per_batch == 5.0
