"""Planificador de reposición con restricciones (ADR-V2-09): modelo de programación lineal entera mixta.

Resuelve con `scipy.optimize.milp` (HiGHS). El modelo matemático completo está en
docs/architecture/v2/P4-decisiones.md §3; aquí se resume:

Variables (por producto p, ubicación l, período τ, oferta o, período de emisión t):
    k[o,l,t] ∈ ℤ≥0   lotes comprados de la oferta o, emitidos en t   (cantidad = lote × k)
    y[o,l,t] ∈ {0,1}  la línea se emite (mínimo de compra y costo fijo)
    x[r,l,t] ∈ ℤ≥0   lotes de producción de la receta r iniciados en t
    I[p,l,τ] ≥ 0     existencias al cierre del período
    U[p,l,τ] ∈ [0,D] demanda no atendida (ventas perdidas)
    V[p,l,τ] ≥ 0     déficit respecto del stock de seguridad
Restricciones: balance, mínimos/lotes, capacidad de proveedor, presupuesto por período de emisión, capacidad de
almacenamiento (pico tras recepciones), capacidad de recursos, insumos disponibles (I ≥ 0) y, en modo `hard`, U = 0.
Objetivo (`min_cost`): compra + costos fijos + producción + mantenimiento + penalización por faltante (supuesto
explícito, ponderada por prioridad) + penalización del déficit de stock de seguridad. `max_margin` usa el precio de
venta como penalización por unidad no atendida, equivalente a maximizar ingresos − costos.
"""

from __future__ import annotations

import math
import time
from collections import defaultdict

import numpy as np
import scipy.sparse as sp
from scipy.optimize import Bounds, LinearConstraint, milp

from predictily_engine.supply.model import (
    EPS,
    PlanProblem,
    PlanSolution,
    ProductionLine,
    PurchaseLine,
    arrival_period,
)
from predictily_engine.supply.verify import effective_capacity, evaluate

BIG = 1e9


def prepare_problem(problem: PlanProblem) -> PlanProblem:
    """Fija `ss_from` por nodo: primer período en el que una compra nueva puede influir (idempotente)."""
    R = problem.period_days
    for node in problem.nodes:
        if "ss_from" in node.meta:
            continue
        firsts = [arrival_period(0, o.lead(node.branch_id), R) for o in problem.offers_for(node.branch_id, node.product_id)]
        for r in problem.recipes_for(node.branch_id):
            if r.output_product_id == node.product_id:
                firsts.append(0 + math.ceil((max(1, r.production_days) - 1) / R))
        node.meta["ss_from"] = min(firsts) if firsts else node.horizon
    return problem


class _Model:
    def __init__(self) -> None:
        self.lb: list[float] = []
        self.ub: list[float] = []
        self.integ: list[int] = []
        self.cost: list[float] = []
        self.rows: list[tuple[dict[int, float], float, float, str]] = []

    def var(self, lb: float, ub: float, cost: float = 0.0, integer: bool = False) -> int:
        self.lb.append(lb)
        self.ub.append(ub)
        self.integ.append(1 if integer else 0)
        self.cost.append(cost)
        return len(self.lb) - 1

    def row(self, coefs: dict[int, float], lo: float, hi: float, tag: str) -> None:
        self.rows.append((coefs, lo, hi, tag))

    def matrices(self):
        data, ri, ci = [], [], []
        lo, hi = [], []
        for i, (coefs, l, h, _tag) in enumerate(self.rows):
            for c, v in coefs.items():
                if v != 0.0:
                    data.append(v)
                    ri.append(i)
                    ci.append(c)
            lo.append(l)
            hi.append(h)
        A = sp.csr_matrix((data, (ri, ci)), shape=(len(self.rows), len(self.lb)))
        return A, np.array(lo), np.array(hi)


def _add(d: dict[int, float], j: int, v: float) -> None:
    d[j] = d.get(j, 0.0) + v


def build_and_solve(problem: PlanProblem, *, relax: frozenset[str] = frozenset(), coverage_mode: str | None = None,
                    time_limit: float | None = None) -> PlanSolution:
    """Construye y resuelve el MILP. `relax` ⊆ {budget, supplier_capacity, storage, resource} (diagnóstico)."""
    prepare_problem(problem)
    t0 = time.perf_counter()
    mode = coverage_mode or problem.coverage_mode
    R = problem.period_days
    T = problem.n_periods
    m = _Model()
    nodes = problem.node_index()
    eff = effective_capacity(problem) if "storage" not in relax else {}
    cap_by_branch = {c.branch_id for c in problem.capacities}

    I_var: dict[tuple[tuple[int, int], int], int] = {}
    U_var: dict[tuple[tuple[int, int], int], int] = {}
    V_var: dict[tuple[tuple[int, int], int], int] = {}
    arr_terms: dict[tuple[tuple[int, int], int], dict[int, float]] = defaultdict(dict)  # llegadas por (nodo, τ)
    cons_terms: dict[tuple[tuple[int, int], int], dict[int, float]] = defaultdict(dict)
    k_var: dict[tuple[str, int, int], int] = {}
    y_var: dict[tuple[str, int, int], int] = {}
    x_var: dict[tuple[int, int, int], int] = {}

    # Necesidad dependiente (insumos de recetas): cota para las cantidades máximas.
    dependent: dict[tuple[int, int], float] = defaultdict(float)
    for r in problem.recipes:
        for node in problem.nodes:
            if node.product_id != r.output_product_id or not (r.branch_id is None or r.branch_id == node.branch_id):
                continue
            batches = (float(node.demand.sum()) + node.safety_stock) / max(r.output_per_batch, EPS) + 1.0
            if r.max_batches is not None:
                batches = min(batches, r.max_batches * T)
            for pid, q in r.inputs.items():
                dependent[(node.branch_id, pid)] += batches * q

    # ── Variables de estado por nodo ──
    for node in problem.nodes:
        key = node.key
        for tau in range(node.horizon):
            I_var[(key, tau)] = m.var(0.0, np.inf, node.holding)
            d = float(node.demand[tau])
            U_var[(key, tau)] = m.var(0.0, 0.0 if mode == "hard" else d, node.penalty)
            if node.safety_stock > EPS and tau >= node.meta["ss_from"]:
                V_var[(key, tau)] = m.var(0.0, node.safety_stock,
                                          problem.ss_weight * node.penalty / max(1, node.horizon))

    # ── Compras ──
    budgets = problem.budgets if "budget" not in relax else []
    for node in problem.nodes:
        b, p = node.key
        total_need = float(node.demand.sum()) + node.safety_stock + dependent.get(node.key, 0.0)
        for o in problem.offers_for(b, p):
            L = o.lead(b)
            lot = max(o.lot, EPS)
            for t in range(T):
                tau_a = arrival_period(t, L, R)
                if tau_a >= node.horizon:
                    break
                d_issue = problem.issue_date(t)
                if not o.valid_on(d_issue):
                    continue
                min_lots = max(1, math.ceil(o.moq / lot - 1e-9)) if o.moq > EPS else 1
                kmax = max(math.ceil(total_need / lot - 1e-9) + 1, min_lots)
                if o.capacity is not None and "supplier_capacity" not in relax:
                    kmax = min(kmax, math.floor(o.capacity / lot + 1e-9))
                for bud in budgets:
                    if (bud.branch_id is None or bud.branch_id == b) and bud.start <= d_issue <= bud.end:
                        per_lot = max(o.unit_cost * lot, EPS)
                        kmax = min(kmax, math.floor(max(bud.amount - o.fixed_cost, 0.0) / per_lot + 1e-9))
                if kmax < 1:
                    continue
                k = m.var(0.0, float(kmax), o.unit_cost * lot, integer=True)
                k_var[(o.key, b, t)] = k
                _add(arr_terms[(node.key, tau_a)], k, lot)
                if o.moq > EPS or o.fixed_cost > EPS:
                    y = m.var(0.0, 1.0, o.fixed_cost, integer=True)
                    y_var[(o.key, b, t)] = y
                    m.row({k: 1.0, y: -float(kmax)}, -np.inf, 0.0, f"link:{o.key}:{b}:{t}")
                    if min_lots > kmax:
                        m.ub[y] = 0.0  # el mínimo excede la cota útil: la línea no puede emitirse
                        m.ub[k] = 0.0
                    else:
                        m.row({k: 1.0, y: -float(min_lots)}, 0.0, np.inf, f"moq:{o.key}:{b}:{t}")

    # ── Producción ──
    for r in problem.recipes:
        for node in problem.nodes:
            b = node.branch_id
            if node.product_id != r.output_product_id or not (r.branch_id is None or r.branch_id == b):
                continue
            if any((b, pid) not in nodes for pid in r.inputs):
                continue  # insumo sin nodo: no se puede planificar su consumo
            for t in range(T):
                tau_a = t + math.ceil((max(1, r.production_days) - 1) / R)
                if tau_a >= node.horizon:
                    break
                ub = (float(node.demand.sum()) + node.safety_stock) / max(r.output_per_batch, EPS) + 1.0
                if r.max_batches is not None:
                    ub = min(ub, r.max_batches)
                ub = math.floor(ub + 1e-9) if r.integer_batches else ub
                if ub <= 0:
                    continue
                x = m.var(0.0, float(ub), float(r.cost_per_batch or 0.0), integer=r.integer_batches)
                x_var[(r.recipe_id, b, t)] = x
                _add(arr_terms[(node.key, tau_a)], x, r.output_per_batch)
                for pid, q in r.inputs.items():
                    if t < nodes[(b, pid)].horizon:
                        _add(cons_terms[((b, pid), t)], x, q)

    # ── Balance, stock de seguridad ──
    for node in problem.nodes:
        key = node.key
        for tau in range(node.horizon):
            coefs: dict[int, float] = {I_var[(key, tau)]: 1.0}
            if tau > 0:
                coefs[I_var[(key, tau - 1)]] = -1.0
            for j, c in arr_terms.get((key, tau), {}).items():
                _add(coefs, j, -c)
            for j, c in cons_terms.get((key, tau), {}).items():
                _add(coefs, j, c)
            _add(coefs, U_var[(key, tau)], -1.0)
            rhs = float(node.known_receipts[tau]) - float(node.demand[tau]) + (node.opening if tau == 0 else 0.0)
            m.row(coefs, rhs, rhs, f"balance:{key}:{tau}")
            if (key, tau) in V_var:
                m.row({I_var[(key, tau)]: 1.0, V_var[(key, tau)]: 1.0}, node.safety_stock, np.inf, f"ss:{key}:{tau}")

    # ── Capacidad de proveedor ──
    if "supplier_capacity" not in relax:
        by_offer_t: dict[tuple[str, int], dict[int, float]] = defaultdict(dict)
        offers = {o.key: o for o in problem.offers}
        for (okey, b, t), k in k_var.items():
            if offers[okey].capacity is not None:
                _add(by_offer_t[(okey, t)], k, max(offers[okey].lot, EPS))
        for (okey, t), coefs in by_offer_t.items():
            m.row(coefs, -np.inf, float(offers[okey].capacity), f"supplier_capacity:{okey}:{t}")

    # ── Presupuesto por período de emisión ──
    for i, bud in enumerate(budgets):
        coefs = {}
        offers = {o.key: o for o in problem.offers}
        for (okey, b, t), k in k_var.items():
            if (bud.branch_id is None or bud.branch_id == b) and bud.start <= problem.issue_date(t) <= bud.end:
                o = offers[okey]
                _add(coefs, k, o.unit_cost * max(o.lot, EPS))
                if (okey, b, t) in y_var:
                    _add(coefs, y_var[(okey, b, t)], o.fixed_cost)
        if coefs:
            m.row(coefs, -np.inf, float(bud.amount), f"budget:{bud.budget_id if bud.budget_id is not None else i}")

    # ── Almacenamiento: pico tras recepciones ≤ capacidad efectiva ──
    if "storage" not in relax:
        for (branch, tau), caps in eff.items():
            coefs = {}
            rhs = caps["effective"]
            for node in problem.nodes:
                if node.branch_id != branch or node.space is None or tau >= node.horizon:
                    continue
                s = node.space
                if tau == 0:
                    rhs -= s * node.opening
                else:
                    _add(coefs, I_var[(node.key, tau - 1)], s)
                rhs -= s * float(node.known_receipts[tau])
                for j, c in arr_terms.get((node.key, tau), {}).items():
                    _add(coefs, j, s * c)
            if coefs:
                m.row(coefs, -np.inf, rhs, f"storage:{branch}:{tau}")

    # ── Capacidad de recursos de producción ──
    if "resource" not in relax:
        for res in problem.resources:
            for t in range(T):
                coefs = {}
                for r in problem.recipes:
                    if r.resource_id != res.resource_id or r.resource_usage <= 0:
                        continue
                    for (rid, b, tt), x in x_var.items():
                        if rid == r.recipe_id and tt == t and (res.branch_id is None or res.branch_id == b):
                            _add(coefs, x, r.resource_usage)
                if coefs:
                    m.row(coefs, -np.inf, float(res.capacity), f"resource:{res.code}:{t}")

    sol = PlanSolution(status="not_solved", n_vars=len(m.lb), n_constraints=len(m.rows), n_integer=int(sum(m.integ)))
    if not m.lb:
        sol.status = "optimal"
        sol.detail = "Sin variables de decisión: no hay productos con demanda y opciones de compra."
        sol.seconds = time.perf_counter() - t0
        sol.evaluation = evaluate(problem, [], [])
        sol.objective_value = sol.evaluation.total_cost
        sol.mip_gap = 0.0
        return sol

    A, lo, hi = m.matrices()
    options = {"mip_rel_gap": float(problem.mip_gap), "presolve": True,
               "time_limit": float(time_limit if time_limit is not None else problem.time_limit)}
    try:
        res = milp(np.array(m.cost), constraints=LinearConstraint(A, lo, hi) if len(m.rows) else None,
                   integrality=np.array(m.integ), bounds=Bounds(np.array(m.lb), np.array(m.ub)), options=options)
    except Exception as exc:  # pragma: no cover - fallo interno del solver
        sol.detail = f"El solver falló: {exc.__class__.__name__}: {exc}"
        sol.seconds = time.perf_counter() - t0
        return sol
    sol.seconds = time.perf_counter() - t0
    sol.detail = str(res.message)
    gap = getattr(res, "mip_gap", None)
    sol.mip_gap = None if gap is None or (isinstance(gap, float) and not math.isfinite(gap)) else float(gap)
    bound = getattr(res, "mip_dual_bound", None)
    sol.dual_bound = None if bound is None or not math.isfinite(bound) else float(bound)
    if res.status == 2:
        sol.status = "infeasible"
        return sol
    if res.x is None:
        sol.status = "time_limit" if res.status == 1 else "not_solved"
        sol.notes.append("El solver no encontró ninguna solución factible.")
        return sol

    x = res.x
    purchases: list[PurchaseLine] = []
    offers = {o.key: o for o in problem.offers}
    for (okey, b, t), k in k_var.items():
        lots = int(round(x[k]))
        if lots <= 0:
            continue
        o = offers[okey]
        L = o.lead(b)
        purchases.append(PurchaseLine(
            offer_key=okey, offer_id=o.offer_id, supplier_id=o.supplier_id, branch_id=b, product_id=o.product_id, t=t,
            lots=lots, quantity=lots * o.lot, arrival_period=arrival_period(t, L, R), lead_days=L,
            unit_cost=o.unit_cost, fixed_cost=o.fixed_cost))
    productions: list[ProductionLine] = []
    recipes = {r.recipe_id: r for r in problem.recipes}
    for (rid, b, t), xv in x_var.items():
        batches = round(x[xv]) if recipes[rid].integer_batches else float(x[xv])
        if batches <= EPS:
            continue
        r = recipes[rid]
        productions.append(ProductionLine(
            recipe_id=rid, branch_id=b, output_product_id=r.output_product_id, t=t, batches=float(batches),
            arrival_period=t + math.ceil((max(1, r.production_days) - 1) / R),
            output_quantity=float(batches) * r.output_per_batch, cost=float(batches) * float(r.cost_per_batch or 0.0)))
    sol.purchases, sol.productions = purchases, productions
    sol.evaluation = evaluate(problem, purchases, productions)
    sol.objective_value = float(res.fun)
    if res.status == 0:
        sol.status = "optimal" if (sol.mip_gap is None or sol.mip_gap <= 1e-6) else "feasible_with_gap"
    elif res.status == 1:
        sol.status = "time_limit"
        sol.notes.append("Se alcanzó el límite de tiempo: la mejor solución factible NO está probada óptima.")
    else:
        sol.status = "not_solved"
    return sol
