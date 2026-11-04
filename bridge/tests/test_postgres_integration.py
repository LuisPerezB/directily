"""Integración con PostgreSQL real (base «ERP de origen» ficticia): extracción incremental, reanudación y solo lectura demostrada por el servidor de BD."""

from __future__ import annotations

from datetime import UTC, datetime
from pathlib import Path

import pytest

from fakes import FakeServer
from helpers import Clock, make_agent
from pg_fixture import Fixture, connect_admin, drop, seed, settings, table_checksum
from predictily_bridge import config as cfgmod
from predictily_bridge.adapters.base import SourceError
from predictily_bridge.adapters.postgres import PostgresAdapter

pytestmark = pytest.mark.postgres


@pytest.fixture
def admin():
    conn = connect_admin()
    fx = Fixture(conn)
    seed(fx)
    yield fx
    drop(fx)
    conn.close()


def pg_config(fx: Fixture, **agent: object) -> cfgmod.Config:
    s = settings()
    return cfgmod.parse({
        "server": {"url": "http://127.0.0.1:9", "allow_insecure_localhost": True},
        "agent": {"batch_rows": 5, "overlap_minutes": 0, "source_timezone": "America/Guayaquil", **agent},
        "source": {"kind": "postgresql", "host": s["host"], "port": s["port"], "database": s["dbname"], "user": s["user"], "password_env": "TEST_ERP_PASSWORD_UNUSED", "sslmode": "prefer"},
        "templates": [{"id": "erp.demo.sales", "entity": "sales", "version": 1, "sql": fx.sales_sql, "recorded_at_column": "source_recorded_at", "order_by": ["document_number", "line_number"]}],
    })


def agent_for(config: cfgmod.Config, tmp_path: Path, server: FakeServer, clock: Clock):
    agent, home = make_agent(config, tmp_path / "home", server, clock, password=settings()["password"] or None)
    return agent, home


def test_extraction_from_real_postgres_ships_every_row_once_and_the_source_is_untouched(admin, tmp_path: Path) -> None:
    before = table_checksum(admin)
    server, clock = FakeServer(), Clock(start=datetime(2026, 4, 1, tzinfo=UTC).timestamp())
    agent, _ = agent_for(pg_config(admin), tmp_path, server, clock)

    report = agent.run_once()

    assert report.entities["sales"].status == "ok" and report.batches_sent == 5
    rows = server.rows()
    assert len(rows) == 24 and len({(r["document_number"], r["line_number"]) for r in rows}) == 24
    first = rows[0]
    assert first["quantity"] == "2.0000" and first["unit_price"] == "10.5000", "numeric llega como texto exacto, sin pérdida"
    assert first["source_recorded_at"].endswith("+00:00") or "-05:00" in first["source_recorded_at"], "timestamptz conserva su desplazamiento"
    assert first["issued_at"] == "2026-01-01 09:00:00", "timestamp ingenuo en la hora local del origen"
    assert first["customer_code"] is None
    assert table_checksum(admin) == before, "el ERP no cambió"
    # Todo lo ejecutado contra el origen fue SELECT o infraestructura de solo lectura.
    assert all(s.lstrip().upper().startswith(("SELECT", "SHOW")) for s in agent.adapter.executed), agent.adapter.executed
    assert server.checkpoints_["sales"]["watermark"] == "2026-01-02T01:00:00+00:00", "min(máx. fecha de registro, inicio): la última modificación del ERP"


def test_incremental_run_reads_only_the_window_with_timestamptz_and_source_timezone(admin, tmp_path: Path) -> None:
    server, clock = FakeServer(), Clock(start=datetime(2026, 4, 1, tzinfo=UTC).timestamp())
    agent, _ = agent_for(pg_config(admin), tmp_path, server, clock)
    agent.run_once()
    admin.conn.execute(f"UPDATE {admin.t('factura_lineas')} SET cantidad = 99 WHERE id_factura = 4 AND linea = 1")
    admin.conn.execute(f"UPDATE {admin.t('facturas')} SET modificado_en = '2026-03-20 10:00:00+00' WHERE id_factura = 4")
    admin.conn.execute(f"INSERT INTO {admin.t('facturas')} (num_factura, tipo, estado, fecha_emision, cod_sucursal, modificado_en) VALUES ('001-001-000000013','FACTURA','EMITIDA','2026-03-21 09:00:00','001','2026-03-21 14:00:00+00')")
    admin.conn.execute(f"INSERT INTO {admin.t('factura_lineas')} (id_factura, linea, cod_item, cantidad, precio_unit) VALUES (13, 1, 'SKU-1', 3, 10.5)")
    clock.t += 3600
    report = agent.run_once()
    docs = {r["document_number"] for r in server.rows()[24:]}
    assert docs == {"001-001-000000004", "001-001-000000012", "001-001-000000013"}, "ventana desde la marca de agua (inclusive): el último documento, el corregido y el nuevo"
    assert report.rows_sent == 5 and server.rows()[24:][0]["document_number"] <= server.rows()[-1]["document_number"]


def test_interrupt_and_resume_against_postgres_without_duplicates(admin, tmp_path: Path) -> None:
    server, clock = FakeServer(), Clock(start=datetime(2026, 4, 1, tzinfo=UTC).timestamp())
    agent, _ = agent_for(pg_config(admin), tmp_path, server, clock)
    assert agent.run_once(max_batches=2).interrupted
    agent2, _ = agent_for(pg_config(admin), tmp_path, server, clock)
    agent2.run_once()
    rows = server.rows()
    assert len(rows) == 24 and len({(r["document_number"], r["line_number"]) for r in rows}) == 24 and len(server.bronze) == 5


def test_the_database_itself_refuses_writes_on_the_agents_connection(admin, tmp_path: Path) -> None:
    import psycopg

    config = pg_config(admin)
    adapter = PostgresAdapter(config.source, settings()["password"] or None, "America/Guayaquil")
    adapter.open()
    conn = adapter._conn
    before = table_checksum(admin)
    # Conexión cruda del agente, saltándose a propósito el guardia: el SERVIDOR rechaza toda escritura.
    for statement in (f"INSERT INTO {admin.t('facturas')} (num_factura, tipo, estado, fecha_emision, cod_sucursal, modificado_en) VALUES ('x','x','x', now(), 'x', now())",
                      f"UPDATE {admin.t('facturas')} SET estado = 'ANULADA'", f"DELETE FROM {admin.t('factura_lineas')}", f"TRUNCATE {admin.t('facturas')} CASCADE", f"CREATE TABLE {admin.t('intruso')} (a int)", f"DROP TABLE {admin.t('facturas')}"):
        with pytest.raises(psycopg.errors.ReadOnlySqlTransaction):
            conn.execute(statement)
        conn.rollback()
    # Ni siquiera pidiendo modo lectura-escritura (dentro de la transacción ni como característica de sesión).
    with pytest.raises(psycopg.errors.ActiveSqlTransaction):
        conn.execute("SELECT 1")
        conn.execute("SET TRANSACTION READ WRITE")
    conn.rollback()
    conn.execute("SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE")
    conn.commit()
    with pytest.raises(psycopg.errors.ReadOnlySqlTransaction):
        conn.execute(f"DELETE FROM {admin.t('factura_lineas')}")
    conn.rollback()
    assert conn.execute("SHOW default_transaction_read_only").fetchone()[0] in ("on", "off")
    adapter.close()
    assert table_checksum(admin) == before


def test_account_with_write_privileges_is_reported_and_can_be_made_fatal(admin, tmp_path: Path) -> None:
    server, clock = FakeServer(), Clock(start=datetime(2026, 4, 1, tzinfo=UTC).timestamp())
    agent, _ = agent_for(pg_config(admin), tmp_path, server, clock)
    info = agent.adapter.describe()
    assert info["writable_account"] is True and info["warnings"], "la cuenta de pruebas es propietaria de la base: debe advertirlo"
    assert info["read_only_enforcement"].startswith("default_transaction_read_only")
    agent.run_once()
    assert any("privilegios" in w or "superusuario" in w for w in agent.warnings)
    assert server.calls[0][1]["health"]["source"]["writable_account"] is True

    strict_server = FakeServer()
    strict, _ = agent_for(pg_config(admin, require_readonly_account=True), tmp_path / "estricto", strict_server, clock)
    report = strict.run_once()
    assert report.entities["sales"].status == "error" and "require_readonly_account" in (report.entities["sales"].error or "")
    assert strict_server.bronze == {}


def test_bad_credentials_fail_without_leaking_the_password(admin, tmp_path: Path) -> None:
    config = pg_config(admin)
    adapter = PostgresAdapter(config.source, "clave-incorrecta-XYZ", "America/Guayaquil")
    adapter.source = type(config.source)(**{**config.source.__dict__, "user": "rol_inexistente_w6b"})  # type: ignore[misc]
    with pytest.raises(SourceError) as e:
        adapter.open()
    assert "clave-incorrecta-XYZ" not in str(e.value) and "rol_inexistente_w6b" not in str(e.value)


def test_template_with_a_write_cannot_even_be_configured() -> None:
    s = settings()
    with pytest.raises(cfgmod.ConfigError, match="solo lectura"):
        cfgmod.parse({
            "server": {"url": "https://x.example"}, "agent": {"source_timezone": "America/Guayaquil"},
            "source": {"kind": "postgresql", "host": s["host"], "database": s["dbname"], "user": s["user"], "password_secret": "pw"},
            "templates": [{"id": "erp.demo.sales", "entity": "sales", "version": 1, "sql": "SELECT 1 FROM t; DELETE FROM t", "order_by": ["a"]}],
        })


def test_statement_timeout_on_the_real_server_is_an_entity_error_and_the_agent_survives(admin, tmp_path: Path) -> None:
    """Sonda 2 de la revisión: con un statement_timeout propio muy corto, una consulta lenta NO debe derribar al agente."""
    s = settings()
    config = cfgmod.parse({
        "server": {"url": "http://127.0.0.1:9", "allow_insecure_localhost": True}, "agent": {"batch_rows": 5, "source_timezone": "America/Guayaquil"},
        "source": {"kind": "postgresql", "host": s["host"], "port": s["port"], "database": s["dbname"], "user": s["user"], "password_env": "X", "sslmode": "prefer", "statement_timeout_seconds": 1},
        "templates": [{"id": "pg.lenta.sales", "entity": "sales", "version": 1, "order_by": ["document_number"],
                       "sql": "SELECT g::text AS document_number, 'FACTURA' AS document_type, now() AS issued_at, '001' AS branch_code, 'X' AS product_sku, 1 AS quantity, 1 AS unit_price FROM generate_series(1, 100000000000) g"}],
    })
    server, clock = FakeServer(), Clock(start=datetime(2026, 4, 1, tzinfo=UTC).timestamp())
    agent, _ = agent_for(config, tmp_path, server, clock)
    report = agent.run_once()   # antes de B-2: psycopg.errors.QueryCanceled sin capturar
    assert report.entities["sales"].status == "error" and "QueryCanceled" in (report.entities["sales"].error or "")
    assert "generate_series" not in (report.entities["sales"].error or "") and server.bronze == {}
    assert agent.run_once().entities["sales"].status == "error"   # y el siguiente ciclo también corre


def test_writable_account_is_detected_through_inherited_role_grants_too(admin) -> None:
    """B-12: una cuenta SIN privilegios propios pero miembro de un rol con INSERT debe figurar como escribible (herencia de roles).

    Crear roles exige un rol con CREATEROLE: se indica con TEST_ERP_ADMIN_USERNAME (solo para esta prueba, p. ej. el superusuario local de desarrollo con
    autenticación `trust`); sin él se OMITE con un mensaje explícito. Los roles se eliminan siempre al terminar."""
    import os
    import uuid

    import psycopg

    admin_user = os.environ.get("TEST_ERP_ADMIN_USERNAME")
    if not admin_user:
        pytest.skip("OMITIDA SIN VERIFICAR (no es un fallo): defina TEST_ERP_ADMIN_USERNAME (un rol con CREATEROLE) para probar la detección de privilegios heredados por pertenencia a roles.")
    base = settings()
    try:
        super_conn = psycopg.connect(host=base["host"], port=base["port"], dbname=base["dbname"], user=admin_user, password=os.environ.get("TEST_ERP_ADMIN_PASSWORD") or None, connect_timeout=3, autocommit=True)
    except psycopg.Error as e:
        pytest.skip(f"OMITIDA SIN VERIFICAR (no es un fallo): no se pudo conectar como {admin_user} ({type(e).__name__}).")
    suffix = uuid.uuid4().hex[:8]
    group, reader, plain = f"brg_grp_{suffix}", f"brg_rd_{suffix}", f"brg_ro_{suffix}"
    table = admin.t("facturas")
    try:
        super_conn.execute(f"CREATE ROLE {group} NOLOGIN")
        super_conn.execute(f"CREATE ROLE {reader} LOGIN IN ROLE {group}")
        super_conn.execute(f"CREATE ROLE {plain} LOGIN")
        super_conn.execute(f"GRANT USAGE ON SCHEMA public TO {group}, {plain}")
        super_conn.execute(f"GRANT INSERT ON {table} TO {group}")
        super_conn.execute(f"GRANT SELECT ON {table} TO {plain}")

        def describe(user: str) -> dict:
            config = pg_config(admin)
            source = type(config.source)(**{**config.source.__dict__, "user": user})
            adapter = PostgresAdapter(source, None, "America/Guayaquil")
            try:
                return adapter.describe()
            finally:
                adapter.close()

        inherited, readonly = describe(reader), describe(plain)
        assert inherited["writable_account"] is True and inherited["warnings"], "el INSERT llega por pertenencia al rol: la consulta de privilegios efectivos lo ve"
        assert readonly["writable_account"] is False and readonly["warnings"] == []
    finally:
        for role in (reader, plain, group):
            super_conn.execute(f"DROP OWNED BY {role}")
            super_conn.execute(f"DROP ROLE IF EXISTS {role}")
        super_conn.close()
