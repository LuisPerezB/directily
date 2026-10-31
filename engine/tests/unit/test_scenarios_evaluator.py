"""E2-15 · Evaluador de escenarios: ventas atendibles = min(demanda, disponible), margen solo sobre lo vendido,
estrategias sobre la misma base y entradas clasificadas observada / pronosticada / supuesta."""

from __future__ import annotations

from datetime import date, timedelta

import numpy as np
import pytest

from predictily_engine.scenarios.evaluator import ASSUMED, FORECASTED, OBSERVED, ScenarioData, evaluate_scenarios

AS_OF = date(2026, 10, 26)


def data(opening=50.0, demand=(30.0, 30.0, 30.0), receipts=(0, 0, 0), price=10.0, cost=6.0, plans=None, unit="u", extra_series=None):
    n = len(demand)
    d = ScenarioData(as_of=AS_OF, currency="USD", period_days=7, n_periods=n, demand={(1, 10): np.array(demand, float)},
                     opening={(1, 10): opening}, receipts={(1, 10): np.array(receipts, float)}, price={10: price}, cost={10: cost},
                     unit={10: unit}, plans=plans or {}, meta={"forecast_run_id": 5})
    if extra_series:
        d.demand[(1, 11)] = np.array(demand, float)
        d.opening[(1, 11)] = opening
        d.receipts[(1, 11)] = np.zeros(n)
        d.price[11], d.cost[11], d.unit[11] = price, cost, extra_series
    return d


def only(result, key="s1"):
    return next(s for s in result["strategies"] if s["key"] == key)


def test_attainable_sales_are_limited_by_supply_and_margin_uses_only_what_is_sold():
    # Existencias 50, demanda 30 por período: ventas 30, 20 y 0 = 50; faltan 40. Margen = 50 · (10 − 6) = 200 (no 90 · 4 = 360).
    r = evaluate_scenarios(data(), [{"key": "s1"}])
    m = only(r)["metrics"]
    assert m["demand_units"] == 90 and m["attainable_sales_units"] == 50 and m["unmet_units"] == 40
    assert m["revenue"] == 500 and m["gross_margin"] == 200 and m["ending_inventory_units"] == 0
    assert all(only(r)["coherence"].values())


def test_price_change_does_not_change_units_but_demand_assumption_does_and_supply_still_binds():
    r = evaluate_scenarios(data(), [{"key": "s1"}, {"key": "s2", "price_change_pct": 0.10},
                                    {"key": "s3", "demand_change_pct": 0.20}, {"key": "s4", "cost_change_pct": 0.5}])
    s1, s2, s3, s4 = (only(r, k)["metrics"] for k in ("s1", "s2", "s3", "s4"))
    assert s2["attainable_sales_units"] == s1["attainable_sales_units"]  # el precio no mueve la demanda por sí solo
    assert s2["revenue"] == pytest.approx(550.0) and s2["gross_margin"] == pytest.approx(250.0)
    assert s3["attainable_sales_units"] == 50 and s3["unmet_units"] == pytest.approx(58.0)  # +20 % de demanda: más faltante, no más ventas
    assert s4["gross_margin"] == pytest.approx(500 - 50 * 9.0)  # costo 6 · 1,5 = 9
    assert only(r, "s2")["delta_vs_first"]["gross_margin"] == pytest.approx(50.0)


def test_extra_supply_and_plan_receipts_enter_through_their_dates():
    plans = {7: [{"branch_id": 1, "product_id": 10, "quantity": 60.0, "expected_receipt_date": AS_OF + timedelta(days=8), "unit_cost": 6.0}]}
    r = evaluate_scenarios(data(plans=plans), [{"key": "s1"}, {"key": "s2", "plan_run_id": 7}, {"key": "s3", "plan_run_id": 7, "supply_fill_rate": 0.5},
                                              {"key": "s4", "plan_run_id": 7, "lead_time_delta_days": 14},
                                              {"key": "s5", "extra_supply": [{"branch_id": 1, "product_id": 10, "period": 2, "units": 25}]}])
    # recepción el día 8 → disponible desde el período 1: ventas 30 / 30 / 30 + 20 de stock = 90 (todo atendido) con 60 u.
    assert only(r, "s2")["metrics"]["attainable_sales_units"] == 90 and only(r, "s2")["metrics"]["ending_inventory_units"] == 20
    assert only(r, "s3")["metrics"]["attainable_sales_units"] == 80  # solo llega la mitad
    assert only(r, "s4")["metrics"]["attainable_sales_units"] == 50  # un retraso de 14 d la lleva al día 22 (período 3): fuera del horizonte
    assert only(r, "s5")["metrics"]["attainable_sales_units"] == 75
    assert only(r, "s2")["metrics"]["plan_purchase_outlay"] == pytest.approx(360.0)


def test_inputs_are_classified_and_assumptions_are_listed_per_strategy():
    r = evaluate_scenarios(data(plans={7: []}), [{"key": "s1"}, {"key": "s2", "price_change_pct": 0.1, "demand_change_pct": -0.1, "plan_run_id": 7}])
    cls1 = {i["input"]: i["classification"] for i in only(r, "s1")["inputs"]}
    assert cls1["demand_base"] == FORECASTED and cls1["opening_inventory"] == OBSERVED and cls1["price_base"] == OBSERVED
    assert ASSUMED not in cls1.values()
    cls2 = {i["input"]: i["classification"] for i in only(r, "s2")["inputs"]}
    assert cls2["price_change"] == ASSUMED and cls2["demand_change"] == ASSUMED and cls2["plan_receipts"] == ASSUMED
    assert r["assumed_inputs"] == ["demand_change", "plan_receipts", "price_change"]
    assert "NO se deriva del cambio de precio" in r["disclaimer"]


def test_all_strategies_share_the_same_basis_and_ranking_uses_attainable_margin():
    r = evaluate_scenarios(data(), [{"key": "base"}, {"key": "caro", "price_change_pct": 0.3}, {"key": "barato", "price_change_pct": -0.5}])
    assert r["common_basis"]["horizon_days"] == 21 and r["common_basis"]["series"] == 1
    assert r["ranking_by_gross_margin"][0] == "caro" and r["ranking_by_gross_margin"][-1] == "barato"
    assert only(r, "barato")["metrics"]["gross_margin"] < 0


def test_unknown_cost_keeps_margin_unknown_and_units_are_not_added_across_units():
    r = evaluate_scenarios(data(cost=None), [{"key": "s1"}])
    m = only(r)["metrics"]
    assert m["gross_margin"] is None and m["revenue"] == 500 and m["revenue_without_known_cost"] == 500
    assert only(r)["products_without_cost"] == [10]
    r = evaluate_scenarios(data(extra_series="kg"), [{"key": "s1"}])
    m = only(r)["metrics"]
    assert m["demand_units"] is None and "unidades distintas" in m["units_note"] and m["revenue"] == 1000


def test_inventory_never_negative_and_balance_identity_in_random_cases():
    rng = np.random.default_rng(1)
    for _ in range(30):
        n = int(rng.integers(2, 8))
        d = data(opening=float(rng.integers(0, 100)), demand=tuple(rng.integers(0, 60, n).astype(float)),
                 receipts=tuple(rng.integers(0, 40, n).astype(float)))
        r = evaluate_scenarios(d, [{"key": "s1", "demand_change_pct": float(rng.uniform(-0.5, 1.0))}])
        assert all(only(r)["coherence"].values())
