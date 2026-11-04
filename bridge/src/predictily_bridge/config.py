"""Configuración local del agente (TOML) y plantillas de consulta versionadas.

- ``config.toml`` NO contiene secretos: la contraseña de la fuente se toma del almacén cifrado (``password_secret``) o de una variable de
  entorno (``password_env``); una clave ``password`` en claro se RECHAZA. El archivo debe tener permisos 0600/0640 sin acceso de «otros».
- Plantillas: una consulta SELECT por entidad y versión, escrita/aprobada LOCALMENTE por el administrador del cliente (o un paquete de
  plantillas de Predictily que él revisa). El servidor nunca envía SQL: ningún endpoint lo entrega. Cada plantilla se valida con el guardia
  SELECT (puerto de SqlQueryGuard) al cargar, y la configuración puede fijar la huella del conjunto (``templates_sha256``): si alguien cambia
  una consulta sin volver a fijarla, el agente se niega a arrancar.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import stat
import tomllib
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from predictily_bridge import sqlguard
from predictily_bridge.fsutil import POSIX

ENTITIES = ("sales", "products", "inventory", "commitments", "branches", "suppliers", "lead_times", "promotions", "calendar_events", "unit_conversions", "warehouses")
SQL_KINDS = {"postgresql": "pgsql", "mysql": "mysql", "sqlserver": "sqlsrv", "sqlite": "sqlite"}
FILE_KINDS = ("csv", "excel")
_IDENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,62}$")
_TEMPLATE_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{2,79}$")


class ConfigError(Exception):
    """Configuración o plantilla no válida. El mensaje nunca incluye secretos."""


@dataclass(frozen=True)
class Template:
    id: str
    entity: str
    version: int
    kind: str  # postgresql | mysql | sqlserver | sqlite | csv | excel
    sql: str | None = None
    recorded_at_column: str | None = None
    order_by: tuple[str, ...] = ()
    path: str | None = None
    delimiter: str = ","
    encoding: str = "utf-8"
    sheet: str | None = None
    columns: dict[str, str] = field(default_factory=dict)  # campo canónico -> encabezado de origen (archivos)

    @property
    def dialect(self) -> str:
        return SQL_KINDS[self.kind]

    def fingerprint(self) -> str:
        """SHA-256 del contenido de la plantilla (se informa al servidor en el latido y se fija en ``templates_sha256``)."""
        return hashlib.sha256(json.dumps(self.as_dict(), sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")).hexdigest()

    def as_dict(self) -> dict[str, Any]:
        return {
            "id": self.id, "entity": self.entity, "version": self.version, "kind": self.kind, "sql": self.sql, "recorded_at_column": self.recorded_at_column,
            "order_by": list(self.order_by), "path": self.path, "delimiter": self.delimiter, "encoding": self.encoding, "sheet": self.sheet, "columns": dict(sorted(self.columns.items())),
        }


@dataclass(frozen=True)
class SourceConfig:
    kind: str
    host: str | None = None
    port: int | None = None
    database: str | None = None
    user: str | None = None
    password_secret: str | None = None
    password_env: str | None = None
    path: str | None = None
    sslmode: str = "require"  # TLS obligatorio por defecto; para un PostgreSQL local sin TLS use sslmode = "prefer" o "disable" de forma EXPLÍCITA (solo pruebas)
    driver: str = "ODBC Driver 18 for SQL Server"
    encrypt: bool = True
    trust_server_certificate: bool = False
    statement_timeout_seconds: int = 120
    connect_timeout_seconds: int = 15


@dataclass(frozen=True)
class AgentConfig:
    batch_rows: int = 5000
    spool_max_mb: int = 512
    overlap_minutes: int = 120
    interval_seconds: int = 900
    heartbeat_seconds: int = 60
    max_batches_per_cycle: int = 0
    source_timezone: str | None = None
    require_readonly_account: bool | None = None  # None = por defecto: true en mysql y sqlserver (sin modo de solo lectura robusto), false en el resto
    templates_sha256: str | None = None
    templates_file: str | None = None
    log_level: str = "INFO"


@dataclass(frozen=True)
class ServerConfig:
    url: str
    ca_bundle: str | None = None
    allow_insecure_localhost: bool = False
    timeout_seconds: int = 30


@dataclass(frozen=True)
class Config:
    server: ServerConfig
    agent: AgentConfig
    source: SourceConfig
    templates: tuple[Template, ...]
    path: Path | None = None

    def template_for(self, entity: str) -> Template | None:
        """Plantilla vigente de la entidad: la de MAYOR versión declarada (las anteriores se conservan como historial)."""
        candidates = [t for t in self.templates if t.entity == entity]
        return max(candidates, key=lambda t: t.version) if candidates else None

    def entities(self) -> list[str]:
        return sorted({t.entity for t in self.templates})

    @property
    def require_readonly_account(self) -> bool:
        """¿El agente se niega a extraer si la cuenta del origen puede escribir? Explícito en ``[agent]``; si no, SÍ en MySQL y SQL Server (donde el motor no
        garantiza el solo lectura como PostgreSQL/SQLite) y NO en el resto (revisión adversarial B-9)."""
        if self.agent.require_readonly_account is not None:
            return bool(self.agent.require_readonly_account)
        return self.source.kind in ("mysql", "sqlserver")

    def templates_fingerprint(self) -> str:
        return templates_fingerprint(self.templates)


def templates_fingerprint(templates: tuple[Template, ...] | list[Template]) -> str:
    parts = sorted((t.entity, t.version, t.fingerprint()) for t in templates)
    return hashlib.sha256(json.dumps(parts, separators=(",", ":")).encode()).hexdigest()


def load(path: Path, *, check_permissions: bool = True) -> Config:
    if not path.exists():
        raise ConfigError(f"No existe el archivo de configuración: {path}")
    if check_permissions and POSIX:
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o007:
            raise ConfigError(f"{path} es legible por «otros» usuarios (modo {mode:o}); ejecute chmod 640 o 600.")
    try:
        raw = tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as e:
        raise ConfigError(f"config.toml no es TOML válido: {e}") from None
    return parse(raw, base=path.parent, path=path)


def parse(raw: dict[str, Any], *, base: Path | None = None, path: Path | None = None) -> Config:
    unknown = set(raw) - {"server", "agent", "source", "templates"}
    if unknown:
        raise ConfigError(f"Secciones desconocidas en la configuración: {', '.join(sorted(unknown))}.")
    server = _server(raw.get("server") or {})
    agent = _agent(raw.get("agent") or {})
    source = _source(raw.get("source") or {})
    templates = [*(raw.get("templates") or [])]
    if agent.templates_file:
        file = Path(agent.templates_file)
        file = file if file.is_absolute() or base is None else base / file
        try:
            templates.extend(tomllib.loads(file.read_text(encoding="utf-8")).get("templates") or [])
        except (OSError, tomllib.TOMLDecodeError) as e:
            raise ConfigError(f"No se pudo leer el archivo de plantillas {file}: {e}") from None
    parsed = tuple(_template(t, source.kind) for t in templates)
    seen: set[tuple[str, int]] = set()
    for t in parsed:
        if (t.entity, t.version) in seen:
            raise ConfigError(f"Plantilla duplicada: {t.entity} versión {t.version}.")
        seen.add((t.entity, t.version))
    if not parsed:
        raise ConfigError("Declare al menos una plantilla de consulta ([[templates]]).")
    if source.kind in SQL_KINDS and any(t.recorded_at_column for t in parsed) and not agent.source_timezone:
        raise ConfigError("[agent] source_timezone es obligatoria cuando una plantilla SQL usa recorded_at_column: el «desde» incremental se expresa en la hora local del origen.")
    if agent.templates_sha256 and agent.templates_sha256.lower() != templates_fingerprint(parsed):
        raise ConfigError("La huella de las plantillas no coincide con templates_sha256: alguien cambió una consulta sin volver a fijarla "
                          "(use «predictily-bridge templates --pin» tras revisarlas).")
    return Config(server, agent, source, parsed, path)


def _check_keys(section: str, data: dict[str, Any], allowed: set[str]) -> None:
    extra = set(data) - allowed
    if extra:
        hint = " Las contraseñas no van en config.toml: use password_secret o password_env." if extra & {"password", "pwd", "passwd", "secret", "token"} else ""
        raise ConfigError(f"Claves desconocidas en [{section}]: {', '.join(sorted(extra))}.{hint}")


def _server(d: dict[str, Any]) -> ServerConfig:
    _check_keys("server", d, {"url", "ca_bundle", "allow_insecure_localhost", "timeout_seconds"})
    url = str(d.get("url") or "").rstrip("/")
    if not url:
        raise ConfigError("[server] url es obligatoria.")
    cfg = ServerConfig(url, d.get("ca_bundle"), bool(d.get("allow_insecure_localhost", False)), int(d.get("timeout_seconds", 30)))
    assert_server_url(cfg)
    return cfg


def assert_server_url(cfg: ServerConfig) -> None:
    """HTTPS obligatorio. Única excepción explícita y para pruebas locales: http a localhost/127.0.0.1/::1 con ``allow_insecure_localhost``."""
    from urllib.parse import urlsplit

    parts = urlsplit(cfg.url)
    if parts.scheme == "https" and parts.hostname:
        return
    if parts.scheme == "http" and cfg.allow_insecure_localhost and parts.hostname in ("localhost", "127.0.0.1", "::1"):
        return
    raise ConfigError("[server] url debe ser https:// (http solo a localhost con allow_insecure_localhost = true, para pruebas).")


def _agent(d: dict[str, Any]) -> AgentConfig:
    allowed = set(AgentConfig.__dataclass_fields__)
    _check_keys("agent", d, allowed)
    cfg = AgentConfig(**{k: v for k, v in d.items()})
    if not 1 <= cfg.batch_rows <= 50_000:
        raise ConfigError("[agent] batch_rows debe estar entre 1 y 50000.")
    if not 1 <= cfg.spool_max_mb <= 102_400:
        raise ConfigError("[agent] spool_max_mb debe estar entre 1 y 102400.")
    if not 0 <= cfg.overlap_minutes <= 10_080:
        raise ConfigError("[agent] overlap_minutes debe estar entre 0 y 10080.")
    if cfg.interval_seconds < 10 or cfg.heartbeat_seconds < 5:
        raise ConfigError("[agent] interval_seconds >= 10 y heartbeat_seconds >= 5.")
    if cfg.source_timezone is not None:
        _check_timezone(cfg.source_timezone)
    return cfg


def _check_timezone(name: str) -> None:
    from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

    try:
        ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError):
        raise ConfigError(f"Zona horaria no válida: {name}.") from None


def _source(d: dict[str, Any]) -> SourceConfig:
    _check_keys("source", d, set(SourceConfig.__dataclass_fields__))
    cfg = SourceConfig(**d)
    if cfg.kind not in (*SQL_KINDS, *FILE_KINDS):
        raise ConfigError(f"[source] kind debe ser uno de: {', '.join((*SQL_KINDS, *FILE_KINDS))}.")
    if cfg.kind in ("postgresql", "mysql", "sqlserver"):
        for key in ("host", "database", "user"):
            if not getattr(cfg, key):
                raise ConfigError(f"[source] {key} es obligatorio para {cfg.kind}.")
        if not _HOST.match(str(cfg.host)):
            raise ConfigError("[source] host no es un nombre de host o IP válido.")
        if not re.match(r"^[A-Za-z0-9._\-]+$", str(cfg.database)):
            raise ConfigError("[source] database solo admite letras, números, punto y guion.")
        if cfg.password_secret is None and cfg.password_env is None:
            raise ConfigError("[source] indique password_secret (almacén cifrado) o password_env (variable de entorno).")
    if cfg.kind == "sqlite" and not cfg.path:
        raise ConfigError("[source] path es obligatorio para sqlite.")
    if cfg.port is not None and not 1 <= int(cfg.port) <= 65535:
        raise ConfigError("[source] port no válido.")
    if cfg.sslmode not in ("disable", "allow", "prefer", "require", "verify-ca", "verify-full"):
        raise ConfigError("[source] sslmode no válido.")
    return cfg


_HOST = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$|^[0-9a-fA-F:]+$")


def _template(d: dict[str, Any], source_kind: str) -> Template:
    allowed = set(Template.__dataclass_fields__) - {"dialect"}
    extra = set(d) - allowed
    if extra:
        raise ConfigError(f"Claves desconocidas en una plantilla: {', '.join(sorted(extra))}.")
    entity = str(d.get("entity", ""))
    if entity not in ENTITIES:
        raise ConfigError(f"Entidad no permitida en la plantilla: «{entity}». Use una de: {', '.join(ENTITIES)}.")
    tid = str(d.get("id", ""))
    if not _TEMPLATE_ID.match(tid):
        raise ConfigError(f"Identificador de plantilla no válido: «{tid}» (minúsculas, dígitos, punto, guion).")
    version = d.get("version")
    if not isinstance(version, int) or isinstance(version, bool) or version < 1:
        raise ConfigError(f"La plantilla {tid} necesita version entera >= 1.")
    kind = str(d.get("kind", source_kind))
    if kind != source_kind:
        raise ConfigError(f"La plantilla {tid} es de tipo {kind} pero la fuente es {source_kind}.")
    template = Template(
        id=tid, entity=entity, version=version, kind=kind, sql=d.get("sql"), recorded_at_column=d.get("recorded_at_column"),
        order_by=tuple(d.get("order_by") or ()), path=d.get("path"), delimiter=str(d.get("delimiter", ",")), encoding=str(d.get("encoding", "utf-8")),
        sheet=d.get("sheet"), columns=dict(d.get("columns") or {}),
    )
    if kind in SQL_KINDS:
        _validate_sql_template(template)
    else:
        if not template.path:
            raise ConfigError(f"La plantilla {tid} (archivos) necesita path.")
        if not template.columns:
            raise ConfigError(f"La plantilla {tid} (archivos) necesita columns: campo canónico → encabezado del archivo.")
        for canonical in template.columns:
            if not _IDENT.match(canonical):
                raise ConfigError(f"La plantilla {tid}: nombre de campo canónico no válido «{canonical}».")
        if template.sql:
            raise ConfigError(f"La plantilla {tid} (archivos) no admite sql.")
    return template


def _validate_sql_template(t: Template) -> None:
    if not t.sql:
        raise ConfigError(f"La plantilla {t.id} necesita sql (una sola sentencia SELECT).")
    try:
        sqlguard.validate(t.sql, t.dialect)
    except sqlguard.SqlQueryRejected as e:
        raise ConfigError(f"La plantilla {t.id} no cumple la política de solo lectura: {e} [{e.reason}]") from None
    if not t.order_by:
        raise ConfigError(f"La plantilla {t.id} necesita order_by: columnas del resultado que ordenan TOTALMENTE las filas (llave única) para reanudar sin repetir lotes.")
    for column in (*t.order_by, *( [t.recorded_at_column] if t.recorded_at_column else [] )):
        if not _IDENT.match(column):
            raise ConfigError(f"La plantilla {t.id}: nombre de columna no válido «{column}» (letras, dígitos y guion bajo).")
    if len(set(t.order_by)) != len(t.order_by):
        raise ConfigError(f"La plantilla {t.id}: order_by repite columnas.")


def password(source: SourceConfig, secrets_get) -> str | None:  # type: ignore[no-untyped-def]
    """Contraseña de la fuente: variable de entorno o almacén cifrado. ``None`` si la fuente no la usa."""
    if source.password_env:
        value = os.environ.get(source.password_env)
        if not value:
            raise ConfigError(f"La variable de entorno {source.password_env} (contraseña de la fuente) no está definida.")
        return value
    if source.password_secret:
        value = secrets_get(source.password_secret)
        if not value:
            raise ConfigError(f"El secreto «{source.password_secret}» no está en el almacén cifrado; guárdelo con «predictily-bridge secret set {source.password_secret}».")
        return value
    return None
