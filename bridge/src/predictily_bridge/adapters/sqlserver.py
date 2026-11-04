"""SQL Server (pyodbc + ODBC Driver 18). Estado: ``implemented_untested`` — NO hay SQL Server en este entorno; solo pruebas con un cursor falso.

SQL Server NO ofrece transacciones de solo lectura: la garantía descansa en (1) el guardia SELECT (con los vetos extra de T-SQL), (2) la
cadena de conexión con ``ApplicationIntent=ReadOnly`` (enruta a una réplica de lectura si existe), (3) una cuenta limitada a ``db_datareader``;
``describe()`` consulta los permisos y advierte (o, con ``require_readonly_account``, el agente se niega a extraer) si la cuenta puede escribir.
Las plantillas deben ser SELECT simples (SQL Server no admite ``WITH``/``ORDER BY`` dentro de la subconsulta que las envuelve): use vistas.
"""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

from predictily_bridge.adapters.base import SourceError, driver_errors
from predictily_bridge.adapters.sqlbase import SqlAdapter
from predictily_bridge.config import SourceConfig

_PERMISSIONS_SQL = (
    "SELECT CASE WHEN HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'INSERT') = 1 OR HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'UPDATE') = 1 "
    "OR IS_MEMBER('db_owner') = 1 THEN 1 ELSE 0 END"
)


def odbc_value(value: str) -> str:
    """Valor de una cadena de conexión ODBC: entre llaves, con ``}`` duplicada, si contiene caracteres especiales (evita inyectar atributos)."""
    if re.fullmatch(r"[A-Za-z0-9_.\-]+", value):
        return value
    return "{" + value.replace("}", "}}") + "}"


class SqlServerAdapter(SqlAdapter):
    kind = "sqlserver"
    dialect = "sqlsrv"
    paramstyle = "qmark"
    read_only_enforcement = "ApplicationIntent=ReadOnly + cuenta db_datareader (verificada) + guardia SELECT"
    FIXED_PATTERNS = (
        re.compile(r"SET LOCK_TIMEOUT 5000"),
        re.compile(r"SET NOCOUNT ON"),
        re.compile(r"SET TRANSACTION ISOLATION LEVEL READ COMMITTED"),
        re.compile(r"SELECT @@VERSION"),
        re.compile(re.escape(_PERMISSIONS_SQL)),
    )

    def __init__(self, source: SourceConfig, password: str | None, source_timezone: str | None, connect: Any = None) -> None:
        super().__init__(source_timezone)
        self.source = source
        self._password = password
        self._connect = connect
        self._conn: Any = None

    def connection_string(self) -> str:
        s = self.source
        server = f"{s.host},{s.port or 1433}"
        parts = [
            f"DRIVER={{{s.driver}}}", f"SERVER={odbc_value(server)}", f"DATABASE={odbc_value(str(s.database))}", f"UID={odbc_value(str(s.user))}",
            f"PWD={odbc_value(self._password or '')}", f"Encrypt={'yes' if s.encrypt else 'no'}", f"TrustServerCertificate={'yes' if s.trust_server_certificate else 'no'}",
            "ApplicationIntent=ReadOnly", f"LoginTimeout={int(s.connect_timeout_seconds)}",
        ]
        return ";".join(parts)

    def open(self) -> None:
        if self._conn is not None:
            return
        try:
            connect = self._connect
            if connect is None:
                import pyodbc

                connect = pyodbc.connect
            self._conn = connect(self.connection_string(), autocommit=False, timeout=int(self.source.statement_timeout_seconds))
            cursor = self._conn.cursor()
            for sql in ("SET LOCK_TIMEOUT 5000", "SET NOCOUNT ON", "SET TRANSACTION ISOLATION LEVEL READ COMMITTED"):
                self.execute_fixed(cursor, sql)
            cursor.close()
        except ImportError:
            raise SourceError("Falta el controlador pyodbc (y el ODBC Driver 18 for SQL Server): pip install 'predictily-bridge[sqlserver]'.") from None
        except Exception as e:
            self._conn = None
            raise SourceError(f"No se pudo abrir la conexión con SQL Server: {type(e).__name__}") from None

    def close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.rollback()
                self._conn.close()
            finally:
                self._conn = None

    def _cursor(self) -> Any:
        self.open()
        return self._conn.cursor()

    def _end_page(self, cursor: Any) -> None:
        super()._end_page(cursor)
        try:
            self._conn.rollback()
        except Exception:  # pragma: no cover
            pass

    def quote(self, identifier: str) -> str:
        return "[" + identifier.replace("]", "]]") + "]"

    def limit_clause(self, limit: int) -> tuple[str, str]:
        return f" TOP ({int(limit)})", ""

    def since_value(self, since: datetime) -> Any:
        return since.replace(tzinfo=None)  # pyodbc la envía tipada (datetime2): sin ambigüedad de formato regional

    def describe(self) -> dict[str, Any]:
        with driver_errors("Error del controlador al consultar el origen"):
            cursor = self._cursor()
            try:
                self.execute_fixed(cursor, "SELECT @@VERSION")
                version = str(cursor.fetchone()[0])[:120]
                self.execute_fixed(cursor, _PERMISSIONS_SQL)
                writable = int(cursor.fetchone()[0]) == 1
            finally:
                self._end_page(cursor)
            warnings = ["La cuenta puede escribir en la base de datos. SQL Server no ofrece transacciones de solo lectura: use una cuenta db_datareader."] if writable else []
        return {"kind": self.kind, "server_version": version, "read_only_enforcement": self.read_only_enforcement, "writable_account": writable, "warnings": warnings}
