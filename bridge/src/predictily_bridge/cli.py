"""Línea de comandos del agente.

    predictily-bridge check-config [--pin]        valida la configuración y las plantillas (sin tocar el origen ni la red)
    predictily-bridge secret set NOMBRE           guarda un secreto de la fuente (contraseña) cifrado; se lee de la entrada, nunca de argumentos
    predictily-bridge test-source                 abre el origen en solo lectura y muestra versión, modo de solo lectura y advertencias
    predictily-bridge enroll --code BRG-…         registra este equipo con el código de un solo uso del administrador
    predictily-bridge run [--once] [--max-batches N]   extrae y envía (un ciclo, o en bucle como servicio)
    predictily-bridge status                      estado local sin secretos

Códigos de salida: 0 correcto · 1 error · 2 configuración · 3 dispositivo revocado · 4 alguna entidad con error, bloqueada o sin conexión.
"""

from __future__ import annotations

import argparse
import getpass
import json
import logging
import os
import signal
import sys
from pathlib import Path
from collections.abc import Callable
from typing import Any

from predictily_bridge import __version__, crypto
from predictily_bridge import config as cfgmod
from predictily_bridge.adapters import VERIFICATION, create_adapter
from predictily_bridge.adapters.base import SourceError
from predictily_bridge.device import DeviceHome, NotEnrolled
from predictily_bridge.fsutil import InsecurePermissions
from predictily_bridge.logging_utils import redact, register_secret, setup_logging
from predictily_bridge.retry import RetryExhausted
from predictily_bridge.runner import Agent, RunReport, _platform
from predictily_bridge.spool import Spool
from predictily_bridge.state import AgentState
from predictily_bridge.transport import DeviceRevoked, HttpServerAPI, Identity, ServerAPI, ServerRejected

log = logging.getLogger("predictily_bridge.cli")


def default_home() -> Path:
    return Path(os.environ.get("PREDICTILY_BRIDGE_HOME") or "~/.predictily-bridge").expanduser()


def load_config(args: argparse.Namespace) -> tuple[cfgmod.Config, DeviceHome]:
    home = DeviceHome(Path(args.home).expanduser() if args.home else default_home(), use_keyring=not args.no_keyring)
    path = Path(args.config).expanduser() if args.config else home.home / "config.toml"
    return cfgmod.load(path), home


def build_agent(config: cfgmod.Config, home: DeviceHome, api: ServerAPI | None = None) -> Agent:
    api = api or HttpServerAPI(config.server, home.load_identity())
    password = cfgmod.password(config.source, home.secret)
    if password:
        register_secret(password)
    adapter = create_adapter(config, password)
    spool = Spool(home.spool_dir, home.keystore, config.agent.spool_max_mb * 1024 * 1024)
    return Agent(config, api, adapter, AgentState(home.state_store), spool, on_revoked=home.mark_revoked, key_backend=home.keystore.backend)


def cmd_check_config(args: argparse.Namespace) -> int:
    config, _ = load_config(args)
    out = {
        "ok": True, "server": config.server.url, "source": config.source.kind, "verification": VERIFICATION.get(config.source.kind),
        "templates": [{"entity": t.entity, "id": t.id, "version": t.version, "active": t is config.template_for(t.entity), "sha256": t.fingerprint()} for t in config.templates],
        "templates_sha256": config.templates_fingerprint(),
        "templates_pinned": bool(config.agent.templates_sha256),
    }
    print(json.dumps(out, ensure_ascii=False, indent=1))
    if args.pin:
        print(f'\n# Añada a [agent] en config.toml (tras revisar las consultas):\ntemplates_sha256 = "{config.templates_fingerprint()}"')
    return 0


def cmd_secret_set(args: argparse.Namespace) -> int:
    home = DeviceHome(Path(args.home).expanduser() if args.home else default_home(), use_keyring=not args.no_keyring)
    value = sys.stdin.readline().rstrip("\n") if not sys.stdin.isatty() else getpass.getpass(f"Valor de «{args.name}»: ")
    if not value:
        print("Valor vacío: no se guardó nada.", file=sys.stderr)
        return 1
    home.set_secret(args.name, value)
    print(f"Secreto «{args.name}» guardado cifrado en {home.home}.")
    return 0


def cmd_test_source(args: argparse.Namespace) -> int:
    config, home = load_config(args)
    password = cfgmod.password(config.source, home.secret)
    if password:
        register_secret(password)
    adapter = create_adapter(config, password)
    try:
        info = adapter.describe()
    finally:
        adapter.close()
    print(json.dumps({**info, "verification": VERIFICATION.get(config.source.kind)}, ensure_ascii=False, indent=1, default=str))
    return 0


def cmd_enroll(args: argparse.Namespace) -> int:
    config, home = load_config(args)
    if home.is_enrolled() and not args.force:
        print("Este equipo ya está registrado. Use --force para registrarlo de nuevo (el administrador debe haber revocado el anterior).", file=sys.stderr)
        return 1
    key = crypto.DeviceKey.generate()  # el par se genera AQUÍ; la clave privada no sale de este equipo
    api = HttpServerAPI(config.server, Identity("", key))
    name = args.name or (os.uname().nodename if hasattr(os, "uname") else os.environ.get("COMPUTERNAME", "bridge"))
    data = api.enrol(args.code, name, key, _platform())
    home.save_enrolment(
        key=key, token=data["token"],
        info={"device_id": data["device_id"], "server": config.server.url, "fingerprint": key.fingerprint, "tenant_uuid": data["tenant"]["uuid"],
              "data_source": data["data_source"], "protocol": data.get("protocol"), "agent_version": __version__},
    )
    print(json.dumps({"enrolled": True, "device_id": data["device_id"], "fingerprint": key.fingerprint, "data_source": data["data_source"]}, ensure_ascii=False))
    return 0


def cmd_run(args: argparse.Namespace) -> int:
    config, home = load_config(args)
    if home.revoked:
        print("Este dispositivo fue revocado por la empresa: no enviará datos. Pida un código nuevo y ejecute «enroll --force».", file=sys.stderr)
        return 3
    agent = build_agent(config, home)
    if args.once:
        report = agent.run_once(args.max_batches)
        print(json.dumps(_report(report), ensure_ascii=False))
        return _exit_code(report)
    stop = {"flag": False}
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: stop.__setitem__("flag", True))
    agent.run_forever(lambda: stop["flag"])
    return 3 if home.revoked else 0


def run_service(home: str, *, stop: Callable[[], bool], config_path: str | None = None, api: ServerAPI | None = None) -> int:
    """Bucle del agente como servicio (Windows: ``packaging/windows_service.py``; Linux: unidad systemd). Termina limpio cuando ``stop()`` es verdadero."""
    namespace = argparse.Namespace(home=home, config=config_path, no_keyring=False)
    config, device = load_config(namespace)
    if device.revoked:
        return 3
    agent = build_agent(config, device, api)
    agent.run_forever(stop)
    return 3 if device.revoked else 0


def cmd_status(args: argparse.Namespace) -> int:
    config, home = load_config(args)
    info: dict[str, Any] = {"enrolled": home.is_enrolled(), "revoked": home.revoked, "agent_version": __version__, "source": config.source.kind, "key_backend": home.keystore.backend}
    if home.is_enrolled():
        info["device"] = home.device_info()
        state = AgentState(home.state_store)
        spool = Spool(home.spool_dir, home.keystore, config.agent.spool_max_mb * 1024 * 1024)
        info["entities"] = {n: {"watermark": state.entity(n).watermark, "rows_sent": state.entity(n).rows_sent, "pending_batches": spool.pending(n),
                                 "run_in_progress": state.entity(n).run is not None, "blocked": state.entity(n).blocked, "last_error": state.entity(n).last_error} for n in config.entities()}
        info["spool_bytes"] = spool.total_bytes()
    print(json.dumps(info, ensure_ascii=False, indent=1))
    return 0


def _report(report: RunReport) -> dict[str, Any]:
    return {"batches_sent": report.batches_sent, "rows_sent": report.rows_sent, "interrupted": report.interrupted, "revoked": report.revoked,
            "entities": {n: {"status": e.status, "batches_sent": e.batches_sent, "rows_sent": e.rows_sent, "error": e.error} for n, e in report.entities.items()}}


def _exit_code(report: RunReport) -> int:
    if report.revoked:
        return 3
    return 4 if any(e.status in ("error", "blocked", "offline", "paused") for e in report.entities.values()) else 0


def parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="predictily-bridge", description="Predictily Bridge: agente de SOLO LECTURA (ADR-V25-10).")
    p.add_argument("--home", help="directorio del agente (por defecto $PREDICTILY_BRIDGE_HOME o ~/.predictily-bridge)")
    p.add_argument("--config", help="ruta de config.toml (por defecto <home>/config.toml)")
    p.add_argument("--no-keyring", action="store_true", help="no usar el almacén de claves del sistema (usa master.key 0600)")
    p.add_argument("--version", action="version", version=f"predictily-bridge {__version__}")
    sub = p.add_subparsers(dest="command", required=True)
    c = sub.add_parser("check-config", help="valida configuración y plantillas")
    c.add_argument("--pin", action="store_true", help="muestra la huella para fijar templates_sha256")
    c.set_defaults(func=cmd_check_config)
    s = sub.add_parser("secret", help="secretos de la fuente cifrados")
    ss = s.add_subparsers(dest="secret_command", required=True)
    s_set = ss.add_parser("set", help="guarda un secreto (valor por entrada estándar o petición interactiva)")
    s_set.add_argument("name")
    s_set.set_defaults(func=cmd_secret_set)
    t = sub.add_parser("test-source", help="prueba la conexión de solo lectura al origen")
    t.set_defaults(func=cmd_test_source)
    e = sub.add_parser("enroll", help="registra este equipo")
    e.add_argument("--code", required=True, help="código de registro de un solo uso (BRG-XXXX-…)")
    e.add_argument("--name", help="nombre del dispositivo")
    e.add_argument("--force", action="store_true")
    e.set_defaults(func=cmd_enroll)
    r = sub.add_parser("run", help="extrae y envía")
    r.add_argument("--once", action="store_true", help="un solo ciclo y termina")
    r.add_argument("--max-batches", type=int, default=None, help="interrumpe de forma limpia tras N lotes confirmados (pruebas de reanudación)")
    r.set_defaults(func=cmd_run)
    st = sub.add_parser("status", help="estado local sin secretos")
    st.set_defaults(func=cmd_status)
    return p


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    setup_logging(os.environ.get("PREDICTILY_BRIDGE_LOG_LEVEL", "INFO"), os.environ.get("PREDICTILY_BRIDGE_LOG_FILE"))
    try:
        return int(args.func(args))
    except (cfgmod.ConfigError, InsecurePermissions) as e:
        print(f"Configuración: {redact(str(e))}", file=sys.stderr)
        return 2
    except NotEnrolled as e:
        print(str(e), file=sys.stderr)
        return 2
    except DeviceRevoked:
        print("Este dispositivo fue revocado por la empresa.", file=sys.stderr)
        return 3
    except (ServerRejected, SourceError, RetryExhausted, crypto.CryptoError) as e:
        print(f"Error: {redact(str(e))}", file=sys.stderr)
        return 1


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
