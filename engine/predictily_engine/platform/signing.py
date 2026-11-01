"""Firma Ed25519 de artefactos de modelos (ADR-V25-20; V2.5 §9: «modelos serializados no confiables prohibidos»).

Problema (auditoría 31-oct-2026, defecto HIGH de modelos_aws): los artefactos son joblib (pickle) y la única garantía era un SHA-256
guardado en la misma base de datos. Quien pudiera escribir una fila de `model_registry` y un archivo en el volumen ejecutaba código
en el motor con un `__reduce__` y un hash coherente.

Contrato:
- Cada artefacto lleva al lado un manifiesto firmado `<artefacto>.manifest.json` (un sobre JSON). El manifiesto es JSON canónico
  (claves ordenadas, separadores compactos, ASCII) con: SHA-256 y tamaño del artefacto, nombre de archivo, empresa
  (`tenant_id`), core, corrida, familia/nombre, fecha de creación, versiones de librerías, versión del esquema de variables y
  huella de los datos. La firma Ed25519 se calcula sobre los BYTES EXACTOS del manifiesto, que el sobre transporta como texto:
  quien verifica no re-serializa nada (sin ambigüedades entre lenguajes; el backend PHP verifica los mismos bytes).
- Claves por entorno: `ENGINE_SIGNING_KEY_ID` y `ENGINE_SIGNING_PRIVATE_KEY` (solo el lado de entrenamiento autorizado),
  `ENGINE_TRUSTED_PUBLIC_KEYS` (lista `kid:clave` en el lado de servicio) y `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` (por defecto falso).
- Al guardar: firma si hay clave privada; si no, el manifiesto se escribe SIN firma (el artefacto queda marcado como legado/sin
  firma y la base de datos lo registra así en `artifact_compat.signature`).
- Al cargar (`verify_artifact_file`): lee los bytes UNA sola vez y verifica firma con las claves de confianza, SHA-256, tamaño,
  empresa y core ANTES de deserializar; la deserialización parte de esos mismos bytes (`deserialize`), sin reabrir el archivo
  (sin TOCTOU). Un artefacto sin firma solo se acepta con `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` y verificable por su SHA-256 del
  registro; una firma inválida, una clave desconocida o un manifiesto corrupto NUNCA se aceptan, con ningún indicador.

Este módulo no registra claves ni rutas completas en los logs: solo códigos de motivo estables.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import io
import json
import logging
import os
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from pydantic_settings import BaseSettings, SettingsConfigDict

log = logging.getLogger(__name__)

MANIFEST_SUFFIX = ".manifest.json"
MANIFEST_SCHEMA = "predictily-artifact-manifest/1"
ENVELOPE_FORMAT = "predictily-signed-manifest/1"
ALGORITHM = "ed25519"
MAX_ENVELOPE_BYTES = 256 * 1024
_KEY_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
_SHA256 = re.compile(r"^[0-9a-f]{64}$")

# Motivos de rechazo estables (los consume `load_artifact`, los registros y el backend). Los dos primeros ya existían.
REASON_MISSING = "artifact_missing"
REASON_SHA256_MISMATCH = "artifact_sha256_mismatch"
REASON_UNSIGNED = "artifact_unsigned"
REASON_MANIFEST_INVALID = "artifact_manifest_invalid"
REASON_UNTRUSTED_KEY = "artifact_untrusted_key"
REASON_SIGNATURE_INVALID = "artifact_signature_invalid"
REASON_MANIFEST_MISMATCH = "artifact_manifest_mismatch"
REASON_SIZE_MISMATCH = "artifact_size_mismatch"
REASON_TENANT_MISMATCH = "artifact_tenant_mismatch"
REASON_CORE_MISMATCH = "artifact_core_mismatch"
REASON_RUN_MISMATCH = "artifact_run_mismatch"
REASON_PATH_INVALID = "artifact_path_invalid"

STATUS_SIGNED = "signed"
STATUS_UNSIGNED = "unsigned"


class SigningConfigError(RuntimeError):
    """Configuración de claves inválida (nunca incluye el material de la clave)."""


# ───────────────────────────── configuración por entorno ─────────────────────────────

class _SigningEnv(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ENGINE_", env_file=".env", extra="ignore")

    signing_key_id: str | None = None
    signing_private_key: str | None = None
    trusted_public_keys: str | None = None
    allow_unsigned_artifacts: str | None = None


@dataclass(frozen=True)
class SigningConfig:
    key_id: str | None = None
    private_key: Ed25519PrivateKey | None = field(default=None, repr=False)
    trusted: dict[str, Ed25519PublicKey] = field(default_factory=dict, repr=False)
    allow_unsigned: bool = False


def _b64decode(value: str) -> bytes:
    """Base64 estándar o URL-safe, con o sin relleno. @raises ValueError si no es base64 válido."""
    text = value.strip().replace("-", "+").replace("_", "/")
    text += "=" * (-len(text) % 4)
    try:
        return base64.b64decode(text, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ValueError("base64 no válido") from exc


def _b64(data: bytes) -> str:
    return base64.b64encode(data).decode("ascii")


def parse_trusted_keys(text: str | None) -> tuple[dict[str, Ed25519PublicKey], list[str]]:
    """`kid:clave` separados por comas, punto y coma o saltos de línea → (claves válidas, advertencias).

    Falla cerrado: una entrada inválida o un `kid` repetido con claves distintas no confían en nada.
    """
    keys: dict[str, Ed25519PublicKey] = {}
    raw: dict[str, bytes] = {}
    conflicted: set[str] = set()
    warnings: list[str] = []
    for entry in re.split(r"[,;\s]+", (text or "").strip()):
        if not entry:
            continue
        kid, sep, value = entry.partition(":")
        if not sep or not _KEY_ID.match(kid):
            warnings.append("entrada de ENGINE_TRUSTED_PUBLIC_KEYS ignorada: use «kid:clave»")
            continue
        try:
            material = _b64decode(value)
            public = Ed25519PublicKey.from_public_bytes(material)
        except ValueError:
            warnings.append(f"clave pública «{kid}» ignorada: no es una clave Ed25519 de 32 bytes en base64")
            continue
        if kid in raw and raw[kid] != material:
            conflicted.add(kid)
            continue
        raw[kid] = material
        keys[kid] = public
    for kid in conflicted:
        keys.pop(kid, None)
        warnings.append(f"clave pública «{kid}» repetida con valores distintos: se ignoran ambas")
    return keys, warnings


def _read_private_key(value: str) -> Ed25519PrivateKey:
    if value.startswith("file:"):
        try:
            value = Path(value[5:]).read_text(encoding="ascii").strip()
        except (OSError, UnicodeDecodeError) as exc:
            raise SigningConfigError("ENGINE_SIGNING_PRIVATE_KEY apunta a un archivo ilegible") from exc
    try:
        return Ed25519PrivateKey.from_private_bytes(_b64decode(value))
    except ValueError as exc:
        raise SigningConfigError("ENGINE_SIGNING_PRIVATE_KEY no es una semilla Ed25519 de 32 bytes en base64") from exc


def load_config() -> SigningConfig:
    """Lee la configuración de claves del entorno en cada llamada (sin caché: las pruebas y la rotación la cambian)."""
    env = _SigningEnv()
    trusted, warnings = parse_trusted_keys(env.trusted_public_keys)
    for message in warnings:
        log.warning("Firma de artefactos: %s.", message)
    private = None
    key_id = (env.signing_key_id or "").strip() or None
    if env.signing_private_key and env.signing_private_key.strip():
        if key_id is None or not _KEY_ID.match(key_id):
            raise SigningConfigError("ENGINE_SIGNING_KEY_ID es obligatorio (letras, números, punto, guion) cuando hay clave privada")
        private = _read_private_key(env.signing_private_key.strip())
    allow = (env.allow_unsigned_artifacts or "").strip().lower() in ("1", "true", "yes", "on")
    return SigningConfig(key_id=key_id, private_key=private, trusted=trusted, allow_unsigned=allow)


# ───────────────────────────── generación de claves ─────────────────────────────

def generate_keypair(key_id: str | None = None) -> tuple[str, str, str]:
    """Par Ed25519 nuevo → (key_id, semilla privada en base64, clave pública en base64). Sin escribir nada."""
    key_id = key_id or f"k-{datetime.now(timezone.utc):%Y%m%d}-{os.urandom(3).hex()}"
    if not _KEY_ID.match(key_id):
        raise ValueError("key_id no válido: use letras, números, punto, guion o guion bajo (máx. 64)")
    private = Ed25519PrivateKey.generate()
    seed = private.private_bytes(serialization.Encoding.Raw, serialization.PrivateFormat.Raw, serialization.NoEncryption())
    public = private.public_key().public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
    return key_id, _b64(seed), _b64(public)


def write_private_key_file(path: Path, private_b64: str, overwrite: bool = False) -> None:
    """Escribe la semilla privada con permisos 0600 desde la creación (sin ventana de lectura); no sobrescribe salvo `overwrite`."""
    flags = os.O_WRONLY | os.O_CREAT | (os.O_TRUNC if overwrite else os.O_EXCL)
    fd = os.open(path, flags, 0o600)
    try:
        os.write(fd, (private_b64 + "\n").encode("ascii"))
    finally:
        os.close(fd)
    os.chmod(path, 0o600)


def public_key_fingerprint(public_b64: str) -> str:
    """SHA-256 de la clave pública (para confirmar por otro canal que se instaló la clave correcta)."""
    return hashlib.sha256(_b64decode(public_b64)).hexdigest()


# ───────────────────────────── firma ─────────────────────────────

def canonical_json(manifest: dict[str, Any]) -> bytes:
    """Serialización canónica: claves ordenadas, separadores compactos, solo ASCII. Son los bytes que se firman."""
    return json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii")


def manifest_path(artifact: Path) -> Path:
    return artifact.with_name(artifact.name + MANIFEST_SUFFIX)


@dataclass(frozen=True)
class SignatureInfo:
    """Resumen de la firma de un artefacto recién guardado (se registra en `model_registry.artifact_compat.signature`)."""

    status: str                      # signed | unsigned
    key_id: str | None
    manifest_sha256: str
    algorithm: str | None = ALGORITHM

    def as_compat(self) -> dict[str, Any]:
        return {"status": self.status, "algorithm": self.algorithm if self.status == STATUS_SIGNED else None,
                "key_id": self.key_id, "manifest_sha256": self.manifest_sha256}


def build_manifest(*, artifact_name: str, sha256: str, size_bytes: int, tenant_id: int, core: str, run_id: int | None, name: str,
                   data_fingerprint: str | None, feature_schema_version: str | None, libraries: dict[str, str] | None,
                   key_id: str | None, created_at: datetime | None = None) -> dict[str, Any]:
    created = (created_at or datetime.now(timezone.utc)).astimezone(timezone.utc).replace(microsecond=0)
    return {
        "schema": MANIFEST_SCHEMA,
        "artifact": {"file_name": artifact_name, "format": "joblib", "sha256": sha256, "size_bytes": int(size_bytes)},
        "tenant_id": int(tenant_id),
        "core": core,
        "run_id": None if run_id is None else int(run_id),
        "name": name,
        "created_at": created.isoformat().replace("+00:00", "Z"),
        "libraries": dict(sorted((libraries or {}).items())),
        "feature_schema_version": feature_schema_version,
        "data_fingerprint": data_fingerprint,
        "key_id": key_id,
    }


def write_manifest(artifact: Path, manifest: dict[str, Any], config: SigningConfig) -> SignatureInfo:
    """Escribe `<artefacto>.manifest.json` (de forma atómica). Firmado si `config` trae clave privada; si no, sin firma."""
    signed = config.private_key is not None
    manifest = {**manifest, "key_id": config.key_id if signed else None}
    payload = canonical_json(manifest)
    envelope = {
        "format": ENVELOPE_FORMAT,
        "algorithm": ALGORITHM if signed else None,
        "key_id": config.key_id if signed else None,
        "manifest": payload.decode("ascii"),
        "signature": _b64(config.private_key.sign(payload)) if signed and config.private_key is not None else None,
    }
    body = (json.dumps(envelope, sort_keys=True, indent=2) + "\n").encode("ascii")
    target = manifest_path(artifact)
    temp = target.with_name(target.name + ".tmp")
    temp.write_bytes(body)
    os.replace(temp, target)
    return SignatureInfo(STATUS_SIGNED if signed else STATUS_UNSIGNED, config.key_id if signed else None, hashlib.sha256(body).hexdigest())


# ───────────────────────────── verificación ─────────────────────────────

@dataclass(frozen=True)
class VerifiedArtifact:
    """Bytes de un artefacto que superó la verificación. Es lo ÚNICO que `deserialize` acepta."""

    data: bytes = field(repr=False)
    sha256: str
    status: str                       # signed | unsigned_allowed
    key_id: str | None = None
    manifest: dict[str, Any] | None = field(default=None, repr=False)


@dataclass(frozen=True)
class Verification:
    reason: str | None                # None = verificado
    artifact: VerifiedArtifact | None = None

    @property
    def ok(self) -> bool:
        return self.reason is None and self.artifact is not None


def _reject(reason: str) -> Verification:
    return Verification(reason=reason)


def _same_digest(computed: str, recorded: object) -> bool:
    """Comparación en tiempo constante de dos SHA-256 en hexadecimal; un valor ilegible del registro (no texto, no ASCII) no coincide."""
    if not isinstance(recorded, str):
        return False
    return hmac.compare_digest(computed.encode("ascii"), recorded.lower().encode("utf-8"))


def _read_envelope(sidecar: Path) -> tuple[dict[str, Any] | None, str | None]:
    """(sobre, motivo). Sobre None sin motivo = no hay manifiesto (artefacto sin firma)."""
    try:
        with sidecar.open("rb") as handle:
            raw = handle.read(MAX_ENVELOPE_BYTES + 1)
    except FileNotFoundError:
        return None, None
    except OSError:
        return None, REASON_MANIFEST_INVALID
    if len(raw) > MAX_ENVELOPE_BYTES:
        return None, REASON_MANIFEST_INVALID
    try:
        envelope = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        return None, REASON_MANIFEST_INVALID
    if not isinstance(envelope, dict) or envelope.get("format") != ENVELOPE_FORMAT or not isinstance(envelope.get("manifest"), str):
        return None, REASON_MANIFEST_INVALID
    return envelope, None


def verify_artifact_file(path: Path, *, tenant_id: int, core: str | None = None, expected_sha256: str | None = None,
                         config: SigningConfig | None = None, run_id: int | None = None) -> Verification:
    """Verifica un artefacto ANTES de deserializarlo. Los bytes se leen una sola vez y son los que luego se deserializan.

    Orden: lectura única → SHA-256 contra el registro → manifiesto (ausente = sin firma) → clave de confianza → firma sobre los
    bytes del manifiesto → coherencia del manifiesto firmado (SHA-256, tamaño, nombre, empresa, core y —solo si el llamador conoce la
    corrida que registró el modelo, `run_id`— la corrida).
    """
    config = config or load_config()
    try:
        data = path.read_bytes()
    except OSError:
        return _reject(REASON_MISSING)
    digest = hashlib.sha256(data).hexdigest()
    row_matches = expected_sha256 is not None and _same_digest(digest, expected_sha256)
    if expected_sha256 is not None and not row_matches:
        return _reject(REASON_SHA256_MISMATCH)

    envelope, problem = _read_envelope(manifest_path(path))
    if problem is not None:
        return _reject(problem)                           # manifiesto corrupto: nunca se trata como «sin firma»
    if envelope is None or (envelope.get("signature") is None and envelope.get("key_id") is None):
        # Sin firma (legado o entrenado sin clave): solo con el indicador explícito y verificable por el SHA-256 del registro.
        if config.allow_unsigned and row_matches:
            return Verification(None, VerifiedArtifact(data, digest, "unsigned_allowed"))
        return _reject(REASON_UNSIGNED)

    key_id, algorithm, signature_b64 = envelope.get("key_id"), envelope.get("algorithm"), envelope.get("signature")
    if algorithm != ALGORITHM or not isinstance(key_id, str) or not _KEY_ID.match(key_id) or not isinstance(signature_b64, str):
        return _reject(REASON_MANIFEST_INVALID)
    public = config.trusted.get(key_id)
    if public is None:
        return _reject(REASON_UNTRUSTED_KEY)
    text = envelope["manifest"]
    if not text.isascii():
        return _reject(REASON_MANIFEST_INVALID)         # el manifiesto canónico es ASCII puro: otra cosa no lo escribió este motor
    payload = text.encode("ascii")
    try:
        signature = _b64decode(signature_b64)
        public.verify(signature, payload)
    except (InvalidSignature, ValueError):
        return _reject(REASON_SIGNATURE_INVALID)

    # A partir de aquí el manifiesto es de confianza (lo firmó una clave de confianza): se comprueba que describe ESTE artefacto.
    try:
        manifest = json.loads(payload)
    except ValueError:
        return _reject(REASON_MANIFEST_INVALID)
    meta = manifest.get("artifact") if isinstance(manifest, dict) else None
    if (not isinstance(manifest, dict) or manifest.get("schema") != MANIFEST_SCHEMA or not isinstance(meta, dict)
            or manifest.get("key_id") != key_id or not isinstance(meta.get("sha256"), str) or not _SHA256.match(meta["sha256"])):
        return _reject(REASON_MANIFEST_INVALID)
    if not hmac.compare_digest(meta["sha256"], digest):
        return _reject(REASON_MANIFEST_MISMATCH)
    if meta.get("size_bytes") != len(data) or isinstance(meta.get("size_bytes"), bool):
        return _reject(REASON_SIZE_MISMATCH)
    if meta.get("file_name") != path.name:
        return _reject(REASON_MANIFEST_MISMATCH)
    manifest_tenant = manifest.get("tenant_id")
    if isinstance(manifest_tenant, bool) or manifest_tenant != int(tenant_id):
        return _reject(REASON_TENANT_MISMATCH)
    if core is not None and manifest.get("core") != core:
        return _reject(REASON_CORE_MISMATCH)
    if run_id is not None and (isinstance(manifest.get("run_id"), bool) or manifest.get("run_id") != int(run_id)):
        return _reject(REASON_RUN_MISMATCH)      # un artefacto firmado de otra corrida no se hace pasar por el de esta fila del registro
    return Verification(None, VerifiedArtifact(data, digest, STATUS_SIGNED, key_id, manifest))


def deserialize(verified: VerifiedArtifact) -> Any:
    """Deserializa SOLO bytes que superaron `verify_artifact_file` (sin reabrir el archivo: sin TOCTOU)."""
    if not isinstance(verified, VerifiedArtifact):
        raise TypeError("Solo se deserializan artefactos verificados (VerifiedArtifact).")
    return joblib.load(io.BytesIO(verified.data))
