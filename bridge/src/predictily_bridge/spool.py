"""Búfer local CIFRADO y ACOTADO de lotes pendientes (caída de red): se reenvían EN ORDEN y de forma idempotente (SHA-256).

Cada lote es un archivo ``spool/<entidad>/<secuencia>-<sha16>.pbe`` (AES-256-GCM; la entidad y la secuencia se autentican, no se pueden
intercambiar archivos). Los metadatos (cursor, marca de agua, ...) viajan DENTRO del cifrado. ``put`` es idempotente por SHA-256: tras una
caída entre escribir el lote y guardar el estado, el mismo lote no se duplica. Si el búfer alcanza su tope, la extracción se detiene
(contrapresión) hasta que se vacíe: nunca se descarta un lote sin enviar.
"""

from __future__ import annotations

import json
import re
import shutil
import struct
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from predictily_bridge import crypto
from predictily_bridge.fsutil import ensure_private_dir, read_private, write_private
from predictily_bridge.keystore import Keystore

_NAME = re.compile(r"^(\d{12})-([0-9a-f]{16})\.pbe$")


class SpoolFull(Exception):
    pass


@dataclass(frozen=True)
class SpoolEntry:
    entity: str
    seq: int
    sha16: str
    path: Path

    @property
    def size(self) -> int:
        return self.path.stat().st_size


class Spool:
    def __init__(self, root: Path, keystore: Keystore, max_bytes: int) -> None:
        self.root = root
        self.keystore = keystore
        self.max_bytes = max_bytes

    def _dir(self, entity: str) -> Path:
        if not re.fullmatch(r"[a-z_]{2,30}", entity):
            raise ValueError("Entidad no válida.")
        ensure_private_dir(self.root)
        return ensure_private_dir(self.root / entity)

    @staticmethod
    def _context(entity: str, seq: int) -> bytes:
        return f"spool/{entity}/{seq}".encode()

    def total_bytes(self) -> int:
        if not self.root.exists():
            return 0
        return sum(p.stat().st_size for p in self.root.glob("*/*.pbe"))

    def list(self, entity: str) -> list[SpoolEntry]:
        directory = self.root / entity
        if not directory.exists():
            return []
        out = []
        for path in directory.iterdir():
            m = _NAME.match(path.name)
            if m:
                out.append(SpoolEntry(entity, int(m.group(1)), m.group(2), path))
        return sorted(out, key=lambda e: e.seq)

    def pending(self, entity: str | None = None) -> int:
        if entity is not None:
            return len(self.list(entity))
        return sum(len(self.list(p.name)) for p in self.root.iterdir() if p.is_dir()) if self.root.exists() else 0

    def full(self, extra: int = 0) -> bool:
        return self.total_bytes() + extra > self.max_bytes

    def put(self, entity: str, seq: int, sha256: str, meta: dict[str, Any], payload: bytes) -> SpoolEntry:
        for existing in self.list(entity):
            if existing.sha16 == sha256[:16] and self._peek_sha(existing) == sha256:
                return existing  # mismo lote ya en el búfer (reintento tras una caída)
        if self.full(len(payload)):
            raise SpoolFull("El búfer local alcanzó su tope; la extracción espera a que se envíen los lotes pendientes.")
        header = json.dumps({**meta, "sha256": sha256}, separators=(",", ":"), sort_keys=True).encode("utf-8")
        blob = crypto.seal(self.keystore.master_key(), struct.pack(">I", len(header)) + header + payload, self._context(entity, seq))
        path = self._dir(entity) / f"{seq:012d}-{sha256[:16]}.pbe"
        write_private(path, blob)
        return SpoolEntry(entity, seq, sha256[:16], path)

    def read(self, entry: SpoolEntry) -> tuple[dict[str, Any], bytes]:
        plain = crypto.unseal(self.keystore.master_key(create=False), read_private(entry.path), self._context(entry.entity, entry.seq))
        (length,) = struct.unpack(">I", plain[:4])
        return json.loads(plain[4 : 4 + length]), plain[4 + length :]

    def _peek_sha(self, entry: SpoolEntry) -> str:
        return str(self.read(entry)[0]["sha256"])

    def delete(self, entry: SpoolEntry) -> None:
        entry.path.unlink(missing_ok=True)

    def quarantine(self, entry: SpoolEntry) -> Path:
        """Aparta un lote rechazado por el servidor (esquema inválido): se conserva cifrado para diagnóstico y no bloquea la cola."""
        target_dir = ensure_private_dir(self.root / "_quarantine")
        target = target_dir / f"{entry.entity}-{entry.path.name}"
        shutil.move(str(entry.path), target)
        return target

    def purge_all(self) -> None:
        """Borra TODO el búfer (dispositivo revocado: minimización de datos)."""
        if self.root.exists():
            shutil.rmtree(self.root, ignore_errors=True)
