"""Diagnóstico de infactibilidad y de demanda no atendida con alternativas calculadas.

Cuando la cobertura es obligatoria (`hard`) y el modelo es infactible, o cuando un plan `soft` deja demanda sin
atender, se resuelven versiones del MISMO modelo relajando UNA familia de restricciones a la vez (presupuesto,
capacidad de proveedor, almacenamiento, recursos) y todas a la vez (causa estructural: plazos, falta de ofertas,
costos). La diferencia de demanda no atendida atribuye la causa; los requisitos (presupuesto por período,
capacidad) salen de la solución relajada. Ningún plan relajado se devuelve como plan: solo cifras de lo que habría
que cambiar. La alternativa «plan de máxima cobertura factible» sí respeta TODAS las restricciones.
"""

from __future__ import annotations

from predictily_engine.supply.model import PlanProblem, PlanSolution
from predictily_engine.supply.planner import build_and_solve

FAMILIES = {
    "budget": ("budget", "presupuesto"),
    "supplier_capacity": ("supplier_capacity", "capacidad de proveedor"),
    "storage": ("storage", "capacidad de almacenamiento"),
    "resource": ("resource", "capacidad de recursos de producción"),
}


def _present(problem: PlanProblem) -> list[str]:
    fams = []
    if problem.budgets:
        fams.append("budget")
    if any(o.capacity is not None for o in problem.offers):
        fams.append("supplier_capacity")
    if problem.capacities:
        fams.append("storage")
    if problem.resources:
        fams.append("resource")
    return fams


def diagnose(problem: PlanProblem, time_limit: float = 10.0) -> dict:
    """Atribuye la demanda no atendida a cada familia de restricciones y calcula alternativas."""
    base = build_and_solve(problem, coverage_mode="soft", time_limit=time_limit)
    if base.evaluation is None:
        return {"status": "not_diagnosed", "reason": "El modelo de máxima cobertura no tuvo solución.",
                "causes": [], "alternatives": []}
    base_unmet = base.evaluation.unmet_units
    causes = []
    requirements: dict = {}
    for fam in _present(problem):
        sol = build_and_solve(problem, relax=frozenset({fam}), coverage_mode="soft", time_limit=time_limit)
        if sol.evaluation is None:
            continue
        recovered = base_unmet - sol.evaluation.unmet_units
        entry = {"family": fam, "label": FAMILIES[fam][1], "unmet_with_constraint": round(base_unmet, 4),
                 "unmet_if_relaxed": round(sol.evaluation.unmet_units, 4), "units_recovered": round(max(recovered, 0.0), 4)}
        if recovered > 1e-6:
            if fam == "budget":
                entry["required"] = [{"budget_id": a["ref"], "period": a["period"], "needed": round(a["used"], 2),
                                      "declared": round(a["limit"], 2)}
                                     for a in sol.evaluation.activity if a["family"] == "budget"]
            elif fam == "supplier_capacity":
                entry["required"] = [{"offer": a["ref"], "period": a["period"], "needed": round(a["used"], 4),
                                      "declared": round(a["limit"], 4)}
                                     for a in sol.evaluation.activity
                                     if a["family"] == "supplier_capacity" and a["used"] > a["limit"] + 1e-6]
            elif fam == "storage":
                entry["required"] = [{"branch_id": a["ref"], "period": a["period"], "needed": round(a["used"], 4),
                                      "declared": round(a.get("declared_limit") or a["limit"], 4)}
                                     for a in sol.evaluation.activity
                                     if a["family"] == "storage_capacity" and a["used"] > (a.get("declared_limit") or a["limit"]) + 1e-6]
            elif fam == "resource":
                entry["required"] = [{"resource": a["ref"], "period": a["period"], "needed": round(a["used"], 4),
                                      "declared": round(a["limit"], 4)}
                                     for a in sol.evaluation.activity
                                     if a["family"] == "resource_capacity" and a["used"] > a["limit"] + 1e-6]
        causes.append(entry)
    structural = base_unmet
    all_relaxed = None
    fams = _present(problem)
    if fams:
        all_relaxed = build_and_solve(problem, relax=frozenset(fams), coverage_mode="soft", time_limit=time_limit)
        if all_relaxed.evaluation is not None:
            structural = all_relaxed.evaluation.unmet_units
    causes.sort(key=lambda c: -c["units_recovered"])
    structural_entry = {
        "family": "structural", "label": "plazos de entrega, falta de ofertas o costo mayor que la penalización",
        "unmet_even_without_constraints": round(structural, 4),
    }
    first_arrival = {}
    for n in problem.nodes:
        offs = problem.offers_for(n.branch_id, n.product_id)
        if offs:
            first_arrival[f"{n.branch_id}:{n.product_id}"] = min(o.lead(n.branch_id) for o in offs)
    structural_entry["earliest_possible_arrival_days"] = first_arrival if len(first_arrival) <= 50 else None
    return {
        "status": "diagnosed",
        "unmet_max_coverage": round(base_unmet, 4),
        "causes": causes,
        "structural": structural_entry,
        "alternatives": [{
            "type": "max_coverage_plan",
            "description": "Plan de máxima cobertura factible: respeta todas las restricciones y declara la demanda no atendida.",
            "unmet_units": round(base_unmet, 4),
            "total_cost": round(base.evaluation.total_cost, 2),
            "n_purchase_lines": len(base.purchases),
        }],
        "requirements": requirements,
        "max_coverage_solution": base,
    }


def explain(diag: dict) -> str:
    """Resumen en español de la causa principal."""
    if diag.get("status") != "diagnosed":
        return diag.get("reason", "No se pudo diagnosticar.")
    parts = []
    for c in diag["causes"]:
        if c["units_recovered"] > 0:
            parts.append(f"{c['units_recovered']:.0f} u. por {c['label']}")
    st = diag["structural"]["unmet_even_without_constraints"]
    if st > 1e-6:
        parts.append(f"{st:.0f} u. por {diag['structural']['label']}")
    if not parts:
        return "No se identificó una restricción que impida atender la demanda."
    return "Demanda que no puede atenderse: " + "; ".join(parts) + "."
