"""Orquestación del planificador: resuelve, verifica, aplica el respaldo heurístico y diagnostica.

Estados del solver que devuelve (nunca se llama «óptimo» a lo que no lo es):
- `optimal`: HiGHS probó optimalidad (brecha ≤ 1e-6).
- `feasible_with_gap`: solución factible con brecha > 1e-6 (tolerancia pedida); se informa la brecha.
- `time_limit`: se agotó el tiempo; se devuelve la mejor solución factible, NO probada óptima.
- `infeasible`: el modelo con cobertura obligatoria no tiene solución; no se devuelve plan, se explican la causa y
  las alternativas calculadas.
- `heuristic_fallback`: el solver no entregó una solución utilizable; plan del respaldo heurístico (factible).
- `not_solved`: ni el solver ni el respaldo produjeron un plan verificable.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from predictily_engine.supply.diagnosis import diagnose, explain
from predictily_engine.supply.heuristic import heuristic_plan
from predictily_engine.supply.model import PlanProblem, PlanSolution
from predictily_engine.supply.planner import build_and_solve, prepare_problem


@dataclass
class PlanOutcome:
    solution: PlanSolution
    status: str
    diagnosis: dict[str, Any] | None = None
    infeasibility: dict[str, Any] | None = None
    notes: list[str] = field(default_factory=list)
    alternative: PlanSolution | None = None  # plan de máxima cobertura factible (solo si el modelo es infactible)


def solve_plan(problem: PlanProblem, diagnose_unmet: bool = True, diagnosis_time_limit: float = 10.0) -> PlanOutcome:
    prepare_problem(problem)
    notes: list[str] = []
    sol = build_and_solve(problem)
    outcome_status = sol.status

    if sol.status == "infeasible":
        diag = diagnose(problem, time_limit=diagnosis_time_limit)
        text = explain(diag)
        infeas = {
            "message": "Con cobertura obligatoria no existe un plan que respete todas las restricciones. " + text,
            "causes": diag.get("causes", []), "structural": diag.get("structural"),
            "alternatives": diag.get("alternatives", []),
        }
        fallback = diag.get("max_coverage_solution")
        sol_out = PlanSolution(status="infeasible", detail=sol.detail, seconds=sol.seconds, n_vars=sol.n_vars,
                               n_constraints=sol.n_constraints, n_integer=sol.n_integer)
        notes.append("No se devuelve ningún plan: ninguna combinación respeta las restricciones con cobertura obligatoria.")
        return PlanOutcome(sol_out, "infeasible", diag, infeas, notes, alternative=fallback)

    usable = sol.status in ("optimal", "feasible_with_gap", "time_limit") and sol.evaluation is not None
    if usable and sol.evaluation is not None and not sol.evaluation.feasible:
        notes.append("La solución del solver no pasó la verificación independiente "
                     f"({len(sol.evaluation.violations)} violación(es)); se usa el respaldo heurístico.")
        usable = False
    if not usable:
        reason = sol.detail or sol.status
        fb = heuristic_plan(problem)
        if fb.evaluation is not None and fb.evaluation.feasible:
            fb.detail = f"Respaldo heurístico por: {reason}"
            fb.n_vars, fb.n_constraints, fb.n_integer = sol.n_vars, sol.n_constraints, sol.n_integer
            fb.notes = list(fb.notes) + [f"Estado del solver: {sol.status} ({reason})"]
            sol = fb
            outcome_status = "heuristic_fallback"
        else:
            sol.status = "not_solved"
            outcome_status = "not_solved"
            notes.append("Ni el solver ni el respaldo heurístico produjeron un plan que cumpla las restricciones.")
    diag = None
    if (diagnose_unmet and sol.evaluation is not None and sol.evaluation.unmet_units > 1e-6
            and outcome_status in ("optimal", "feasible_with_gap", "time_limit", "heuristic_fallback")
            and (problem.budgets or problem.capacities or problem.resources
                 or any(o.capacity is not None for o in problem.offers))):
        diag = diagnose(problem, time_limit=diagnosis_time_limit)
        diag.pop("max_coverage_solution", None)
    return PlanOutcome(sol, outcome_status, diag, None, notes)
