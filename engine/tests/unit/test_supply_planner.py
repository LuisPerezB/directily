"""E2-13 · Planificador: casos pequeños de solución conocida calculada a mano, ninguna violación de
presupuesto, capacidad, mínimos o balance, e infactibilidad explicada. Comparación con un oráculo de
enumeración independiente del modelo MILP."""

from __future__ import annotations

import itertools
import math

import numpy as np
import pytest
from _supply_builders import AS_OF, budget, node, offer, problem

from predictily_engine.supply.heuristic import heuristic_plan
from predictily_engine.supply.model import CapacitySpec, RecipeSpec, ResourceSpec
from predictily_engine.supply.planner import build_and_solve
from predictily_engine.supply.service import solve_plan
from predictily_engine.supply.verify import evaluate


def qty_by_t(sol, key=None):
    out = {}
    for p in sol.purchases:
        if key is None or p.offer_key == key:
            out[p.t] = out.get(p.t, 0.0) + p.quantity
    return out


# ───────────────────────────── a mano ─────────────────────────────

def test_just_in_time_with_late_first_receipt_keeps_the_early_shortage():
    # Existencias 10, demanda 70 por período de 7 días, proveedor a 7 días (llega al período siguiente).
    # Período 0: solo se puede vender lo que hay (10) → 60 sin atender. Períodos 1..5: comprar 70 el período anterior.
    p = problem([node(opening=10.0, demand=[70.0] * 6)], [offer(cost=2.0, lead=7)])
    sol = build_and_solve(p)
    assert sol.status == "optimal"
    assert qty_by_t(sol) == {0: 70.0, 1: 70.0, 2: 70.0, 3: 70.0, 4: 70.0}
    ev = sol.evaluation
    assert ev.nodes[(1, 10)].unmet.tolist() == [60.0, 0, 0, 0, 0, 0]
    assert ev.purchase_cost == pytest.approx(700.0)
    assert ev.shortage_penalty == pytest.approx(240.0)
    assert sol.objective_value == pytest.approx(940.0)
    assert ev.feasible


def test_minimum_order_blocks_small_purchases_by_hand():
    # Demanda total 40 (10 por período × 4), compra 1 $/u, mínimo de compra 100 u.
    # Penalización 2 $/u: dejar 40 u. sin atender cuesta 80 < 100 (mínimo) → no se compra nada.
    p = problem([node(demand=[10.0] * 4, penalty=2.0)], [offer(cost=1.0, lead=1, moq=100.0)])
    sol = build_and_solve(p)
    assert sol.status == "optimal" and sol.purchases == []
    assert sol.objective_value == pytest.approx(80.0)
    # Penalización 3 $/u: dejar 120 $ sin atender > 100 $ → se compra exactamente el mínimo (100 u.) en t=0.
    p = problem([node(demand=[10.0] * 4, penalty=3.0)], [offer(cost=1.0, lead=1, moq=100.0)])
    sol = build_and_solve(p)
    assert qty_by_t(sol) == {0: 100.0}
    assert sol.evaluation.unmet_units == 0
    assert sol.objective_value == pytest.approx(100.0)
    assert all(l.quantity >= 100.0 for l in sol.purchases)


def test_lot_multiples_are_respected():
    p = problem([node(demand=[70.0] * 3, penalty=4.0)], [offer(cost=2.0, lead=1, lot=50.0)])
    sol = build_and_solve(p)
    assert sol.status == "optimal"
    assert all(abs(l.quantity / 50.0 - round(l.quantity / 50.0)) < 1e-9 for l in sol.purchases)
    assert sol.evaluation.feasible


def test_budget_per_issue_period_is_never_exceeded():
    # 70 u. por período, costo 2: un presupuesto de 100 $ por período de emisión alcanza para 50 u.
    nodes = [node(demand=[70.0] * 3, penalty=4.0)]
    buds = [budget(100.0, 0, 0, bid=1), budget(100.0, 7, 7, bid=2), budget(100.0, 14, 14, bid=3)]
    sol = build_and_solve(problem(nodes, [offer(cost=2.0, lead=1)], budgets=buds))
    assert sol.status == "optimal"
    assert qty_by_t(sol) == {0: 50.0, 1: 50.0, 2: 50.0}
    assert sol.evaluation.unmet_units == pytest.approx(60.0)  # 20 por período
    spent = [a for a in sol.evaluation.activity if a["family"] == "budget"]
    assert all(a["used"] <= a["limit"] + 1e-9 for a in spent)
    assert all(a["binding"] for a in spent)


def test_supplier_capacity_limits_each_period():
    p = problem([node(demand=[70.0] * 3, penalty=4.0)], [offer(cost=2.0, lead=1, capacity=40.0)])
    sol = build_and_solve(p)
    assert qty_by_t(sol) == {0: 40.0, 1: 40.0, 2: 40.0}
    assert sol.evaluation.unmet_units == pytest.approx(90.0)


def test_storage_capacity_blocks_a_purchase_that_does_not_fit():
    # Mínimo de compra 100 u., capacidad de almacén 50 u. (1 espacio por u.): comprar 100 excedería la capacidad.
    n = node(demand=[10.0] * 3, penalty=10.0, space=1.0)
    p = problem([n], [offer(cost=1.0, lead=1, moq=100.0)], capacities=[CapacitySpec(1, 50.0, "u")])
    sol = build_and_solve(p)
    assert sol.status == "optimal"
    assert sol.purchases == []
    assert sol.evaluation.unmet_units == pytest.approx(30.0)


def test_storage_peak_includes_stock_before_sales():
    # Capacidad 100; demanda 40/período; comprar 80 llega a un almacén con 30 → pico 110 > 100: no puede.
    n = node(opening=30.0, demand=[40.0] * 3, penalty=10.0, space=1.0)
    p = problem([n], [offer(cost=1.0, lead=1, lot=80.0)], capacities=[CapacitySpec(1, 100.0, "u")])
    sol = build_and_solve(p)
    peaks = [a for a in sol.evaluation.activity if a["family"] == "storage_capacity"]
    assert all(a["used"] <= a["limit"] + 1e-6 for a in peaks)
    assert sol.evaluation.feasible


def test_cheaper_late_offer_does_not_hide_the_early_shortage():
    # Oferta A: 3 $/u, llega en el mismo período. Oferta B: 2 $/u, plazo de 21 días (disponible desde el período 3).
    # Los períodos 0-2 solo pueden abastecerse con A; los 3-5 conviene B. Costo = 3·70·3 + 2·70·3 = 1050, sin faltantes.
    p = problem([node(demand=[70.0] * 6, penalty=6.0)],
                [offer("o1", cost=3.0, lead=1), offer("o2", cost=2.0, lead=21, supplier=2)])
    sol = build_and_solve(p)
    assert sol.status == "optimal"
    a, b = qty_by_t(sol, "o1"), qty_by_t(sol, "o2")
    assert a == {0: 70.0, 1: 70.0, 2: 70.0}
    assert b == {0: 70.0, 1: 70.0, 2: 70.0}  # emitidas en 0-2, llegan en 3-5
    assert sol.evaluation.unmet_units == 0
    assert sol.objective_value == pytest.approx(1050.0)


def test_late_offer_chosen_over_expensive_fast_one_shows_unmet_and_reason():
    # A cuesta 10 (> penalización 6): no conviene. Solo B (2 $/u, llega en el período 3). Faltante 210 u. visible.
    pr = problem([node(demand=[70.0] * 6, penalty=6.0)],
                 [offer("o1", cost=10.0, lead=1), offer("o2", cost=2.0, lead=21, supplier=2)])
    outcome = solve_plan(pr)
    ev = outcome.solution.evaluation
    assert ev.nodes[(1, 10)].unmet.tolist() == [70.0, 70.0, 70.0, 0, 0, 0]
    assert {l.offer_key for l in outcome.solution.purchases} == {"o2"}
    from predictily_engine.supply.present import present_solution

    out = present_solution(pr, outcome.solution)
    reasons = {s["period"]: s["reason"] for s in out["shortfalls"]}
    assert reasons == {0: "economic", 1: "economic", 2: "economic"}
    assert all(l["justification"] for l in out["lines"])


def test_only_slow_offer_reports_lead_time_as_the_cause():
    pr = problem([node(demand=[70.0] * 6, penalty=6.0)], [offer("o2", cost=2.0, lead=21)])
    from predictily_engine.supply.present import present_solution

    out = present_solution(pr, build_and_solve(pr))
    assert {s["period"]: s["reason"] for s in out["shortfalls"]} == {0: "lead_time", 1: "lead_time", 2: "lead_time"}


def test_safety_stock_is_kept_when_affordable_and_reported_when_not():
    n = node(opening=0.0, demand=[10.0] * 6, penalty=4.0, ss=25.0)
    sol = build_and_solve(problem([n], [offer(cost=2.0, lead=1)]))
    inv = sol.evaluation.nodes[(1, 10)].inventory
    assert inv[0] == pytest.approx(25.0) and all(inv[:5] >= 25.0 - 1e-6)
    # Sin presupuesto: el déficit de stock de seguridad queda declarado, no se oculta.
    sol2 = build_and_solve(problem([n], [offer(cost=2.0, lead=1)], budgets=[budget(0.0, 0, 70)]))
    assert sol2.evaluation.nodes[(1, 10)].ss_shortfall.sum() > 0 and sol2.evaluation.feasible


def test_offer_validity_window_is_respected():
    from datetime import timedelta

    o = offer(cost=2.0, lead=1, valid_to=AS_OF + timedelta(days=7))  # vigente para emitir en t=0 y t=1
    sol = build_and_solve(problem([node(demand=[10.0] * 4, penalty=5.0)], [o]))
    assert max(l.t for l in sol.purchases) <= 1 and sol.evaluation.feasible


def test_fixed_order_cost_consolidates_purchases():
    # Costo fijo alto por línea: conviene una sola compra anticipada. Demanda 10×4, costo fijo 100, mantenimiento 0,01/u-período.
    n = node(demand=[10.0] * 4, penalty=50.0, holding=0.01)
    sol = build_and_solve(problem([n], [offer(cost=1.0, lead=1, fixed=100.0)]))
    assert len(sol.purchases) == 1 and sol.purchases[0].quantity == pytest.approx(40.0)
    assert sol.evaluation.fixed_cost == pytest.approx(100.0)


# ───────────────────────────── infactibilidad ─────────────────────────────

def test_hard_coverage_infeasible_is_explained_without_returning_a_plan():
    pr = problem([node(opening=10.0, demand=[70.0] * 4, penalty=4.0)], [offer(cost=2.0, lead=21)], mode="hard")
    out = solve_plan(pr)
    assert out.status == "infeasible"
    assert out.solution.purchases == [] and out.solution.evaluation is None
    assert out.infeasibility["structural"]["unmet_even_without_constraints"] == pytest.approx(200.0)
    assert "plazos" in out.infeasibility["message"]
    alt = out.alternative
    assert alt is not None and alt.evaluation.feasible and alt.evaluation.unmet_units == pytest.approx(200.0)


def test_hard_coverage_infeasible_due_to_budget_names_the_budget_and_requirement():
    pr = problem([node(demand=[70.0] * 3, penalty=4.0)], [offer(cost=2.0, lead=1)],
                 budgets=[budget(100.0, 0, 0, bid=1), budget(1000.0, 7, 14, bid=2)], mode="hard")
    out = solve_plan(pr)
    assert out.status == "infeasible"
    causes = {c["family"]: c for c in out.infeasibility["causes"]}
    assert "budget" in causes and causes["budget"]["units_recovered"] == pytest.approx(20.0)
    needed = {r["budget_id"]: r["needed"] for r in causes["budget"]["required"]}
    assert needed[1] == pytest.approx(140.0)  # 70 u. × 2 $
    alt = out.alternative
    assert alt.evaluation.feasible and alt.evaluation.unmet_units == pytest.approx(20.0)
    spent = [a for a in alt.evaluation.activity if a["family"] == "budget" and a["ref"] == 1][0]
    assert spent["used"] <= 100.0 + 1e-9


def test_soft_plan_with_unmet_demand_attributes_causes():
    pr = problem([node(demand=[70.0] * 3, penalty=4.0)], [offer(cost=2.0, lead=1, capacity=40.0)])
    out = solve_plan(pr)
    causes = {c["family"]: c for c in out.diagnosis["causes"]}
    assert causes["supplier_capacity"]["units_recovered"] == pytest.approx(90.0)


# ───────────────────────────── producción genérica (E2-14) ─────────────────────────────

def _production_problem(demand_p, cap_batches):
    # Producto P (id 20): lote nominal 10 u., rendimiento 0,8 → 8 u. útiles. Insumos X (id 30): 5 por lote, Y (id 31): 1.
    # Tiempo de producción 7 días → disponible desde el período siguiente. Recurso: 1 unidad de capacidad por lote.
    nodes = [node(product=20, demand=[demand_p] * 4, penalty=4.0),
             node(product=30, opening=1000.0, demand=[0.0] * 4, penalty=1.0, derived=True),
             node(product=31, opening=1000.0, demand=[0.0] * 4, penalty=1.0, derived=True)]
    recipe = RecipeSpec(recipe_id=1, code="R1", output_product_id=20, branch_id=None, output_per_batch=8.0,
                        inputs={30: 5.0, 31: 1.0}, production_days=7, resource_id=1, resource_usage=1.0,
                        cost_per_batch=0.0, integer_batches=True)
    res = ResourceSpec(1, "LINEA", None, cap_batches, "lotes")
    return problem(nodes, [], recipes=[recipe], resources=[res])


def test_generic_production_respects_yield_time_inputs_and_capacity():
    # Demanda 40 = 5 lotes por período. Capacidad 6 lotes: alcanza. Período 0 no se puede producir a tiempo.
    sol = build_and_solve(_production_problem(40.0, 6.0))
    assert sol.status == "optimal"
    batches = {pl.t: pl.batches for pl in sol.productions}
    assert batches == {0: 5.0, 1: 5.0, 2: 5.0}
    ev = sol.evaluation
    assert ev.nodes[(1, 20)].unmet.tolist() == [40.0, 0, 0, 0]
    # Consumo exacto de insumos: 3 períodos × 5 lotes × 5 = 75 de X y 15 de Y
    assert ev.nodes[(1, 30)].inventory[-1] == pytest.approx(1000 - 75.0)
    assert ev.nodes[(1, 31)].inventory[-1] == pytest.approx(1000 - 15.0)
    assert ev.feasible


def test_production_is_capped_by_resource_capacity():
    sol = build_and_solve(_production_problem(80.0, 6.0))  # 10 lotes necesarios, 6 de capacidad
    assert all(pl.batches <= 6.0 + 1e-9 for pl in sol.productions)
    assert {pl.t: pl.batches for pl in sol.productions} == {0: 6.0, 1: 6.0, 2: 6.0}
    # 80 + 3×(80 − 48) = 176 u. sin atender
    assert sol.evaluation.unmet_units == pytest.approx(176.0)
    act = [a for a in sol.evaluation.activity if a["family"] == "resource_capacity"]
    assert all(a["used"] <= a["limit"] + 1e-9 for a in act)


def test_production_is_limited_by_input_stock():
    pr = _production_problem(80.0, 100.0)
    for n in pr.nodes:
        if n.product_id == 30:
            n.opening = 60.0  # alcanza para 12 lotes en total (5 por lote)
    sol = build_and_solve(pr)
    assert sum(pl.batches for pl in sol.productions) <= 12.0 + 1e-9
    assert sol.evaluation.feasible and all(pl.batches == int(pl.batches) for pl in sol.productions)


# ───────────────────────────── oráculo de enumeración ─────────────────────────────

def _oracle(node_spec, off, periods, budget_cap=None):
    """Mejor costo por enumeración de lotes por período, con una simulación independiente (sin NumPy ni el modelo)."""
    best = None
    kmax = int(sum(node_spec.demand) / off.lot) + 2
    lead_shift = math.ceil((off.default_lead - 1) / 7)
    for ks in itertools.product(range(kmax + 1), repeat=periods):
        if off.capacity is not None and any(k * off.lot > off.capacity + 1e-9 for k in ks):
            continue
        if off.moq > 0 and any(0 < k * off.lot < off.moq - 1e-9 for k in ks):
            continue
        spend_ok = True
        cost = 0.0
        inv = node_spec.opening
        arrivals = [0.0] * periods
        for t, k in enumerate(ks):
            q = k * off.lot
            c = q * off.unit_cost + (off.fixed_cost if q > 0 else 0.0)
            if budget_cap is not None and c > budget_cap + 1e-9:
                spend_ok = False
                break
            cost += c
            if t + lead_shift < periods:
                arrivals[t + lead_shift] += q
        if not spend_ok:
            continue
        for tau in range(periods):
            avail = inv + arrivals[tau]
            sold = min(node_spec.demand[tau], avail)
            cost += node_spec.penalty * (node_spec.demand[tau] - sold)
            inv = avail - sold
            cost += node_spec.holding * inv
        if best is None or cost < best:
            best = cost
    return best


@pytest.mark.parametrize("seed", range(25))
def test_milp_matches_enumeration_oracle(seed):
    rng = np.random.default_rng(seed)
    periods = int(rng.integers(3, 5))
    lot = float(rng.choice([1.0, 5.0, 10.0]))
    n = node(opening=float(rng.integers(0, 30)), demand=rng.integers(5, 40, periods).astype(float).tolist(),
             penalty=float(rng.uniform(2.5, 6.0)), holding=float(rng.choice([0.0, 0.05, 0.2])))
    o = offer(cost=float(rng.uniform(1.0, 2.4)), lead=int(rng.choice([1, 7, 8, 14])), lot=lot,
              moq=float(rng.choice([0.0, 0.0, 2 * lot, 6 * lot])), fixed=float(rng.choice([0.0, 0.0, 5.0, 20.0])),
              capacity=float(rng.choice([1e9, 1e9, 30.0, 60.0])))
    if o.capacity >= 1e9:
        o.capacity = None
    cap = float(rng.choice([10 ** 9, 40.0, 120.0]))
    pr = problem([n], [o], budgets=[] if cap >= 10 ** 9 else None or [], periods=periods)
    pr.budgets = []
    expected = _oracle(n, o, periods)
    sol = build_and_solve(pr)
    assert sol.status == "optimal"
    assert sol.objective_value == pytest.approx(expected, rel=1e-6, abs=1e-6)
    assert sol.evaluation.total_cost == pytest.approx(expected, rel=1e-6, abs=1e-6)
    assert sol.evaluation.feasible


@pytest.mark.parametrize("seed", range(12))
def test_milp_never_violates_constraints_and_beats_the_heuristic(seed):
    rng = np.random.default_rng(100 + seed)
    nodes, offers = [], []
    for i in range(int(rng.integers(2, 5))):
        pid = 10 + i
        nodes.append(node(product=pid, opening=float(rng.integers(0, 50)),
                          demand=rng.integers(0, 60, 5).astype(float).tolist(), penalty=float(rng.uniform(3, 7)),
                          holding=0.05, ss=float(rng.choice([0.0, 10.0])), space=float(rng.choice([1.0, 2.0]))))
        for j in range(int(rng.integers(1, 3))):
            offers.append(offer(key=f"o{pid}{j}", product=pid, cost=float(rng.uniform(1, 2.5)), lead=int(rng.choice([1, 8, 15])),
                                lot=float(rng.choice([1.0, 10.0])), moq=float(rng.choice([0.0, 20.0])),
                                fixed=float(rng.choice([0.0, 10.0])), supplier=j + 1,
                                capacity=float(rng.choice([50.0, 200.0]))))
    buds = [budget(float(rng.uniform(100, 300)), 0, 7, bid=1), budget(float(rng.uniform(100, 300)), 14, 28, bid=2)]
    caps = [CapacitySpec(1, float(rng.uniform(150, 400)), "u")]
    pr = problem(nodes, offers, budgets=buds, capacities=caps, periods=5)
    sol = build_and_solve(pr)
    assert sol.status in ("optimal", "feasible_with_gap")
    assert sol.evaluation.feasible, sol.evaluation.violations
    # La función objetivo del modelo coincide con la re-simulación independiente (no hay ventas retenidas).
    assert sol.objective_value == pytest.approx(sol.evaluation.total_cost, rel=1e-6, abs=1e-5)
    hs = heuristic_plan(pr)
    assert hs.evaluation.feasible, hs.evaluation.violations
    assert sol.evaluation.total_cost <= hs.evaluation.total_cost + 1e-6


def test_time_limit_is_never_called_optimal():
    # Mochila grande con límite de tiempo ínfimo: el estado debe ser time_limit (o solución trivial verificable).
    rng = np.random.default_rng(7)
    nodes = [node(product=100 + i, demand=rng.integers(5, 40, 8).astype(float).tolist(), penalty=5.0) for i in range(60)]
    offers = [offer(key=f"o{100 + i}", product=100 + i, cost=float(rng.uniform(1, 2)), lead=1, lot=7.0, moq=21.0,
                    fixed=3.0, capacity=60.0) for i in range(60)]
    pr = problem(nodes, offers, budgets=[budget(900.0, 0, 70)], periods=8, gap=0.0, time_limit=0.02)
    sol = build_and_solve(pr)
    assert sol.status in ("time_limit", "optimal")
    if sol.status == "time_limit":
        assert sol.mip_gap is None or sol.mip_gap >= 0
        assert any("límite de tiempo" in n for n in sol.notes)


def test_solve_plan_falls_back_to_the_heuristic_when_the_solver_fails(monkeypatch):
    from predictily_engine.supply import service

    def broken(problem, **kw):
        from predictily_engine.supply.model import PlanSolution

        return PlanSolution(status="time_limit", detail="sin solución")

    monkeypatch.setattr(service, "build_and_solve", broken)
    pr = problem([node(demand=[70.0] * 3, penalty=4.0)], [offer(cost=2.0, lead=1)], budgets=[budget(150.0, 0, 14)])
    out = service.solve_plan(pr, diagnose_unmet=False)
    assert out.status == "heuristic_fallback"
    assert out.solution.status == "heuristic_fallback" and out.solution.evaluation.feasible
    spent = [a for a in out.solution.evaluation.activity if a["family"] == "budget"][0]
    assert spent["used"] <= 150.0 + 1e-9
