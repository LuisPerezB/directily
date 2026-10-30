"""Integración P4 · plan de reposición, ofertas, presupuesto, aislamiento e idempotencia (BD aislada, datos ficticios)."""

from __future__ import annotations

import math
from datetime import date

import pytest
from _supply_db import (
    add_branch,
    add_budget,
    add_conversion,
    add_core_run,
    add_forecast,
    add_fx,
    add_inventory,
    add_offer,
    add_product,
    add_supplier,
    new_tenant,
)
from sqlalchemy import text

pytestmark = pytest.mark.integration
AUTH = {"Authorization": "Bearer test-token"}
CUT = date(2026, 10, 26)


def execute(client, run_id, tenant_id, job_id=None):
    return client.post(f"/v1/supply/runs/{run_id}/execute", json={"tenant_id": tenant_id, "training_job_id": job_id}, headers=AUTH)


@pytest.fixture()
def world(db_engine):
    """Empresa A con ofertas comparables y no comparables, presupuesto y pronóstico; empresa B aparte."""
    with db_engine.begin() as conn:
        A = new_tenant(conn, "P4 Empresa A (ficticia)")
        b = add_branch(conn, A)
        p1 = add_product(conn, A, "P1", "u", price=5.0, cost=2.0)
        s1, s2 = add_supplier(conn, A, "S1"), add_supplier(conn, A, "S2")
        add_inventory(conn, A, b, p1, CUT, on_hand=10.0)
        add_forecast(conn, A, CUT, {(b, p1): [10.0] * 90})
        add_fx(conn, A, "EUR", "USD", 1.1, date(2026, 10, 1), "banco (ficticio)")
        add_conversion(conn, A, p1, "caja", "u", 12.0)
        o1 = add_offer(conn, A, s1, p1, 3.0, "USD", "u", lead=1)
        o2 = add_offer(conn, A, s2, p1, 24.0, "EUR", "caja", lead=21, moq=10.0, multiple=1.0, capacity=50.0)
        o3 = add_offer(conn, A, s1, p1, 2.5, "GBP", "u", lead=5)  # sin tasa: no comparable
        o4 = add_offer(conn, A, s2, p1, 90.0, "USD", "pallet", lead=5)  # sin conversión: no comparable
        bud = add_budget(conn, A, CUT, CUT, 500.0)
        B = new_tenant(conn, "P4 Empresa B (ficticia)")
        bb = add_branch(conn, B)
        pb = add_product(conn, B, "P1", "u", price=5.0, cost=2.0)
        add_inventory(conn, B, bb, pb, CUT, on_hand=1.0)
        add_forecast(conn, B, CUT, {(bb, pb): [20.0] * 90})
        sb = add_supplier(conn, B, "SB")
        add_offer(conn, B, sb, pb, 1.0, "USD", "u", lead=1)
    return {"A": A, "B": B, "branch": b, "p1": p1, "offers": (o1, o2, o3, o4), "budget": bud, "B_branch": bb, "B_product": pb}


def run_plan(db_engine, client, tenant, options=None):
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, tenant, "supply", "plan", options)
    r = execute(client, rid, tenant, jid)
    assert r.status_code == 200, r.text
    return rid, jid, r.json()


def test_plan_respects_budget_lots_minimums_and_declares_comparison_coverage(db_engine, client, world):
    A = world["A"]
    rid, jid, body = run_plan(db_engine, client, A)
    assert body["status"] == "completed" and body["quality_status"] in ("ok", "degraded")
    with db_engine.connect() as conn:
        plan = conn.execute(text("SELECT * FROM supply_plans WHERE tenant_id = :t AND core_run_id = :r"), {"t": A, "r": rid}).mappings().one()
        lines = conn.execute(text("SELECT * FROM supply_plan_lines WHERE tenant_id = :t AND supply_plan_id = :p ORDER BY order_date, id"),
                             {"t": A, "p": plan["id"]}).mappings().all()
        run = conn.execute(text("SELECT * FROM core_runs WHERE id = :r"), {"r": rid}).mappings().one()
        job = conn.execute(text("SELECT status, progress FROM training_jobs WHERE id = :j"), {"j": jid}).mappings().one()
    assert plan["solver_status"] in ("optimal", "feasible_with_gap")
    assert job["status"] == "succeeded" and job["progress"] == 100
    # Envoltura común
    assert run["result_type"] == "optimized_plan" and run["core"] == "supply" and run["config_version"]
    assert run["data_snapshot_id"] is not None and run["as_of"] == CUT
    assert run["assumptions"] and run["uncertainty_source"] in ("none", "unknown", "empirical_holdout", "conformal")
    # Ninguna línea usa ofertas no comparables y las cantidades respetan lotes y mínimos (verificado desde la BD)
    o1, o2, o3, o4 = world["offers"]
    used = {ln["offer_id"] for ln in lines}
    assert used <= {o1, o2} and used  # o3 (sin tasa) y o4 (sin conversión) quedan fuera
    for ln in lines:
        if ln["offer_id"] == o2:
            assert float(ln["quantity"]) % 12.0 == pytest.approx(0.0, abs=1e-6)  # lote = 1 caja = 12 u.
            assert float(ln["quantity"]) >= 120.0 - 1e-6  # mínimo 10 cajas
            assert float(ln["unit_cost"]) == pytest.approx(24.0 / 12.0 * 1.1, rel=1e-6)  # EUR → USD con tasa explícita
            assert (ln["expected_receipt_date"] - ln["order_date"]).days == 21
        if ln["offer_id"] == o1:
            assert float(ln["unit_cost"]) == pytest.approx(3.0)
    spent_today = sum(float(ln["line_cost"]) for ln in lines if ln["order_date"] == CUT)
    assert spent_today <= 500.0 + 1e-6  # presupuesto de la fecha de emisión de hoy
    assert plan["details"]["report"]["comparison_coverage"]["offers_received"] == 4
    assert plan["details"]["report"]["comparison_coverage"]["offers_excluded"] == 2
    reasons = {e["offer_id"]: e["reasons"] for e in plan["details"]["offers"] if e["status"] == "excluded"}
    assert reasons[o3] == ["no_fx_rate"] and reasons[o4] == ["no_unit_conversion"]
    # La demanda no atendida del período 0 se declara (presupuesto/plazo), no se oculta
    with db_engine.connect() as conn:
        unmet = conn.execute(text("SELECT COALESCE(SUM(unmet), 0) FROM supply_plan_shortfalls WHERE tenant_id = :t AND supply_plan_id = :p"),
                             {"t": A, "p": plan["id"]}).scalar_one()
    assert float(unmet) == pytest.approx(float(plan["unmet_units_total"]), rel=1e-6)


def test_execution_is_idempotent_and_does_not_duplicate_lines(db_engine, client, world):
    A = world["A"]
    rid, jid, first = run_plan(db_engine, client, A)
    with db_engine.connect() as conn:
        n1 = conn.execute(text("SELECT COUNT(*) FROM supply_plan_lines WHERE tenant_id = :t"), {"t": A}).scalar_one()
        plans1 = conn.execute(text("SELECT COUNT(*) FROM supply_plans WHERE tenant_id = :t"), {"t": A}).scalar_one()
    again = execute(client, rid, A, jid)
    assert again.status_code == 200 and again.json()["status"] == "completed"
    with db_engine.connect() as conn:
        assert conn.execute(text("SELECT COUNT(*) FROM supply_plan_lines WHERE tenant_id = :t"), {"t": A}).scalar_one() == n1
        assert conn.execute(text("SELECT COUNT(*) FROM supply_plans WHERE tenant_id = :t"), {"t": A}).scalar_one() == plans1


def test_tenant_isolation_in_runs_and_plans(db_engine, client, world):
    A, B = world["A"], world["B"]
    rid_a, _, _ = run_plan(db_engine, client, A)
    # La empresa B no puede ejecutar ni ver la corrida de A (404, sin revelar que existe)
    r = execute(client, rid_a, B)
    assert r.status_code == 404
    rid_b, _, _ = run_plan(db_engine, client, B)
    with db_engine.connect() as conn:
        # Las líneas de B solo referencian productos/sucursales/ofertas de B
        rows = conn.execute(text(
            "SELECT l.product_id, l.branch_id, l.offer_id FROM supply_plan_lines l JOIN supply_plans p ON p.id = l.supply_plan_id "
            "WHERE p.core_run_id = :r"), {"r": rid_b}).all()
        assert rows and all(r.product_id == world["B_product"] and r.branch_id == world["B_branch"] for r in rows)
        offers_b = {r[0] for r in conn.execute(text("SELECT id FROM supplier_offers WHERE tenant_id = :t"), {"t": B})}
        assert all(r.offer_id in offers_b for r in rows)
        # Los planes de A no cambiaron al correr B
        assert conn.execute(text("SELECT COUNT(*) FROM supply_plans WHERE tenant_id = :t"), {"t": A}).scalar_one() == 1
        # Composite FK: no se puede insertar una línea de A apuntando a un plan de B
        with pytest.raises(Exception):
            with db_engine.begin() as c2:
                c2.execute(text("INSERT INTO supply_plan_lines (tenant_id, supply_plan_id, line_type, branch_id, product_id, quantity, order_date, "
                                "expected_receipt_date, currency) SELECT :a, id, 'purchase', :b, :p, 1, DATE '2026-10-26', DATE '2026-10-27', 'USD' "
                                "FROM supply_plans WHERE core_run_id = :r"),
                           {"a": A, "b": world["branch"], "p": world["p1"], "r": rid_b})


def test_no_forecast_means_insufficient_data_not_an_empty_success(db_engine, client):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "P4 Sin pronóstico (ficticia)")
    rid, _, body = run_plan(db_engine, client, t)
    assert body["quality_status"] == "insufficient_data"
    with db_engine.connect() as conn:
        assert conn.execute(text("SELECT COUNT(*) FROM supply_plan_lines WHERE tenant_id = :t"), {"t": t}).scalar_one() == 0


def test_inventory_unknown_is_excluded_not_assumed_zero(db_engine, client):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "P4 Sin inventario (ficticia)")
        b = add_branch(conn, t)
        p = add_product(conn, t, "P1")
        s = add_supplier(conn, t, "S")
        add_forecast(conn, t, CUT, {(b, p): [10.0] * 60})
        add_offer(conn, t, s, p, 2.0, lead=3)
    rid, _, body = run_plan(db_engine, client, t)
    assert body["quality_status"] == "insufficient_data"
    with db_engine.connect() as conn:
        details = conn.execute(text("SELECT details FROM supply_plans WHERE core_run_id = :r"), {"r": rid}).scalar_one()
    assert details["report"]["excluded_nodes"][0]["reason"] == "no_inventory"


def test_budget_in_currency_without_rate_blocks_the_plan(db_engine, client):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "P4 Presupuesto EUR (ficticia)")
        b = add_branch(conn, t)
        p = add_product(conn, t, "P1")
        s = add_supplier(conn, t, "S")
        add_inventory(conn, t, b, p, CUT, 5.0)
        add_forecast(conn, t, CUT, {(b, p): [10.0] * 60})
        add_offer(conn, t, s, p, 2.0, lead=1)
        add_budget(conn, t, CUT, CUT, 100.0, "EUR")
    rid, _, body = run_plan(db_engine, client, t)
    assert body["quality_status"] == "insufficient_data"
    with db_engine.connect() as conn:
        lim = conn.execute(text("SELECT limitations FROM core_runs WHERE id = :r"), {"r": rid}).scalar_one()
        assert conn.execute(text("SELECT COUNT(*) FROM supply_plan_lines WHERE tenant_id = :t"), {"t": t}).scalar_one() == 0
    assert any("tasa de cambio" in x for x in lim)
