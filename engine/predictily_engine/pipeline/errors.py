"""Errores del motor mapeados a códigos HTTP en la API."""

from predictily_engine.pipeline.core import InsufficientDataError


class NotFoundError(LookupError):
    """Recurso inexistente o de otra empresa (404, sin revelar cuál)."""


class InvalidConfigurationError(ValueError):
    """Configuración efectiva inválida (p. ej. clave desconocida en settings.cores.demand): 422, no se ejecuta."""


class ConflictError(RuntimeError):
    """Ejecución concurrente del mismo run (409)."""


__all__ = ["InsufficientDataError", "InvalidConfigurationError", "NotFoundError", "ConflictError"]
