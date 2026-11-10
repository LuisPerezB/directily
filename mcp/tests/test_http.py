"""Transporte HTTP real (uvicorn en un hilo): la identidad es la de la cabecera de CADA petición, y el arranque por CLI."""

import socket
import threading
import time
from collections.abc import Iterator
from contextlib import contextmanager

import httpx
import httpx2
import pytest
import uvicorn
from mcp import Client
from mcp.client.streamable_http import streamable_http_client

from conftest import API, TOKEN, FakeApi, json_of, settings, text_of
from predictily_mcp import cli
from predictily_mcp.config import Settings
from predictily_mcp.insights import build_insights_server
from predictily_mcp.runtime import Runtime

pytestmark = pytest.mark.anyio

ANA = "11|tokenDeAna000000000000000000"
LUIS = "12|tokenDeLuis00000000000000000"


@contextmanager
def serving(api: FakeApi, **overrides) -> Iterator[str]:
    """Servidor de análisis en modo HTTP sobre un puerto libre de 127.0.0.1."""
    runtime = Runtime(settings(api_token=None, http_mode=True, **overrides), transport=httpx.MockTransport(api.handler))
    app = build_insights_server(runtime).streamable_http_app(json_response=True, stateless_http=True)
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="warning"))
    thread = threading.Thread(target=server.run, daemon=True)
    thread.start()
    deadline = time.monotonic() + 10
    while not server.started and time.monotonic() < deadline:
        time.sleep(0.02)
    assert server.started, "el servidor HTTP de prueba no arrancó"
    try:
        yield f"http://127.0.0.1:{port}/mcp"
    finally:
        server.should_exit = True
        thread.join(timeout=10)


def connect(url: str, headers: dict[str, str]) -> Client:
    return Client(streamable_http_client(url, http_client=httpx2.AsyncClient(headers=headers)))


async def test_each_connection_reaches_the_api_with_its_own_persons_token(api: FakeApi) -> None:
    api.on("GET", "decisions/summary", {"data": {"total": 0}})

    with serving(api) as url:
        async with connect(url, {"Authorization": f"Bearer {ANA}", "X-Tenant-ID": "aaaaaaaa-0000-0000-0000-000000000001"}) as ana:
            await ana.call_tool("predictily_decisions_summary", {})
        async with connect(url, {"Authorization": f"Bearer {LUIS}"}) as luis:
            await luis.call_tool("predictily_decisions_summary", {})
            await luis.call_tool("predictily_decisions_summary", {})

    seen = [(r.headers["authorization"], r.headers.get("x-tenant-id")) for r in api.requests]
    assert seen == [(f"Bearer {ANA}", "aaaaaaaa-0000-0000-0000-000000000001"), (f"Bearer {LUIS}", None), (f"Bearer {LUIS}", None)]


async def test_without_a_personal_token_nothing_reaches_the_api(api: FakeApi) -> None:
    api.fallback = {"data": []}

    with serving(api) as url:
        async with connect(url, {}) as anonymous:
            tools = (await anonymous.list_tools()).tools
            missing = await anonymous.call_tool("predictily_dashboard_summary", {})
        async with connect(url, {"Authorization": "Basic dXN1YXJpbzpjbGF2ZQ=="}) as basic:
            wrong_scheme = await basic.call_tool("predictily_dashboard_summary", {})

    assert tools, "listar herramientas no requiere identidad: no entrega ningún dato"
    assert missing.is_error and "Authorization: Bearer" in text_of(missing)
    assert wrong_scheme.is_error
    assert api.calls() == []


async def test_the_http_server_has_no_token_of_its_own_to_fall_back_on(api: FakeApi) -> None:
    """Aunque el entorno traiga PREDICTILY_API_TOKEN, en modo HTTP no se usa: no hay «usuario por defecto»."""
    env = {"PREDICTILY_API_URL": API, "PREDICTILY_API_TOKEN": TOKEN}
    http_settings = Settings.from_env(env, http_mode=True)
    api.on("GET", "decisions/summary", {"data": {"total": 0}})

    with serving(api, tenant_id=http_settings.tenant_id) as url:
        async with connect(url, {}) as anonymous:
            result = await anonymous.call_tool("predictily_decisions_summary", {})

    assert http_settings.api_token is None
    assert result.is_error and all(TOKEN not in r.headers.get("authorization", "") for r in api.requests)


async def test_a_real_api_answer_travels_back_over_http(api: FakeApi) -> None:
    api.on("GET", "dashboard/summary", {"data": {"horizon": 30, "kpis": {"forecast_units": 1234.5, "backtest_wape": 0.23}}})

    with serving(api) as url:
        async with connect(url, {"Authorization": f"Bearer {ANA}"}) as ana:
            result = json_of(await ana.call_tool("predictily_dashboard_summary", {"horizon": 30}))

    assert result["data"]["kpis"] == {"forecast_units": 1234.5, "backtest_wape": 0.23}


# ── CLI ──────────────────────────────────────────────────────────────────────────────────────────────────────────


def test_the_cli_refuses_to_start_without_configuration(monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    for name in ("PREDICTILY_API_URL", "PREDICTILY_API_TOKEN"):
        monkeypatch.delenv(name, raising=False)

    code = cli.main(["ingest"])

    captured = capsys.readouterr()
    assert code == 2
    assert captured.out == "", "en stdio la salida estándar es el canal del protocolo: los errores van a stderr"
    assert "PREDICTILY_API_TOKEN" in captured.err or "PREDICTILY_API_URL" in captured.err


def test_the_cli_builds_each_server_and_only_ingest_loads_connection_profiles() -> None:
    env = {"PREDICTILY_API_URL": API, "PREDICTILY_API_TOKEN": TOKEN, "PREDICTILY_SOURCE_ERP_USERNAME": "lector", "PREDICTILY_SOURCE_ERP_PASSWORD": "x"}

    ingest = cli.build_server("ingest", Settings.from_env(env), env)
    insights = cli.build_server("insights", Settings.from_env(env), env)

    assert (ingest.name, insights.name) == ("predictily_ingest_mcp", "predictily_insights_mcp")
    assert ingest.instructions and "SELECT" in ingest.instructions
    assert insights.instructions and "No calcule" in insights.instructions


def test_a_bad_profile_stops_the_cli_with_a_clear_message(monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    monkeypatch.setenv("PREDICTILY_API_URL", API)
    monkeypatch.setenv("PREDICTILY_API_TOKEN", TOKEN)
    monkeypatch.setenv("PREDICTILY_SOURCE_ERP_CONNECTOR", "oracle")

    assert cli.main(["ingest"]) == 2
    assert "connector" in capsys.readouterr().err
