"""Customer Core · integración con PostgreSQL: entrenar ≠ predecir, registro de modelos, idempotencia, aislamiento,
abstención, compromisos y reconciliación. Mundos sintéticos FICTICIOS (verdad conocida) cargados como empresas.

E2-09, E2-11, E2-17 (customer), E2-18, E2-21, E2-22, E2-23, E2-24 (parte customer).
"""

from __future__ import annotations

import hashlib
import json
from datetime import timedelta
from pathlib import Path

import pytest
from sqlalchemy import text

from customer_fixtures import create_core_run, load_world
from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.db.customer_repository import CustomerRepository
from predictily_engine.db.repository import TenantScopeError
from predictily_engine.pipeline.errors import ConflictError, NotFoundError

pytestmark = pytest.mark.integration
AUTH = {"Authorization": "Bearer test-token"}


def run(engine, tenant_id: int, operation: str, **kw) -> dict:
    with engine.begin() as conn:
        run_id, job_id = create_core_run(conn, tenant_id, operation, **kw)
    out = execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))
    out["_job_id"] = job_id
    return out


def q(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


def one(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).scalar()


@pytest.fixture(scope="module")
def world_a():
    return generate_world(seed=7, n_customers=140, n_products=8, n_days=440, mean_interval_range=(20, 200), churn_share=0.1)


@pytest.fixture(scope="module")
def env(db_engine, world_a):
    """Empresa A entrenada; B (sin modelos) y C (datos mínimos) cargadas; todo ficticio."""
    with db_engine.begin() as conn:
        a = load_world(conn, world_a, "Empresa A (ficticia, P2)")
        b = load_world(conn, generate_world(seed=9, n_customers=60, n_products=6, n_days=300, mean_interval_range=(20, 200)), "Empresa B (ficticia, P2)")
        c = load_world(conn, generate_world(seed=11, n_customers=15, n_products=3, n_days=120, mean_interval_range=(40, 200)), "Empresa C (mínima, P2)")
    train = run(db_engine, a["tenant_id"], "train")
    last = world_a.start + timedelta(days=world_a.n_days - 1)
    return {"A": a, "B": b, "C": c, "train": train, "as_of": (last + timedelta(days=1)).isoformat(), "last": last}


def predict_a(db_engine, env, as_of=None):
    return run(db_engine, env["A"]["tenant_id"], "predict", as_of=as_of or env["as_of"])


# ───────────────────────────── entrenamiento y registro ─────────────────────────────

def test_train_records_the_protocol_manifest_snapshot_and_config(db_engine, env):
    tid, rid = env["A"]["tenant_id"], env["train"]["run_id"]
    row = q(db_engine, "SELECT * FROM core_runs WHERE id = :r AND tenant_id = :t", r=rid, t=tid)[0]
    assert row["status"] == "completed" and row["result_type"] == "evaluation" and row["operation"] == "train"
    assert row["quality_status"] in ("ok", "degraded") and row["uncertainty_source"] == "empirical_holdout"
    assert len(row["config_version"]) == 64 and row["effective_config"]["config"]["promotion_rule_version"] == "cust-promotion-v1"
    assert row["data_snapshot_id"] is not None and row["is_current"] is True
    manifest = row["evaluation_manifest"]
    assert manifest["labels_mature_through"] and manifest["final_holdout_policy"]
    h30 = manifest["horizons"]["30"]
    assert h30["status"] in ("trained", "reference_only")
    for name in (("train", "calibration", "selection", "holdout") if h30["tier"] == "full" else ("calibration", "holdout")):
        blk = h30["blocks"][name]
        assert blk["cutoff_from"] <= blk["cutoff_to"] and blk["n_cutoffs"] >= 2
    assert row["target_definition"]["expected_amount"].count("supuesto") == 1
    assert any("sin doble conteo" in a for a in row["assumptions"])
    job = q(db_engine, "SELECT status, progress FROM training_jobs WHERE id = :j", j=env["train"]["_job_id"])[0]
    assert job["status"] == "succeeded" and job["progress"] == 100
    snap = q(db_engine, "SELECT components, coverage FROM data_snapshots WHERE id = :i AND tenant_id = :t", i=row["data_snapshot_id"], t=tid)[0]
    assert {"purchases", "availability", "config", "eligibility"} <= set(snap["components"])


def test_registry_has_one_production_model_per_slot_with_verified_artifacts(db_engine, env):
    tid = env["A"]["tenant_id"]
    rows = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND core = 'customer' ORDER BY id", t=tid)
    prod = [r for r in rows if r["status"] == "production"]
    slots = [r["hyperparameters"]["slot"] for r in prod]
    assert len(slots) == len(set(slots)) and any(s.endswith(":probability") for s in slots) and any(s.endswith(":quantity") for s in slots)
    for r in rows:
        assert r["status"] in ("candidate", "validated", "production", "retired", "failed")
        assert r["role"] == "fallback" or r["hyperparameters"]["kind"] == "complex"
        if r["artifact_path"]:
            data = Path(r["artifact_path"]).read_bytes()
            assert hashlib.sha256(data).hexdigest() == r["artifact_sha256"]
            assert str(tid) in r["artifact_path"] and "/customer/" in r["artifact_path"]
            assert r["artifact_compat"]["feature_schema_version"] and r["artifact_compat"]["libraries"]["lightgbm"]
    for r in prod:   # un modelo complejo solo está en producción si superó la regla; la referencia se sirve con rol fallback
        if r["hyperparameters"]["kind"] == "reference":
            assert r["role"] == "fallback"
        else:
            assert r["hyperparameters"]["gate"]["passed"] is True and r["role"] == "primary"
    # toda referencia evaluada queda registrada como respaldo validado
    assert any(r["hyperparameters"]["kind"] == "reference" for r in rows)


def test_evaluations_store_brier_logloss_curves_and_ranking_with_n(db_engine, env):
    tid, rid = env["A"]["tenant_id"], env["train"]["run_id"]
    rows = q(db_engine, "SELECT * FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r AND core = 'customer'", t=tid, r=rid)
    keys = {r["level_key"] for r in rows}
    assert {"h7:probability", "h30:probability", "coherence", "inactivity", "frequency"} <= keys
    prob = [r for r in rows if r["level_key"] == "h7:probability" and r["evaluation_type"] == "holdout"]
    assert any(r["is_baseline"] for r in prob) and any(r["is_selected"] for r in prob)
    for r in prob:
        m = r["metrics"]
        assert m["brier"] is not None and m["log_loss"] is not None and m["pr_auc"] is not None
        assert r["n_obs"] == m["n"] and all(b["n"] > 0 for b in m["calibration_curve"])
    exp = [r for r in rows if r["level_key"] == "h7:expected_quantity"]
    assert len(exp) == 2 and all(r["mae"] is not None for r in exp)
    amount = [r for r in rows if r["level_key"] == "h7:expected_amount" and r["is_selected"]][0]
    assert "supuesto" in amount["extra"]["definition"]
    assert [r for r in rows if r["level_key"] == "h7:ranking"][0]["metrics"]["popularity"]["precision_at_3"] is not None


def test_a_second_training_retires_the_previous_production_models(db_engine, env):
    tid = env["A"]["tenant_id"]
    before = {r["hyperparameters"]["slot"]: r["id"] for r in q(db_engine, "SELECT id, hyperparameters FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production'", t=tid)}
    second = run(db_engine, tid, "train")
    prod = q(db_engine, "SELECT id, hyperparameters FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production'", t=tid)
    slots = [r["hyperparameters"]["slot"] for r in prod]
    assert len(slots) == len(set(slots)) == len(before)                 # una sola producción por horizonte y objetivo
    retired = q(db_engine, "SELECT id FROM model_registry WHERE tenant_id = :t AND status = 'retired'", t=tid)
    assert set(before.values()) <= {r["id"] for r in retired}
    assert one(db_engine, "SELECT is_current FROM core_runs WHERE id = :r", r=env["train"]["run_id"]) is False
    assert one(db_engine, "SELECT is_current FROM core_runs WHERE id = :r", r=second["run_id"]) is True


# ───────────────────────────── predicción ─────────────────────────────

def test_predict_does_not_train_and_persists_outputs_with_the_envelope(db_engine, env):
    tid = env["A"]["tenant_id"]
    registry_before = one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=tid)
    out = predict_a(db_engine, env)
    assert one(db_engine, "SELECT count(*) FROM model_registry WHERE tenant_id = :t", t=tid) == registry_before   # predecir no entrena
    assert out["status"] == "completed" and out["quality_status"] in ("ok", "degraded")
    run_row = q(db_engine, "SELECT * FROM core_runs WHERE id = :r", r=out["run_id"])[0]
    assert run_row["result_type"] == "forecast" and str(run_row["as_of"]) == env["as_of"]
    assert str(run_row["horizon_start"]) == (env["last"] + timedelta(days=2)).isoformat()
    assert run_row["model_versions"] and run_row["limitations"] and run_row["assumptions"] and run_row["data_snapshot_id"]
    preds = q(db_engine, "SELECT * FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r", t=tid, r=out["run_id"])
    assert len(preds) == out["summary"]["rows"] > 500
    for p in preds:
        assert 0 < float(p["purchase_probability"]) < 1
        assert float(p["expected_quantity"]) == pytest.approx(float(p["purchase_probability"]) * float(p["quantity_if_purchase"]), rel=1e-3, abs=1e-3)
        assert float(p["total_view_quantity"]) >= float(p["expected_quantity"]) - 1e-3
        assert p["currency"] == "USD" and p["model_role"] in ("primary", "fallback")
    by = {(p["customer_id"], p["product_id"], p["horizon_days"]): float(p["purchase_probability"]) for p in preds}
    horizons = sorted({k[2] for k in by})
    for (c, pr, h), v in by.items():
        for h2 in horizons:
            if h2 > h and (c, pr, h2) in by:
                assert v <= by[(c, pr, h2)] + 1e-6                 # P7 ≤ P30 ≤ P90
    assert q(db_engine, "SELECT 1 FROM customer_activity WHERE tenant_id = :t AND core_run_id = :r LIMIT 1", t=tid, r=out["run_id"])
    ranks = q(db_engine, "SELECT customer_id, horizon_days, count(*) n FROM customer_product_rankings WHERE tenant_id = :t AND core_run_id = :r GROUP BY 1, 2", t=tid, r=out["run_id"])
    assert ranks and max(r["n"] for r in ranks) <= 10
    assert one(db_engine, "SELECT status FROM training_jobs WHERE id = :j", j=out["_job_id"]) == "succeeded"


def test_generic_and_anonymous_buyers_get_no_individual_prediction(db_engine, env, world_a):
    """E2-09: nadie recibe una identidad recurrente inventada; el respaldo es el residual de la reconciliación."""
    tid = env["A"]["tenant_id"]
    out = predict_a(db_engine, env)
    generic = q(db_engine, "SELECT id FROM customers WHERE tenant_id = :t AND is_generic", t=tid)
    assert generic
    assert one(db_engine, "SELECT count(*) FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r AND customer_id = ANY(:g)",
               t=tid, r=out["run_id"], g=[g["id"] for g in generic]) == 0
    ex = out["summary"]["excluded_from_individual_prediction"]
    assert ex["anonymous"]["lines"] > 0 and ex["generic"]["lines"] > 0 and 0 < ex["modeled_share_units_180d"] < 1
    assert any("residual" in lim for lim in q(db_engine, "SELECT limitations FROM core_runs WHERE id = :r", r=out["run_id"])[0]["limitations"])


def test_recency_changes_with_the_date_even_without_new_sales(db_engine, env):
    """E2-18: la predicción diaria recalcula la recencia aunque no haya ninguna venta nueva."""
    tid = env["A"]["tenant_id"]
    d1 = predict_a(db_engine, env)
    d4 = predict_a(db_engine, env, as_of=(env["last"] + timedelta(days=4)).isoformat())
    sql = ("SELECT customer_id, product_id, days_since_last_purchase, purchase_probability FROM customer_predictions "
           "WHERE tenant_id = :t AND core_run_id = :r AND horizon_days = 30")
    a = {(r["customer_id"], r["product_id"]): r for r in q(db_engine, sql, t=tid, r=d1["run_id"])}
    b = {(r["customer_id"], r["product_id"]): r for r in q(db_engine, sql, t=tid, r=d4["run_id"])}
    common = set(a) & set(b)
    assert len(common) > 300
    assert all(b[k]["days_since_last_purchase"] - a[k]["days_since_last_purchase"] == 3 for k in common)
    assert any(abs(float(a[k]["purchase_probability"]) - float(b[k]["purchase_probability"])) > 1e-6 for k in common)


def test_retries_are_idempotent_and_never_duplicate_results(db_engine, env):
    """E2-18: reejecutar una corrida completada no cambia nada; retomar una vencida reemplaza (no duplica)."""
    tid = env["A"]["tenant_id"]
    out = predict_a(db_engine, env)
    rid, jid = out["run_id"], out["_job_id"]
    count = lambda: one(db_engine, "SELECT count(*) FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r", t=tid, r=rid)  # noqa: E731
    n = count()
    again = execute_customer_run(rid, CustomerExecuteRequest(tenant_id=tid, training_job_id=jid))
    assert again["status"] == "completed" and again["run_id"] == rid and count() == n
    # un intento anterior murió a medias: la corrida figura «running» desde hace horas → se retoma y reemplaza
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE core_runs SET status = 'running', started_at = now() - interval '3 hours', completed_at = NULL WHERE id = :r"), {"r": rid})
        conn.execute(text("DELETE FROM customer_predictions WHERE core_run_id = :r AND horizon_days = 90"), {"r": rid})   # resultado parcial
    resumed = execute_customer_run(rid, CustomerExecuteRequest(tenant_id=tid, training_job_id=jid))
    assert resumed["status"] == "completed" and count() == n
    assert one(db_engine, "SELECT count(*) FROM (SELECT 1 FROM customer_predictions WHERE core_run_id = :r GROUP BY customer_id, product_id, horizon_days HAVING count(*) > 1) d", r=rid) == 0
    # otra ejecución en curso reciente → conflicto (no se ejecuta dos veces a la vez)
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE core_runs SET status = 'running', started_at = now() WHERE id = :r"), {"r": rid})
    with pytest.raises(ConflictError):
        execute_customer_run(rid, CustomerExecuteRequest(tenant_id=tid, training_job_id=jid))
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE core_runs SET status = 'completed' WHERE id = :r"), {"r": rid})


# ───────────────────────────── aislamiento (E2-17) ─────────────────────────────

def test_company_isolation_in_runs_models_artifacts_and_results(db_engine, env):
    a, b = env["A"]["tenant_id"], env["B"]["tenant_id"]
    out = predict_a(db_engine, env)
    # la corrida de A no se puede ejecutar como si fuera de B
    with pytest.raises(NotFoundError):
        execute_customer_run(out["run_id"], CustomerExecuteRequest(tenant_id=b, training_job_id=out["_job_id"]))
    # B no tiene modelos: no hereda los de A (sin entrenamiento compartido) y se abstiene
    pb = run(db_engine, b, "predict", as_of="2027-01-01")
    assert pb["status"] == "completed" and pb["quality_status"] == "insufficient_data"
    assert one(db_engine, "SELECT count(*) FROM customer_predictions WHERE tenant_id = :t", t=b) == 0
    assert "no hay modelo en producción" in " ".join(q(db_engine, "SELECT limitations FROM core_runs WHERE id = :r", r=pb["run_id"])[0]["limitations"])
    # ninguna fila de A apunta a compradores o productos de otra empresa (FKs compuestas + lectura con tenant_id)
    assert one(db_engine, "SELECT count(*) FROM customer_predictions p JOIN customers c ON c.id = p.customer_id WHERE p.tenant_id = :t AND c.tenant_id <> p.tenant_id", t=a) == 0
    assert one(db_engine, "SELECT count(*) FROM customer_predictions p JOIN products c ON c.id = p.product_id WHERE p.tenant_id = :t AND c.tenant_id <> p.tenant_id", t=a) == 0
    # entrenar B no toca los modelos de A; los artefactos de cada empresa viven en su propio directorio
    prod_a = {r["id"] for r in q(db_engine, "SELECT id FROM model_registry WHERE tenant_id = :t AND status = 'production' AND core = 'customer'", t=a)}
    tb = run(db_engine, b, "train")
    assert tb["status"] == "completed"
    assert prod_a == {r["id"] for r in q(db_engine, "SELECT id FROM model_registry WHERE tenant_id = :t AND status = 'production' AND core = 'customer'", t=a)}
    paths_b = [r["artifact_path"] for r in q(db_engine, "SELECT artifact_path FROM model_registry WHERE tenant_id = :t AND artifact_path IS NOT NULL", t=b)]
    assert paths_b and all(f"/{b}/customer/" in p for p in paths_b)
    # sus predicciones jamás incluyen compradores de A
    pb2 = run(db_engine, b, "predict", as_of="2026-01-01")
    if pb2["quality_status"] != "insufficient_data":
        assert one(db_engine, "SELECT count(*) FROM customer_predictions p JOIN customers c ON c.id = p.customer_id WHERE p.tenant_id = :t AND c.tenant_id <> :t", t=b) == 0


def test_repository_refuses_queries_without_a_tenant_filter(db_engine):
    with db_engine.connect() as conn:
        repo = CustomerRepository(conn, 1)
        with pytest.raises(TenantScopeError):
            repo._read("SELECT * FROM customers")
        with pytest.raises(TenantScopeError):
            CustomerRepository(conn, 0)


# ───────────────────────────── abstención (E2-21) ─────────────────────────────

def test_insufficient_history_abstains_explicitly(db_engine, env):
    c = env["C"]["tenant_id"]
    t = run(db_engine, c, "train")
    row = q(db_engine, "SELECT quality_status, limitations FROM core_runs WHERE id = :r", r=t["run_id"])[0]
    horizons = t["summary"]["horizons"]
    assert horizons["90"]["status"] == "abstained" and "insuficientes" in horizons["90"]["reason"]
    assert any("H=90" in lim and "abstención" in lim for lim in row["limitations"])
    prod = q(db_engine, "SELECT hyperparameters FROM model_registry WHERE tenant_id = :t AND status = 'production' AND core = 'customer'", t=c)
    assert all(r["hyperparameters"]["horizon_days"] != 90 for r in prod)          # no se inventa un modelo para H=90
    p = run(db_engine, c, "predict", as_of="2026-05-01")
    assert p["status"] == "completed"
    assert 90 not in (p["summary"]["horizons"] or [])
    assert one(db_engine, "SELECT count(*) FROM customer_predictions WHERE tenant_id = :t AND horizon_days = 90", t=c) == 0


# ───────────────────────────── respaldo y reversión (E2-24) ─────────────────────────────

def _complex_slot(db_engine, tid):
    for r in q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production' "
                          "AND hyperparameters->>'target' = 'probability' ORDER BY id", t=tid):
        if r["hyperparameters"]["kind"] == "complex":
            return r
    return None


def test_a_broken_production_artifact_falls_back_to_the_validated_reference(db_engine, env):
    tid = env["A"]["tenant_id"]
    prod = _complex_slot(db_engine, tid)
    if prod is None:
        pytest.skip("En esta corrida ningún horizonte sirve un modelo complejo")
    h = prod["hyperparameters"]["horizon_days"]
    path = Path(prod["artifact_path"])
    original = path.read_bytes()
    try:
        path.write_bytes(original[:-50] + b"corrupto" * 7)               # el sha256 ya no coincide
        out = predict_a(db_engine, env)
        lims = " ".join(q(db_engine, "SELECT limitations FROM core_runs WHERE id = :r", r=out["run_id"])[0]["limitations"])
        assert "referencia validada" in lims and out["quality_status"] == "degraded"
        row = q(db_engine, "SELECT model_family, model_role FROM customer_predictions WHERE core_run_id = :r AND horizon_days = :h LIMIT 1", r=out["run_id"], h=h)[0]
        assert row["model_family"] == "reference" and row["model_role"] == "fallback"       # respaldo identificado
    finally:
        path.write_bytes(original)


def test_rollback_to_a_validated_version_is_served_by_the_next_prediction(db_engine, env):
    tid = env["A"]["tenant_id"]
    prod = _complex_slot(db_engine, tid)
    if prod is None:
        pytest.skip("En esta corrida ningún horizonte sirve un modelo complejo")
    slot = prod["hyperparameters"]["slot"]
    ref = q(db_engine, "SELECT id FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND hyperparameters->>'slot' = :s "
                       "AND hyperparameters->>'kind' = 'reference' AND artifact_path IS NOT NULL ORDER BY id DESC LIMIT 1", t=tid, s=slot)[0]["id"]
    with db_engine.begin() as conn:      # reversión: la referencia validada pasa a producción y el complejo a «retired»
        conn.execute(text("UPDATE model_registry SET status = 'retired' WHERE id = :i"), {"i": prod["id"]})
        conn.execute(text("UPDATE model_registry SET status = 'production' WHERE id = :i"), {"i": ref})
    try:
        out = predict_a(db_engine, env)
        h = prod["hyperparameters"]["horizon_days"]
        assert q(db_engine, "SELECT model_family FROM customer_predictions WHERE core_run_id = :r AND horizon_days = :h LIMIT 1", r=out["run_id"], h=h)[0]["model_family"] == "reference"
        assert out["summary"]["served"][str(h)]["role"] == "fallback"
    finally:
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE model_registry SET status = 'production' WHERE id = :i"), {"i": prod["id"]})
            conn.execute(text("UPDATE model_registry SET status = 'validated' WHERE id = :i"), {"i": ref})


# ───────────────────────────── compromisos y reconciliación (E2-11, E2-23) ─────────────────────────────

def test_commitments_and_aggregate_demand_are_reconciled_without_double_counting(db_engine, env, world_a):
    tid = env["A"]["tenant_id"]
    ids = env["A"]
    as_of = env["as_of"]
    base = predict_a(db_engine, env)
    pred = q(db_engine, "SELECT customer_id, product_id, horizon_days, expected_quantity FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r AND horizon_days = 30 "
                        "ORDER BY expected_quantity DESC LIMIT 1", t=tid, r=base["run_id"])[0]
    cust, prod = pred["customer_id"], pred["product_id"]
    other_prod = q(db_engine, "SELECT id FROM products WHERE tenant_id = :t AND id <> :p ORDER BY id LIMIT 1", t=tid, p=prod)[0]["id"]
    expected = float(pred["expected_quantity"])
    big = expected + 40                                                      # el comprometido supera la esperanza del comprador
    with db_engine.begin() as conn:
        def commit(ext, ctype, status, qty, fulfilled, delivery, known, cancelled=None, customer=cust, product=prod, unit="unidad"):
            cid = conn.execute(text(
                "INSERT INTO commercial_commitments (tenant_id, customer_id, commitment_type, external_id, status, issued_at, known_at, "
                "expected_delivery_date, cancelled_at, currency, created_at, updated_at) VALUES (:t, :c, :ty, :e, :s, :iss, :k, :d, :x, 'USD', now(), now()) RETURNING id"
            ), {"t": tid, "c": customer, "ty": ctype, "e": ext, "s": status, "iss": f"{known} 08:00:00-05", "k": f"{known} 08:00:00-05", "d": delivery,
                "x": cancelled}).scalar_one()
            conn.execute(text(
                "INSERT INTO commercial_commitment_items (tenant_id, commitment_id, line_number, product_id, quantity, quantity_fulfilled, unit, created_at, updated_at) "
                "VALUES (:t, :c, 1, :p, :q, :f, :u, now(), now())"), {"t": tid, "c": cid, "p": product, "q": qty, "f": fulfilled, "u": unit})
            # revisión como la deja el importador de P3: la regla de estado a un corte (CommitmentAsOf) lee estas versiones
            sku = conn.execute(text("SELECT sku FROM products WHERE tenant_id = :t AND id = :p"), {"t": tid, "p": product}).scalar_one()
            snapshot = {"header": {"commitment_type": ctype, "external_id": ext, "status": status, "expected_delivery_date": delivery, "currency": "USD"},
                        "lines": [{"line_number": 1, "product_sku": sku, "quantity": qty, "quantity_fulfilled": fulfilled, "unit": unit, "delivery_date": None}]}
            conn.execute(text(
                "INSERT INTO commercial_commitment_revisions (tenant_id, commitment_id, revision, status, known_at, cancelled_at, source_hash, snapshot, recorded_at) "
                "VALUES (:t, :c, 1, :s, :k, :x, repeat('a', 64), CAST(:snap AS jsonb), now())"),
                {"t": tid, "c": cid, "s": status, "k": f"{known} 08:00:00-05", "x": cancelled, "snap": json.dumps(snapshot)})
        within = (env["last"] + timedelta(days=15)).isoformat()
        commit("PED-1", "order", "partially_fulfilled", big + 10, 10, within, env["last"].isoformat())             # pendiente = big
        commit("PED-2", "order", "confirmed", 500, 0, within, (env["last"] + timedelta(days=9)).isoformat())         # conocido DESPUÉS del corte
        commit("COT-1", "quote", "open", 500, 0, within, env["last"].isoformat())                                   # cotización: no es compromiso
        commit("PED-3", "order", "cancelled", 500, 0, within, env["last"].isoformat(), cancelled=f"{env['last'].isoformat()} 12:00:00-05")  # cancelado antes del corte
        commit("PED-4", "order", "confirmed", 500, 0, (env["last"] + timedelta(days=200)).isoformat(), env["last"].isoformat())  # entrega fuera de H
        commit("PED-5", "contract", "confirmed", 500, 0, within, env["last"].isoformat(), unit="caja")             # otra unidad: no se convierte en silencio
        commit("PED-6", "order", "confirmed", 25, 0, within, env["last"].isoformat(), customer=None, product=other_prod)  # sin comprador atribuible
        # demanda agregada vigente: product-level diario, 20 u/día para `prod` y 0,01 u/día para `other_prod`
        fr = conn.execute(text("INSERT INTO forecast_runs (tenant_id, version, run_type, status, horizon_days, cutoff_date, is_current, created_at, updated_at) "
                               "VALUES (:t, 1000, 'production', 'completed', 90, :c, true, now(), now()) RETURNING id"), {"t": tid, "c": env["last"]}).scalar_one()
        for pid, yhat in ((prod, 20.0), (other_prod, 0.01)):
            conn.execute(text(
                "INSERT INTO forecast_values (tenant_id, forecast_run_id, node_key, level, product_id, granularity, target_date, period_days, horizon_step, yhat) "
                "SELECT :t, :f, :nk, 'product', :p, 'day', CAST(:c AS date) + g, 1, g, :y FROM generate_series(1, 90) g"
            ), {"t": tid, "f": fr, "nk": f"product:{pid}", "p": pid, "c": env["last"], "y": yhat})
    out = predict_a(db_engine, env)
    row = q(db_engine, "SELECT committed_quantity, expected_quantity, total_view_quantity, incremental_quantity FROM customer_predictions "
                       "WHERE tenant_id = :t AND core_run_id = :r AND customer_id = :c AND product_id = :p AND horizon_days = 30", t=tid, r=out["run_id"], c=cust, p=prod)[0]
    assert float(row["committed_quantity"]) == pytest.approx(big, abs=1e-3)       # solo el pedido vigente, conocido y con entrega dentro de H
    assert float(row["total_view_quantity"]) == pytest.approx(max(float(row["expected_quantity"]), big), abs=1e-3)   # no se suman: max(esperanza, comprometido)
    assert float(row["incremental_quantity"]) == pytest.approx(float(row["total_view_quantity"]) - big, abs=1e-3)
    summ = out["summary"]["committed"]["30"]
    assert summ["excluded"]["unknown_or_after_cutoff"] == 1 and summ["excluded"]["not_order_or_contract"] == 1 and summ["excluded"]["cancelled"] == 1
    assert summ["commitments_not_visible_at_cutoff"] == 1           # PED-2 (conocido después del corte) no existe para este corte (regla P3)
    assert summ["excluded"]["delivery_after_horizon"] == 1 and summ["excluded"]["unit_mismatch"] == 1
    rec = {(r["product_id"], r["horizon_days"]): r for r in q(db_engine, "SELECT * FROM customer_reconciliations WHERE tenant_id = :t AND core_run_id = :r", t=tid, r=out["run_id"])}
    r1 = rec[(prod, 30)]
    assert r1["status"] == "ok" and float(r1["aggregate_quantity"]) == pytest.approx(600.0)            # 20 u/día × 30 días (misma unidad del producto)
    total = float(r1["buyers_total_quantity"]) + float(r1["committed_outside_model"]) + float(r1["residual_quantity"])
    assert total == pytest.approx(float(r1["aggregate_quantity"]), abs=1e-3)                          # A = compradores + comprometido fuera + residual
    assert float(r1["residual_quantity"]) > 0 and float(r1["buyers_committed_quantity"]) >= big - 1e-3
    r2 = rec[(other_prod, 30)]
    assert r2["status"] == "inconsistent" and float(r2["residual_quantity"]) == 0 and float(r2["excess_quantity"]) > 0   # Σ compradores > agregado: se informa, no se corrige
    assert float(r2["committed_outside_model"]) == pytest.approx(25.0, abs=1e-3)                      # comprometido sin comprador atribuible
    # las probabilidades individuales no cambian por la reconciliación: coinciden con la corrida previa (misma fecha, mismos modelos)
    before = {(r["customer_id"], r["product_id"]): float(r["purchase_probability"]) for r in q(
        db_engine, "SELECT customer_id, product_id, purchase_probability FROM customer_predictions WHERE core_run_id = :r AND horizon_days = 30", r=base["run_id"])}
    after = {(r["customer_id"], r["product_id"]): float(r["purchase_probability"]) for r in q(
        db_engine, "SELECT customer_id, product_id, purchase_probability FROM customer_predictions WHERE core_run_id = :r AND horizon_days = 30", r=out["run_id"])}
    assert before == after
    assert out["summary"]["reconciliation"]["30"]["status_counts"].get("inconsistent", 0) >= 1


def test_api_execute_endpoint_requires_token_and_company(client, db_engine, env):
    tid = env["A"]["tenant_id"]
    with db_engine.begin() as conn:
        rid, jid = create_core_run(conn, tid, "predict", as_of=env["as_of"])
    url = f"/v1/customer/runs/{rid}/execute"
    assert client.post(url, json={"tenant_id": tid}).status_code == 401
    assert client.post(url, json={"tenant_id": env["B"]["tenant_id"]}, headers=AUTH).status_code == 404
    ok = client.post(url, json={"tenant_id": tid, "training_job_id": jid}, headers=AUTH)
    assert ok.status_code == 200 and ok.json()["operation"] == "predict" and ok.json()["status"] == "completed"
    assert client.post(url, json={"tenant_id": tid, "training_job_id": jid}, headers=AUTH).json()["run_id"] == rid        # idempotente
    caps = client.get("/v1/customer/capabilities", headers=AUTH).json()
    assert caps["horizons"] == [7, 30, 90] and "isotonic" in caps["calibration"]
    assert json.dumps(caps["target_definition"]).count("supuesto") >= 1


# ───────────────────────────── fuente del ranking (ADR-P2-01 · cust-ranking-v1) ─────────────────────────────

def test_ranking_source_is_recorded_in_models_summary_and_every_ranking_row(db_engine, env):
    tid = env["A"]["tenant_id"]
    train = q(db_engine, "SELECT model_versions, summary FROM core_runs WHERE id = :r", r=env["train"]["run_id"])[0]
    out = predict_a(db_engine, env)
    pred = q(db_engine, "SELECT model_versions, summary FROM core_runs WHERE id = :r", r=out["run_id"])[0]
    for h, entry in pred["model_versions"].items():
        decided = entry["ranking_source"]
        assert decided in ("model", "popularity", "recency")
        assert out["summary"]["served"][h]["ranking_source"] == decided
        rows = q(db_engine, "SELECT DISTINCT ranking_source FROM customer_product_rankings WHERE tenant_id = :t AND core_run_id = :r AND horizon_days = :h", t=tid, r=out["run_id"], h=int(h))
        assert [r["ranking_source"] for r in rows] == [decided]
    for h, item in train["summary"]["horizons"].items():
        if item.get("ranking_source"):
            assert item["ranking"]["rule_version"] == "cust-ranking-v1" and item["ranking"]["primary_k"] == 5
            assert train["model_versions"][h]["ranking_source"] == item["ranking_source"]
    ev = q(db_engine, "SELECT extra FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r AND level_key LIKE :pat", t=tid, r=env["train"]["run_id"], pat="%:ranking")
    assert ev and all(e["extra"]["selection"]["source"] in ("model", "popularity", "recency") for e in ev)


def test_when_popularity_wins_the_served_ranking_is_popularity(db_engine):
    """Con un margen exigido inalcanzable (override de empresa), ninguna fuente supera a popularidad: se sirve popularidad en todos los horizontes."""
    world = generate_world(seed=13, n_customers=110, n_products=7, n_days=430, mean_interval_range=(20, 200))
    with db_engine.begin() as conn:
        env_d = load_world(conn, world, "Empresa D (ranking, ficticia)", settings={"cores": {"customer": {"ranking_min_relative_margin": 50.0, "ranking_min_units": 1}}})
    tid = env_d["tenant_id"]
    train = run(db_engine, tid, "train")
    served = {h: i for h, i in train["summary"]["horizons"].items() if i["status"] in ("trained", "reference_only")}
    assert served and all(i["ranking_source"] == "popularity" for i in served.values())
    assert all("ninguna fuente supera a popularidad" in i["ranking"]["reason"] for i in served.values())
    out = run(db_engine, tid, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    assert out["status"] == "completed"
    rows = q(db_engine, "SELECT customer_id, horizon_days, rank, ranking_source, ranking_score FROM customer_product_rankings WHERE tenant_id = :t AND core_run_id = :r "
                        "ORDER BY customer_id, horizon_days, rank", t=tid, r=out["run_id"])
    assert rows and {r["ranking_source"] for r in rows} == {"popularity"}
    prev = None
    for r in rows:
        if prev and (prev["customer_id"], prev["horizon_days"]) == (r["customer_id"], r["horizon_days"]):
            assert float(prev["ranking_score"]) >= float(r["ranking_score"])        # orden por popularidad (compradores distintos 90 d)
        prev = r
    assert {m["ranking_source"] for m in q(db_engine, "SELECT model_versions->:h AS m FROM core_runs WHERE id = :r", h=next(iter(served)), r=out["run_id"])[0].values() if isinstance(m, dict)} == {"popularity"}


# ───────────────────────────── cobertura efectiva de P3 y madurez de etiquetas ─────────────────────────────

def test_label_maturity_and_prediction_date_follow_the_effective_coverage_of_p3(db_engine):
    """La cobertura declarada (regla P3 `effective_coverage`) manda sobre la última venta: etiquetas maduras solo hasta ahí
    y los días posteriores son AUSENTES (la predicción se calcula a la fecha de cobertura y queda degradada)."""
    world = generate_world(seed=17, n_customers=90, n_products=6, n_days=420, mean_interval_range=(20, 200))
    through = world.start + timedelta(days=300)
    with db_engine.begin() as conn:
        e = load_world(conn, world, "Empresa E (cobertura, ficticia)", coverage_through=through.isoformat())
    tid = e["tenant_id"]
    train = run(db_engine, tid, "train")
    row = q(db_engine, "SELECT evaluation_manifest, data_snapshot_id FROM core_runs WHERE id = :r", r=train["run_id"])[0]
    assert row["evaluation_manifest"]["labels_mature_through"] == through.isoformat()
    assert row["evaluation_manifest"]["coverage_source"] == "data_coverage"
    for h, item in row["evaluation_manifest"]["horizons"].items():
        if item.get("blocks"):
            for name in ("train", "calibration", "holdout"):
                blk = item["blocks"].get(name)
                if blk:
                    assert blk["label_window_to"] <= through.isoformat()      # ninguna ventana de resultado pasa la cobertura
    snap = q(db_engine, "SELECT coverage FROM data_snapshots WHERE id = :i", i=row["data_snapshot_id"])[0]["coverage"]
    assert snap["sales_complete_through"] == through.isoformat() and snap["basis"] == "data_coverage"
    out = run(db_engine, tid, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    assert out["summary"]["horizons"] == [7, 30] and train["summary"]["horizons"]["90"]["status"] == "abstained"
    assert out["summary"]["as_of_effective"] == through.isoformat() and out["summary"]["stale_data"] is True
    assert out["quality_status"] == "degraded"
    lims = " ".join(q(db_engine, "SELECT limitations FROM core_runs WHERE id = :r", r=out["run_id"])[0]["limitations"])
    assert "cobertura declarada" in lims


def test_a_confirmed_order_without_known_at_counts_today_but_not_in_past_cutoffs(db_engine, env):
    """La ingestión es la cota superior del conocimiento: un pedido confirmado importado SIN known_at es visible en el corte de hoy
    (`known_basis = ingestion`) y no existe en cortes anteriores a su importación."""
    from datetime import datetime
    from zoneinfo import ZoneInfo
    tid = env["A"]["tenant_id"]
    today = datetime.now(ZoneInfo("America/Guayaquil")).date()
    base = predict_a(db_engine, env)
    rel = q(db_engine, "SELECT p.customer_id, p.product_id FROM customer_predictions p WHERE p.tenant_id = :t AND p.core_run_id = :r AND p.horizon_days = 30 "
                       "AND p.customer_id NOT IN (SELECT customer_id FROM commercial_commitments WHERE tenant_id = :t AND customer_id IS NOT NULL) "
                       "ORDER BY p.expected_quantity DESC LIMIT 1", t=tid, r=base["run_id"])[0]
    delivery = (today + timedelta(days=10)).isoformat()
    with db_engine.begin() as conn:
        sku = conn.execute(text("SELECT sku FROM products WHERE tenant_id = :t AND id = :p"), {"t": tid, "p": rel["product_id"]}).scalar_one()
        cid = conn.execute(text(
            "INSERT INTO commercial_commitments (tenant_id, customer_id, commitment_type, external_id, status, issued_at, known_at, expected_delivery_date, currency, created_at, updated_at) "
            "VALUES (:t, :c, 'order', 'PED-SINF', 'confirmed', now(), NULL, :d, 'USD', now(), now()) RETURNING id"), {"t": tid, "c": rel["customer_id"], "d": delivery}).scalar_one()
        conn.execute(text("INSERT INTO commercial_commitment_items (tenant_id, commitment_id, line_number, product_id, quantity, quantity_fulfilled, unit, created_at, updated_at) "
                          "VALUES (:t, :c, 1, :p, 33, 3, 'unidad', now(), now())"), {"t": tid, "c": cid, "p": rel["product_id"]})
        snap = {"header": {"commitment_type": "order", "external_id": "PED-SINF", "status": "confirmed", "expected_delivery_date": delivery, "currency": "USD"},
                "lines": [{"line_number": 1, "product_sku": sku, "quantity": 33, "quantity_fulfilled": 3, "unit": "unidad", "delivery_date": None}]}
        conn.execute(text("INSERT INTO commercial_commitment_revisions (tenant_id, commitment_id, revision, status, known_at, source_hash, snapshot, recorded_at) "
                          "VALUES (:t, :c, 1, 'confirmed', NULL, repeat('b', 64), CAST(:s AS jsonb), now())"), {"t": tid, "c": cid, "s": json.dumps(snap)})
    sql = ("SELECT committed_quantity FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r AND customer_id = :c AND product_id = :p AND horizon_days = 30")
    past = predict_a(db_engine, env)                                              # corte anterior a la importación
    assert float(q(db_engine, sql, t=tid, r=past["run_id"], c=rel["customer_id"], p=rel["product_id"])[0]["committed_quantity"]) == 0.0
    assert past["summary"]["committed"]["30"]["commitments_not_visible_at_cutoff"] >= 1
    now_run = run(db_engine, tid, "predict", as_of=today.isoformat())              # corte de hoy
    committed = q(db_engine, sql, t=tid, r=now_run["run_id"], c=rel["customer_id"], p=rel["product_id"])
    assert committed and float(committed[0]["committed_quantity"]) == pytest.approx(30.0)    # 33 − 3 cumplidos, entrega dentro de 30 días
    s30 = now_run["summary"]["committed"]["30"]
    assert s30["commitments_known_by_ingestion"] >= 1 and s30["lines_known_by_ingestion"] >= 1
