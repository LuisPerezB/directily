"""Integración W5-D · Supply Core (perecibilidad, FEFO, compromisos y simulación as-of) con BD aislada y datos ficticios.

Cubre lo que las pruebas unitarias no pueden: el esquema real (`inventory_lots`, `products.is_perishable`, `commercial_commitments`), la lectura as-of
de ventas de W1 (documentos tardíos y anulaciones posteriores) y el aislamiento entre empresas. FUGA: un documento tardío no altera una simulación pasada.
"""

from __future__ import annotations

import json
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest
from _supply_db import (
    add_branch,
    add_core_run,
    add_forecast,
    add_inventory,
    add_offer,
    add_product,
    add_supplier,
    new_tenant,
)
from asof_helpers import add_document, amend_document, local, make_world
from sqlalchemy import text

from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.platform.config import resolve_config
from predictily_engine.supply.simulation import Policy, simulate_policy
from predictily_engine.supply.simulation_runner import build_inputs

pytestmark = pytest.mark.integration
AUTH = {"Authorization": "Bearer test-token"}
CUT = date(2026, 10, 26)


def q(db_engine, sql, **p):
    with db_engine.connect() as conn:
        return [dict(r) for r in conn.execute(text(sql), p).mappings().all()]


# ── lectura as-of de precios y costos de venta ────────────────────────────────────────────────────────────

@pytest.fixture()
def sales_world(db_engine):
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name="W5D Ventas as-of (ficticia)")
        add_document(conn, w, number="F-1", sale_date="2026-09-10", lines=[(0, 10, 4.0, 2.0)])
        add_document(conn, w, number="F-2", sale_date="2026-09-20", lines=[(0, 10, 6.0, 3.0)])
        # documento TARDÍO: del 25/09, entregado por la fuente el 05/10 al mediodía
        add_document(conn, w, number="F-3", sale_date="2026-09-25", lines=[(0, 10, 8.0, 5.0)], mode="incremental", known_from=local("2026-10-05", 12))
        # F-2 se ANULA el 10/10 (posterior al corte del 30/09)
        amend_document(conn, w, "F-2", known_from=local("2026-10-10", 9), status="voided")
        # última venta: 20/10 (los cortes anteriores son reconstrucciones HISTÓRICAS; uno posterior es una ejecución vigente)
        add_document(conn, w, number="F-4", sale_date="2026-10-20", lines=[(0, 10, 6.0, 4.0)])
    return w


def test_recent_prices_and_sales_cost_history_are_read_as_of_the_cutoff(db_engine, sales_world):
    w = sales_world
    with db_engine.connect() as conn:
        repo = SupplyRepository(conn, w.tenant_id)
        pid = w.product_ids[0]
        at_cut = repo.recent_net_prices(date(2026, 9, 30), 45)
        # al 30/09 solo F-1 y F-2 eran conocidos: (40 + 60) / 20 = 5,0 (ni la venta tardía ni la anulación posterior existen aún)
        assert float(at_cut.set_index("product_id").loc[pid, "price"]) == pytest.approx(5.0)
        hist_cut = repo.sales_cost_history(date(2026, 9, 30))
        assert float(hist_cut.iloc[0]["unit_cost"]) == pytest.approx(2.5) and float(hist_cut.iloc[0]["quantity"]) == pytest.approx(20.0)
        # ejecución vigente (corte posterior al último dato): rige lo conocido HOY: F-3 llegó y F-2 está anulada → (40 + 80 + 60) / 30 = 6,0
        now = repo.recent_net_prices(date(2026, 10, 31), 60)
        assert float(now.set_index("product_id").loc[pid, "price"]) == pytest.approx(6.0)
        hist_now = repo.sales_cost_history(date(2026, 10, 31)).set_index("period")
        assert float(hist_now.iloc[0]["unit_cost"]) == pytest.approx((10 * 2.0 + 10 * 5.0) / 20) and float(hist_now.iloc[1]["unit_cost"]) == pytest.approx(4.0)
        # un corte intermedio: F-3 ya llegó (05/10) y F-2 sigue vigente (se anula el 10/10)
        mid = repo.recent_net_prices(date(2026, 10, 8), 60)
        assert float(mid.set_index("product_id").loc[pid, "price"]) == pytest.approx((40 + 60 + 80) / 30)
        # paridad con la regla de W1 en pandas (SalesVersions): el SQL agregado aplica la misma visibilidad
        for cut in (date(2026, 9, 30), date(2026, 10, 8), date(2026, 10, 15)):
            k = repo.asof_knowledge(cut, cut)
            cells = repo.sales_versions(cut, k).sales_cells(cut, k)
            win = cells[(pd.to_datetime(cells["ds"]) > pd.Timestamp(cut) - pd.Timedelta(days=60))]
            expected = float(win["net_amount"].sum() / win["units"].sum())
            assert float(repo.recent_net_prices(cut, 60, knowledge=cut).set_index("product_id").loc[pid, "price"]) == pytest.approx(expected)


def test_lead_times_are_filtered_by_valid_from_for_a_past_cutoff(db_engine):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "W5D plazos as-of (ficticia)")
        b = add_branch(conn, t)
        p = add_product(conn, t, "P1")
        s = add_supplier(conn, t, "S1")
        conn.execute(text("INSERT INTO supplier_lead_times (tenant_id, supplier_id, product_id, branch_id, lead_time_days, valid_from, created_at, updated_at) "
                          "VALUES (:t, :s, :p, NULL, 5, '2026-01-01', now(), now()), (:t, :s, :p, NULL, 20, '2026-12-01', now(), now())"),
                     {"t": t, "s": s, "p": p})
    with db_engine.connect() as conn:
        repo = SupplyRepository(conn, t)
        assert repo.lead_times_asof(date(2026, 8, 31))["lead_time_days"].tolist() == [5]
        assert sorted(repo.lead_times_asof(date(2026, 12, 15))["lead_time_days"].tolist()) == [5, 20]
        assert len(repo.lead_times()) == 2


# ── FUGA: un documento tardío no altera una simulación pasada ─────────────────────────────────────────────

class Recorder(Policy):
    name = "recorder"

    def __init__(self):
        self.seen = []

    def decide(self, ctx):
        inp = ctx.inp
        self.seen.append({"day": ctx.day - inp.start, "pre": round(float(ctx.hist[: inp.start].sum()), 3), "cost": float(inp.cost_at(ctx.day)[0]),
                          "lead": int(inp.lead_at(ctx.day)[0])})
        return np.full(len(inp.keys), 5.0)


def _history_world(db_engine, name: str, late: bool, inv_date: date = date(2026, 7, 3), inv_cost: float | None = 2.0, catalogue_cost: float | None = None):
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name=name)
        d0 = date(2026, 1, 1)
        for i in range(212):
            day = (d0 + timedelta(days=i)).isoformat()
            add_document(conn, w, number=f"H-{i:04d}", sale_date=day, lines=[(0, 5, 4.0, 2.0)])
        if late:    # venta previa a la ventana (20/06) que la fuente entrega recién el 12/07 (día 9 de la ventana del 04/07 al 31/07)
            add_document(conn, w, number="TARDE-1", sale_date="2026-06-20", lines=[(0, 3000, 4.0, 2.0)], mode="incremental", known_from=local("2026-07-12", 12))
        add_inventory(conn, w.tenant_id, w.branch_ids[0], w.product_ids[0], inv_date, on_hand=50.0, cost=inv_cost)
        if catalogue_cost is not None:
            conn.execute(text("UPDATE products SET current_cost = :c WHERE id = :p AND tenant_id = :t"), {"c": catalogue_cost, "p": w.product_ids[0], "t": w.tenant_id})
    return w


def _simulate(db_engine, w):
    with db_engine.connect() as conn:
        repo = SupplyRepository(conn, w.tenant_id)
        tenant = repo.tenant()
        cfg, _v, _p = resolve_config("supply", tenant["settings"], tenant.get("sector"), None)
        inp, info = build_inputs(repo, tenant, {"as_of": "2026-07-31", "days": 28, "review_days": 7}, cfg)
    assert inp is not None, info
    rec = Recorder()
    simulate_policy(inp, rec)
    return {s["day"]: s for s in rec.seen}, inp


def test_a_late_sales_document_does_not_alter_decisions_taken_before_it_was_known(db_engine):
    clean, _ = _simulate(db_engine, _history_world(db_engine, "W5D Fuga sin tardío (ficticia)", late=False))
    leaky, inp = _simulate(db_engine, _history_world(db_engine, "W5D Fuga con tardío (ficticia)", late=True))
    assert sorted(clean) == sorted(leaky) == [0, 7, 14, 21]
    # revisiones del 04/07 y del 11/07: el documento (conocido desde el 12/07) NO existe; las decisiones son idénticas
    assert clean[0]["pre"] == leaky[0]["pre"] and clean[7]["pre"] == leaky[7]["pre"]
    # revisiones posteriores al 12/07: ya lo conocen (+3000 u. en las ventas previas)
    assert leaky[14]["pre"] == pytest.approx(clean[14]["pre"] + 3000.0) and leaky[21]["pre"] == pytest.approx(clean[21]["pre"] + 3000.0)
    assert inp.pre_history is not None and inp.unit_cost_t is not None and inp.lead_t is not None      # la ventana usa tablas as-of, no vectores estáticos
    # costo y plazo son los del día (aquí constantes: el snapshot de existencias trae el costo y no hay plazos)
    assert {s["cost"] for s in leaky.values()} == {2.0}


# ── plan de reposición con lotes (de extremo a extremo) ───────────────────────────────────────────────────

@pytest.fixture()
def perishable_world(db_engine):
    with db_engine.begin() as conn:
        A = new_tenant(conn, "W5D Perecible A (ficticia)")
        b = add_branch(conn, A)
        p = add_product(conn, A, "YOG", "u", price=5.0, cost=2.0)
        conn.execute(text("UPDATE products SET is_perishable = true, shelf_life_days = 14 WHERE id = :p AND tenant_id = :t"), {"p": p, "t": A})
        s = add_supplier(conn, A, "S1")
        add_offer(conn, A, s, p, 2.0, "USD", "u", lead=1)
        add_inventory(conn, A, b, p, CUT, on_hand=60.0)
        fr = add_forecast(conn, A, CUT, {(b, p): [2.0] * 90})
        conn.execute(text("UPDATE forecast_values SET lo_80 = 1, hi_80 = 3 WHERE tenant_id = :t AND forecast_run_id = :r"), {"t": A, "r": fr})
        for code, e, qty in (("L1", 5, 20.0), ("L2", 60, 40.0)):
            conn.execute(text("INSERT INTO inventory_lots (tenant_id, branch_id, product_id, lot_code, snapshot_date, expiry_date, quantity, created_at, updated_at) "
                              "VALUES (:t, :b, :p, :c, :d, :e, :q, now(), now())"), {"t": A, "b": b, "p": p, "c": code, "d": CUT, "e": CUT + timedelta(days=e), "q": qty})
        # otra empresa con SUS lotes (no deben influir en la de A)
        B = new_tenant(conn, "W5D Perecible B (ficticia)")
        bb = add_branch(conn, B)
        pb = add_product(conn, B, "YOG", "u", price=5.0, cost=2.0)
        conn.execute(text("UPDATE products SET is_perishable = true, shelf_life_days = 3 WHERE id = :p AND tenant_id = :t"), {"p": pb, "t": B})
        add_inventory(conn, B, bb, pb, CUT, on_hand=500.0)
        add_forecast(conn, B, CUT, {(bb, pb): [2.0] * 90})
        add_offer(conn, B, add_supplier(conn, B, "SB"), pb, 2.0, "USD", "u", lead=1)
        conn.execute(text("INSERT INTO inventory_lots (tenant_id, branch_id, product_id, lot_code, snapshot_date, expiry_date, quantity, created_at, updated_at) "
                          "VALUES (:t, :b, :p, 'LB', :d, :e, 500, now(), now())"), {"t": B, "b": bb, "p": pb, "d": CUT, "e": CUT + timedelta(days=2)})
    return {"A": A, "branch": b, "product": p, "B": B}


def run_plan(db_engine, client, tenant, options=None):
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, tenant, "supply", "plan", options)
    r = client.post(f"/v1/supply/runs/{rid}/execute", json={"tenant_id": tenant, "training_job_id": jid}, headers=AUTH)
    assert r.status_code == 200, r.text
    return rid, r.json()


def plan_row(db_engine, tenant, rid):
    return q(db_engine, "SELECT p.details, p.unmet_units_total, r.assumptions, r.limitations, r.summary FROM supply_plans p JOIN core_runs r ON r.id = p.core_run_id "
                        "WHERE p.tenant_id = :t AND p.core_run_id = :r", t=tenant, r=rid)[0]


def test_plan_reports_fefo_expiry_losses_the_no_purchase_scenario_and_ignores_other_companies_lots(db_engine, client, perishable_world):
    A = perishable_world["A"]
    rid, body = run_plan(db_engine, client, A)
    row = plan_row(db_engine, A, rid)
    det = row["details"]
    per = det["perishability"]
    # L1: 20 u. con último día vendible el día 5 y demanda 2/día ⇒ vende 10, pierde 10. L2 se vende antes de vencer.
    assert per["expected_expiry_loss_units"] == pytest.approx(10.0) and per["series_with_lots"] == 1 and per["series_with_effect"] == 1
    assert per["low_demand_expiry_loss_units"] == pytest.approx(15.0)                       # lo_80 = 1 u./día persistido
    audit = per["audit"]
    assert audit["expected_expiry_loss_units"] == pytest.approx(10.0)
    assert audit["series"][0]["expected_loss_from_new_receipts_units"] == pytest.approx(0.0, abs=1e-6)       # las compras nuevas no vencen sin venderse
    assert audit["expected_expiry_loss_value"] == pytest.approx(20.0)
    # la auditoría del PLAN completo en demanda baja suma además lo que las compras del plan (dimensionadas con la media) no alcanzarían a vender: ≥ lotes solos
    assert audit["low_demand_expiry_loss_units"] >= per["low_demand_expiry_loss_units"] and audit["low_demand_expiry_loss_value"] == pytest.approx(2.0 * audit["low_demand_expiry_loss_units"])
    assert row["summary"]["expected_expiry_loss_units"] == pytest.approx(10.0)
    assert any("FEFO" in x for x in row["assumptions"])
    # escenario «sin compra»: mismas existencias y recepciones abiertas, sin ninguna compra nueva
    base = det["no_purchase_baseline"]
    assert base["unmet_units"] > 0 and base["series_with_shortfall"] == 1 and base["series"][0]["first_unmet_date"] is not None
    assert float(row["unmet_units_total"]) < base["unmet_units"]
    # posición de inventario: apertura utilizable = 60 − 10 de merma proyectada
    pos = det["report"]["availability"]["series"][0]
    assert pos["on_hand"] == 60.0 and pos["expected_expiry_loss"] == pytest.approx(10.0) and pos["available"] == pytest.approx(50.0)
    # aislamiento: solo los lotes de A entran en el plan de A, y los de B (500 u. que vencen en 2 días) solo en el de B
    lot_codes = {lot["lot"] for lot in per["series"][0]["lots"]["lots"]}
    assert lot_codes == {"L1", "L2"} and per["series"][0]["lots"]["lots_total"] == pytest.approx(60.0)
    ridb, _ = run_plan(db_engine, client, perishable_world["B"])
    perb = plan_row(db_engine, perishable_world["B"], ridb)["details"]["perishability"]
    assert perb["expected_expiry_loss_units"] == pytest.approx(500.0 - 4.0)       # vende 2/día durante 2 días y pierde el resto
    assert {lot["lot"] for lot in perb["series"][0]["lots"]["lots"]} == {"LB"}
    assert plan_row(db_engine, A, rid)["details"]["perishability"]["expected_expiry_loss_units"] == pytest.approx(10.0)      # A no cambió


def test_a_historical_cutoff_ignores_a_lots_snapshot_taken_after_it(db_engine, client):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "W5D Perecible retroactivo (ficticia)")
        b = add_branch(conn, t)
        p = add_product(conn, t, "LEC", "u", price=5.0, cost=2.0)
        conn.execute(text("UPDATE products SET is_perishable = true, shelf_life_days = 14 WHERE id = :p AND tenant_id = :t"), {"p": p, "t": t})
        s = add_supplier(conn, t, "S1")
        add_offer(conn, t, s, p, 2.0, "USD", "u", lead=1, known_at="2026-01-01 00:00:00-05")        # en un corte pasado, la oferta debe tener disponibilidad conocida
        cutoff = CUT - timedelta(days=20)
        add_inventory(conn, t, b, p, cutoff, on_hand=60.0)
        add_forecast(conn, t, cutoff, {(b, p): [2.0] * 90}, run_type="historical", current=False)
        # foto de lotes POSTERIOR al corte (10 días después): no existe para ese corte
        conn.execute(text("INSERT INTO inventory_lots (tenant_id, branch_id, product_id, lot_code, snapshot_date, expiry_date, quantity, created_at, updated_at) "
                          "VALUES (:t, :b, :p, 'LX', :d, :e, 60, now(), now())"), {"t": t, "b": b, "p": p, "d": cutoff + timedelta(days=10), "e": cutoff + timedelta(days=12)})
    rid, _ = run_plan(db_engine, client, t, {"forecast_run_id": q(db_engine, "SELECT id FROM forecast_runs WHERE tenant_id = :t ORDER BY id DESC LIMIT 1", t=t)[0]["id"]})
    det = plan_row(db_engine, t, rid)["details"]
    assert det["perishability"]["series_with_lots"] == 0 and det["perishability"]["expected_expiry_loss_units"] == 0.0
    series = det["perishability"]["series"]
    assert series and series[0]["status"] == "shelf_life" and "lots" not in series[0]       # solo la vida útil declarada; el lote futuro no se ve
    assert any("declarada hoy" in x for x in plan_row(db_engine, t, rid)["limitations"])        # el atributo del catálogo no tiene historia: se declara


# ── compromisos conciliados con el pronóstico en el plan guardado ─────────────────────────────────────────

def test_plan_reconciles_confirmed_orders_with_the_forecast_and_does_not_count_them_twice(db_engine, client):
    with db_engine.begin() as conn:
        t = new_tenant(conn, "W5D Compromisos (ficticia)")
        b = add_branch(conn, t)
        p = add_product(conn, t, "CEM", "u", price=5.0, cost=2.0)
        s = add_supplier(conn, t, "S1")
        add_offer(conn, t, s, p, 2.0, "USD", "u", lead=1)
        add_inventory(conn, t, b, p, CUT, on_hand=100.0, reserved=40.0)
        add_forecast(conn, t, CUT, {(b, p): [10.0] * 90})
        deliver = (CUT + timedelta(days=3)).isoformat()
        cid = conn.execute(text("INSERT INTO commercial_commitments (tenant_id, branch_id, commitment_type, external_id, status, issued_at, known_at, expected_delivery_date, currency, "
                                "created_at, updated_at) VALUES (:t, :b, 'order', 'PED-1', 'confirmed', now(), :k, :d, 'USD', now(), now()) RETURNING id"),
                           {"t": t, "b": b, "k": f"{CUT.isoformat()} 08:00:00-05", "d": deliver}).scalar_one()
        conn.execute(text("INSERT INTO commercial_commitment_items (tenant_id, commitment_id, line_number, product_id, quantity, quantity_fulfilled, unit, created_at, updated_at) "
                          "VALUES (:t, :c, 1, :p, 40, 0, 'u', now(), now())"), {"t": t, "c": cid, "p": p})
        snap = {"header": {"commitment_type": "order", "external_id": "PED-1", "status": "confirmed", "expected_delivery_date": deliver, "currency": "USD"},
                "lines": [{"line_number": 1, "product_sku": "CEM", "quantity": 40, "quantity_fulfilled": 0, "unit": "u", "delivery_date": None}]}
        conn.execute(text("INSERT INTO commercial_commitment_revisions (tenant_id, commitment_id, revision, status, known_at, source_hash, snapshot, recorded_at) "
                          "VALUES (:t, :c, 1, 'confirmed', :k, repeat('a', 64), CAST(:s AS jsonb), now())"), {"t": t, "c": cid, "k": f"{CUT.isoformat()} 08:00:00-05", "s": json.dumps(snap)})
    rid, _ = run_plan(db_engine, client, t)
    det = plan_row(db_engine, t, rid)["details"]
    commits = det["report"]["availability"]["commitments"]
    assert commits["series"] == 1 and commits["committed_qty"] == pytest.approx(40.0) and commits["double_count_avoided_qty"] == pytest.approx(40.0)
    pos = det["report"]["availability"]["series"][0]
    assert pos["committed"][0] == pytest.approx(40.0) and pos["residual_probabilistic"][0] == pytest.approx(30.0)   # 70 = 40 + 30, no 110
    assert pos["available"] == pytest.approx(100.0) and pos["reserved_held_back"] == 0.0
    # con la conducta anterior (`ignore`) la existencia utilizable es 60: las 40 u. reservadas se descontaban Y los pedidos seguían en el pronóstico
    rid2, _ = run_plan(db_engine, client, t, {"config": {"commitments_mode": "ignore"}})
    legacy = plan_row(db_engine, t, rid2)["details"]["report"]["availability"]
    assert legacy["commitments"]["series"] == 0


# ── precio de catálogo de HOY no entra en un corte pasado (D1 · SUP-012/050) ─────────────────────────────────

def test_changing_todays_catalogue_price_does_not_alter_a_past_max_margin_plan(db_engine, client):
    cutoff = date(2026, 9, 30)
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name="W5D Precio retroactivo (ficticia)")
        for i in range(40):
            day = (cutoff - timedelta(days=i)).isoformat()
            add_document(conn, w, number=f"R-{i:03d}", sale_date=day, lines=[(0, 5, 7.0, 3.0)])      # precio neto OBSERVADO: 7,0
        s = add_supplier(conn, w.tenant_id, "S1")
        add_offer(conn, w.tenant_id, s, w.product_ids[0], 2.0, "USD", "unidad", lead=1, known_at="2026-01-01 00:00:00-05")
        add_inventory(conn, w.tenant_id, w.branch_ids[0], w.product_ids[0], cutoff, on_hand=20.0)
        fr = add_forecast(conn, w.tenant_id, cutoff, {(w.branch_ids[0], w.product_ids[0]): [5.0] * 90}, run_type="historical", current=False)
        add_inventory(conn, w.tenant_id, w.branch_ids[0], w.product_ids[0], cutoff + timedelta(days=40), on_hand=1.0)     # existencias POSTERIORES: no deben influir
        conn.execute(text("UPDATE products SET current_price = 1 WHERE id = :p AND tenant_id = :t"), {"p": w.product_ids[0], "t": w.tenant_id})     # hoy: menor que el costo
    opts = {"forecast_run_id": fr, "config": {"objective": "max_margin"}}
    rid1, _ = run_plan(db_engine, client, w.tenant_id, opts)
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE products SET current_price = 99 WHERE id = :p AND tenant_id = :t"), {"p": w.product_ids[0], "t": w.tenant_id})
    rid2, _ = run_plan(db_engine, client, w.tenant_id, opts)
    plans = [q(db_engine, "SELECT objective_value, total_purchase_cost, unmet_units_total FROM supply_plans WHERE tenant_id = :t AND core_run_id = :r", t=w.tenant_id, r=r)[0]
             for r in (rid1, rid2)]
    lines = [q(db_engine, "SELECT l.product_id, l.quantity, l.order_date FROM supply_plan_lines l JOIN supply_plans p ON p.id = l.supply_plan_id AND p.tenant_id = l.tenant_id "
                          "WHERE l.tenant_id = :t AND p.core_run_id = :r ORDER BY l.order_date, l.id", t=w.tenant_id, r=r) for r in (rid1, rid2)]
    assert plans[0] == plans[1] and lines[0] == lines[1] and lines[0]       # el precio de hoy (1 → 99) no cambia el plan de un corte pasado: rige el neto observado (7,0)
    lim = plan_row(db_engine, w.tenant_id, rid1)["limitations"]
    assert any("precio de venta es el neto observado" in x for x in lim)


def _build(db_engine, w, opts=None):
    with db_engine.connect() as conn:
        repo = SupplyRepository(conn, w.tenant_id)
        tenant = repo.tenant()
        cfg, _v, _p = resolve_config("supply", tenant["settings"], tenant.get("sector"), None)
        return build_inputs(repo, tenant, {"as_of": "2026-07-31", "days": 28, "review_days": 7, **(opts or {})}, cfg)


def test_a_stale_initial_inventory_snapshot_excludes_the_series_from_the_simulation(db_engine):
    w = _history_world(db_engine, "W5D Simulación snapshot obsoleto (ficticia)", late=False, inv_date=date(2026, 5, 20))     # 44 días antes del inicio de la ventana
    inp, info = _build(db_engine, w)
    assert inp is None and info["reason"].startswith("Ninguna serie")
    assert any("más de 30 días de antigüedad" in e["reason"] for e in info["excluded"])
    fresh, _ = _build(db_engine, _history_world(db_engine, "W5D Simulación snapshot vigente (ficticia)", late=False, inv_date=date(2026, 6, 25)))   # 8 días: se usa
    assert fresh is not None and fresh.init_inv[0] == pytest.approx(50.0)


def test_a_company_whose_only_cost_is_the_catalogue_cost_still_simulates_and_declares_it_unversioned(db_engine):
    w = _history_world(db_engine, "W5D Simulación costo de catálogo (ficticia)", late=False, inv_cost=None, catalogue_cost=3.5)
    inp, info = _build(db_engine, w)
    assert inp is not None, info
    assert inp.unit_cost_t is not None and set(np.unique(inp.unit_cost_t)) == {3.5}
    assert any("NO VERSIONADO" in n and "costo del catálogo vigente" in n for n in inp.notes)
    # con costo en el snapshot (as-of) o historial de compras NO se usa el del catálogo y no se declara
    own, _ = _build(db_engine, _history_world(db_engine, "W5D Simulación costo propio (ficticia)", late=False, inv_cost=2.0, catalogue_cost=3.5))
    assert set(np.unique(own.unit_cost_t)) == {2.0} and not any("NO VERSIONADO" in n for n in own.notes)
    # sin ningún costo (ni historial, ni snapshot, ni catálogo) la serie se excluye y se declara
    none, info_none = _build(db_engine, _history_world(db_engine, "W5D Simulación sin costo (ficticia)", late=False, inv_cost=None))
    assert none is None and any("sin costo conocido" in e["reason"] for e in info_none["excluded"])


def test_the_current_run_reads_what_is_known_today_but_a_past_cutoff_reads_only_what_was_known_then(db_engine):
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name="W5D Conocimiento vigente (ficticia)")
        add_document(conn, w, number="S-1", sale_date="2026-10-10", lines=[(0, 10, 4.0, 2.0)])
        add_document(conn, w, number="S-2", sale_date="2026-10-20", lines=[(0, 10, 4.0, 2.0)])
        # documento tardío: del 18/10, entregado por la fuente el 25/10 (después del último día de datos)
        add_document(conn, w, number="S-3", sale_date="2026-10-18", lines=[(0, 10, 10.0, 6.0)], mode="incremental", known_from=local("2026-10-25", 12))
    pid = w.product_ids[0]
    with db_engine.connect() as conn:
        repo = SupplyRepository(conn, w.tenant_id)
        price = lambda cut: float(repo.recent_net_prices(cut, 30).set_index("product_id").loc[pid, "price"])      # noqa: E731
        assert price(date(2026, 10, 20)) == pytest.approx((40 + 40 + 100) / 30)       # corte = último dato ⇒ ejecución vigente: rige lo conocido HOY (incluye el tardío)
        assert price(date(2026, 10, 19)) == pytest.approx(4.0)                        # corte histórico: el documento tardío no existía
        assert repo.asof_knowledge(date(2026, 10, 20)) > date(2026, 10, 20) and repo.asof_knowledge(date(2026, 10, 19)) == date(2026, 10, 19)
        assert float(repo.sales_cost_history(date(2026, 10, 20)).iloc[0]["unit_cost"]) == pytest.approx((10 * 2.0 + 10 * 2.0 + 10 * 6.0) / 30)


def test_w5d_repository_queries_are_scoped_to_the_company_and_the_cutoff(db_engine):
    cut = date(2026, 10, 26)
    ids = {}
    with db_engine.begin() as conn:
        for tag, price, adj in (("A", 5.0, -5.0), ("B", 50.0, -99.0)):
            w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name=f"W5D Aislamiento repositorio {tag} (ficticia)")
            t, b, p = w.tenant_id, w.branch_ids[0], w.product_ids[0]
            for i in range(10):
                add_document(conn, w, number=f"{tag}-{i}", sale_date=(cut - timedelta(days=i + 1)).isoformat(), lines=[(0, 5, price, price / 2)])
            conn.execute(text("UPDATE products SET is_perishable = true, shelf_life_days = :s WHERE id = :p AND tenant_id = :t"), {"s": 7 if tag == "A" else 70, "p": p, "t": t})
            for code, snap, qty in ((f"{tag}-OLD", cut - timedelta(days=16), 1), (f"{tag}-NEW", cut - timedelta(days=6), 2), (f"{tag}-LATE", cut + timedelta(days=4), 3)):
                conn.execute(text("INSERT INTO inventory_lots (tenant_id, branch_id, product_id, lot_code, snapshot_date, expiry_date, quantity, created_at, updated_at) "
                                  "VALUES (:t, :b, :p, :c, :d, :e, :q, now(), now())"), {"t": t, "b": b, "p": p, "c": code, "d": snap, "e": snap + timedelta(days=20), "q": qty})
            conn.execute(text("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, occurred_at, reference) "
                              "VALUES (:t, :b, :p, 'adjustment', :q, :at, :r)"), {"t": t, "b": b, "p": p, "q": adj, "at": f"{(cut - timedelta(days=1)).isoformat()} 10:00:00-05", "r": f"ajuste {tag}"})
            fr = add_forecast(conn, t, cut, {(b, p): [2.0] * 30})
            conn.execute(text("UPDATE forecast_values SET lo_80 = :lo, hi_80 = :hi WHERE tenant_id = :t AND forecast_run_id = :r"),
                         {"lo": 1.0 if tag == "A" else 10.0, "hi": 3.0, "t": t, "r": fr})
            conn.execute(text("INSERT INTO recommendations (tenant_id, forecast_run_id, branch_id, product_id, type, lead_time_days, lead_time_source, review_period_days, service_level, "
                              "demand_lead_time, safety_stock, reorder_point, risk_level, assumptions, created_at, updated_at) VALUES "
                              "(:t, :r, :b, :p, 'reorder', 7, 'tenant_default', 7, 0.95, 100, :ss, 150, 'low', CAST(:a AS jsonb), now(), now())"),
                         {"t": t, "r": fr, "b": b, "p": p, "ss": 11.0 if tag == "A" else 99.0, "a": json.dumps({"safety_stock_policy": "normal_z_sigma", "uncertainty": "intervals"})})
            ids[tag] = {"t": t, "b": b, "p": p, "run": fr}
    with db_engine.connect() as conn:
        A, B = ids["A"], ids["B"]
        ra, rb = SupplyRepository(conn, A["t"]), SupplyRepository(conn, B["t"])
        # lotes: solo la ÚLTIMA foto ≤ corte de la empresa (ni el historial de fotos, ni la foto posterior al corte, ni los lotes de la otra empresa)
        assert ra.inventory_lots(cut)["lot_code"].tolist() == ["A-NEW"] and rb.inventory_lots(cut)["lot_code"].tolist() == ["B-NEW"]
        assert sorted(ra.inventory_lots(cut + timedelta(days=5))["lot_code"]) == ["A-LATE"]            # con un corte posterior, la foto tardía sí es la vigente
        assert ra.inventory_lots(cut - timedelta(days=20)).empty                                        # antes de la primera foto: nada
        # ajustes, perecibilidad
        assert ra.stock_adjustments(cut)["quantity"].tolist() == [-5.0] and rb.stock_adjustments(cut)["quantity"].tolist() == [-99.0]
        assert ra.stock_adjustments(cut - timedelta(days=2)).empty                                      # el ajuste ocurrió después de ese corte
        assert ra.product_perishability()["product_id"].tolist() == [A["p"]] and rb.product_perishability()["shelf_life_days"].tolist() == [70]
        # corrida, cuantiles y política: una empresa no puede leer la corrida de otra aunque conozca su id
        assert len(ra.series_forecast_bands(A["run"])) == 30 and ra.series_forecast_bands(B["run"]).empty and rb.series_forecast_bands(A["run"]).empty
        assert ra.replenishment_policy(A["run"])["safety_stock"].tolist() == [11.0] and ra.replenishment_policy(B["run"]).empty
        assert rb.replenishment_policy(B["run"])["safety_stock"].tolist() == [99.0]
        # precios netos y costo de ventas: solo los productos de la empresa, con sus propios importes
        pa, pb = ra.recent_net_prices(cut, 30), rb.recent_net_prices(cut, 30)
        assert pa["product_id"].tolist() == [A["p"]] and pa["price"].tolist() == [pytest.approx(5.0)]
        assert pb["product_id"].tolist() == [B["p"]] and pb["price"].tolist() == [pytest.approx(50.0)]
        ca, cb = ra.sales_cost_history(cut), rb.sales_cost_history(cut)
        assert set(ca["product_id"]) == {A["p"]} and set(cb["product_id"]) == {B["p"]}
        assert float(ca["unit_cost"].iloc[0]) == pytest.approx(2.5) and float(cb["unit_cost"].iloc[0]) == pytest.approx(25.0)


def test_over_reserved_stock_degrades_the_stored_plan_quality_while_the_same_plan_without_it_is_ok(db_engine, client):
    def world(name, on_hand, reserved):
        with db_engine.begin() as conn:
            t = new_tenant(conn, name)
            b = add_branch(conn, t)
            p = add_product(conn, t, "CEM", "u", price=5.0, cost=2.0)
            s_ = add_supplier(conn, t, "S1")
            add_offer(conn, t, s_, p, 2.0, "USD", "u", lead=1)
            add_inventory(conn, t, b, p, CUT, on_hand=on_hand, reserved=reserved)
            fr = add_forecast(conn, t, CUT, {(b, p): [10.0] * 90})
            # incertidumbre EMPÍRICA persistida por la etapa 1: así la única causa posible de «degraded» es la validez del stock
            conn.execute(text("INSERT INTO recommendations (tenant_id, forecast_run_id, branch_id, product_id, type, lead_time_days, lead_time_source, review_period_days, service_level, "
                              "demand_lead_time, safety_stock, reorder_point, risk_level, assumptions, created_at, updated_at) VALUES "
                              "(:t, :r, :b, :p, 'reorder', 7, 'tenant_default', 7, 0.95, 100, 5, 105, 'low', CAST(:a AS jsonb), now(), now())"),
                         {"t": t, "r": fr, "b": b, "p": p, "a": json.dumps({"safety_stock_policy": "empirical_quantile", "uncertainty": "empirical"})})
        return t

    clean, bad = world("W5D Calidad sin sobrerreserva (ficticia)", 100.0, 10.0), world("W5D Calidad con sobrerreserva (ficticia)", 40.0, 90.0)
    rid_clean, body_clean = run_plan(db_engine, client, clean)
    rid_bad, body_bad = run_plan(db_engine, client, bad)
    assert body_clean["quality_status"] == "ok", plan_row(db_engine, clean, rid_clean)["limitations"]
    assert body_bad["quality_status"] == "degraded"
    row = plan_row(db_engine, bad, rid_bad)
    assert any("Sobrerreserva" in x and "50" in x for x in row["limitations"])
    assert q(db_engine, "SELECT quality_status FROM core_runs WHERE id = :r", r=rid_bad)[0]["quality_status"] == "degraded"


def test_a_simulation_ending_after_the_last_sale_still_prices_each_review_with_what_was_known_then(db_engine):
    # La ventana termina el 15/08 pero la última venta es del 31/07: ningún corte de la ventana es «anterior a la última venta», así que el modo automático
    # de `asof_knowledge` valdría HOY. La simulación debe fijar el conocimiento en el propio corte de cada revisión.
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=1, n_customers=1, name="W5D Precio por revisión (ficticia)")
        for i in range(212):
            add_document(conn, w, number=f"H-{i:04d}", sale_date=(date(2026, 1, 1) + timedelta(days=i)).isoformat(), lines=[(0, 5, 4.0, 2.0)])
        # venta del 20/07 a 40 $ que la fuente entrega el 05/08 (después de la revisión del 02/08 y antes de la del 09/08)
        add_document(conn, w, number="TARDE-P", sale_date="2026-07-20", lines=[(0, 1000, 40.0, 2.0)], mode="incremental", known_from=local("2026-08-05", 12))
        add_inventory(conn, w.tenant_id, w.branch_ids[0], w.product_ids[0], date(2026, 7, 18), on_hand=50.0, cost=2.0)
    inp, info = _build(db_engine, w, {"as_of": "2026-08-15"})
    assert inp is not None, info
    row = lambda day: inp.price_t[(date.fromisoformat(day) - date(2026, 7, 19)).days][0]        # noqa: E731  (la ventana empieza el 19/07)
    assert row("2026-08-02") == pytest.approx(4.0)             # revisión del 02/08: el documento tardío (05/08) no existía
    assert row("2026-08-09") > 20.0                            # revisión del 09/08: ya se conocía
