"""Custodia de la clave maestra local (32 bytes) con la que se cifran el almacén de secretos, el estado y el búfer.

Orden de resolución (la primera que exista):
 1. variable de entorno ``PREDICTILY_BRIDGE_MASTER_KEY`` (base64 URL-safe; contenedores y servicios que inyectan secretos);
 2. almacén de claves del sistema (``keyring``: Keychain de macOS, Credential Locker/DPAPI de Windows, Secret Service de Linux) si el extra
    ``keyring`` está instalado y funciona;
 3. archivo ``master.key`` con permisos 0600 en el directorio del agente (0700).
Honestidad: el archivo protege contra copias de seguridad y lectura por otros usuarios, NO contra quien ya controla la cuenta del servicio
(ahí también estaría el token). En Windows se recomienda el almacén del sistema (DPAPI) y una cuenta de servicio dedicada: ver BRIDGE_WINDOWS.md.
"""

from __future__ import annotations

import os
from pathlib import Path

from predictily_bridge import crypto
from predictily_bridge.fsutil import ensure_private_dir, read_private, write_private

ENV_VAR = "PREDICTILY_BRIDGE_MASTER_KEY"
SERVICE = "predictily-bridge"


class KeystoreError(Exception):
    pass


class Keystore:
    def __init__(self, home: Path, *, use_keyring: bool = True) -> None:
        self.home = home
        self.use_keyring = use_keyring
        self._cached: bytes | None = None

    @property
    def backend(self) -> str:
        if os.environ.get(ENV_VAR):
            return "env"
        if self.use_keyring and self._keyring() is not None:
            return "keyring"
        return "file"

    def master_key(self, *, create: bool = True) -> bytes:
        if self._cached is not None:
            return self._cached
        env = os.environ.get(ENV_VAR)
        if env:
            key = crypto.b64u_decode(env.strip())
            if len(key) != 32:
                raise KeystoreError(f"{ENV_VAR} debe ser una clave de 32 bytes en base64 URL-safe.")
            self._cached = key
            return key
        ring = self._keyring() if self.use_keyring else None
        account = str(self.home.resolve())
        if ring is not None:
            stored = ring.get_password(SERVICE, account)
            if stored:
                self._cached = crypto.b64u_decode(stored)
                return self._cached
            if not create:
                raise KeystoreError("No hay clave maestra en el almacén del sistema.")
            key = crypto.new_master_key()
            ring.set_password(SERVICE, account, crypto.b64u(key))
            self._cached = key
            return key
        ensure_private_dir(self.home)
        path = self.home / "master.key"
        if path.exists():
            self._cached = crypto.b64u_decode(read_private(path).decode("ascii").strip())
            return self._cached
        if not create:
            raise KeystoreError("No hay clave maestra: ejecute «enroll» primero.")
        key = crypto.new_master_key()
        write_private(path, crypto.b64u(key).encode("ascii"))
        self._cached = key
        return key

    @staticmethod
    def _keyring():  # pragma: no cover - depende del extra y del sistema
        try:
            import keyring
            from keyring.backends.fail import Keyring as FailKeyring

            backend = keyring.get_keyring()
            return None if isinstance(backend, FailKeyring) else keyring
        except Exception:
            return None
