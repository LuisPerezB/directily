"""Paquete de modelos de una empresa: guardar lo entrenado, empaquetar, firmar, verificar y publicar como `candidate` (ADR-V25-19, -20, -07).

Directorio de trabajo (`--out` de `train`, `--dir` del resto):

    train.json                      resumen de lo entrenado (NO firmado: es un borrador; lo que se publica sale del paquete firmado)
    artifacts/<nombre>.joblib       artefacto serializado
    artifacts/<nombre>.joblib.manifest.json   manifiesto Ed25519 del artefacto (platform/signing.py; el mismo que verifica el serving)
    report/evaluations.json         filas de evaluación (demanda) para auditar y comparar
    package.manifest.json           sobre firmado `predictily-signed-package/1` con TODO lo que `publish` registrará (huellas, versiones de
                                    librerías, huella de datos, hiperparámetros, métricas y resultado del gate de datos)

`publish` solo lee el paquete FIRMADO (nunca `train.json`) y exige que cada artefacto verifique contra su huella, firma y empresa. Un artefacto sin
firma (legado o entrenado sin clave) no se publica (ADR-V25-20: re-firmar o reentrenar). Contrato de publicación con el backend
(MODEL_REGISTRY §5.6): estado `candidate` —NUNCA `validated`, `approved` ni `production`—, `published_by`, `published_env`, `published_at`,
`published_object_key` (clave del servidor `tenants/<uuid>/models/<core>/<versión>/<archivo>`, con el `.manifest.json` firmado al lado),
`artifact_sha256`, eventos `model_registry_events` con `actor_kind = cli` y `actor_user_id`, y `artifact_compat.data_gate.outcome`.
"""

from __future__ import annotations

import hashlib
import io
import json
import logging
import math
import os
import re
import secrets
from dataclasses import dataclass, field
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any

import joblib
from cryptography.exceptions import InvalidSignature
from sqlalchemy import Engine, text
from sqlalchemy.exc import IntegrityError

from predictily_engine import __version__
from predictily_engine.mlops import policy
from predictily_engine.mlops.errors import EXIT_CHECK_FAILED, EXIT_CONFIG, EXIT_CONFLICT, EXIT_NOT_AUTHORIZED, EXIT_NOT_FOUND, EXIT_USAGE, CliError
from predictily_engine.platform import signing
from predictily_engine.platform.object_store import (
    MANIFEST_SUFFIX,
    ObjectConflict,
    ObjectStore,
    ObjectStoreError,
    model_object_key,
)

log = logging.getLogger(__name__)

TRAIN_FILE = "train.json"
PACKAGE_FILE = "package.manifest.json"
ARTIFACT_DIR = "artifacts"
REPORT_DIR = "report"
PACKAGE_SCHEMA = "predictily-model-package/1"
PACKAGE_FORMAT = "predictily-signed-package/1"
ENVIRONMENTS = ("local", "staging", "production")
PUBLISH_STATUS = "candidate"        # lo ÚNICO que escribe `publish` (contrato §5.6): jamás validated, approved ni production
DRAFT_MAX_BYTES = 8 * 1024 * 1024

R_PACKAGE_MISSING = "package_missing"
R_PACKAGE_INVALID = "package_manifest_invalid"
R_PACKAGE_UNSIGNED = "package_unsigned"
R_PACKAGE_UNTRUSTED = "package_untrusted_key"
R_PACKAGE_SIGNATURE = "package_signature_invalid"
R_PACKAGE_TENANT = "package_tenant_mismatch"
R_ARTIFACT_NOT_SIGNED = "artifact_not_signed"


def _now() -> datetime:
    return datetime.now(timezone.utc)


def _iso(moment: datetime) -> str:
    return moment.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _sanitize(value: Any) -> Any:
    """Valor JSON puro y estable: NaN/Inf → null, fechas y tipos de numpy → texto o número, claves → texto."""
    if isinstance(value, dict):
        return {str(k): _sanitize(v) for k, v in value.items()}
    if isinstance(value, (list, tuple, set)):
        return [_sanitize(v) for v in value]
    if isinstance(value, bool) or value is None or isinstance(value, (str, int)):
        return value
    if isinstance(value, float):
        return value if math.isfinite(value) else None
    if hasattr(value, "isoformat"):
        return value.isoformat()
    if hasattr(value, "item"):
        return _sanitize(value.item())
    return str(value)


def write_json_atomic(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    body = (json.dumps(_sanitize(payload), indent=2, sort_keys=True, ensure_ascii=False) + "\n").encode("utf-8")
    temp = path.with_name(path.name + ".tmp")
    temp.write_bytes(body)
    os.replace(temp, path)


def read_json(path: Path, *, max_bytes: int = DRAFT_MAX_BYTES) -> Any:
    try:
        with path.open("rb") as handle:
            raw = handle.read(max_bytes + 1)
    except FileNotFoundError as exc:
        raise CliError(EXIT_NOT_FOUND, f"No existe {path.name} en el directorio indicado.") from exc
    if len(raw) > max_bytes:
        raise CliError(EXIT_USAGE, f"{path.name} es demasiado grande.")
    try:
        return json.loads(raw)
    except ValueError as exc:
        raise CliError(EXIT_USAGE, f"{path.name} no es un JSON válido.") from exc


def load_signing_config() -> signing.SigningConfig:
    try:
        return signing.load_config()
    except signing.SigningConfigError as exc:
        raise CliError(EXIT_CONFIG, f"Configuración de firma inválida: {exc}") from exc


# ───────────────────────────── guardar lo entrenado ─────────────────────────────

@dataclass(frozen=True)
class ArtifactRecord:
    file: str
    name: str
    sha256: str
    size_bytes: int
    signature: dict[str, Any]


def write_signed_artifact(obj: Any, directory: Path, name: str, *, tenant_id: int, core: str, data_fingerprint: str | None,
                          feature_schema_version: str, libraries: dict[str, str], config: signing.SigningConfig) -> ArtifactRecord:
    """Serializa `obj` en `directory/<name>.joblib` y deja a su lado el manifiesto (firmado si `config` trae clave privada).

    Misma mecánica que `platform.artifacts.save_signed_artifact` (SHA-256 sobre los bytes escritos, escritura atómica, manifiesto DESPUÉS) pero sin
    ligarse al directorio de artefactos del servicio: es un borrador local hasta que `publish` lo sube.
    """
    buffer = io.BytesIO()
    joblib.dump(obj, buffer, compress=3)
    data = buffer.getvalue()
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / f"{name}.joblib"
    temp = path.with_name(path.name + ".tmp")
    temp.write_bytes(data)
    os.replace(temp, path)
    digest = hashlib.sha256(data).hexdigest()
    manifest = signing.build_manifest(artifact_name=path.name, sha256=digest, size_bytes=len(data), tenant_id=tenant_id, core=core, run_id=None,
                                      name=name, data_fingerprint=data_fingerprint, feature_schema_version=feature_schema_version,
                                      libraries=libraries, key_id=config.key_id)
    info = signing.write_manifest(path, manifest, config)
    return ArtifactRecord(path.name, name, digest, len(data), info.as_compat())


def save_training(out_dir: Path, run: Any, core: str, *, config: signing.SigningConfig | None = None, notes: list[str] | None = None) -> dict[str, Any]:
    """Escribe los artefactos de una corrida (`DemandRun` | `CustomerRun`) y el borrador `train.json`. No toca la base de datos."""
    from predictily_engine.mlops import training as T

    config = config or load_signing_config()
    out_dir = Path(out_dir)
    if (out_dir / TRAIN_FILE).exists() or (out_dir / PACKAGE_FILE).exists():
        raise CliError(EXIT_CONFLICT, f"{out_dir} ya contiene un entrenamiento o un paquete; use otro directorio (no se sobrescribe).")
    libraries = T.library_versions_for(core)
    schema = T.feature_schema_for(core)
    if core == "demand":
        entries = T.demand_entries(run)
        fingerprint = run.result.training_data_hash
        metrics = T.demand_metrics_summary(run.result.evaluations)
        gate = T.data_gate_block(run.result.quality, "demand") if run.result.quality is not None else None
        extra = {"cutoff": run.result.cutoff.isoformat(), "data_from": run.result.data_from.isoformat(), "data_to": run.result.data_to.isoformat(),
                 "quality_status": run.result.quality_status, "horizons": list(run.horizons),
                 "limitation_codes": [x.get("code") for x in run.result.limitations],
                 "selection_winners": getattr(run.result.selection, "winners", None), "tuning": (run.result.manifest.get("promotion") or {}).get("tuning")}
        trained_to = run.result.data_to
        trained_from = run.result.data_from
        compat_base = {"input_schema": "demand-panel-v1", "feature_set_version": schema, "libraries": libraries,
                       "artifact_kind": "mlforecast_joblib", "frequency": "D", "season_length": 7}
        evaluations = run.result.evaluations
    else:
        from predictily_engine.customer.service import _artifact_compat, _snapshot

        entries = T.customer_entries(run)
        inputs = run.inputs
        fingerprint = _snapshot(inputs.data, inputs.cfg, inputs.cfg_version, run.last_complete or date.today(),
                                {"horizons": run.horizons, "seed": run.seed}).hash
        metrics = T.customer_metrics_summary(run)
        gate = T.data_gate_block(inputs.qa, "customer")
        extra = {"as_of": run.last_complete.isoformat() if run.last_complete else None, "horizons": run.horizons, "config_version": inputs.cfg_version,
                 "individual_stop": run.individual_stop, "horizon_status": {str(h): r.status for h, r in run.results.items()}}
        trained_to = run.last_complete
        trained_from = None
        compat_base = _artifact_compat()
        evaluations = None
    models = []
    for entry in entries:
        record = write_signed_artifact(entry.obj, out_dir / ARTIFACT_DIR, entry.name, tenant_id=run.inputs.tenant_id, core=core,
                                       data_fingerprint=fingerprint, feature_schema_version=schema, libraries=libraries, config=config)
        models.append({
            "file": record.file, "name": record.name, "sha256": record.sha256, "size_bytes": record.size_bytes, "signature": record.signature,
            "registry": {"model_family": entry.model_family, "role": entry.role, "strategy_level": entry.strategy_level,
                         "demand_class": entry.demand_class, "hyperparameters": entry.hyperparameters, "series_count": entry.series_count,
                         "purpose": entry.purpose, "granularity": entry.granularity, "slot": entry.slot, "served": entry.served, "notes": entry.notes,
                         "feature_set_version": schema, "training_data_to": trained_to.isoformat() if trained_to else None,
                         "training_data_from": trained_from.isoformat() if trained_from else None,
                         "training_data_hash": fingerprint, "random_seed": run.seed, "library_versions": libraries,
                         "artifact_compat": compat_base}})
    draft = {
        "schema": "predictily-train-draft/1", "tenant_id": run.inputs.tenant_id, "core": core, "created_at": _iso(_now()), "engine_version": __version__,
        "seed": run.seed, "elapsed_seconds": round(run.elapsed_s, 1), "source": run.inputs.source, "data_fingerprint": fingerprint,
        "feature_schema_version": schema, "libraries": libraries, "data_gate": gate, "metrics": metrics, "details": extra, "models": models,
        "signed": bool(config.private_key is not None), "notes": notes or [],
    }
    if core == "demand" and not entries:
        draft["notes"] = [*draft["notes"], "Esta corrida no produjo un artefacto serializable (los modelos estadísticos se reajustan en cada corrida; LightGBM no participó)."]
    write_json_atomic(out_dir / TRAIN_FILE, draft)
    if evaluations is not None:
        write_json_atomic(out_dir / REPORT_DIR / "evaluations.json", evaluations)
    return draft


# ───────────────────────────── empaquetar (sobre firmado) ─────────────────────────────

def _artifact_path(directory: Path, file: str) -> Path:
    if file != Path(file).name or not file.endswith(".joblib"):
        raise CliError(EXIT_USAGE, "Nombre de artefacto no válido en el borrador.")
    return directory / ARTIFACT_DIR / file


def sign_artifacts(directory: Path, config: signing.SigningConfig, *, force: bool = False) -> list[dict[str, Any]]:
    """(Re)firma los artefactos del borrador con la clave privada configurada. No deserializa nada: parte de los bytes del archivo y exige que su SHA-256
    coincida con el que quedó registrado al entrenar (`train.json`); si no, el archivo cambió después de entrenar y no se firma."""
    if config.private_key is None or not config.key_id:
        raise CliError(EXIT_CONFIG, "No hay clave de firma: defina ENGINE_SIGNING_KEY_ID y ENGINE_SIGNING_PRIVATE_KEY (keygen genera el par).")
    draft = read_json(directory / TRAIN_FILE)
    done = []
    for model in draft.get("models", []):
        path = _artifact_path(directory, model["file"])
        try:
            data = path.read_bytes()
        except OSError as exc:
            raise CliError(EXIT_NOT_FOUND, f"Falta el artefacto {model['file']}.") from exc
        digest = hashlib.sha256(data).hexdigest()
        if digest != model["sha256"]:
            raise CliError(EXIT_CONFLICT, f"{model['file']} cambió después de entrenar (SHA-256 distinto del registrado): no se firma.")
        current = signing.verify_artifact_file(path, tenant_id=draft["tenant_id"], core=draft["core"], expected_sha256=digest, config=config)
        if current.ok and current.artifact is not None and current.artifact.status == signing.STATUS_SIGNED and not force:
            done.append({"file": model["file"], "action": "ya firmado", "key_id": current.artifact.key_id})
            continue
        manifest = signing.build_manifest(
            artifact_name=path.name, sha256=digest, size_bytes=len(data), tenant_id=draft["tenant_id"], core=draft["core"], run_id=None,
            name=model["name"], data_fingerprint=draft.get("data_fingerprint"), feature_schema_version=draft.get("feature_schema_version"),
            libraries=draft.get("libraries"), key_id=config.key_id)
        info = signing.write_manifest(path, manifest, config)
        model["signature"] = info.as_compat()
        done.append({"file": model["file"], "action": "firmado", "key_id": config.key_id})
    write_json_atomic(directory / TRAIN_FILE, {**draft, "signed": True})
    return done


def build_package(directory: Path, config: signing.SigningConfig, *, force: bool = False) -> dict[str, Any]:
    """Crea `package.manifest.json` (sobre firmado) a partir del borrador. Todo artefacto debe estar FIRMADO y verificar con las claves de confianza."""
    directory = Path(directory)
    target = directory / PACKAGE_FILE
    if target.exists() and not force:
        raise CliError(EXIT_CONFLICT, f"{PACKAGE_FILE} ya existe; el paquete firmado no se reescribe (use --force para rehacerlo con otro identificador).")
    if config.private_key is None or not config.key_id:
        raise CliError(EXIT_CONFIG, "Empaquetar firma el paquete: defina ENGINE_SIGNING_KEY_ID y ENGINE_SIGNING_PRIVATE_KEY.")
    draft = read_json(directory / TRAIN_FILE)
    if not draft.get("models"):
        raise CliError(EXIT_USAGE, "El entrenamiento no produjo artefactos que empaquetar.")
    gate = draft.get("data_gate") or {}
    if gate.get("outcome") not in ("PASS", "WARN", "DEGRADE"):
        raise CliError(EXIT_USAGE, "El borrador no declara un resultado del gate de datos utilizable (PASS, WARN o DEGRADE): no se empaqueta.")
    models = []
    for model in draft["models"]:
        path = _artifact_path(directory, model["file"])
        verification = signing.verify_artifact_file(path, tenant_id=draft["tenant_id"], core=draft["core"], expected_sha256=model["sha256"], config=config)
        if not verification.ok or verification.artifact is None or verification.artifact.status != signing.STATUS_SIGNED:
            raise CliError(EXIT_CHECK_FAILED, f"{model['file']} no verifica como firmado ({verification.reason or R_ARTIFACT_NOT_SIGNED}); "
                                          "firme con «sign» o reentrene con la clave de firma.")
        sidecar = signing.manifest_path(path).read_bytes()
        models.append({**{k: model[k] for k in ("file", "name", "sha256", "size_bytes")}, "key_id": verification.artifact.key_id,
                       "manifest_sha256": hashlib.sha256(sidecar).hexdigest(), "registry": model["registry"]})
    manifest = {
        "schema": PACKAGE_SCHEMA, "bundle_id": secrets.token_hex(8), "tenant_id": int(draft["tenant_id"]), "core": draft["core"],
        "created_at": _iso(_now()), "engine_version": draft.get("engine_version"), "seed": draft.get("seed"),
        "feature_schema_version": draft.get("feature_schema_version"), "libraries": draft.get("libraries"),
        "data_fingerprint": draft.get("data_fingerprint"), "source": draft.get("source"), "data_gate": gate,
        "metrics": draft.get("metrics"), "details": draft.get("details"), "models": models, "key_id": config.key_id,
    }
    manifest = _sanitize(manifest)
    payload = signing.canonical_json(manifest)
    envelope = {"format": PACKAGE_FORMAT, "algorithm": signing.ALGORITHM, "key_id": config.key_id, "manifest": payload.decode("ascii"),
                "signature": signing._b64(config.private_key.sign(payload))}
    write_json_atomic(target, envelope)
    return manifest


def read_package(directory: Path, config: signing.SigningConfig | None = None, *, tenant_id: int | None = None) -> tuple[dict[str, Any] | None, str | None]:
    """(manifiesto, motivo). Verifica la firma del paquete con las claves de confianza ANTES de usar su contenido."""
    config = config or load_signing_config()
    path = Path(directory) / PACKAGE_FILE
    try:
        with path.open("rb") as handle:
            raw = handle.read(signing.MAX_ENVELOPE_BYTES * 8 + 1)
    except FileNotFoundError:
        return None, R_PACKAGE_MISSING
    except OSError:
        return None, R_PACKAGE_INVALID
    try:
        envelope = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        return None, R_PACKAGE_INVALID
    if (not isinstance(envelope, dict) or envelope.get("format") != PACKAGE_FORMAT or not isinstance(envelope.get("manifest"), str)
            or not envelope["manifest"].isascii()):
        return None, R_PACKAGE_INVALID
    key_id, signature = envelope.get("key_id"), envelope.get("signature")
    if envelope.get("algorithm") != signing.ALGORITHM or not isinstance(key_id, str) or not isinstance(signature, str):
        return None, R_PACKAGE_UNSIGNED
    public = config.trusted.get(key_id)
    if public is None:
        return None, R_PACKAGE_UNTRUSTED
    payload = envelope["manifest"].encode("ascii")
    try:
        public.verify(signing._b64decode(signature), payload)
    except (InvalidSignature, ValueError):
        return None, R_PACKAGE_SIGNATURE
    try:
        manifest = json.loads(payload)
    except ValueError:
        return None, R_PACKAGE_INVALID
    if (not isinstance(manifest, dict) or manifest.get("schema") != PACKAGE_SCHEMA or manifest.get("key_id") != key_id
            or not isinstance(manifest.get("models"), list) or not isinstance(manifest.get("tenant_id"), int)):
        return None, R_PACKAGE_INVALID
    if tenant_id is not None and manifest["tenant_id"] != int(tenant_id):
        return None, R_PACKAGE_TENANT
    return manifest, None


@dataclass
class VerifyReport:
    ok: bool
    package: str                                  # verified | absent | <motivo>
    artifacts: list[dict[str, Any]] = field(default_factory=list)
    reasons: list[str] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return {"ok": self.ok, "package": self.package, "artifacts": self.artifacts, "reasons": self.reasons}


def verify_directory(directory: Path, config: signing.SigningConfig | None = None, *, tenant_id: int | None = None,
                     require_package: bool = False) -> VerifyReport:
    """Verifica el paquete y TODOS sus artefactos sin deserializar nada (firma Ed25519, clave de confianza, SHA-256, tamaño, empresa y core)."""
    config = config or load_signing_config()
    directory = Path(directory)
    manifest, reason = read_package(directory, config, tenant_id=tenant_id)
    reasons: list[str] = []
    if manifest is not None:
        package_state, tenant, core, expected = "verified", manifest["tenant_id"], manifest["core"], {m["file"]: m["sha256"] for m in manifest["models"]}
    elif reason == R_PACKAGE_MISSING and not require_package:
        draft = read_json(directory / TRAIN_FILE)
        package_state, tenant, core, expected = "absent", int(draft["tenant_id"]), draft["core"], {m["file"]: m["sha256"] for m in draft.get("models", [])}
        if tenant_id is not None and tenant != int(tenant_id):
            return VerifyReport(False, "absent", [], [R_PACKAGE_TENANT])
    else:
        return VerifyReport(False, reason or R_PACKAGE_INVALID, [], [reason or R_PACKAGE_INVALID])
    results = []
    ok = True
    for file, sha in sorted(expected.items()):
        try:
            path = _artifact_path(directory, file)
        except CliError:
            results.append({"file": file, "ok": False, "reason": "artifact_name_invalid"})
            ok = False
            continue
        v = signing.verify_artifact_file(path, tenant_id=tenant, core=core, expected_sha256=sha, config=config)
        state = v.artifact.status if v.artifact else None
        good = v.ok and state == signing.STATUS_SIGNED
        results.append({"file": file, "ok": good, "reason": None if good else (v.reason or R_ARTIFACT_NOT_SIGNED), "status": state,
                        "key_id": v.artifact.key_id if v.artifact else None})
        ok = ok and good
    if not expected:
        ok = False
        reasons.append("package_without_models")
    if not ok:
        reasons.extend(sorted({r["reason"] for r in results if not r["ok"]}))
    return VerifyReport(ok, package_state, results, reasons)


# ───────────────────────────── publicar ─────────────────────────────

@dataclass
class PublishedModel:
    version: str
    object_key: str
    model_registry_id: int | None
    action: str                                   # published | already_published | dry_run
    sha256: str
    size_bytes: int


@dataclass
class PublishReport:
    tenant_id: int
    core: str
    env: str
    bundle_id: str
    dry_run: bool
    models: list[PublishedModel]

    def as_dict(self) -> dict[str, Any]:
        return {"tenant_id": self.tenant_id, "core": self.core, "env": self.env, "bundle_id": self.bundle_id, "dry_run": self.dry_run,
                "status_written": PUBLISH_STATUS, "models": [m.__dict__ for m in self.models],
                "next_step": "Un propietario o administrador DISTINTO de quien publicó debe validar y aprobar cada candidato en el backend "
                             "(POST /api/v1/models/{id}/validate, /approve y /promote). Esta CLI nunca promueve."}


def model_version(tenant_id: int, core: str, bundle_id: str, name: str) -> str:
    """Versión generada por el servidor para un modelo publicado: única por empresa y estable para el mismo paquete (reintentos idempotentes)."""
    return f"t{int(tenant_id)}-pub-{core}-{bundle_id[:8]}-{name}"[:100]


def operator_identity() -> dict[str, str]:
    """Quién ejecutó la CLI en la máquina (usuario del sistema operativo y nombre del equipo). `--published-by-user-id` es una declaración del operador: esto deja
    constancia, en el evento de auditoría, de dónde y con qué cuenta se declaró (no es una prueba criptográfica de identidad)."""
    import getpass
    import socket

    def clean(value: str) -> str:
        return re.sub(r"[^A-Za-z0-9._@-]", "?", value)[:64] or "desconocido"

    try:
        user = getpass.getuser()
    except Exception:  # noqa: BLE001  (contenedores sin entrada en /etc/passwd)
        user = "desconocido"
    try:
        host = socket.gethostname()
    except Exception:  # noqa: BLE001
        host = "desconocido"
    return {"os_user": clean(user), "hostname": clean(host)}


def publish_package(engine: Engine, store: ObjectStore, directory: Path, *, env: str, published_by_user_id: int, tenant_id: int | None = None,
                    dry_run: bool = False, confirm_production: bool = False, config: signing.SigningConfig | None = None) -> PublishReport:
    """Sube el paquete firmado al almacén y registra cada modelo como `candidate` (contrato de MODEL_REGISTRY §5.6). NUNCA escribe otro estado.

    Control de quién publica: `--published-by-user-id` es una DECLARACIÓN del operador (organizativa, no criptográfica). Se exige que el usuario pertenezca a la
    empresa y que su rol tenga la habilidad `forecast.run` (la que el backend pide para validar un candidato publicado), y el evento de auditoría registra el
    usuario y el equipo desde donde se ejecutó (`cli_operator`).
    """
    if env not in ENVIRONMENTS:
        raise CliError(EXIT_USAGE, f"--env debe ser uno de: {', '.join(ENVIRONMENTS)}.")
    if env == "production" and not confirm_production and not dry_run:
        raise CliError(EXIT_NOT_AUTHORIZED, "Publicar con --env production exige --confirm-production. Recuerde: publicar solo registra un candidato; "
                                            "servirlo exige la aprobación separada del backend.")
    config = config or load_signing_config()
    directory = Path(directory)
    _, missing = read_package(directory, config, tenant_id=None)
    if missing == R_PACKAGE_MISSING:
        raise CliError(EXIT_NOT_FOUND, f"No hay {PACKAGE_FILE} en {directory}: entrene con «train» y empaquete con «package» antes de publicar.")
    verification = verify_directory(directory, config, tenant_id=tenant_id, require_package=True)
    if not verification.ok:
        raise CliError(EXIT_CHECK_FAILED, "El paquete no verifica (" + ", ".join(verification.reasons or [verification.package]) + "): no se publica nada.")
    manifest, _ = read_package(directory, config, tenant_id=tenant_id)
    assert manifest is not None
    tenant, core, bundle_id = int(manifest["tenant_id"]), manifest["core"], str(manifest["bundle_id"])

    with engine.connect() as conn:
        row = conn.execute(text("SELECT id, uuid::text AS uuid, status FROM tenants WHERE id = :t AND deleted_at IS NULL"), {"t": tenant}).mappings().first()
        if row is None:
            raise CliError(EXIT_NOT_FOUND, f"La empresa {tenant} no existe en la base de datos de destino.")
        if row["status"] != "active":
            raise CliError(EXIT_NOT_AUTHORIZED, f"La empresa {tenant} no está activa ({row['status']}).")
        member = conn.execute(text("SELECT tu.role FROM tenant_users tu JOIN users u ON u.id = tu.user_id "
                                   "WHERE tu.tenant_id = :t AND tu.user_id = :u AND u.deleted_at IS NULL"), {"t": tenant, "u": int(published_by_user_id)}).mappings().first()
        if member is None:
            raise CliError(EXIT_NOT_AUTHORIZED, f"El usuario {published_by_user_id} no pertenece a la empresa {tenant} (o está eliminado): no puede publicar en su nombre.")
        if not policy.role_can(member["role"], policy.PUBLISH_ABILITY):
            raise CliError(EXIT_NOT_AUTHORIZED, f"El usuario {published_by_user_id} tiene el rol «{member['role']}», que no tiene la habilidad {policy.PUBLISH_ABILITY} "
                                                "(la que el backend exige para validar un candidato publicado): no puede publicar.")
    tenant_uuid = row["uuid"]
    publisher_role = str(member["role"])

    plan = []
    for model in manifest["models"]:
        version = model_version(tenant, core, bundle_id, model["name"])
        key = model_object_key(tenant_uuid, core, version, model["file"])
        plan.append((model, version, key))

    if dry_run:
        return PublishReport(tenant, core, env, bundle_id, True,
                             [PublishedModel(v, k, None, "dry_run", m["sha256"], m["size_bytes"]) for m, v, k in plan])

    operator = operator_identity()
    created: dict[str, list[str]] = {}          # versión → claves que ESTA llamada creó en el almacén (solo esas se borran si algo falla)
    out: list[PublishedModel] = []
    try:
        # Una sola transacción: el bloqueo consultivo serializa dos publicaciones del MISMO paquete (la segunda espera, ve las filas de la primera y responde
        # `already_published` sin borrar nada del almacén); si algo falla, se deshacen las filas y se borra solo lo creado aquí.
        with engine.begin() as conn:
            conn.execute(text("SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))"), {"k": f"predictily-publish:{tenant}:{bundle_id}"})
            for model, version, key in plan:
                path = _artifact_path(directory, model["file"])
                data = path.read_bytes()
                sidecar = signing.manifest_path(path).read_bytes()
                if hashlib.sha256(data).hexdigest() != model["sha256"] or hashlib.sha256(sidecar).hexdigest() != model["manifest_sha256"]:
                    raise CliError(EXIT_CONFLICT, f"{model['file']} cambió después de empaquetar: no se publica.")
                for target, payload, content_type in ((key, data, "application/octet-stream"), (key + MANIFEST_SUFFIX, sidecar, "application/json")):
                    existed = store.exists(target)
                    store.put(target, payload, content_type=content_type)            # el manifiesto DESPUÉS del artefacto, como al guardar
                    if not existed:
                        created.setdefault(version, []).append(target)                # registrado tras CADA escritura correcta: un fallo a la mitad no deja huérfanos
            for model, version, key in plan:
                done = _register_candidate(conn, tenant, core, env, int(published_by_user_id), bundle_id, manifest, model, version, key,
                                           operator=operator, publisher_role=publisher_role)
                if done.action == "already_published":
                    created.pop(version, None)                                       # esas claves son de una fila ya confirmada (de otro proceso): no se tocan
                out.append(done)
    except ObjectConflict as exc:
        _cleanup(store, [k for ks in created.values() for k in ks])
        raise CliError(EXIT_CONFLICT, f"El almacén ya tiene otro contenido bajo una clave de este paquete: {exc}") from exc
    except ObjectStoreError as exc:
        _cleanup(store, [k for ks in created.values() for k in ks])
        raise CliError(EXIT_CONFIG, f"El almacén de objetos falló: {exc}") from exc
    except Exception:
        _cleanup(store, [k for ks in created.values() for k in ks])
        raise
    return PublishReport(tenant, core, env, bundle_id, False, out)


def _date(value: Any) -> date | None:
    return date.fromisoformat(str(value)[:10]) if value else None


def _when(value: Any) -> datetime | None:
    return datetime.fromisoformat(str(value).replace("Z", "+00:00")) if value else None


def _cleanup(store: ObjectStore, keys: list[str]) -> None:
    """Una publicación que falla deja el almacén como estaba: solo se borra lo que ESTA llamada creó."""
    for key in keys:
        try:
            store.delete(key)
        except Exception:  # noqa: BLE001
            log.warning("No se pudo limpiar un objeto de una publicación fallida")


def _existing_candidate(conn: Any, tenant: int, version: str) -> Any:
    return conn.execute(text("SELECT id, artifact_sha256, published_object_key, published_by, published_env, status FROM model_registry "
                             "WHERE tenant_id = :t AND version = :v"), {"t": tenant, "v": version}).mappings().first()


def _same_publication(existing: Any, model: dict[str, Any], key: str, user_id: int, env: str, version: str) -> bool:
    """La fila existente es exactamente esta publicación (mismo contenido, misma clave, mismo usuario y mismo entorno) o se rechaza con exit 6."""
    if (existing["artifact_sha256"] or "").strip() != model["sha256"] or existing["published_object_key"] != key:
        raise CliError(EXIT_CONFLICT, f"La versión {version} ya existe en el registro con otro contenido: no se sobrescribe.")
    if existing["published_by"] != user_id or existing["published_env"] != env:
        raise CliError(EXIT_CONFLICT, f"La versión {version} ya fue publicada por otro usuario o en otro entorno ({existing['published_env']}): "
                                      "no se republica a nombre de otra persona.")
    return True


def _register_candidate(conn: Any, tenant: int, core: str, env: str, user_id: int, bundle_id: str, manifest: dict[str, Any], model: dict[str, Any],
                        version: str, key: str, *, operator: dict[str, str] | None = None, publisher_role: str | None = None) -> PublishedModel:
    """Inserta la fila `candidate` y su evento `cli` en la misma transacción. Idempotente por (empresa, versión): la misma publicación (mismo contenido, clave,
    usuario y entorno) responde `already_published`; cualquier diferencia es un conflicto (exit 6)."""
    existing = _existing_candidate(conn, tenant, version)
    if existing is not None:
        _same_publication(existing, model, key, user_id, env, version)
        return PublishedModel(version, key, int(existing["id"]), "already_published", model["sha256"], int(model["size_bytes"]))
    reg = model["registry"]
    compat = dict(reg.get("artifact_compat") or {})
    compat["signature"] = {"status": "signed", "algorithm": signing.ALGORITHM, "key_id": model["key_id"], "manifest_sha256": model["manifest_sha256"]}
    compat["data_gate"] = {k: v for k, v in (manifest.get("data_gate") or {}).items() if k in ("outcome", "module", "rules_version", "as_of", "reason_codes")}
    compat["published"] = {"bundle_id": bundle_id, "env": env, "engine_version": manifest.get("engine_version"), "source": (manifest.get("source") or {}).get("kind")}
    compat["evaluation_summary"] = manifest.get("metrics")
    now = _now()
    try:
        with conn.begin_nested():                 # punto de guardado: una carrera perdida por (empresa, versión) no deshace el resto de la transacción
            new_id = int(conn.execute(text(
                """
                INSERT INTO model_registry (tenant_id, version, model_family, strategy_level, scope, demand_class, status, hyperparameters, feature_set_version,
                    training_data_from, training_data_to, training_data_hash, series_count, artifact_path, artifact_sha256, library_versions, random_seed,
                    trained_at, notes, created_at, updated_at, core, role, artifact_compat, purpose, granularity,
                    published_by, published_object_key, published_env, published_at)
                VALUES (:t, :version, :family, :level, 'tenant', :dclass, :status, CAST(:hp AS jsonb), :fsv, :dfrom, :dto, :hash, :series, NULL, :sha,
                    CAST(:libs AS jsonb), :seed, :trained_at, :notes, :now, :now, :core, :role, CAST(:compat AS jsonb), :purpose, :granularity,
                    :user, :key, :env, :now)
                RETURNING id
                """), {
                "t": tenant, "version": version, "family": str(reg["model_family"])[:40], "level": str(reg["strategy_level"])[:1], "dclass": reg.get("demand_class"),
                "status": PUBLISH_STATUS, "hp": json.dumps(_sanitize(reg["hyperparameters"])), "fsv": reg.get("feature_set_version"),
                "dfrom": _date(reg.get("training_data_from")), "dto": _date(reg.get("training_data_to")), "hash": (reg.get("training_data_hash") or "")[:64] or None,
                "series": int(reg.get("series_count") or 0), "sha": model["sha256"], "libs": json.dumps(_sanitize(reg.get("library_versions") or {})),
                "seed": reg.get("random_seed"), "trained_at": _when(manifest.get("created_at")) or now, "notes": reg.get("notes"), "now": now, "core": core,
                "role": reg.get("role", "primary"), "compat": json.dumps(_sanitize(compat)), "purpose": (reg.get("purpose") or "")[:120] or None,
                "granularity": (reg.get("granularity") or "")[:40] or None, "user": user_id, "key": key, "env": env}).scalar_one())
    except IntegrityError:
        existing = _existing_candidate(conn, tenant, version)
        if existing is None:
            raise
        _same_publication(existing, model, key, user_id, env, version)          # otro proceso confirmó la MISMA publicación entre la consulta y el insert
        return PublishedModel(version, key, int(existing["id"]), "already_published", model["sha256"], int(model["size_bytes"]))
    conn.execute(text(
        "INSERT INTO model_registry_events (tenant_id, model_registry_id, from_status, to_status, actor_user_id, actor_kind, reason, evidence, created_at) "
        "VALUES (:t, :id, NULL, :status, :user, 'cli', :reason, CAST(:evidence AS jsonb), :now)"),
        {"t": tenant, "id": new_id, "status": PUBLISH_STATUS, "user": user_id, "now": now,
         "reason": "Publicado por la CLI de entrenamiento privado (candidato; la aprobación es separada).",
         "evidence": json.dumps(_sanitize({"trigger": "cli_publish", "env": env, "bundle_id": bundle_id, "sha256_prefix": model["sha256"][:12],
                                           "key_id": model["key_id"], "data_gate": compat["data_gate"].get("outcome"), "engine_version": manifest.get("engine_version"),
                                           "cli_operator": operator or operator_identity(), "declared_publisher_role": publisher_role,
                                           "publisher_is_declared": True}))})
    return PublishedModel(version, key, new_id, "published", model["sha256"], int(model["size_bytes"]))
