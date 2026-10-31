"""Ubicación de los artefactos de modelos (`model_registry.artifact_path`): UNA regla para todo el sistema.

Regla: `artifact_path` se guarda RELATIVO al directorio de artefactos (`ENGINE_ARTIFACTS_DIR`), con separador `/`
(`<empresa>/run-<id>/<familia>.joblib`, `<empresa>/customer/run-<id>/<nombre>.joblib`). Así el valor no depende de dónde esté
montado el volumen en cada servicio: el motor lo resuelve contra su `ENGINE_ARTIFACTS_DIR` y el backend contra su
`engine.artifacts_dir` (la misma variable `ENGINE_ARTIFACTS_DIR`, apuntando al mismo volumen compartido). Si el backend no ve el
directorio, no verifica el SHA-256 y lo informa explícitamente (lo verifica el motor al cargar el artefacto).

Compatibilidad con filas anteriores (se siguen leyendo, no se reescriben): ruta absoluta, o relativa al directorio de trabajo del
motor e incluyendo el propio directorio (`storage/artifacts/<empresa>/…` con `ENGINE_ARTIFACTS_DIR=./storage/artifacts`).
"""

from __future__ import annotations

from pathlib import Path, PurePosixPath

from predictily_engine.config import get_settings


def artifacts_root() -> Path:
    return Path(get_settings().artifacts_dir)


def to_stored_path(path: Path) -> str:
    """Ruta del archivo → valor para `artifact_path`: relativa al directorio de artefactos y con separador `/`."""
    root = artifacts_root().resolve()
    return PurePosixPath(*path.resolve().relative_to(root).parts).as_posix()


def resolve_stored_path(stored: str | Path) -> Path:
    """`artifact_path` guardado → ruta del archivo en ESTE servicio (la regla vigente primero; luego las filas anteriores)."""
    p = Path(stored)
    if p.is_absolute():
        return p
    primary = artifacts_root() / p
    if primary.exists():
        return primary
    return p if p.exists() else primary
