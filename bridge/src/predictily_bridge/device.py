"""Directorio del agente (0700): identidad del dispositivo, secretos cifrados, estado y búfer.

    <home>/device.json     datos NO secretos (id, servidor, huella, empresa/fuente) — legible para ``status``
    <home>/secrets.enc     token del dispositivo, clave privada Ed25519 y secretos de la fuente (AES-256-GCM)
    <home>/state.enc       estado de extracción (AES-256-GCM)
    <home>/spool/          búfer de lotes pendientes (AES-256-GCM)
    <home>/master.key      clave maestra (solo si no hay almacén del sistema ni variable de entorno)
    <home>/revoked.flag    marca de dispositivo revocado
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from predictily_bridge import crypto
from predictily_bridge.fsutil import ensure_private_dir, read_private, write_private
from predictily_bridge.keystore import Keystore
from predictily_bridge.logging_utils import register_secret
from predictily_bridge.securestore import SecureStore
from predictily_bridge.transport import Identity


class NotEnrolled(Exception):
    pass


class DeviceHome:
    def __init__(self, home: Path, *, use_keyring: bool = True) -> None:
        self.home = home
        ensure_private_dir(home)
        self.keystore = Keystore(home, use_keyring=use_keyring)
        self.secrets = SecureStore(home / "secrets.enc", self.keystore)
        self.state_store = SecureStore(home / "state.enc", self.keystore)
        self.spool_dir = home / "spool"
        self.revoked_flag = home / "revoked.flag"

    # ── identidad ──
    def is_enrolled(self) -> bool:
        return self.secrets.exists() and (self.home / "device.json").exists()

    def device_info(self) -> dict[str, Any]:
        path = self.home / "device.json"
        if not path.exists():
            raise NotEnrolled("Este equipo no está registrado: ejecute «predictily-bridge enroll».")
        return json.loads(read_private(path).decode("utf-8"))

    def save_enrolment(self, *, key: crypto.DeviceKey, token: str, info: dict[str, Any]) -> None:
        data = self.secrets.load({})
        data.update({"device_token": token, "device_key_seed": crypto.b64u(key.seed)})
        self.secrets.save(data)
        write_private(self.home / "device.json", json.dumps(info, ensure_ascii=False, indent=1).encode("utf-8"))
        register_secret(token)
        self.revoked_flag.unlink(missing_ok=True)

    def load_identity(self) -> Identity:
        if not self.is_enrolled():
            raise NotEnrolled("Este equipo no está registrado: ejecute «predictily-bridge enroll».")
        data = self.secrets.load({})
        token = str(data["device_token"])
        register_secret(token)
        return Identity(token, crypto.DeviceKey(crypto.b64u_decode(str(data["device_key_seed"]))))

    # ── secretos de la fuente ──
    def secret(self, name: str) -> str | None:
        if not self.secrets.exists():
            return None
        value = self.secrets.load({}).get("source:" + name)
        if value:
            register_secret(str(value))
        return str(value) if value else None

    def set_secret(self, name: str, value: str) -> None:
        data = self.secrets.load({})
        data["source:" + name] = value
        self.secrets.save(data)
        register_secret(value)

    def mark_revoked(self) -> None:
        write_private(self.revoked_flag, b"revoked")

    @property
    def revoked(self) -> bool:
        return self.revoked_flag.exists()
