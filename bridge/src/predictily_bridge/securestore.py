"""Almacén JSON cifrado (AES-256-GCM) con escritura atómica y permisos 0600: secretos del dispositivo y estado de extracción.

Contiene: token del dispositivo, clave privada Ed25519, contraseña de la fuente (si el administrador la guardó aquí). Nunca se registra.
El nombre del archivo se autentica como contexto: copiar ``state.enc`` sobre ``secrets.enc`` falla al descifrar.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from predictily_bridge import crypto
from predictily_bridge.fsutil import read_private, write_private
from predictily_bridge.keystore import Keystore


class SecureStore:
    def __init__(self, path: Path, keystore: Keystore) -> None:
        self.path = path
        self.keystore = keystore

    def exists(self) -> bool:
        return self.path.exists()

    def load(self, default: dict[str, Any] | None = None) -> dict[str, Any]:
        if not self.path.exists():
            return dict(default) if default is not None else {}
        blob = read_private(self.path)
        plain = crypto.unseal(self.keystore.master_key(create=False), blob, self.path.name.encode())
        data = json.loads(plain.decode("utf-8"))
        if not isinstance(data, dict):
            raise crypto.CryptoError("Formato de almacén no válido.")
        return data

    def save(self, data: dict[str, Any]) -> None:
        plain = json.dumps(data, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")
        write_private(self.path, crypto.seal(self.keystore.master_key(), plain, self.path.name.encode()))
