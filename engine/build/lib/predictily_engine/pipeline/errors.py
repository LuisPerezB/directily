"""Errores del motor mapeados a códigos HTTP en la API."""

from predictily_engine.pipeline.core import InsufficientDataError


class NotFoundError(LookupError):
    """Recurso inexistente o de otra empresa (404, sin revelar cuál)."""


class ConflictError(RuntimeError):
    """Ejecución concurrente del mismo run (409)."""


__all__ = ["InsufficientDataError", "NotFoundError", "ConflictError"]
