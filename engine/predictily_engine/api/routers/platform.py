"""Rutas internas del core platform (etapa 2). Requieren Bearer token (require_token)."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from predictily_engine.api.auth import require_token

router = APIRouter(prefix="/v1/platform", tags=["platform"], dependencies=[Depends(require_token)])
