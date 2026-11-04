"""Exportación local autorizada (W4-B): autorización obligatoria, minimización por lista blanca con seudónimos, cifrado AES-256-GCM, vencimiento y limpieza."""

from __future__ import annotations

import io
import json
import os
import stat
import zipfile
from datetime import datetime, timedelta, timezone

import pandas as pd
import pytest

from predictily_engine.mlops import exports
from predictily_engine.mlops.errors import CliError

AUTH = {"authorization_ref": "AUT-2026-0042", "authorized_by": "Dueña de los datos", "purpose": "Entrenamiento privado de la demanda"}


@pytest.fixture(scope="module")
def demand_inputs(small_data):
    from predictily_engine.mlops.training import DemandInputs
    from predictily_engine.platform.config import effective_config_record

    return DemandInputs(1, small_data, effective_config_record("demand", small_data.settings, small_data.sector),
                        effective_config_record("promotion", small_data.settings, small_data.sector), None, {}, "none",
                        {"kind": "database", "cutoff": small_data.cutoff.isoformat(), "asof_fidelity": "exact_with_versions"})


@pytest.fixture()
def key(tmp_path):
    path = tmp_path / "export.key"
    exports.generate_key_file(path)
    return exports.load_key_file(path)


# ───────────────────────────── clave y cifrado ─────────────────────────────

def test_the_key_file_is_0600_and_loose_permissions_are_refused(tmp_path):
    path = tmp_path / "k"
    exports.generate_key_file(path)
    assert stat.S_IMODE(os.stat(path).st_mode) == 0o600 and len(exports.load_key_file(path)) == 32
    with pytest.raises(FileExistsError):
        exports.generate_key_file(path)                                           # no sobrescribe
    os.chmod(path, 0o644)
    with pytest.raises(CliError) as caught:
        exports.load_key_file(path)
    assert caught.value.code == 3 and "0600" in caught.value.message
    bad = tmp_path / "bad"
    bad.write_text("no es base64 !!!")
    os.chmod(bad, 0o600)
    with pytest.raises(CliError):
        exports.load_key_file(bad)


def test_encryption_roundtrip_across_chunk_boundaries(key, monkeypatch):
    monkeypatch.setattr(exports, "CHUNK_SIZE", 64)
    for size in (0, 1, 63, 64, 65, 128, 1000):
        data = os.urandom(size)
        assert exports.decrypt_payload(exports.encrypt_payload(data, key, "a" * 16), key, "a" * 16) == data


def test_decryption_fails_for_a_wrong_key_another_export_tampering_truncation_and_reordering(key, monkeypatch):
    monkeypatch.setattr(exports, "CHUNK_SIZE", 64)
    data = os.urandom(400)
    blob = exports.encrypt_payload(data, key, "a" * 16)
    other_key = os.urandom(32)
    with pytest.raises(ValueError):
        exports.decrypt_payload(blob, other_key, "a" * 16)                         # clave equivocada
    with pytest.raises(ValueError):
        exports.decrypt_payload(blob, key, "b" * 16)                               # contenido de OTRA exportación
    flipped = bytearray(blob)
    flipped[len(blob) // 2] ^= 1
    with pytest.raises(ValueError):
        exports.decrypt_payload(bytes(flipped), key, "a" * 16)                     # alterado
    header = len(exports.MAGIC) + 7 + 4
    step = 64 + exports.TAG
    with pytest.raises(ValueError):
        exports.decrypt_payload(blob[:header + 2 * step], key, "a" * 16)           # truncado en el límite de un fragmento
    chunks = [blob[header + i * step:header + (i + 1) * step] for i in range(len(blob[header:]) // step)]
    swapped = blob[:header] + chunks[1] + chunks[0] + b"".join(chunks[2:]) + blob[header + len(chunks) * step:]
    with pytest.raises(ValueError):
        exports.decrypt_payload(swapped, key, "a" * 16)                            # reordenado
    with pytest.raises(ValueError):
        exports.decrypt_payload(b"basura", key, "a" * 16)
    assert data not in blob                                                       # el texto en claro no aparece en el archivo


# ───────────────────────────── autorización ─────────────────────────────

@pytest.mark.parametrize("ref,by,purpose,code", [
    (None, "Alguien", "Entrenamiento", 4), ("", "Alguien", "Entrenamiento", 4), ("  ", "Alguien", "Entrenamiento", 4), ("ab", "Alguien", "Entrenamiento", 4),
    ("AUT 2026", "Alguien", "Entrenamiento", 4), ("AUT-1\n--x", "Alguien", "Entrenamiento", 4), ("AUT-2026-0042", None, "Entrenamiento", 4),
    ("AUT-2026-0042", "Alguien", None, 2), ("AUT-2026-0042", "Alguien", "ok", 2), ("AUT-2026-0042", "Alguien", "x" * 201, 2)])
def test_no_explicit_authorization_no_export(ref, by, purpose, code):
    with pytest.raises(CliError) as caught:
        exports.validate_authorization(ref, by, purpose)
    assert caught.value.code == code


# ───────────────────────────── minimización ─────────────────────────────

def test_only_allowlisted_columns_leave_and_names_are_pseudonymized():
    dropped: dict = {}
    products = pd.DataFrame({"product_id": [10, 11], "category_id": [1, 1], "unit": ["u", "u"], "sku": ["SKU-SECRETO-1", "SKU-SECRETO-2"],
                             "name": ["Martillo Marca X", "Clavos"], "supplier_contact": ["a@b.com", "c@d.com"], "current_price": [5.0, 1.0], "is_active": [True, True]})
    out = exports.minimize_frame("products", products, dropped)
    assert "supplier_contact" not in out and dropped == {"products": ["supplier_contact"]}
    assert list(out["sku"]) == ["P10", "P11"] and list(out["name"]) == ["Producto 10", "Producto 11"]
    branches = exports.minimize_frame("branches", pd.DataFrame({"branch_id": [3], "code": ["SUC-QUITO"], "name": ["Sucursal Norte, Av. X 123"], "province": ["Pichincha"],
                                                                  "canton": ["Quito"], "address": ["Av. X 123"], "phone": ["099"]}), dropped)
    assert list(branches["code"]) == ["B3"] and "address" not in branches and "phone" not in branches and branches["province"].iloc[0] == "Pichincha"


def test_customers_are_pseudonymized_stably_and_irreversibly_per_export():
    frame = pd.DataFrame({"customer_id": [101, 202, 101, 303], "product_id": [1, 1, 2, 2], "branch_id": [1, 1, 1, 1],
                          "ds": pd.to_datetime(["2026-01-01"] * 4), "currency": ["USD"] * 4, "qty": [1.0] * 4, "net_amount": [1.0] * 4,
                          "gross_amount": [1.0] * 4, "discount_amount": [0.0] * 4, "email": ["x@y.com"] * 4})
    mapping = exports.customer_id_mapping(b"A" * 32, frame)
    first = exports.minimize_frame("purchases", frame, {}, customer_map=mapping)
    again = exports.minimize_frame("purchases", frame, {}, customer_map=exports.customer_id_mapping(b"A" * 32, frame))
    assert "email" not in first and list(first["customer_id"]) == list(again["customer_id"])             # estable con la misma clave
    assert first["customer_id"].iloc[0] == first["customer_id"].iloc[2] and first["customer_id"].nunique() == 3     # misma persona = mismo seudónimo
    assert set(first["customer_id"]) == {1, 2, 3}                                                         # rango denso: no deriva del id real
    assert list(first["customer_id"]) != [101, 202, 101, 303]                                            # nunca el identificador real


def test_one_customer_mapping_serves_every_table_so_returns_still_match_their_purchases():
    """H1 de la revisión: un mapa POR TABLA rompía la relación devolución ↔ compra (40 % de coincidencias frente a 100 % en la base)."""
    purchases = pd.DataFrame({"customer_id": [10, 20, 30, 40], "product_id": [1, 1, 2, 3], "branch_id": 1, "ds": pd.to_datetime(["2026-01-01"] * 4), "currency": "USD",
                              "qty": 5.0, "net_amount": 1.0, "gross_amount": 1.0, "discount_amount": 0.0})
    # devoluciones de compradores con compra (10, 30), de un comprador SIN compras en el periodo (99) y de un par (20, 2) que nunca compró
    returns = pd.DataFrame({"customer_id": [10, 30, 99, 20], "product_id": [1, 2, 7, 2], "ds": pd.to_datetime(["2026-01-05"] * 4), "qty": [1.0, 2.0, 1.0, 1.0]})
    mapping = exports.customer_id_mapping(b"K" * 32, purchases, returns)
    assert len(mapping) == 5                                                                   # la unión: 10, 20, 30, 40 y 99
    out_p = exports.minimize_frame("purchases", purchases, {}, customer_map=mapping)
    out_r = exports.minimize_frame("customer_returns", returns, {}, customer_map=mapping)

    def matches(p, r):
        pairs = set(zip(p.customer_id, p.product_id))
        return [(c, pr) in pairs for c, pr in zip(r.customer_id, r.product_id)]

    assert matches(out_p, out_r) == matches(purchases, returns) == [True, True, False, False]      # coincide en la exportación exactamente cuando coincide en la base
    assert set(out_r["customer_id"]) <= set(mapping.values()) and out_r["customer_id"].iloc[2] not in set(out_p["customer_id"])
    # los enteros leídos como flotantes (NaN en la columna) y el entero equivalen al mismo comprador
    floaty = exports.minimize_frame("customer_returns", returns.assign(customer_id=returns["customer_id"].astype(float)), {}, customer_map=mapping)
    assert list(floaty["customer_id"]) == list(out_r["customer_id"])


# ───────────────────────────── exportar, cargar, vencer y limpiar ─────────────────────────────

def test_export_is_encrypted_minimized_authorized_and_private(demand_inputs, key, tmp_path):
    result = exports.create_export(demand_inputs, "demand", out_root=tmp_path / "ex", key=key, **AUTH, ttl_hours=24)
    d = result.export_dir
    assert d.name == f"export-{result.export_id}" and stat.S_IMODE(os.stat(d).st_mode) == 0o700
    assert {p.name for p in d.iterdir()} == {exports.DATA_FILE, exports.MANIFEST_FILE, exports.MARKER_FILE}
    assert all(stat.S_IMODE(os.stat(p).st_mode) == 0o600 for p in d.iterdir())
    manifest = json.loads((d / exports.MANIFEST_FILE).read_text())
    assert manifest["authorization"] == {"reference": "AUT-2026-0042", "authorized_by": "Dueña de los datos", "purpose": AUTH["purpose"]}
    assert manifest["encryption"]["algorithm"] == "AES-256-GCM" and manifest["asof_fidelity"] == "approximate_no_versions"
    assert manifest["minimization"]["method"] == "column_allowlist" and manifest["tables"]["sales"]["rows"] == len(demand_inputs.data.sales)
    assert "key" not in json.dumps(manifest["encryption"]).replace("key_fingerprint", "")               # el manifiesto no trae material de clave
    blob = (d / exports.DATA_FILE).read_bytes()
    assert b"PAR1" not in blob and b"PK\x03\x04" not in blob and not any(str(n).encode() in blob for n in demand_inputs.data.products["name"].head(3))
    # el contenido descifrado NO trae SKU ni nombres reales
    archive = zipfile.ZipFile(io.BytesIO(exports.decrypt_payload(blob, key, result.export_id, exports.bound_data(manifest))))
    products = pd.read_parquet(io.BytesIO(archive.read("products.parquet")))
    assert set(products["sku"]) <= {f"P{i}" for i in demand_inputs.data.products["product_id"]}
    real_names = set(demand_inputs.data.products["name"]) | set(demand_inputs.data.products["sku"]) | set(demand_inputs.data.branches["name"])
    assert not (real_names & set(products["name"]) | real_names & set(products["sku"]))
    assert not any(n.encode() in archive.read(name) for name in archive.namelist() for n in list(real_names)[:5])


def test_an_export_loads_back_into_equivalent_training_data(demand_inputs, key, tmp_path):
    from predictily_engine.pipeline.core import training_data_hash
    from predictily_engine.profiling.days import DayRules
    from predictily_engine.profiling.series import build_panel

    result = exports.create_export(demand_inputs, "demand", out_root=tmp_path / "ex", key=key, **AUTH)
    loaded = exports.load_export(result.export_dir, key, expect_tenant_id=1, expect_core="demand")
    assert loaded.source["kind"] == "export" and loaded.source["asof_fidelity"] == "approximate_no_versions"
    assert loaded.data.sales_versions is None and loaded.data.cutoff == demand_inputs.data.cutoff
    rules = DayRules.from_config(demand_inputs.config_record["effective_config"])
    original, restored = build_panel(demand_inputs.data, rules), build_panel(loaded.data, rules)
    assert training_data_hash(original) == training_data_hash(restored)                    # mismos datos de entrenamiento: misma huella
    assert len(original.profile) == len(restored.profile)


def test_loading_refuses_expired_foreign_wrong_key_and_altered_exports(demand_inputs, key, tmp_path):
    result = exports.create_export(demand_inputs, "demand", out_root=tmp_path / "ex", key=key, **AUTH, ttl_hours=1)
    later = datetime.now(timezone.utc) + timedelta(hours=2)
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key, now=later)
    assert caught.value.code == 4 and "venció" in caught.value.message
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key, expect_tenant_id=2)
    assert caught.value.code == 4 and "otra empresa" in caught.value.message
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key, expect_core="customer")
    assert caught.value.code == 2
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, os.urandom(32))
    assert caught.value.code == 4 and "clave" in caught.value.message
    data = result.export_dir / exports.DATA_FILE
    raw = bytearray(data.read_bytes())
    raw[len(raw) // 2] ^= 1
    os.chmod(data, 0o600)
    data.write_bytes(bytes(raw))
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key)
    assert caught.value.code == 4 and "alterada" in caught.value.message
    with pytest.raises(CliError) as caught:
        exports.load_export(tmp_path / "no-existe", key)
    assert caught.value.code == 5


def test_cleanup_removes_expired_exports_keeps_valid_ones_and_never_touches_foreign_folders(demand_inputs, key, tmp_path):
    root = tmp_path / "ex"
    now = datetime.now(timezone.utc)
    expired = exports.create_export(demand_inputs, "demand", out_root=root, key=key, **AUTH, ttl_hours=1, now=now - timedelta(hours=5))
    valid = exports.create_export(demand_inputs, "demand", out_root=root, key=key, **AUTH, ttl_hours=48, now=now)
    foreign = root / "trabajo-del-usuario"                                          # una carpeta que NO es una exportación
    foreign.mkdir()
    (foreign / "importante.txt").write_text("no tocar")
    fake = root / "export-falso"                                                    # con el nombre pero sin la marca
    fake.mkdir()
    (fake / "x").write_text("no tocar")
    outside = tmp_path / "fuera"
    outside.mkdir()
    (outside / "dato.txt").write_text("fuera")
    (root / "enlace").symlink_to(outside)
    shredded = []
    original = exports._shred
    exports._shred = lambda p: (shredded.append(p.name), original(p))[1]
    try:
        dry = exports.cleanup_exports(root, dry_run=True)
        assert dry.removed == [expired.export_dir.name] and expired.export_dir.exists()          # --dry-run no borra
        report = exports.cleanup_exports(root)
    finally:
        exports._shred = original
    assert report.removed == [expired.export_dir.name] and report.kept == [valid.export_dir.name]
    assert sorted(report.skipped) == ["export-falso", "trabajo-del-usuario"]
    assert not expired.export_dir.exists() and valid.export_dir.exists()
    assert (foreign / "importante.txt").read_text() == "no tocar" and (fake / "x").exists() and (outside / "dato.txt").read_text() == "fuera"
    assert {exports.DATA_FILE, exports.MANIFEST_FILE} <= set(shredded)                         # se sobrescribió antes de borrar
    everything = exports.cleanup_exports(root, remove_all=True)
    assert everything.removed == [valid.export_dir.name] and not valid.export_dir.exists()
    assert (foreign / "importante.txt").exists() and (outside / "dato.txt").exists()


def test_cleanup_refuses_dangerous_roots(tmp_path):
    with pytest.raises(CliError):
        exports.cleanup_exports(tmp_path / "no-existe")
    with pytest.raises(CliError):
        exports.cleanup_exports(__import__("pathlib").Path("/"))
    link = tmp_path / "enlace"
    link.symlink_to(tmp_path)
    with pytest.raises(CliError):
        exports.cleanup_exports(link)


def test_export_ttl_is_bounded(demand_inputs, key, tmp_path):
    for ttl in (0, exports.MAX_TTL_HOURS + 1):
        with pytest.raises(CliError):
            exports.create_export(demand_inputs, "demand", out_root=tmp_path / "ex", key=key, **AUTH, ttl_hours=ttl)
    assert not (tmp_path / "ex").exists()


def test_the_allowlists_cover_every_column_the_real_loaders_return(small_data):
    """Guarda estática: una columna que el pipeline necesita y la lista blanca omite rompería `train --from-export` solo con ciertos datos (p. ej. eventos del calendario)."""
    from predictily_engine.customer.data import PURCHASE_COLUMNS
    from predictily_engine.extensions.data_core import CALENDAR_COLUMNS

    assert set(CALENDAR_COLUMNS) - {"id"} <= set(exports.ALLOWED_COLUMNS["exceptional_events"])
    assert set(PURCHASE_COLUMNS) <= set(exports.ALLOWED_COLUMNS["purchases"])
    dropped: dict = {}
    for name in exports.DEMAND_FRAMES:
        source = {"customer_returns": "returns"}.get(name, name)
        exports.minimize_frame(name, getattr(small_data, source, None), dropped)
    assert dropped == {}, dropped                                                       # nada que el modelado use queda fuera


def test_free_text_columns_of_calendar_events_are_replaced():
    events = pd.DataFrame({"id": [1], "tenant_id": [None], "scope": ["country"], "scope_ref": [None], "code": ["FERIADO"], "name": ["Texto libre del cliente"],
                           "category": ["exceptional"], "starts_on": pd.to_datetime(["2026-01-01"]), "ends_on": pd.to_datetime(["2026-01-01"]), "status": ["confirmed"],
                           "known_at": pd.to_datetime(["2025-01-01"]), "source": ["nota interna"], "revision": [0]})
    dropped: dict = {}
    out = exports.minimize_frame("exceptional_events", events, dropped)
    assert dropped == {"exceptional_events": ["id"]} and out["name"].iloc[0] == "FERIADO" and out["source"].iloc[0] == "export"


def test_the_manifest_is_authenticated_so_editing_tenant_core_or_expiry_breaks_decryption(demand_inputs, key, tmp_path):
    """L2 de la revisión: `tenant_id`, `core`, `expires_at` y el resto del manifiesto van en los datos autenticados del cifrado."""
    from datetime import datetime, timedelta, timezone

    result = exports.create_export(demand_inputs, "demand", out_root=tmp_path / "ex", key=key, **AUTH, ttl_hours=1)
    manifest_path = result.export_dir / exports.MANIFEST_FILE
    original = manifest_path.read_text()
    assert exports.load_export(result.export_dir, key, expect_tenant_id=1)                       # intacto: carga
    for field, value in (("tenant_id", 2), ("core", "customer"), ("expires_at", "2999-01-01T00:00:00Z")):
        edited = json.loads(original)
        edited[field] = value
        manifest_path.write_text(json.dumps(edited))
        with pytest.raises(CliError) as caught:
            exports.load_export(result.export_dir, key)
        assert caught.value.code == 4, field                                                      # no autentica
    edited = json.loads(original)
    edited["authorization"]["reference"] = "AUT-FALSA-9999"                                      # la autorización registrada tampoco se puede cambiar
    manifest_path.write_text(json.dumps(edited))
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key)
    assert caught.value.code == 4
    manifest_path.write_text(original)
    # copiada y usada como otra empresa: se rechaza antes y después de descifrar
    with pytest.raises(CliError) as caught:
        exports.load_export(result.export_dir, key, expect_tenant_id=2)
    assert caught.value.code == 4 and "otra empresa" in caught.value.message
    # un TTL editado que además intenta alargar la vigencia no se obtiene
    assert datetime.now(timezone.utc) < exports._parse(json.loads(original)["expires_at"]) < datetime.now(timezone.utc) + timedelta(hours=2)


def test_the_ttl_is_validated_before_anything_is_read():
    for bad in (0, -5, exports.MAX_TTL_HOURS + 1):
        with pytest.raises(CliError) as caught:
            exports.validate_ttl(bad)
        assert caught.value.code == 2
    assert exports.validate_ttl(72) == 72
