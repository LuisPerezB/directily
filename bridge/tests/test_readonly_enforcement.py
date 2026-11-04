"""SOLO LECTURA en el origen: lo que se ejecuta, lo que el motor permite y lo que el guardia rechaza (SQLite real; SQL Server y MySQL con cursor falso)."""

from __future__ import annotations

import hashlib
import re
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Any

import pytest

from fakes import SALES_SQL, FakeServer, make_erp
from helpers import Clock, make_agent, sqlite_config
from predictily_bridge import sqlguard
from predictily_bridge.adapters.base import ReadOnlyViolation, SourceError
from predictily_bridge.adapters.mysql import MySqlAdapter
from predictily_bridge.adapters.sqlbase import SqlAdapter
from predictily_bridge.adapters.sqlite import SqliteAdapter, authorizer
from predictily_bridge.adapters.sqlserver import SqlServerAdapter, odbc_value
from predictily_bridge.config import SourceConfig, Template

SELECT_OR_FIXED = re.compile(r"^\s*(SELECT|WITH|PRAGMA query_only = ON|SET |START TRANSACTION READ ONLY|ROLLBACK|SHOW )", re.IGNORECASE)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


@pytest.fixture
def erp(tmp_path: Path) -> Path:
    path = tmp_path / "erp.sqlite"
    make_erp(path).close()
    return path


def template(**kw: Any) -> Template:
    return Template(id="t.sales", entity="sales", version=1, kind=kw.pop("kind", "sqlite"), sql=kw.pop("sql", SALES_SQL), recorded_at_column="source_recorded_at",
                    order_by=("document_number", "line_number"), **kw)


# ── SQLite real ──
def test_extraction_executes_only_select_and_leaves_the_source_untouched(erp: Path, home: Path) -> None:
    before, mtime = sha(erp), erp.stat().st_mtime_ns
    server = FakeServer()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, Clock())
    agent.run_once()
    assert len(server.rows()) == 24
    executed = agent.adapter.executed  # type: ignore[attr-defined]
    assert executed and all(SELECT_OR_FIXED.match(s) for s in executed), executed
    assert not any(re.search(r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|ATTACH)\b", s, re.IGNORECASE) for s in executed if not s.startswith("PRAGMA"))
    assert sha(erp) == before and erp.stat().st_mtime_ns == mtime, "el archivo del ERP no cambió"


def test_sqlite_engine_refuses_writes_even_if_the_guard_were_bypassed(erp: Path) -> None:
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(erp)))
    adapter.open()
    conn = adapter._conn  # el controlador crudo, saltándose a propósito el guardia
    for statement in ("INSERT INTO erp_facturas VALUES (99,'x','x','x','x','x','x')", "DELETE FROM erp_facturas", "DROP TABLE erp_facturas", "CREATE TABLE x (a)",
                      "UPDATE erp_facturas SET estado = 'X'", "ATTACH DATABASE ':memory:' AS m", "PRAGMA query_only = OFF", "SELECT load_extension('x')", "SELECT * FROM sqlite_master"):
        with pytest.raises(sqlite3.Error):
            conn.execute(statement)
    adapter.close()
    con = sqlite3.connect(erp)
    assert con.execute("SELECT COUNT(*) FROM erp_facturas").fetchone()[0] == 12
    con.close()


def test_the_file_is_opened_with_mode_ro_so_even_a_bare_connection_cannot_write(erp: Path) -> None:
    bare = sqlite3.connect(SqliteAdapter.uri(erp), uri=True)  # sin autorizador ni PRAGMA: solo el modo del archivo
    with pytest.raises(sqlite3.OperationalError, match="readonly"):
        bare.execute("CREATE TABLE y (a)")
    with pytest.raises(sqlite3.OperationalError, match="readonly"):
        bare.execute("DELETE FROM erp_facturas")
    bare.close()


def test_authorizer_allows_reads_and_denies_everything_else() -> None:
    assert authorizer(sqlite3.SQLITE_SELECT, None, None, None, None) == sqlite3.SQLITE_OK
    assert authorizer(sqlite3.SQLITE_READ, "erp_facturas", "id", "main", None) == sqlite3.SQLITE_OK
    assert authorizer(sqlite3.SQLITE_READ, "sqlite_master", "sql", "main", None) == sqlite3.SQLITE_DENY
    for action in (sqlite3.SQLITE_INSERT, sqlite3.SQLITE_UPDATE, sqlite3.SQLITE_DELETE, sqlite3.SQLITE_CREATE_TABLE, sqlite3.SQLITE_DROP_TABLE, sqlite3.SQLITE_ATTACH, sqlite3.SQLITE_PRAGMA, sqlite3.SQLITE_TRANSACTION):
        assert authorizer(action, "x", None, None, None) == sqlite3.SQLITE_DENY


def test_adapters_refuse_statements_that_are_neither_a_validated_select_nor_fixed_infrastructure(erp: Path) -> None:
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(erp)))
    adapter.open()
    cursor = adapter._cursor()
    for sql in ("DROP TABLE erp_facturas", "INSERT INTO t VALUES (1)", "PRAGMA query_only = OFF", "SELECT 1; DROP TABLE x", "VACUUM"):
        with pytest.raises(ReadOnlyViolation):
            adapter.execute_fixed(cursor, sql)
    # Un objeto ValidatedQuery cuyo texto no sea SELECT tampoco se ejecuta (defensa final).
    forged = sqlguard.ValidatedQuery(("DELETE FROM erp_facturas",), False)
    with pytest.raises(ReadOnlyViolation):
        adapter.execute_query(cursor, forged, {})
    assert adapter.executed == ["PRAGMA query_only = ON"]
    adapter.close()


def test_a_write_template_never_reaches_the_driver(erp: Path) -> None:
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(erp)))
    for sql in ("UPDATE erp_facturas SET estado = 'X'", "SELECT 1; DELETE FROM erp_facturas", "SELECT * FROM erp_facturas FOR UPDATE", "SELECT * FROM sqlite_master"):
        with pytest.raises(sqlguard.SqlQueryRejected):
            adapter.build(template(sql=sql), False, 10)


def test_keyset_predicate_is_parameterised_and_validated(erp: Path) -> None:
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(erp)))
    query = adapter.build(template(), True, 100)
    sql, args = query.bind("qmark", {"since": "2026-01-01 00:00:00", "ks0": "001-001-000000005", "ks1": 2})
    assert 'ORDER BY "document_number", "line_number" LIMIT 101' in sql
    assert '("document_number" > ?) OR ("document_number" = ? AND "line_number" > ?)' in sql
    assert args == ["2026-01-01 00:00:00", "001-001-000000005", "001-001-000000005", 2]
    assert "000000005" not in sql, "los valores nunca se concatenan al SQL"


def test_cursor_for_another_template_shape_is_refused(erp: Path) -> None:
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(erp)))
    with pytest.raises(SourceError, match="cursor"):
        adapter.fetch_page(template(), since=None, after=("solo-una-llave",), limit=5, context={})


def test_null_order_keys_are_refused(tmp_path: Path) -> None:
    path = tmp_path / "n.sqlite"
    con = sqlite3.connect(path)
    con.executescript("CREATE TABLE v (k TEXT, m TEXT); INSERT INTO v VALUES (NULL, '2026-01-01 00:00:00');")
    con.close()
    t = Template(id="t.sales", entity="sales", version=1, kind="sqlite", sql="SELECT k AS document_number, m AS source_recorded_at FROM v WHERE m >= :since", recorded_at_column="source_recorded_at", order_by=("document_number",))
    adapter = SqliteAdapter(SourceConfig(kind="sqlite", path=str(path)))
    with pytest.raises(SourceError, match="NULL"):
        adapter.fetch_page(t, since=None, after=None, limit=5, context={})


# ── SQL Server (cursor falso: SIN servidor real) ──
class FakeCursor:
    def __init__(self, log: list[tuple[str, Any]], rows: list[tuple[Any, ...]] | None = None, columns: list[str] | None = None) -> None:
        self.log, self._rows, self.description = log, rows or [], [(c,) for c in (columns or [])]

    def execute(self, sql: str, params: Any = None) -> "FakeCursor":
        self.log.append((sql, params))
        return self

    def fetchmany(self, n: int) -> list[tuple[Any, ...]]:
        return self._rows[:n]

    def fetchone(self) -> tuple[Any, ...]:
        return self._rows[0] if self._rows else (0,)

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows

    def close(self) -> None:
        pass


class FakeConnection:
    def __init__(self, log: list[tuple[str, Any]], rows=None, columns=None) -> None:
        self.log, self.rows, self.columns, self.closed, self.rollbacks = log, rows, columns, False, 0

    def cursor(self) -> FakeCursor:
        return FakeCursor(self.log, self.rows, self.columns)

    def rollback(self) -> None:
        self.rollbacks += 1

    def close(self) -> None:
        self.closed = True


def sqlserver(log: list, rows=None, columns=None, **source) -> tuple[SqlServerAdapter, dict[str, Any]]:
    seen: dict[str, Any] = {}

    def connect(dsn: str, **kw: Any) -> FakeConnection:
        seen.update(dsn=dsn, **kw)
        return FakeConnection(log, rows, columns)

    cfg = SourceConfig(kind="sqlserver", host="10.0.0.5", port=1433, database="ERP", user="lector", password_secret="pw", **source)
    return SqlServerAdapter(cfg, "p;w}d", "America/Guayaquil", connect=connect), seen


def test_sqlserver_connection_is_read_intent_and_never_autocommits() -> None:
    log: list = []
    adapter, seen = sqlserver(log)
    adapter.open()
    assert "ApplicationIntent=ReadOnly" in seen["dsn"] and "Encrypt=yes" in seen["dsn"] and "TrustServerCertificate=no" in seen["dsn"]
    assert seen["autocommit"] is False
    assert "PWD={p;w}}d}" in seen["dsn"], "la contraseña con ; y } se escapa para no inyectar atributos ODBC"
    assert [s for s, _ in log] == ["SET LOCK_TIMEOUT 5000", "SET NOCOUNT ON", "SET TRANSACTION ISOLATION LEVEL READ COMMITTED"]


def test_sqlserver_pages_use_top_brackets_typed_parameters_and_only_select() -> None:
    log: list = []
    rows = [("A-1", 1, datetime(2026, 1, 1, 10, 0, 0)), ("A-2", 1, datetime(2026, 1, 1, 11, 0, 0)), ("A-3", 1, datetime(2026, 1, 1, 12, 0, 0))]
    adapter, _ = sqlserver(log, rows, ["document_number", "line_number", "source_recorded_at"])
    t = Template(id="t.sales", entity="sales", version=1, kind="sqlserver", sql="SELECT n AS document_number, l AS line_number, m AS source_recorded_at FROM dbo.v WHERE m >= :since",
                 recorded_at_column="source_recorded_at", order_by=("document_number", "line_number"))
    page = adapter.fetch_page(t, since=datetime(2026, 1, 1), after=("A-0", 9), limit=2, context={})
    assert [r["document_number"] for r in page.rows] == ["A-1", "A-2"] and not page.exhausted and page.cursor_after == ("A-2", 1)
    page_sql = [s for s, _ in log if s.upper().startswith("SELECT TOP")]
    assert len(page_sql) == 1
    assert "SELECT TOP (3) * FROM (" in page_sql[0] and "ORDER BY [document_number], [line_number]" in page_sql[0] and "[document_number] > ?" in page_sql[0]
    params = [p for s, p in log if s.upper().startswith("SELECT TOP")][0]
    assert params == [datetime(2026, 1, 1), "A-0", "A-0", 9] and isinstance(params[0], datetime), "la fecha viaja tipada"
    assert all(SELECT_OR_FIXED.match(s) for s, _ in log)


def test_sqlserver_refuses_non_fixed_statements_and_dangerous_templates() -> None:
    log: list = []
    adapter, _ = sqlserver(log)
    adapter.open()
    for sql in ("EXEC xp_cmdshell 'dir'", "DROP TABLE t", "UPDATE t SET a = 1", "SELECT 1; SELECT 2"):
        with pytest.raises(ReadOnlyViolation):
            adapter.execute_fixed(adapter._cursor(), sql)
    t = Template(id="t.sales", entity="sales", version=1, kind="sqlserver", sql="SELECT 1 EXEC sp_who", order_by=("a",))
    with pytest.raises(sqlguard.SqlQueryRejected):
        adapter.build(t, False, 5)
    t2 = Template(id="t.sales", entity="sales", version=1, kind="sqlserver", sql="SELECT * FROM OPENROWSET('x','y','z')", order_by=("a",))
    with pytest.raises(sqlguard.SqlQueryRejected):
        adapter.build(t2, False, 5)


def test_sqlserver_describe_flags_writable_accounts() -> None:
    log: list = []
    adapter, _ = sqlserver(log, [(1,)])
    info = adapter.describe()
    assert info["writable_account"] is True and "db_datareader" in info["warnings"][0]
    adapter2, _ = sqlserver([], [(0,)])
    assert adapter2.describe()["writable_account"] is False


def test_odbc_value_escaping() -> None:
    assert odbc_value("simple.host-1") == "simple.host-1"
    assert odbc_value("a;b") == "{a;b}" and odbc_value("a}b") == "{a}}b}" and odbc_value("x=y;Evil=1") == "{x=y;Evil=1}"


# ── MySQL (cursor falso: SIN servidor real) ──
def test_mysql_session_is_read_only_before_any_query_and_pages_roll_back() -> None:
    log: list = []
    connect_kwargs: dict[str, Any] = {}

    def connect(**kw: Any) -> FakeConnection:
        connect_kwargs.update(kw)
        return FakeConnection(log, [("A-1", 1)], ["document_number", "line_number"])

    adapter = MySqlAdapter(SourceConfig(kind="mysql", host="10.0.0.6", database="erp", user="lector", password_secret="pw"), "pw", "America/Guayaquil", connect=connect)
    t = Template(id="t.sales", entity="sales", version=1, kind="mysql", sql="SELECT n AS document_number, l AS line_number FROM v WHERE m >= :since", order_by=("document_number", "line_number"))
    page = adapter.fetch_page(t, since=datetime(2026, 1, 1), after=None, limit=10, context={})
    statements = [s for s, _ in log]
    assert statements[0] == "SET SESSION TRANSACTION READ ONLY"
    assert statements[1].startswith("SET SESSION MAX_EXECUTION_TIME")
    assert "START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT" in statements and statements[-1] == "ROLLBACK"
    select = next(s for s in statements if s.startswith("SELECT"))
    assert "`document_number`" in select and "%s" in select and "LIMIT 11" in select
    assert page.exhausted and connect_kwargs["autocommit"] is True
    for s in statements:
        assert SELECT_OR_FIXED.match(s)
    with pytest.raises(ReadOnlyViolation):
        adapter.execute_fixed(adapter._cursor(), "INSERT INTO t VALUES (1)")


def test_base_class_has_no_execution_path_for_plain_text() -> None:
    assert not hasattr(SqlAdapter, "execute_raw") and not hasattr(SqlAdapter, "execute")
