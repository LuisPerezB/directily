"""Perecibilidad en el plan de reposición (V2.5 · W5-D, §10): FEFO, merma proyectada con los cuantiles persistidos, tope por vida útil de las compras
nuevas, lotes as-of y, sobre todo, que un producto SIN vida útil ni lotes se planifica EXACTAMENTE como antes (sin vencimiento inventado)."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.heuristic import heuristic_plan
from predictily_engine.supply.inputs import PlanInputs, build_problem
from predictily_engine.supply.perishability import audit_plan
from predictily_engine.supply.service import solve_plan
from predictily_engine.supply.verify import evaluate

AS_OF = date(2026, 10, 26)
PRODUCTS = pd.DataFrame({"product_id": [10], "unit": ["u"], "current_price": [5.0], "current_cost": [2.0]})
COSTS = {10: {"unit_cost": 2.0, "supplier_id": 1, "source": "t"}}


def forecast(days=84, daily=10.0):
    return pd.DataFrame([{"branch_id": 1, "product_id": 10, "ds": pd.Timestamp(AS_OF) + pd.Timedelta(days=d), "yhat": daily} for d in range(1, days + 1)])


def inv(on_hand=50.0, reserved=0.0, ds=AS_OF):
    return pd.DataFrame({"branch_id": [1], "product_id": [10], "on_hand": [on_hand], "reserved": [reserved], "in_transit": [0.0], "ds": [pd.Timestamp(ds)]})


def perish(is_perishable=True, shelf=None):
    return pd.DataFrame({"product_id": [10], "is_perishable": [is_perishable], "shelf_life_days": [shelf]})


def lots(rows):
    """rows: (lote, foto, días hasta el vencimiento o None, cantidad)."""
    return pd.DataFrame([{"branch_id": 1, "product_id": 10, "lot_code": c, "snapshot_date": pd.Timestamp(ds),
                          "expiry_date": None if e is None else pd.Timestamp(AS_OF) + pd.Timedelta(days=e), "quantity": q} for c, ds, e, q in rows])


def bands(lo=None, hi=None, days=84):
    return pd.DataFrame([{"branch_id": 1, "product_id": 10, "ds": pd.Timestamp(AS_OF) + pd.Timedelta(days=d), "lo_80": lo, "hi_80": hi}
                         for d in range(1, days + 1)])


def make(**kw):
    base = dict(as_of=AS_OF, forecast=forecast(), inventory=inv(), products=PRODUCTS, cost_by_product=COSTS,
                config={"review_period_days": 7, "planning_horizon_days": 84, "default_lead_time_days": 7, "solver_mip_gap": 1e-6})
    config = kw.pop("config", None)
    if config:
        base["config"] = {**base["config"], **config}
    base.update(kw)
    return PlanInputs(**base)


def plan(inputs):
    problem, report = build_problem(inputs)
    return problem, report, solve_plan(problem, diagnose_unmet=False)


# ── sin datos de perecibilidad: conducta anterior, bit a bit ──────────────────────────────────────────────

def test_a_product_without_shelf_life_or_lots_is_planned_exactly_as_before_and_the_plan_says_so():
    p0, r0, o0 = plan(make())
    # marcado perecible pero sin vida útil ni lotes: no se inventa vencimiento
    p1, r1, o1 = plan(make(perishability=perish(True, None)))
    n0, n1 = p0.nodes[0], p1.nodes[0]
    assert n1.opening == n0.opening and n1.shelf_cap is None and np.array_equal(n1.demand, n0.demand)
    assert [(ln.t, ln.quantity, ln.offer_key) for ln in o1.solution.purchases] == [(ln.t, ln.quantity, ln.offer_key) for ln in o0.solution.purchases]
    assert o1.solution.evaluation.total_cost == o0.solution.evaluation.total_cost
    assert r1["perishability"]["by_status"] == {"perishable_unquantified": 1} and r1["perishability"]["series_with_effect"] == 0
    assert any("sin vida útil declarada ni lotes" in x for x in r1["limitations"])
    # un durable declarado no cambia nada y no escribe texto de perecibilidad
    p2, r2, _ = plan(make(perishability=perish(False, None)))
    assert p2.nodes[0].opening == n0.opening and r2["perishability"]["series_assessed"] == 0
    assert not any("ecedero" in x or "ecibilidad" in x or "FEFO" in x for x in r2["assumptions"] + r2["limitations"])
    # vida útil declarada en un producto NO perecible no se usa
    p3, _r3, _ = plan(make(perishability=perish(False, 10)))
    assert p3.nodes[0].shelf_cap is None


def test_perishability_can_be_switched_off_and_then_lots_and_shelf_life_have_no_effect():
    kw = dict(forecast=forecast(daily=2.0), inventory=inv(30.0))
    inputs = make(perishability=perish(True, 10), lots=lots([("A", AS_OF, 3, 30.0)]), **kw)
    off, _r, _o = plan(make(perishability=perish(True, 10), lots=lots([("A", AS_OF, 3, 30.0)]), config={"perishability_enabled": False}, **kw))
    legacy, _r2, _o2 = plan(make(**kw))
    assert off.nodes[0].opening == legacy.nodes[0].opening == 30.0 and off.nodes[0].shelf_cap is None
    on, _r3, _o3 = plan(inputs)
    assert on.nodes[0].opening == pytest.approx(30.0 - 24.0)       # vende 2/día durante 3 días (6 u.) y las otras 24 vencen: se descuentan


# ── lotes, vencidos al corte y merma proyectada (FEFO) ────────────────────────────────────────────────────

def test_expired_units_and_projected_fefo_loss_reduce_the_opening_stock_and_are_reported():
    # Demanda 2/día. Lote A: 20 u. con último día vendible el día 5 (vende 10 y pierde 10). Lote B: 40 u. día 100. Vencidas al corte: 7 u.
    inputs = make(forecast=forecast(daily=2.0), inventory=inv(67.0),
                  lots=lots([("OLD", AS_OF, -1, 7.0), ("A", AS_OF, 5, 20.0), ("B", AS_OF, 100, 40.0)]), perishability=perish(False, None))
    problem, report, _ = plan(inputs)
    node = problem.nodes[0]
    assert node.opening == pytest.approx(67.0 - 7.0 - 10.0)
    info = node.meta["perishability"]
    assert info["status"] == "lots" and info["expected_expiry_loss_units"] == pytest.approx(10.0) and info["first_loss_day"] == 5
    assert info["lots"]["expired_qty"] == pytest.approx(7.0)
    assert node.meta["position"]["expired_on_shelf"] == pytest.approx(7.0)
    assert report["perishability"]["expected_expiry_loss_units"] == pytest.approx(10.0) and report["perishability"]["expired_on_shelf_units"] == pytest.approx(7.0)
    assert any("ya vencidas al corte" in x for x in report["limitations"])
    assert any("FEFO" in x for x in report["assumptions"])


def test_loss_uses_the_persisted_quantiles_for_the_low_demand_scenario_and_never_recomputes_the_forecast():
    inputs = make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=lots([("A", AS_OF, 6, 30.0)]), perishability=perish(False, None),
                  forecast_bands=bands(lo=2.0, hi=6.0))
    problem, report, _ = plan(inputs)
    info = problem.nodes[0].meta["perishability"]
    assert info["expected_expiry_loss_units"] == pytest.approx(6.0)             # yhat 4/día: vende 24 de 30
    assert info["low_demand_expiry_loss_units"] == pytest.approx(18.0)          # lo_80 2/día: vende 12
    assert info["high_demand_expiry_loss_units"] == pytest.approx(0.0)          # hi_80 6/día: vende todo
    assert report["perishability"]["low_demand_expiry_loss_units"] == pytest.approx(18.0)
    # base de merma conservadora: lo_80 descuenta la merma de demanda baja de la existencia
    low_basis, _r, _ = plan(make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=lots([("A", AS_OF, 6, 30.0)]), perishability=perish(False, None),
                                 forecast_bands=bands(lo=2.0, hi=6.0), config={"perishability_loss_demand_basis": "lo_80"}))
    assert low_basis.nodes[0].opening == pytest.approx(30.0 - 18.0)
    # sin cuantiles persistidos no se calcula el escenario y se declara
    no_bands, _r2, _ = plan(make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=lots([("A", AS_OF, 6, 30.0)]), perishability=perish(False, None)))
    assert no_bands.nodes[0].meta["perishability"]["low_demand_expiry_loss_units"] is None
    assert any("lo_80" in n for n in no_bands.nodes[0].meta["perishability"]["notes"])


def test_lots_are_read_as_of_the_cutoff_and_stale_lots_are_ignored():
    future = lots([("A", date(2026, 10, 27), 6, 30.0)])                          # foto POSTERIOR al corte: no existe para este corte
    problem, report, _ = plan(make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=future, perishability=perish(False, None)))
    assert problem.nodes[0].opening == 30.0 and "perishability" not in problem.nodes[0].meta and report["perishability"]["series_assessed"] == 0
    stale = lots([("A", date(2026, 10, 1), 6, 30.0)])                            # 25 días más antigua que el snapshot de existencias
    problem, report, _ = plan(make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=stale, perishability=perish(False, None)))
    assert problem.nodes[0].opening == 30.0
    assert any("lotes ignorados por obsoletos" in x for x in report["limitations"])
    # la misma foto que no alcanzaba al corte anterior sí rige en uno posterior (lectura as-of, no estado actual)
    later = make(as_of=date(2026, 10, 27), forecast=forecast().assign(ds=lambda d: d["ds"] + pd.Timedelta(days=1)), inventory=inv(30.0, ds=date(2026, 10, 27)),
                 lots=future, perishability=perish(False, None))
    p_later, _r, _ = plan(later)
    assert "perishability" in p_later.nodes[0].meta


# ── tope por vida útil de las compras nuevas ──────────────────────────────────────────────────────────────

def _offer_lot(lot):
    return pd.DataFrame([{"offer_id": 1, "supplier_id": 1, "product_id": 10, "branch_id": None, "unit_cost": 2.0, "currency": "USD", "unit": "u",
                          "min_order_qty": None, "order_multiple": float(lot), "lead_time_days": 1, "lead_time_std_days": 0.0, "capacity_per_period": None,
                          "capacity_period_days": 7, "fixed_order_cost": 0.0, "acquisition_cost_pct": 0.0, "acquisition_cost_per_unit": 0.0,
                          "valid_from": None, "valid_to": None, "known_at": None, "is_active": True}])


def test_shelf_life_caps_period_end_stock_so_the_plan_never_buys_more_than_it_can_sell_before_expiry():
    # Demanda 10/día (70 por período) y vida útil de 10 días: lo que queda al cierre de un período solo puede venderse en 3 días = 30 u.
    base = dict(inventory=inv(0.0), offers=_offer_lot(200), config={"use_implicit_offers": False, "coverage_mode": "soft"})
    free, _r, out_free = plan(make(**base, perishability=perish(True, None)))
    capped, report, out_cap = plan(make(**base, perishability=perish(True, 10)))
    node = capped.nodes[0]
    assert node.shelf_cap is not None and np.all(node.shelf_cap[:6] == pytest.approx(30.0))
    free_inv = out_free.solution.evaluation.nodes[free.nodes[0].key].inventory
    cap_inv = out_cap.solution.evaluation.nodes[node.key].inventory
    assert free_inv.max() > 30.0                                                    # sin el tope el plan acumula stock que no vendería a tiempo
    assert np.all(cap_inv[: len(node.shelf_cap)] <= node.shelf_cap + 1e-6) and out_cap.solution.evaluation.feasible
    # lotes de 200 u. no caben (queda mucho más que 30 al cierre): no se compra, la demanda sin atender se DECLARA (no se inventa un plan que vence)
    assert not out_cap.solution.purchases and out_cap.solution.evaluation.unmet_units > 0
    assert report["perishability"]["series_with_shelf_cap"] == 1
    # la demanda sin atender se atribuye a la vida útil (no a «economic»): todo lote posible dejaría más stock del vendible a tiempo
    from predictily_engine.supply.present import present_solution

    shortfalls = present_solution(capped, out_cap.solution)["shortfalls"]
    capped_periods = int(np.isfinite(node.shelf_cap).sum())            # el último período no tiene la ventana de vida útil cubierta por el pronóstico: sin tope
    assert capped_periods == 11 and all("shelf_life" in sf["reasons"] for sf in shortfalls if sf["period"] < capped_periods)
    # sin tope (producto sin vida útil) esa misma restricción no se alega
    assert not any("shelf_life" in sf["reasons"] for sf in present_solution(free, out_free.solution)["shortfalls"])


def test_with_fine_lots_the_plan_covers_demand_and_the_independent_audit_finds_no_loss_from_new_receipts():
    inputs = make(inventory=inv(0.0), perishability=perish(True, 10), offers=_offer_lot(1), config={"use_implicit_offers": False})
    problem, report, out = plan(inputs)
    node = problem.nodes[0]
    inv_path = out.solution.evaluation.nodes[node.key].inventory
    assert np.all(inv_path <= node.shelf_cap + 1e-6) and out.solution.evaluation.feasible
    audit = audit_plan(problem, out.solution, problem.meta["perishability_nodes"], {(1, 10): 2.0})
    assert audit["series_audited"] == 1
    row = audit["series"][0]
    assert row["expected_loss_from_new_receipts_units"] == pytest.approx(0.0, abs=1e-6)
    assert audit["expected_expiry_loss_units"] == pytest.approx(0.0, abs=1e-6)
    assert "FEFO" in audit["method"]


def test_the_cap_never_forces_stock_below_what_is_already_on_hand_and_the_excess_is_reported():
    inputs = make(inventory=inv(500.0), perishability=perish(True, 10), config={"use_implicit_offers": True})
    problem, report, out = plan(inputs)
    node = problem.nodes[0]
    floor = evaluate(problem, [], []).nodes[node.key].inventory
    assert np.all(node.shelf_cap >= floor - 1e-9)                                  # el tope respeta el piso: el plan sigue siendo factible
    assert out.solution.evaluation.feasible and out.solution.evaluation.violations == []
    assert any("exceso preexistente" in n for n in node.meta["perishability"]["notes"])


def test_verifier_and_heuristic_respect_the_shelf_cap():
    inputs = make(inventory=inv(0.0), perishability=perish(True, 10), offers=_offer_lot(1), config={"use_implicit_offers": False})
    problem, _r, out = plan(inputs)
    node = problem.nodes[0]
    # el verificador independiente rechaza un plan que supera el tope
    from predictily_engine.supply.model import PurchaseLine

    offer = problem.offers[0]
    big = PurchaseLine(offer.key, offer.offer_id, offer.supplier_id, 1, 10, 0, 300, 300.0, 0, 1, offer.unit_cost, 0.0)
    ev = evaluate(problem, [big], [])
    assert any(v["type"] == "shelf_life_cap" for v in ev.violations) and not ev.feasible
    # el respaldo heurístico produce un plan factible dentro del tope
    fb = heuristic_plan(problem)
    assert fb.evaluation.feasible and np.all(fb.evaluation.nodes[node.key].inventory <= node.shelf_cap + 1e-6)


# ── HIGH-1: vida útil MENOR que el período de revisión ────────────────────────────────────────────────────────

@pytest.mark.parametrize("R,S", [(7, 3), (7, 5), (7, 6), (7, 7), (14, 3), (14, 10), (14, 13), (3, 2), (3, 3), (2, 1)])
def test_a_plan_never_buys_more_than_can_be_sold_before_expiry_whatever_the_shelf_life_versus_the_review_period(R, S):
    inputs = make(inventory=inv(0.0), perishability=perish(True, S), offers=_offer_lot(1), config={"use_implicit_offers": False, "review_period_days": R})
    problem, report, out = plan(inputs)
    node = problem.nodes[0]
    assert out.solution.evaluation.feasible and out.solution.evaluation.violations == []
    audit = audit_plan(problem, out.solution, problem.meta["perishability_nodes"], {(1, 10): 2.0})
    # la auditoría diaria FEFO, independiente del modelo por períodos, no encuentra merma esperada (ni de lo comprado ni de nada más)
    assert audit["expected_expiry_loss_units"] <= 1e-6, (R, S, audit["series"][0]["lost_lots"][:3])
    assert audit["series"][0]["expected_loss_from_new_receipts_units"] <= 1e-6
    bought = sum(p.quantity for p in out.solution.purchases)
    assert bought > 0                                                                  # sí se compra lo que se alcanza a vender
    if S < R:
        # lo disponible al inicio de cada período no supera la demanda de sus primeros S días (10 u./día)
        assert node.avail_cap is not None and np.nanmax(np.where(np.isfinite(node.avail_cap), node.avail_cap, np.nan)) <= 10.0 * S + 1e-9
        assert np.all(out.solution.evaluation.nodes[node.key].peak <= node.avail_cap + 1e-6)
        assert report["perishability_issues"] == ["shelf_life_shorter_than_period"]
        assert any("vida útil menor que el período de revisión" in x for x in report["limitations"])
        # la demanda de los días restantes de cada período se declara sin atender POR VIDA ÚTIL, no «economic»
        from predictily_engine.supply.present import present_solution

        shortfalls = present_solution(problem, out.solution)["shortfalls"]
        assert shortfalls and all("shelf_life" in sf["reasons"] for sf in shortfalls)
        covered = sum(1 for sf in shortfalls if abs(sf["unmet"] - (10.0 * R - 10.0 * S)) < 1e-6)
        assert covered >= len(shortfalls) - 2                                          # (R − S) días × 10 u. por período, salvo bordes del horizonte
    else:
        assert report["perishability_issues"] == []


def test_the_reviewers_case_shelf_life_3_with_a_weekly_review_no_longer_buys_480_units_that_expire():
    free, _r, out_free = plan(make(inventory=inv(0.0), perishability=perish(True, None), offers=_offer_lot(1), config={"use_implicit_offers": False}))
    capped, report, out_cap = plan(make(inventory=inv(0.0), perishability=perish(True, 3), offers=_offer_lot(1), config={"use_implicit_offers": False}))
    audit_free = audit_plan(free, out_free.solution, {free.nodes[0].key: __import__("predictily_engine.supply.perishability", fromlist=["x"]).NodePerishability(
        "shelf_life", True, 3, daily_yhat=np.full(84, 10.0))}, {(1, 10): 2.0})
    assert sum(p.quantity for p in out_free.solution.purchases) >= 800                  # sin tope: ≈ 70 u. por semana
    assert audit_free["expected_expiry_loss_units"] > 300                               # y con vida útil de 3 días, más de la mitad vence
    audit_cap = audit_plan(capped, out_cap.solution, capped.meta["perishability_nodes"], {(1, 10): 2.0})
    assert audit_cap["expected_expiry_loss_units"] <= 1e-6
    assert sum(p.quantity for p in out_cap.solution.purchases) == pytest.approx(30.0 * 12)       # 3 días × 10 u. por cada una de las 12 semanas


def test_hard_coverage_with_a_shelf_life_cap_diagnoses_the_shelf_life_as_the_cause():
    inputs = make(inventory=inv(0.0), perishability=perish(True, 5), offers=_offer_lot(1), config={"use_implicit_offers": False, "coverage_mode": "hard"})
    problem, _report = build_problem(inputs)
    out = solve_plan(problem, diagnose_unmet=True)
    assert out.status == "infeasible" and out.infeasibility is not None
    families = {c["family"]: c for c in out.infeasibility["causes"]}
    assert "shelf_life" in families and families["shelf_life"]["units_recovered"] > 0
    assert "vida útil" in out.infeasibility["message"]
    # el plan de máxima cobertura factible sí respeta el tope y lo declara
    assert out.alternative is not None and out.alternative.evaluation.feasible
    # en modo blando (el predeterminado) el mismo problema da un plan factible con el faltante atribuido a la vida útil
    soft, _r = build_problem(make(inventory=inv(0.0), perishability=perish(True, 5), offers=_offer_lot(1), config={"use_implicit_offers": False}))
    out_soft = solve_plan(soft, diagnose_unmet=True)
    assert out_soft.status == "optimal" and out_soft.diagnosis is not None
    assert any(c["family"] == "shelf_life" and c["units_recovered"] > 0 for c in out_soft.diagnosis["causes"])


def test_shelf_life_declared_on_a_product_not_marked_perishable_is_ignored_even_when_it_has_lots():
    lot = lots([("A", AS_OF, 40, 50.0)])
    problem, report, _ = plan(make(inventory=inv(50.0), perishability=perish(False, 10), lots=lot, offers=_offer_lot(1), config={"use_implicit_offers": False}))
    node = problem.nodes[0]
    assert node.shelf_cap is None and node.avail_cap is None                          # los lotes dan FEFO, pero una vida útil «declarada» en un no perecible no limita compras
    assert node.meta["perishability"]["status"] == "lots"


# ── demanda NETA de pedidos confirmados en la merma y el tope ────────────────────────────────────────────────

def commit(qty, due_days=3):
    return pd.DataFrame({"branch_id": [1], "product_id": [10], "commitment_id": [1], "due": [pd.Timestamp(AS_OF) + pd.Timedelta(days=due_days)], "pending": [qty]})


def test_the_expiry_loss_and_the_shelf_cap_use_the_commitments_netted_demand_not_the_raw_forecast():
    base = dict(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=lots([("A", AS_OF, 6, 30.0)]), perishability=perish(True, 10),
                offers=_offer_lot(1), config={"use_implicit_offers": False})
    raw, _r, _o = plan(make(**base))
    # 60 u. confirmadas en el período 0 (pronóstico 28 u./semana): la demanda neta de la semana es 60, no 28
    net, report, _o2 = plan(make(**base, commitments=commit(60.0)))
    assert raw.nodes[0].meta["perishability"]["expected_expiry_loss_units"] == pytest.approx(6.0)        # con el pronóstico crudo: 24 vendidas, 6 vencen
    assert net.nodes[0].meta["perishability"]["expected_expiry_loss_units"] == pytest.approx(0.0)        # con los pedidos: se vende todo antes del día 6
    assert net.nodes[0].demand[0] == pytest.approx(60.0)
    # el tope por vida útil también crece con la demanda neta de la ventana (días 8..10 del período 1 NO cambian; la semana 0 sí)
    n0 = net.nodes[0].shelf_cap
    r0 = raw.nodes[0].shelf_cap
    assert n0[0] == pytest.approx(r0[0])             # la ventana del cierre del período 0 son los días 8-10 (período 1), sin pedidos
    net1, _r3, _o3 = plan(make(**base, commitments=commit(70.0, due_days=9)))                          # pedido en el período 1
    assert net1.nodes[0].shelf_cap[0] > raw.nodes[0].shelf_cap[0]                                       # la ventana del período 0 incluye el período 1 neto
    assert net1.nodes[0].meta["perishability"]["status"] == "lots_and_shelf_life"


# ── valoración de la merma por (sucursal, producto) y solo con costo confiable ────────────────────────────────

def test_expiry_loss_is_valued_per_branch_and_only_with_a_reliable_cost():
    from predictily_engine.supply.plan_runner import _ref_cost

    fc = pd.concat([forecast(daily=2.0), forecast(daily=2.0).assign(branch_id=2)])
    iv = pd.concat([inv(30.0), inv(30.0).assign(branch_id=2)])
    lt = pd.concat([lots([("A", AS_OF, 3, 30.0)]), lots([("B", AS_OF, 3, 30.0)]).assign(branch_id=2)])
    o1 = _offer_lot(1).assign(branch_id=1, unit_cost=2.0, offer_id=1)
    o2 = _offer_lot(1).assign(branch_id=2, unit_cost=10.0, offer_id=2)
    problem, report, out = plan(make(forecast=fc, inventory=iv, lots=lt, perishability=perish(False, None), offers=pd.concat([o1, o2]), config={"use_implicit_offers": False}))
    costs = {n.key: _ref_cost(problem, n) for n in problem.nodes}
    assert costs[(1, 10)] == (2.0, "supplier_offer") and costs[(2, 10)] == (10.0, "supplier_offer")
    audit = audit_plan(problem, out.solution, problem.meta["perishability_nodes"], {k: v[0] for k, v in costs.items()}, cost_source={k: v[1] for k, v in costs.items()})
    # 24 u. vencen en cada sucursal: 24·2 + 24·10 = 288 (no 480 = 48·10 con el costo de una sola sucursal)
    assert audit["expected_expiry_loss_units"] == pytest.approx(48.0) and audit["expected_expiry_loss_value"] == pytest.approx(288.0)
    assert audit["valuation"] == {**audit["valuation"], "series_valued": 2, "series_not_valued": 0}
    assert {r["branch_id"]: r["cost_source"] for r in audit["series"]} == {1: "supplier_offer", 2: "supplier_offer"}
    # con ofertas implícitas (último costo conocido) el costo no es confiable: la serie no se valora y se informa cuántas no
    implicit, _r, out_i = plan(make(forecast=fc, inventory=iv, lots=lt, perishability=perish(False, None), cost_by_product=COSTS))
    ic = {n.key: _ref_cost(implicit, n) for n in implicit.nodes}
    assert all(v[0] is None and v[1] == "implicit_offer" for v in ic.values())
    audit_i = audit_plan(implicit, out_i.solution, implicit.meta["perishability_nodes"], {k: v[0] for k, v in ic.items()}, cost_source={k: v[1] for k, v in ic.items()})
    assert audit_i["expected_expiry_loss_value"] is None and audit_i["valuation"]["series_valued"] == 0 and audit_i["valuation"]["series_not_valued"] == 2
    assert audit_i["expected_expiry_loss_units"] == pytest.approx(48.0)                 # las unidades se informan siempre; solo el valor se omite


# ── política de conciliación de lotes ─────────────────────────────────────────────────────────────────────────

def test_lot_reconciliation_policies_are_configurable_and_declared():
    rows = lots([("OLD", AS_OF, 4, 20.0), ("NEW", AS_OF, 50, 40.0)])
    kw = dict(forecast=forecast(daily=2.0), inventory=inv(40.0), lots=rows, perishability=perish(False, None))
    cons, _r, _o = plan(make(**kw))                                          # por defecto: se descarta lo que vence DESPUÉS (merma proyectada conservadora)
    fifo, _r2, _o2 = plan(make(**kw, config={"perishability_excess_drop": "earliest_first"}))
    assert cons.nodes[0].meta["perishability"]["expected_expiry_loss_units"] == pytest.approx(12.0)       # OLD (20) vende 8 en 4 días y pierde 12
    assert fifo.nodes[0].meta["perishability"]["expected_expiry_loss_units"] == pytest.approx(0.0)        # se descartó OLD (lo vendido salió de lo primero)
    assert any("merma proyectada conservadora" in n for n in cons.nodes[0].meta["perishability"]["notes"])
    # vencidos que el ERP ya dio de baja: no se restan dos veces
    expired = lots([("EXP", AS_OF, -2, 10.0), ("OK", AS_OF, 60, 30.0)])
    deducted, _r3, _o3 = plan(make(forecast=forecast(daily=2.0), inventory=inv(30.0), lots=expired, perishability=perish(False, None)))
    written, _r4, _o4 = plan(make(forecast=forecast(daily=2.0), inventory=inv(30.0), lots=expired, perishability=perish(False, None),
                                  config={"perishability_expired_stock_mode": "written_off"}))
    assert deducted.nodes[0].opening == pytest.approx(30.0 - 10.0 + 0.0 - 0.0) or deducted.nodes[0].opening == pytest.approx(20.0 - 0.0)
    assert written.nodes[0].opening == pytest.approx(30.0)                     # on_hand 30 ya no incluye los 10 vencidos: nada que restar
    notes = " ".join(deducted.nodes[0].meta["perishability"]["notes"])
    assert "es probable que el ERP ya haya dado de baja" in notes              # el excedente de lotes (40 − 30) coincide con lo vencido (10)


def test_non_finite_values_are_rejected_in_the_effective_config():
    from predictily_engine.platform.config import validate_overrides

    for bad in (float("nan"), float("inf"), float("-inf")):
        errors = validate_overrides("supply", {"perishability_receipt_life_fraction": bad})
        assert errors and errors[0]["code"] == "invalid_number", bad
    assert validate_overrides("supply", {"perishability_receipt_life_fraction": 0.5}) == []
    assert validate_overrides("supply", {"perishability_receipt_life_fraction": 1.5})[0]["code"] == "out_of_range"
    assert validate_overrides("supply", {"perishability_excess_drop": "otro"})[0]["code"] == "invalid_choice"
    assert validate_overrides("supply", {"perishability_expired_stock_mode": "written_off", "perishability_excess_drop": "earliest_first"}) == []


@pytest.mark.parametrize("S,lot", [(10, 200), (3, 50), (5, 100)])
def test_the_heuristic_fallback_alone_never_returns_a_plan_that_violates_the_shelf_caps(S, lot):
    # sin la comprobación `fits_shelf` el respaldo compraría el lote entero (200 > 30, o 50 > 30) y el verificador lo rechazaría
    problem, _r = build_problem(make(inventory=inv(0.0), perishability=perish(True, S), offers=_offer_lot(lot), config={"use_implicit_offers": False}))
    fb = heuristic_plan(problem)
    node = problem.nodes[0]
    assert fb.evaluation.feasible, fb.evaluation.violations[:2]
    assert all(p.arrival_period == problem.n_periods - 1 or p.quantity <= 10.0 * S + 1e-9 for p in fb.purchases)     # salvo el último período, que no tiene tope (ventana sin pronóstico)
    res = fb.evaluation.nodes[node.key]
    assert np.all(res.inventory <= node.shelf_cap + 1e-6) and (node.avail_cap is None or np.all(res.peak <= node.avail_cap + 1e-6))


def test_the_verifier_rejects_a_plan_that_exceeds_the_availability_cap_when_the_shelf_life_is_shorter_than_the_period():
    from predictily_engine.supply.model import PurchaseLine

    problem, _r = build_problem(make(inventory=inv(0.0), perishability=perish(True, 3), offers=_offer_lot(1), config={"use_implicit_offers": False}))
    offer = problem.offers[0]
    big = PurchaseLine(offer.key, offer.offer_id, offer.supplier_id, 1, 10, 0, 100, 100.0, 0, 1, offer.unit_cost, 0.0)      # 100 u. disponibles al inicio; solo se venden 30 en 3 días
    ev = evaluate(problem, [big], [])
    assert not ev.feasible
    v = [x for x in ev.violations if x["type"] == "shelf_life_cap" and "available" in x]
    assert v and v[0]["period"] == 0 and v[0]["available"] == pytest.approx(100.0) and v[0]["limit"] == pytest.approx(30.0)
    ok = PurchaseLine(offer.key, offer.offer_id, offer.supplier_id, 1, 10, 0, 30, 30.0, 0, 1, offer.unit_cost, 0.0)
    assert evaluate(problem, [ok], []).feasible


def test_the_availability_cap_never_forces_stock_below_what_is_already_on_hand():
    # 500 u. en estante con vida útil de 3 días y revisión semanal: el tope crudo (30) quedaría por debajo de lo ya disponible; se respeta el piso (sin compras nuevas)
    problem, report, out = plan(make(inventory=inv(500.0), perishability=perish(True, 3), offers=_offer_lot(1), config={"use_implicit_offers": False}))
    node = problem.nodes[0]
    assert node.avail_cap[0] == pytest.approx(500.0) and out.status != "infeasible" and out.solution.evaluation.feasible
    assert not out.solution.purchases or out.solution.purchases[0].t > 0


def test_hard_coverage_diagnosis_relaxes_the_carry_over_cap_too_when_the_shelf_life_is_longer_than_the_period():
    # S = 10 ≥ R = 7: solo rige el tope de arrastre (30 u.); con un pedido mínimo de 250 u. y cobertura obligatoria el modelo es infactible por la vida útil
    problem, _r = build_problem(make(inventory=inv(0.0), perishability=perish(True, 10), offers=_offer_lot(1), config={"use_implicit_offers": False, "coverage_mode": "hard"}))
    for o in problem.offers:
        o.moq = 250.0
    out = solve_plan(problem, diagnose_unmet=True)
    assert out.status == "infeasible" and problem.nodes[0].avail_cap is None
    fam = {c["family"]: c for c in out.infeasibility["causes"]}
    assert "shelf_life" in fam and fam["shelf_life"]["units_recovered"] > 0


def test_audit_reports_losses_of_existing_lots_in_the_expected_and_the_low_demand_scenario():
    inputs = make(forecast=forecast(daily=4.0), inventory=inv(30.0), lots=lots([("A", AS_OF, 6, 30.0)]), perishability=perish(False, None),
                  forecast_bands=bands(lo=2.0, hi=6.0))
    problem, report, out = plan(inputs)
    audit = audit_plan(problem, out.solution, problem.meta["perishability_nodes"], {(1, 10): 2.0})
    # la auditoría parte de los lotes completos (no de la apertura ya descontada): merma esperada 6 u. = 12 de valor; demanda baja 18 u. = 36
    assert audit["expected_expiry_loss_units"] == pytest.approx(6.0) and audit["expected_expiry_loss_value"] == pytest.approx(12.0)
    assert audit["low_demand_expiry_loss_units"] == pytest.approx(18.0) and audit["low_demand_expiry_loss_value"] == pytest.approx(36.0)
    assert audit["series"][0]["lost_lots"][0]["day"] == 6
