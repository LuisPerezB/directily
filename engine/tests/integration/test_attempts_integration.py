"""`training_jobs.attempts` cuenta UNA vez por intento real, y lo cuenta solo el backend (Laravel registra cada intento de la cola).

Antes el motor volvía a sumar al arrancar la ejecución: un trabajo ejecutado una vez informaba `attempts = 2`, y con un reintento
`4`. Aquí se imita a Laravel (`markAttempt` = `attempts + 1` antes de llamar al motor) y se comprueba que el motor no suma.
"""

from __future__ import annotations

import json
from datetime import timedelta

import pytest
from _supply_db import add_core_run, new_tenant
from customer_fixtures import create_core_run, load_world
from loader import create_run, load_company
from sqlalchemy import text

from conftest import AUTH
from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration


def _backend_marks_attempt(engine, job_id: int) -> None:
    """Lo que hace `TrainingJobRecorder::markAttempt` en Laravel antes de llamar al motor."""
    with engine.begin() as conn:
        conn.execute(text("UPDATE training_jobs SET attempts = attempts + 1 WHERE id = :j"), {"j": job_id})


def _attempts(engine, job_id: int) -> int:
    with engine.connect() as conn:
        return int(conn.execute(text("SELECT attempts FROM training_jobs WHERE id = :j"), {"j": job_id}).scalar_one())


def test_a_forecast_job_retried_once_counts_two_attempts_not_four(client, db_engine):
    co = generate_company("ferreteria_andina", seed=42, n_products=5, start="2026-03-01")
    with db_engine.begin() as conn:
        tenant = load_company(conn, co, "Empresa intentos (prueba ficticia)")
        run_id, job_id = create_run(conn, tenant)
        conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"),
                     {"s": json.dumps({"cores": {"demand": {"inventa_algo": 1}}}), "t": tenant})
    # Intento 1: Laravel lo registra y el motor lo rechaza (configuración inválida) → falla.
    _backend_marks_attempt(db_engine, job_id)
    r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH, json={"tenant_id": tenant, "training_job_id": job_id, "horizons": [7, 30]})
    assert r.status_code == 422
    assert _attempts(db_engine, job_id) == 1, "un intento fallido cuenta una vez"
    # Intento 2 (reintento de la cola): se corrige la configuración y esta vez termina.
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE tenants SET settings = '{}'::jsonb WHERE id = :t"), {"t": tenant})
    _backend_marks_attempt(db_engine, job_id)
    r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH, json={"tenant_id": tenant, "training_job_id": job_id, "horizons": [7, 30]})
    assert r.status_code == 200, r.text
    with db_engine.connect() as conn:
        job = conn.execute(text("SELECT status, attempts FROM training_jobs WHERE id = :j"), {"j": job_id}).mappings().one()
    assert job["status"] == "succeeded" and job["attempts"] == 2


def test_the_engine_never_adds_attempts_for_customer_supply_readiness_and_actuals_jobs(client, db_engine):
    world = generate_world(seed=11, n_customers=15, n_products=3, n_days=120, mean_interval_range=(40, 200))
    with db_engine.begin() as conn:
        c = load_world(conn, world, "Empresa intentos comprador (ficticia)")["tenant_id"]
        s = new_tenant(conn, "Empresa intentos abastecimiento (ficticia)")
        crid, cjid = create_core_run(conn, c, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
        srid, sjid = add_core_run(conn, s, "scenario", "evaluate", {"strategies": [{"key": "base"}]})
        _, rjid = create_run(conn, c)
        _, ejid = create_run(conn, c)
        conn.execute(text("UPDATE training_jobs SET job_type = 'readiness', forecast_run_id = NULL WHERE id = :j"), {"j": rjid})
        conn.execute(text("UPDATE training_jobs SET job_type = 'evaluate_actuals', forecast_run_id = NULL WHERE id = :j"), {"j": ejid})
    for jid in (cjid, sjid, rjid, ejid):                       # Laravel ya registró el intento 1 de cada uno
        _backend_marks_attempt(db_engine, jid)

    assert execute_customer_run(crid, CustomerExecuteRequest(tenant_id=c, training_job_id=cjid))["status"] == "completed"
    r = client.post(f"/v1/supply/runs/{srid}/execute", json={"tenant_id": s, "training_job_id": sjid}, headers=AUTH)
    assert r.status_code == 200, r.text
    r = client.post(f"/v1/tenants/{c}/readiness", headers=AUTH, json={"training_job_id": rjid})
    assert r.status_code == 200, r.text
    r = client.post(f"/v1/tenants/{c}/evaluate-actuals", headers=AUTH, json={"training_job_id": ejid, "lookback_runs": 3})
    assert r.status_code == 200, r.text

    assert {name: _attempts(db_engine, jid) for name, jid in (("customer", cjid), ("supply", sjid), ("readiness", rjid), ("actuals", ejid))} == {
        "customer": 1, "supply": 1, "readiness": 1, "actuals": 1}
