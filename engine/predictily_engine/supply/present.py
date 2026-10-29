"""Presentación del plan: líneas, restricciones activas, demanda no atendida con causa y justificación.

Todo número sale de re-simular el plan (`verify.evaluate`). Las justificaciones de elección de oferta son
contrafactuales exactos: se sustituye la oferta de la línea por cada alternativa y se compara el objetivo.
"""

from __future__ import annotations

import copy
from datetime import timedelta
from typing import Any

import numpy as np

from predictily_engine.supply.model import EPS, PlanProblem, PlanSolution, PurchaseLine, arrival_period
from predictily_engine.supply.verify import evaluate

MAX_JUSTIFIED_LINES = 400
MAX_BALANCE_NODES = 300


def _money(v: float) -> str:
    return f"{v:,.2f}"


def _counterfactual(problem: PlanProblem, sol: PlanSolution, line: PurchaseLine, alt) -> dict[str, Any] | None:
    """Evalúa el plan cambiando SOLO esta línea a la oferta `alt` (misma cantidad y fecha de emisión)."""
    swapped = []
    for p in sol.purchases:
        if p is line:
            q = p.quantity
            lots = int(round(q / alt.lot)) if alt.lot > 0 else 0
            if lots * alt.lot < q - 1e-9:
                lots += 1
            qty = lots * alt.lot
            swapped.append(PurchaseLine(alt.key, alt.offer_id, alt.supplier_id, p.branch_id, p.product_id, p.t, lots,
                                        qty, arrival_period(p.t, alt.lead(p.branch_id), problem.period_days),
                                        alt.lead(p.branch_id), alt.unit_cost, alt.fixed_cost))
        else:
            swapped.append(p)
    ev = evaluate(problem, swapped, sol.productions)
    base = sol.evaluation
    return {"delta_objective": ev.total_cost - base.total_cost, "delta_unmet": ev.unmet_units - base.unmet_units,
            "feasible": ev.feasible, "violations": [v["type"] for v in ev.violations][:3]}


def _supplier_name(names: dict[int, str], sid) -> str:
    if sid is None:
        return "proveedor sin identificar"
    return names.get(int(sid), f"proveedor {int(sid)}")


def present_solution(problem: PlanProblem, sol: PlanSolution, supplier_names: dict[int, str] | None = None,
                     product_names: dict[int, str] | None = None) -> dict[str, Any]:
    supplier_names = supplier_names or {}
    product_names = product_names or {}
    ev = sol.evaluation
    R = problem.period_days
    offers = {o.key: o for o in problem.offers}
    nodes = problem.node_index()
    activity = ev.activity if ev is not None else []
    lines: list[dict[str, Any]] = []
    justified = 0
    for p in sorted(sol.purchases, key=lambda x: (x.t, x.branch_id, x.product_id)):
        o = offers[p.offer_key]
        node = nodes[(p.branch_id, p.product_id)]
        issue = problem.issue_date(p.t)
        receipt = issue + timedelta(days=p.lead_days)
        binding = []
        for a in activity:
            if not a.get("binding"):
                continue
            if a["family"] == "budget":
                b = next((x for x in problem.budgets if x.budget_id == a["ref"] and f"{x.start}..{x.end}" == a["period"]), None)
                if b is not None and (b.branch_id is None or b.branch_id == p.branch_id) and b.start <= issue <= b.end:
                    binding.append({"family": "budget", "ref": a["ref"], "period": a["period"],
                                    "used": round(a["used"], 2), "limit": round(a["limit"], 2)})
            elif a["family"] == "supplier_capacity" and a["ref"] == p.offer_key and a["period"] == p.t:
                binding.append({"family": "supplier_capacity", "ref": a["ref"], "used": round(a["used"], 4),
                                "limit": round(a["limit"], 4)})
            elif a["family"] == "storage_capacity" and a["ref"] == p.branch_id and a["period"] == p.arrival_period:
                binding.append({"family": "storage_capacity", "ref": a["ref"], "used": round(a["used"], 4),
                                "limit": round(a["limit"], 4)})
        if o.moq > EPS and p.quantity <= o.moq + o.lot - 1e-9 and p.quantity >= o.moq - 1e-9:
            binding.append({"family": "minimum_order", "ref": p.offer_key, "used": round(p.quantity, 4),
                            "limit": round(o.moq, 4)})
        justification = _justify(problem, sol, p, node, issue, supplier_names, offers) if justified < MAX_JUSTIFIED_LINES else None
        justified += 1
        lines.append({
            "line_type": "purchase", "branch_id": p.branch_id, "product_id": p.product_id,
            "product_name": product_names.get(p.product_id), "supplier_id": p.supplier_id,
            "supplier_name": supplier_names.get(p.supplier_id) if p.supplier_id is not None else None,
            "offer_id": p.offer_id, "offer_key": p.offer_key, "implicit_offer": o.implicit,
            "quantity": round(p.quantity, 4), "unit": node.unit,
            "offer_quantity": round(p.quantity / o.offer_factor, 4) if o.offer_factor else None,
            "offer_unit": o.offer_unit, "order_date": issue.isoformat(), "expected_receipt_date": receipt.isoformat(),
            "unit_cost": round(p.unit_cost, 6), "line_cost": round(p.cost, 4), "currency": problem.currency,
            "actionable_now": p.t == 0, "binding_constraints": binding, "justification": justification,
            "assumptions": {"lead_days": p.lead_days, "lead_source": o.meta.get("lead_source"), "lots": p.lots,
                            "lot_size": o.lot, "minimum_order": o.moq or None, "fixed_cost": p.fixed_cost or None,
                            "arrival_period": p.arrival_period, "issue_period": p.t,
                            "availability_rule": "disponible desde el período siguiente a la llegada si llega a mitad de período"},
        })
    recipes = {r.recipe_id: r for r in problem.recipes}
    for pl in sorted(sol.productions, key=lambda x: (x.t, x.branch_id)):
        r = recipes[pl.recipe_id]
        start = problem.issue_date(pl.t)
        lines.append({
            "line_type": "production", "branch_id": pl.branch_id, "product_id": pl.output_product_id,
            "product_name": product_names.get(pl.output_product_id), "supplier_id": None, "offer_id": None,
            "recipe_id": pl.recipe_id, "quantity": round(pl.output_quantity, 4), "unit": nodes[(pl.branch_id, pl.output_product_id)].unit,
            "offer_quantity": None, "offer_unit": None, "order_date": start.isoformat(),
            "expected_receipt_date": (start + timedelta(days=r.production_days)).isoformat(),
            "unit_cost": None, "line_cost": round(pl.cost, 4) if pl.cost else None, "currency": problem.currency,
            "actionable_now": pl.t == 0, "binding_constraints": [
                {"family": "resource_capacity", "ref": a["ref"], "used": round(a["used"], 4), "limit": round(a["limit"], 4)}
                for a in activity if a["family"] == "resource_capacity" and a["period"] == pl.t and a["binding"]
                and r.resource_id is not None and a.get("resource_id") == r.resource_id],
            "justification": None,
            "assumptions": {"batches": pl.batches, "recipe_code": r.code, "production_days": r.production_days,
                            "inputs_per_batch": r.inputs, "output_per_batch": r.output_per_batch},
        })

    shortfalls = _shortfalls(problem, ev, activity, offers) if ev is not None else []
    balance = []
    if ev is not None:
        interesting = [k for k, res in ev.nodes.items() if res.unmet.sum() > EPS or any((p.branch_id, p.product_id) == k for p in sol.purchases)]
        for k in interesting[:MAX_BALANCE_NODES]:
            node, res = nodes[k], ev.nodes[k]
            balance.append({"branch_id": k[0], "product_id": k[1], "periods": [
                {"period": t, "start": problem.period_start(t).isoformat(), "end": problem.period_end(t).isoformat(),
                 "receipts": round(float(res.receipts[t]), 4), "demand": round(float(node.demand[t]), 4),
                 "sales": round(float(res.sales[t]), 4), "unmet": round(float(res.unmet[t]), 4),
                 "closing": round(float(res.inventory[t]), 4)} for t in range(node.horizon)]})
    return {"lines": lines, "shortfalls": shortfalls, "constraints": [
        {k: (round(v, 4) if isinstance(v, float) else v) for k, v in a.items() if k != "detail"} for a in activity
        if a["family"] != "storage_unverified"], "balance": balance}


def _justify(problem, sol, line, node, issue, supplier_names, offers) -> str:
    alts = [o for o in problem.offers_for(line.branch_id, line.product_id) if o.key != line.offer_key and o.valid_on(issue)]
    chosen = offers[line.offer_key]
    name = _supplier_name(supplier_names, chosen.supplier_id)
    if not alts:
        return f"Única oferta comparable vigente para el producto ({name})."
    best = None
    for alt in alts:
        cf = _counterfactual(problem, sol, line, alt)
        if cf is None:
            continue
        if best is None or cf["delta_objective"] < best[1]["delta_objective"]:
            best = (alt, cf)
    if best is None:
        return f"Oferta de {name}."
    alt, cf = best
    aname = _supplier_name(supplier_names, alt.supplier_id)
    chosen_cheaper = chosen.unit_cost <= alt.unit_cost + 1e-12
    if not cf["feasible"]:
        reason = "no cabe en las restricciones (" + ", ".join(cf["violations"]) + ")"
        return (f"Se eligió {name} ({chosen.unit_cost:.4f}/u, plazo {line.lead_days} d) en lugar de {aname} "
                f"({alt.unit_cost:.4f}/u): la alternativa {reason}.")
    if cf["delta_objective"] >= -1e-9:
        if chosen_cheaper and chosen.lead(line.branch_id) <= alt.lead(line.branch_id):
            return (f"Menor costo puesto en bodega ({chosen.unit_cost:.4f}/u) y llega igual o antes que {aname} "
                    f"({alt.unit_cost:.4f}/u, {alt.lead(line.branch_id)} d).")
        if chosen_cheaper:
            return (f"{name} cuesta {chosen.unit_cost:.4f}/u frente a {alt.unit_cost:.4f}/u de {aname} pero llega más tarde "
                    f"({line.lead_days} d vs {alt.lead(line.branch_id)} d). Es la mejor opción del objetivo: cambiar "
                    f"empeoraría el objetivo en {_money(cf['delta_objective'])} ({cf['delta_unmet']:+.0f} u. no atendidas).")
        return (f"{name} cuesta más ({chosen.unit_cost:.4f}/u frente a {alt.unit_cost:.4f}/u de {aname}) pero llega antes "
                f"({line.lead_days} d vs {alt.lead(line.branch_id)} d): cambiar a la oferta barata empeoraría el objetivo en "
                f"{_money(cf['delta_objective'])} y dejaría {cf['delta_unmet']:+.0f} u. más sin atender.")
    return f"Oferta de {name}; la alternativa {aname} no mejora el objetivo en esta línea."


def _shortfalls(problem: PlanProblem, ev, activity, offers) -> list[dict[str, Any]]:
    out = []
    R = problem.period_days
    binding_budget = [a for a in activity if a["family"] == "budget" and a["binding"]]
    binding_cap = {(a["ref"], a["period"]) for a in activity if a["family"] == "supplier_capacity" and a["binding"]}
    binding_storage = {(a["ref"], a["period"]) for a in activity if a["family"] == "storage_capacity" and a["binding"]}
    binding_res = [a for a in activity if a["family"] == "resource_capacity" and a["binding"]]
    for k, res in ev.nodes.items():
        node = problem.node_index()[k]
        b, p = k
        offs = problem.offers_for(b, p)
        first_arrival = min((arrival_period(0, o.lead(b), R) for o in offs), default=None)
        for tau in range(node.horizon):
            if res.unmet[tau] <= 1e-9:
                continue
            reasons: list[str] = []
            if not offs and not any(r.output_product_id == p for r in problem.recipes):
                reasons.append("no_offer")
            elif first_arrival is not None and tau < first_arrival:
                reasons.append("lead_time")
            else:
                for o in offs:
                    L = o.lead(b)
                    for t in range(problem.n_periods):
                        ta = arrival_period(t, L, R)
                        if ta > tau:
                            break
                        if ta < 0 or not o.valid_on(problem.issue_date(t)):
                            continue
                        d = problem.issue_date(t)
                        for a in binding_budget:
                            bud = next((x for x in problem.budgets if x.budget_id == a["ref"]), None)
                            if bud is not None and (bud.branch_id is None or bud.branch_id == b) and bud.start <= d <= bud.end:
                                reasons.append("budget")
                        if (o.key, t) in binding_cap:
                            reasons.append("supplier_capacity")
                        if (b, ta) in binding_storage:
                            reasons.append("storage_capacity")
                if any(a["binding"] for a in binding_res):
                    reasons.append("resource_capacity")
                if not reasons:
                    reasons.append("economic")
            uniq = sorted(set(reasons))
            out.append({"branch_id": b, "product_id": p, "period": tau,
                        "period_start": problem.period_start(tau).isoformat(),
                        "period_end": problem.period_end(tau).isoformat(),
                        "demand": round(float(node.demand[tau]), 4), "unmet": round(float(res.unmet[tau]), 4),
                        "reason": uniq[0] if len(uniq) == 1 else "mixed", "reasons": uniq})
    return out


def summarize(problem: PlanProblem, sol: PlanSolution) -> dict[str, Any]:
    ev = sol.evaluation
    if ev is None:
        return {"solver_status": sol.status}
    return {
        "solver_status": sol.status, "mip_gap": sol.mip_gap, "objective_value": sol.objective_value,
        "dual_bound": sol.dual_bound, "solve_seconds": round(sol.seconds, 3), "n_vars": sol.n_vars,
        "n_constraints": sol.n_constraints, "n_integer": sol.n_integer, "currency": problem.currency,
        "total_purchase_cost": round(ev.purchase_cost + ev.fixed_cost, 4),
        "total_production_cost": round(ev.production_cost, 4),
        "total_holding_cost": round(ev.holding_cost, 4), "total_shortage_penalty": round(ev.shortage_penalty, 4),
        "total_ss_penalty": round(ev.ss_penalty, 4),
        "unmet_units_total": round(ev.unmet_units, 4), "demand_units_total": round(ev.demand_units, 4),
        "fill_rate": round(1 - ev.unmet_units / ev.demand_units, 4) if ev.demand_units > EPS else None,
        "purchase_lines": len(sol.purchases), "production_lines": len(sol.productions),
        "actionable_now_lines": sum(1 for p in sol.purchases if p.t == 0),
        "nodes": len(problem.nodes),
    }
