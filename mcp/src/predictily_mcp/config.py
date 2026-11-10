"""Configuración por variables de entorno. Ningún valor secreto se imprime ni se devuelve a un modelo."""

import ipaddress
import os
from collections.abc import Mapping
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlsplit


class ConfigError(Exception):
    """Configuración inválida: el servidor no arranca y explica qué variable corregir."""


APPROVAL_MODES = ("elicit", "allow", "deny")
ROW_SAMPLE_MODES = ("shapes", "full")

_TRUE = {"1", "true", "yes", "on", "si", "sí"}
_FALSE = {"0", "false", "no", "off", ""}


def _flag(env: Mapping[str, str], name: str, default: bool) -> bool:
    raw = env.get(name)
    if raw is None:
        return default
    value = raw.strip().lower()
    if value in _TRUE:
        return True
    if value in _FALSE:
        return False
    raise ConfigError(f"{name} debe ser true o false.")


def _choice(env: Mapping[str, str], name: str, default: str, allowed: tuple[str, ...]) -> str:
    value = (env.get(name) or default).strip().lower()
    if value not in allowed:
        raise ConfigError(f"{name} debe ser uno de: {', '.join(allowed)}.")
    return value


def _number(env: Mapping[str, str], name: str, default: float, minimum: float, maximum: float) -> float:
    raw = env.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        value = float(raw)
    except ValueError:
        raise ConfigError(f"{name} debe ser un número.") from None
    if not minimum <= value <= maximum:
        raise ConfigError(f"{name} debe estar entre {minimum:g} y {maximum:g}.")
    return value


def _is_loopback(host: str) -> bool:
    if host == "localhost" or host.endswith(".localhost"):
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


def _api_url(env: Mapping[str, str]) -> str:
    raw = (env.get("PREDICTILY_API_URL") or "").strip().rstrip("/")
    if not raw:
        raise ConfigError("Falta PREDICTILY_API_URL (p. ej. http://127.0.0.1:8000/api/v1).")
    parts = urlsplit(raw)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise ConfigError("PREDICTILY_API_URL debe ser una URL http(s) completa, incluido /api/v1.")
    if parts.username or parts.password or parts.query or parts.fragment:
        raise ConfigError("PREDICTILY_API_URL no admite usuario, contraseña, consulta ni fragmento.")
    # El token viaja en cada petición: en claro solo se acepta hacia el propio equipo.
    if parts.scheme == "http" and not _is_loopback(parts.hostname) and not _flag(env, "PREDICTILY_API_ALLOW_INSECURE_HTTP", False):
        raise ConfigError(
            "PREDICTILY_API_URL usa http hacia un equipo remoto: el token viajaría sin cifrar. "
            "Use https, o declare PREDICTILY_API_ALLOW_INSECURE_HTTP=true si la red ya está cifrada (VPN)."
        )
    return raw


def _directory(env: Mapping[str, str], name: str) -> Path | None:
    raw = (env.get(name) or "").strip()
    if not raw:
        return None
    path = Path(raw).expanduser()
    if not path.is_dir():
        raise ConfigError(f"{name} debe ser una carpeta que exista.")
    return path.resolve()


def _file(env: Mapping[str, str], name: str) -> Path | None:
    raw = (env.get(name) or "").strip()
    if not raw:
        return None
    path = Path(raw).expanduser()
    if not path.is_file():
        raise ConfigError(f"{name} debe ser un archivo que exista.")
    return path.resolve()


@dataclass(frozen=True)
class Settings:
    """Ajustes de un servidor. `api_token` nunca aparece en `repr` ni en mensajes."""

    api_url: str
    api_token: str | None = field(default=None, repr=False)
    tenant_id: str | None = None
    timeout_seconds: float = 60.0
    verify_tls: bool = True
    # Aprobaciones (mapeos, plantillas, confirmación de importaciones): elicit = pide confirmación a la persona en su
    # cliente MCP; allow = el agente aprueba directamente; deny = solo en la aplicación.
    approvals: str = "elicit"
    # Filas del origen hacia el modelo: shapes = solo la FORMA de los valores (letras→A, dígitos→9); full = tal cual.
    row_samples: str = "shapes"
    # Herramientas de análisis por cliente (códigos de comprador). Apagadas por defecto.
    customer_tools: bool = False
    import_dir: Path | None = None
    profiles_file: Path | None = None
    max_result_chars: int = 20000
    # true = transporte HTTP: el token llega en la cabecera Authorization de CADA petición; el del entorno se ignora.
    http_mode: bool = False
    poll_interval_seconds: float = 3.0

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None, *, http_mode: bool = False) -> "Settings":
        env = os.environ if env is None else env
        token = (env.get("PREDICTILY_API_TOKEN") or "").strip() or None
        if not http_mode and token is None:
            raise ConfigError(
                "Falta PREDICTILY_API_TOKEN: el token personal de la persona (se emite en Seguridad → Tokens o con "
                "POST /api/v1/auth/tokens, ligado a una empresa y con las habilidades mínimas)."
            )
        return cls(
            api_url=_api_url(env),
            api_token=None if http_mode else token,
            tenant_id=(env.get("PREDICTILY_TENANT_ID") or "").strip() or None,
            timeout_seconds=_number(env, "PREDICTILY_API_TIMEOUT", 60.0, 1.0, 900.0),
            verify_tls=_flag(env, "PREDICTILY_API_VERIFY_TLS", True),
            approvals=_choice(env, "PREDICTILY_MCP_APPROVALS", "elicit", APPROVAL_MODES),
            row_samples=_choice(env, "PREDICTILY_MCP_ROW_SAMPLES", "shapes", ROW_SAMPLE_MODES),
            customer_tools=_flag(env, "PREDICTILY_MCP_CUSTOMER_TOOLS", False),
            import_dir=_directory(env, "PREDICTILY_MCP_IMPORT_DIR"),
            profiles_file=_file(env, "PREDICTILY_MCP_SOURCE_PROFILES"),
            max_result_chars=int(_number(env, "PREDICTILY_MCP_MAX_RESULT_CHARS", 20000, 2000, 200000)),
            http_mode=http_mode,
        )
