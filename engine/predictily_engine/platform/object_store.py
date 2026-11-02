"""Almacén de objetos del motor (ADR-V25-08; V2.5 §9): interfaz `ObjectStore` con controladores `local` y `s3`.

Misma convención de claves y misma raíz local que el `BronzeStore` del backend (`FilesystemBronzeStore`, `config/bronze.php`):

- La clave la genera SIEMPRE el servidor, con el prefijo de la empresa: `tenants/<uuid de la empresa>/models/<core>/<versión>/<archivo>`
  (Bronze usa `tenants/<uuid>/bronze/...`). El `.manifest.json` firmado va junto al objeto (`<clave>.manifest.json`). Nunca se deriva de una
  ruta enviada por un cliente: `model_object_key` valida cada segmento con una expresión estricta y `validate_key` rechaza `..`, `//`,
  barras al inicio o al final y cualquier carácter fuera de `[A-Za-z0-9._/-]` (las mismas reglas con que el backend comprueba el prefijo de
  la empresa antes de leer el objeto, `ModelApprovalGates::assertOwnKey`).
- Controlador `local`: raíz = el disco `local` del backend (`backend/storage/app/private`, `config/filesystems.php`). Se configura con
  `ENGINE_OBJECT_STORE_ROOT`; si no se define y el motor corre dentro del monorepo, se usa esa misma carpeta (se informa en `describe()`).
  Desplegado aparte, la raíz debe ser el mismo volumen que ve el backend (`PREDICTILY_MODELS_DISK`).
- Controlador `s3`: boto3 (dependencia OPCIONAL, extra `s3`; importación perezosa), contra AWS o un emulador (`ENGINE_OBJECT_STORE_ENDPOINT`,
  `ENGINE_OBJECT_STORE_PATH_STYLE`). Las credenciales NUNCA se leen de aquí: las resuelve la cadena estándar de boto3 (entorno, perfil, rol
  de tarea). Cifrado del lado del servidor con `ENGINE_OBJECT_STORE_SSE` (`AES256` | `aws:kms`) y `ENGINE_OBJECT_STORE_KMS_KEY_ID`.

Garantías de escritura: `put` no sobrescribe (escritura única, como Bronze): el mismo contenido en la misma clave es idempotente; un
contenido distinto es `ObjectConflict`. Tras escribir se relee y se compara el SHA-256 (un objeto «fantasma» o truncado se detecta).

Este módulo solo depende de la biblioteca estándar y de pydantic-settings (y de boto3 si se usa `s3`); no importa nada del motor.
"""

from __future__ import annotations

import hashlib
import os
import re
import tempfile
import threading
from abc import ABC, abstractmethod
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

from pydantic_settings import BaseSettings, SettingsConfigDict

DRIVER_LOCAL = "local"
DRIVER_S3 = "s3"
MANIFEST_SUFFIX = ".manifest.json"           # igual que `signing.MANIFEST_SUFFIX` y `ModelArtifactVerifier::MANIFEST_SUFFIX`
MAX_KEY_LENGTH = 500                          # columna `model_registry.published_object_key`
MODEL_CORES = ("demand", "customer")

_UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
_SEGMENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$")
_KEY_CHARS = re.compile(r"^[A-Za-z0-9._/\-]+$")


class ObjectStoreError(RuntimeError):
    """Fallo del almacén de objetos (nunca incluye credenciales ni claves de acceso)."""


class ObjectStoreUnavailable(ObjectStoreError):
    """El controlador no se puede usar en este entorno (falta configuración o la biblioteca opcional)."""


class ObjectNotFound(ObjectStoreError):
    """La clave no existe."""


class ObjectConflict(ObjectStoreError):
    """La clave ya existe con un contenido distinto (escritura única: no se sobrescribe)."""


class InvalidObjectKey(ValueError):
    """La clave no cumple la convención del servidor."""


# ───────────────────────────── convención de claves (compartida con el backend) ─────────────────────────────

def validate_key(key: str) -> str:
    """Una clave del almacén: segura como ruta y como clave S3. @raises InvalidObjectKey."""
    if (not isinstance(key, str) or not key or len(key) > MAX_KEY_LENGTH or key.startswith("/") or key.endswith("/")
            or ".." in key or "//" in key or _KEY_CHARS.match(key) is None):
        raise InvalidObjectKey("clave de objeto no válida")
    return key


def _segment(value: str, what: str) -> str:
    if not isinstance(value, str) or ".." in value or _SEGMENT.match(value) is None:
        raise InvalidObjectKey(f"{what} no válido para una clave de objeto")
    return value


def tenant_models_prefix(tenant_uuid: str) -> str:
    """`tenants/<uuid>/models/`: el prefijo de los modelos publicados de UNA empresa."""
    if not isinstance(tenant_uuid, str) or _UUID.match(tenant_uuid.lower()) is None:
        raise InvalidObjectKey("uuid de empresa no válido")
    return f"tenants/{tenant_uuid.lower()}/models/"


def model_object_key(tenant_uuid: str, core: str, version: str, file_name: str) -> str:
    """Clave del servidor de un artefacto publicado: `tenants/<uuid>/models/<core>/<versión>/<archivo>`."""
    if core not in MODEL_CORES:
        raise InvalidObjectKey("core no válido para una clave de modelo")
    return validate_key(f"{tenant_models_prefix(tenant_uuid)}{core}/{_segment(version, 'version')}/{_segment(file_name, 'nombre de archivo')}")


def is_tenant_models_key(key: object, tenant_uuid: str) -> bool:
    """¿`key` es una clave de modelos de ESTA empresa? Mismas reglas que `ModelApprovalGates::assertOwnKey` del backend."""
    try:
        prefix = tenant_models_prefix(tenant_uuid)
    except InvalidObjectKey:
        return False
    return (isinstance(key, str) and key.startswith(prefix) and ".." not in key and "//" not in key
            and _KEY_CHARS.match(key) is not None and not key.endswith("/") and len(key) <= MAX_KEY_LENGTH)


def manifest_key(key: str) -> str:
    return validate_key(key) + MANIFEST_SUFFIX


@dataclass(frozen=True)
class StoredObject:
    key: str
    size_bytes: int
    sha256: str


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


# ───────────────────────────── configuración por entorno ─────────────────────────────

class _StoreEnv(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ENGINE_OBJECT_STORE_", env_file=".env", extra="ignore")

    driver: str = DRIVER_LOCAL
    root: str | None = None
    bucket: str | None = None
    region: str | None = None
    endpoint: str | None = None
    path_style: bool = False
    sse: str | None = None
    kms_key_id: str | None = None


def monorepo_default_root() -> Path | None:
    """`backend/storage/app/private` del monorepo (el disco `local` del backend), si existe junto al motor."""
    candidate = Path(__file__).resolve().parents[3] / "backend" / "storage" / "app" / "private"
    return candidate if candidate.is_dir() else None


# ───────────────────────────── interfaz ─────────────────────────────

class ObjectStore(ABC):
    driver: str

    @abstractmethod
    def put(self, key: str, data: bytes, *, overwrite: bool = False, content_type: str | None = None) -> StoredObject:
        """Escribe `data` (escritura única; ver el módulo) y devuelve lo guardado, releído y verificado."""

    @abstractmethod
    def get(self, key: str, *, max_bytes: int | None = None) -> bytes:
        """Contenido íntegro. @raises ObjectNotFound; ObjectStoreError si supera `max_bytes`."""

    @abstractmethod
    def exists(self, key: str) -> bool: ...

    @abstractmethod
    def delete(self, key: str) -> None:
        """Borra el objeto (idempotente). Solo para limpieza de pruebas y de publicaciones fallidas del propio proceso."""

    @abstractmethod
    def list_keys(self, prefix: str) -> list[str]: ...

    @abstractmethod
    def describe(self) -> dict[str, Any]:
        """Configuración sin secretos (para `diagnose`)."""

    def _verify(self, key: str, data: bytes, size: int | None, content: bytes | None) -> StoredObject:
        digest = sha256_hex(data)
        if size != len(data) or (content is not None and sha256_hex(content) != digest):
            raise ObjectStoreError("la escritura en el almacén no quedó íntegra (tamaño o SHA-256 distinto tras releer)")
        return StoredObject(key, len(data), digest)


# ───────────────────────────── controlador local ─────────────────────────────

def _os_failure(action: str, exc: OSError) -> str:
    """Mensaje de un fallo del sistema de archivos SIN rutas del servidor (el mensaje de un OSError las trae): solo la acción y el tipo."""
    return f"falló {action} en el almacén local ({type(exc).__name__})"


class LocalObjectStore(ObjectStore):
    driver = DRIVER_LOCAL

    def __init__(self, root: str | Path, *, root_source: str = "configured") -> None:
        self.root = Path(root)
        self.root_source = root_source

    def _path(self, key: str) -> Path:
        validate_key(key)
        root = self.root.resolve()
        path = (root / key).resolve()          # sigue enlaces simbólicos: uno que salga de la raíz se rechaza
        if not path.is_relative_to(root) or path == root:
            raise InvalidObjectKey("clave de objeto fuera de la raíz del almacén")
        parts = key.split("/")
        if len(parts) > 2 and parts[0] == "tenants":
            # Defensa en profundidad: la ruta resuelta de una clave `tenants/<uuid>/…` debe seguir DENTRO del directorio de esa empresa. Un enlace simbólico
            # dentro de la raíz que apunte al directorio de OTRA empresa no puede hacer que la clave de una lea los bytes de la otra.
            if not path.is_relative_to((root / "tenants" / parts[1]).resolve()):
                raise InvalidObjectKey("clave de objeto fuera del directorio de su empresa")
        return path

    def put(self, key: str, data: bytes, *, overwrite: bool = False, content_type: str | None = None) -> StoredObject:
        path = self._path(key)
        try:
            if path.exists() and not overwrite:
                existing = path.read_bytes()
                if sha256_hex(existing) == sha256_hex(data):
                    return StoredObject(key, len(existing), sha256_hex(existing))
                raise ObjectConflict("la clave ya existe con un contenido distinto; las publicaciones son de escritura única")
            os.makedirs(path.parent, mode=0o700, exist_ok=True)
            fd, temp = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=path.parent)      # mkstemp crea con 0600
            try:
                with os.fdopen(fd, "wb") as handle:
                    handle.write(data)
                    handle.flush()
                    os.fsync(handle.fileno())
                os.replace(temp, path)
            except BaseException:
                try:
                    os.unlink(temp)
                except OSError:
                    pass
                raise
            return self._verify(key, data, path.stat().st_size, path.read_bytes())
        except OSError as exc:
            raise ObjectStoreError(_os_failure("la escritura", exc)) from None

    def get(self, key: str, *, max_bytes: int | None = None) -> bytes:
        path = self._path(key)
        try:
            if max_bytes is not None and path.stat().st_size > max_bytes:
                raise ObjectStoreError("el objeto supera el tamaño máximo permitido")
            return path.read_bytes()
        except (FileNotFoundError, NotADirectoryError) as exc:        # un padre que es un archivo equivale a «no existe»
            raise ObjectNotFound("el objeto no existe en el almacén") from exc
        except IsADirectoryError as exc:
            raise ObjectNotFound("la clave no es un objeto") from exc
        except OSError as exc:
            raise ObjectStoreError(_os_failure("la lectura", exc)) from None

    def exists(self, key: str) -> bool:
        try:
            return self._path(key).is_file()
        except OSError as exc:
            raise ObjectStoreError(_os_failure("la consulta", exc)) from None

    def delete(self, key: str) -> None:
        try:
            self._path(key).unlink()
        except FileNotFoundError:
            pass
        except OSError as exc:
            raise ObjectStoreError(_os_failure("el borrado", exc)) from None

    def list_keys(self, prefix: str) -> list[str]:
        base = prefix.rstrip("/")
        validate_key(base)
        folder = self._path(base)
        try:
            if not folder.is_dir():
                return []
            root = self.root.resolve()
            return sorted(p.relative_to(root).as_posix() for p in folder.rglob("*") if p.is_file() and not p.name.endswith(".tmp"))
        except OSError as exc:
            raise ObjectStoreError(_os_failure("el listado", exc)) from None

    def describe(self) -> dict[str, Any]:
        return {"driver": self.driver, "root": str(self.root), "root_source": self.root_source}


# ───────────────────────────── controlador S3 ─────────────────────────────

class S3ObjectStore(ObjectStore):
    """boto3 contra AWS o un emulador S3. Estado: verificado SOLO contra un emulador (moto); AWS real BLOQUEADO (sin cuenta del propietario)."""

    driver = DRIVER_S3

    def __init__(self, bucket: str, *, region: str | None = None, endpoint: str | None = None, path_style: bool = False,
                 sse: str | None = None, kms_key_id: str | None = None, client: Any = None) -> None:
        if not bucket:
            raise ObjectStoreUnavailable("ENGINE_OBJECT_STORE_BUCKET es obligatorio con el controlador s3")
        if sse not in (None, "", "AES256", "aws:kms"):
            raise ObjectStoreUnavailable("ENGINE_OBJECT_STORE_SSE debe ser AES256 o aws:kms")
        self.bucket, self.region, self.endpoint, self.path_style = bucket, region or None, endpoint or None, path_style
        self.sse, self.kms_key_id = (sse or None), (kms_key_id or None)
        self._client_obj = client

    def _client(self) -> Any:
        if self._client_obj is None:
            try:
                import boto3
                from botocore.config import Config
            except ImportError as exc:
                raise ObjectStoreUnavailable("boto3 no está instalado: pip install 'predictily-engine[s3]' (dependencia opcional del controlador s3)") from exc
            config = Config(s3={"addressing_style": "path" if self.path_style else "auto"}, retries={"max_attempts": 3, "mode": "standard"})
            self._client_obj = boto3.client("s3", region_name=self.region, endpoint_url=self.endpoint, config=config)
        return self._client_obj

    @staticmethod
    def _code(exc: Exception) -> str:
        response = getattr(exc, "response", None) or {}
        return str((response.get("Error") or {}).get("Code") or type(exc).__name__)

    def _fail(self, exc: Exception, action: str) -> ObjectStoreError:
        if self._code(exc) in ("404", "NoSuchKey", "NotFound"):
            return ObjectNotFound("el objeto no existe en el almacén")
        return ObjectStoreError(f"falló {action} en el almacén S3 ({self._code(exc)})")      # solo el código: sin cabeceras ni credenciales

    def put(self, key: str, data: bytes, *, overwrite: bool = False, content_type: str | None = None) -> StoredObject:
        validate_key(key)
        if not overwrite and self.exists(key):
            existing = self.get(key)
            if sha256_hex(existing) == sha256_hex(data):
                return StoredObject(key, len(existing), sha256_hex(existing))
            raise ObjectConflict("la clave ya existe con un contenido distinto; las publicaciones son de escritura única")
        args: dict[str, Any] = {"Bucket": self.bucket, "Key": key, "Body": data, "ContentType": content_type or "application/octet-stream"}
        if self.sse:
            args["ServerSideEncryption"] = self.sse
            if self.sse == "aws:kms" and self.kms_key_id:
                args["SSEKMSKeyId"] = self.kms_key_id
        try:
            self._client().put_object(**args)
            head = self._client().head_object(Bucket=self.bucket, Key=key)
        except ObjectStoreError:
            raise
        except Exception as exc:  # noqa: BLE001  errores de botocore: se reducen a su código
            raise self._fail(exc, "la escritura") from None
        return self._verify(key, data, int(head.get("ContentLength", -1)), self.get(key))

    def get(self, key: str, *, max_bytes: int | None = None) -> bytes:
        validate_key(key)
        try:
            response = self._client().get_object(Bucket=self.bucket, Key=key)
            if max_bytes is not None and int(response.get("ContentLength", 0)) > max_bytes:
                raise ObjectStoreError("el objeto supera el tamaño máximo permitido")
            return response["Body"].read()
        except ObjectStoreError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise self._fail(exc, "la lectura") from None

    def exists(self, key: str) -> bool:
        validate_key(key)
        try:
            self._client().head_object(Bucket=self.bucket, Key=key)
            return True
        except ObjectStoreError:
            raise
        except Exception as exc:  # noqa: BLE001
            if isinstance(self._fail(exc, "la consulta"), ObjectNotFound):
                return False
            raise self._fail(exc, "la consulta") from None

    def delete(self, key: str) -> None:
        validate_key(key)
        try:
            self._client().delete_object(Bucket=self.bucket, Key=key)
        except ObjectStoreError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise self._fail(exc, "el borrado") from None

    def list_keys(self, prefix: str) -> list[str]:
        validate_key(prefix.rstrip("/"))
        keys: list[str] = []
        try:
            for page in self._client().get_paginator("list_objects_v2").paginate(Bucket=self.bucket, Prefix=prefix.rstrip("/") + "/"):
                keys.extend(item["Key"] for item in page.get("Contents", []))
        except ObjectStoreError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise self._fail(exc, "el listado") from None
        return sorted(keys)

    def describe(self) -> dict[str, Any]:
        host = urlsplit(self.endpoint).hostname if self.endpoint else None
        return {"driver": self.driver, "bucket": self.bucket, "region": self.region, "endpoint_host": host, "path_style": self.path_style,
                "server_side_encryption": self.sse, "kms_key_configured": bool(self.kms_key_id), "credentials": "cadena estándar de boto3 (no se leen aquí)"}


# ───────────────────────────── construcción por entorno ─────────────────────────────

def build_store(env: _StoreEnv | None = None) -> ObjectStore:
    """Controlador según `ENGINE_OBJECT_STORE_*`. @raises ObjectStoreUnavailable con un mensaje accionable."""
    env = env or _StoreEnv()
    driver = (env.driver or DRIVER_LOCAL).strip().lower()
    if driver == DRIVER_LOCAL:
        if env.root and env.root.strip():
            return LocalObjectStore(env.root.strip(), root_source="ENGINE_OBJECT_STORE_ROOT")
        default = monorepo_default_root()
        if default is None:
            raise ObjectStoreUnavailable("Defina ENGINE_OBJECT_STORE_ROOT: debe ser la raíz del disco que lee el backend (PREDICTILY_MODELS_DISK; "
                                         "en el monorepo, backend/storage/app/private)")
        return LocalObjectStore(default, root_source="monorepo_backend_default")
    if driver == DRIVER_S3:
        return S3ObjectStore(env.bucket or "", region=env.region, endpoint=env.endpoint, path_style=env.path_style, sse=env.sse,
                             kms_key_id=env.kms_key_id)
    raise ObjectStoreUnavailable("ENGINE_OBJECT_STORE_DRIVER debe ser local o s3")


_STORE_LOCK = threading.Lock()
_STORE_CACHED: tuple[tuple[Any, ...], ObjectStore] | None = None


def get_store() -> ObjectStore:
    """El almacén que describe el entorno, REUTILIZADO mientras la configuración no cambie: un solo cliente boto3 por proceso (cada carga de un modelo
    publicado pedía uno nuevo, con su cadena de credenciales y su conexión). Si cambia cualquier variable `ENGINE_OBJECT_STORE_*` se construye otro."""
    global _STORE_CACHED
    env = _StoreEnv()
    signature = (env.driver, env.root, env.bucket, env.region, env.endpoint, env.path_style, env.sse, env.kms_key_id,
                 str(monorepo_default_root()) if (env.driver or DRIVER_LOCAL).strip().lower() == DRIVER_LOCAL and not (env.root and env.root.strip()) else None)
    with _STORE_LOCK:
        if _STORE_CACHED is None or _STORE_CACHED[0] != signature:
            _STORE_CACHED = (signature, build_store(env))
        return _STORE_CACHED[1]
