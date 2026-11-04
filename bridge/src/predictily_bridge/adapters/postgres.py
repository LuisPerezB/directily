"""PostgreSQL (psycopg 3). Estado: ``tested_with_test_database`` (base «ERP de origen» ficticia local).

Solo lectura en TRES capas: (1) el guardia SELECT sobre cada sentencia; (2) la sesión se abre con ``default_transaction_read_only=on`` y la
conexión es ``read_only`` (cada página corre en ``BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY``): el servidor rechaza cualquier escritura
aunque el guardia fallara; (3) tiempos de espera de sentencia y de transacción ociosa. Cada página termina con ROLLBACK: no deja instantáneas
abiertas. La cuenta del cliente debería tener solo ``GRANT SELECT``; ``describe()`` advierte si puede escribir.
"""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

from predictily_bridge.adapters.base import SourceError, driver_errors
from predictily_bridge.adapters.sqlbase import SqlAdapter
from predictily_bridge.config import SourceConfig

_TZ = re.compile(r"^[A-Za-z0-9_+\-/]{1,64}$")
_VERSION_SQL = "SHOW server_version"
_PRIVILEGE_SQL = (
    # Privilegios EFECTIVOS de la cuenta, también los heredados por pertenencia a roles (has_table_privilege/has_database_privilege/pg_has_role los resuelven),
    # y los roles predefinidos de escritura de PostgreSQL 14+ (la consulta no falla en versiones anteriores: busca el rol por nombre).
    "SELECT r.rolsuper AS superuser, has_database_privilege(current_user, current_database(), 'CREATE') AS can_create, "
    "(EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p') "
    "AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_toast%' "
    "AND (has_table_privilege(c.oid, 'INSERT') OR has_table_privilege(c.oid, 'UPDATE') OR has_table_privilege(c.oid, 'DELETE') OR has_table_privilege(c.oid, 'TRUNCATE'))) "
    "OR coalesce((SELECT bool_or(pg_has_role(current_user, p.oid, 'member')) FROM pg_roles p WHERE p.rolname = 'pg_write_all_data'), false)) AS can_write "
    "FROM pg_roles r WHERE r.rolname = current_user"
)


class PostgresAdapter(SqlAdapter):
    kind = "postgresql"
    dialect = "pgsql"
    paramstyle = "pyformat"
    read_only_enforcement = "default_transaction_read_only + BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY + guardia SELECT"
    FIXED_PATTERNS = (re.compile(re.escape(_VERSION_SQL)), re.compile(re.escape(_PRIVILEGE_SQL)))

    def __init__(self, source: SourceConfig, password: str | None, source_timezone: str | None, connect: Any = None) -> None:
        super().__init__(source_timezone)
        self.source = source
        self._password = password
        self._connect = connect
        self._conn: Any = None

    def open(self) -> None:
        if self._conn is not None:
            return
        tz = self.source_timezone
        if tz is not None and not _TZ.match(tz):
            raise SourceError("Zona horaria de la fuente no válida.")
        timeout_ms = int(self.source.statement_timeout_seconds) * 1000
        options = f"-c default_transaction_read_only=on -c statement_timeout={timeout_ms} -c lock_timeout=5000 -c idle_in_transaction_session_timeout={timeout_ms + 60_000}"
        if tz:
            options += f" -c TimeZone={tz}"
        try:
            connect = self._connect
            if connect is None:
                import psycopg

                connect = psycopg.connect
            self._conn = connect(
                host=self.source.host, port=self.source.port or 5432, dbname=self.source.database, user=self.source.user, password=self._password,
                sslmode=self.source.sslmode, connect_timeout=self.source.connect_timeout_seconds, application_name="predictily-bridge", options=options,
            )
            self._conn.read_only = True
            self._conn.autocommit = False
        except ImportError:
            raise SourceError("Falta el controlador psycopg: pip install 'predictily-bridge[postgres]'.") from None
        except Exception as e:  # el mensaje del controlador no incluye la contraseña, pero se acota y se deja al filtro de registro
            self._conn = None
            raise SourceError(f"No se pudo abrir la conexión con PostgreSQL: {type(e).__name__}") from None

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
            self._conn.rollback()  # termina la transacción de lectura: libera la instantánea
        except Exception:  # pragma: no cover
            pass

    def quote(self, identifier: str) -> str:
        return '"' + identifier.replace('"', '""') + '"'

    def limit_clause(self, limit: int) -> tuple[str, str]:
        return "", f" LIMIT {int(limit)}"

    def since_value(self, since: datetime) -> Any:
        return since.replace(tzinfo=None)  # hora local de la fuente, ingenua (igual que el literal del conector PHP)

    def describe(self) -> dict[str, Any]:
        with driver_errors("Error del controlador al consultar el origen"):
            cursor = self._cursor()
            try:
                self.execute_fixed(cursor, _VERSION_SQL)
                version = str(cursor.fetchone()[0])[:120]
                self.execute_fixed(cursor, _PRIVILEGE_SQL)
                row = cursor.fetchone()
            finally:
                self._end_page(cursor)
            superuser, can_create, can_write = (bool(row[0]), bool(row[1]), bool(row[2])) if row else (False, False, False)
            warnings = []
            if superuser:
                warnings.append("La cuenta es superusuario de PostgreSQL. Use una cuenta de solo lectura (GRANT SELECT).")
            elif can_write or can_create:
                warnings.append("La cuenta tiene privilegios de escritura/creación. El agente solo lee (transacción READ ONLY), pero se recomienda una cuenta con GRANT SELECT únicamente.")
        return {"kind": self.kind, "server_version": version, "read_only_enforcement": self.read_only_enforcement, "writable_account": bool(superuser or can_write or can_create), "warnings": warnings}
