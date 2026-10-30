"""Rutas internas del core platform (etapa 2). Requieren Bearer token (require_token).

- GET  /v1/platform/effective-config?tenant_id&core   configuración efectiva (core → perfil sectorial → empresa) con procedencia por clave
- POST /v1/platform/validate-config                   validación en seco de valores de empresa (claves desconocidas, tipos, rangos)
- GET  /v1/platform/profiles                          perfiles sectoriales registrados y sus proveedores
- GET  /v1/platform/tenants/{tenant_id}/extensions    perfil y proveedores que aplican a una empresa (vacío si no tiene perfil)
"""

from __future__ import annotations

import json
from typing import Any

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from sqlalchemy import text

from predictily_engine.api.auth import require_token
from predictily_engine.db.session import get_engine
from predictily_engine.pipeline.errors import NotFoundError
from predictily_engine.platform.config import CORE_DEFAULTS, ConfigValidationError, effective_config_record
from predictily_engine.platform.extensions import get_profile, providers_for, registered_profiles

router = APIRouter(prefix="/v1/platform", tags=["platform"], dependencies=[Depends(require_token)])

PRECEDENCE = ["core", "profile", "tenant"]


class ValidateConfigRequest(BaseModel):
    tenant_id: int
    core: str
    overrides: dict[str, Any] = Field(default_factory=dict)


def _tenant(tenant_id: int) -> tuple[dict[str, Any], str | None]:
    """Lee SOLO la fila de la empresa pedida: la configuración de otra empresa nunca interviene."""
    with get_engine().connect() as conn:
        row = conn.execute(text("SELECT settings, sector FROM tenants WHERE id = :t AND deleted_at IS NULL"), {"t": tenant_id}).mappings().first()
    if row is None:
        raise NotFoundError(f"Empresa {tenant_id} no encontrada.")
    settings = row["settings"]
    if isinstance(settings, str):
        settings = json.loads(settings or "{}")
    return settings or {}, row["sector"]


def _invalid(exc: ConfigValidationError) -> JSONResponse:
    return JSONResponse(status_code=422, content={"code": "invalid_config", "message": "La configuración contiene claves desconocidas o valores no válidos.", "errors": exc.errors})


def _payload(tenant_id: int, core: str, settings: dict[str, Any], sector: str | None, overrides: dict[str, Any] | None = None) -> dict[str, Any]:
    record = effective_config_record(core, settings, sector, overrides)
    return {
        "tenant_id": tenant_id,
        "core": core,
        "sector": sector,
        "profile": record["profile"],
        "config_version": record["config_version"],
        "precedence": PRECEDENCE,
        "config": record["effective_config"],
        "provenance": record["provenance"],
        "keys": [{"key": k, "value": v, "source": record["provenance"][k]} for k, v in record["effective_config"].items()],
    }


@router.get("/effective-config")
def effective_config(tenant_id: int, core: str):
    if core not in CORE_DEFAULTS:
        return JSONResponse(status_code=422, content={"code": "unknown_core", "message": f"Core desconocido: {core}.", "errors": [{"key": "core", "code": "unknown_core", "message": f"Valores válidos: {sorted(CORE_DEFAULTS)}"}]})
    settings, sector = _tenant(tenant_id)
    try:
        return _payload(tenant_id, core, settings, sector)
    except ConfigValidationError as exc:
        return _invalid(exc)


@router.post("/validate-config")
def validate_config(body: ValidateConfigRequest):
    if body.core not in CORE_DEFAULTS:
        return JSONResponse(status_code=422, content={"code": "unknown_core", "message": f"Core desconocido: {body.core}.", "errors": []})
    settings, sector = _tenant(body.tenant_id)
    try:
        return _payload(body.tenant_id, body.core, settings, sector, body.overrides)
    except ConfigValidationError as exc:
        return _invalid(exc)


@router.get("/profiles")
def profiles():
    return {"profiles": [p.describe() for p in registered_profiles()]}


@router.get("/tenants/{tenant_id}/extensions")
def tenant_extensions(tenant_id: int):
    _, sector = _tenant(tenant_id)
    profile = get_profile(sector)
    return {
        "tenant_id": tenant_id,
        "sector": sector,
        "profile": None if profile is None else {"code": profile.code, "version": profile.version, "description": profile.description, "synthetic": profile.synthetic},
        "providers": [p.describe() for p in providers_for(sector)],
        "note": None if profile is not None else "La empresa no tiene perfil sectorial: usa solo los valores del core.",
    }
