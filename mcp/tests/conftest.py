"""API de Predictily SIMULADA (httpx.MockTransport) y utilidades para conectar un cliente MCP en memoria.

Ninguna prueba toca la red ni una base de datos: se comprueba qué pide cada herramienta a la API (método, ruta, cuerpo,
cabeceras) y qué entrega al modelo. Los datos son ficticios.
"""

import json
from collections.abc import Callable
from typing import Any

import httpx
import pytest
from mcp import Client

from predictily_mcp.config import Settings
from predictily_mcp.ingest import build_ingest_server
from predictily_mcp.insights import build_insights_server
from predictily_mcp.runtime import Runtime
from predictily_mcp.sources import SourceProfiles

API = "http://127.0.0.1:8000/api/v1"
TOKEN = "7|tokenDeLaPersonaDePrueba0123456789"


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


class FakeApi:
    """Enrutador mínimo: `on(method, path, respuesta)`; registra cada petición recibida."""

    def __init__(self) -> None:
        self.routes: dict[tuple[str, str], Any] = {}
        self.requests: list[httpx.Request] = []
        # Cuerpo para cualquier GET sin ruta declarada (None = 404).
        self.fallback: Any = None

    def on(self, method: str, path: str, response: Any = None, *, status: int = 200) -> "FakeApi":
        """`response`: cuerpo JSON, lista de cuerpos (uno por llamada, el último se repite) o función(request) → httpx.Response."""
        self.routes[(method.upper(), path.strip("/"))] = (response, status)
        return self

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        path = request.url.path.removeprefix("/api/v1").strip("/")
        route = self.routes.get((request.method, path))
        if route is None:
            if self.fallback is not None and request.method == "GET":
                return httpx.Response(200, json=self.fallback)
            return httpx.Response(404, json={"message": "No encontrado."})
        response, status = route
        if callable(response):
            return response(request)
        if isinstance(response, list):
            body = response.pop(0) if len(response) > 1 else response[0]
        else:
            body = response
        if body is None:
            return httpx.Response(204)
        return httpx.Response(status, json=body)

    # ── consultas sobre lo recibido ──────────────────────────────────────────────────────────────────────────────
    def calls(self, method: str | None = None) -> list[tuple[str, str]]:
        found = [(r.method, r.url.path.removeprefix("/api/v1").strip("/")) for r in self.requests]
        return [c for c in found if method is None or c[0] == method.upper()]

    def last(self, method: str, path: str) -> httpx.Request:
        for request in reversed(self.requests):
            if request.method == method.upper() and request.url.path.removeprefix("/api/v1").strip("/") == path.strip("/"):
                return request
        raise AssertionError(f"La API no recibió {method} {path}; recibió {self.calls()}")

    def json_of(self, method: str, path: str) -> Any:
        return json.loads(self.last(method, path).content)


@pytest.fixture
def api() -> FakeApi:
    return FakeApi()


def settings(**overrides: Any) -> Settings:
    return Settings(**{"api_url": API, "api_token": TOKEN, "poll_interval_seconds": 0.01, **overrides})


@pytest.fixture
def make_runtime(api: FakeApi) -> Callable[..., Runtime]:
    def build(profiles: SourceProfiles | None = None, **overrides: Any) -> Runtime:
        return Runtime(settings(**overrides), profiles=profiles, transport=httpx.MockTransport(api.handler))

    return build


@pytest.fixture
def ingest(make_runtime: Callable[..., Runtime]) -> Callable[..., Client]:
    def connect(profiles: SourceProfiles | None = None, *, client: dict[str, Any] | None = None, **overrides: Any) -> Client:
        return Client(build_ingest_server(make_runtime(profiles, **overrides)), **(client or {}))

    return connect


@pytest.fixture
def insights(make_runtime: Callable[..., Runtime]) -> Callable[..., Client]:
    def connect(**overrides: Any) -> Client:
        return Client(build_insights_server(make_runtime(**overrides)))

    return connect


def text_of(result: Any) -> str:
    return "\n".join(block.text for block in result.content if getattr(block, "type", None) == "text")


def json_of(result: Any) -> Any:
    assert not result.is_error, text_of(result)
    return json.loads(text_of(result))
