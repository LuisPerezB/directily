"""Diagnóstico del entorno de entrenamiento privado (`diagnose`): qué está listo para entrenar, firmar, publicar y servir.

NUNCA imprime secretos: ni material de claves (la privada se reporta como «configurada» con la huella SHA-256 de su clave PÚBLICA derivada y su identificador), ni la contraseña
de la cadena de conexión (solo servidor, puerto, base y usuario), ni claves de acceso o secretos del almacén (solo controlador, bucket, región y host del
endpoint). Las pruebas fijan valores centinela en el entorno y comprueban que no aparecen en la salida.
"""

from __future__ import annotations

import platform
import re
import sys
from importlib import metadata
from pathlib import Path
from typing import Any

from sqlalchemy import create_engine, text
from sqlalchemy.engine import make_url

from predictily_engine import __version__
from predictily_engine.platform import signing
from predictily_engine.platform.object_store import ObjectStoreError, ObjectStoreUnavailable, build_store

OK, WARN, FAIL = "ok", "warn", "fail"
INVALID = "<no válido>"

# Reglas ESTRICTAS de lo que `diagnose` imprime de la configuración (revisión W4-B · M1). Un valor que no cumple su patrón se muestra como «<no válido>»: una URL de conexión
# con una arroba sin codificar en la contraseña se analiza mal («host» = parte de la contraseña) y no debe llegar a la salida.
_HOST = re.compile(r"^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9_-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9_-]{0,61}[A-Za-z0-9])?)*$|^\[?[0-9A-Fa-f:.]{2,45}\]?$")
_NAME = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9_.-]{0,62}$")
_DRIVER = re.compile(r"^[A-Za-z][A-Za-z0-9+.-]{0,40}$")
_BUCKET = re.compile(r"^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$")
_REGION = re.compile(r"^[a-z]{2}(-[a-z0-9]+){1,3}$")
_PATH = re.compile(r"^[A-Za-z0-9._/ +,=:~-]{1,400}$")
_SSE = ("AES256", "aws:kms")
# Forma estricta de una URL de conexión: la contraseña NO puede contener «@», «/», «?» ni «#» (deben ir codificados en porcentaje).
_DB_URL = re.compile(r"^(?P<driver>[A-Za-z][A-Za-z0-9+.-]*)://(?:(?P<user>[^:@/?#]*)(?::(?P<password>[^@/?#]*))?@)?"
                     r"(?P<host>\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_.-]*)(?::(?P<port>[0-9]{1,5}))?(?:/(?P<database>[^?#]*))?(?:\?.*)?$")


def safe(value: Any, pattern: re.Pattern[str] | None = None, *, allowed: tuple[str, ...] | None = None) -> Any:
    """`value` si cumple su regla; si no, «<no válido>». None se conserva (el campo no está definido)."""
    if value is None:
        return None
    if allowed is not None:
        return value if value in allowed else INVALID
    return value if isinstance(value, str) and pattern is not None and pattern.match(value) else INVALID


def describe_database_url(url_text: str) -> dict[str, Any] | None:
    """Servidor, puerto, base y usuario de una URL de conexión SIN la contraseña; None si no tiene la forma estricta (p. ej. una «@» sin codificar en la contraseña)."""
    match = _DB_URL.match(url_text or "")
    if match is None:
        return None
    port = match.group("port")
    return {"driver": safe(match.group("driver"), _DRIVER), "host": safe(match.group("host") or None, _HOST), "port": int(port) if port and int(port) <= 65535 else None,
            "database": safe(match.group("database") or None, _NAME), "user": safe(match.group("user") or None, _NAME)}
LIBRARIES = ["lightgbm", "scikit-learn", "pandas", "numpy", "joblib", "statsforecast", "mlforecast", "optuna", "cryptography", "pyarrow", "boto3", "SQLAlchemy",
             "psycopg"]
REQUIRED_TABLES = ("model_registry", "model_registry_events")
PUBLICATION_COLUMNS = ("published_by", "published_env", "published_at", "published_object_key", "approved_by", "approved_at")


def _check(checks: list[dict[str, str]], name: str, status: str, detail: str) -> None:
    checks.append({"name": name, "status": status, "detail": detail})


def _versions() -> dict[str, str | None]:
    out: dict[str, str | None] = {}
    for lib in LIBRARIES:
        try:
            out[lib] = metadata.version(lib)
        except metadata.PackageNotFoundError:
            out[lib] = None
    return out


def _signing(checks: list[dict[str, str]]) -> dict[str, Any]:
    try:
        config = signing.load_config()
    except signing.SigningConfigError as exc:
        _check(checks, "firma", FAIL, f"configuración de claves inválida: {exc}")
        return {"configured": False, "error": str(exc)}
    info: dict[str, Any] = {"key_id": config.key_id, "private_key_configured": config.private_key is not None,
                            "trusted_keys": [{"key_id": kid, "fingerprint_sha256": signing.public_key_fingerprint(signing._b64(pub.public_bytes_raw()))}
                                             for kid, pub in sorted(config.trusted.items())],
                            "allow_unsigned_artifacts": config.allow_unsigned}
    if config.private_key is not None:
        derived = signing._b64(config.private_key.public_key().public_bytes_raw())
        info["signing_public_key_fingerprint_sha256"] = signing.public_key_fingerprint(derived)
        trusted = config.trusted.get(config.key_id or "")
        if trusted is None:
            _check(checks, "firma", WARN, "la clave pública de la clave de firma no está en ENGINE_TRUSTED_PUBLIC_KEYS: lo que firme este entorno no se verificará aquí")
        elif signing._b64(trusted.public_bytes_raw()) != derived:
            _check(checks, "firma", FAIL, "ENGINE_TRUSTED_PUBLIC_KEYS trae para este key_id una clave distinta de la derivada de la privada")
        else:
            _check(checks, "firma", OK, "clave de firma configurada y su pública es de confianza")
    else:
        _check(checks, "firma", WARN, "sin clave privada (ENGINE_SIGNING_PRIVATE_KEY): se puede verificar pero no firmar ni empaquetar")
    if not config.trusted:
        _check(checks, "claves_de_confianza", WARN, "ENGINE_TRUSTED_PUBLIC_KEYS vacío: ningún artefacto verificará")
    else:
        _check(checks, "claves_de_confianza", OK, f"{len(config.trusted)} clave(s) pública(s) de confianza")
    if config.allow_unsigned:
        _check(checks, "artefactos_sin_firma", WARN, "ENGINE_ALLOW_UNSIGNED_ARTIFACTS está activo: solo para un entorno local con legados (nunca staging ni producción)")
    return info


def _database(checks: list[dict[str, str]], require: bool) -> dict[str, Any]:
    from predictily_engine.config import Settings

    url_text = Settings().database_url          # (sin caché: el diagnóstico mira el entorno de ESTA ejecución)
    described = describe_database_url(url_text)
    if described is None:
        # No se imprime NADA de la URL ni se intenta conectar con credenciales mal analizadas.
        _check(checks, "base_de_datos", FAIL if require else WARN,
               "ENGINE_DATABASE_URL no tiene la forma esperada (postgresql+psycopg://usuario:clave@servidor:puerto/base): codifique en porcentaje los caracteres especiales de la clave (@ → %40)")
        return {"configured": False, "url": INVALID}
    try:
        url = make_url(url_text)
    except Exception:  # noqa: BLE001
        _check(checks, "base_de_datos", FAIL, "ENGINE_DATABASE_URL no es una URL válida")
        return {"configured": False, "url": INVALID}
    info: dict[str, Any] = {**described, "reachable": False}
    bad = FAIL if require else WARN
    engine = create_engine(url, connect_args={"connect_timeout": 5}, pool_pre_ping=False)
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
            info["reachable"] = True
            tables = {r[0] for r in conn.execute(text("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()")).all()}
            columns = {r[0] for r in conn.execute(text(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'model_registry'")).all()}
        missing_tables = [t for t in REQUIRED_TABLES if t not in tables]
        missing_columns = [c for c in PUBLICATION_COLUMNS if c not in columns]
        info["schema"] = {"missing_tables": missing_tables, "missing_publication_columns": missing_columns}
        if missing_tables or missing_columns:
            _check(checks, "esquema", bad, "faltan tablas o columnas del ciclo de vida del registro (migración 2026_03_12_000100): migre la base")
        else:
            _check(checks, "base_de_datos", OK, "conexión correcta y esquema del registro con publicación y aprobación")
    except Exception as exc:  # noqa: BLE001  solo el tipo: el mensaje del driver puede incluir datos de conexión
        info["error"] = type(exc).__name__
        _check(checks, "base_de_datos", bad, f"no se pudo conectar ({type(exc).__name__}); revise ENGINE_DATABASE_URL")
    finally:
        engine.dispose()
    return info


def _clean_store_info(info: dict[str, Any]) -> dict[str, Any]:
    """Lo que se imprime del almacén, bajo las mismas reglas estrictas (raíz, bucket, región, host del endpoint, cifrado)."""
    out = dict(info)
    if "root" in out:
        out["root"] = safe(out["root"], _PATH)
    if "root_source" in out:
        out["root_source"] = safe(out["root_source"], allowed=("ENGINE_OBJECT_STORE_ROOT", "monorepo_backend_default", "configured"))
    if "bucket" in out:
        out["bucket"] = safe(out["bucket"], _BUCKET)
    if "region" in out:
        out["region"] = safe(out["region"], _REGION)
    if "endpoint_host" in out:
        out["endpoint_host"] = safe(out["endpoint_host"], _HOST)
    if "server_side_encryption" in out:
        out["server_side_encryption"] = safe(out["server_side_encryption"], allowed=_SSE)
    return out


def _store(checks: list[dict[str, str]], require: bool) -> dict[str, Any]:
    bad = FAIL if require else WARN
    try:
        store = build_store()
    except ObjectStoreUnavailable as exc:
        _check(checks, "almacén_de_objetos", bad, str(exc))
        return {"configured": False}
    info = _clean_store_info(store.describe())
    if store.driver == "local":
        root = Path(store.root)
        info["exists"] = root.is_dir()
        if not root.is_dir():
            _check(checks, "almacén_de_objetos", bad, "la raíz local no existe")
        else:
            import os

            info["writable"] = os.access(root, os.W_OK)
            _check(checks, "almacén_de_objetos", OK if info["writable"] else bad,
                   "controlador local listo" + ("" if info["writable"] else " pero sin permiso de escritura"))
            if info.get("root_source") == "monorepo_backend_default":
                _check(checks, "almacén_raíz", WARN, "se usa la raíz por defecto del monorepo (backend/storage/app/private); fije ENGINE_OBJECT_STORE_ROOT explícitamente")
    else:
        try:
            store.exists("tenants/diagnose/models/probe")
            info["reachable"] = True
            _check(checks, "almacén_de_objetos", OK, "controlador s3 accesible")
        except (ObjectStoreError, ObjectStoreUnavailable) as exc:
            info["reachable"] = False
            _check(checks, "almacén_de_objetos", bad, str(exc))
    return info


def _artifacts_dir(checks: list[dict[str, str]]) -> dict[str, Any]:
    from predictily_engine.config import Settings

    path = Path(Settings().artifacts_dir)
    exists = path.exists()
    _check(checks, "directorio_de_artefactos", OK if exists else WARN, "existe" if exists else "no existe todavía (se crea al entrenar)")
    return {"path": safe(str(path), _PATH), "exists": exists}


def diagnose(*, require: set[str] | None = None) -> dict[str, Any]:
    """Informe del entorno. `require` ⊂ {db, object-store, signing}: lo requerido que falte se reporta como fallo (si no, como aviso)."""
    require = require or set()
    checks: list[dict[str, str]] = []
    report: dict[str, Any] = {
        "engine_version": __version__, "python": sys.version.split()[0], "platform": platform.platform(),
        "compute": {"device": "cpu", "note": "CPU por defecto; GPU opcional cuando la biblioteca la soporte, sin dependencia obligatoria"},
        "libraries": _versions(), "signing": _signing(checks), "database": _database(checks, "db" in require),
        "object_store": _store(checks, "object-store" in require), "artifacts_dir": _artifacts_dir(checks),
    }
    if "signing" in require:
        s = report["signing"]
        if not s.get("private_key_configured") or not s.get("trusted_keys"):
            _check(checks, "firma_requerida", FAIL, "se requiere poder firmar y verificar: falta la clave privada o las claves de confianza")
    if not report["libraries"].get("optuna"):
        _check(checks, "optuna", WARN, "Optuna no está instalado: el comando tune no estará disponible")
    if report["libraries"].get("boto3") is None:
        _check(checks, "boto3", WARN, "boto3 no está instalado: el controlador s3 no estará disponible (extra «s3»)")
    report["checks"] = checks
    report["ok"] = not any(c["status"] == FAIL for c in checks)
    return report
