"""Serving del motor (ADR-V25-07, ADR-V25-20; V2.5 §9): qué modelos se cargan, de dónde y con qué caché.

1. FILTRO (`partition_servable`): el motor carga solo modelos cuya base de servicio (`mlops.policy`, espejo de `ModelServingPolicy::basis()`)
   es `approved` o `legacy_pipeline`. Un `not_approved` —publicado sin aprobar, candidato, validado por una persona, sin origen, rechazado o
   fallido— NO se carga, aunque alguien haya dejado su estado en `production`.
2. CARGA VERIFICADA (`verify_row_artifact`): un modelo del pipeline propio se verifica en `ENGINE_ARTIFACTS_DIR/<empresa>/…`
   (`platform.artifacts.verify_stored_artifact`); uno PUBLICADO por la CLI, en el almacén de objetos con la clave del servidor
   `tenants/<uuid de la empresa>/models/…` (la clave debe pertenecer a la empresa del trabajo; se descarga a un directorio temporal privado, se
   verifica con `platform.signing.verify_artifact_file` —firma Ed25519, SHA-256, tamaño, empresa y core— y se borra). Nunca se deserializa antes.
3. CACHÉ (`ArtifactCache`): guarda el objeto ya deserializado bajo (empresa, core, versión del modelo, versión del esquema de variables, SHA-256 del
   artefacto). La verificación se repite SIEMPRE antes de consultar la caché (un artefacto alterado o una clave de confianza retirada del ENTORNO DEL
   PROCESO se notan en la siguiente carga; retirar una clave en un motor en marcha exige reiniciarlo: su entorno no cambia desde fuera); la caché solo evita deserializar. Las empresas no comparten entradas: la
   empresa es parte de la clave y `invalidate_tenant` borra solo las suyas.

Los artefactos servidos son de solo lectura (la predicción no los modifica), por eso la caché devuelve el mismo objeto.
"""

from __future__ import annotations

import logging
import os
import shutil
import tempfile
import threading
from collections import OrderedDict
from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from pydantic import ValidationError
from pydantic_settings import BaseSettings, SettingsConfigDict
from sqlalchemy import Connection

from predictily_engine.mlops import policy
from predictily_engine.platform import signing
from predictily_engine.platform.artifacts import verify_stored_artifact
from predictily_engine.platform.object_store import (
    MANIFEST_SUFFIX,
    InvalidObjectKey,
    ObjectNotFound,
    ObjectStore,
    ObjectStoreError,
    ObjectStoreUnavailable,
    get_store,
    is_tenant_models_key,
)
from predictily_engine.platform.signing import Verification

log = logging.getLogger(__name__)

REASON_OBJECT_KEY_INVALID = "artifact_object_key_invalid"
REASON_STORE_UNAVAILABLE = "artifact_store_unavailable"
REASON_NOT_APPROVED = "model_not_approved"
MAX_ARTIFACT_BYTES = 1024 * 1024 * 1024
MAX_MANIFEST_BYTES = signing.MAX_ENVELOPE_BYTES + 1


# ───────────────────────────── 1. filtro ─────────────────────────────

@dataclass(frozen=True)
class Partition:
    servable: list[dict[str, Any]]
    excluded: list[dict[str, Any]]


def partition_servable(conn: Connection, tenant_id: int, rows: Iterable[Mapping[str, Any]]) -> Partition:
    """Separa las filas del registro de UNA empresa en servibles (`approved` | `legacy_pipeline`) y excluidas (`not_approved`).

    Cada fila devuelta es una copia con `serving_basis`. Una fila de otra empresa es un error (el serving no mezcla empresas), no un descarte.
    """
    rows = [dict(r) for r in rows]
    bases = policy.basis_of_rows(conn, tenant_id, rows)
    servable, excluded = [], []
    for row, basis in zip(rows, bases):
        row["serving_basis"] = basis
        (servable if policy.is_servable(basis) else excluded).append(row)
    return Partition(servable, excluded)


# ───────────────────────────── 2. carga verificada ─────────────────────────────

def _private_tempdir() -> Path:
    path = Path(tempfile.mkdtemp(prefix="predictily-serving-"))
    os.chmod(path, 0o700)
    return path


def verify_row_artifact(row: Mapping[str, Any], *, tenant_id: int, core: str | None, store: ObjectStore | None = None,
                        config: signing.SigningConfig | None = None) -> Verification:
    """Verificación completa del artefacto de una fila del registro, sin deserializar. Publicado → almacén de objetos; si no → directorio de artefactos."""
    key = row.get("published_object_key")
    expected = row.get("artifact_sha256")
    if not policy.present(key) or key == "":
        stored = row.get("artifact_path")
        if not policy.present(stored) or not stored:
            return Verification(reason=signing.REASON_MISSING)
        return verify_stored_artifact(stored, tenant_id=tenant_id, core=core, expected_sha256=expected, config=config)

    uuid = row.get("tenant_uuid")
    if not isinstance(uuid, str) or not is_tenant_models_key(key, uuid):
        log.warning("Clave de objeto publicado rechazada: no pertenece a la empresa %s (modelo %s)", tenant_id, row.get("version"))
        return Verification(reason=REASON_OBJECT_KEY_INVALID)
    if not expected:
        return Verification(reason=signing.REASON_SHA256_MISMATCH)
    try:
        store = store or get_store()
        data = store.get(key, max_bytes=MAX_ARTIFACT_BYTES)
        try:
            sidecar: bytes | None = store.get(key + MANIFEST_SUFFIX, max_bytes=MAX_MANIFEST_BYTES)
        except ObjectNotFound:
            sidecar = None                                       # sin manifiesto = sin firma (signing.py lo rechaza)
        return _verify_downloaded(key, data, sidecar, tenant_id=tenant_id, core=core, expected=expected, config=config)
    except ObjectNotFound:
        return Verification(reason=signing.REASON_MISSING)
    except InvalidObjectKey:                                     # p. ej. un enlace simbólico que sale de la raíz del almacén local
        log.warning("Objeto publicado rechazado por su clave (modelo %s, empresa %s)", row.get("version"), tenant_id)
        return Verification(reason=REASON_OBJECT_KEY_INVALID)
    except (ObjectStoreUnavailable, ObjectStoreError, OSError, ValueError) as exc:
        # Un fallo del almacén (o del disco temporal) degrada ESE modelo —se sirve la referencia o el horizonte se abstiene— y no tumba la corrida.
        # Solo se registra el tipo de error: nunca su mensaje (podría traer rutas del servidor).
        log.warning("Almacén de objetos no disponible al cargar el modelo %s de la empresa %s: %s", row.get("version"), tenant_id, type(exc).__name__)
        return Verification(reason=REASON_STORE_UNAVAILABLE)


def _verify_downloaded(key: str, data: bytes, sidecar: bytes | None, *, tenant_id: int, core: str | None, expected: Any,
                       config: signing.SigningConfig | None) -> Verification:
    workdir = _private_tempdir()
    try:
        file = workdir / key.rsplit("/", 1)[1]
        file.write_bytes(data)
        if sidecar is not None:
            signing.manifest_path(file).write_bytes(sidecar)
        verification = signing.verify_artifact_file(file, tenant_id=tenant_id, core=core, expected_sha256=expected, config=config)
    finally:
        shutil.rmtree(workdir, ignore_errors=True)
    if verification.ok and (verification.artifact is None or verification.artifact.status != signing.STATUS_SIGNED):
        # Un modelo PUBLICADO siempre va firmado (ADR-V25-20): ENGINE_ALLOW_UNSIGNED_ARTIFACTS es solo para legados locales y no se aplica aquí.
        return Verification(reason=signing.REASON_UNSIGNED)
    return verification


# ───────────────────────────── 3. caché ─────────────────────────────

@dataclass(frozen=True)
class CacheKey:
    tenant_id: int
    core: str
    model_version: str
    feature_schema_version: str
    artifact_sha256: str


class _CacheEnv(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ENGINE_", env_file=".env", extra="ignore")

    artifact_cache_entries: int = 32
    artifact_cache_max_mb: int = 256


MAX_CACHE_ENTRIES = 1024
MAX_CACHE_MB = 8192


def validate_cache_settings() -> str | None:
    """Mensaje en español si `ENGINE_ARTIFACT_CACHE_ENTRIES` (0 a 1024; 0 desactiva la caché) o `ENGINE_ARTIFACT_CACHE_MAX_MB` (1 a 8192) son inválidos; None si están bien.
    Se llama al arrancar (la CLI y el módulo): un valor mal escrito debe fallar claro al inicio, no deformar la caché en silencio."""
    try:
        env = _CacheEnv()
    except ValidationError:
        return "ENGINE_ARTIFACT_CACHE_ENTRIES y ENGINE_ARTIFACT_CACHE_MAX_MB deben ser números enteros."
    if not 0 <= env.artifact_cache_entries <= MAX_CACHE_ENTRIES:
        return f"ENGINE_ARTIFACT_CACHE_ENTRIES debe estar entre 0 y {MAX_CACHE_ENTRIES} (0 desactiva la caché)."
    if not 1 <= env.artifact_cache_max_mb <= MAX_CACHE_MB:
        return f"ENGINE_ARTIFACT_CACHE_MAX_MB debe estar entre 1 y {MAX_CACHE_MB}."
    return None


class ArtifactCache:
    """LRU acotada por NÚMERO de entradas y por BYTES (el tamaño serializado del artefacto verificado es la medida; el objeto deserializado ocupa más, de ahí un tope
    conservador por defecto), segura entre hilos, de artefactos ya verificados y deserializados. `max_entries = 0` la desactiva.

    Invalidación: la clave incluye el SHA-256 del contenido y la verificación se repite en cada carga, así que una entrada vieja nunca se sirve de más: solo ocupa memoria
    hasta que la LRU la expulsa. `invalidate_tenant` la libera antes cuando los modelos de una empresa cambian (el motor la llama al terminar un entrenamiento del
    Customer Core)."""

    def __init__(self, max_entries: int | None = None, max_bytes: int | None = None) -> None:
        self._max = max_entries
        self._max_bytes = max_bytes
        self._items: OrderedDict[CacheKey, tuple[Any, int]] = OrderedDict()
        self._bytes = 0
        self._lock = threading.Lock()
        self.hits = 0
        self.misses = 0

    @property
    def max_entries(self) -> int:
        return max(0, _CacheEnv().artifact_cache_entries if self._max is None else self._max)

    @property
    def max_bytes(self) -> int:
        return max(1, (_CacheEnv().artifact_cache_max_mb * 1024 * 1024) if self._max_bytes is None else self._max_bytes)

    @property
    def size_bytes(self) -> int:
        with self._lock:
            return self._bytes

    def get(self, key: CacheKey) -> tuple[bool, Any]:
        with self._lock:
            if key in self._items:
                self._items.move_to_end(key)
                self.hits += 1
                return True, self._items[key][0]
            self.misses += 1
            return False, None

    def put(self, key: CacheKey, value: Any, size_bytes: int = 0) -> None:
        limit, byte_limit = self.max_entries, self.max_bytes
        if limit == 0 or size_bytes > byte_limit:                         # un artefacto más grande que todo el tope no se cachea
            return
        with self._lock:
            if key in self._items:
                self._bytes -= self._items.pop(key)[1]
            self._items[key] = (value, int(size_bytes))
            self._bytes += int(size_bytes)
            while len(self._items) > limit or self._bytes > byte_limit:
                _, (_, dropped) = self._items.popitem(last=False)
                self._bytes -= dropped

    def invalidate_tenant(self, tenant_id: int) -> int:
        with self._lock:
            doomed = [k for k in self._items if k.tenant_id == int(tenant_id)]
            for k in doomed:
                self._bytes -= self._items.pop(k)[1]
            return len(doomed)

    def clear(self) -> None:
        with self._lock:
            self._items.clear()
            self._bytes = 0
            self.hits = self.misses = 0

    def keys(self) -> list[CacheKey]:
        with self._lock:
            return list(self._items)

    def __len__(self) -> int:
        with self._lock:
            return len(self._items)


ARTIFACT_CACHE = ArtifactCache()


def deserialize_cached(key: CacheKey, verified: signing.VerifiedArtifact, cache: ArtifactCache | None = None) -> Any:
    """Objeto del artefacto verificado: de la caché si ya se deserializó bajo la misma clave; si no, se deserializa desde los MISMOS bytes verificados.

    La clave debe construirse con la empresa que sirve el trabajo y el SHA-256 de los bytes verificados: una entrada de otra empresa, de otra
    versión, de otro esquema de variables o de otro contenido no coincide.
    """
    cache = cache or ARTIFACT_CACHE
    if key.artifact_sha256 != verified.sha256:
        raise ValueError("la clave de caché no corresponde a los bytes verificados")
    hit, value = cache.get(key)
    if hit:
        return value
    value = signing.deserialize(verified)
    cache.put(key, value, len(verified.data))
    return value


_problem = validate_cache_settings()
if _problem is not None:          # fail-fast al arrancar el motor (la API importa este módulo); la CLI lo comprueba antes con una salida 3
    raise RuntimeError(_problem)
