"""API interna del motor predictivo (solo red interna; invocada por los Jobs de Laravel).

Contrato: docs/06-contratos-integracion.md §4. OpenAPI: /openapi.json.
"""

from __future__ import annotations

import logging
import os

os.environ.setdefault("NIXTLA_ID_AS_COL", "1")

from fastapi import Depends, FastAPI, Request  # noqa: E402
from fastapi.responses import JSONResponse  # noqa: E402
from sqlalchemy import text  # noqa: E402

from predictily_engine import __version__  # noqa: E402
from predictily_engine.api.auth import require_token  # noqa: E402
from predictily_engine.api.routers import customer as customer_router  # noqa: E402
from predictily_engine.api.routers import data_core as data_core_router  # noqa: E402
from predictily_engine.api.routers import platform as platform_router  # noqa: E402
from predictily_engine.api.routers import supply as supply_router  # noqa: E402
from predictily_engine.config import FEATURE_SET_VERSION, get_settings  # noqa: E402
from predictily_engine.db.session import get_engine  # noqa: E402
from predictily_engine.models.foundation import TimesFMAdapter  # noqa: E402
from predictily_engine.models.registry import FAMILIES  # noqa: E402
from predictily_engine.pipeline.errors import ConflictError, InsufficientDataError, NotFoundError  # noqa: E402
from predictily_engine.pipeline.schemas import (  # noqa: E402
    EvaluateRequest,
    ExecuteRequest,
    ExecuteResponse,
    ReadinessRequest,
)

logging.basicConfig(level=get_settings().log_level, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

app = FastAPI(
    title="Predictily Engine",
    version=__version__,
    description="Motor predictivo interno de Predictily. Solo accesible desde la red interna (Bearer token).",
)
# Etapa 2: un router por core (docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md §5).
for _router in (data_core_router.router, customer_router.router, supply_router.router, platform_router.router):
    app.include_router(_router)


@app.exception_handler(NotFoundError)
async def _not_found(_: Request, exc: NotFoundError) -> JSONResponse:
    return JSONResponse(status_code=404, content={"code": "not_found", "message": str(exc)})


@app.exception_handler(ConflictError)
async def _conflict(_: Request, exc: ConflictError) -> JSONResponse:
    return JSONResponse(status_code=409, content={"code": "run_in_progress", "message": str(exc)})


@app.exception_handler(InsufficientDataError)
async def _insufficient(_: Request, exc: InsufficientDataError) -> JSONResponse:
    return JSONResponse(status_code=422, content={"code": "insufficient_data", "message": str(exc),
                                                  "details": exc.details})


@app.get("/health")
def health() -> dict:
    db = "ok"
    try:
        with get_engine().connect() as conn:
            conn.execute(text("SELECT 1"))
    except Exception:  # pragma: no cover - depende del entorno
        db = "error"
    available = [f for f, i in FAMILIES.items() if i.kind != "foundation" or TimesFMAdapter.available()]
    return {"status": "ok" if db == "ok" else "degraded", "version": __version__, "db": db,
            "models_available": available}


@app.get("/v1/capabilities", dependencies=[Depends(require_token)])
def capabilities() -> dict:
    return {
        "feature_set_version": FEATURE_SET_VERSION,
        "horizons": [7, 30, 90],
        "frequency": "D",
        "models": [{"family": i.family, "level": i.level, "kind": i.kind, "label": i.label,
                    "enabled": i.kind != "foundation" or TimesFMAdapter.available()} for i in FAMILIES.values()],
        "foundation_models": {"timesfm": {"enabled": TimesFMAdapter.available(), "reason": TimesFMAdapter.reason}},
        "interval_levels": [80, 95],
        "reconciliation": "bottom_up",
    }


@app.post("/v1/forecast-runs/{run_id}/execute", response_model=ExecuteResponse,
          dependencies=[Depends(require_token)])
def execute(run_id: int, body: ExecuteRequest) -> dict:
    from predictily_engine.pipeline.forecast_run import execute_forecast_run

    return execute_forecast_run(run_id, body)


@app.post("/v1/tenants/{tenant_id}/readiness", dependencies=[Depends(require_token)])
def readiness(tenant_id: int, body: ReadinessRequest | None = None) -> dict:
    from predictily_engine.pipeline.readiness import run_readiness

    return run_readiness(tenant_id, body.training_job_id if body else None)


@app.post("/v1/tenants/{tenant_id}/evaluate-actuals", dependencies=[Depends(require_token)])
def evaluate(tenant_id: int, body: EvaluateRequest | None = None) -> dict:
    from predictily_engine.evaluation.actuals import evaluate_actuals

    body = body or EvaluateRequest()
    return evaluate_actuals(tenant_id, body.training_job_id, body.lookback_runs)
