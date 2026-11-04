"""Base común de los adaptadores SQL: paginación por llave sobre la plantilla validada y ejecución SOLO de SELECT.

Qué se ejecuta contra el origen (y NADA más):
 1. la plantilla (SELECT validado por el guardia) envuelta como subconsulta con filtro de llave, ``ORDER BY`` y límite que genera el agente a
    partir de identificadores validados (``^[A-Za-z_]\\w*$``) y parámetros ligados; el texto envuelto se VUELVE a validar con el guardia;
 2. un conjunto cerrado de sentencias de infraestructura (``FIXED_PATTERNS``: modo de solo lectura de la sesión, tiempos de espera, consulta
    de privilegios). Cualquier otra sentencia lanza ``ReadOnlyViolation`` antes de llegar al controlador.
Los valores (``:since``, llaves) viajan como PARÁMETROS del controlador, nunca concatenados en el texto.
"""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

from predictily_bridge import sqlguard
from predictily_bridge.adapters.base import Page, ReadOnlyViolation, SourceError, driver_errors
from predictily_bridge.config import Template

_SELECT_START = re.compile(r"^\s*(select|with)\b", re.IGNORECASE)
FULL_LOAD_SINCE = datetime(1900, 1, 1)


class SqlAdapter:
    kind = ""
    dialect = ""
    paramstyle = "qmark"
    read_only_enforcement = ""
    #: Patrones del conjunto cerrado de sentencias de infraestructura permitidas (sin datos del usuario).
    FIXED_PATTERNS: tuple[re.Pattern[str], ...] = ()

    def __init__(self, source_timezone: str | None) -> None:
        self.source_timezone = source_timezone
        self.executed: list[str] = []  # trazabilidad de lo ejecutado (pruebas)

    # ── a implementar por cada motor ──
    def quote(self, identifier: str) -> str:
        raise NotImplementedError

    def limit_clause(self, limit: int) -> tuple[str, str]:
        """(prefijo tras SELECT, sufijo al final): ``("", " LIMIT n")`` o ``(" TOP (n)", "")``."""
        raise NotImplementedError

    def since_value(self, since: datetime) -> Any:
        return since

    def _cursor(self) -> Any:
        raise NotImplementedError

    def describe(self) -> dict[str, Any]:
        raise NotImplementedError

    # ── ejecución ──
    def start_run(self, template: Template, known: dict[str, str] | None = None) -> dict[str, Any]:
        return {}

    def execute_fixed(self, cursor: Any, sql: str, params: tuple[Any, ...] = ()) -> None:
        if not any(p.fullmatch(sql) for p in self.FIXED_PATTERNS):
            raise ReadOnlyViolation("Sentencia de infraestructura no permitida en el agente de solo lectura.")
        self.executed.append(sql)
        cursor.execute(sql, params) if params else cursor.execute(sql)

    def execute_query(self, cursor: Any, query: sqlguard.ValidatedQuery, values: dict[str, Any]) -> None:
        sql, args = query.bind(self.paramstyle, values)
        if not _SELECT_START.match(sql):
            raise ReadOnlyViolation("El texto a ejecutar no es un SELECT.")
        self.executed.append(sql)
        cursor.execute(sql, args)

    def build(self, template: Template, has_after: bool, limit: int) -> sqlguard.ValidatedQuery:
        """Envuelve la plantilla: ``SELECT * FROM (<plantilla>) AS bridge_src [WHERE llave > cursor] ORDER BY llaves LIMIT n+1`` (re-validado)."""
        assert template.sql is not None
        inner = sqlguard.validate(template.sql, self.dialect)
        inner_text = "".join(p if isinstance(p, str) else f":{p.name}" for p in inner.parts)
        keys = list(template.order_by)
        quoted = [self.quote(k) for k in keys]
        where = ""
        if has_after:
            clauses = []
            for i in range(len(keys)):
                eq = [f"{quoted[j]} = :ks{j}" for j in range(i)]
                clauses.append("(" + " AND ".join([*eq, f"{quoted[i]} > :ks{i}"]) + ")")
            where = " WHERE " + " OR ".join(clauses)
        top, tail = self.limit_clause(limit + 1)  # una fila de más: detecta el final sin una consulta extra
        text = f"SELECT{top} * FROM ({inner_text}) AS bridge_src{where} ORDER BY {', '.join(quoted)}{tail}"
        return sqlguard.validate(text, self.dialect, allowed_params=frozenset({sqlguard.SINCE_PARAM, *(f"ks{i}" for i in range(len(keys)))}))

    def fetch_page(self, template: Template, *, since: datetime | None, after: tuple[Any, ...] | None, limit: int, context: dict[str, Any]) -> Page:
        if after is not None and len(after) != len(template.order_by):
            raise SourceError("El cursor no corresponde a las llaves de orden de la plantilla (¿cambió la plantilla?).")
        with driver_errors("Error del controlador al leer el origen"):
            query = self.build(template, after is not None, limit)
            values: dict[str, Any] = {sqlguard.SINCE_PARAM: self.since_value(since or FULL_LOAD_SINCE)}
            for i, value in enumerate(after or ()):
                values[f"ks{i}"] = value
            cursor = self._cursor()
            try:
                self.execute_query(cursor, query, values)
                fetched = cursor.fetchmany(limit + 1)
                names = [d[0] for d in cursor.description]
            finally:
                self._end_page(cursor)
        rows = [dict(zip(names, record, strict=True)) for record in fetched]
        missing = [k for k in template.order_by if rows and k not in rows[0]]
        if missing:
            raise SourceError(f"La plantilla no devuelve las columnas de orden: {', '.join(missing)}.")
        exhausted = len(rows) <= limit
        rows = rows[:limit]
        cursor_after: tuple[Any, ...] | None = None
        if rows:
            cursor_after = tuple(rows[-1][k] for k in template.order_by)
            if any(v is None for v in cursor_after):
                raise SourceError("Una llave de orden devolvió NULL: las columnas de order_by deben ser NOT NULL para reanudar sin repetir lotes.")
        return Page(rows, cursor_after, exhausted)

    def _end_page(self, cursor: Any) -> None:
        """Cierra el cursor y termina la transacción de lectura de la página (libera la instantánea)."""
        try:
            cursor.close()
        except Exception:  # pragma: no cover
            pass
