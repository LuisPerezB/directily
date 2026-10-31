"""E2-12 · Balance fechado: recepciones con fecha, parciales, fechas desconocidas, reservas, tránsito sin doble
conteo, evidencia de retrasos y vencimiento opcional."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.balance import (
    balance_identity_errors,
    build_receipts,
    expiry_projection,
    lead_time_evidence,
    project_balance,
    receipts_by_period,
)
from predictily_engine.supply.inputs import PlanInputs, build_problem

AS_OF = date(2026, 10, 26)


def orders(*rows):
    return pd.DataFrame(rows, columns=["purchase_order_id", "supplier_id", "ordered_at", "expected_at", "qty_open"])


def test_dated_receipt_arrives_on_its_date_and_never_today():
    o = orders((1, 7, "2026-10-01", "2026-11-25", 1000.0))  # día 30 después del corte
    rc, info = build_receipts(o, 0.0, 10, AS_OF)
    assert [(r.day, r.qty, r.status) for r in rc] == [(30, 1000.0, "dated")]
    bal = project_balance(10.0, rc, np.full(40, 10.0))
    # Con 10 u. y 10/día: se agota el día 1 y la llegada del día 30 no lo oculta (E2-02)
    assert bal.loc[bal["unmet"] > 0, "period"].min() == 1
    assert bal["unmet"].iloc[1:29].sum() == pytest.approx(280.0)


def test_undated_and_overdue_follow_an_explicit_policy():
    o = orders((1, 7, "2026-10-01", None, 50.0), (2, 7, "2026-10-01", "2026-10-20", 30.0))
    rc, _ = build_receipts(o, 0.0, 12, AS_OF, "lead_time_end")
    assert sorted((r.day, r.status, r.assumed) for r in rc) == [(12, "overdue", True), (12, "undated", True)]
    rc, info = build_receipts(o, 0.0, 12, AS_OF, "exclude")
    assert rc == [] and info["excluded_qty"] == 80.0 and info["excluded_orders"] == 2
    # Política con plazo observado del proveedor: emitida el 01-oct + 20 días de plazo observado = 21-oct (≤ corte) → día 1
    rc, _ = build_receipts(o, 0.0, 12, AS_OF, "observed_lead_time", observed_lead={7: 20.0})
    assert all(r.day >= 1 for r in rc) and min(r.day for r in rc) == 1


def test_transit_snapshot_is_not_counted_twice_with_open_orders():
    o = orders((1, 7, "2026-10-01", "2026-11-05", 100.0))
    rc, info = build_receipts(o, 100.0, 7, AS_OF)  # el snapshot ya incluye esa orden
    assert sum(r.qty for r in rc) == 100.0 and info["snapshot_in_transit_ignored"] == 100.0
    rc, info = build_receipts(None, 80.0, 7, AS_OF)  # sin órdenes: el tránsito del snapshot se usa, sin fecha
    assert [(r.day, r.qty, r.source, r.assumed) for r in rc] == [(7, 80.0, "snapshot", True)]
    rc, info = build_receipts(None, 80.0, 7, AS_OF, "exclude")
    assert rc == [] and info["excluded_qty"] == 80.0


def test_partial_receipt_contributes_only_the_pending_balance():
    # La capa de datos entrega qty_open = pedido − recibido (recepción parcial): aquí 120 de 200.
    rc, _ = build_receipts(orders((1, 7, "2026-10-01", "2026-11-02", 120.0)), 0.0, 7, AS_OF)
    assert [(r.day, r.qty) for r in rc] == [(7, 120.0)]


def test_balance_identity_holds_for_random_cases():
    rng = np.random.default_rng(3)
    for _ in range(20):
        rc = []
        for _i in range(int(rng.integers(0, 4))):
            rc += build_receipts(orders((1, 1, "2026-10-01", str(pd.Timestamp(AS_OF) + pd.Timedelta(days=int(rng.integers(1, 35))))[:10],
                                         float(rng.integers(10, 200)))), 0.0, 7, AS_OF)[0]
        dem = rng.integers(0, 30, 42).astype(float)
        for mode in ("lost_sales", "backorder"):
            for period in (1, 7):
                bal = project_balance(float(rng.integers(0, 80)), rc, dem, period, mode)
                assert balance_identity_errors(bal) == []


def test_period_receipts_use_the_conservative_availability_rule():
    rc = build_receipts(orders((1, 1, "2026-10-01", "2026-11-02", 10.0), (2, 1, "2026-10-01", "2026-10-27", 5.0)), 0, 7, AS_OF)[0]
    # día 1 → período 0; día 7 (mitad del período 0) → disponible desde el período 1
    assert receipts_by_period(rc, 3, 7).tolist() == [5.0, 10.0, 0.0]


def test_reserved_stock_is_not_available_and_future_order_does_not_hide_the_shortage():
    fc = pd.DataFrame({"branch_id": 1, "product_id": 10, "ds": pd.date_range("2026-10-27", periods=84), "yhat": 10.0})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [10], "on_hand": [100.0], "reserved": [90.0], "in_transit": [0.0]})
    po = pd.DataFrame({"branch_id": [1], "product_id": [10], "qty_open": [1000.0], "expected_at": [pd.Timestamp("2026-11-25")],
                       "purchase_order_id": [1], "supplier_id": [7], "ordered_at": [pd.Timestamp("2026-10-01")]})
    products = pd.DataFrame({"product_id": [10], "unit": ["u"], "current_price": [5.0], "current_cost": [2.0]})
    inp = PlanInputs(as_of=AS_OF, forecast=fc, inventory=inv, open_pos=po, products=products,
                     cost_by_product={10: {"unit_cost": 2.0, "supplier_id": 7, "source": "t"}},
                     config={"review_period_days": 7, "planning_horizon_days": 84})
    problem, report = build_problem(inp)
    node = problem.nodes[0]
    assert node.opening == 10.0  # existencias 100 − reservas 90
    assert node.known_receipts[5] == 1000.0 and node.known_receipts[:5].sum() == 0  # no disponible hoy
    assert report["receipts_summary"]["undated"] == 0


def test_lead_time_evidence_uses_only_orders_received_by_the_cutoff():
    rows = []
    for i, d in enumerate([5, 7, 9, 5, 7]):
        rows.append((i, 1, "received", pd.Timestamp("2026-09-01"), pd.Timestamp("2026-09-01") + pd.Timedelta(days=6),
                     pd.Timestamp("2026-09-01") + pd.Timedelta(days=d)))
    rows.append((9, 1, "received", pd.Timestamp("2026-10-20"), pd.Timestamp("2026-10-25"), pd.Timestamp("2026-11-30")))  # posterior al corte
    df = pd.DataFrame(rows, columns=["purchase_order_id", "supplier_id", "status", "ordered_at", "expected_at", "received_at"])
    ev = lead_time_evidence(df, AS_OF).iloc[0]
    # plazos 5,7,9,5,7: media 6,6 y desviación muestral √(11,2/4) = 1,6733; retrasos = plazo − 6: media 0,6
    assert ev["n_orders"] == 5 and ev["mean_lead"] == pytest.approx(6.6) and ev["std_lead"] == pytest.approx(1.6733, abs=1e-4)
    assert ev["mean_delay"] == pytest.approx(0.6) and ev["late_share"] == pytest.approx(0.6)
    assert lead_time_evidence(df.head(3), AS_OF).empty  # menos de 5 órdenes: no hay evidencia suficiente


def test_expiry_is_optional_and_fefo():
    assert expiry_projection(None, np.ones(5), AS_OF)["status"] == "not_configured"
    lots = pd.DataFrame({"qty": [10.0, 20.0], "expires_on": [pd.Timestamp(AS_OF) + pd.Timedelta(days=3), pd.Timestamp(AS_OF) + pd.Timedelta(days=10)]})
    res = expiry_projection(lots, np.full(10, 2.0), AS_OF)
    # Lote 1: vende 2/día los días 1-3 (6 u.) y 4 u. vencen el día 3. Lote 2: vende días 4-10 (14 u.) y 6 u. vencen el día 10.
    assert res["status"] == "configured" and res["expired_qty"] == pytest.approx(10.0)
    assert res["expired_by_day"][2] == pytest.approx(4.0) and res["expired_by_day"][9] == pytest.approx(6.0)
