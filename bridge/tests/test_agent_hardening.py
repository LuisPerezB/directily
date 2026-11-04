"""Endurecimiento tras la revisión adversarial (B-2, B-3, B-9, B-11, B-12, B-13): el agente no se cae ante errores comunes, no confirma checkpoints sin prueba y avisa
cuando la cuenta puede escribir o la clave maestra está en un archivo."""

from __future__ import annotations

import logging
import threading
from datetime import datetime
from pathlib import Path
from typing import Any

import pytest

from fakes import SALES_SQL, FakeServer, make_erp
from helpers import Clock, make_agent, sqlite_config
from predictily_bridge import config as cfgmod
from predictily_bridge import sqlguard
from predictily_bridge.adapters.base import SourceError
from predictily_bridge.adapters.mysql import MySqlAdapter
from predictily_bridge.adapters.postgres import PostgresAdapter
from predictily_bridge.adapters.sqlite import SqliteAdapter
from predictily_bridge.adapters.sqlserver import SqlServerAdapter
from predictily_bridge.config import SourceConfig, Template
from predictily_bridge.logging_utils import clear_secrets, register_secret
from predictily_bridge.retry import RetryExhausted
from predictily_bridge.spool import Spool, SpoolFull
from predictily_bridge.state import parse_iso
from predictily_bridge.transport import ServerRejected


@pytest.fixture
def erp(tmp_path: Path) -> Path:
    path = tmp_path / "erp.sqlite"
    make_erp(path, documents=12, lines=2).close()
    return path


def keys(rows):
    return [(r["document_number"], r["line_number"]) for r in rows]


# ───────────────────────── B-2 · errores comunes no derriban al agente ─────────────────────────
def test_driver_errors_become_source_errors_with_only_the_type_name(erp: Path, home: Path) -> None:
    sql = "SELECT 1 AS document_number, 1 AS line_number, m AS source_recorded_at FROM tabla_que_no_existe WHERE m >= :since"
    agent, _ = make_agent(sqlite_config(erp, sql=sql), home, FakeServer(), Clock())
    report = agent.run_once()
    entity = report.entities["sales"]
    assert entity.status == "error" and "OperationalError" in (entity.error or "")
    assert "tabla_que_no_existe" not in (entity.error or ""), "el texto del controlador (con la consulta) no sale del adaptador"


def test_an_unexpected_defect_in_one_entity_does_not_take_the_agent_down(erp: Path, home: Path, caplog: pytest.LogCaptureFixture) -> None:
    clear_secrets()
    register_secret("Clave-Super-Secreta-9")
    caplog.set_level(logging.DEBUG)
    logging.getLogger("predictily_bridge").addFilter(__import__("predictily_bridge.logging_utils", fromlist=["x"]).RedactingFilter())
    caplog.handler.addFilter(__import__("predictily_bridge.logging_utils", fromlist=["x"]).RedactingFilter())
    agent, _ = make_agent(sqlite_config(erp), home, FakeServer(), Clock())

    def boom(*a: Any, **k: Any) -> Any:
        raise RuntimeError("fallo con password=Clave-Super-Secreta-9")

    agent.adapter.fetch_page = boom  # type: ignore[method-assign]
    report = agent.run_once()
    assert report.entities["sales"].status == "error" and "RuntimeError" in (report.entities["sales"].error or "")
    assert "Clave-Super-Secreta-9" not in str(report.entities["sales"].error) and "Clave-Super-Secreta-9" not in caplog.text
    clear_secrets()


def test_postgres_statement_timeout_is_an_entity_error_not_a_crash() -> None:
    class Timeout(Exception):
        pass

    class Cursor:
        description = [("document_number",)]

        def execute(self, sql: str, args: Any = None) -> None:
            raise Timeout("canceling statement due to statement timeout: SELECT ... password=hunter2")

        def close(self) -> None:
            pass

    class Conn:
        read_only = False
        autocommit = True

        def cursor(self) -> Cursor:
            return Cursor()

        def rollback(self) -> None:
            pass

        def close(self) -> None:
            pass

    adapter = PostgresAdapter(SourceConfig(kind="postgresql", host="h", database="d", user="u", password_env="X"), "p", "America/Guayaquil", connect=lambda **kw: Conn())
    t = Template(id="t.sales", entity="sales", version=1, kind="postgresql", sql="SELECT 1 AS document_number WHERE now() >= :since", order_by=("document_number",))
    with pytest.raises(SourceError) as e:
        adapter.fetch_page(t, since=None, after=None, limit=5, context={})
    assert str(e.value) == "Error del controlador al leer el origen: Timeout" and "hunter2" not in str(e.value)


@pytest.mark.parametrize("adapter_factory", [
    lambda cfg: SqliteAdapter(SourceConfig(kind="sqlite", path="/no/existe.sqlite")),
    lambda cfg: SqlServerAdapter(SourceConfig(kind="sqlserver", host="h", database="d", user="u", password_secret="p"), "p", None, connect=lambda *a, **k: (_ for _ in ()).throw(RuntimeError("PWD=x"))),
    lambda cfg: MySqlAdapter(SourceConfig(kind="mysql", host="h", database="d", user="u", password_secret="p"), "p", None, connect=lambda **k: (_ for _ in ()).throw(RuntimeError("password=x"))),
])
def test_describe_never_leaks_driver_exceptions(adapter_factory: Any) -> None:
    with pytest.raises(SourceError) as e:
        adapter_factory(None).describe()
    assert "password=x" not in str(e.value) and "PWD=x" not in str(e.value)


def test_describe_wraps_errors_raised_after_connecting() -> None:
    class Cursor:
        def execute(self, sql: str, params: Any = None) -> None:
            raise RuntimeError("permiso denegado password=zzz")

        def close(self) -> None:
            pass

    class Conn:
        def cursor(self) -> Cursor:
            return Cursor()

        def rollback(self) -> None:
            pass

        def close(self) -> None:
            pass

    adapter = SqlServerAdapter(SourceConfig(kind="sqlserver", host="h", database="d", user="u", password_secret="p"), "p", None, connect=lambda *a, **k: Conn())
    with pytest.raises(SourceError, match="RuntimeError") as e:
        adapter.describe()
    assert "zzz" not in str(e.value)


@pytest.mark.parametrize("error,expected", [
    (ServerRejected(401, "device_clock_skew", "reloj", {"server_time": 1_767_225_600 + 4000}), "difiere unos 4000 s"),
    (ServerRejected(401, "device_signature_invalid", "firma"), "device_signature_invalid"),
    (ServerRejected(423, "tenant_inactive", "La empresa no está activa."), "tenant_inactive"),
    (ServerRejected(403, "data_source_unavailable", "La fuente ya no existe."), "data_source_unavailable"),
])
def test_rejected_heartbeat_pauses_the_cycle_with_a_readable_message(erp: Path, home: Path, error: ServerRejected, expected: str) -> None:
    server = FakeServer()
    server.heartbeat_error = error
    agent, _ = make_agent(sqlite_config(erp), home, server, Clock())
    report = agent.run_once()   # antes: ServerRejected sin capturar derribaba el proceso
    assert report.entities["sales"].status == "paused" and expected in (report.entities["sales"].error or "")
    assert server.bronze == {} and agent.spool.pending() == 0, "en pausa no se extrae ni se envía"
    # Corregida la causa, el siguiente ciclo entrega todo.
    server.heartbeat_error = None
    assert agent.run_once().entities["sales"].status == "ok" and len(server.rows()) == 24


def test_rejected_checkpoint_listing_pauses_the_cycle(erp: Path, home: Path) -> None:
    server = FakeServer()
    server.checkpoints_error = ServerRejected(403, "data_source_unavailable", "La fuente ya no existe.")
    agent, _ = make_agent(sqlite_config(erp), home, server, Clock())
    assert agent.run_once().entities["sales"].status == "paused"


def test_a_validation_error_on_the_health_report_does_not_block_extraction(erp: Path, home: Path) -> None:
    server = FakeServer()
    server.heartbeat_error = ServerRejected(422, "validation", "La salud informada no es válida")
    agent, _ = make_agent(sqlite_config(erp), home, server, Clock())
    assert agent.run_once().entities["sales"].status == "ok" and len(server.rows()) == 24


def test_run_forever_survives_unexpected_cycle_failures_logs_redacted_and_backs_off(erp: Path, home: Path, caplog: pytest.LogCaptureFixture) -> None:
    from predictily_bridge.logging_utils import RedactingFilter

    clear_secrets()
    register_secret("ClaveDeCiclo-123")
    caplog.set_level(logging.DEBUG)
    caplog.handler.addFilter(RedactingFilter())
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    calls = {"n": 0}
    real = agent.run_once
    waits: list[float] = []
    agent.cycle_backoff.delay = lambda attempt, retry_after=None: waits.append(attempt) or 5.0  # type: ignore[method-assign]

    def flaky(max_batches: int | None = None):  # type: ignore[no-untyped-def]
        calls["n"] += 1
        if calls["n"] <= 2:
            raise RuntimeError("fallo password=ClaveDeCiclo-123")
        return real(max_batches)

    agent.run_once = flaky  # type: ignore[method-assign]
    stop_after = {"n": 0}

    def stop() -> bool:
        stop_after["n"] += 1
        return calls["n"] >= 3 and stop_after["n"] > 40

    agent.run_forever(stop, interval=10)
    assert calls["n"] >= 3, "tras dos ciclos fallidos el bucle siguió"
    assert waits[:2] == [1, 2], "retroceso creciente entre ciclos fallidos"
    assert len(server.rows()) == 24, "y el tercer ciclo entregó todo"
    assert "ClaveDeCiclo-123" not in caplog.text and "RuntimeError" in caplog.text
    clear_secrets()


def test_run_forever_stops_on_revocation(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.revoked = True
    agent, dev = make_agent(sqlite_config(erp), home, server, clock)
    agent.run_forever(lambda: False, interval=10)   # no debe quedar en bucle infinito
    assert dev.revoked


# ───────────────────────── B-3 · sin prueba no hay checkpoint ─────────────────────────
def test_a_batch_the_server_could_not_process_pauses_the_entity_and_is_retried(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.fail["commit"].append(ServerRejected(409, "batch_failed", "falló al procesarse"))
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    first = agent.run_once()
    assert first.entities["sales"].status == "paused" and "batch_failed" in (first.entities["sales"].error or "")
    assert agent.spool.pending("sales") == 1 and server.checkpoints_.get("sales", {}).get("watermark") is None
    second = agent.run_once()
    assert second.entities["sales"].status == "ok" and server.checkpoints_["sales"]["watermark"] and agent.spool.pending() == 0
    assert len(server.rows()) == 24 and len(server.bronze) == 1


def test_a_run_the_server_cannot_verify_is_discarded_and_redone_from_the_confirmed_watermark(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once(max_batches=2)
    server.ledger.clear()   # el servidor perdió el libro de la corrida (p. ej. restauración de una copia)
    report = agent.run_once()
    assert report.entities["sales"].status == "paused" and "run_incomplete" in (report.entities["sales"].error or "")
    assert agent.state.entity("sales").run is None and server.checkpoints_["sales"]["watermark"] is None
    again = agent.run_once()
    assert again.entities["sales"].status == "ok" and server.checkpoints_["sales"]["watermark"]
    rows = server.rows()
    assert len({(r["document_number"], r["line_number"]) for r in rows}) == 24 and len(server.bronze) == 5, "la nueva corrida reutiliza los lotes ya recibidos (idempotentes)"


def test_commit_carries_run_id_positions_and_total_batches(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once()
    commits = server.commits()
    assert [c["run_batch_index"] for c in commits] == [1, 2, 3, 4, 5]
    assert len({c["run_id"] for c in commits}) == 1
    assert commits[-1]["run_batches"] == 5 and all("run_batches" not in c for c in commits[:-1])
    assert all(c["batch_sha256"] for c in commits)


def test_cursor_carries_the_batch_position_so_a_resume_after_state_loss_keeps_counting(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once(max_batches=2)
    (home / "state.enc").unlink()
    for f in (home / "spool" / "sales").glob("*.pbe"):
        f.unlink()
    agent2, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent2.run_once()
    assert [c["run_batch_index"] for c in server.commits()] == [1, 2, 3, 4, 5], "la posición continúa donde el servidor la dejó"
    assert server.commits()[-1]["run_batches"] == 5 and server.checkpoints_["sales"]["watermark"]


def test_duplicate_declarations_report_the_servers_processing_state(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    sha = server.order[0]
    server.checkpoints_.clear()   # reinicio solicitado: se vuelve a extraer y todo es «duplicate»
    server.processing[sha] = "needs_review"
    agent.run_once()
    assert any("necesita revisión" in w for w in agent.warnings)
    assert "necesita revisión" in str(agent.health_payload()["health"]["warnings"])


# ───────────────────────── B-9 · cuenta de escritura ─────────────────────────
@pytest.mark.parametrize("kind,expected", [("mysql", True), ("sqlserver", True), ("postgresql", False), ("sqlite", False), ("csv", False)])
def test_require_readonly_account_defaults_to_true_only_for_engines_without_a_robust_read_only_mode(kind: str, expected: bool) -> None:
    source: dict[str, Any] = {"kind": kind}
    templates: list[dict[str, Any]] = [{"id": "t.sales", "entity": "sales", "version": 1, "sql": "SELECT a AS document_number FROM t WHERE m >= :since", "order_by": ["document_number"]}]
    if kind in ("mysql", "sqlserver", "postgresql"):
        source.update(host="h", database="d", user="u", password_secret="p")
    elif kind == "sqlite":
        source["path"] = "/tmp/x.sqlite"
    else:
        templates = [{"id": "csv.sales", "entity": "sales", "version": 1, "path": "/d/*.csv", "columns": {"document_number": "A"}}]
    config = cfgmod.parse({"server": {"url": "https://x.example"}, "agent": {}, "source": source, "templates": templates})
    assert config.require_readonly_account is expected
    explicit = cfgmod.parse({"server": {"url": "https://x.example"}, "agent": {"require_readonly_account": not expected}, "source": source, "templates": templates})
    assert explicit.require_readonly_account is (not expected), "lo explícito manda sobre el valor por defecto"


@pytest.mark.parametrize("kind,adapter_cls", [("sqlserver", SqlServerAdapter), ("mysql", MySqlAdapter)])
def test_a_writable_sqlserver_or_mysql_account_blocks_extraction_by_default(tmp_path: Path, home: Path, kind: str, adapter_cls: Any) -> None:
    class Cursor:
        description = [("a",)]

        def __init__(self) -> None:
            self.last = ""

        def execute(self, sql: str, params: Any = None) -> None:
            self.last = sql

        def fetchone(self) -> tuple[Any, ...]:
            return (1,) if "HAS_PERMS_BY_NAME" in self.last else ("v",)

        def fetchall(self) -> list[tuple[Any, ...]]:
            return [("GRANT ALL PRIVILEGES ON *.* TO u",)]

        def fetchmany(self, n: int) -> list[Any]:
            raise AssertionError("no debe leer datos con una cuenta de escritura")

        def close(self) -> None:
            pass

    class Conn:
        def cursor(self) -> Cursor:
            return Cursor()

        def rollback(self) -> None:
            pass

        def close(self) -> None:
            pass

    source = SourceConfig(kind=kind, host="h", database="d", user="u", password_secret="p")
    config = cfgmod.parse({"server": {"url": "https://x.example"}, "agent": {}, "source": {"kind": kind, "host": "h", "database": "d", "user": "u", "password_secret": "p"},
                           "templates": [{"id": "t.sales", "entity": "sales", "version": 1, "sql": "SELECT a AS document_number FROM t WHERE m >= :since", "order_by": ["document_number"]}]})
    adapter = adapter_cls(source, "p", None, connect=(lambda *a, **k: Conn()))
    server = FakeServer()
    agent, _ = make_agent(config, home, server, Clock(), adapter=adapter)
    report = agent.run_once()
    assert report.entities["sales"].status == "error" and "require_readonly_account" in (report.entities["sales"].error or "")
    assert server.bronze == {} and not [c for c in server.calls if c[0] == "declare"]


# ───────────────────────── B-12 · PostgreSQL seguro por defecto ─────────────────────────
def test_postgres_defaults_to_tls_and_the_read_only_session_options_are_sent() -> None:
    assert SourceConfig(kind="postgresql").sslmode == "require"
    seen: dict[str, Any] = {}

    class Conn:
        read_only = False
        autocommit = True

        def rollback(self) -> None:
            pass

        def close(self) -> None:
            pass

    def connect(**kw: Any) -> Conn:
        seen.update(kw)
        return Conn()

    config = cfgmod.parse({"server": {"url": "https://x.example"}, "agent": {"source_timezone": "America/Guayaquil"}, "source": {"kind": "postgresql", "host": "h", "database": "d", "user": "u", "password_secret": "p"},
                           "templates": [{"id": "t.sales", "entity": "sales", "version": 1, "sql": "SELECT a AS document_number FROM t WHERE m >= :since", "order_by": ["document_number"]}]})
    adapter = PostgresAdapter(config.source, "p", "America/Guayaquil", connect=connect)
    adapter.open()
    assert seen["sslmode"] == "require"
    assert "-c default_transaction_read_only=on" in seen["options"] and "-c TimeZone=America/Guayaquil" in seen["options"]
    assert adapter._conn.read_only is True and adapter._conn.autocommit is False, "la conexión pide BEGIN … READ ONLY en cada transacción"


# ───────────────────────── B-13 · clave maestra en archivo ─────────────────────────
def test_master_key_in_a_file_is_warned_about_in_the_log_and_in_the_health_report(erp: Path, home: Path, caplog: pytest.LogCaptureFixture) -> None:
    caplog.set_level(logging.WARNING)
    server = FakeServer()
    agent, _ = make_agent(sqlite_config(erp), home, server, Clock(), key_backend="file")
    agent.run_once()
    agent.run_once()
    assert any("master.key" in r.getMessage() for r in caplog.records) and sum("master.key" in r.getMessage() for r in caplog.records) == 1, "se registra una vez por proceso"
    warnings = [c[1]["health"]["warnings"] for c in server.calls if c[0] == "heartbeat"]
    assert warnings and all(any("master.key" in w for w in ws) for ws in warnings), "y se informa en cada latido"
    quiet, _ = make_agent(sqlite_config(erp), home.parent / "otro", FakeServer(), Clock(), key_backend="keyring")
    quiet.run_once()
    assert not any("master.key" in w for w in quiet.warnings)


# ───────────────────────── B-11 · mutantes que sobrevivieron ─────────────────────────
def test_wrapper_query_is_validated_again_even_if_the_identifier_quoting_were_tampered(erp: Path) -> None:
    class Tampered(SqliteAdapter):
        def quote(self, identifier: str) -> str:
            return f'"{identifier}"; DROP TABLE erp_facturas'

    adapter = Tampered(SourceConfig(kind="sqlite", path=str(erp)))
    t = Template(id="t.sales", entity="sales", version=1, kind="sqlite", sql=SALES_SQL, recorded_at_column="source_recorded_at", order_by=("document_number", "line_number"))
    with pytest.raises(sqlguard.SqlQueryRejected):
        adapter.build(t, True, 5)


def test_watermark_never_moves_back_when_a_backdated_row_appears_inside_the_overlap_window(tmp_path: Path, home: Path) -> None:
    """Corrida 1 sobre un origen vacío: marca = inicio (T0). Después llega una fila con fecha de registro ANTERIOR a T0 (carga tardía) dentro de la ventana de
    solape: el máximo de lo leído (T0 − 30 min) es MENOR que la marca previa; la nueva marca debe quedarse en T0 (nunca retrocede; el servidor rechazaría un retroceso)."""
    path = tmp_path / "vacio.sqlite"
    make_erp(path, documents=0).close()
    server, clock = FakeServer(), Clock()   # T0 = 2026-01-01T00:00:00Z
    config = sqlite_config(path, batch_rows=100, extra_agent={"overlap_minutes": 120})
    agent, _ = make_agent(config, home, server, clock)
    agent.run_once()
    t0 = parse_iso(server.checkpoints_["sales"]["watermark"])
    assert t0.isoformat() == "2026-01-01T00:00:00+00:00"

    con = __import__("sqlite3").connect(path)
    con.execute("INSERT INTO erp_facturas VALUES (1, '001-001-000000001', 'FACTURA', 'EMITIDA', '2025-12-31 18:30:00', '001', '2025-12-31 18:30:00')")   # 23:30Z = T0 − 30 min
    con.execute("INSERT INTO erp_factura_lineas VALUES (1, 1, 'SKU-1', 2.0, 10.5)")
    con.commit()
    con.close()
    clock.t += 3600
    report = agent.run_once()
    assert report.entities["sales"].status == "ok", report.entities["sales"].error
    assert len(server.rows()) == 1, "la fila tardía sí se entregó (estaba dentro del solape)"
    assert parse_iso(server.checkpoints_["sales"]["watermark"]) == t0, "pero la marca de agua NO retrocedió"
    marks = [c["watermark"] for c in server.commits() if c["run_complete"]]
    assert marks == sorted(marks)


def test_watermark_is_the_minimum_of_the_latest_record_and_the_extraction_start(erp: Path, home: Path) -> None:
    con = __import__("sqlite3").connect(erp)
    # Una fila registrada 2 minutos DESPUÉS del inicio (dentro de la tolerancia de 5): es legible pero la marca se acota al inicio.
    con.execute("UPDATE erp_facturas SET modificado_en = '2026-04-01 00:02:00' WHERE id_factura = 12")   # 05:02 UTC > inicio 2026-04-01 05:00:00 UTC? ver reloj
    con.commit()
    con.close()
    start = datetime(2026, 4, 1, 4, 59, 0).replace(tzinfo=__import__("datetime").UTC).timestamp()   # inicio 04:59 UTC = 23:59 hora de Guayaquil del 31-mar
    server, clock = FakeServer(), Clock(start=start)
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    watermark = parse_iso(server.checkpoints_["sales"]["watermark"])
    assert watermark == datetime.fromtimestamp(start, __import__("datetime").UTC), "min(máx. registro = 00:02 local = 05:02 UTC, inicio 04:59 UTC) = inicio"


def test_redirects_are_refused_and_the_redirect_target_is_never_contacted() -> None:
    from http.server import BaseHTTPRequestHandler, HTTPServer

    from predictily_bridge import crypto
    from predictily_bridge.retry import Backoff
    from predictily_bridge.transport import HttpServerAPI, Identity

    hits: list[str] = []

    class Target(BaseHTTPRequestHandler):
        def log_message(self, *a: Any) -> None:
            pass

        def do_GET(self) -> None:
            hits.append(self.headers.get("Authorization", ""))
            body = b'{"data": []}'
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    target = HTTPServer(("127.0.0.1", 0), Target)
    threading.Thread(target=target.serve_forever, daemon=True).start()

    class Redirector(BaseHTTPRequestHandler):
        def log_message(self, *a: Any) -> None:
            pass

        def do_GET(self) -> None:
            self.send_response(302)   # urllib sigue por defecto las redirecciones de GET (y reenvía Authorization)
            self.send_header("Location", f"http://127.0.0.1:{target.server_address[1]}/capturar")
            self.send_header("Content-Length", "0")
            self.end_headers()

    redirector = HTTPServer(("127.0.0.1", 0), Redirector)
    threading.Thread(target=redirector.serve_forever, daemon=True).start()
    try:
        api = HttpServerAPI(cfgmod.ServerConfig(f"http://127.0.0.1:{redirector.server_address[1]}", allow_insecure_localhost=True, timeout_seconds=5),
                            Identity("brt_" + "T" * 43, crypto.DeviceKey.generate()), Backoff(sleep=lambda s: None))
        with pytest.raises(ServerRejected):
            api.checkpoints()
        assert hits == [], "la redirección no se siguió: el token y la firma no llegaron al otro destino"
    finally:
        redirector.shutdown()
        target.shutdown()


def test_spool_put_is_idempotent_by_sha256_and_never_counts_twice_against_the_bound(home: Path) -> None:
    from predictily_bridge.device import DeviceHome

    dev = DeviceHome(home, use_keyring=False)
    spool = Spool(dev.spool_dir, dev.keystore, max_bytes=2000)
    payload = b"x" * 600
    first = spool.put("sales", 1, "a" * 64, {"row_count": 1}, payload)
    again = spool.put("sales", 7, "a" * 64, {"row_count": 1}, payload)   # reintento tras una caída: otra secuencia, mismo contenido
    assert again.path == first.path and len(spool.list("sales")) == 1
    # Con el búfer casi lleno, reponer un lote que YA está dentro no falla (no se vuelve a contar); uno nuevo sí.
    spool.put("sales", 2, "b" * 64, {"row_count": 1}, payload)
    spool.put("sales", 9, "b" * 64, {"row_count": 1}, payload)
    assert len(spool.list("sales")) == 2
    with pytest.raises(SpoolFull):
        spool.put("sales", 3, "c" * 64, {"row_count": 1}, payload * 2)
    assert spool.total_bytes() <= 2000


def test_a_run_whose_last_page_comes_back_empty_is_closed_citing_the_ledger_not_a_batch(erp: Path, home: Path) -> None:
    """Las filas desaparecen del origen ENTRE páginas: no hay «último lote» que citar. La corrida se cierra con `run_batches` = lotes ya confirmados y el servidor
    la valida con su libro (1…N); con `run_batches = 0` la prueba no cuadraría (409 run_incomplete)."""
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    real = agent.adapter.fetch_page
    calls = {"n": 0}

    def fetch(*args: Any, **kwargs: Any):  # type: ignore[no-untyped-def]
        calls["n"] += 1
        if calls["n"] == 2:   # antes de la segunda página, otra sesión borra lo que faltaba por leer
            con = __import__("sqlite3").connect(erp)
            con.execute("DELETE FROM erp_factura_lineas WHERE id_factura > 3 OR (id_factura = 3 AND linea = 2)")
            con.execute("DELETE FROM erp_facturas WHERE id_factura > 3")
            con.commit()
            con.close()
        return real(*args, **kwargs)

    agent.adapter.fetch_page = fetch  # type: ignore[method-assign]
    report = agent.run_once()
    assert report.entities["sales"].status == "ok", report.entities["sales"].error
    closing = server.commits()[-1]
    assert closing["run_complete"] is True and closing["run_batches"] == 1 and "batch_sha256" not in closing
    assert server.checkpoints_["sales"]["watermark"] and agent.state.entity("sales").run is None and len(server.rows()) == 5
