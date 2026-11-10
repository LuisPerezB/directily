"""Arranque de los servidores: `predictily-mcp ingest|insights [--transport stdio|http]`.

- stdio (por defecto): lo lanza el cliente MCP de UNA persona; el token es el de su entorno.
- http: un proceso para varias personas; cada una envía su token en `Authorization: Bearer …` y el servidor lo reenvía.
  Escucha en 127.0.0.1; para exponerlo, póngalo detrás de un proxy con TLS.
"""

import argparse
import sys
from collections.abc import Mapping, Sequence

from mcp.server.mcpserver import MCPServer

from . import __version__
from .config import ConfigError, Settings
from .ingest import build_ingest_server
from .insights import build_insights_server
from .runtime import Runtime
from .sources import SourceProfiles

SERVERS = {"ingest": build_ingest_server, "insights": build_insights_server}


def build_server(name: str, settings: Settings, env: Mapping[str, str] | None = None) -> MCPServer:
    """Servidor listo para correr. Los perfiles de conexión solo existen en el de siembra."""
    profiles = SourceProfiles.load(settings.profiles_file, env) if name == "ingest" else SourceProfiles()
    return SERVERS[name](Runtime(settings, profiles=profiles))


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="predictily-mcp",
        description="Servidores MCP de Predictily (clientes de la API pública con el token de cada persona).",
    )
    parser.add_argument("--version", action="version", version=f"predictily-mcp {__version__}")
    parser.add_argument("server", choices=sorted(SERVERS), help="ingest = siembra de datos; insights = consulta de análisis")
    parser.add_argument("--transport", choices=("stdio", "http"), default="stdio", help="stdio (una persona) o http (varias, token por petición)")
    parser.add_argument("--host", default="127.0.0.1", help="solo http: interfaz de escucha (por defecto 127.0.0.1)")
    parser.add_argument("--port", type=int, default=None, help="solo http: puerto (por defecto 8765 ingest, 8766 insights)")
    return parser


def _utf8_stderr() -> None:
    """Un cliente MCP captura stderr por una tubería y la lee como UTF-8; en Windows Python usaría la página ANSI."""
    try:
        if not sys.stderr.isatty():
            sys.stderr.reconfigure(encoding="utf-8", errors="backslashreplace")  # type: ignore[union-attr]
    except (AttributeError, ValueError, OSError):
        pass


def main(argv: Sequence[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    _utf8_stderr()
    http = args.transport == "http"
    try:
        settings = Settings.from_env(http_mode=http)
        server = build_server(args.server, settings)
    except ConfigError as error:
        # A stderr: en stdio, la salida estándar es el canal del protocolo.
        print(f"predictily-mcp: {error}", file=sys.stderr)
        return 2

    if http:
        port = args.port or (8765 if args.server == "ingest" else 8766)
        # Sin estado y con respuesta JSON: cada petición trae su token; no hay sesión que suplantar ni que expire.
        server.run("streamable-http", host=args.host, port=port, json_response=True, stateless_http=True)
    else:
        server.run("stdio")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
