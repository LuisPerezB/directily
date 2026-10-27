"""Autenticación de la API interna: Bearer token compartido con Laravel (comparación en tiempo constante)."""

from __future__ import annotations

import hmac

from fastapi import Header, HTTPException, status

from predictily_engine.config import get_settings


def require_token(authorization: str | None = Header(default=None)) -> None:
    expected = get_settings().api_token
    supplied = ""
    if authorization and authorization.lower().startswith("bearer "):
        supplied = authorization[7:].strip()
    if not expected or not hmac.compare_digest(supplied.encode(), expected.encode()):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"code": "unauthorized",
                            "message": "Token del motor inválido o ausente."})
