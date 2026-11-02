"""CLI de entrenamiento privado y serving solo de lo aprobado (W4-B), con PostgreSQL migrado: contrato de publicación, filtro de serving y aislamiento entre empresas.

Mundos sintéticos FICTICIOS. La empresa A (grande: modelos complejos) y la B (pequeña) se entrenan primero con el SERVICIO (modelos legados del pipeline propio) y
después con la CLI (candidatos publicados): así se comparan ambos caminos y se prueba que lo publicado jamás se sirve sin aprobación.
"""

from __future__ import annotations

import contextlib
import io
import json
import uuid
from pathlib import Path

import pytest
from sqlalchemy import text

from customer_fixtures import create_core_run, load_world
from predictily_engine import cli
from predictily_engine.customer.service import CustomerExecuteRequest, _load_serving_models, execute_customer_run
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.mlops import bundle, policy, serving
from predictily_engine.mlops.serving import ARTIFACT_CACHE
from predictily_engine.platform.object_store import LocalObjectStore, is_tenant_models_key, manifest_key

pytestmark = pytest.mark.integration


def invoke(*argv: str) -> tuple[int, str, str]:
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        try:
            code = cli.main([str(a) for a in argv])
        except SystemExit as exc:      # argparse
            code = int(exc.code or 0)
    return code, out.getvalue(), err.getvalue()


def ok_json(*argv: str) -> dict:
    code, out, err = invoke(*argv)
    assert code == 0, (argv, err)
    return json.loads(out)


def q(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


def one(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).scalar()


def make_user(conn, tenant_id: int, role: str = "analyst") -> int:
    uid = conn.execute(text("INSERT INTO users (name, email, password, created_at, updated_at) VALUES (:n, :e, 'x', now(), now()) RETURNING id"),
                       {"n": f"Usuario {role}", "e": f"{uuid.uuid4().hex[:10]}@ficticio.test"}).scalar_one()
    conn.execute(text("INSERT INTO tenant_users (tenant_id, user_id, role, created_at, updated_at) VALUES (:t, :u, :r, now(), now())"), {"t": tenant_id, "u": uid, "r": role})
    return int(uid)


def service_run(engine, tenant_id: int, operation: str, **kw) -> dict:
    with engine.begin() as conn:
        run_id, job_id = create_core_run(conn, tenant_id, operation, **kw)
    out = execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))
    out["_run_id"] = run_id
    return out


@pytest.fixture(scope="module")
def env(db_engine, tmp_path_factory):
    store_root = tmp_path_factory.mktemp("objetos")
    work = tmp_path_factory.mktemp("trabajo")
    mp = pytest.MonkeyPatch()
    mp.setenv("ENGINE_OBJECT_STORE_DRIVER", "local")
    mp.setenv("ENGINE_OBJECT_STORE_ROOT", str(store_root))
    ARTIFACT_CACHE.clear()
    world_a = generate_world(seed=7, n_customers=140, n_products=8, n_days=440, mean_interval_range=(20, 200), churn_share=0.1)
    world_b = generate_world(seed=9, n_customers=60, n_products=6, n_days=300, mean_interval_range=(20, 200))
    with db_engine.begin() as conn:
        a = load_world(conn, world_a, "Empresa A (ficticia, W4-B)")
        b = load_world(conn, world_b, "Empresa B (ficticia, W4-B)")
        users = {"pub_a": make_user(conn, a["tenant_id"]), "approver_a": make_user(conn, a["tenant_id"], "owner"), "pub_b": make_user(conn, b["tenant_id"])}
        uuids = {k: str(conn.execute(text("SELECT uuid::text FROM tenants WHERE id = :t"), {"t": v["tenant_id"]}).scalar_one()) for k, v in (("A", a), ("B", b))}
    all_horizons = [7, 14, 15, 30, 90]                      # los mismos que la CLI toma por defecto del catálogo del Customer Core
    service_run(db_engine, a["tenant_id"], "train", horizons=all_horizons)
    service_run(db_engine, b["tenant_id"], "train", horizons=all_horizons)
    published = {}
    for key, tenant, user in (("A", a["tenant_id"], users["pub_a"]), ("B", b["tenant_id"], users["pub_b"])):
        out = work / f"train-{key}"
        ok_json("train", "--tenant-id", tenant, "--core", "customer", "--out", out)
        ok_json("package", "--dir", out, "--sign")
        published[key] = ok_json("publish", "--dir", out, "--env", "staging", "--published-by-user-id", user, "--tenant-id", tenant)
    yield {"A": a["tenant_id"], "B": b["tenant_id"], "users": users, "uuids": uuids, "store": LocalObjectStore(store_root), "work": work, "published": published,
           "dirs": {"A": work / "train-A", "B": work / "train-B"}, "store_root": store_root}
    mp.undo()
    ARTIFACT_CACHE.clear()


# ───────────────────────────── contrato de publicación (MODEL_REGISTRY §5.6) ─────────────────────────────

def test_publish_meets_the_registry_publication_contract(db_engine, env):
    tenant, user, uuid_ = env["A"], env["users"]["pub_a"], env["uuids"]["A"]
    rows = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND published_object_key IS NOT NULL ORDER BY id", t=tenant)
    assert len(rows) == len(env["published"]["A"]["models"]) >= 8
    for r in rows:
        assert r["status"] == "candidate"                                                                   # NUNCA validated, approved ni production
        assert r["published_by"] == user and r["published_env"] == "staging" and r["published_at"] is not None
        assert r["published_object_key"].startswith(f"tenants/{uuid_}/models/{r['core']}/{r['version']}/") and is_tenant_models_key(r["published_object_key"], uuid_)
        assert r["approved_by"] is None and r["approved_at"] is None and r["artifact_path"] is None
        assert env["store"].exists(r["published_object_key"]) and env["store"].exists(manifest_key(r["published_object_key"]))     # objeto + manifiesto firmado al lado
        compat = r["artifact_compat"]
        assert compat["data_gate"]["outcome"] in ("PASS", "WARN", "DEGRADE") and compat["signature"]["status"] == "signed" and compat["signature"]["key_id"] == "test-ephemeral"
        assert compat["feature_schema_version"] == "cust-fs-2026.10-v1" and r["hyperparameters"]["slot"].startswith("h")
        assert r["forecast_run_id"] is None and r["core_run_id"] is None and r["training_job_id"] is None      # sin corrida: el gate de datos lo declara quien publica
        events = q(db_engine, "SELECT * FROM model_registry_events WHERE tenant_id = :t AND model_registry_id = :m ORDER BY id", t=tenant, m=r["id"])
        assert [(e["from_status"], e["to_status"], e["actor_kind"], e["actor_user_id"]) for e in events] == [(None, "candidate", "cli", user)]
        assert events[0]["evidence"]["env"] == "staging" and events[0]["evidence"]["trigger"] == "cli_publish"
    sha_of_object = {r["published_object_key"]: __import__("hashlib").sha256(env["store"].get(r["published_object_key"])).hexdigest() for r in rows}
    assert all(sha_of_object[r["published_object_key"]] == r["artifact_sha256"] for r in rows)


def test_the_status_written_by_publish_can_only_be_candidate():
    """El valor está fijado en el código del contrato (no es un parámetro): no hay camino a validated, approved ni production."""
    assert bundle.PUBLISH_STATUS == "candidate"
    source = Path(bundle.__file__).read_text()
    assert source.count('"status": PUBLISH_STATUS') == 2 and "UPDATE model_registry" not in source           # fila y evento llevan la constante; publish nunca actualiza estados
    for forbidden in ("'validated'", "'approved'", "'production'"):
        assert forbidden not in source.split("def _register_candidate")[1]


def test_published_models_are_never_servable_until_approved_by_the_backend(db_engine, env):
    rows = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND published_object_key IS NOT NULL", t=env["A"])
    with db_engine.connect() as conn:
        assert set(policy.basis_of_rows(conn, env["A"], [dict(r) for r in rows])) == {"not_approved"}
    listing = ok_json("list-models", "--tenant-id", env["A"], "--core", "customer", "--limit", 200)
    published = [m for m in listing["models"] if m["published"]]
    assert published and all(m["serving_basis"] == "not_approved" and m["servable"] is False and m["status"] == "candidate" for m in published)
    legacy = [m for m in listing["models"] if not m["published"]]
    assert legacy and all(m["serving_basis"] == "legacy_pipeline" for m in legacy)             # lo que entrenó el servicio sigue sirviéndose (demo/E2E)


def test_publish_is_idempotent_and_refuses_to_overwrite_with_other_content(db_engine, env):
    before = one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["A"])
    again = ok_json("publish", "--dir", env["dirs"]["A"], "--env", "staging", "--published-by-user-id", env["users"]["pub_a"])
    assert {m["action"] for m in again["models"]} == {"already_published"}
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["A"]) == before
    key = env["published"]["A"]["models"][0]["object_key"]
    version = env["published"]["A"]["models"][0]["version"]
    original = env["store"].get(key)
    with pytest.raises(Exception):
        env["store"].put(key, original + b"x")                                                    # el almacén es de escritura única
    assert env["store"].get(key) == original
    # un registro existente con otro contenido: conflicto (6) y nada cambia
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE model_registry SET artifact_sha256 = :s WHERE tenant_id = :t AND version = :v"), {"s": "0" * 64, "t": env["A"], "v": version})
    try:
        code, _, err = invoke("publish", "--dir", env["dirs"]["A"], "--env", "staging", "--published-by-user-id", env["users"]["pub_a"])
        assert code == 6 and "no se sobrescribe" in err
    finally:
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET artifact_sha256 = :s WHERE tenant_id = :t AND version = :v"), {"s": bundle.hashlib.sha256(original).hexdigest(), "t": env["A"], "v": version})


def test_publish_refuses_actors_environments_and_packages_it_must_not_trust(db_engine, env, tmp_path):
    d = env["dirs"]["A"]
    assert invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"])[0] == 4         # usuario de OTRA empresa
    assert invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", 987654)[0] == 4                      # usuario inexistente
    code, _, err = invoke("publish", "--dir", d, "--env", "production", "--published-by-user-id", env["users"]["pub_a"])
    assert code == 4 and "--confirm-production" in err
    assert invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", env["users"]["pub_a"], "--tenant-id", env["B"])[0] == 1   # paquete de otra empresa
    assert invoke("publish", "--dir", tmp_path / "vacío", "--env", "staging", "--published-by-user-id", env["users"]["pub_a"])[0] == 5
    # un paquete sin firma o alterado no se publica
    unsigned = env["work"] / "sin-paquete"
    ok_json("train", "--tenant-id", env["B"], "--core", "customer", "--out", unsigned)
    assert invoke("publish", "--dir", unsigned, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"])[0] == 5       # sin package.manifest.json
    # empresa inactiva
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE tenants SET status = 'suspended' WHERE id = :t"), {"t": env["B"]})
    try:
        assert invoke("publish", "--dir", env["dirs"]["B"], "--env", "staging", "--published-by-user-id", env["users"]["pub_b"], "--dry-run")[0] == 4
    finally:
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE tenants SET status = 'active' WHERE id = :t"), {"t": env["B"]})


def test_production_publish_with_confirmation_is_still_only_a_candidate(db_engine, env):
    out = env["work"] / "train-prod"
    ok_json("train", "--tenant-id", env["B"], "--core", "customer", "--out", out, "--seed", 11)
    ok_json("package", "--dir", out, "--sign")
    ok_json("publish", "--dir", out, "--env", "production", "--confirm-production", "--published-by-user-id", env["users"]["pub_b"])
    rows = q(db_engine, "SELECT status, published_env FROM model_registry WHERE tenant_id = :t AND published_env = 'production'", t=env["B"])
    assert rows and {r["status"] for r in rows} == {"candidate"}


def test_dry_run_writes_nothing(db_engine, env):
    out = env["work"] / "train-dry"
    ok_json("train", "--tenant-id", env["B"], "--core", "customer", "--out", out, "--seed", 12)
    ok_json("package", "--dir", out, "--sign")
    before_rows = one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["B"])
    before_objects = len(env["store"].list_keys(f"tenants/{env['uuids']['B']}/models"))
    report = ok_json("publish", "--dir", out, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"], "--dry-run")
    assert report["dry_run"] and {m["action"] for m in report["models"]} == {"dry_run"} and report["status_written"] == "candidate"
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["B"]) == before_rows
    assert len(env["store"].list_keys(f"tenants/{env['uuids']['B']}/models")) == before_objects


def test_a_failed_registration_removes_only_the_objects_that_call_created(db_engine, env, monkeypatch):
    out = env["work"] / "train-fail"
    ok_json("train", "--tenant-id", env["B"], "--core", "customer", "--out", out, "--seed", 13)
    ok_json("package", "--dir", out, "--sign")
    prefix = f"tenants/{env['uuids']['B']}/models"
    before_objects = set(env["store"].list_keys(prefix))
    before_rows = one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["B"])

    def boom(*args, **kwargs):
        raise RuntimeError("falla simulada del registro")

    monkeypatch.setattr(bundle, "_register_candidate", boom)
    code, _, err = invoke("publish", "--dir", out, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"])
    assert code == 7 and "RuntimeError" in err and "falla simulada" not in err                   # error interno, sin detalles
    assert set(env["store"].list_keys(prefix)) == before_objects                                     # el almacén quedó como estaba
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=env["B"]) == before_rows


def test_server_generated_keys_never_leave_the_companys_prefix(env):
    for tenant_key in ("A", "B"):
        keys = [m["object_key"] for m in env["published"][tenant_key]["models"]]
        assert keys and all(is_tenant_models_key(k, env["uuids"][tenant_key]) for k in keys)
        other = "B" if tenant_key == "A" else "A"
        assert not any(is_tenant_models_key(k, env["uuids"][other]) for k in keys)


# ───────────────────────────── serving: solo lo aprobado (filtro del espejo + carga del almacén) ─────────────────────────────

class RegistrySnapshot:
    """Guarda y restaura el estado de aprobación de las filas de una empresa (las pruebas de serving simulan lo que haría el backend)."""

    def __init__(self, engine, tenant_id):
        self.engine, self.tenant_id = engine, tenant_id

    def __enter__(self):
        self.rows = q(self.engine, "SELECT id, status, approved_by, approved_at, promoted_at, retired_at, artifact_path, artifact_sha256, published_object_key, hyperparameters "
                                   "FROM model_registry WHERE tenant_id = :t", t=self.tenant_id)
        self.max_event = one(self.engine, "SELECT COALESCE(MAX(id), 0) FROM model_registry_events WHERE tenant_id = :t", t=self.tenant_id)
        return self

    def __exit__(self, *exc):
        with self.engine.begin() as conn:
            conn.execute(text("DELETE FROM model_registry_events WHERE tenant_id = :t AND id > :m"), {"t": self.tenant_id, "m": self.max_event})
            for r in self.rows:
                conn.execute(text("UPDATE model_registry SET status = :s, approved_by = :ab, approved_at = :aa, promoted_at = :pa, retired_at = :ra, artifact_path = :ap, "
                                  "artifact_sha256 = :sh, published_object_key = :pk, hyperparameters = CAST(:hp AS jsonb) WHERE id = :i"),
                             {"s": r["status"], "ab": r["approved_by"], "aa": r["approved_at"], "pa": r["promoted_at"], "ra": r["retired_at"], "ap": r["artifact_path"],
                              "sh": r["artifact_sha256"], "pk": r["published_object_key"], "hp": json.dumps(r["hyperparameters"]), "i": r["id"]})
        ARTIFACT_CACHE.clear()


def _slot_rows(engine, tenant_id, slot, published: bool):
    return q(engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND hyperparameters->>'slot' = :s AND "
                     f"published_object_key IS {'NOT ' if published else ''}NULL ORDER BY id", t=tenant_id, s=slot)


def _promote_like_backend(engine, tenant_id, model_id, approver_id):
    """Lo que dejan en la fila la validación, la aprobación separada y la promoción del backend (W4-A): eventos de personas, `approved_*` y `production`."""
    with engine.begin() as conn:
        conn.execute(text("UPDATE model_registry SET status = 'production', approved_by = :a, approved_at = now(), promoted_at = now() WHERE id = :i AND tenant_id = :t"),
                     {"a": approver_id, "i": model_id, "t": tenant_id})
        for frm, to in (("candidate", "validated"), ("validated", "approved"), ("approved", "production")):
            conn.execute(text("INSERT INTO model_registry_events (tenant_id, model_registry_id, from_status, to_status, actor_user_id, actor_kind, evidence) "
                              "VALUES (:t, :m, :f, :to, :a, 'user', '{}'::jsonb)"), {"t": tenant_id, "m": model_id, "f": frm, "to": to, "a": approver_id})


def _served_published(engine, tenant_id, slot, env=None):
    """La fila publicada que sirve `slot` en el PRIMER paquete publicado de la empresa (las pruebas de publicación crean otros)."""
    first = {m["version"] for m in env["published"]["A" if tenant_id == env["A"] else "B"]["models"]} if env else None
    rows = [r for r in _slot_rows(engine, tenant_id, slot, True) if r["hyperparameters"].get("served") and (first is None or r["version"] in first)]
    assert len(rows) == 1, slot
    return rows[0]


def _put_in_production(engine, tenant_id, model_id, retire_ids):
    with engine.begin() as conn:
        for i in retire_ids:
            conn.execute(text("UPDATE model_registry SET status = 'retired' WHERE id = :i AND tenant_id = :t"), {"i": i, "t": tenant_id})
        conn.execute(text("UPDATE model_registry SET status = 'production' WHERE id = :i AND tenant_id = :t"), {"i": model_id, "t": tenant_id})


def test_a_published_model_forced_to_production_without_approval_is_not_loaded(db_engine, env):
    """Alguien deja `status = production` en una fila publicada sin aprobar (el backend no puede impedir una escritura directa): el motor NO la carga."""
    tenant = env["A"]
    with RegistrySnapshot(db_engine, tenant):
        legacy_ids, rogue_ids = [], []
        for target in ("probability", "quantity"):
            slot = f"h7:{target}"
            legacy_ids += [r["id"] for r in _slot_rows(db_engine, tenant, slot, False) if r["status"] == "production"]
            rogue_ids.append(_served_published(db_engine, tenant, slot, env)["id"])
        for lid in legacy_ids:
            with db_engine.begin() as conn:
                conn.execute(text("UPDATE model_registry SET status = 'retired' WHERE id = :i"), {"i": lid})
        for rid in rogue_ids:
            with db_engine.begin() as conn:
                conn.execute(text("UPDATE model_registry SET status = 'production' WHERE id = :i"), {"i": rid})
        selection = _load_serving_models(db_engine, tenant, [7])
        served_ids = {selection.model_versions["7"]["probability_id"], selection.model_versions["7"]["quantity_id"]}
        assert not (served_ids & set(rogue_ids))                                                      # jamás sirve lo publicado sin aprobar
        assert sum("no está aprobado (base «not_approved»): no se carga" in r for r in selection.rejected) == 2        # probabilidad y cantidad
        # el modelo no aprobado se trata como un artefacto que falló: se sirve la referencia validada del mismo horizonte (legado, rol fallback)
        assert all("no está aprobado; se sirve la referencia validada" in r for r in selection.rejected[2:]) and not selection.abstained
        assert set(_bases(db_engine, tenant, served_ids)) == {"legacy_pipeline"} and selection.model_versions["7"]["role"] == "fallback"
        # sin referencia a la que caer, el horizonte se abstiene con un motivo propio
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET status = 'retired', hyperparameters = jsonb_set(hyperparameters, '{kind}', '\"complex\"') "
                              "WHERE tenant_id = :t AND id = ANY(:ids)"), {"t": tenant, "ids": sorted(served_ids)})
        none_left = _load_serving_models(db_engine, tenant, [7])
        assert none_left.abstained[7]["reason_code"] == "model_not_approved" and "7" not in none_left.artifacts.get(7, {}) and not none_left.artifacts


def _bases(engine, tenant_id, ids):
    rows = [dict(r) for r in q(engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND id = ANY(:ids)", t=tenant_id, ids=sorted(ids))]
    with engine.connect() as conn:
        return policy.basis_of_rows(conn, tenant_id, rows)


def test_a_published_model_approved_by_someone_else_is_served_from_the_object_store(db_engine, env):
    tenant, approver = env["A"], env["users"]["approver_a"]
    with RegistrySnapshot(db_engine, tenant):
        picks = {}
        for target in ("probability", "quantity"):
            slot = f"h7:{target}"
            legacy_prod = [r["id"] for r in _slot_rows(db_engine, tenant, slot, False) if r["status"] == "production"]
            picks[target] = _served_published(db_engine, tenant, slot, env)
            _put_in_production(db_engine, tenant, picks[target]["id"], legacy_prod)
            _promote_like_backend(db_engine, tenant, picks[target]["id"], approver)
        assert set(_bases(db_engine, tenant, [p["id"] for p in picks.values()])) == {"approved"}
        ARTIFACT_CACHE.clear()
        selection = _load_serving_models(db_engine, tenant, [7])
        assert "7" in selection.model_versions and not selection.abstained
        assert selection.model_versions["7"]["probability_id"] == picks["probability"]["id"] and selection.model_versions["7"]["quantity_id"] == picks["quantity"]["id"]
        assert selection.model_versions["7"]["probability"] == picks["probability"]["version"]
        assert {"prob", "qty"} == set(selection.artifacts[7]) and selection.artifacts[7]["prob"]["kind"] in ("complex", "reference")
        assert {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {tenant} and len(ARTIFACT_CACHE) == 2          # cacheado bajo la empresa del trabajo


def test_an_approved_published_model_whose_object_was_altered_is_not_served(db_engine, env):
    tenant, approver = env["A"], env["users"]["approver_a"]
    with RegistrySnapshot(db_engine, tenant):
        row = _served_published(db_engine, tenant, "h14:probability", env)
        other = _served_published(db_engine, tenant, "h14:quantity", env)
        for r, slot in ((row, "h14:probability"), (other, "h14:quantity")):
            _put_in_production(db_engine, tenant, r["id"], [x["id"] for x in _slot_rows(db_engine, tenant, slot, False) if x["status"] == "production"])
            _promote_like_backend(db_engine, tenant, r["id"], approver)
        key = row["published_object_key"]
        original = env["store"].get(key)
        env["store"].put(key, original + b"x", overwrite=True)
        try:
            ARTIFACT_CACHE.clear()
            selection = _load_serving_models(db_engine, tenant, [14])
            assert any("artifact_sha256_mismatch" in r for r in selection.rejected)                          # alterado: no se carga; cae a la referencia o se abstiene
            assert selection.model_versions.get("14", {}).get("probability_id") != row["id"]
        finally:
            env["store"].put(key, original, overwrite=True)


# ───────────────────────────── aislamiento entre empresas: registro, caché y trabajos ─────────────────────────────

def test_registry_queries_return_only_the_jobs_own_company(db_engine, env):
    """Registro: aunque B tenga versiones con el mismo formato de nombre, el trabajo de A solo ve filas de A y el filtro rechaza filas ajenas."""
    from predictily_engine.db.customer_repository import CustomerRepository

    with db_engine.connect() as conn:
        for tenant in (env["A"], env["B"]):
            repo = CustomerRepository(conn, tenant)
            for frame in (repo.production_models(), repo.validated_references()):
                assert set(frame["tenant_id"]) <= {tenant}
        rows_b = [dict(r) for r in conn.execute(text("SELECT * FROM model_registry WHERE tenant_id = :t"), {"t": env["B"]}).mappings().all()]
        with pytest.raises(ValueError):
            serving.partition_servable(conn, env["A"], rows_b)                                           # filas de B para el trabajo de A: error, no mezcla
        ids_b = [r["id"] for r in rows_b]
        assert policy.fetch_event_flags(conn, env["A"], ids_b) == {}                                     # los eventos también se filtran por empresa
        assert policy.fetch_event_flags(conn, env["B"], ids_b)


def test_a_job_of_company_a_never_loads_artifacts_or_caches_of_company_b(db_engine, env):
    a, b = env["A"], env["B"]
    ARTIFACT_CACHE.clear()
    sel_b = _load_serving_models(db_engine, b, [7, 14, 30])
    b_ids = {int(v[k]) for v in sel_b.model_versions.values() for k in ("probability_id", "quantity_id")}
    assert b_ids and {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {b}
    hits_before = ARTIFACT_CACHE.hits
    sel_a = _load_serving_models(db_engine, a, [7, 14, 30])
    a_ids = {int(v[k]) for v in sel_a.model_versions.values() for k in ("probability_id", "quantity_id")}
    assert a_ids and not (a_ids & b_ids)
    assert ARTIFACT_CACHE.hits == hits_before                                                           # A no recibió ninguna entrada que B cacheó
    assert {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {a, b}
    owners = {int(r["tenant_id"]) for r in q(db_engine, "SELECT tenant_id FROM model_registry WHERE id = ANY(:ids)", ids=sorted(a_ids))}
    assert owners == {a}
    assert ARTIFACT_CACHE.invalidate_tenant(b) > 0 and {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {a}


def test_a_registry_row_of_a_pointing_at_the_artifacts_of_b_is_refused(db_engine, env):
    a, b = env["A"], env["B"]
    with RegistrySnapshot(db_engine, a):
        prod = _slot_rows(db_engine, a, "h7:probability", False)
        target = [r for r in prod if r["status"] == "production"][0]
        victim = [r for r in _slot_rows(db_engine, b, "h7:probability", False) if r["status"] == "production"][0]
        published_b = _served_published(db_engine, b, "h7:probability", env)
        with db_engine.begin() as conn:                                                                 # la ruta/clave de B, el SHA-256 de B, dentro de la fila de A
            conn.execute(text("UPDATE model_registry SET artifact_path = :p, artifact_sha256 = :s WHERE id = :i"),
                         {"p": victim["artifact_path"], "s": victim["artifact_sha256"], "i": target["id"]})
        row = dict(q(db_engine, "SELECT m.*, t.uuid::text AS tenant_uuid FROM model_registry m JOIN tenants t ON t.id = m.tenant_id WHERE m.id = :i", i=target["id"])[0])
        from predictily_engine.customer.service import load_artifact

        assert load_artifact(row, a) == (None, "artifact_path_invalid")                                  # fuera del directorio de la empresa A
        poisoned = {**row, "artifact_path": None, "published_object_key": published_b["published_object_key"], "artifact_sha256": published_b["artifact_sha256"]}
        assert load_artifact(poisoned, a) == (None, "artifact_object_key_invalid")                       # clave de B: ni siquiera se consulta el almacén
        # el trabajo de A (con la fila envenenada en producción) sirve su respaldo o se abstiene; nunca el artefacto de B
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET artifact_path = :p WHERE id = :i"), {"p": victim["artifact_path"], "i": target["id"]})
        selection = _load_serving_models(db_engine, a, [7])
        assert selection.model_versions.get("7", {}).get("probability_id") != target["id"]
        assert any("artifact_path_invalid" in r for r in selection.rejected)


def test_an_approved_published_model_of_b_cannot_be_served_to_a_even_if_a_row_claims_it(db_engine, env):
    a, b = env["A"], env["B"]
    approver_b = env["users"]["pub_b"]
    with RegistrySnapshot(db_engine, a), RegistrySnapshot(db_engine, b):
        row_b = _served_published(db_engine, b, "h7:probability", env)
        other_b = _served_published(db_engine, b, "h7:quantity", env)
        for r, slot in ((row_b, "h7:probability"), (other_b, "h7:quantity")):
            _put_in_production(db_engine, b, r["id"], [x["id"] for x in _slot_rows(db_engine, b, slot, False) if x["status"] == "production"])
            _promote_like_backend(db_engine, b, r["id"], approver_b)
        ARTIFACT_CACHE.clear()
        sel_b = _load_serving_models(db_engine, b, [7])
        assert sel_b.model_versions["7"]["probability_id"] == row_b["id"]                                # B sí sirve su aprobado publicado
        # una fila de A que reclama el objeto aprobado de B (misma clave, mismo SHA-256)
        target = [r for r in _slot_rows(db_engine, a, "h7:probability", False) if r["status"] == "production"][0]
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET published_object_key = :k, artifact_sha256 = :s, artifact_path = NULL WHERE id = :i"),
                         {"k": row_b["published_object_key"], "s": row_b["artifact_sha256"], "i": target["id"]})
        sel_a = _load_serving_models(db_engine, a, [7])
        assert sel_a.model_versions.get("7", {}).get("probability_id") != row_b["id"]
        assert not any(k.tenant_id == a and k.model_version == row_b["version"] for k in ARTIFACT_CACHE.keys())


# ───────────────────────────── paridad con el servicio, comparación y reversión ─────────────────────────────

def test_cli_training_matches_the_service_for_the_customer_core(db_engine, env):
    """Paridad: la CLI (sin base) entrena los MISMOS modelos que el servicio (mismos datos y semilla): misma huella, mismos horizontes, familias, roles y holdout."""
    a = env["A"]
    draft = json.loads((env["dirs"]["A"] / "train.json").read_text())
    service_rows = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND published_object_key IS NULL AND status <> 'failed'", t=a)
    assert {r["training_data_hash"].strip() for r in service_rows} == {draft["data_fingerprint"]}              # misma huella de datos
    service = {(r["hyperparameters"]["slot"], r["hyperparameters"]["family"]): r for r in service_rows}
    cli_models = {(m["registry"]["hyperparameters"]["slot"], m["registry"]["hyperparameters"]["family"]): m["registry"] for m in draft["models"]}
    assert set(cli_models) == set(service)                                                                       # mismos horizontes, objetivos y familias
    for key, registry in cli_models.items():
        row = service[key]
        assert registry["role"] == row["role"] and registry["hyperparameters"]["kind"] == row["hyperparameters"]["kind"]
        assert registry["hyperparameters"]["served"] == row["hyperparameters"]["served"] and registry["hyperparameters"]["tier"] == row["hyperparameters"]["tier"]
        assert registry["hyperparameters"]["gate"] == row["hyperparameters"]["gate"]
        assert registry["hyperparameters"]["holdout"] == row["hyperparameters"]["holdout"]                      # mismas cifras del holdout
        assert registry["artifact_compat"]["feature_names_sha256"] == row["artifact_compat"]["feature_names_sha256"]
    assert "trained" in draft["details"]["horizon_status"].values()                                             # (modelos complejos entrenados, no solo referencias)
    assert {s for s, _ in cli_models} == {f"h{h}:{t}" for h in (7, 14, 15, 30, 90) for t in ("probability", "quantity")}


def test_compare_shows_the_same_procedure_trained_twice_as_a_tie(db_engine, env):
    """`compare` de la versión del servicio contra la publicada por la CLI: mismos datos y semilla ⇒ mismas cifras (empate en toda métrica con veredicto)."""
    legacy = q(db_engine, "SELECT version FROM model_registry WHERE tenant_id = :t AND published_object_key IS NULL AND hyperparameters->>'slot' = 'h7:probability' "
                          "AND hyperparameters->>'served' = 'true'", t=env["A"])[0]["version"]
    published = _served_published(db_engine, env["A"], "h7:probability", env)["version"]
    report = ok_json("compare", "--tenant-id", env["A"], "--core", "customer", "--a", legacy, "--b", published)
    assert report["comparable"] and report["a"]["serving_basis"] == "legacy_pipeline" and report["b"]["serving_basis"] == "not_approved"
    verdicts = [m for m in report["metrics"] if m["better"] is not None]
    assert verdicts and all(m["better"] == "tie" and m["delta_b_minus_a"] == 0 for m in verdicts), verdicts[:3]
    assert report["wins"]["a"] == 0 and report["wins"]["b"] == 0 and "no es una recomendación" in report["note"]


def test_compare_two_published_versions_by_horizon_and_bundles_and_failure_modes(db_engine, env, tmp_path):
    a = env["A"]
    first = _served_published(db_engine, a, "h14:probability", env)["version"]
    other = _served_published(db_engine, a, "h30:probability", env)["version"]
    report = ok_json("compare", "--tenant-id", a, "--core", "customer", "--a", first, "--b", other)
    assert report["comparable"] is False or report["metrics"] == [] or all("horizons.14" not in m["metric"] or "horizons.30" not in m["metric"] for m in report["metrics"])
    # un paquete local contra su versión publicada
    side = ok_json("compare", "--tenant-id", a, "--core", "customer", "--a", f"bundle:{env['dirs']['A']}", "--b", _served_published(db_engine, a, "h7:probability", env)["version"])
    assert side["comparable"] and side["a"]["verified"] is True and side["wins"]["a"] == 0 and side["wins"]["b"] == 0
    two_bundles = ok_json("compare", "--tenant-id", a, "--core", "customer", "--a", f"bundle:{env['dirs']['A']}", "--b", f"bundle:{env['dirs']['A']}")
    assert two_bundles["comparable"] and two_bundles["wins"] == {"a": 0, "b": 0, "tie": two_bundles["wins"]["tie"]}
    assert invoke("compare", "--tenant-id", a, "--core", "customer", "--a", "no-existe", "--b", first)[0] == 5
    assert invoke("compare", "--tenant-id", env["B"], "--core", "customer", "--a", first, "--b", first)[0] == 5          # una versión de A no existe para B
    assert invoke("compare", "--tenant-id", a, "--core", "demand", "--a", first, "--b", first)[0] == 5                  # otro core


def test_rollback_only_prints_the_backend_instruction_and_changes_nothing(db_engine, env):
    a = env["A"]

    def state():
        return (q(db_engine, "SELECT id, status, approved_at, promoted_at, retired_at, updated_at FROM model_registry WHERE tenant_id = :t ORDER BY id", t=a),
                one(db_engine, "SELECT count(*) FROM model_registry_events WHERE tenant_id = :t", t=a), one(db_engine, "SELECT count(*) FROM audit_logs WHERE tenant_id = :t", t=a))

    before = state()
    retired = q(db_engine, "SELECT id, version FROM model_registry WHERE tenant_id = :t AND published_object_key IS NULL AND status IN ('retired', 'validated') "
                           "AND core = 'customer' LIMIT 1", t=a)[0]
    rep = ok_json("rollback", "--tenant-id", a, "--core", "customer", "--to-version", retired["version"])
    assert rep["servable"] is True and rep["this_cli_changed_nothing"] is True
    assert rep["instruction"] == {"method": "POST", "path": "/api/v1/models/customer/rollback", "body": {"model_registry_id": retired["id"]}, "ability": "forecast.run",
                                  "note": rep["instruction"]["note"]}
    # un publicado sin aprobar: NO servible → salida 1, sin instrucción
    published = _served_published(db_engine, a, "h7:probability", env)
    code, out, _ = invoke("rollback", "--tenant-id", a, "--core", "customer", "--to-version", published["version"])
    body = json.loads(out)
    assert code == 1 and body["servable"] is False and body["instruction"] is None and body["target"]["serving_basis"] == "not_approved"
    assert "/validate" in body["path"] and "distinta" in body["path"]
    # uno en producción: nada que revertir
    current = q(db_engine, "SELECT version FROM model_registry WHERE tenant_id = :t AND status = 'production' AND core = 'customer' LIMIT 1", t=a)[0]["version"]
    rep = ok_json("rollback", "--tenant-id", a, "--core", "customer", "--to-version", current)
    assert rep["instruction"] is None and "ya está en producción" in rep["reason"]
    # uno aprobado que nunca sirvió → se promueve
    with RegistrySnapshot(db_engine, a):
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET status = 'approved', approved_by = :u, approved_at = now() WHERE id = :i"), {"u": env["users"]["approver_a"], "i": published["id"]})
        rep = ok_json("rollback", "--tenant-id", a, "--core", "customer", "--to-version", published["version"])
        assert rep["instruction"]["path"] == f"/api/v1/models/{published['id']}/promote" and rep["instruction"]["ability"] == "models.approve"
    # rechazado: no servible
    with RegistrySnapshot(db_engine, a):
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET status = 'rejected' WHERE id = :i"), {"i": published["id"]})
        assert invoke("rollback", "--tenant-id", a, "--core", "customer", "--to-version", published["version"])[0] == 1
    assert invoke("rollback", "--tenant-id", a, "--core", "customer", "--to-version", "no-existe")[0] == 5
    assert invoke("rollback", "--tenant-id", env["B"], "--core", "customer", "--to-version", published["version"])[0] == 5      # la versión de A no existe para B
    assert state() == before                                                                                  # nada cambió: ni estados, ni eventos, ni auditoría


def test_demand_rollback_is_by_run_and_never_writes(db_engine, env):
    with db_engine.begin() as conn:
        run_id = conn.execute(text("INSERT INTO forecast_runs (tenant_id, version, run_type, status, triggered_by, horizon_days, options, created_at, updated_at) "
                                   "VALUES (:t, 900, 'production', 'completed', 'user', 90, '{}'::jsonb, now(), now()) RETURNING id"), {"t": env["A"]}).scalar_one()
        model_id = conn.execute(text("INSERT INTO model_registry (tenant_id, version, model_family, strategy_level, scope, status, core, role, forecast_run_id, created_at, updated_at) "
                                     "VALUES (:t, 'dem-rb-1', 'auto_ets', 'A', 'tenant', 'retired', 'demand', 'primary', :r, now(), now()) RETURNING id"), {"t": env["A"], "r": run_id}).scalar_one()
    try:
        rep = ok_json("rollback", "--tenant-id", env["A"], "--core", "demand", "--to-version", "dem-rb-1")
        assert rep["instruction"]["path"] == "/api/v1/models/demand/rollback" and rep["instruction"]["body"] == {"run_id": run_id} and rep["target"]["serving_basis"] == "legacy_pipeline"
        assert one(db_engine, "SELECT status FROM model_registry WHERE id = :i", i=model_id) == "retired"
    finally:
        with db_engine.begin() as conn:
            conn.execute(text("DELETE FROM model_registry WHERE id = :i"), {"i": model_id})
            conn.execute(text("DELETE FROM forecast_runs WHERE id = :i"), {"i": run_id})


# ───────────────────────────── validate-data, build-features, backtest: solo lectura ─────────────────────────────

WRITE_TABLES = ("model_registry", "model_registry_events", "model_evaluations", "core_runs", "forecast_runs", "training_jobs", "data_snapshots",
                "data_quality_reports", "customer_predictions", "model_promotion_decisions", "audit_logs")


def _counts(engine, tenant_id):
    return {t: one(engine, f"SELECT count(*) FROM {t} WHERE tenant_id = :t", t=tenant_id) for t in WRITE_TABLES}


def test_validate_build_features_backtest_and_train_never_write_to_the_database(db_engine, env, tmp_path):
    a = env["A"]
    before = _counts(db_engine, a)
    vd = ok_json("validate-data", "--tenant-id", a, "--core", "customer")
    assert vd["outcome"] in ("PASS", "WARN", "DEGRADE") and vd["modules"]["customer"]["rules_version"] and vd["modules"]["customer"]["source"]["kind"] == "database"
    bf = ok_json("build-features", "--tenant-id", a, "--core", "customer", "--horizons", 7, 30, "--out", tmp_path / "bf.json")
    assert bf["feature_schema_version"] == "cust-fs-2026.10-v1" and bf["n_features"] > 10 and bf["dataset"]["n_cutoffs"] > 0 and (tmp_path / "bf.json").exists()
    bt = ok_json("backtest", "--tenant-id", a, "--core", "customer", "--horizons", 7, "--seed", 5)
    assert bt["horizon_status"]["7"] == "trained" and bt["metrics_summary"]["horizons"]["7"]["probability"]["brier"] is not None
    ok_json("train", "--tenant-id", a, "--core", "customer", "--horizons", 7, "--out", tmp_path / "t")
    assert _counts(db_engine, a) == before                                                                     # ni una fila escrita en la base
    assert not list((tmp_path / "t").glob("*.sql")) and (tmp_path / "t" / "train.json").exists()


def test_validate_data_exit_codes(db_engine, env, monkeypatch):
    assert invoke("validate-data", "--tenant-id", 999999, "--core", "customer")[0] == 5                      # empresa inexistente
    with db_engine.begin() as conn:
        empty = conn.execute(text("INSERT INTO tenants (uuid, name, sector, settings, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Sin datos', 'otro', '{}'::jsonb, 'active', now(), now()) RETURNING id")).scalar_one()
    code, _, err = invoke("validate-data", "--tenant-id", empty, "--core", "demand")
    assert code == 1 and "ventas" in err                                                                       # sin ventas: no hay qué validar → comprobación negativa
    code, out, _ = invoke("validate-data", "--tenant-id", empty, "--core", "customer")
    assert code in (0, 1) and json.loads(out)["modules"]["customer"]["outcome"] in ("PASS", "WARN", "DEGRADE", "BLOCK")
    if code == 1:
        assert json.loads(out)["outcome"] == "BLOCK"
    from predictily_engine.db import session

    original = session._engine
    try:
        session.reset_engine("postgresql+psycopg://u:CLAVE-SECRETA-DB@127.0.0.1:1/x")
        code, _, err = invoke("validate-data", "--tenant-id", 1, "--core", "customer")
        assert code == 3 and "CLAVE-SECRETA-DB" not in err and "ENGINE_DATABASE_URL" in err
    finally:
        session._engine = original


# ───────────────────────────── exportación autorizada → entrenamiento local → limpieza ─────────────────────────────

def test_authorized_export_trains_locally_without_plaintext_and_is_cleaned_up(db_engine, env, tmp_path):
    b = env["B"]
    key = tmp_path / "export.key"
    root = tmp_path / "exports"
    auth = ["--authorized-export", "AUT-2026-0042", "--authorized-by", "Dueña de los datos", "--purpose", "Entrenar la empresa B en local"]
    exported = ok_json("export-local", "--tenant-id", b, "--core", "customer", "--out", root, "--key-file", key, "--generate-key", *auth)
    assert exported["encrypted"] is True and exported["authorization"]["reference"] == "AUT-2026-0042" and exported["tables"]["purchases"] > 0
    assert exported["dropped_columns"] == {}                                                                     # la lista blanca cubre todo lo que el Customer Core carga
    export_dir = Path(exported["export_dir"])
    files = {p.name for p in export_dir.rglob("*") if p.is_file()}
    assert files == {"data.enc", "export.manifest.json", ".predictily-export"}                                  # nada en claro en el disco
    assert not list(export_dir.rglob("*.parquet")) and not list(export_dir.rglob("*.csv"))
    # el contenido minimizado no trae códigos externos de compradores ni nombres de productos de la base
    names = [r[0] for r in q(db_engine, "SELECT name FROM products WHERE tenant_id = :t", t=b) and [(x["name"],) for x in q(db_engine, "SELECT name FROM products WHERE tenant_id = :t", t=b)]]
    assert names and not any(n.encode() in (export_dir / "data.enc").read_bytes() for n in names)
    train = ok_json("train", "--tenant-id", b, "--core", "customer", "--from-export", export_dir, "--key-file", key, "--out", tmp_path / "from-export", "--seed", 3)
    assert train["source"]["kind"] == "export" and train["source"]["authorization_reference"] == "AUT-2026-0042" and train["source"]["asof_fidelity"] == "approximate_no_versions"
    assert train["models"] and train["signed"]
    # otra empresa, otro core o sin clave: se rechaza
    assert invoke("train", "--tenant-id", env["A"], "--core", "customer", "--from-export", export_dir, "--key-file", key, "--out", tmp_path / "x")[0] == 4
    assert invoke("train", "--tenant-id", b, "--core", "demand", "--from-export", export_dir, "--key-file", key, "--out", tmp_path / "y")[0] == 2
    assert invoke("train", "--tenant-id", b, "--core", "customer", "--from-export", export_dir, "--out", tmp_path / "z")[0] == 2
    # limpieza: --dry-run no borra; --all borra y deja la carpeta raíz vacía de exportaciones
    dry = ok_json("cleanup-exports", "--root", root, "--all", "--dry-run")
    assert dry["removed"] == [export_dir.name] and export_dir.exists()
    done = ok_json("cleanup-exports", "--root", root, "--all")
    assert done["removed"] == [export_dir.name] and not export_dir.exists()
    assert invoke("train", "--tenant-id", b, "--core", "customer", "--from-export", export_dir, "--key-file", key, "--out", tmp_path / "w")[0] == 5      # ya no existe


def _flat_numbers(value, prefix=""):
    if isinstance(value, dict):
        for k, v in value.items():
            yield from _flat_numbers(v, f"{prefix}.{k}")
    elif isinstance(value, (int, float)) and not isinstance(value, bool):
        yield prefix, float(value)


def test_customer_training_from_an_export_matches_training_from_the_database_up_to_relabelling(db_engine, env, tmp_path):
    """H1 de la revisión: las devoluciones conservan su relación con las compras y lo entrenado desde la exportación equivale a lo entrenado desde la base
    (mismos horizontes, familias, roles, compuertas, promoción y cifras del holdout; los compradores solo cambian de etiqueta)."""
    a = env["A"]
    key, root = tmp_path / "export.key", tmp_path / "exports"
    auth = ["--authorized-export", "AUT-2026-0077", "--authorized-by", "Dueña de los datos", "--purpose", "Paridad del Customer Core con la exportación"]
    exported = ok_json("export-local", "--tenant-id", a, "--core", "customer", "--out", root, "--key-file", key, "--generate-key", *auth)
    assert exported["dropped_columns"] == {}
    from predictily_engine.mlops import exports, training

    loaded = exports.load_export(Path(exported["export_dir"]), exports.load_key_file(key), expect_tenant_id=a, expect_core="customer")
    original = training.prepare_customer_from_db(db_engine, a)

    def matching(purchases, returns):
        pairs = set(zip(purchases.customer_id, purchases.product_id))
        return [(c, p) in pairs for c, p in zip(returns.customer_id, returns.product_id)]

    assert len(original.data.returns) > 0 and len(loaded.data.returns) == len(original.data.returns)
    assert sorted(matching(loaded.data.purchases, loaded.data.returns)) == sorted(matching(original.data.purchases, original.data.returns))    # misma relación devolución ↔ compra
    assert loaded.data.purchases["customer_id"].nunique() == original.data.purchases["customer_id"].nunique()
    assert len(set(loaded.data.returns["customer_id"]) - set(loaded.data.purchases["customer_id"])) == len(set(original.data.returns["customer_id"]) - set(original.data.purchases["customer_id"]))
    train = ok_json("train", "--tenant-id", a, "--core", "customer", "--from-export", exported["export_dir"], "--key-file", key, "--out", tmp_path / "from-export")
    assert train["source"]["kind"] == "export"
    db_draft = json.loads((env["dirs"]["A"] / "train.json").read_text())
    ex_draft = json.loads((tmp_path / "from-export" / "train.json").read_text())
    assert ex_draft["details"]["horizon_status"] == db_draft["details"]["horizon_status"]                      # mismos horizontes entrenados / en referencia / abstenidos
    by_db = {(m["registry"]["slot"], m["registry"]["hyperparameters"]["family"]): m["registry"] for m in db_draft["models"]}
    by_ex = {(m["registry"]["slot"], m["registry"]["hyperparameters"]["family"]): m["registry"] for m in ex_draft["models"]}
    assert set(by_db) == set(by_ex)                                                                            # mismos slots y familias (lo promovido)
    for slot_family, registry in by_db.items():
        other = by_ex[slot_family]
        assert (registry["role"], registry["hyperparameters"]["kind"], registry["hyperparameters"]["served"]) == (other["role"], other["hyperparameters"]["kind"], other["hyperparameters"]["served"])
        assert registry["hyperparameters"]["gate"] == other["hyperparameters"]["gate"]                         # compuertas de promoción
        left, right = dict(_flat_numbers(registry["hyperparameters"]["holdout"])), dict(_flat_numbers(other["hyperparameters"]["holdout"]))
        assert left.keys() == right.keys() and all(abs(left[k] - right[k]) <= 1e-9 for k in left), slot_family     # cifras del holdout
    assert db_draft["data_fingerprint"] != ex_draft["data_fingerprint"]                                        # los compradores cambiaron de etiqueta: la huella lo refleja


# ───────────────────────────── revisión adversarial: quién publica, huérfanos, republicación y carreras ─────────────────────────────

def test_publish_needs_a_role_with_forecast_run_and_records_who_ran_the_cli(db_engine, env):
    """M4: `--published-by-user-id` es una declaración; se exige un usuario de la empresa con rol que tenga forecast.run y el evento deja el operador de la CLI."""
    a, d = env["A"], env["dirs"]["A"]
    with db_engine.begin() as conn:
        viewer = make_user(conn, a, "viewer")
        gone = make_user(conn, a, "analyst")
        conn.execute(text("UPDATE users SET deleted_at = now() WHERE id = :u"), {"u": gone})
    code, _, err = invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", viewer, "--dry-run")
    assert code == 4 and "forecast.run" in err and "viewer" in err
    code, _, err = invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", gone, "--dry-run")
    assert code == 4 and "eliminado" in err
    events = q(db_engine, "SELECT actor_user_id, evidence FROM model_registry_events WHERE tenant_id = :t AND actor_kind = 'cli' ORDER BY id", t=a)
    assert events
    for event in events:
        operator = event["evidence"]["cli_operator"]
        assert operator["os_user"] and operator["hostname"] and event["evidence"]["declared_publisher_role"] in ("analyst", "owner", "admin")
        assert event["evidence"]["publisher_is_declared"] is True


def test_a_failed_upload_leaves_no_orphan_object_even_with_a_conflicting_sidecar(db_engine, env):
    """L1: el artefacto ya subido se borra si el manifiesto choca; el objeto ajeno preexistente no se toca."""
    b = env["B"]
    out = env["work"] / "train-orphan"
    ok_json("train", "--tenant-id", b, "--core", "customer", "--out", out, "--seed", 31)
    ok_json("package", "--dir", out, "--sign")
    first = ok_json("publish", "--dir", out, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"], "--dry-run")["models"][0]
    artifact_key, sidecar_key = first["object_key"], first["object_key"] + ".manifest.json"
    env["store"].put(sidecar_key, b"contenido ajeno preexistente")
    before_rows = one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=b)
    code, _, err = invoke("publish", "--dir", out, "--env", "staging", "--published-by-user-id", env["users"]["pub_b"])
    assert code == 6 and "otro contenido" in err
    assert not env["store"].exists(artifact_key)                                                         # lo creado por esta llamada se borró
    assert env["store"].get(sidecar_key) == b"contenido ajeno preexistente"                             # lo que ya estaba, no
    assert not [k for k in env["store"].list_keys(f"tenants/{env['uuids']['B']}/models") if first["version"] in k and k != sidecar_key]
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=b) == before_rows
    env["store"].delete(sidecar_key)


def test_republishing_with_another_user_or_environment_is_a_conflict(db_engine, env):
    """L5: la misma publicación (mismo contenido, usuario y entorno) es idempotente; a nombre de otra persona o en otro entorno, no."""
    a, d = env["A"], env["dirs"]["A"]
    assert {m["action"] for m in ok_json("publish", "--dir", d, "--env", "staging", "--published-by-user-id", env["users"]["pub_a"])["models"]} == {"already_published"}
    code, _, err = invoke("publish", "--dir", d, "--env", "staging", "--published-by-user-id", env["users"]["approver_a"])
    assert code == 6 and "otro usuario" in err
    code, _, err = invoke("publish", "--dir", d, "--env", "local", "--published-by-user-id", env["users"]["pub_a"])
    assert code == 6 and "otro entorno" in err
    rows = q(db_engine, "SELECT DISTINCT published_by, published_env FROM model_registry WHERE tenant_id = :t AND published_object_key IS NOT NULL "
                        "AND version IN (SELECT version FROM model_registry WHERE tenant_id = :t AND published_object_key IS NOT NULL ORDER BY id LIMIT 8)", t=a)
    assert {(r["published_by"], r["published_env"]) for r in rows} == {(env["users"]["pub_a"], "staging")}        # la fila original no cambió


def test_two_processes_publishing_the_same_bundle_are_serialized_and_nothing_of_the_winner_is_deleted(db_engine, env):
    """L5 (determinista): el bloqueo consultivo hace esperar al segundo; este ve las filas del primero, responde already_published y NO borra sus objetos."""
    import threading
    import time

    b = env["B"]
    out = env["work"] / "train-race"
    ok_json("train", "--tenant-id", b, "--core", "customer", "--out", out, "--seed", 41)
    ok_json("package", "--dir", out, "--sign")
    started, release = threading.Event(), threading.Event()
    prefix = f"tenants/{env['uuids']['B']}/models"
    before_objects = len(env["store"].list_keys(prefix))

    class SlowStore(LocalObjectStore):
        def put(self, key, data, **kw):
            started.set()
            assert release.wait(60)                                                                    # el primer proceso retiene el bloqueo hasta que se lo permitan
            return super().put(key, data, **kw)

    results, errors = {}, {}

    def publish(name, store):
        try:
            results[name] = bundle.publish_package(db_engine, store, out, env="staging", published_by_user_id=env["users"]["pub_b"], tenant_id=b)
        except BaseException as exc:  # noqa: BLE001
            errors[name] = exc

    first = threading.Thread(target=publish, args=("first", SlowStore(env["store_root"])))
    first.start()
    assert started.wait(30)
    second = threading.Thread(target=publish, args=("second", env["store"]))
    second.start()
    deadline = time.time() + 30
    while time.time() < deadline and one(db_engine, "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted") == 0:
        time.sleep(0.05)
    assert one(db_engine, "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted") >= 1      # el segundo está esperando el bloqueo
    release.set()
    first.join(120)
    second.join(120)
    assert not errors, errors
    assert {m.action for m in results["first"].models} == {"published"}
    assert {m.action for m in results["second"].models} == {"already_published"}
    assert len(env["store"].list_keys(prefix)) == before_objects + 2 * len(results["first"].models)               # ningún objeto del ganador se borró
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t AND version = ANY(:v)", t=b, v=[m.version for m in results["first"].models]) == len(results["first"].models)


def test_losing_the_insert_race_returns_the_other_processes_row_or_a_conflict(db_engine, env, monkeypatch):
    """L5: si otro proceso confirma la MISMA publicación entre la consulta y el insert, se responde already_published; si es de otro usuario, conflicto (6)."""
    a = env["A"]
    manifest, _ = bundle.read_package(env["dirs"]["A"])
    model = manifest["models"][0]
    row = q(db_engine, "SELECT version, published_object_key, published_by, published_env FROM model_registry WHERE tenant_id = :t AND artifact_sha256 = :s", t=a, s=model["sha256"])[0]
    real = bundle._existing_candidate
    calls = {"n": 0}

    def hidden_first(conn, tenant, version):
        calls["n"] += 1
        return None if calls["n"] == 1 else real(conn, tenant, version)

    monkeypatch.setattr(bundle, "_existing_candidate", hidden_first)
    with db_engine.begin() as conn:
        done = bundle._register_candidate(conn, a, "customer", row["published_env"], row["published_by"], manifest["bundle_id"], manifest, model, row["version"], row["published_object_key"])
    assert done.action == "already_published" and calls["n"] == 2                                      # el insert chocó y se resolvió contra la fila existente
    calls["n"] = 0
    with pytest.raises(bundle.CliError) as caught:
        with db_engine.begin() as conn:
            bundle._register_candidate(conn, a, "customer", row["published_env"], env["users"]["approver_a"], manifest["bundle_id"], manifest, model, row["version"], row["published_object_key"])
    assert caught.value.code == 6


# ───────────────────────────── revisión adversarial: fallos del almacén y modelos no aprobados en una corrida completa ─────────────────────────────

def _approve_published_pair(db_engine, env, slots, approve=True):
    tenant, approver = env["A"], env["users"]["approver_a"]
    rows = []
    for slot in slots:
        row = _served_published(db_engine, tenant, slot, env)
        _put_in_production(db_engine, tenant, row["id"], [x["id"] for x in _slot_rows(db_engine, tenant, slot, False) if x["status"] == "production"])
        if approve:
            _promote_like_backend(db_engine, tenant, row["id"], approver)
        rows.append(row)
    return rows


def test_a_failing_object_store_never_stops_a_customer_run(db_engine, env):
    """M2: objeto ilegible, directorio que es un enlace fuera de la raíz o padre que es un archivo → ESE modelo se degrada (referencia o abstención) y la corrida
    completa termina; ningún mensaje de la corrida lleva rutas del servidor."""
    import os
    import shutil

    a = env["A"]
    root = env["store_root"]
    with RegistrySnapshot(db_engine, a):
        pair = _approve_published_pair(db_engine, env, ("h14:probability", "h14:quantity"))
        prob = pair[0]
        object_file = root / prob["published_object_key"]
        folder = object_file.parent
        scenarios = {}
        if os.geteuid() != 0:
            scenarios["unreadable"] = (lambda: os.chmod(object_file, 0), lambda: os.chmod(object_file, 0o600))
        outside = root.parent / f"fuera-{uuid.uuid4().hex[:6]}"
        saved = root.parent / f"guardado-{uuid.uuid4().hex[:6]}"

        def make_symlink():
            shutil.copytree(folder, outside)
            shutil.move(str(folder), str(saved))
            folder.symlink_to(outside)

        def undo_symlink():
            folder.unlink()
            shutil.move(str(saved), str(folder))
            shutil.rmtree(outside, ignore_errors=True)

        def make_file_parent():
            shutil.move(str(folder), str(saved))
            folder.write_text("ahora soy un archivo")

        def undo_file_parent():
            folder.unlink()
            shutil.move(str(saved), str(folder))

        scenarios["symlink_out_of_root"] = (make_symlink, undo_symlink)
        scenarios["parent_is_a_file"] = (make_file_parent, undo_file_parent)
        for name, (break_it, fix_it) in scenarios.items():
            break_it()
            try:
                ARTIFACT_CACHE.clear()
                result = service_run(db_engine, a, "predict", horizons=[14])
                assert result["status"] == "completed", name
                run = q(db_engine, "SELECT summary, limitations, model_versions FROM core_runs WHERE id = :r", r=result["_run_id"])[0]
                blob = json.dumps([run["summary"], run["limitations"], run["model_versions"]], ensure_ascii=False, default=str)
                assert str(root) not in blob and "/private/" not in blob and "/var/folders" not in blob, name                # sin rutas del servidor
                served = run["model_versions"].get("14")
                if served is not None:
                    assert served["probability_id"] != prob["id"], name                                                      # no sirvió el objeto roto
                assert any(("falló" in lim and "referencia validada" in lim) or "abstención" in lim for lim in run["limitations"]), name
                assert "14" in run["summary"]["horizon_support"], name
            finally:
                fix_it()


def test_a_refused_production_model_is_reported_as_model_not_approved_in_the_run(db_engine, env):
    """M3: con el modelo en producción sin aprobar sustituido por la referencia, horizon_support dice model_not_approved (no «no superó la regla de promoción»)."""
    a = env["A"]
    with RegistrySnapshot(db_engine, a):
        _approve_published_pair(db_engine, env, ("h14:probability", "h14:quantity"), approve=False)             # en producción SIN aprobar
        ARTIFACT_CACHE.clear()
        result = service_run(db_engine, a, "predict", horizons=[14])
        assert result["status"] == "completed"
        run = q(db_engine, "SELECT summary, limitations FROM core_runs WHERE id = :r", r=result["_run_id"])[0]
        support = run["summary"]["horizon_support"]["14"]
        codes = [r["reason_code"] for r in support["reasons"]]
        assert support["status"] == "degraded" and codes[0] == "model_not_approved" and "fallback_reference" not in codes     # (puede añadir stale_data: los datos del mundo son antiguos)
        assert "no está aprobado" in support["reasons"][0]["message"] and "no superó" not in support["reasons"][0]["message"]
        limitations = " ".join(run["limitations"])
        assert "no está aprobado" in limitations and not any("H=14" in lim and "no superó la regla de promoción" in lim for lim in run["limitations"])


def test_training_a_company_frees_only_its_own_cache_entries(db_engine, env):
    """L7: al terminar un entrenamiento del Customer Core el motor libera la memoria de esa empresa (la verificación no depende de ello); las demás no se tocan.
    (Va al final del módulo: reentrena a la empresa B con el servicio.)"""
    ARTIFACT_CACHE.clear()
    _load_serving_models(db_engine, env["A"], [7])
    _load_serving_models(db_engine, env["B"], [7])
    assert {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {env["A"], env["B"]}
    service_run(db_engine, env["B"], "train", horizons=[7])
    assert {k.tenant_id for k in ARTIFACT_CACHE.keys()} == {env["A"]}
    assert _load_serving_models(db_engine, env["B"], [7]).model_versions["7"]["probability"].startswith(f"t{env['B']}-customer-v")      # B sirve sus modelos nuevos
