"""Exportación local AUTORIZADA, minimizada, cifrada en reposo y con limpieza (ADR-V25-19; V2.5 §9: «los datasets locales deben ser exportados con
autorización y minimización, cifrado temporal y limpieza»).

Qué garantiza este módulo (y qué NO):

- AUTORIZACIÓN: no se exporta nada sin `--authorized-export <referencia>`, `--authorized-by` y `--purpose`. La referencia y quién autorizó quedan en el
  manifiesto de la exportación. El motor NO puede validar la autorización contra un sistema externo: es un registro obligatorio, y el control real es
  organizacional (la referencia debe corresponder a una autorización escrita del propietario de los datos).
- MINIMIZACIÓN POR LISTA BLANCA: solo salen las columnas que el modelado usa (`ALLOWED_COLUMNS`); toda columna no listada se descarta y se reporta en el manifiesto
  (`dropped_columns`). Nombres, códigos y SKU de sucursales, productos, categorías y proveedores se sustituyen por seudónimos (`B12`, `P340`…);
  los compradores se seudonimizan con HMAC-SHA256 con una clave aleatoria POR EXPORTACIÓN que se DESCARTA al terminar (irreversible para quien recibe la exportación:
  no hay mapa de re-identificación; los artefactos no contienen identificadores de compradores). No salen nombres, correos, documentos de identidad ni texto libre.
- CIFRADO EN REPOSO: el contenido se cifra SIEMPRE con AES-256-GCM por fragmentos (nonce con contador y marca de último fragmento; el encabezado y el identificador de
  la exportación van como datos autenticados: un fragmento cambiado, reordenado o truncado, o un archivo de otra exportación, no descifra). La clave es un archivo local
  con permisos 0600 (`--key-file`); nunca se imprime. El motor nunca escribe el conjunto de datos en claro: se descifra en memoria al entrenar (`train --from-export`).
- VENCIMIENTO Y LIMPIEZA: cada exportación vence (`--ttl-hours`, por defecto 72, máx. 720); una vencida no se carga (`train --from-export` la rechaza). `cleanup-exports`
  borra las vencidas (o todas con `--all`), sobrescribiendo con ceros antes de borrar (mejor esfuerzo: en SSD o sistemas de archivos con copia al escribir no hay garantía
  física; el cifrado es la defensa real). Solo toca carpetas con la marca `.predictily-export` y manifiesto válido, hijas directas de la raíz indicada, nunca enlaces.
- Fidelidad: lo entrenado desde una exportación NO tiene el historial de versiones con tiempo de conocimiento (ADR-V25-23): `asof_fidelity = approximate_no_versions`.
"""

from __future__ import annotations

import hashlib
import hmac
import io
import json
import logging
import os
import re
import secrets
import shutil
import stat
import zipfile
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any

import pandas as pd

from predictily_engine.mlops.errors import EXIT_CONFIG, EXIT_NOT_AUTHORIZED, EXIT_NOT_FOUND, EXIT_USAGE, CliError

log = logging.getLogger(__name__)

EXPORT_SCHEMA = "predictily-authorized-export/1"
MANIFEST_FILE = "export.manifest.json"
DATA_FILE = "data.enc"
MARKER_FILE = ".predictily-export"
MAGIC = b"PXENC1\n"
CHUNK_SIZE = 4 * 1024 * 1024
TAG = 16
DEFAULT_TTL_HOURS = 72
MAX_TTL_HOURS = 720
_REF = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/#-]{3,79}$")
_EXPORT_ID = re.compile(r"^[0-9a-f]{16}$")

# Lista blanca de columnas por tabla (fail-closed): lo que no está aquí NO sale.
ALLOWED_COLUMNS: dict[str, tuple[str, ...]] = {
    # demanda
    "sales": ("branch_id", "product_id", "ds", "units", "net_amount", "gross_amount", "discount_amount", "cost_amount", "cost_units", "lines"),
    "returns": ("branch_id", "product_id", "ds", "units"),
    "inventory": ("branch_id", "product_id", "ds", "on_hand", "reserved", "in_transit", "unit_cost"),
    "latest_inventory": ("branch_id", "product_id", "ds", "on_hand", "reserved", "in_transit", "unit_cost"),
    "branches": ("branch_id", "province", "canton", "region", "is_active", "code", "name"),
    "products": ("product_id", "category_id", "unit", "current_price", "current_cost", "is_active", "deleted", "sku", "name"),
    "categories": ("category_id", "name"),
    "promotions": ("promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id", "known_at", "created_at", "deleted_at"),
    "promotion_revisions": ("promotion_id", "revision", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id", "deleted",
                            "deleted_at", "recorded_at", "known_at", "created_at"),
    "lead_times": ("supplier_id", "product_id", "branch_id", "lead_time_days", "lead_time_std_days", "valid_from"),
    "suppliers": ("supplier_id", "default_lead_time_days", "code"),
    "open_purchase_orders": ("purchase_order_id", "branch_id", "product_id", "supplier_id", "qty_open", "ordered_at", "expected_at"),
    # (columnas de `extensions.data_core.CALENDAR_COLUMNS` sin `id`; `name` y `source` son texto libre: se sustituyen, ver DERIVED)
    "exceptional_events": ("tenant_id", "scope", "scope_ref", "code", "name", "category", "starts_on", "ends_on", "status", "known_at", "source", "revision"),
    "coverage_gaps": ("data_source_id", "branch_id", "gap_from", "gap_to", "reason", "basis"),
    "closures": ("branch_id", "starts_on", "ends_on", "code"),
    # customer
    "purchases": ("customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount", "gross_amount", "discount_amount"),
    "customer_returns": ("customer_id", "product_id", "ds", "qty"),
    "availability": ("branch_id", "product_id", "ds", "available"),
    "coverage": ("entity", "branch_id", "complete_from", "complete_through", "rule", "basis"),
}
PSEUDONYMIZED = {  # tabla → {columna: prefijo}: se sustituye por <prefijo><id>
    "branches": {"code": "B", "name": "Sucursal "}, "products": {"sku": "P", "name": "Producto "}, "categories": {"name": "Categoría "},
    "suppliers": {"code": "S"},
}
DERIVED = {  # tabla → {columna: ("copy", otra columna) | ("const", valor)}: texto libre que se sustituye por un valor no identificable
    "exceptional_events": {"name": ("copy", "code"), "source": ("const", "export")},
}
ID_OF = {"branches": "branch_id", "products": "product_id", "categories": "category_id", "suppliers": "supplier_id"}
SETTINGS_ALLOWED = ("default_horizon_days", "service_level", "review_period_days", "default_lead_time_days", "stockout_risk_threshold", "cores")
DEMAND_FRAMES = ("sales", "returns", "inventory", "latest_inventory", "branches", "products", "categories", "promotions", "promotion_revisions",
                 "lead_times", "suppliers", "open_purchase_orders", "exceptional_events", "coverage_gaps", "closures")
CUSTOMER_FRAMES = ("purchases", "customer_returns", "products", "branches", "availability", "coverage", "promotions", "promotion_revisions")


# ───────────────────────────── clave y cifrado ─────────────────────────────

def generate_key_file(path: Path) -> None:
    """Clave AES-256 nueva (32 bytes en base64) en un archivo 0600 desde su creación; no sobrescribe."""
    import base64

    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        os.write(fd, (base64.b64encode(secrets.token_bytes(32)).decode("ascii") + "\n").encode("ascii"))
    finally:
        os.close(fd)
    os.chmod(path, 0o600)


def load_key_file(path: Path) -> bytes:
    """Lee la clave de un archivo que SOLO el propietario puede leer. @raises CliError (3)."""
    import base64
    import binascii

    try:
        info = os.stat(path)
    except OSError as exc:
        raise CliError(EXIT_CONFIG, "No se pudo leer el archivo de clave de la exportación.") from exc
    if info.st_mode & (stat.S_IRWXG | stat.S_IRWXO):
        raise CliError(EXIT_CONFIG, "El archivo de clave de la exportación debe tener permisos 0600 (solo el propietario).")
    try:
        key = base64.b64decode(path.read_text(encoding="ascii").strip(), validate=True)
    except (OSError, UnicodeDecodeError, binascii.Error, ValueError) as exc:
        raise CliError(EXIT_CONFIG, "El archivo de clave no contiene una clave AES-256 en base64.") from exc
    if len(key) != 32:
        raise CliError(EXIT_CONFIG, "La clave de la exportación debe ser de 32 bytes (AES-256).")
    return key


def key_fingerprint(key: bytes) -> str:
    return hashlib.sha256(b"predictily-export-key:" + key).hexdigest()[:16]


def _nonce(prefix: bytes, counter: int, last: bool) -> bytes:
    return prefix + counter.to_bytes(4, "big") + (b"\x01" if last else b"\x00")


def bound_data(manifest: dict[str, Any]) -> bytes:
    """Lo que el cifrado autentica del manifiesto: TODO salvo el bloque `encryption` (que describe el cifrado y depende de él). Editar la empresa, el core, la
    autorización, el vencimiento o las tablas del manifiesto invalida el descifrado."""
    return json.dumps({k: v for k, v in manifest.items() if k != "encryption"}, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii")


def encrypt_payload(plaintext: bytes, key: bytes, export_id: str, bound: bytes = b"") -> bytes:
    """Encabezado + fragmentos AES-256-GCM. El encabezado, `export_id` y `bound` (el manifiesto, ver `bound_data`) se autentican en cada fragmento."""
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    prefix = secrets.token_bytes(7)
    header = MAGIC + prefix + CHUNK_SIZE.to_bytes(4, "big")
    aad = header + export_id.encode("ascii") + b"|" + bound
    cipher = AESGCM(key)
    pieces = [header]
    total = max(1, -(-len(plaintext) // CHUNK_SIZE))
    for i in range(total):
        chunk = plaintext[i * CHUNK_SIZE:(i + 1) * CHUNK_SIZE]
        pieces.append(cipher.encrypt(_nonce(prefix, i, i == total - 1), chunk, aad))
    return b"".join(pieces)


def decrypt_payload(blob: bytes, key: bytes, export_id: str, bound: bytes = b"") -> bytes:
    """Descifra en memoria. @raises ValueError si algo no autentica (clave equivocada, alterado, truncado, reordenado o de otra exportación)."""
    from cryptography.exceptions import InvalidTag
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    header_len = len(MAGIC) + 7 + 4
    if len(blob) < header_len + TAG or not blob.startswith(MAGIC):
        raise ValueError("formato de exportación no reconocido")
    header = blob[:header_len]
    prefix, size = header[len(MAGIC):len(MAGIC) + 7], int.from_bytes(header[-4:], "big")
    if size <= 0 or size > 64 * 1024 * 1024:
        raise ValueError("tamaño de fragmento no válido")
    aad = header + export_id.encode("ascii") + b"|" + bound
    body, step = blob[header_len:], size + TAG
    cipher = AESGCM(key)
    out = []
    count = max(1, -(-len(body) // step))
    try:
        for i in range(count):
            out.append(cipher.decrypt(_nonce(prefix, i, i == count - 1), body[i * step:(i + 1) * step], aad))
    except InvalidTag as exc:
        raise ValueError("la exportación no autentica con esa clave (clave equivocada, contenido alterado o truncado)") from exc
    return b"".join(out)


# ───────────────────────────── minimización ─────────────────────────────

def _id_key(value: Any) -> str:
    """Forma textual estable de un identificador (101 y 101.0 son el mismo comprador)."""
    try:
        number = float(value)
        return str(int(number)) if number.is_integer() else str(value)
    except (TypeError, ValueError):
        return str(value)


def customer_id_mapping(secret: bytes, *frames: pd.DataFrame | None) -> dict[str, int]:
    """UN mapa comprador → seudónimo para TODA la exportación: la unión de los identificadores de todas las tablas que traen `customer_id` (compras y
    devoluciones), con rango denso del HMAC-SHA256 del identificador (irreversible: la clave se descarta). Un mapa por tabla rompería la relación entre
    una devolución y su compra."""
    ids: set[str] = set()
    for frame in frames:
        if frame is not None and "customer_id" in frame:
            ids |= {_id_key(v) for v in frame["customer_id"].dropna().unique()}
    ranked = sorted(ids, key=lambda v: hmac.new(secret, v.encode("utf-8"), hashlib.sha256).digest())
    return {v: i + 1 for i, v in enumerate(ranked)}


def minimize_frame(name: str, df: pd.DataFrame | None, dropped: dict[str, list[str]], *, customer_map: dict[str, int] | None = None) -> pd.DataFrame:
    """Aplica la lista blanca de columnas y los seudónimos. Las columnas descartadas se anotan en `dropped`. `customer_map`: el mapa ÚNICO de la exportación
    (`customer_id_mapping`), el mismo para todas las tablas con `customer_id`."""
    if df is None or not isinstance(df, pd.DataFrame):
        return pd.DataFrame(columns=list(ALLOWED_COLUMNS[name]))
    allowed = ALLOWED_COLUMNS[name]
    extra = [c for c in df.columns if c not in allowed]
    if extra:
        dropped[name] = sorted(extra)
    out = df[[c for c in df.columns if c in allowed]].copy()
    ident = ID_OF.get(name)
    for column, prefix in PSEUDONYMIZED.get(name, {}).items():
        if column in out and ident in out:
            out[column] = [f"{prefix}{int(i)}" if pd.notna(i) else None for i in out[ident]]
        elif column in out:
            out = out.drop(columns=[column])
    for column, (kind, value) in DERIVED.get(name, {}).items():
        if column in out:
            out[column] = out[value] if kind == "copy" and value in out else value
    if "customer_id" in out and customer_map is not None:
        out["customer_id"] = out["customer_id"].map(lambda v: customer_map[_id_key(v)] if pd.notna(v) else v)
    return out


def _settings(settings: dict[str, Any] | None) -> dict[str, Any]:
    return {k: v for k, v in (settings or {}).items() if k in SETTINGS_ALLOWED}


def _jsonable(value: Any) -> Any:
    from predictily_engine.mlops.bundle import _sanitize

    return _sanitize(value)


# ───────────────────────────── exportar ─────────────────────────────

def _check_text(value: str, what: str, low: int, high: int) -> str:
    value = (value or "").strip()
    if not (low <= len(value) <= high) or any(ord(c) < 32 for c in value):
        raise CliError(EXIT_USAGE, f"{what} debe tener entre {low} y {high} caracteres.")
    return value


def validate_authorization(ref: str | None, authorized_by: str | None, purpose: str | None) -> tuple[str, str, str]:
    """Sin referencia de autorización explícita no hay exportación (exit 4)."""
    if not ref or not ref.strip():
        raise CliError(EXIT_NOT_AUTHORIZED, "Una exportación local exige --authorized-export <referencia de la autorización escrita>.")
    ref = ref.strip()
    if not _REF.match(ref):
        raise CliError(EXIT_NOT_AUTHORIZED, "La referencia de autorización no es válida (4 a 80 caracteres: letras, números y . _ : / # -).")
    if not authorized_by or not authorized_by.strip():
        raise CliError(EXIT_NOT_AUTHORIZED, "Indique quién autoriza la exportación (--authorized-by).")
    return ref, _check_text(authorized_by, "--authorized-by", 3, 120), _check_text(purpose or "", "--purpose", 5, 200)


@dataclass
class ExportResult:
    export_dir: Path
    export_id: str
    manifest: dict[str, Any]


def _parquet(df: pd.DataFrame) -> bytes:
    buffer = io.BytesIO()
    try:
        df.to_parquet(buffer, index=False)
    except ImportError as exc:
        raise CliError(EXIT_CONFIG, "pyarrow no está instalado: pip install 'predictily-engine[mlops]' (necesario para la exportación local).") from exc
    return buffer.getvalue()


def validate_ttl(ttl_hours: int) -> int:
    if not (1 <= int(ttl_hours) <= MAX_TTL_HOURS):
        raise CliError(EXIT_USAGE, f"--ttl-hours debe estar entre 1 y {MAX_TTL_HOURS}.")
    return int(ttl_hours)


def create_export(inputs: Any, core: str, *, out_root: Path, key: bytes, authorization_ref: str, authorized_by: str, purpose: str,
                  ttl_hours: int = DEFAULT_TTL_HOURS, now: datetime | None = None) -> ExportResult:
    """Exporta las entradas de entrenamiento de UNA empresa (`DemandInputs` | `CustomerInputs`) minimizadas y cifradas."""
    ref, by, why = validate_authorization(authorization_ref, authorized_by, purpose)
    validate_ttl(ttl_hours)
    now = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    export_id = secrets.token_hex(8)
    secret = secrets.token_bytes(32)             # solo vive en este proceso: se descarta al terminar (seudónimos irreversibles)
    dropped: dict[str, list[str]] = {}
    frames: dict[str, pd.DataFrame] = {}
    meta: dict[str, Any]
    data = inputs.data
    if core == "demand":
        for name in DEMAND_FRAMES:
            source = {"customer_returns": "returns"}.get(name, name)
            frames[name] = minimize_frame(name, getattr(data, source, None), dropped)
        meta = {"tenant_id": inputs.tenant_id, "cutoff": data.cutoff.isoformat(), "currency": data.currency, "sector": data.sector,
                "settings": _settings(data.settings), "sales_stats": _jsonable(data.sales_stats), "coverage": _jsonable(data.coverage),
                "coverage_limitations": _jsonable(data.coverage_limitations), "exceptional_stats": _jsonable(data.exceptional_stats),
                "source_branches": _jsonable({str(k): v for k, v in (data.source_branches or {}).items()}),
                "promotions_known_on": data.promotions_known_on.isoformat() if data.promotions_known_on else None,
                "config_record": _jsonable(inputs.config_record), "promotion_record": _jsonable(inputs.promotion_record)}
    else:
        customer_map = customer_id_mapping(secret, data.purchases, data.returns)         # UN mapa para compras y devoluciones
        for name in CUSTOMER_FRAMES:
            source = {"customer_returns": "returns"}.get(name, name)
            frames[name] = minimize_frame(name, getattr(data, source, None), dropped, customer_map=customer_map)
        tenant = data.tenant or {}
        meta = {"tenant_id": inputs.tenant_id, "through": data.through.isoformat() if data.through else None, "live": bool(data.live),
                "sales_first": data.sales_first.isoformat() if data.sales_first else None, "sales_last": data.sales_last.isoformat() if data.sales_last else None,
                "excluded": _jsonable(data.excluded),
                "tenant": {"id": tenant.get("id"), "sector": tenant.get("sector"), "currency": tenant.get("currency"), "timezone": tenant.get("timezone"),
                           "settings": _settings(tenant.get("settings"))}}
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, frame in frames.items():
            archive.writestr(f"{name}.parquet", _parquet(frame))
        archive.writestr("meta.json", json.dumps(meta, sort_keys=True, ensure_ascii=False))
    manifest = {
        "schema": EXPORT_SCHEMA, "export_id": export_id, "tenant_id": int(inputs.tenant_id), "core": core, "created_at": _iso(now),
        "expires_at": _iso(now + timedelta(hours=int(ttl_hours))), "authorization": {"reference": ref, "authorized_by": by, "purpose": why},
        "source": inputs.source, "asof_fidelity": "approximate_no_versions",
        "minimization": {"method": "column_allowlist", "dropped_columns": dropped, "identifier_pseudonyms": sorted(
            f"{t}.{c}" for t, cols in PSEUDONYMIZED.items() for c in cols) + (["customer_id en todas las tablas (un solo mapa; HMAC-SHA256 aleatorio, clave descartada)"]
                                                                                if core == "customer" else []),
            "settings_keys_kept": list(SETTINGS_ALLOWED)},
        "tables": {name: {"rows": int(len(frame)), "columns": list(frame.columns)} for name, frame in frames.items()},
        "cleanup": "python -m predictily_engine.cli cleanup-exports --root <carpeta> (borra las vencidas); la exportación vence en expires_at.",
    }
    encrypted = encrypt_payload(buffer.getvalue(), key, export_id, bound_data(manifest))        # el manifiesto (sin `encryption`) queda autenticado
    manifest["encryption"] = {"algorithm": "AES-256-GCM", "chunk_size": CHUNK_SIZE, "key_fingerprint": key_fingerprint(key), "file": DATA_FILE,
                              "sha256": hashlib.sha256(encrypted).hexdigest(), "size_bytes": len(encrypted), "authenticated_manifest": True}
    out_root = Path(out_root)
    target = out_root / f"export-{export_id}"
    out_root.mkdir(parents=True, exist_ok=True)
    os.makedirs(target, mode=0o700)
    try:
        _write_private(target / DATA_FILE, encrypted)
        _write_private(target / MANIFEST_FILE, (json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n").encode("utf-8"))
        _write_private(target / MARKER_FILE, b"predictily export\n")
    except BaseException:
        shutil.rmtree(target, ignore_errors=True)
        raise
    return ExportResult(target, export_id, manifest)


def _write_private(path: Path, data: bytes) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        os.write(fd, data)
        os.fsync(fd)
    finally:
        os.close(fd)


def _iso(moment: datetime) -> str:
    return moment.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _parse(moment: str) -> datetime:
    return datetime.fromisoformat(moment.replace("Z", "+00:00"))


# ───────────────────────────── cargar (descifra en memoria) ─────────────────────────────

def read_manifest(export_dir: Path) -> dict[str, Any]:
    path = Path(export_dir) / MANIFEST_FILE
    try:
        manifest = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise CliError(EXIT_NOT_FOUND, "No existe la exportación indicada (falta su manifiesto).") from exc
    except (OSError, ValueError) as exc:
        raise CliError(EXIT_USAGE, "El manifiesto de la exportación no es válido.") from exc
    if (not isinstance(manifest, dict) or manifest.get("schema") != EXPORT_SCHEMA or not _EXPORT_ID.match(str(manifest.get("export_id", "")))
            or not isinstance(manifest.get("tenant_id"), int) or manifest.get("core") not in ("demand", "customer")):
        raise CliError(EXIT_USAGE, "El manifiesto de la exportación no es válido.")
    return manifest


def _frame(archive: zipfile.ZipFile, name: str) -> pd.DataFrame:
    return pd.read_parquet(io.BytesIO(archive.read(f"{name}.parquet")))


def load_export(export_dir: Path, key: bytes, *, expect_tenant_id: int | None = None, expect_core: str | None = None,
                now: datetime | None = None) -> Any:
    """Descifra EN MEMORIA y reconstruye `DemandInputs` | `CustomerInputs`. Rechaza exportaciones vencidas, de otra empresa o de otro core."""
    from predictily_engine.mlops.training import CustomerInputs, DemandInputs

    export_dir = Path(export_dir)
    manifest = read_manifest(export_dir)
    now = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    if now >= _parse(manifest["expires_at"]):
        raise CliError(EXIT_NOT_AUTHORIZED, "La exportación venció: solicite una nueva autorización y vuelva a exportar (cleanup-exports la borra).")
    if expect_tenant_id is not None and manifest["tenant_id"] != int(expect_tenant_id):
        raise CliError(EXIT_NOT_AUTHORIZED, "La exportación es de otra empresa: no se carga.")
    core = manifest["core"]
    if expect_core is not None and core != expect_core:
        raise CliError(EXIT_USAGE, f"La exportación es del core «{core}», no de «{expect_core}».")
    enc = manifest["encryption"]
    if key_fingerprint(key) != enc.get("key_fingerprint"):
        raise CliError(EXIT_NOT_AUTHORIZED, "La clave no corresponde a esta exportación.")
    try:
        blob = (export_dir / DATA_FILE).read_bytes()
    except OSError as exc:
        raise CliError(EXIT_NOT_FOUND, "Falta el contenido cifrado de la exportación.") from exc
    if hashlib.sha256(blob).hexdigest() != enc.get("sha256"):
        raise CliError(EXIT_NOT_AUTHORIZED, "El contenido cifrado no coincide con su huella: la exportación fue alterada.")
    try:
        payload = decrypt_payload(blob, key, manifest["export_id"], bound_data(manifest))
    except ValueError as exc:
        raise CliError(EXIT_NOT_AUTHORIZED, str(exc) + " (el manifiesto también está autenticado: editar la empresa, el core o el vencimiento lo invalida)") from exc
    archive = zipfile.ZipFile(io.BytesIO(payload))
    meta = json.loads(archive.read("meta.json"))
    if int(meta["tenant_id"]) != int(manifest["tenant_id"]) or (expect_tenant_id is not None and int(meta["tenant_id"]) != int(expect_tenant_id)):
        raise CliError(EXIT_NOT_AUTHORIZED, "El contenido descifrado es de otra empresa que la indicada: no se carga.")
    source = {"kind": "export", "export_id": manifest["export_id"], "authorization_reference": manifest["authorization"]["reference"],
              "created_at": manifest["created_at"], "asof_fidelity": "approximate_no_versions"}
    tenant_id = int(meta["tenant_id"])
    if core == "demand":
        from predictily_engine.pipeline.data import DEFAULT_SETTINGS, TenantData, _normalize

        stats = dict(meta.get("sales_stats") or {})
        for k in ("date_from", "date_to"):
            if stats.get(k):
                stats[k] = date.fromisoformat(str(stats[k])[:10])
        frames = {name: _frame(archive, name) for name in DEMAND_FRAMES}
        data = TenantData(
            tenant_id=tenant_id, settings={**DEFAULT_SETTINGS, **(meta.get("settings") or {})}, cutoff=date.fromisoformat(meta["cutoff"]),
            branches=frames["branches"], products=frames["products"], categories=frames["categories"], sales=frames["sales"], returns=frames["returns"],
            inventory=frames["inventory"], latest_inventory=frames["latest_inventory"], promotions=frames["promotions"],
            lead_times=frames["lead_times"], suppliers=frames["suppliers"], open_purchase_orders=frames["open_purchase_orders"], sales_stats=stats,
            promotion_revisions=frames["promotion_revisions"],
            promotions_known_on=date.fromisoformat(meta["promotions_known_on"]) if meta.get("promotions_known_on") else None,
            currency=meta.get("currency"), sector=meta.get("sector"), exceptional_events=frames["exceptional_events"],
            exceptional_stats=meta.get("exceptional_stats") or {}, coverage=meta.get("coverage") or {},
            coverage_limitations=meta.get("coverage_limitations") or [], coverage_gaps=frames["coverage_gaps"], closures=frames["closures"],
            source_branches={int(k): v for k, v in (meta.get("source_branches") or {}).items()})
        return DemandInputs(tenant_id, _normalize(data), meta["config_record"], meta["promotion_record"], None, {}, "none", source)
    from predictily_engine.customer.data import CustomerData
    from predictily_engine.platform.config import resolve_config

    tenant = {**(meta.get("tenant") or {})}
    cfg, cfg_version, provenance = resolve_config("customer", tenant.get("settings") or {}, tenant.get("sector"))
    frames = {name: _frame(archive, name) for name in CUSTOMER_FRAMES}
    data = CustomerData(
        purchases=frames["purchases"], returns=frames["customer_returns"], products=frames["products"], branches=frames["branches"],
        availability=frames["availability"], coverage=frames["coverage"], promotions=frames["promotions"],
        promotion_revisions=frames["promotion_revisions"],
        sales_first=date.fromisoformat(meta["sales_first"]) if meta.get("sales_first") else None,
        sales_last=date.fromisoformat(meta["sales_last"]) if meta.get("sales_last") else None, excluded=meta.get("excluded") or {}, tenant=tenant,
        through=date.fromisoformat(meta["through"]) if meta.get("through") else None, purchase_versions=None, live=bool(meta.get("live")))
    return CustomerInputs(tenant_id, data, cfg, cfg_version, provenance, _export_gate(data, cfg, tenant), tenant, source)


def _export_gate(data: Any, cfg: dict[str, Any], tenant: dict[str, Any]) -> Any:
    """Gate del Customer Core sobre los datos de una exportación: las reglas que dependen de la base (informes de ingestión, alias) quedan «no evaluadas»."""
    from predictily_engine.quality.assessment import assess_customer
    from predictily_engine.quality.service import resolve_policy

    return assess_customer(data, resolve_policy(tenant), None)


# ───────────────────────────── limpieza ─────────────────────────────

@dataclass
class CleanupReport:
    removed: list[str]
    kept: list[str]
    skipped: list[str]

    def as_dict(self) -> dict[str, Any]:
        return {"removed": self.removed, "kept": self.kept, "skipped": self.skipped}


def _shred(path: Path) -> None:
    """Sobrescribe con ceros antes de borrar (mejor esfuerzo; ver el aviso del módulo)."""
    try:
        size = path.stat().st_size
        with path.open("r+b") as handle:
            remaining = size
            block = b"\x00" * min(1024 * 1024, max(size, 1))
            while remaining > 0:
                handle.write(block[:remaining])
                remaining -= len(block[:remaining])
            handle.flush()
            os.fsync(handle.fileno())
    except OSError:
        pass
    path.unlink(missing_ok=True)


def cleanup_exports(root: Path, *, now: datetime | None = None, remove_all: bool = False, dry_run: bool = False) -> CleanupReport:
    """Borra las exportaciones vencidas (o todas con `remove_all`) de `root`. Solo toca carpetas HIJAS DIRECTAS con la marca y un manifiesto válido."""
    root = Path(root)
    if not root.is_dir() or root.is_symlink():
        raise CliError(EXIT_NOT_FOUND, "La carpeta de exportaciones no existe.")
    if root.resolve() in (Path("/"), Path.home().resolve()):
        raise CliError(EXIT_USAGE, "Esa carpeta no puede ser la raíz de las exportaciones.")
    now = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    removed, kept, skipped = [], [], []
    for child in sorted(root.iterdir()):
        if child.is_symlink() or not child.is_dir():
            continue
        if not (child / MARKER_FILE).is_file() or (child / MARKER_FILE).is_symlink():
            skipped.append(child.name)
            continue
        try:
            manifest = read_manifest(child)
            expired = now >= _parse(manifest["expires_at"])
        except (CliError, ValueError, KeyError):
            skipped.append(child.name)
            continue
        if not (expired or remove_all):
            kept.append(child.name)
            continue
        if not dry_run:
            for file in sorted(child.rglob("*")):
                if file.is_file() and not file.is_symlink():
                    _shred(file)
            shutil.rmtree(child, ignore_errors=True)
        removed.append(child.name)
    return CleanupReport(removed, kept, skipped)
