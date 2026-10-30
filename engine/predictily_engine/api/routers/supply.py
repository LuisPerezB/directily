"""Rutas internas del core supply y decisiones comunes (etapa 2 · P4). Requieren Bearer token (require_token).

- POST /v1/supply/runs/{run_id}/execute : ejecuta una corrida creada por Laravel (core_runs):
    supply/plan, supply/simulate, cost/forecast, scenario/evaluate.
- POST /v1/supply/causal/check          : verificación de requisitos causales (sin estimador; sin persistencia).
- GET  /v1/supply/capabilities          : versiones, estados del solver y capacidades.
"""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field

from predictily_engine.api.auth import require_token
from predictily_engine.db.session import get_engine
from predictily_engine.supply.runs import RunExecuteRequest

router = APIRouter(prefix="/v1/supply", tags=["supply"], dependencies=[Depends(require_token)])


class CausalCheckRequest(BaseModel):
    tenant_id: int
    request: dict[str, Any] = Field(default_factory=dict)


@router.get("/capabilities")
def supply_capabilities() -> dict:
    from predictily_engine.causal.contract import CONTRACT_VERSION
    from predictily_engine.supply.model import SOLVER_STATUSES
    from predictily_engine.supply.plan_runner import PLANNER_VERSION

    return {"planner_version": PLANNER_VERSION, "solver": "scipy.optimize.milp (HiGHS)", "solver_statuses": list(SOLVER_STATUSES),
            "objectives": ["min_cost", "max_margin"], "coverage_modes": ["soft", "hard"],
            "operations": ["supply/plan", "supply/simulate", "cost/forecast", "scenario/evaluate"],
            "causal": {"contract_version": CONTRACT_VERSION, "estimator_enabled": False},
            "optional": {"perishables": "not_configured sin lotes con vencimiento", "production": "not_configured sin recetas"}}


@router.post("/runs/{run_id}/execute")
def execute_run(run_id: int, body: RunExecuteRequest) -> dict:
    from predictily_engine.supply.dispatch import execute_run as _execute

    return _execute(run_id, body)


@router.post("/causal/check")
def causal_check(body: CausalCheckRequest) -> dict:
    from predictily_engine.causal.runner import causal_check as _check

    return _check(get_engine(), body.tenant_id, body.request)
