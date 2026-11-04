"""SQLite (biblioteca estándar). Estado: ``tested``.

Solo lectura en CUATRO capas: archivo abierto con ``mode=ro`` (URI), ``PRAGMA query_only = ON``, un AUTORIZADOR del motor que deniega toda
acción que no sea lectura (SELECT/READ/FUNCTION/RECURSIVE; tampoco ``sqlite_*``) y el guardia SELECT. Sin red, sin credenciales.
"""

from __future__ import annotations

import re
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Any
from urllib.parse import quote

from predictily_bridge.adapters.base import SourceError, driver_errors
from predictily_bridge.adapters.sqlbase import SqlAdapter
from predictily_bridge.config import SourceConfig

_ALLOWED_ACTIONS = {sqlite3.SQLITE_SELECT, sqlite3.SQLITE_READ, sqlite3.SQLITE_FUNCTION, sqlite3.SQLITE_RECURSIVE}


def authorizer(action: int, arg1: str | None, arg2: str | None, dbname: str | None, source: str | None) -> int:
    """Deniega todo lo que no sea leer tablas de usuario (sin catálogo ``sqlite_*``)."""
    if action not in _ALLOWED_ACTIONS:
        return sqlite3.SQLITE_DENY
    if action == sqlite3.SQLITE_READ and (arg1 or "").lower().startswith("sqlite_"):
        return sqlite3.SQLITE_DENY
    if action == sqlite3.SQLITE_FUNCTION and (arg2 or arg1 or "").lower() in {"load_extension", "readfile", "writefile", "edit", "fts3_tokenizer"}:
        return sqlite3.SQLITE_DENY
    return sqlite3.SQLITE_OK


class SqliteAdapter(SqlAdapter):
    kind = "sqlite"
    dialect = "sqlite"
    paramstyle = "qmark"
    read_only_enforcement = "archivo mode=ro + PRAGMA query_only + autorizador del motor + guardia SELECT"
    FIXED_PATTERNS = (re.compile(r"PRAGMA query_only = ON"), re.compile(r"SELECT sqlite_version\(\)"))

    def __init__(self, source: SourceConfig) -> None:
        super().__init__(None)
        self.source = source
        self._conn: sqlite3.Connection | None = None

    def open(self) -> None:
        if self._conn is not None:
            return
        path = Path(str(self.source.path)).expanduser()
        if not path.is_file():
            raise SourceError(f"No existe el archivo SQLite: {path}")
        try:
            conn = sqlite3.connect(self.uri(path), uri=True, timeout=float(self.source.connect_timeout_seconds), isolation_level=None)
            cursor = conn.cursor()
            self.execute_fixed(cursor, "PRAGMA query_only = ON")
            cursor.close()
            conn.set_authorizer(authorizer)  # después del PRAGMA de infraestructura: desde aquí solo lecturas
        except sqlite3.Error as e:
            raise SourceError(f"No se pudo abrir la base SQLite: {type(e).__name__}") from None
        self._conn = conn

    @staticmethod
    def uri(path: Path) -> str:
        """URI de SQLite con ``mode=ro``: el archivo se abre SOLO para lectura, sin importar lo que se ejecute después."""
        return f"file:{quote(str(path.resolve()))}?mode=ro"

    def close(self) -> None:
        if self._conn is not None:
            self._conn.close()
            self._conn = None

    def _cursor(self) -> Any:
        self.open()
        assert self._conn is not None
        return self._conn.cursor()

    def quote(self, identifier: str) -> str:
        return '"' + identifier.replace('"', '""') + '"'

    def limit_clause(self, limit: int) -> tuple[str, str]:
        return "", f" LIMIT {int(limit)}"

    def since_value(self, since: datetime) -> Any:
        return since.strftime("%Y-%m-%d %H:%M:%S")

    def describe(self) -> dict[str, Any]:
        with driver_errors("Error del controlador al consultar el origen"):
            cursor = self._cursor()
            try:
                cursor.execute("SELECT sqlite_version()")  # constante
                version = str(cursor.fetchone()[0])
            finally:
                cursor.close()
        return {"kind": self.kind, "server_version": version, "read_only_enforcement": self.read_only_enforcement, "writable_account": False, "warnings": []}
