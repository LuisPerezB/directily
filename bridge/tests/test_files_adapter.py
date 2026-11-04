"""Adaptadores de archivos CSV y Excel: páginas ordenadas, reanudación por (archivo, fila), omisión de archivos sin cambios."""

from __future__ import annotations

from pathlib import Path

import pytest

from fakes import FakeServer
from helpers import Clock, make_agent
from predictily_bridge import config as cfgmod
from predictily_bridge.adapters.base import SourceError
from predictily_bridge.adapters.files import FileAdapter


def write_csv(path: Path, rows: list[tuple[str, int]], header: str = "Nro,Linea,Fecha,Sku,Cant,Precio") -> None:
    lines = [header] + [f"{n},{ln},2026-01-0{(ln % 9) + 1} 10:00:00,SKU-{ln},{ln + 1},9.5" for n, ln in rows]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def file_config(pattern: str, kind: str = "csv", **template: object) -> cfgmod.Config:
    return cfgmod.parse({
        "server": {"url": "http://127.0.0.1:9", "allow_insecure_localhost": True}, "agent": {"batch_rows": 4}, "source": {"kind": kind},
        "templates": [{"id": f"{kind}.sales", "entity": "sales", "version": 1, "path": pattern,
                       "columns": {"document_number": "Nro", "line_number": "Linea", "issued_at": "Fecha", "product_sku": "Sku", "quantity": "Cant", "unit_price": "Precio"}, **template}],
    })


def test_csv_pages_resume_by_file_and_row_and_end_exactly(tmp_path: Path) -> None:
    d = tmp_path / "in"
    d.mkdir()
    write_csv(d / "a.csv", [("A-1", 1), ("A-1", 2), ("A-2", 1)])
    write_csv(d / "b.csv", [("B-1", 1), ("B-1", 2), ("B-2", 1), ("B-2", 2)])
    cfg = file_config(str(d / "*.csv"))
    adapter = FileAdapter("csv")
    template = cfg.templates[0]
    context = adapter.start_run(template)
    assert [f["name"] for f in context["files"]] == ["a.csv", "b.csv"]
    seen, after, pages = [], None, 0
    while True:
        page = adapter.fetch_page(template, since=None, after=after, limit=3, context=context)
        pages += 1
        seen += [(r["document_number"], r["line_number"]) for r in page.rows]
        after = page.cursor_after
        if page.exhausted:
            break
    assert seen == [("A-1", "1"), ("A-1", "2"), ("A-2", "1"), ("B-1", "1"), ("B-1", "2"), ("B-2", "1"), ("B-2", "2")] and pages == 3
    # Reanudar a mitad del segundo archivo (cursor ("b.csv", 2)) entrega solo lo que falta.
    page = adapter.fetch_page(template, since=None, after=("b.csv", 2), limit=10, context=context)
    assert [(r["document_number"], r["line_number"]) for r in page.rows] == [("B-2", "1"), ("B-2", "2")] and page.exhausted


def test_missing_column_and_encoding_errors_are_clear(tmp_path: Path) -> None:
    (tmp_path / "x.csv").write_text("Otro,Linea\n1,2\n", encoding="utf-8")
    cfg = file_config(str(tmp_path / "x.csv"))
    adapter = FileAdapter("csv")
    ctx = adapter.start_run(cfg.templates[0])
    with pytest.raises(SourceError, match="no tiene la columna «Nro»"):
        adapter.fetch_page(cfg.templates[0], since=None, after=None, limit=5, context=ctx)
    (tmp_path / "y.csv").write_bytes("Nro,Linea\nÑ,1\n".encode("latin-1"))
    cfg = file_config(str(tmp_path / "y.csv"))
    ctx = adapter.start_run(cfg.templates[0])
    with pytest.raises(SourceError, match="No se pudo leer"):
        adapter.fetch_page(cfg.templates[0], since=None, after=None, limit=5, context=ctx)


def test_agent_ships_csv_files_skips_unchanged_ones_and_rereads_changed_ones(tmp_path: Path) -> None:
    d = tmp_path / "in"
    d.mkdir()
    write_csv(d / "ventas1.csv", [("A-1", 1), ("A-1", 2), ("A-2", 1)])
    write_csv(d / "ventas2.csv", [("B-1", 1), ("B-2", 1)])
    cfg = file_config(str(d / "ventas*.csv"))
    server, clock = FakeServer(), Clock()
    agent, _ = make_agent(cfg, tmp_path / "home", server, clock)
    agent.run_once()
    assert len(server.rows()) == 5 and server.checkpoints_["sales"]["watermark"]
    sources_before = {p.name: p.read_bytes() for p in d.iterdir()}

    clock.t += 600
    agent2, _ = make_agent(cfg, tmp_path / "home", server, clock)
    r = agent2.run_once()
    assert r.rows_sent == 0, "ningún archivo cambió: no se vuelve a leer nada"

    write_csv(d / "ventas2.csv", [("B-1", 1), ("B-2", 1), ("B-3", 1)])  # cambia el contenido
    clock.t += 600
    agent3, _ = make_agent(cfg, tmp_path / "home", server, clock)
    r = agent3.run_once()
    assert r.rows_sent == 3 and {row["document_number"] for row in server.rows()[5:]} == {"B-1", "B-2", "B-3"}
    assert {p.name: p.read_bytes() for p in d.iterdir() if p.name == "ventas1.csv"} == {k: v for k, v in sources_before.items() if k == "ventas1.csv"}, "los archivos del origen no se tocan"


def test_excel_adapter_reads_header_and_rows(tmp_path: Path) -> None:
    openpyxl = pytest.importorskip("openpyxl")
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Ventas"
    ws.append(["Nro", "Linea", "Fecha", "Sku", "Cant", "Precio"])
    for i in range(1, 8):
        ws.append([f"X-{i}", 1, "2026-01-02 10:00:00", "SKU-1", i, 3.25])
    ws.append([None] * 6)
    wb.save(tmp_path / "ventas.xlsx")
    cfg = file_config(str(tmp_path / "ventas.xlsx"), kind="excel", sheet="Ventas")
    server = FakeServer()
    agent, _ = make_agent(cfg, tmp_path / "home", server, Clock())
    report = agent.run_once(max_batches=1)
    assert report.interrupted
    make_agent(cfg, tmp_path / "home", server, Clock())[0].run_once()
    rows = server.rows()
    assert [r["document_number"] for r in rows] == [f"X-{i}" for i in range(1, 8)] and rows[0]["unit_price"] == 3.25
