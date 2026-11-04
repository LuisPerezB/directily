"""Extracción incremental, checkpoints confirmados por el servidor y reanudación SIN duplicados (agente + servidor simulado + SQLite)."""

from __future__ import annotations

import sqlite3
from pathlib import Path

import pytest

from fakes import FakeServer, make_erp
from helpers import Clock, make_agent, sqlite_config
from predictily_bridge.batching import decode_batch
from predictily_bridge.retry import RetryExhausted
from predictily_bridge.spool import Spool
from predictily_bridge.state import AgentState, parse_iso
from predictily_bridge.transport import DeviceRevoked, ServerRejected


@pytest.fixture
def erp(tmp_path: Path):
    path = tmp_path / "erp.sqlite"
    make_erp(path, documents=12, lines=2).close()  # 24 líneas
    return path


def keys(rows):
    return [(r["document_number"], r["line_number"]) for r in rows]


def test_full_extraction_ships_ordered_batches_and_confirms_the_bounded_watermark(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)

    report = agent.run_once()

    assert report.entities["sales"].status == "ok"
    assert report.batches_sent == 5  # 24 filas en lotes de 5
    rows = server.rows()
    assert len(rows) == 24 and len(set(keys(rows))) == 24, "cada fila exactamente una vez"
    assert keys(rows) == sorted(keys(rows)), "en el orden total de la plantilla"
    assert set(rows[0]) == {"document_number", "document_type", "status", "issued_at", "branch_code", "line_number", "product_sku", "quantity", "unit_price", "source_recorded_at"}
    # Checkpoints intermedios con cursor y solo el último con marca de agua; el último lote lleva run_complete.
    commits = server.commits()
    assert [c["run_complete"] for c in commits] == [False, False, False, False, True]
    assert all("watermark" not in c for c in commits[:-1]) and all(len(c["checkpoint"]) <= 200 for c in commits[:-1])
    # Todas las fechas de registro del origen son POSTERIORES al inicio de la extracción (reloj fijo en 2026-01-01T00:00Z): no adelantan la marca de agua,
    # que queda acotada al inicio.
    assert commits[-1]["watermark"] == "2026-01-01T00:00:00+00:00"
    assert server.checkpoints_["sales"]["checkpoint"] is None
    assert agent.state.entity("sales").run is None and agent.spool.pending() == 0


def test_watermark_is_bounded_at_extraction_start_and_future_dates_do_not_advance_it(erp: Path, home: Path) -> None:
    con = sqlite3.connect(erp)
    con.execute("UPDATE erp_facturas SET modificado_en = '2099-01-01 00:00:00' WHERE id_factura = 12")  # fecha de registro futura (anomalía)
    con.commit()
    con.close()
    server, clock = FakeServer(), Clock(start=1_767_225_600.0 + 90 * 86400)  # 2026-04-01: todo el origen es anterior
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    wm = parse_iso(server.checkpoints_["sales"]["watermark"])
    # La fila futura NO adelanta la marca: queda en la mayor fecha legible (factura 11, 2026-01-01 19:00 en hora de Guayaquil = 2026-01-02 00:00 UTC).
    assert wm.isoformat() == "2026-01-02T00:00:00+00:00"
    assert wm <= parse_iso("2026-04-01T00:00:00+00:00")
    assert any("fecha de registro futura" in w for w in agent.warnings)


def test_watermark_never_exceeds_the_extraction_start(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock(start=1_767_225_600.0)  # inicio = 2026-01-01 00:00 UTC, ANTES de casi todo el origen
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    assert parse_iso(server.checkpoints_["sales"]["watermark"]) <= parse_iso("2026-01-01T00:00:00+00:00")


def test_second_run_is_incremental_and_reads_only_the_window(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock(start=1_767_225_600.0 + 30 * 86400)
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    first_rows = len(server.rows())
    assert first_rows == 24
    # Cambia una factura en el origen (otra cantidad y fecha de modificación posterior a la marca de agua) y llegan dos nuevas.
    con = sqlite3.connect(erp)
    con.execute("UPDATE erp_factura_lineas SET cantidad = 99 WHERE id_factura = 3 AND linea = 1")
    con.execute("UPDATE erp_facturas SET modificado_en = '2026-01-25 10:00:00' WHERE id_factura = 3")
    con.execute("INSERT INTO erp_facturas VALUES (13, '001-001-000000013', 'FACTURA', 'EMITIDA', '2026-01-26 09:00:00', '001', '2026-01-26 09:00:00')")
    con.execute("INSERT INTO erp_factura_lineas VALUES (13, 1, 'SKU-1', 3.0, 10.5)")
    con.commit()
    con.close()
    clock.t += 3600
    report = agent.run_once()
    # Solo la ventana (desde la marca de agua, inclusive): el último documento ya entregado (12, se relee), el corregido (3) y el nuevo (13).
    assert report.rows_sent == 5, "no se vuelven a leer las 24 líneas"
    new_rows = server.rows()[first_rows:]
    assert {r["document_number"] for r in new_rows} == {"001-001-000000003", "001-001-000000012", "001-001-000000013"}
    assert {r["quantity"] for r in new_rows if r["document_number"] == "001-001-000000003" and r["line_number"] == 1} == {99.0}
    assert server.commits()[-1]["run_complete"] is True
    assert agent.state.entity("sales").watermark == server.checkpoints_["sales"]["watermark"]


def test_interruption_and_resume_deliver_every_row_exactly_once(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)

    first = agent.run_once(max_batches=2)
    assert first.interrupted and first.batches_sent == 2
    assert server.checkpoints_["sales"]["checkpoint"] is not None and server.checkpoints_["sales"]["watermark"] is None
    assert len(server.rows()) == 10

    # Proceso nuevo (estado en disco): reanuda desde el cursor, no desde el principio.
    agent2, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    second = agent2.run_once()
    assert second.entities["sales"].status == "ok"
    rows = server.rows()
    assert len(rows) == 24 and len(set(keys(rows))) == 24, "sin duplicados ni huecos"
    assert len(server.bronze) == 5, "ningún lote se registró dos veces"
    assert server.checkpoints_["sales"]["watermark"] is not None


def test_crash_between_upload_and_commit_resends_the_same_batch_without_duplicating(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    # El servidor registra el lote pero la confirmación del checkpoint se pierde (caída de red justo después del PUT).
    server.fail["commit"].append(RetryExhausted("red", Exception("x")))
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    first = agent.run_once()
    assert first.entities["sales"].status == "offline"
    assert agent.spool.pending("sales") >= 1, "el lote sigue en el búfer: no se confirmó"
    puts_before = sum(1 for k, _ in server.calls if k == "put")

    agent2, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent2.run_once()
    rows = server.rows()
    assert len(rows) == 24 and len(set(keys(rows))) == 24
    assert len(server.bronze) == 5
    # El lote reenviado se declaró y el servidor respondió «duplicate»: no se volvió a subir.
    assert sum(1 for k, _ in server.calls if k == "put") == 5 and puts_before >= 1


def test_network_outage_buffers_encrypted_and_replays_in_order_when_back(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.offline = True
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    report = agent.run_once()
    assert report.entities["sales"].status == "offline"
    assert agent.spool.pending("sales") == 5, "toda la extracción quedó en el búfer"
    assert server.bronze == {}
    for path in (home / "spool" / "sales").iterdir():
        assert b"001-001-" not in path.read_bytes(), "el búfer está cifrado"

    server.offline = False
    agent2, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent2.run_once()
    assert agent2.spool.pending() == 0
    assert keys(server.rows()) == sorted(keys(server.rows())) and len(server.rows()) == 24
    commits = server.commits()
    assert commits[-1]["run_complete"] and not any(c["run_complete"] for c in commits[:-1])


def test_buffer_is_bounded_and_extraction_pauses_without_losing_data(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.offline = True
    config = sqlite_config(erp, batch_rows=2)
    agent, _ = make_agent(config, home, server, clock)
    agent.spool.max_bytes = 900  # unos pocos lotes
    report = agent.run_once()
    assert report.entities["sales"].status == "offline"
    held = agent.spool.pending("sales")
    assert 0 < held < 12 and agent.spool.total_bytes() <= 900
    run = agent.state.entity("sales").run
    assert run is not None and not run["extraction_complete"]

    server.offline = False
    agent2, _ = make_agent(config, home, server, clock)
    agent2.spool.max_bytes = 900
    for _ in range(40):
        agent2.run_once()
        if agent2.state.entity("sales").watermark:
            break
    rows = server.rows()
    assert len(rows) == 24 and len(set(keys(rows))) == 24, "ninguna fila se perdió por el tope del búfer"


def test_lost_local_state_resumes_from_the_servers_cursor(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once(max_batches=2)
    (home / "state.enc").unlink()
    for entry in (home / "spool" / "sales").glob("*.pbe"):
        entry.unlink()
    agent2, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent2.run_once()
    rows = server.rows()
    assert len(rows) == 24 and len(set(keys(rows))) == 24, "se reanudó en el cursor confirmado por el servidor, sin repetir"


def test_admin_reset_triggers_a_new_full_load(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent.run_once()
    assert server.checkpoints_["sales"]["watermark"]
    server.checkpoints_.clear()  # DELETE /bridge/devices/{id}/checkpoints/sales
    agent2, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    agent2.run_once()
    assert server.checkpoints_["sales"]["watermark"]
    assert len(server.bronze) == 1, "el mismo contenido ya estaba en Bronze: idempotente por SHA-256"
    assert [c["run_complete"] for c in server.commits()][-1] is True
    full_declares = [b for k, b in server.calls if k == "declare"]
    assert full_declares[-1]["run_mode"] == "full"


def test_empty_source_closes_the_run_with_a_batchless_confirmation(tmp_path: Path, home: Path) -> None:
    path = tmp_path / "vacio.sqlite"
    make_erp(path, documents=0).close()
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(path), home, server, clock)
    report = agent.run_once()
    assert report.entities["sales"].status == "ok"
    assert server.bronze == {}
    commit = server.commits()[0]
    assert len(server.commits()) == 1 and commit["run_complete"] is True and commit["run_batches"] == 0 and "batch_sha256" not in commit and commit["run_id"]
    assert server.checkpoints_["sales"]["watermark"] is not None


def test_revocation_stops_everything_purges_the_buffer_and_sets_the_flag(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.offline = True
    agent, dev = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once()
    assert agent.spool.pending() > 0
    server.offline = False
    server.revoked = True
    agent2, dev2 = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    report = agent2.run_once()
    assert report.revoked
    assert server.bronze == {} and agent2.spool.pending() == 0 and not (home / "spool").exists()
    assert dev2.revoked


def test_revocation_mid_run_stops_uploading(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.fail["put"].append(DeviceRevoked(401, "device_revoked", "x"))
    agent, dev = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    report = agent.run_once()
    assert report.revoked and dev.revoked
    assert server.bronze == {} and agent.spool.pending() == 0


def test_schema_rejection_quarantines_the_batch_and_blocks_the_entity_until_the_template_changes(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.reject_columns = True
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    report = agent.run_once()
    assert report.entities["sales"].status == "blocked"
    assert list((home / "spool" / "_quarantine").glob("*.pbe")), "el lote rechazado se conserva cifrado para diagnóstico"
    assert agent.state.entity("sales").blocked["code"] == "unknown_columns"
    declares = sum(1 for k, _ in server.calls if k == "declare")
    # Mientras la plantilla no cambie, el agente no insiste (no martilla al servidor).
    agent.run_once()
    assert sum(1 for k, _ in server.calls if k == "declare") == declares
    # Con la plantilla corregida (otra huella) se desbloquea y entrega.
    server.reject_columns = False
    fixed = sqlite_config(erp, batch_rows=5, sql=__import__("fakes").SALES_SQL + "\n-- v2")
    agent2, _ = make_agent(fixed, home, server, clock)
    agent2.run_once()
    assert agent2.state.entity("sales").blocked is None
    assert len(server.rows()) == 24


def test_expired_upload_url_is_redeclared_once(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.fail["put"].append(ServerRejected(403, "http_403", "Invalid signature."))
    agent, _ = make_agent(sqlite_config(erp, batch_rows=100), home, server, clock)
    report = agent.run_once()
    assert report.entities["sales"].status == "ok"
    assert sum(1 for k, _ in server.calls if k == "declare") == 2
    assert len(server.rows()) == 24


def test_batch_too_big_for_the_server_is_split(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    server.limits = {"max_batch_bytes": 300}
    agent, _ = make_agent(sqlite_config(erp, batch_rows=24), home, server, clock)
    agent.run_once()
    assert all(len(c) <= 300 for c in server.bronze.values())
    assert len(server.rows()) == 24 and len(server.bronze) > 1


def test_identical_content_produces_identical_batches_across_processes(erp: Path, tmp_path: Path) -> None:
    sha_sets = []
    for n in range(2):
        server, clock = FakeServer(), Clock()
        agent, _ = make_agent(sqlite_config(erp, batch_rows=5), tmp_path / f"h{n}", server, clock)
        agent.run_once()
        sha_sets.append(server.order)
    assert sha_sets[0] == sha_sets[1], "gzip determinista: el mismo lote siempre tiene el mismo SHA-256"


def test_budget_zero_extracts_nothing(erp: Path, home: Path) -> None:
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(sqlite_config(erp, batch_rows=5), home, server, clock)
    agent.run_once(max_batches=0)
    assert server.bronze == {}
