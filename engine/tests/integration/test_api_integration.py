"""Integración: API FastAPI + PostgreSQL con el esquema real migrado por Laravel."""

from __future__ import annotations

import pandas as pd
import pytest
from sqlalchemy import text

from conftest import AUTH
from loader import create_run
from predictily_engine.db.repository import TenantRepository, TenantScopeError

pytestmark = pytest.mark.integration

ENGINE_TABLES = ["forecast_values", "model_evaluations", "model_registry", "recommendations", "forecasting_series",
                 "forecast_actuals", "data_quality_reports", "alerts"]


def _counts(engine, tenant_id: int) -> dict[str, int]:
    with engine.connect() as conn:
        return {t: conn.execute(text(f"SELECT COUNT(*) FROM {t} WHERE tenant_id = :t"), {"t": tenant_id}).scalar_one()
                for t in ENGINE_TABLES}


def _execute(client, run_id: int, tenant_id: int, job_id: int | None, **extra):
    return client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                       json={"tenant_id": tenant_id, "training_job_id": job_id, "horizons": [7, 30, 90], **extra})


@pytest.fixture(scope="module")
def first_run(client, db_engine, tenants):
    with db_engine.begin() as conn:
        run_id, job_id = create_run(conn, tenants["A"])
    before_b = _counts(db_engine, tenants["B"])
    resp = _execute(client, run_id, tenants["A"], job_id)
    return {"run_id": run_id, "job_id": job_id, "resp": resp, "before_b": before_b}


def test_health_and_auth(client):
    assert client.get("/health").json()["db"] == "ok"
    assert client.get("/v1/capabilities").status_code == 401
    assert client.get("/v1/capabilities", headers={"Authorization": "Bearer otro"}).status_code == 401
    caps = client.get("/v1/capabilities", headers=AUTH).json()
    assert caps["foundation_models"]["timesfm"]["enabled"] is False
    r = client.post("/v1/forecast-runs/1/execute", json={"tenant_id": 1})
    assert r.status_code == 401


def test_execute_writes_versioned_results(first_run, db_engine, tenants):
    resp = first_run["resp"]
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["status"] == "completed" and body["series_count"] > 0
    assert body["metrics"]["selected_wape"] <= body["metrics"]["baseline_wape"] + 1e-9
    a, run_id = tenants["A"], first_run["run_id"]
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT * FROM forecast_runs WHERE id = :id"), {"id": run_id}).mappings().one()
        assert run["status"] == "completed" and run["is_current"] and run["cutoff_date"] is not None
        assert run["feature_set_version"] and run["selection"]["fallback"]["model_registry_id"]
        job = conn.execute(text("SELECT * FROM training_jobs WHERE id = :id"),
                           {"id": first_run["job_id"]}).mappings().one()
        assert job["status"] == "succeeded" and job["progress"] == 100 and job["duration_ms"] > 0
        levels = dict(conn.execute(text(
            "SELECT level, COUNT(*) FROM forecast_values WHERE forecast_run_id = :r AND granularity = 'day' "
            "GROUP BY level"), {"r": run_id}).all())
        assert set(levels) == {"series", "product", "category", "branch", "branch_category", "total"}
        assert levels["total"] == 90
        hz = conn.execute(text(
            "SELECT period_days, lo_80, hi_80 FROM forecast_values WHERE forecast_run_id = :r AND node_key = 'total' "
            "AND granularity = 'horizon' ORDER BY period_days"), {"r": run_id}).all()
        assert [h[0] for h in hz] == [7, 30, 90] and all(h[1] is not None for h in hz)
        reg = conn.execute(text("SELECT model_family, status, role, core, training_data_hash, artifact_path, random_seed "
                                "FROM model_registry WHERE forecast_run_id = :r"), {"r": run_id}).mappings().all()
        statuses = {r["model_family"]: r["status"] for r in reg}
        # Estados de la etapa 2 (candidate | validated | production | retired | failed) y rol primary | fallback.
        assert set(statuses.values()) <= {"candidate", "validated", "production", "failed"}
        assert any(r["status"] == "production" and r["role"] == "primary" for r in reg)
        assert any(r["status"] == "production" and r["role"] == "fallback" for r in reg) or \
            any(r["role"] == "primary" for r in reg)
        assert {r["core"] for r in reg} == {"demand"}
        assert all(r["training_data_hash"] and r["random_seed"] == 42 for r in reg)
        if "lightgbm_global" in statuses and statuses["lightgbm_global"] == "production":
            assert any(r["artifact_path"] for r in reg if r["model_family"] == "lightgbm_global")
        ev = conn.execute(text(
            "SELECT model_family, is_selected, is_baseline FROM model_evaluations WHERE forecast_run_id = :r "
            "AND evaluation_type = 'backtest' AND level = 'overall'"), {"r": run_id}).mappings().all()
        assert any(e["model_family"] == "selection" and e["is_selected"] for e in ev)
        assert any(e["model_family"] == "seasonal_naive" and e["is_baseline"] for e in ev)
        n_recs = conn.execute(text("SELECT COUNT(*) FROM recommendations WHERE forecast_run_id = :r"),
                              {"r": run_id}).scalar_one()
        assert n_recs > 0
        dq = conn.execute(text("SELECT score FROM data_quality_reports WHERE forecast_run_id = :r"),
                          {"r": run_id}).scalar_one()
        assert dq is not None
        assert conn.execute(text("SELECT COUNT(*) FROM forecast_features WHERE tenant_id IS NULL")).scalar_one() > 10


def test_tenant_isolation(first_run, db_engine, tenants, client):
    a, b = tenants["A"], tenants["B"]
    # La ejecución de A no escribió nada para B.
    assert _counts(db_engine, b) == first_run["before_b"]
    with db_engine.connect() as conn:
        a_products = {r[0] for r in conn.execute(text("SELECT id FROM products WHERE tenant_id = :t"), {"t": a})}
        a_branches = {r[0] for r in conn.execute(text("SELECT id FROM branches WHERE tenant_id = :t"), {"t": a})}
        fv_products = {r[0] for r in conn.execute(text(
            "SELECT DISTINCT product_id FROM forecast_values WHERE forecast_run_id = :r AND product_id IS NOT NULL"),
            {"r": first_run["run_id"]})}
        fv_branches = {r[0] for r in conn.execute(text(
            "SELECT DISTINCT branch_id FROM forecast_values WHERE forecast_run_id = :r AND branch_id IS NOT NULL"),
            {"r": first_run["run_id"]})}
        assert fv_products <= a_products and fv_branches <= a_branches
        tenants_written = {r[0] for r in conn.execute(text(
            "SELECT DISTINCT tenant_id FROM forecast_values WHERE forecast_run_id = :r"), {"r": first_run["run_id"]})}
        assert tenants_written == {a}
        # El repositorio rechaza consultas sin filtro de empresa.
        repo = TenantRepository(conn, a)
        with pytest.raises(TenantScopeError):
            repo._read("SELECT * FROM sales")
        with pytest.raises(TenantScopeError):
            TenantRepository(conn, 0)
        # Los datos cargados para A solo contienen series de A.
        sales = repo.daily_sales(pd.Timestamp("2030-01-01").date())
        assert set(sales["product_id"]) <= a_products
    # Pedir el run de A diciendo ser B → 404 (no revela existencia).
    resp = _execute(client, first_run["run_id"], b, None)
    assert resp.status_code == 404


def test_idempotent_reexecution(first_run, client, db_engine, tenants):
    with db_engine.connect() as conn:
        before = conn.execute(text("SELECT COUNT(*) FROM forecast_values WHERE forecast_run_id = :r"),
                              {"r": first_run["run_id"]}).scalar_one()
    resp = _execute(client, first_run["run_id"], tenants["A"], first_run["job_id"])
    assert resp.status_code == 200 and resp.json()["status"] == "completed"
    with db_engine.connect() as conn:
        after = conn.execute(text("SELECT COUNT(*) FROM forecast_values WHERE forecast_run_id = :r"),
                             {"r": first_run["run_id"]}).scalar_one()
    assert before == after


def test_concurrent_execution_conflict(client, db_engine, tenants):
    with db_engine.begin() as conn:
        run_id, job_id = create_run(conn, tenants["B"], status="running")
        conn.execute(text("UPDATE forecast_runs SET started_at = now() WHERE id = :r"), {"r": run_id})
    assert _execute(client, run_id, tenants["B"], job_id).status_code == 409
    with db_engine.begin() as conn:
        conn.execute(text("DELETE FROM forecast_runs WHERE id = :r"), {"r": run_id})


def test_second_run_becomes_current_and_archives_models(first_run, client, db_engine, tenants):
    a = tenants["A"]
    with db_engine.begin() as conn:
        run2, job2 = create_run(conn, a)
    assert _execute(client, run2, a, job2).status_code == 200
    with db_engine.connect() as conn:
        current = conn.execute(text("SELECT id FROM forecast_runs WHERE tenant_id = :t AND is_current"),
                               {"t": a}).scalars().all()
        assert current == [run2]
        old = conn.execute(text("SELECT DISTINCT status FROM model_registry WHERE forecast_run_id = :r"),
                           {"r": first_run["run_id"]}).scalars().all()
        assert set(old) <= {"retired", "validated"}, "los modelos de producción anteriores pasan a retired"
        assert "production" not in old


def test_historical_run_and_evaluate_actuals(client, db_engine, tenants):
    a = tenants["A"]
    with db_engine.connect() as conn:
        last = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": a}).scalar_one()
    cutoff = (pd.Timestamp(last) - pd.Timedelta(days=30)).date().isoformat()
    with db_engine.begin() as conn:
        run_id, job_id = create_run(conn, a, run_type="historical", cutoff=cutoff)
    resp = _execute(client, run_id, a, job_id)
    assert resp.status_code == 200, resp.text
    assert resp.json()["cutoff_date"] == cutoff
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT is_current, run_type FROM forecast_runs WHERE id = :r"),
                           {"r": run_id}).mappings().one()
        assert not run["is_current"]
    with db_engine.begin() as conn:
        _, eval_job = create_run(conn, a)
        conn.execute(text("UPDATE training_jobs SET job_type = 'evaluate_actuals', forecast_run_id = NULL "
                          "WHERE id = :j"), {"j": eval_job})
    resp = client.post(f"/v1/tenants/{a}/evaluate-actuals", headers=AUTH,
                       json={"training_job_id": eval_job, "lookback_runs": 10})
    assert resp.status_code == 200, resp.text
    body = resp.json()
    hist = [e for e in body["evaluated_runs"] if e["run_id"] == run_id]
    assert hist and hist[0]["days_observed"] == 30 and hist[0]["wape"] is not None
    with db_engine.connect() as conn:
        n = conn.execute(text("SELECT COUNT(*) FROM forecast_actuals WHERE forecast_run_id = :r AND level = 'total'"),
                         {"r": run_id}).scalar_one()
        assert n == 30
        realized = conn.execute(text("SELECT COUNT(*) FROM model_evaluations WHERE forecast_run_id = :r "
                                     "AND evaluation_type = 'realized'"), {"r": run_id}).scalar_one()
        assert realized >= 2
    # Reevaluar es idempotente.
    again = client.post(f"/v1/tenants/{a}/evaluate-actuals", headers=AUTH, json={"lookback_runs": 10})
    assert again.status_code == 200
    with db_engine.connect() as conn:
        assert conn.execute(text("SELECT COUNT(*) FROM forecast_actuals WHERE forecast_run_id = :r AND level = 'total'"),
                            {"r": run_id}).scalar_one() == 30


def test_readiness(client, db_engine, tenants):
    resp = client.post(f"/v1/tenants/{tenants['B']}/readiness", headers=AUTH, json={})
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert 0 <= body["score"] <= 100 and body["series_total"] > 0 and body["has_inventory"]
    assert body["data_quality_report_id"]
    assert client.post("/v1/tenants/999999/readiness", headers=AUTH, json={}).status_code == 404


def test_insufficient_data_returns_422(client, db_engine):
    with db_engine.begin() as conn:
        t = conn.execute(text("INSERT INTO tenants (uuid, name, settings, created_at, updated_at) VALUES "
                              "(gen_random_uuid(), 'Empresa vacía (prueba)', '{}'::jsonb, now(), now()) RETURNING id")
                         ).scalar_one()
        run_id, job_id = create_run(conn, t)
    resp = _execute(client, run_id, t, job_id)
    assert resp.status_code == 422 and resp.json()["code"] == "insufficient_data"
    with db_engine.connect() as conn:
        status = conn.execute(text("SELECT status, error_message FROM forecast_runs WHERE id = :r"),
                              {"r": run_id}).one()
        assert status[0] == "failed" and status[1]
        assert conn.execute(text("SELECT status FROM training_jobs WHERE id = :j"), {"j": job_id}).scalar_one() == "failed"
