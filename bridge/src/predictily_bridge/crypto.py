"""Criptografía del agente: identidad Ed25519 del dispositivo, firma de peticiones y cifrado en reposo AES-256-GCM.

- La clave PRIVADA se genera localmente y nunca sale del equipo; al servidor solo llegan la clave pública (en cada petición, para que
  compruebe su huella) y las firmas.
- Todo lo que el agente guarda con secretos o datos de negocio (almacén de secretos, estado, búfer de lotes) va cifrado con AES-256-GCM
  y una clave maestra de 32 bytes que custodia ``keystore`` (almacén del sistema o archivo 0600).
- El mensaje firmado es idéntico al de ``backend/app/Services/Bridge/BridgeSignature.php`` (vectores compartidos en
  ``tests/fixtures/signature_vectors.json``).
"""

from __future__ import annotations

import base64
import hashlib
import os
import secrets
from dataclasses import dataclass

from cryptography.exceptions import InvalidSignature, InvalidTag
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

SIGNATURE_PREFIX = "PREDICTILY-BRIDGE-V1"
ENROL_PREFIX = "PREDICTILY-BRIDGE-ENROL-V1"
_MAGIC = b"PBE1"  # formato de los archivos cifrados


class CryptoError(Exception):
    """Archivo cifrado ilegible, alterado o con otra clave. Nunca incluye datos del contenido."""


def b64u(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def b64u_decode(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


@dataclass(frozen=True)
class DeviceKey:
    """Par Ed25519 del dispositivo. ``seed`` es la clave privada (32 bytes): solo vive cifrada en el almacén de secretos."""

    seed: bytes

    @staticmethod
    def generate() -> "DeviceKey":
        key = Ed25519PrivateKey.generate()
        return DeviceKey(key.private_bytes(serialization.Encoding.Raw, serialization.PrivateFormat.Raw, serialization.NoEncryption()))

    @property
    def _private(self) -> Ed25519PrivateKey:
        return Ed25519PrivateKey.from_private_bytes(self.seed)

    @property
    def public_bytes(self) -> bytes:
        return self._private.public_key().public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)

    @property
    def public_b64(self) -> str:
        return b64u(self.public_bytes)

    @property
    def fingerprint(self) -> str:
        """SHA-256 de la clave pública (lo que el servidor guarda en ``public_key_fingerprint``)."""
        return sha256_hex(self.public_bytes)

    def sign(self, message: str) -> str:
        return b64u(self._private.sign(message.encode("utf-8")))


def verify(public_bytes: bytes, signature_b64: str, message: str) -> bool:
    try:
        Ed25519PublicKey.from_public_bytes(public_bytes).verify(b64u_decode(signature_b64), message.encode("utf-8"))
    except (InvalidSignature, ValueError):
        return False
    return True


def request_message(method: str, path: str, query: str, timestamp: int | str, nonce: str, body: bytes) -> str:
    return "\n".join([SIGNATURE_PREFIX, method.upper(), path, query, str(timestamp), nonce, sha256_hex(body)])


def enrol_message(code_hash: str, public_key_b64: str, timestamp: int | str) -> str:
    return "\n".join([ENROL_PREFIX, code_hash, public_key_b64, str(timestamp)])


def new_nonce() -> str:
    return b64u(secrets.token_bytes(12))


def new_master_key() -> bytes:
    return AESGCM.generate_key(bit_length=256)


def seal(master_key: bytes, plaintext: bytes, context: bytes = b"") -> bytes:
    """AES-256-GCM: ``PBE1 || nonce(12) || cifrado+etiqueta``. ``context`` se autentica (impide cambiar un archivo por otro)."""
    nonce = os.urandom(12)
    return _MAGIC + nonce + AESGCM(master_key).encrypt(nonce, plaintext, _MAGIC + context)


def unseal(master_key: bytes, blob: bytes, context: bytes = b"") -> bytes:
    if len(blob) < len(_MAGIC) + 12 + 16 or not blob.startswith(_MAGIC):
        raise CryptoError("Archivo cifrado no reconocido.")
    nonce = blob[len(_MAGIC) : len(_MAGIC) + 12]
    try:
        return AESGCM(master_key).decrypt(nonce, blob[len(_MAGIC) + 12 :], _MAGIC + context)
    except InvalidTag:
        raise CryptoError("No se pudo descifrar: clave incorrecta o archivo alterado.") from None
