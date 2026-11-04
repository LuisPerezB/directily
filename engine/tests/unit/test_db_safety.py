"""W6-E (revisión M4) · guardas de «solo una base de PRUEBAS»: las evasiones del heurístico anterior y las tres copias de la regla.

El heurístico anterior (`url.rsplit("/", 1)[-1].split("?")[0]` contiene «test») se evadía con `…/predictily?x=/test`, con `?dbname=…` en la cadena de consulta y con
nombres como `latest_prod`. `db/safety.py` analiza la URL con `make_url`, exige `url.database`, el nombre con «test» como palabra y una consulta sin parámetros que
redirijan la conexión; las herramientas destructivas de `tests/tools/` llevan una copia en línea (corren también contra el árbol base) que debe rechazar lo mismo.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path

import pytest

from predictily_engine.db import safety

TOOLS = Path(__file__).resolve().parents[1] / "tools"

ACCEPTED = [
    "postgresql+psycopg://u:p@h:5432/predictily_engine_test_w6e",
    "postgresql+psycopg://u:p@h/test_copia",
    "postgresql://u:p@h/copia-test",
    "postgresql+psycopg://u:p@h:5432/predictily_test?sslmode=require&connect_timeout=5",
    "postgresql+psycopg://u:p@h/predictily_engine_test",
]
REJECTED = {
    "el texto «/test» al final de la consulta (la evasión original)": "postgresql+psycopg://u:p@produccion/predictily?x=/test",
    "dbname en la consulta (libpq lo usa por encima de la ruta)": "postgresql+psycopg://u:p@h/x_test?dbname=predictily",
    "host en la consulta": "postgresql+psycopg://u:p@h/x_test?host=produccion",
    "options en la consulta (anula la sesión de solo lectura)": "postgresql+psycopg://u:p@h/x_test?options=-c%20default_transaction_read_only%3Doff",
    "service en la consulta": "postgresql+psycopg://u:p@h/x_test?service=prod",
    "sin base de datos": "postgresql+psycopg://u:p@h:5432/",
    "sin base de datos y con consulta": "postgresql+psycopg://u:p@h:5432?x=/test",
    "palabra que contiene test pero no lo es (latest)": "postgresql+psycopg://u:p@h/latest_prod",
    "palabra que contiene test pero no lo es (contest)": "postgresql+psycopg://u:p@h/contest",
    "producción": "postgresql+psycopg://u:p@h/predictily",
    "otro motor": "mysql+pymysql://u:p@h/x_test",
    "varios hosts": "postgresql+psycopg://u:p@h1,h2/x_test",
    "ilegible": "esto no es una url",
}


def load_tool(name: str):
    spec = importlib.util.spec_from_file_location(f"tool_{name}", TOOLS / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


COPIES = {"db.safety": safety.parse_test_database_url, "factor_off_parity": load_tool("factor_off_parity").parse_test_database_url,
          "demo_parity": load_tool("demo_parity").parse_test_database_url}


@pytest.mark.parametrize("copy", sorted(COPIES))
def test_every_copy_of_the_guard_rejects_the_bypasses_and_accepts_real_test_databases(copy):
    parse = COPIES[copy]
    for url in ACCEPTED:
        assert parse(url).database, url
    for why, url in REJECTED.items():
        with pytest.raises(PermissionError):
            parse(url)
        assert why


def test_the_database_name_is_judged_from_the_parsed_url_and_the_connection_is_checked_after_connecting():
    assert safety.is_test_database_name("predictily_engine_test_w6e") and safety.is_test_database_name("TEST") and safety.is_test_database_name("a.test")
    assert not safety.is_test_database_name("latest") and not safety.is_test_database_name("attestation") and not safety.is_test_database_name("") and not safety.is_test_database_name(None)

    class Conn:
        def __init__(self, name):
            self.name = name

        def execute(self, statement):
            assert "current_database" in str(statement)
            return type("R", (), {"scalar": lambda self_: self.name})()

    assert safety.assert_connected_to_test_database(Conn("predictily_test_x")) == "predictily_test_x"
    with pytest.raises(PermissionError, match="no parece de pruebas"):
        safety.assert_connected_to_test_database(Conn("predictily"))        # la URL declaraba una base de pruebas, pero la conexión real es otra
    for tool in ("factor_off_parity", "demo_parity"):
        module = load_tool(tool)
        with pytest.raises(PermissionError):
            module.assert_connected_to_test_database(Conn("predictily"))
        assert module.assert_connected_to_test_database(Conn("predictily_engine_test_w6e")) == "predictily_engine_test_w6e"


def test_the_destructive_tools_refuse_to_run_before_touching_anything(monkeypatch, capsys):
    """Con una URL evasiva el `main` de cada herramienta termina con código 3 SIN importar el motor ni conectarse (antes de cualquier TRUNCATE)."""
    for tool, args in (("factor_off_parity", ["tree", "out.pkl"]), ("demo_parity", ["tree", "out.pkl"])):
        module = load_tool(tool)
        monkeypatch.setattr("sys.argv", [tool, *args])
        for url in (REJECTED["el texto «/test» al final de la consulta (la evasión original)"], REJECTED["dbname en la consulta (libpq lo usa por encima de la ruta)"], ""):
            monkeypatch.setenv("ENGINE_TEST_DATABASE_URL", url)
            assert module.main() == 3, (tool, url)
            assert "error" in capsys.readouterr().err
