"""W2-E · horizonte de 15 días de punta a punta (ADR-V25-02), contra PostgreSQL.

Demanda: H15 se calcula, evalúa y publica por el MISMO camino que los demás horizontes (backtest as-of por ventana y período final
independiente de W1); no hay un camino paralelo. Customer: H15 entrena, predice y se abstiene con motivo cuando no hay modelo.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import timedelta

import numpy as np
import pandas as pd
import pytest
from asof_helpers import World, add_document, amend_document, local
from sqlalchemy import text

from conftest import AUTH
from customer_fixtures import create_core_run, load_world
from loader import create_run
from predictily_engine.backtest.rolling import plan_backtest
from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.db.repository import TenantRepository
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import load_tenant_data
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel

pytestmark = pytest.mark.integration


# ───────────────────────────── demanda ─────────────────────────────

def _execute(client, engine, tenant_id: int, horizons: list[int]) -> tuple[int, dict]:
    with engine.begin() as conn:
        run_id, job_id = create_run(conn, tenant_id)
    r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                    json={"tenant_id": tenant_id, "training_job_id": job_id, "horizons": horizons})
    assert r.status_code == 200, r.text
    return run_id, r.json()


@pytest.fixture(scope="module")
def run15(client, db_engine, tenants):
    """Empresa A (≈540 días de historia): ejecución con los horizontes de Growth/Enterprise (7, 15 y 30)."""
    return (*_execute(client, db_engine, tenants["A"], [7, 15, 30]), tenants["A"])


def test_horizon_15_is_published_with_its_status_and_intervals(run15, db_engine):
    run_id, body, _ = run15
    assert body["status"] == "completed" and body["quality_status"] == "ok"
    assert set(body["horizon_support"]) == {"7", "14", "15", "30"}, "se respalda cada horizonte del dominio hasta el pedido"
    assert body["horizon_support"]["15"] == "ok"
    with db_engine.connect() as conn:
        fv = pd.read_sql(text("SELECT * FROM forecast_values WHERE forecast_run_id = :r AND node_key = 'total' AND granularity = 'horizon'"),
                         conn, params={"r": run_id}).sort_values("period_days")
        cutoff = conn.execute(text("SELECT cutoff_date FROM forecast_runs WHERE id = :r"), {"r": run_id}).scalar_one()
    assert fv["period_days"].tolist() == [7, 15, 30]
    row = fv[fv["period_days"] == 15].iloc[0]
    assert row["quality_status"] == "ok" and row[["lo_80", "hi_80", "lo_95", "hi_95"]].notna().all()
    assert pd.Timestamp(row["target_date"]) == pd.Timestamp(cutoff) + pd.Timedelta(days=15) and row["horizon_step"] == 15
    assert fv.loc[fv["period_days"] == 7, "yhat"].iat[0] < row["yhat"] < fv.loc[fv["period_days"] == 30, "yhat"].iat[0]


def test_horizon_15_is_evaluated_on_the_independent_holdout_like_every_other_horizon(run15, db_engine):
    run_id, _, _ = run15
    with db_engine.connect() as conn:
        rows = conn.execute(text(
            "SELECT model_family, evaluation_type, horizon_days, n_obs, extra, metrics FROM model_evaluations "
            "WHERE forecast_run_id = :r AND level = 'horizon_total' AND level_key = 'H15'"), {"r": run_id}).mappings().all()
        support = conn.execute(text("SELECT summary->'horizon_support'->'15' FROM forecast_runs WHERE id = :r"), {"r": run_id}).scalar_one()
    backtest = [r for r in rows if r["evaluation_type"] == "backtest"]
    assert {r["model_family"] for r in backtest} >= {"selection", "seasonal_naive"}, "el modelo servido y la referencia se miden igual"
    for r in backtest:
        assert r["horizon_days"] == 15 and r["n_obs"] > 0
        assert r["extra"]["independent_holdout"] is True and r["extra"]["evaluation_scope"] == "holdout"
    assert {r["evaluation_type"] for r in rows} <= {"backtest", "selection_windows"}, "mismos tipos de evaluación que los demás horizontes"
    assert support["status"] == "ok" and support["reasons"] == [] and support["evaluated_obs"] > 0
    assert support["independent_holdout"] is True and support["backtest_horizon_days"] >= 15


def test_horizon_15_evaluation_does_not_know_what_was_learned_after_the_holdout_origin(db_engine, tenants):
    """Anulación y documento tardío CONOCIDOS después del origen del período final (con fecha de evento anterior a él): el backtest as-of
    de W1 no los conoce, así que ningún número evaluado de H15 cambia; el pronóstico de producción (que sí los conoce) cambia: la prueba
    discrimina. Es la misma garantía de `test_the_holdout_window_trains_with_what_was_known_at_its_origin`, ahora observada en H15."""
    @contextmanager
    def rolled_back():
        with db_engine.connect() as conn:
            tx = conn.begin()
            try:
                yield conn
            finally:
                tx.rollback()

    def reading(conn, w):
        repo = TenantRepository(conn, w.tenant_id)
        repo.analytic_reads()
        return repo

    ids = lambda conn, sql, t: [int(r[0]) for r in conn.execute(text(sql), {"t": t})]  # noqa: E731
    with rolled_back() as conn:
        t = tenants["A"]
        w = World(t, ids(conn, "SELECT id FROM branches WHERE tenant_id = :t ORDER BY id", t),
                  ids(conn, "SELECT id FROM products WHERE tenant_id = :t ORDER BY id", t), [], None)
        repo = reading(conn, w)
        cutoff = repo.last_sale_date()
        plan = plan_backtest(build_panel(load_tenant_data(repo, cutoff), DayRules()), 3)
        assert plan is not None and plan.has_holdout and plan.horizon >= 15, "la empresa de pruebas puede evaluar ventanas de 15 días"
        h0 = plan.holdout_cutoff
        day = lambda off: (h0 + pd.Timedelta(days=off)).date().isoformat()  # noqa: E731

        add_document(conn, w, number="W2E-X", sale_date=day(-6), lines=[(0, 300, 10.0)])
        base = run_pipeline(load_tenant_data(reading(conn, w), cutoff), horizon=30, seed=42, n_jobs=2, horizons=(7, 15, 30))
        amend_document(conn, w, "W2E-X", known_from=local(day(5), 12), status="voided")
        add_document(conn, w, number="W2E-L", sale_date=day(-9), lines=[(1, 400, 10.0)], mode="incremental", known_from=local(day(3), 9))
        pert_data = load_tenant_data(reading(conn, w), cutoff)
        assert pert_data.sales_versions.has_dynamic
        pert = run_pipeline(pert_data, horizon=30, seed=42, n_jobs=2, horizons=(7, 15, 30))

    def h15(res):
        ev = pd.DataFrame(res.evaluations)
        return ev[(ev["level"] == "horizon_total") & (ev["level_key"] == "H15")].set_index(["evaluation_type", "model_family"]).sort_index()

    b, p = h15(base), h15(pert)
    assert len(b) == len(p) > 0 and ("backtest", "selection") in b.index
    for col in ("wape", "mae", "bias", "n_obs"):
        np.testing.assert_allclose(p[col].astype(float), b[col].astype(float), rtol=1e-9, atol=1e-9, err_msg=col)
    holdout = lambda res: res.backtest[res.backtest["is_holdout"]].set_index(["unique_id", "ds", "family"]).sort_index()  # noqa: E731
    np.testing.assert_allclose(holdout(pert)["yhat"], holdout(base)["yhat"], rtol=1e-9, atol=1e-9)
    # El número publicado de H15 sí cambia con lo conocido hasta el corte final: la perturbación fue material.
    total15 = lambda res: float(res.forecast_values.query("granularity == 'horizon' and level == 'total' and period_days == 15")["yhat"].iat[0])  # noqa: E731
    assert abs(total15(pert) - total15(base)) > 1e-6


def test_the_service_capabilities_declare_the_catalog(client):
    caps = client.get("/v1/capabilities", headers=AUTH).json()
    assert caps["horizons"] == [7, 14, 15, 30, 60, 90] and caps["horizon_catalog"] == [7, 14, 15, 30, 60, 90]
    assert "coverage_insufficient" in caps["horizon_rule"] and "insufficient_horizon" in caps["horizon_rule"]
    assert client.get("/v1/customer/capabilities", headers=AUTH).json()["horizons"] == [7, 14, 15, 30, 90]
    r = client.post("/v1/forecast-runs/1/execute", headers=AUTH, json={"tenant_id": 1, "horizons": [21]})
    assert r.status_code == 422, "un horizonte fuera del catálogo se rechaza"
    r = client.post("/v1/forecast-runs/1/execute", headers=AUTH, json={"tenant_id": 1, "horizons": [15]})
    assert r.status_code != 422, "15 sí es del catálogo (404 si la ejecución no existe, pero no 422 de validación)"


# ───────────────────────────── Customer ─────────────────────────────

def _customer_run(engine, tenant_id: int, operation: str, **kw) -> dict:
    with engine.begin() as conn:
        run_id, job_id = create_core_run(conn, tenant_id, operation, **kw)
    out = execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))
    out["_job_id"] = job_id
    return out


def _q(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


@pytest.fixture(scope="module")
def customer_env(db_engine):
    world = generate_world(seed=7, n_customers=140, n_products=8, n_days=440, mean_interval_range=(20, 200), churn_share=0.1)
    with db_engine.begin() as conn:
        a = load_world(conn, world, "Empresa H15 (ficticia, W2-E)")
    tid = a["tenant_id"]
    train = _customer_run(db_engine, tid, "train", horizons=[7, 15])
    as_of = (world.start + timedelta(days=world.n_days)).isoformat()
    # 14 está en el catálogo de Customer pero NO se entrenó: la predicción debe abstenerse de ese horizonte, no inventarlo.
    predict = _customer_run(db_engine, tid, "predict", horizons=[7, 14, 15], as_of=as_of)
    return {"tid": tid, "train": train, "predict": predict}


def test_customer_trains_h15_with_its_own_slot_manifest_and_evaluation(db_engine, customer_env):
    tid, rid = customer_env["tid"], customer_env["train"]["run_id"]
    summary = customer_env["train"]["summary"]["horizons"]
    assert set(summary) == {"7", "15"} and summary["15"]["status"] in ("trained", "reference_only")
    manifest = _q(db_engine, "SELECT evaluation_manifest FROM core_runs WHERE id = :r AND tenant_id = :t", r=rid, t=tid)[0]["evaluation_manifest"]
    h15 = manifest["horizons"]["15"]
    assert h15["horizon_days"] == 15 and h15["status"] in ("trained", "reference_only")
    assert manifest["separation"] and h15["blocks"]["gap_days_between_blocks"] == 15, "la separación entre bloques es de H = 15 días"
    slots = {r["hyperparameters"]["slot"] for r in _q(
        db_engine, "SELECT hyperparameters FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production'", t=tid)}
    assert {"h15:probability", "h15:quantity"} <= slots and not any(s.startswith("h14:") for s in slots)
    keys = {r["level_key"] for r in _q(db_engine, "SELECT level_key FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r", t=tid, r=rid)}
    assert {"h7:probability", "h15:probability", "h15:expected_quantity", "coherence"} <= keys


def test_customer_predicts_h15_coherently_and_abstains_from_the_untrained_h14(db_engine, customer_env):
    tid, rid = customer_env["tid"], customer_env["predict"]["run_id"]
    assert customer_env["predict"]["summary"]["horizons"] == [7, 15], "el horizonte sin modelo no se sirve"
    counts = {r["horizon_days"]: r["n"] for r in _q(
        db_engine, "SELECT horizon_days, count(*) AS n FROM customer_predictions WHERE tenant_id = :t AND core_run_id = :r GROUP BY 1", t=tid, r=rid)}
    assert set(counts) == {7, 15} and counts[7] == counts[15] > 0, "mismo universo comprador × producto en cada horizonte servido"
    # Coherencia entre horizontes: P(compra en 15 días) ≥ P(compra en 7 días) para la misma relación (regla del máximo acumulado).
    rows = pd.DataFrame(_q(db_engine, "SELECT customer_id, product_id, horizon_days, purchase_probability FROM customer_predictions "
                                      "WHERE tenant_id = :t AND core_run_id = :r", t=tid, r=rid))
    wide = rows.pivot_table(index=["customer_id", "product_id"], columns="horizon_days", values="purchase_probability")
    assert (wide[15] + 1e-9 >= wide[7]).all()
    support = customer_env["predict"]["summary"]["horizon_support"]
    assert support["14"]["status"] == "insufficient_data" and support["14"]["reasons"][0]["reason_code"] == "no_model"
    assert support["7"]["status"] in ("ok", "degraded") and support["15"]["status"] in ("ok", "degraded")
    assert customer_env["predict"]["quality_status"] == "degraded", "un horizonte pedido sin respaldo impide el estado ok"
    limitations = _q(db_engine, "SELECT limitations FROM core_runs WHERE id = :r", r=rid)[0]["limitations"]
    assert any("H=14" in lim and "abstención" in lim for lim in limitations)
    assert _q(db_engine, "SELECT count(*) AS n FROM customer_predictions WHERE tenant_id = :t AND horizon_days = 14", t=tid)[0]["n"] == 0
