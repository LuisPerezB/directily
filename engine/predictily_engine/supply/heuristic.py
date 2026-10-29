"""Respaldo factible heurístico del planificador (explícito, ADR-V2-09).

Se usa SOLO si el MILP no entrega ninguna solución (error del solver o límite de tiempo sin solución factible) y
se informa como `heuristic_fallback`. Es una regla voraz por producto: cuando la simulación muestra demanda no
atendida (o déficit de stock de seguridad) en el período τ, compra lo necesario con la oferta más barata que llega
a tiempo, lo más tarde posible, redondeando a lotes y respetando mínimo, capacidad de proveedor, presupuesto y
capacidad de almacenamiento (reduce o descarta la compra si no cabe). No optimiza el costo conjunto ni planifica
producción. Toda salida se verifica con `verify.evaluate` antes de devolverse.
"""

from __future__ import annotations

import math
import time
from collections import defaultdict

import numpy as np

from predictily_engine.supply.model import EPS, PlanProblem, PlanSolution, PurchaseLine, arrival_period
from predictily_engine.supply.planner import prepare_problem
from predictily_engine.supply.verify import _simulate_node, effective_capacity, evaluate


def heuristic_plan(problem: PlanProblem) -> PlanSolution:
    prepare_problem(problem)
    t0 = time.perf_counter()
    R = problem.period_days
    nodes = problem.node_index()
    eff = effective_capacity(problem)
    offers_used: dict[tuple[str, int], float] = defaultdict(float)
    budget_spent = {i: 0.0 for i in range(len(problem.budgets))}
    arrivals = {k: np.zeros(n.horizon) for k, n in nodes.items()}
    zero = {k: np.zeros(n.horizon) for k, n in nodes.items()}
    purchases: dict[tuple[str, int, int], PurchaseLine] = {}
    notes = ["Respaldo heurístico: no optimiza el costo conjunto y no planifica producción."]

    def simulate(key):
        node = nodes[key]
        return _simulate_node(node, arrivals[key], zero[key], zero[key], int(node.meta.get("ss_from", 0)), [])

    cur = {k: simulate(k) for k in nodes}

    def branch_usage(branch, tau):
        return sum(nodes[k].space * cur[k].peak[tau] for k in nodes
                   if k[0] == branch and nodes[k].space is not None and tau < nodes[k].horizon)

    def fits_storage(key, new_res):
        node = nodes[key]
        if node.space is None:
            return True
        for tau in range(node.horizon):
            caps = eff.get((node.branch_id, tau))
            if caps is None:
                continue
            delta = node.space * (new_res.peak[tau] - cur[key].peak[tau])
            if delta > 1e-12 and branch_usage(node.branch_id, tau) + delta > caps["effective"] + 1e-6:
                return False
        return True

    order = sorted(nodes, key=lambda k: -nodes[k].penalty)
    for key in order:
        node = nodes[key]
        b, p = key
        blocked: set[int] = set()
        for _ in range(4 * node.horizon + 4):
            res = cur[key]
            ss_from = int(node.meta.get("ss_from", 0))
            deficit = res.unmet + np.where(np.arange(node.horizon) >= ss_from, res.ss_shortfall, 0.0)
            taus = [t for t in np.flatnonzero(deficit > 1e-9) if t not in blocked]
            if not taus:
                break
            tau = int(taus[0])
            need = float(deficit[tau])
            options = []
            for o in problem.offers_for(b, p):
                L = o.lead(b)
                t_max = tau - math.ceil((max(1, L) - 1) / R)
                if t_max < 0:
                    continue
                for t in range(min(t_max, problem.n_periods - 1), -1, -1):
                    if o.valid_on(problem.issue_date(t)):
                        options.append((o.unit_cost, L, o, t))
                        break
            if not options:
                blocked.add(tau)
                continue
            options.sort(key=lambda x: (x[0], x[1]))
            done = False
            for _, L, o, t0_ in options:
                for t in range(t0_, -1, -1):
                    if not o.valid_on(problem.issue_date(t)):
                        continue
                    lot = max(o.lot, EPS)
                    lots = math.ceil(need / lot - 1e-9)
                    min_lots = max(1, math.ceil(o.moq / lot - 1e-9)) if o.moq > EPS else 1
                    lots = max(lots, min_lots)
                    cap_left = None if o.capacity is None else o.capacity - offers_used[(o.key, t)]
                    if cap_left is not None:
                        lots = min(lots, math.floor(cap_left / lot + 1e-9))
                    for i, bud in enumerate(problem.budgets):
                        if (bud.branch_id is None or bud.branch_id == b) and bud.start <= problem.issue_date(t) <= bud.end:
                            room = bud.amount - budget_spent[i] - o.fixed_cost
                            lots = min(lots, math.floor(max(room, 0.0) / max(o.unit_cost * lot, EPS) + 1e-9))
                    if lots < min_lots:
                        continue
                    ta = arrival_period(t, L, R)
                    # Reducir lotes hasta que quepa en el almacenamiento.
                    while lots >= min_lots:
                        trial = arrivals[key].copy()
                        if ta < node.horizon:
                            trial[ta] += lots * lot
                        new_res = _simulate_node(node, trial, zero[key], zero[key], ss_from, [])
                        if fits_storage(key, new_res):
                            break
                        lots -= 1
                    if lots < min_lots:
                        continue
                    qty = lots * lot
                    if ta < node.horizon:
                        arrivals[key][ta] += qty
                    offers_used[(o.key, t)] += qty
                    cost = qty * o.unit_cost + o.fixed_cost
                    for i, bud in enumerate(problem.budgets):
                        if (bud.branch_id is None or bud.branch_id == b) and bud.start <= problem.issue_date(t) <= bud.end:
                            budget_spent[i] += cost
                    pk = (o.key, b, t)
                    if pk in purchases:
                        prev = purchases[pk]
                        prev.lots += lots
                        prev.quantity += qty
                    else:
                        purchases[pk] = PurchaseLine(o.key, o.offer_id, o.supplier_id, b, p, t, lots, qty, ta, L,
                                                     o.unit_cost, o.fixed_cost)
                    cur[key] = simulate(key)
                    done = True
                    break
                if done:
                    break
            if not done:
                blocked.add(tau)
    lines = list(purchases.values())
    sol = PlanSolution(status="heuristic_fallback", detail="Respaldo heurístico factible", purchases=lines,
                       notes=notes)
    sol.evaluation = evaluate(problem, lines, [])
    sol.objective_value = sol.evaluation.total_cost
    sol.seconds = time.perf_counter() - t0
    return sol
