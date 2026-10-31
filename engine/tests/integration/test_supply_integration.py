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


@pytest.fixture(scope="module")
def synthetic_tenant(db_engine):
    """Empresa sintética propia de este módulo (independiente del estado compartido y del orden de las pruebas)."""
    from loader import load_company

    from predictily_engine.synthetic import generate_company

    company = generate_company("ferreteria_andina", seed=42, n_products=4, start="2026-01-01", end="2026-07-31")
    with db_engine.begin() as conn:
        return load_company(conn, company, "P4 Sintética (ficticia)")


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
        o3 = add_offer(conn, A, s1, p1, 2.5, "GBP", "u", lead=5, valid_from=date(2026, 1, 1))  # sin tasa: no comparable
        o4 = add_offer(conn, A, s2, p1, 90.0, "USD", "pallet", lead=5, valid_from=date(2026, 1, 2))  # sin conversión: no comparable
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


# ───────────────────────────── costos, escenarios, simulación y causalidad ─────────────────────────────

def add_cost(conn, t, product, supplier, cost, valid_from, known_at=None, source="purchase_order", currency="USD", unit="kg"):
    conn.execute(text("INSERT INTO purchase_cost_history (tenant_id, product_id, supplier_id, unit_cost, currency, valid_from, source, known_at, unit, created_at) "
                      "VALUES (:t, :p, :s, :c, :cur, :v, :src, CAST(:k AS timestamptz), :u, now())"),
                 {"t": t, "p": product, "s": supplier, "c": cost, "cur": currency, "v": valid_from, "src": source, "k": known_at, "u": unit})


def test_cost_forecast_run_respects_availability_and_stores_metrics(db_engine, client):
    from predictily_engine.costs.forecast import add_months

    with db_engine.begin() as conn:
        t = new_tenant(conn, "P4 Costos (ficticia)")
        p = add_product(conn, t, "C1", "kg")
        s = add_supplier(conn, t, "SC")
        for k in range(18):
            d = add_months(date(2025, 1, 1), k).replace(day=10)
            add_cost(conn, t, p, s, 10.0 + 0.5 * k, d, known_at=f"{d.isoformat()} 12:00:00-05")
        add_cost(conn, t, p, s, 999.0, date(2026, 6, 10), known_at="2026-08-01 00:00:00+00")  # conocido después del corte
        add_cost(conn, t, p, s, 777.0, date(2026, 6, 12), known_at=None, source="import")  # sin known_at: no se conoce a futuro de un corte pasado
    rid, jid = None, None
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, t, "cost", "forecast", {"as_of": "2026-06-30", "historical": True})
    r = execute(client, rid, t, jid)
    assert r.status_code == 200 and r.json()["quality_status"] in ("ok", "degraded")
    with db_engine.connect() as conn:
        row = conn.execute(text("SELECT * FROM cost_forecasts WHERE tenant_id = :t AND core_run_id = :r"), {"t": t, "r": rid}).mappings().one()
        run = conn.execute(text("SELECT result_type, summary, as_of FROM core_runs WHERE id = :r"), {"r": rid}).mappings().one()
    assert row["method"] == "drift" and row["reference_method"] == "last_value" and row["n_obs"] == 18
    assert float(row["last_observed_cost"]) == pytest.approx(18.5)  # el 999 con known_at posterior no existe al corte
    assert row["points"][0]["yhat"] == pytest.approx(19.0)
    assert row["metrics"]["drift"]["holdout"]["mae"] < row["metrics"]["last_value"]["holdout"]["mae"]
    assert run["result_type"] == "forecast" and run["summary"]["availability"]["after_cutoff"] == 1
    assert run["summary"]["availability"]["unknown_availability_excluded"] == 1


def test_scenario_evaluation_run_persists_classified_inputs(db_engine, client, world):
    A = world["A"]
    opts = {"horizon_days": 28, "strategies": [{"key": "base", "name": "Base"},
                                               {"key": "promo", "name": "Precio -10 %", "price_change_pct": -0.10, "demand_change_pct": 0.15}]}
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, A, "scenario", "evaluate", opts)
    r = execute(client, rid, A, jid)
    assert r.status_code == 200, r.text
    with db_engine.connect() as conn:
        ev = conn.execute(text("SELECT results FROM scenario_evaluations WHERE tenant_id = :t AND core_run_id = :r"), {"t": A, "r": rid}).scalar_one()
        run = conn.execute(text("SELECT result_type, quality_status FROM core_runs WHERE id = :r"), {"r": rid}).mappings().one()
    assert run["result_type"] == "conditional_simulation"
    base = next(s for s in ev["strategies"] if s["key"] == "base")
    promo = next(s for s in ev["strategies"] if s["key"] == "promo")
    # 10 u. de existencias y demanda de 70 por semana: las ventas atendibles quedan limitadas por la oferta (10 u.)
    assert base["metrics"]["attainable_sales_units"] == pytest.approx(10.0) and all(base["coherence"].values())
    assert promo["metrics"]["attainable_sales_units"] == pytest.approx(10.0) and promo["metrics"]["unmet_units"] > base["metrics"]["unmet_units"]
    assert {"price_change", "demand_change"} <= set(ev["assumed_inputs"])
    assert ev["common_basis"]["horizon_days"] == 28 and "NO se deriva" in ev["disclaimer"]


def test_causal_check_endpoint_never_publishes_an_effect(db_engine, client, synthetic_tenant):
    r = client.post("/v1/supply/causal/check", json={"tenant_id": synthetic_tenant, "request": {"treatment": "net_price", "outcome": "units"}}, headers=AUTH)
    assert r.status_code == 200
    body = r.json()
    assert body["result_type"] == "causal_estimate" and body["quality_status"] == "insufficient_evidence"
    assert body["data"]["effect"] is None and body["data"]["estimator_enabled"] is False
    assert body["data"]["conditional_simulation"]["available"] is True


def test_historical_simulation_run_on_synthetic_tenant(db_engine, client, synthetic_tenant):
    A = synthetic_tenant
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, A, "supply", "simulate", {"days": 45})
    r = execute(client, rid, A, jid)
    assert r.status_code == 200, r.text
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT summary, quality_status, result_type, limitations FROM core_runs WHERE id = :r"), {"r": rid}).mappings().one()
    s = run["summary"]
    assert run["result_type"] == "evaluation" and run["quality_status"] == "degraded"
    assert set(s["policies"]) == {"current", "reference", "planner"} and s["demand_basis"] == "observed_sales_censored"
    cur = s["policies"]["current"]
    assert cur["sold_units"] + cur["unmet_units"] == pytest.approx(cur["demand_units"], rel=1e-6)
    assert any("censurada" in x for x in run["limitations"])


def test_run_of_another_core_or_tenant_cannot_be_hijacked(db_engine, client, world):
    A, B = world["A"], world["B"]
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, A, "scenario", "evaluate", {"strategies": [{"key": "base"}]})
    assert execute(client, rid, B, jid).status_code == 404  # otra empresa
    assert execute(client, 999999, A).status_code == 404  # inexistente


def _current_runs(db_engine, tenant, core, operation):
    with db_engine.connect() as conn:
        return conn.execute(text("SELECT id FROM core_runs WHERE tenant_id = :t AND core = :c AND operation = :o AND is_current ORDER BY id"),
                            {"t": tenant, "c": core, "o": operation}).scalars().all()


def test_historical_supply_runs_never_become_current_nor_displace_the_production_one(db_engine, client, world):
    """Solo una ejecución `production` puede ser vigente: un plan o pronóstico de costos `historical` (aunque su pronóstico de
    demanda sea de producción) no se vuelve vigente ni releva a la vigente de producción."""
    A = world["A"]
    prod_plan, _, _ = run_plan(db_engine, client, A)
    assert _current_runs(db_engine, A, "supply", "plan") == [prod_plan]
    with db_engine.begin() as conn:
        retro_plan, retro_job = add_core_run(conn, A, "supply", "plan", None, run_type="historical")
    assert execute(client, retro_plan, A, retro_job).status_code == 200
    with db_engine.connect() as conn:
        done = conn.execute(text("SELECT status, run_type, is_current FROM core_runs WHERE id = :r"), {"r": retro_plan}).mappings().one()
    assert (done["status"], done["run_type"], done["is_current"]) == ("completed", "historical", False)
    assert _current_runs(db_engine, A, "supply", "plan") == [prod_plan], "el plan histórico no releva al de producción"

    with db_engine.begin() as conn:
        prod_cost, prod_job = add_core_run(conn, A, "cost", "forecast", {})
    assert execute(client, prod_cost, A, prod_job).status_code == 200
    assert _current_runs(db_engine, A, "cost", "forecast") == [prod_cost]
    with db_engine.begin() as conn:
        retro_cost, retro_cjob = add_core_run(conn, A, "cost", "forecast", {"as_of": "2026-06-30", "historical": True}, run_type="historical")
    assert execute(client, retro_cost, A, retro_cjob).status_code == 200
    assert _current_runs(db_engine, A, "cost", "forecast") == [prod_cost]
