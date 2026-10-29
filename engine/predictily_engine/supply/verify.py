"""Re-simulación independiente de un plan y comprobación de todas sus restricciones.

El solver (o la heurística) propone compras y lotes de producción; este módulo recalcula, sin usar las
matrices del modelo, el balance de cada producto/ubicación y verifica presupuesto, capacidades, mínimos,
múltiplos, vigencia, plazos y balance. Todo plan devuelto por el motor pasa por aquí (E2-13).
"""

from __future__ import annotations

import math
from collections import defaultdict

import numpy as np

from predictily_engine.supply.model import (
    EPS,
    Evaluation,
    NodeResult,
    PlanProblem,
    ProductionLine,
    PurchaseLine,
    arrival_period,
)

REL_TOL = 1e-6


def _tol(limit: float) -> float:
    return max(1e-6, REL_TOL * abs(limit))


def _simulate_node(node, arrivals: np.ndarray, prod_out: np.ndarray, consumption: np.ndarray, ss_from: int,
                   violations: list[dict], tol: float = 1e-6) -> NodeResult:
    n = node.horizon
    inv = np.zeros(n)
    sales = np.zeros(n)
    unmet = np.zeros(n)
    ss_short = np.zeros(n)
    receipts = node.known_receipts[:n] + arrivals[:n] + prod_out[:n]
    peak = np.zeros(n)
    prev = float(node.opening)
    for tau in range(n):
        avail = prev + receipts[tau]
        peak[tau] = avail
        cons = float(consumption[tau])
        left = avail - cons
        if left < -max(tol, REL_TOL * max(1.0, avail)):
            violations.append({"type": "input_shortage", "branch_id": node.branch_id, "product_id": node.product_id,
                               "period": tau, "short_by": round(-left, 6)})
        left = max(left, 0.0)
        s = min(float(node.demand[tau]), left)
        sales[tau] = s
        unmet[tau] = float(node.demand[tau]) - s
        prev = left - s
        inv[tau] = prev
        if node.safety_stock > 0 and tau >= ss_from:
            ss_short[tau] = max(0.0, node.safety_stock - prev)
    return NodeResult(inv, sales, unmet, ss_short, receipts, peak)


def baseline_peaks(problem: PlanProblem) -> dict[tuple[int, int], float]:
    """Pico de espacio ocupado por sucursal y período SIN compras ni producción nuevas."""
    violations: list[dict] = []
    peaks: dict[tuple[int, int], float] = defaultdict(float)
    for node in problem.nodes:
        if node.space is None:
            continue
        z = np.zeros(node.horizon)
        res = _simulate_node(node, z, z, z, 10 ** 9, violations)
        for tau in range(node.horizon):
            peaks[(node.branch_id, tau)] += node.space * res.peak[tau]
    return dict(peaks)


def effective_capacity(problem: PlanProblem) -> dict[tuple[int, int], dict[str, float]]:
    """Capacidad efectiva por (sucursal, período) = max(capacidad declarada, espacio ya comprometido sin compras).

    Si las existencias y órdenes ya abiertas superan la capacidad, ninguna compra nueva puede corregirlo;
    la capacidad efectiva evita un modelo infactible por una causa preexistente y se informa como exceso.
    """
    base = baseline_peaks(problem)
    out: dict[tuple[int, int], dict[str, float]] = {}
    for cap in problem.capacities:
        horizon = max((n.horizon for n in problem.nodes if n.branch_id == cap.branch_id), default=0)
        for tau in range(horizon):
            b = base.get((cap.branch_id, tau), 0.0)
            out[(cap.branch_id, tau)] = {"declared": cap.capacity, "baseline": b,
                                         "effective": max(cap.capacity, b),
                                         "preexisting_excess": max(0.0, b - cap.capacity)}
    return out


def evaluate(problem: PlanProblem, purchases: list[PurchaseLine], productions: list[ProductionLine]) -> Evaluation:
    violations: list[dict] = []
    activity: list[dict] = []
    nodes = problem.node_index()
    offers = {o.key: o for o in problem.offers}
    recipes = {r.recipe_id: r for r in problem.recipes}
    R = problem.period_days

    arrivals = {k: np.zeros(n.horizon) for k, n in nodes.items()}
    prod_out = {k: np.zeros(n.horizon) for k, n in nodes.items()}
    consumption = {k: np.zeros(n.horizon) for k, n in nodes.items()}

    # ── Compras: reglas por línea ──
    cap_use: dict[tuple[str, int], float] = defaultdict(float)
    for p in purchases:
        node = nodes.get((p.branch_id, p.product_id))
        offer = offers.get(p.offer_key)
        if node is None or offer is None:
            violations.append({"type": "unknown_reference", "offer": p.offer_key, "branch_id": p.branch_id,
                               "product_id": p.product_id})
            continue
        if p.quantity < -EPS:
            violations.append({"type": "negative_quantity", "offer": p.offer_key})
        if p.quantity <= EPS:
            continue
        if not offer.applies_to(p.branch_id) or offer.product_id != p.product_id:
            violations.append({"type": "offer_not_applicable", "offer": p.offer_key, "branch_id": p.branch_id})
        issue = problem.issue_date(p.t)
        if not offer.valid_on(issue):
            violations.append({"type": "offer_not_valid_on_issue_date", "offer": p.offer_key, "date": issue.isoformat()})
        lots = p.quantity / offer.lot
        if abs(lots - round(lots)) > 1e-6 * max(1.0, lots):
            violations.append({"type": "lot_multiple", "offer": p.offer_key, "quantity": p.quantity, "lot": offer.lot})
        if offer.moq > EPS and p.quantity < offer.moq - _tol(offer.moq):
            violations.append({"type": "minimum_order", "offer": p.offer_key, "quantity": p.quantity,
                               "minimum": offer.moq})
        expected_arr = arrival_period(p.t, offer.lead(p.branch_id), R)
        if expected_arr != p.arrival_period:
            violations.append({"type": "arrival_period_mismatch", "offer": p.offer_key,
                               "expected": expected_arr, "got": p.arrival_period})
        if p.arrival_period < node.horizon:
            arrivals[node.key][p.arrival_period] += p.quantity
        cap_use[(p.offer_key, p.t)] += p.quantity

    # ── Capacidad de proveedor ──
    for (okey, t), used in sorted(cap_use.items()):
        cap = offers[okey].capacity
        if cap is None:
            continue
        activity.append({"family": "supplier_capacity", "ref": okey, "period": t, "used": used, "limit": cap,
                         "binding": used >= cap - _tol(cap), "offer_id": offers[okey].offer_id,
                         "supplier_id": offers[okey].supplier_id, "product_id": offers[okey].product_id})
        if used > cap + _tol(cap):
            violations.append({"type": "supplier_capacity", "offer": okey, "period": t, "used": used, "limit": cap})

    # ── Producción ──
    res_use: dict[tuple[int, int], float] = defaultdict(float)
    for pl in productions:
        recipe = recipes.get(pl.recipe_id)
        if recipe is None:
            violations.append({"type": "unknown_recipe", "recipe_id": pl.recipe_id})
            continue
        if pl.batches <= EPS:
            continue
        if recipe.integer_batches and abs(pl.batches - round(pl.batches)) > 1e-6:
            violations.append({"type": "batch_multiple", "recipe_id": pl.recipe_id, "batches": pl.batches})
        out_node = nodes.get((pl.branch_id, recipe.output_product_id))
        if out_node is None:
            violations.append({"type": "unknown_node", "branch_id": pl.branch_id, "product_id": recipe.output_product_id})
            continue
        arr = pl.t + math.ceil((max(1, recipe.production_days) - 1) / R)
        if arr < out_node.horizon:
            prod_out[out_node.key][arr] += pl.batches * recipe.output_per_batch
        for pid, qty in recipe.inputs.items():
            inode = nodes.get((pl.branch_id, pid))
            if inode is None:
                violations.append({"type": "unknown_node", "branch_id": pl.branch_id, "product_id": pid})
                continue
            if pl.t < inode.horizon:
                consumption[inode.key][pl.t] += pl.batches * qty
        if recipe.resource_id is not None:
            res_use[(recipe.resource_id, pl.t)] += pl.batches * recipe.resource_usage
        if recipe.max_batches is not None and pl.batches > recipe.max_batches + 1e-6:
            violations.append({"type": "max_batches", "recipe_id": pl.recipe_id, "batches": pl.batches,
                               "limit": recipe.max_batches})
    for res in problem.resources:
        for t in range(problem.n_periods):
            used = sum(v for (rid, tt), v in res_use.items() if rid == res.resource_id and tt == t)
            if used <= EPS and not any(rid == res.resource_id for rid, _ in res_use):
                continue
            activity.append({"family": "resource_capacity", "ref": res.code, "period": t, "used": used,
                             "limit": res.capacity, "binding": used >= res.capacity - _tol(res.capacity),
                             "resource_id": res.resource_id})
            if used > res.capacity + _tol(res.capacity):
                violations.append({"type": "resource_capacity", "resource": res.code, "period": t, "used": used,
                                   "limit": res.capacity})

    # ── Balance por nodo ──
    results: dict[tuple[int, int], NodeResult] = {}
    for k, node in nodes.items():
        ss_from = int(node.meta.get("ss_from", 0))
        results[k] = _simulate_node(node, arrivals[k], prod_out[k], consumption[k], ss_from, violations)

    # ── Presupuestos ──
    for b in problem.budgets:
        spent = 0.0
        for p in purchases:
            if p.quantity <= EPS:
                continue
            if b.branch_id is not None and p.branch_id != b.branch_id:
                continue
            d = problem.issue_date(p.t)
            if b.start <= d <= b.end:
                spent += p.cost
        activity.append({"family": "budget", "ref": b.budget_id, "period": f"{b.start}..{b.end}", "used": spent,
                         "limit": b.amount, "binding": spent >= b.amount - _tol(b.amount) and spent > EPS,
                         "branch_id": b.branch_id})
        if spent > b.amount + _tol(b.amount):
            violations.append({"type": "budget", "budget_id": b.budget_id, "period": f"{b.start}..{b.end}",
                               "spent": spent, "limit": b.amount})

    # ── Almacenamiento (pico tras recepciones) ──
    eff = effective_capacity(problem)
    unverified = [n.key for n in problem.nodes if n.space is None and any(c.branch_id == n.branch_id for c in problem.capacities)]
    for (branch, tau), caps in sorted(eff.items()):
        used = sum(nodes[k].space * results[k].peak[tau] for k in nodes
                   if k[0] == branch and nodes[k].space is not None and tau < nodes[k].horizon)
        activity.append({"family": "storage_capacity", "ref": branch, "period": tau, "used": used,
                         "limit": caps["effective"], "declared_limit": caps["declared"],
                         "binding": used >= caps["effective"] - _tol(caps["effective"]) and used > EPS,
                         "preexisting_excess": caps["preexisting_excess"]})
        if used > caps["effective"] + _tol(caps["effective"]):
            violations.append({"type": "storage_capacity", "branch_id": branch, "period": tau, "used": used,
                               "limit": caps["effective"]})
    if unverified:
        violations_note = {"type": "storage_unverified", "nodes": [list(k) for k in unverified]}
        activity.append({"family": "storage_unverified", "ref": None, "period": None, "used": None, "limit": None,
                         "binding": False, "detail": violations_note})

    # ── Costos ──
    purchase_cost = sum(p.quantity * p.unit_cost for p in purchases if p.quantity > EPS)
    fixed_cost = sum(p.fixed_cost for p in purchases if p.quantity > EPS)
    production_cost = 0.0
    for pl in productions:
        r = recipes.get(pl.recipe_id)
        if r is not None and pl.batches > EPS and r.cost_per_batch:
            production_cost += pl.batches * r.cost_per_batch
    holding = shortage = ss_pen = unmet_units = demand_units = 0.0
    for k, node in nodes.items():
        res = results[k]
        holding += node.holding * float(res.inventory.sum())
        shortage += node.penalty * float(res.unmet.sum())
        ss_pen += (problem.ss_weight * node.penalty / max(1, node.horizon)) * float(res.ss_shortfall.sum())
        unmet_units += float(res.unmet.sum())
        demand_units += float(node.demand.sum())
    return Evaluation(results, purchase_cost, fixed_cost, production_cost, holding, shortage, ss_pen, unmet_units,
                      demand_units, violations, activity)
