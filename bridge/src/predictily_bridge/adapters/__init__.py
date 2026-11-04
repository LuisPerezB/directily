"""Adaptadores de origen. Todos son de SOLO LECTURA y entregan páginas ordenadas y reanudables por llave (``Page``)."""

from __future__ import annotations

from predictily_bridge.adapters.base import Page, SourceAdapter, SourceError
from predictily_bridge.config import Config

# Estado de verificación honesto de cada adaptador (se informa en el latido y en docs/integrations/ERP_COMPATIBILITY.md).
VERIFICATION = {
    "postgresql": "tested_with_test_database",
    "sqlite": "tested",
    "csv": "tested",
    "excel": "tested",
    "mysql": "implemented_untested",
    "sqlserver": "implemented_untested",
}


def create_adapter(config: Config, password: str | None) -> SourceAdapter:
    kind = config.source.kind
    if kind == "postgresql":
        from predictily_bridge.adapters.postgres import PostgresAdapter

        return PostgresAdapter(config.source, password, config.agent.source_timezone)
    if kind == "sqlite":
        from predictily_bridge.adapters.sqlite import SqliteAdapter

        return SqliteAdapter(config.source)
    if kind == "mysql":
        from predictily_bridge.adapters.mysql import MySqlAdapter

        return MySqlAdapter(config.source, password, config.agent.source_timezone)
    if kind == "sqlserver":
        from predictily_bridge.adapters.sqlserver import SqlServerAdapter

        return SqlServerAdapter(config.source, password, config.agent.source_timezone)
    if kind in ("csv", "excel"):
        from predictily_bridge.adapters.files import FileAdapter

        return FileAdapter(kind)
    raise SourceError(f"Tipo de fuente no soportado: {kind}")


__all__ = ["Page", "SourceAdapter", "SourceError", "VERIFICATION", "create_adapter"]
