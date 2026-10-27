"""Conexión a PostgreSQL (SQLAlchemy 2 + psycopg 3)."""

from __future__ import annotations

from sqlalchemy import Engine, create_engine

from predictily_engine.config import get_settings

_engine: Engine | None = None


def get_engine() -> Engine:
    global _engine
    if _engine is None:
        _engine = create_engine(get_settings().database_url, pool_pre_ping=True, pool_size=5, max_overflow=5)
    return _engine


def reset_engine(url: str | None = None) -> Engine:
    """Reconfigura el engine (usado en pruebas)."""
    global _engine
    if _engine is not None:
        _engine.dispose()
    _engine = create_engine(url or get_settings().database_url, pool_pre_ping=True)
    return _engine
