"""MySQL / MariaDB (PyMySQL). Estado: ``implemented_untested`` — NO hay servidor MySQL en este entorno; solo pruebas con un cursor falso.

Solo lectura: ``SET SESSION TRANSACTION READ ONLY`` + ``START TRANSACTION READ ONLY`` por página + guardia SELECT. Recomendado: cuenta con
``GRANT SELECT``. Sin verificar contra un servidor real: no se declara disponible hasta probarlo (docs/integrations/ERP_COMPATIBILITY.md).
"""

from __future__ import annotations

import re
from typing import Any

from predictily_bridge.adapters.base import SourceError, driver_errors
from predictily_bridge.adapters.sqlbase import SqlAdapter
from predictily_bridge.config import SourceConfig


class MySqlAdapter(SqlAdapter):
    kind = "mysql"
    dialect = "mysql"
    paramstyle = "format"
    read_only_enforcement = "SET SESSION TRANSACTION READ ONLY + START TRANSACTION READ ONLY + guardia SELECT"
    FIXED_PATTERNS = (
        re.compile(r"SET SESSION TRANSACTION READ ONLY"),
        re.compile(r"SET SESSION MAX_EXECUTION_TIME = \d{1,7}"),
        re.compile(r"START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT"),
        re.compile(r"SELECT VERSION\(\)"),
        re.compile(r"SHOW GRANTS FOR CURRENT_USER\(\)"),
        re.compile(r"ROLLBACK"),
    )
    _WRITE_GRANT = re.compile(r"\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|FILE|SUPER)\b", re.IGNORECASE)

    def __init__(self, source: SourceConfig, password: str | None, source_timezone: str | None, connect: Any = None) -> None:
        super().__init__(source_timezone)
        self.source = source
        self._password = password
        self._connect = connect
        self._conn: Any = None

    def open(self) -> None:
        if self._conn is not None:
            return
        try:
            connect = self._connect
            if connect is None:
                import pymysql

                connect = pymysql.connect
            self._conn = connect(
                host=self.source.host, port=self.source.port or 3306, user=self.source.user, password=self._password, database=self.source.database,
                charset="utf8mb4", connect_timeout=self.source.connect_timeout_seconds, read_timeout=self.source.statement_timeout_seconds, autocommit=True,
            )
            cursor = self._conn.cursor()
            self.execute_fixed(cursor, "SET SESSION TRANSACTION READ ONLY")
            self.execute_fixed(cursor, f"SET SESSION MAX_EXECUTION_TIME = {int(self.source.statement_timeout_seconds) * 1000}")
            cursor.close()
        except ImportError:
            raise SourceError("Falta el controlador PyMySQL: pip install 'predictily-bridge[mysql]'.") from None
        except Exception as e:
            self._conn = None
            raise SourceError(f"No se pudo abrir la conexión con MySQL: {type(e).__name__}") from None

    def close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.close()
            finally:
                self._conn = None

    def _cursor(self) -> Any:
        self.open()
        cursor = self._conn.cursor()
        self.execute_fixed(cursor, "START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT")
        return cursor

    def _end_page(self, cursor: Any) -> None:
        try:
            self.execute_fixed(cursor, "ROLLBACK")
            cursor.close()
        except Exception:  # pragma: no cover
            pass

    def quote(self, identifier: str) -> str:
        return "`" + identifier.replace("`", "``") + "`"

    def limit_clause(self, limit: int) -> tuple[str, str]:
        return "", f" LIMIT {int(limit)}"

    def describe(self) -> dict[str, Any]:
        with driver_errors("Error del controlador al consultar el origen"):
            cursor = self._cursor()
            try:
                self.execute_fixed(cursor, "SELECT VERSION()")
                version = str(cursor.fetchone()[0])[:120]
                self.execute_fixed(cursor, "SHOW GRANTS FOR CURRENT_USER()")
                grants = " ".join(str(r[0]) for r in cursor.fetchall())
            finally:
                self._end_page(cursor)
            writable = bool(self._WRITE_GRANT.search(grants))
            warnings = ["La cuenta tiene privilegios de escritura. Use una cuenta con GRANT SELECT únicamente."] if writable else []
        return {"kind": self.kind, "server_version": version, "read_only_enforcement": self.read_only_enforcement, "writable_account": writable, "warnings": warnings}
