"""Archivos y directorios privados: creación atómica con permisos restrictivos y verificación al leer (POSIX)."""

from __future__ import annotations

import os
import stat
import sys
import tempfile
from pathlib import Path

POSIX = os.name == "posix"


class InsecurePermissions(Exception):
    """Un archivo o directorio con secretos es legible por otros usuarios del equipo."""


def ensure_private_dir(path: Path) -> Path:
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    if POSIX:
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o077:
            raise InsecurePermissions(f"El directorio {path} es accesible por otros usuarios (modo {mode:o}); use chmod 700.")
    return path


def write_private(path: Path, data: bytes) -> None:
    """Escritura atómica (temporal + fsync + rename) con modo 0600 desde la creación."""
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=path.parent)
    try:
        if POSIX:
            os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise
    if POSIX:
        _fsync_dir(path.parent)


def read_private(path: Path) -> bytes:
    if POSIX:
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o077:
            raise InsecurePermissions(f"El archivo {path} es legible por otros usuarios (modo {mode:o}); use chmod 600.")
    return path.read_bytes()


def _fsync_dir(directory: Path) -> None:
    try:
        fd = os.open(directory, os.O_RDONLY)
    except OSError:  # pragma: no cover - Windows
        return
    try:
        os.fsync(fd)
    except OSError:  # pragma: no cover
        pass
    finally:
        os.close(fd)


def is_windows() -> bool:
    return sys.platform.startswith("win")
