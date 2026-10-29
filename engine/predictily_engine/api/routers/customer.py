"""Rutas internas del core customer (etapa 2). Requieren Bearer token (require_token)."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from predictily_engine.api.auth import require_token

router = APIRouter(prefix="/v1/customer", tags=["customer"], dependencies=[Depends(require_token)])
