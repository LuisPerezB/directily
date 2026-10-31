"""Ubicación y custodia de los artefactos de modelos (`model_registry.artifact_path`): UNA regla para todo el sistema.

Regla: `artifact_path` se guarda RELATIVO al directorio de artefactos (`ENGINE_ARTIFACTS_DIR`), con separador `/`
(`<empresa>/run-<id>/<familia>.joblib`, `<empresa>/customer/run-<id>/<nombre>.joblib`). Así el valor no depende de dónde esté
montado el volumen en cada servicio: el motor lo resuelve contra su `ENGINE_ARTIFACTS_DIR` y el backend contra su
`engine.artifacts_dir` (la misma variable `ENGINE_ARTIFACTS_DIR`, apuntando al mismo volumen compartido). Si el backend no ve el
directorio, no verifica el SHA-256 ni la firma y lo informa explícitamente (lo verifica el motor al cargar el artefacto).

Compatibilidad con filas anteriores (se siguen leyendo, no se reescriben): ruta absoluta, o relativa al directorio de trabajo del
motor e incluyendo el propio directorio (`storage/artifacts/<empresa>/…`). `resolve_stored_path` conserva esa compatibilidad y NO es
una frontera de seguridad; la carga usa `confine_stored_path`, que exige que el archivo quede DENTRO de
`<ENGINE_ARTIFACTS_DIR>/<empresa>/` (rechaza rutas absolutas ajenas, `..` y enlaces simbólicos que salgan).

Custodia (ADR-V25-20): `save_signed_artifact` serializa, calcula el SHA-256 sobre los bytes que escribe y deja junto al archivo
el manifiesto firmado (`platform/signing.py`); `verify_stored_artifact` + `deserialize` verifican firma, SHA-256, tamaño y empresa
ANTES de `joblib.load` y deserializan desde los mismos bytes verificados.
"""

from __future__ import annotations

import hashlib
import io
import logging
import os
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any

import joblib

from predictily_engine.config import get_settings
from predictily_engine.platform import signing
from predictily_engine.platform.signing import SignatureInfo, SigningConfig, Verification


log = logging.getLogger(__name__)


class ArtifactPathError(ValueError):
    """La ruta guardada de un artefacto sale del directorio de su empresa."""


def artifacts_root() -> Path:
    return Path(get_settings().artifacts_dir)


def to_stored_path(path: Path) -> str:
    """Ruta del archivo → valor para `artifact_path`: relativa al directorio de artefactos y con separador `/`."""
    root = artifacts_root().resolve()
    return PurePosixPath(*path.resolve().relative_to(root).parts).as_posix()


def resolve_stored_path(stored: str | Path) -> Path:
    """`artifact_path` guardado → ruta del archivo en ESTE servicio (la regla vigente primero; luego las filas anteriores).

    Compatibilidad de lectura, NO confinamiento: para cargar un artefacto use `confine_stored_path`.
    """
    p = Path(stored)
    if p.is_absolute():
        return p
    primary = artifacts_root() / p
    if primary.exists():
        return primary
    return p if p.exists() else primary


def tenant_of_stored_path(stored: str | Path) -> int | None:
    """Empresa a la que pertenece una ruta guardada según su ubicación (`<ENGINE_ARTIFACTS_DIR>/<empresa>/…`), o None si no cuelga de
    ningún directorio de empresa. Solo para llamadores que no conocen la empresa: la fuente fiable es la corrida que carga el modelo."""
    try:
        relative = resolve_stored_path(stored).resolve().relative_to(artifacts_root().resolve())
    except (OSError, ValueError, RuntimeError, TypeError):
        return None
    first = relative.parts[0] if relative.parts else ""
    return int(first) if first.isdigit() else None


def confine_stored_path(stored: str | Path, tenant_id: int) -> Path:
    """Ruta resuelta del artefacto, garantizando que queda dentro de `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/`.

    Se normaliza (`..`, enlaces simbólicos) antes de comprobar: `7/../../x`, una absoluta ajena o un enlace que apunte fuera se
    rechazan. Una fila de otra empresa que apunte a la ruta de esta tampoco pasa.

    @raises ArtifactPathError
    """
    try:
        candidate = resolve_stored_path(stored).resolve()
        tenant_dir = (artifacts_root() / str(int(tenant_id))).resolve()
    except (OSError, ValueError, RuntimeError, TypeError) as exc:
        raise ArtifactPathError("ruta de artefacto no válida") from exc
    if candidate == tenant_dir or not candidate.is_relative_to(tenant_dir):
        raise ArtifactPathError("la ruta del artefacto sale del directorio de su empresa")
    return candidate


@dataclass(frozen=True)
class SavedArtifact:
    stored_path: str
    sha256: str
    size_bytes: int
    signature: SignatureInfo


def save_signed_artifact(obj: Any, path: Path, *, tenant_id: int, core: str, run_id: int | None, name: str,
                         data_fingerprint: str | None = None, feature_schema_version: str | None = None,
                         libraries: dict[str, str] | None = None, config: SigningConfig | None = None) -> SavedArtifact:
    """Serializa `obj` en `path` (joblib comprimido), lo firma y devuelve su registro.

    El SHA-256 y el tamaño se calculan sobre los mismos bytes que se escriben (no se relee el archivo). El artefacto se escribe
    de forma atómica y DESPUÉS el manifiesto: un manifiesto nunca apunta a un artefacto inexistente. Sin clave privada
    configurada el manifiesto queda sin firma y el artefacto se registra como `unsigned`.
    """
    buffer = io.BytesIO()
    joblib.dump(obj, buffer, compress=3)
    data = buffer.getvalue()
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(path.name + ".tmp")
    temp.write_bytes(data)
    os.replace(temp, path)
    digest = hashlib.sha256(data).hexdigest()
    config = config or signing.load_config()
    manifest = signing.build_manifest(
        artifact_name=path.name, sha256=digest, size_bytes=len(data), tenant_id=tenant_id, core=core, run_id=run_id, name=name,
        data_fingerprint=data_fingerprint, feature_schema_version=feature_schema_version, libraries=libraries, key_id=config.key_id)
    info = signing.write_manifest(path, manifest, config)
    if info.status == signing.STATUS_UNSIGNED:
        log.warning("Artefacto guardado SIN firma (no hay ENGINE_SIGNING_PRIVATE_KEY): el serving lo rechazará salvo ENGINE_ALLOW_UNSIGNED_ARTIFACTS. "
                    "empresa=%s core=%s corrida=%s", tenant_id, core, run_id)
    return SavedArtifact(to_stored_path(path), digest, len(data), info)


def verify_stored_artifact(stored: str | Path, *, tenant_id: int, core: str | None, expected_sha256: str | None,
                           config: SigningConfig | None = None) -> Verification:
    """Verificación completa de un `artifact_path` del registro, SIN deserializar: ruta confinada a la empresa, firma Ed25519 con
    las claves de confianza, SHA-256 del registro y del manifiesto, tamaño, empresa y core."""
    try:
        path = confine_stored_path(stored, tenant_id)
    except ArtifactPathError:
        return Verification(reason=signing.REASON_PATH_INVALID)
    return signing.verify_artifact_file(path, tenant_id=tenant_id, core=core, expected_sha256=expected_sha256, config=config)


deserialize = signing.deserialize
