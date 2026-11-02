"""Posición de inventario en el plan (V2.5 · W5-D, §10): validez del stock físico (sobrerreserva, negativo, obsoleto), compromisos conciliados con
el pronóstico SIN doble conteo, ajustes posteriores al snapshot como componente separado, política de reposición explícita y escenario sin compra."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.inputs import PlanInputs, build_problem
from predictily_engine.supply.planner import prepare_problem
from predictily_engine.supply.policy import no_purchase_baseline, policy_rows
from predictily_engine.supply.position import (
    adjustments_after_snapshot,
    committed_by_period,
    committed_lines_by_series,
    reconcile_demand,
    stock_validity,
)
from predictily_engine.supply.service import solve_plan

AS_OF = date(2026, 10, 26)
PRODUCTS = pd.DataFrame({"product_id": [10], "unit": ["u"], "current_price": [5.0], "current_cost": [2.0]})
COSTS = {10: {"unit_cost": 2.0, "supplier_id": 1, "source": "t"}}


def forecast(days=84, daily=10.0):
    return pd.DataFrame([{"branch_id": 1, "product_id": 10, "ds": pd.Timestamp(AS_OF) + pd.Timedelta(days=d), "yhat": daily} for d in range(1, days + 1)])


def inv(on_hand=100.0, reserved=0.0, ds=AS_OF):
    return pd.DataFrame({"branch_id": [1], "product_id": [10], "on_hand": [on_hand], "reserved": [reserved], "in_transit": [0.0], "ds": [pd.Timestamp(ds)]})


def commit(qty, due_days=3, branch=1, cid=1):
    return pd.DataFrame({"branch_id": [branch], "product_id": [10], "commitment_id": [cid], "due": [pd.Timestamp(AS_OF) + pd.Timedelta(days=due_days)], "pending": [qty]})


def make(**kw):
    config = kw.pop("config", {})
    base = dict(as_of=AS_OF, forecast=forecast(), inventory=inv(), products=PRODUCTS, cost_by_product=COSTS,
                config={"review_period_days": 7, "planning_horizon_days": 84, "default_lead_time_days": 7, **config})
    base.update(kw)
    return PlanInputs(**base)


# ── validez del stock físico (SUP-002/032) ────────────────────────────────────────────────────────────────

def test_over_reserved_stock_is_declared_with_the_clipped_quantity_and_degrades_quality():
    problem, report = build_problem(make(inventory=inv(on_hand=40.0, reserved=90.0)))
    node = problem.nodes[0]
    assert node.opening == 0.0                                                 # nunca negativo...
    val = node.meta["position"]["validity"]
    assert val["over_reserved"] and val["over_reserved_qty"] == pytest.approx(50.0)     # ...pero la sobrerreserva (50 u.) NO desaparece
    assert report["stock_validity_issues"] == ["over_reserved"]
    assert any("Sobrerreserva" in x and "50" in x for x in report["limitations"])
    assert report["availability"]["validity"]["over_reserved_qty"] == pytest.approx(50.0)
    from predictily_engine.supply.plan_runner import _quality

    # La única causa de degradación es la validez del stock: se fija una incertidumbre conocida para aislarla (con «none» el plan ya sería degraded)
    known = {"uncertainty_source": "empirical_holdout"}
    assert _quality("optimal", {**report, **known}, 1, 0, "optimal") == "degraded"
    clean, rep_clean = build_problem(make(inventory=inv(on_hand=100.0, reserved=90.0)))
    assert not rep_clean["stock_validity_issues"] and _quality("optimal", {**rep_clean, **known}, 1, 0, "optimal") == "ok"
    for issue in ("over_reserved", "negative_physical", "snapshot_stale"):
        assert _quality("optimal", {**rep_clean, **known, "stock_validity_issues": [issue]}, 1, 0, "optimal") == "degraded", issue
    # una vida útil menor que el período de revisión también degrada la calidad (la demanda de los días restantes no puede cubrirse)
    assert _quality("optimal", {**rep_clean, **known, "perishability_issues": ["shelf_life_shorter_than_period"]}, 1, 0, "optimal") == "degraded"


def test_negative_on_hand_and_stale_snapshot_are_declared_not_accepted_silently():
    problem, report = build_problem(make(inventory=inv(on_hand=-4.0)))
    assert problem.nodes[0].opening == 0.0 and report["stock_validity_issues"] == ["negative_physical"]
    assert any("negativa" in x for x in report["limitations"])
    problem, report = build_problem(make(inventory=inv(on_hand=50.0, ds=date(2026, 9, 1))))        # snapshot de 55 días
    v = problem.nodes[0].meta["position"]["validity"]
    assert v["snapshot_stale"] and v["snapshot_age_days"] == 55 and report["stock_validity_issues"] == ["snapshot_stale"]
    assert any("Snapshot de existencias obsoleto" in x for x in report["limitations"])
    assert not stock_validity(5.0, 1.0, date(2026, 10, 20), AS_OF, 30)["snapshot_stale"]


# ── compromisos conciliados con el pronóstico, sin doble conteo (SUP-003/025/037) ─────────────────────────

def test_confirmed_orders_are_inside_the_forecast_never_added_on_top():
    rec = reconcile_demand(np.array([70.0, 70.0]), np.array([40.0, 0.0]))
    assert rec["net"].tolist() == [70.0, 70.0] and rec["committed"].tolist() == [40.0, 0.0] and rec["residual"].tolist() == [30.0, 70.0]
    assert rec["double_count_avoided"] == pytest.approx(40.0)
    assert rec["net"].sum() != pytest.approx(70.0 * 2 + 40.0)                  # nunca 110 por el primer período
    above = reconcile_demand(np.array([20.0]), np.array([50.0]))
    assert above["net"].tolist() == [50.0] and above["committed_above_forecast"].tolist() == [30.0]


def test_balance_reports_committed_and_residual_and_reserved_stock_is_not_discounted_twice():
    # 100 en estante, 40 reservados para un pedido confirmado de 40 u. (entrega en el período 0) dentro de un pronóstico de 70 u. por período.
    inputs = make(inventory=inv(on_hand=100.0, reserved=40.0), commitments=commit(40.0))
    problem, report = build_problem(inputs)
    node = problem.nodes[0]
    assert node.demand[0] == pytest.approx(70.0)                                # comprometido 40 + residual probabilístico 30 = 70, no 110
    pos = node.meta["position"]
    assert pos["committed"][0] == pytest.approx(40.0) and pos["residual_probabilistic"][0] == pytest.approx(30.0)
    assert node.opening == pytest.approx(100.0)                                 # el reservado que respalda el pedido ya sale como demanda
    assert pos["reserved"] == 40.0 and pos["reserved_held_back"] == 0.0
    commits = report["availability"]["commitments"]
    assert commits["series"] == 1 and commits["committed_qty"] == pytest.approx(40.0) and commits["double_count_avoided_qty"] == pytest.approx(40.0)
    assert any("max(pronóstico, comprometido)" in x for x in report["assumptions"])
    # sin pedidos cargados (conducta anterior): todo lo reservado se descuenta y se declara el posible solapamiento con el pronóstico
    legacy, rep_legacy = build_problem(make(inventory=inv(on_hand=100.0, reserved=40.0)))
    assert legacy.nodes[0].opening == pytest.approx(60.0)
    # el solapamiento posible con el pronóstico se declara como UNA suposición del plan (no una limitación por cada serie ni por cada plan)
    assert sum("Reservado sin pedidos confirmados" in x for x in rep_legacy["assumptions"]) == 1
    assert not any("Reservado sin pedidos" in x or "posible doble conteo" in x for x in rep_legacy["limitations"])
    # `ignore` conserva la conducta anterior aun con pedidos cargados
    ignored, _ = build_problem(make(inventory=inv(on_hand=100.0, reserved=40.0), commitments=commit(40.0), config={"commitments_mode": "ignore"}))
    assert ignored.nodes[0].opening == pytest.approx(60.0) and ignored.nodes[0].demand[0] == pytest.approx(70.0)


def test_committed_above_the_forecast_is_planned_as_firm_demand_and_unbacked_reserved_stays_discounted():
    problem, report = build_problem(make(inventory=inv(on_hand=100.0, reserved=60.0), commitments=commit(100.0, due_days=2)))
    node = problem.nodes[0]
    assert node.demand[0] == pytest.approx(100.0)                               # 100 comprometidas > pronóstico 70: demanda firme
    assert node.opening == pytest.approx(100.0)                                 # reservado 60 ≤ comprometido: respaldado, no se descuenta dos veces
    assert any("superan el pronóstico" in x for x in report["limitations"])
    problem, _ = build_problem(make(inventory=inv(on_hand=100.0, reserved=60.0), commitments=commit(40.0)))
    assert problem.nodes[0].opening == pytest.approx(100.0 - (60.0 - 40.0))     # 20 u. reservadas SIN pedido que las respalde siguen descontadas


def test_commitments_fall_in_the_period_that_contains_their_due_day_at_every_boundary():
    # período 0 = días 1..7; período 1 = días 8..14; período 2 = días 15..21 (el día 1 es el siguiente al corte)
    for due_days, expected in ((1, 0), (7, 0), (8, 1), (14, 1), (15, 2), (21, 2), (22, 3)):
        got = committed_by_period(commit(10.0, due_days=due_days), 1, 10, AS_OF, 7, 4)
        assert got.argmax() == expected and got.sum() == 10.0, (due_days, got.tolist())
    assert committed_by_period(commit(10.0, due_days=29), 1, 10, AS_OF, 7, 4).sum() == 0.0      # fuera del horizonte (4 períodos = 28 días)
    assert committed_by_period(commit(10.0, due_days=0), 1, 10, AS_OF, 7, 4).sum() == 0.0       # vencida (hoy o antes): no es demanda futura
    # y el balance del plan lo refleja: un pedido del día 7 reduce el residual del período 0, el del día 8 el del período 1
    p7, _ = build_problem(make(commitments=commit(30.0, due_days=7)))
    p8, _ = build_problem(make(commitments=commit(30.0, due_days=8)))
    assert p7.nodes[0].meta["position"]["committed"][:2] == [30.0, 0.0] and p8.nodes[0].meta["position"]["committed"][:2] == [0.0, 30.0]


def test_an_adjustment_dated_on_the_snapshot_day_is_already_in_on_hand_and_is_not_applied_twice():
    adj = pd.DataFrame({"branch_id": [1, 1], "product_id": [10, 10],
                        "occurred_on": [pd.Timestamp(AS_OF) - pd.Timedelta(days=2), pd.Timestamp(AS_OF) - pd.Timedelta(days=1)],
                        "quantity": [-7.0, -4.0], "reference": ["el mismo día del snapshot", "al día siguiente"]})
    snap = (pd.Timestamp(AS_OF) - pd.Timedelta(days=2)).date()
    qty, detail = adjustments_after_snapshot(adj, 1, 10, snap, AS_OF)
    assert qty == -4.0 and [d["reason"] for d in detail] == ["al día siguiente"]      # el del día del snapshot ya está en `on_hand`
    assert adjustments_after_snapshot(adj, 1, 10, AS_OF, AS_OF) == (0.0, [])          # snapshot de hoy: ningún ajuste posterior
    assert adjustments_after_snapshot(adj, 1, 10, None, AS_OF) == (0.0, [])           # sin fecha de snapshot no se aplica nada (no se adivina)


def test_commitments_are_attributed_by_branch_and_period_and_unattributed_ones_are_reported():
    lines = pd.concat([commit(40.0, due_days=3), commit(25.0, due_days=9, cid=2), commit(5.0, due_days=3, branch=2, cid=3)], ignore_index=True)
    by_period = committed_by_period(lines, 1, 10, AS_OF, 7, 4)
    assert by_period.tolist() == [40.0, 25.0, 0.0, 0.0]                         # día 3 → período 0; día 9 → período 1; otra sucursal no cuenta
    assert committed_by_period(None, 1, 10, AS_OF, 7, 4).sum() == 0.0
    revisions = pd.DataFrame([{"commitment_id": 1, "revision": 1, "status": "confirmed", "known_local": pd.Timestamp("2026-10-01"), "recorded_local": pd.Timestamp("2026-10-01"),
                               "cancelled_local": pd.NaT, "commitment_type": "order", "customer_id": None, "branch_id": None, "currency": "USD",
                               "snapshot": {"header": {"expected_delivery_date": "2026-10-30"},
                                            "lines": [{"product_sku": "P-10", "quantity": 30.0, "quantity_fulfilled": 0.0, "unit": "u", "delivery_date": "2026-10-30"}]}}])
    out, summary = committed_lines_by_series(revisions, {"P-10": 10}, {10: "u"}, AS_OF, 84)
    assert out.empty and summary["unattributed_quantity"] == pytest.approx(30.0) and summary["unattributed_lines"] == 1       # sin sucursal: no se descuenta de ninguna serie
    revisions.loc[0, "branch_id"] = 1
    out, summary = committed_lines_by_series(revisions, {"P-10": 10}, {10: "u"}, AS_OF, 84)
    assert len(out) == 1 and out["branch_id"].iloc[0] == 1 and summary["committed_quantity"] == pytest.approx(30.0)
    # una revisión conocida DESPUÉS del corte no existe para ese corte
    late = revisions.assign(known_local=pd.Timestamp("2026-11-02"), recorded_local=pd.Timestamp("2026-11-02"))
    assert committed_lines_by_series(late, {"P-10": 10}, {10: "u"}, AS_OF, 84)[0].empty


# ── ajustes de inventario como componente separado (SUP-005) ──────────────────────────────────────────────

def test_adjustments_after_the_snapshot_are_a_separate_component_and_do_not_touch_the_forecast():
    adj = pd.DataFrame({"branch_id": [1, 1, 1], "product_id": [10, 10, 10],
                        "occurred_on": [pd.Timestamp(AS_OF) - pd.Timedelta(days=3), pd.Timestamp(AS_OF) - pd.Timedelta(days=1), pd.Timestamp(AS_OF) + pd.Timedelta(days=1)],
                        "quantity": [-9.0, -5.0, -2.0], "reference": ["conteo anterior", "merma por rotura", "posterior al corte"]})
    snap = pd.Timestamp(AS_OF) - pd.Timedelta(days=2)
    assert adjustments_after_snapshot(adj, 1, 10, snap.date(), AS_OF)[0] == -5.0       # el de hace 3 días ya está en el snapshot; el posterior al corte no existe
    base, _ = build_problem(make(inventory=inv(50.0, ds=snap.date())))
    problem, report = build_problem(make(inventory=inv(50.0, ds=snap.date()), adjustments=adj))
    node = problem.nodes[0]
    assert node.opening == pytest.approx(45.0) and np.array_equal(node.demand, base.nodes[0].demand)    # −5 u. ajustan la existencia, no la demanda
    pos = node.meta["position"]
    assert pos["on_hand"] == 50.0 and pos["adjustments_after_snapshot"] == -5.0 and pos["adjustments"][0]["reason"] == "merma por rotura"
    assert report["availability"]["adjustments"] == {"series": 1, "quantity": -5.0}
    ignored, _ = build_problem(make(inventory=inv(50.0, ds=snap.date()), adjustments=adj, config={"adjustments_mode": "ignore"}))
    assert ignored.nodes[0].opening == 50.0


# ── política de reposición y escenario sin compra (SUP-007/023) ───────────────────────────────────────────

def test_no_purchase_scenario_reports_what_is_lost_if_nothing_is_ordered():
    problem, _ = build_problem(make(inventory=inv(on_hand=100.0)))
    prepare_problem(problem)
    base = no_purchase_baseline(problem, {(1, 10): 2.0})
    # 100 u. cubren 10 días de 70 u./semana: se pierden 70·12 − 100 = 740 u.; margen = (5 − 2) · 740
    assert base["unmet_units"] == pytest.approx(70.0 * 12 - 100.0) and base["series_with_shortfall"] == 1
    assert base["lost_margin"] == pytest.approx(3.0 * (70.0 * 12 - 100.0)) and base["series"][0]["first_unmet_period"] == 1
    assert base["series"][0]["first_unmet_date"] == "2026-11-03"
    out = solve_plan(problem, diagnose_unmet=False)
    assert out.solution.evaluation.unmet_units < base["unmet_units"]          # comprar mejora el no atendido: el contrafactual respalda el plan
    unknown = no_purchase_baseline(problem, {(1, 10): None})
    assert unknown["lost_margin"] == 0.0 and unknown["lost_margin_series_unknown"] == 1 and unknown["series"][0]["lost_margin"] is None


def test_no_purchase_scenario_values_each_branch_with_its_own_cost():
    fc = pd.concat([forecast(daily=2.0), forecast(daily=2.0).assign(branch_id=2)])
    iv = pd.concat([inv(10.0), inv(10.0).assign(branch_id=2)])
    problem, _ = build_problem(make(forecast=fc, inventory=iv))
    prepare_problem(problem)
    base = no_purchase_baseline(problem, {(1, 10): 2.0, (2, 10): 4.0})
    unmet = 2.0 * 84 - 10.0                                                      # 84 días de 2 u. con 10 en el estante
    by_branch = {r["branch_id"]: r["lost_margin"] for r in base["series"]}
    assert by_branch[1] == pytest.approx(unmet * (5.0 - 2.0)) and by_branch[2] == pytest.approx(unmet * (5.0 - 4.0))
    assert base["lost_margin"] == pytest.approx(unmet * 3.0 + unmet * 1.0) and base["lost_margin_series_known"] == 2


def test_replenishment_policy_is_exposed_with_its_formula_service_level_and_lead_time_variability():
    pol = pd.DataFrame([{"branch_id": 1, "product_id": 10, "service_level": 0.95, "lead_time_days": 7, "lead_time_source": "default", "lead_time_std_days": 2.0,
                         "review_period_days": 7, "demand_lead_time": 140.0, "demand_lead_time_std": 30.0, "safety_stock": 55.0, "reorder_point": 195.0,
                         "safety_stock_policy": "normal_z_sigma", "uncertainty": "intervals"}])
    rows = policy_rows(pol)
    r = rows[0]
    assert r["z"] == pytest.approx(1.6449, abs=1e-3) and r["safety_stock"] == 55.0 and r["reorder_point"] == 195.0
    assert "SS = z(NS)" in r["formula"] and "σ_plazo" in r["formula"] and r["lead_time_std_days"] == 2.0
    assert policy_rows(None) == [] and policy_rows(pol, planned=set()) == []


# ── ningún plan devuelto viola restricciones (SUP-031 · D17) ─────────────────────────────────────────────────

def test_a_plan_that_neither_solver_nor_fallback_can_make_feasible_returns_no_lines(monkeypatch):
    import predictily_engine.supply.service as service
    from predictily_engine.supply.model import PlanSolution, PurchaseLine
    from predictily_engine.supply.verify import evaluate

    problem, _ = build_problem(make(inventory=inv(0.0), config={"use_implicit_offers": True}))
    offer = problem.offers[0]
    bad = PurchaseLine(offer.key, offer.offer_id, offer.supplier_id, 1, 10, 0, 100000, 100000.0, 0, 1, offer.unit_cost, 0.0)
    bad_eval = evaluate(problem, [], [])
    bad_eval.violations.append({"type": "budget", "spent": 1.0, "limit": 0.0})      # el verificador independiente rechaza el plan

    def fake(status):
        return lambda *a, **k: PlanSolution(status=status, purchases=[bad], evaluation=bad_eval, detail="falla simulada")

    monkeypatch.setattr(service, "build_and_solve", fake("optimal"))
    monkeypatch.setattr(service, "heuristic_plan", fake("heuristic_fallback"))
    out = service.solve_plan(problem, diagnose_unmet=False)
    assert out.status == "not_solved" and out.solution.purchases == [] and out.solution.evaluation is None
    assert any("no se devuelve ninguna línea" in n for n in out.notes)
