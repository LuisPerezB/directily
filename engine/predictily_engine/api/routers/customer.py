"""Rutas internas del core customer (etapa 2). Requieren Bearer token (require_token).

- POST /v1/customer/runs/{run_id}/execute : ejecuta una corrida `train` o `predict` creada por Laravel (core_runs).
- GET  /v1/customer/capabilities          : horizontes, definiciones y versiones del contrato.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends

from predictily_engine.api.auth import require_token
from predictily_engine.customer import FEATURE_SCHEMA_VERSION, LABEL_DEFINITION_VERSION, SUPPORTED_HORIZONS
from predictily_engine.customer.service import TARGET_DEFINITION, CustomerExecuteRequest, execute_customer_run

router = APIRouter(prefix="/v1/customer", tags=["customer"], dependencies=[Depends(require_token)])


@router.get("/capabilities")
def customer_capabilities() -> dict:
    return {"horizons": list(SUPPORTED_HORIZONS), "operations": ["train", "predict"], "feature_schema_version": FEATURE_SCHEMA_VERSION,
            "label_definition_version": LABEL_DEFINITION_VERSION, "target_definition": TARGET_DEFINITION,
            "models": {"probability": ["reference", "logistic", "lgbm_classifier"],
                       "quantity": ["reference", "lgbm_regressor:l2|l2_log1p|tweedie|gamma|poisson(solo enteros)"]},
            "calibration": ["isotonic", "platt"], "result_type": {"train": "evaluation", "predict": "forecast"}}


@router.post("/runs/{run_id}/execute")
def execute_run(run_id: int, body: CustomerExecuteRequest) -> dict:
    return execute_customer_run(run_id, body)
