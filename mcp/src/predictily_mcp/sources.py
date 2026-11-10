"""Perfiles de conexión al sistema del cliente.

Las credenciales de la base del cliente NUNCA pasan por el modelo: el agente solo nombra un perfil
(`credentials_profile`) y este módulo lee usuario y contraseña del entorno o de un archivo local del servidor MCP.
De ahí van directo a la API, que las guarda cifradas (`data_sources.credentials_encrypted`) y no las devuelve.

Dos orígenes (el entorno gana si un nombre se repite):

- Variables `PREDICTILY_SOURCE_<NOMBRE>_USERNAME` / `_PASSWORD` y, opcionales, `_CONNECTOR`, `_HOST`, `_PORT`,
  `_DATABASE`, `_SSLMODE`, `_ENCRYPT`, `_TRUST_SERVER_CERTIFICATE`, `_SOURCE_TIMEZONE`, `_SYNC_SCHEDULE`,
  `_CONNECT_TIMEOUT_SECONDS`, `_STATEMENT_TIMEOUT_SECONDS`.
- Un archivo JSON fuera del repositorio (`PREDICTILY_MCP_SOURCE_PROFILES`): `{"<nombre>": {"username": "…",
  "password": "…", "connector": "sql_sqlserver", "host": "…", "port": 1433, "database": "…", …}}`.
"""

import json
import os
import re
import stat
from collections.abc import Mapping
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .config import ConfigError

SQL_CONNECTORS = ("sql_sqlserver", "sql_postgresql", "sql_mysql")

# Claves de `config` que acepta el backend (AbstractSqlConnector::validateInput) y su tipo.
CONFIG_KEYS: dict[str, type] = {
    "host": str,
    "port": int,
    "database": str,
    "sslmode": str,
    "encrypt": bool,
    "trust_server_certificate": bool,
    "source_timezone": str,
    "sync_schedule": str,
    "connect_timeout_seconds": int,
    "statement_timeout_seconds": int,
}

_NAME = re.compile(r"^[a-z0-9][a-z0-9_\-]{0,39}$")
_ENV = re.compile(r"^PREDICTILY_SOURCE_([A-Z0-9_]+?)_(USERNAME|PASSWORD|CONNECTOR|" + "|".join(k.upper() for k in CONFIG_KEYS) + r")$")
_TRUE = {"1", "true", "yes", "on", "si", "sí"}
_FALSE = {"0", "false", "no", "off"}


class ProfileError(Exception):
    """El perfil pedido no existe o está incompleto. El mensaje nunca incluye un valor secreto."""


@dataclass(frozen=True)
class SourceProfile:
    name: str
    connector: str | None = None
    config: dict[str, Any] = field(default_factory=dict)
    username: str = field(default="", repr=False)
    password: str = field(default="", repr=False)

    def credentials(self) -> dict[str, str]:
        if not self.username or not self.password:
            raise ProfileError(f"El perfil «{self.name}» no tiene usuario y contraseña completos en el servidor MCP.")
        return {"username": self.username, "password": self.password}

    def describe(self) -> dict[str, Any]:
        """Lo que puede ver el modelo: nombre, conector y QUÉ trae configurado. Nunca host, usuario ni contraseña."""
        return {
            "profile": self.name,
            "connector": self.connector,
            "has_credentials": bool(self.username and self.password),
            "provides": sorted(self.config),
        }


def _coerce(profile: str, key: str, value: Any) -> Any:
    kind = CONFIG_KEYS[key]
    if kind is bool:
        if isinstance(value, bool):
            return value
        text = str(value).strip().lower()
        if text in _TRUE:
            return True
        if text in _FALSE:
            return False
        raise ConfigError(f"Perfil «{profile}»: {key} debe ser true o false.")
    if kind is int:
        try:
            return int(str(value).strip())
        except ValueError:
            raise ConfigError(f"Perfil «{profile}»: {key} debe ser un número entero.") from None
    text = str(value).strip()
    if not text:
        raise ConfigError(f"Perfil «{profile}»: {key} está vacío.")
    return text


def _build(name: str, raw: Mapping[str, Any]) -> SourceProfile:
    if not _NAME.match(name):
        raise ConfigError("Nombre de perfil no válido: use minúsculas, números, guion o guion bajo (máx. 40).")
    unknown = sorted(set(raw) - set(CONFIG_KEYS) - {"username", "password", "connector"})
    if unknown:
        raise ConfigError(f"Perfil «{name}»: claves no reconocidas: {', '.join(unknown)}.")
    connector = raw.get("connector")
    if connector is not None and connector not in SQL_CONNECTORS:
        raise ConfigError(f"Perfil «{name}»: connector debe ser uno de {', '.join(SQL_CONNECTORS)}.")
    return SourceProfile(
        name=name,
        connector=connector,
        config={key: _coerce(name, key, raw[key]) for key in CONFIG_KEYS if raw.get(key) not in (None, "")},
        username=str(raw.get("username") or ""),
        password=str(raw.get("password") or ""),
    )


def _from_file(path: Path) -> dict[str, dict[str, Any]]:
    if os.name == "posix" and stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise ConfigError("El archivo de perfiles contiene contraseñas: restrinja sus permisos (chmod 600).")
    try:
        content = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        # Sin el detalle del analizador: podría citar un fragmento del archivo (una contraseña).
        raise ConfigError("PREDICTILY_MCP_SOURCE_PROFILES no es un JSON legible.") from None
    if not isinstance(content, dict) or not all(isinstance(v, dict) for v in content.values()):
        raise ConfigError("PREDICTILY_MCP_SOURCE_PROFILES debe ser un objeto {nombre: {…}}.")
    return {str(name).strip().lower(): dict(values) for name, values in content.items()}


def _from_env(env: Mapping[str, str]) -> dict[str, dict[str, Any]]:
    found: dict[str, dict[str, Any]] = {}
    for variable, value in env.items():
        match = _ENV.match(variable)
        if match and value != "":
            found.setdefault(match.group(1).lower(), {})[match.group(2).lower()] = value
    return found


class SourceProfiles:
    def __init__(self, profiles: Mapping[str, SourceProfile] | None = None) -> None:
        self._profiles = dict(profiles or {})

    @classmethod
    def load(cls, profiles_file: Path | None, env: Mapping[str, str] | None = None) -> "SourceProfiles":
        raw = _from_file(profiles_file) if profiles_file is not None else {}
        for name, values in _from_env(os.environ if env is None else env).items():
            raw[name] = {**raw.get(name, {}), **values}
        return cls({name: _build(name, values) for name, values in raw.items()})

    def names(self) -> list[str]:
        return sorted(self._profiles)

    def get(self, name: str) -> SourceProfile:
        profile = self._profiles.get(name.strip().lower())
        if profile is None:
            available = ", ".join(self.names()) or "ninguno configurado"
            raise ProfileError(
                f"No existe el perfil de conexión «{name}» en el servidor MCP (disponibles: {available}). "
                "Quien opera el servidor debe definirlo con PREDICTILY_SOURCE_<NOMBRE>_USERNAME/_PASSWORD o en el archivo "
                "PREDICTILY_MCP_SOURCE_PROFILES; las credenciales del cliente no se escriben en el chat."
            )
        return profile

    def describe(self) -> list[dict[str, Any]]:
        return [self._profiles[name].describe() for name in self.names()]
