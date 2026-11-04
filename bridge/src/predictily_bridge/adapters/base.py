"""Contrato de los adaptadores de origen."""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Protocol

from predictily_bridge.config import Template


class SourceError(Exception):
    """Fallo al leer el origen. El mensaje nunca incluye credenciales."""


class ReadOnlyViolation(SourceError):
    """Se intentó ejecutar algo que no es una lectura. Es un defecto de programación o un ataque: nunca se ejecuta."""


@contextmanager
def driver_errors(action: str) -> Iterator[None]:
    """Frontera de los adaptadores: cualquier excepción del controlador (tiempo de espera de sentencia, conexión caída, permisos, error de SQL…) se vuelve ``SourceError``
    con SOLO el nombre del tipo —el texto del controlador puede traer la consulta, el usuario o la cadena de conexión—. El ciclo del agente trata ``SourceError`` como un
    error de la entidad (se informa y se reintenta en el siguiente ciclo), nunca como un fallo que derribe el proceso (revisión adversarial B-2)."""
    from predictily_bridge import sqlguard

    try:
        yield
    except SourceError:
        raise
    except sqlguard.SqlQueryRejected as e:
        raise SourceError(f"{action}: la consulta no pasó el guardia SELECT ({e.reason}).") from None
    except Exception as e:
        raise SourceError(f"{action}: {type(e).__name__}") from None


@dataclass
class Page:
    """Una página de filas en el orden total de la plantilla. ``cursor_after`` = llaves de orden de la ÚLTIMA fila entregada."""

    rows: list[dict[str, Any]]
    cursor_after: tuple[Any, ...] | None
    exhausted: bool
    extras: dict[str, Any] = field(default_factory=dict)


class SourceAdapter(Protocol):
    kind: str
    read_only_enforcement: str

    def open(self) -> None: ...

    def close(self) -> None: ...

    def describe(self) -> dict[str, Any]:
        """Versión del servidor, modo de solo lectura efectivo y advertencias (p. ej. cuenta con privilegios de escritura)."""

    def start_run(self, template: Template, known: dict[str, str] | None = None) -> dict[str, Any]:
        """Contexto serializable de una corrida nueva (los archivos fijan aquí su lista, omitiendo los ya entregados sin cambios en ``known``).
        Los SQL devuelven ``{}``."""

    def fetch_page(self, template: Template, *, since: datetime | None, after: tuple[Any, ...] | None, limit: int, context: dict[str, Any]) -> Page: ...
