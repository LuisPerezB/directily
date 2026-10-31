"""Customer Core · compromisos comerciales y reconciliación con la demanda agregada (E2-11, E2-23).

Los pedidos/contratos confirmados son demanda comprometida; la esperanza probabilística NO se vuelve a sumar; el residual
cubre anónimos/nuevos/no modelados; las dos vistas nunca se suman. Unidades coherentes con el producto.
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.commitments import committed_by_relation, committed_lines, state_as_of
from predictily_engine.customer.reconcile import reconcile_horizon, reconciliation_summary

AS_OF = date(2026, 6, 1)


def line(**kw) -> dict:
    base = {"commitment_id": 1, "customer_id": 10, "commitment_type": "order", "status": "confirmed",
            "known_on": pd.Timestamp("2026-05-20"), "cancelled_on": pd.NaT, "expected_delivery_date": pd.Timestamp("2026-06-10"),
            "delivery_date": pd.NaT, "product_id": 5, "quantity": 100.0, "quantity_fulfilled": 0.0, "unit": "unidad",
            "product_unit": "unidad", "currency": "USD", "unknown_knowledge": False}
    base.update(kw)
    return base


def accepted(rows, horizon=30):
    lines, summary = committed_lines(pd.DataFrame(rows), AS_OF, horizon)
    return lines, summary


def test_pending_is_quantity_minus_fulfilled_and_never_negative():
    lines, s = accepted([line(quantity=100, quantity_fulfilled=30, status="partially_fulfilled"), line(commitment_id=2, quantity=10, quantity_fulfilled=25)])
    assert s["committed_quantity"] == pytest.approx(70.0)       # la segunda línea (sobrecumplida) no resta ni suma
    assert s["excluded"]["nothing_pending"] == 1


def test_commitments_unknown_at_the_cutoff_are_excluded():
    """E2-23: lo conocido después del corte (known_at > corte) o con conocimiento desconocido no entra (defensa en profundidad:
    la regla P3 ya los saca de la vista antes)."""
    _, s = accepted([line(known_on=pd.Timestamp("2026-06-05")), line(commitment_id=2, known_on=pd.NaT)])
    assert s["committed_quantity"] == 0 and s["excluded"]["unknown_or_after_cutoff"] == 2


def test_cancellations_respect_the_cutoff():
    """Cancelado antes del corte: fuera. Cancelado DESPUÉS del corte: a esa fecha seguía vigente, entra."""
    _, s = accepted([line(status="cancelled", cancelled_on=pd.Timestamp("2026-05-25")),
                     line(commitment_id=2, status="confirmed", cancelled_on=pd.Timestamp("2026-06-20"), quantity=40)])
    assert s["excluded"]["cancelled"] == 1 and s["committed_quantity"] == pytest.approx(40.0)


def test_delivery_must_fall_inside_the_horizon():
    rows = [line(expected_delivery_date=pd.Timestamp("2026-06-05"), quantity=10),                    # dentro de 7 días
            line(commitment_id=2, expected_delivery_date=pd.Timestamp("2026-06-20"), quantity=20),   # dentro de 30, fuera de 7
            line(commitment_id=3, expected_delivery_date=pd.Timestamp("2026-09-01"), quantity=40),   # fuera de 90
            line(commitment_id=4, expected_delivery_date=pd.NaT, quantity=5),                        # fecha desconocida
            line(commitment_id=5, expected_delivery_date=pd.Timestamp("2026-05-28"), quantity=7)]    # vencida
    assert accepted(rows, 7)[1]["committed_quantity"] == pytest.approx(10)
    assert accepted(rows, 30)[1]["committed_quantity"] == pytest.approx(30)
    s90 = accepted(rows, 90)[1]
    assert s90["committed_quantity"] == pytest.approx(30)
    assert s90["excluded"]["undated_delivery"] == 1 and s90["excluded"]["overdue_delivery"] == 1 and s90["excluded"]["delivery_after_horizon"] == 1


def test_line_delivery_date_takes_precedence_over_the_header_date():
    _, s = accepted([line(delivery_date=pd.Timestamp("2026-06-03"), expected_delivery_date=pd.Timestamp("2026-08-01"), quantity=9)], 7)
    assert s["committed_quantity"] == pytest.approx(9)


def test_quotes_unconfirmed_and_expired_are_not_committed_demand():
    rows = [line(commitment_type="quote"), line(commitment_id=2, status="open"), line(commitment_id=3, status="expired"),
            line(commitment_id=4, status="fulfilled"), line(commitment_id=5, commitment_type="contract", quantity=12)]
    _, s = accepted(rows)
    assert s["committed_quantity"] == pytest.approx(12)
    assert s["excluded"]["not_order_or_contract"] == 1 and s["excluded"]["not_confirmed"] == 1
    assert s["excluded"]["expired_or_fulfilled"] == 2


def test_units_must_match_the_product_unit_no_silent_conversion():
    """E2-11/E2-23: una línea en otra unidad no se suma como si fuera la del producto."""
    _, s = accepted([line(unit="docena", product_unit="unidad", quantity=5), line(commitment_id=2, unit="UNIDAD", product_unit="unidad", quantity=3)])
    assert s["excluded"]["unit_mismatch"] == 1 and s["committed_quantity"] == pytest.approx(3)


def test_committed_by_relation_aggregates_per_buyer_and_product_and_keeps_unattributed():
    lines, _ = accepted([line(quantity=10), line(commitment_id=2, quantity=5), line(commitment_id=3, customer_id=None, quantity=8)])
    rel = committed_by_relation(lines).sort_values("committed_quantity")
    assert list(rel["committed_quantity"]) == [8.0, 15.0]
    assert rel["customer_id"].isna().sum() == 1


def _rev(cid, revision, status, known, cancelled=None, qty=100.0, fulfilled=10.0, ctype="order", delivery="2026-06-10", sku="ABC", unit="unidad"):
    header = {"commitment_type": ctype, "status": status, "expected_delivery_date": delivery, "currency": "USD"}
    lines = [{"line_number": 1, "product_sku": sku, "quantity": qty, "quantity_fulfilled": fulfilled, "unit": unit, "delivery_date": None}]
    return {"commitment_id": cid, "revision": revision, "status": status, "known_local": pd.Timestamp(known) if known else pd.NaT,
            "cancelled_local": pd.Timestamp(cancelled) if cancelled else pd.NaT, "snapshot": {"header": header, "lines": lines},
            "commitment_type": ctype, "customer_id": 10, "currency": "USD"}


UNITS = {5: "unidad"}


def test_state_follows_the_p3_rule_last_version_known_at_or_before_the_cutoff():
    """Regla P3 (CommitmentAsOf): última revisión con known_at no nulo y ≤ corte; cumplido tal como se conocía entonces."""
    revs = pd.DataFrame([_rev(1, 1, "confirmed", "2026-05-01 08:00", fulfilled=10), _rev(1, 2, "partially_fulfilled", "2026-05-20 09:00", fulfilled=60),
                         _rev(1, 3, "cancelled", "2026-05-30 10:00", cancelled="2026-05-30 10:00", fulfilled=60)])
    for as_of, status, pending in ((date(2026, 5, 15), "confirmed", 90), (date(2026, 5, 25), "partially_fulfilled", 40), (date(2026, 5, 31), "cancelled", 0)):
        st, stats = state_as_of(revs, {"ABC": 5}, UNITS, as_of)
        assert len(st) == 1 and st.iloc[0]["status"] == status
        lines, summary = committed_lines(st, as_of, 30, stats)
        assert summary["committed_quantity"] == pytest.approx(pending)
    # antes de que la fuente conociera el compromiso no existe estado (y se cuenta como no visible)
    st, stats = state_as_of(revs, {"ABC": 5}, UNITS, date(2026, 4, 1))
    assert st.empty and stats["not_visible_unknown_or_later"] == 1
    # el día del known_at cuenta: el corte es el FIN del día local
    assert len(state_as_of(revs, {"ABC": 5}, UNITS, date(2026, 5, 1))[0]) == 1


def test_a_version_without_known_at_is_never_visible_even_for_the_present():
    revs = pd.DataFrame([_rev(1, 1, "confirmed", None)])
    st, stats = state_as_of(revs, {"ABC": 5}, UNITS, date(2026, 6, 1))
    assert st.empty and stats["not_visible_unknown_or_later"] == 1
    _, summary = committed_lines(st, date(2026, 6, 1), 30, stats)
    assert summary["excluded"]["unknown_or_after_cutoff"] == 1 and summary["committed_quantity"] == 0


def test_cancellation_without_date_is_flagged_and_counts_zero_and_a_later_cancellation_does_not_apply():
    revs = pd.DataFrame([_rev(1, 1, "cancelled", "2026-05-01"), _rev(2, 1, "cancelled", "2026-05-01", cancelled="2026-06-20", qty=50, fulfilled=0)])
    st, stats = state_as_of(revs, {"ABC": 5}, UNITS, date(2026, 6, 1))
    assert stats["cancellation_date_unknown"] == 1
    _, summary = committed_lines(st, date(2026, 6, 1), 30, stats)
    assert summary["excluded"]["cancellation_date_unknown"] == 1
    assert summary["committed_quantity"] == pytest.approx(50)      # la cancelación del 20-jun es posterior al corte: seguía vigente


def test_state_keeps_units_and_customers_for_the_reconciliation():
    revs = pd.DataFrame([_rev(1, 1, "confirmed", "2026-05-01", unit="caja")])
    st, stats = state_as_of(revs, {"ABC": 5}, UNITS, date(2026, 6, 1))
    assert st.iloc[0]["customer_id"] == 10 and st.iloc[0]["unit"] == "caja" and st.iloc[0]["product_unit"] == "unidad"
    assert committed_lines(st, date(2026, 6, 1), 30, stats)[1]["excluded"]["unit_mismatch"] == 1


# ───────────────────────────── reconciliación ─────────────────────────────

def pred(rows):
    return pd.DataFrame(rows, columns=["product_id", "expected_quantity", "committed_quantity"])


def agg(product_id, qty, days=30):
    return pd.DataFrame({"product_id": [product_id], "days": [days], "yhat": [qty]})


def test_components_add_up_to_the_aggregate_without_double_counting():
    """E2-11: agregado = compradores modelados + comprometido fuera del modelo + residual; no se suma agregado + compradores."""
    p = pred([(1, 30.0, 0.0), (1, 20.0, 0.0), (1, 5.0, 12.0)])    # el tercero tiene 12 comprometidos > 5 esperados
    out = reconcile_horizon(p, pd.DataFrame({"product_id": [1], "committed_quantity": [8.0]}), agg(1, 100.0), 30).iloc[0]
    assert out["buyers_total_quantity"] == pytest.approx(30 + 20 + 12)            # max(esperanza, comprometido): 12 y no 17
    assert out["buyers_committed_quantity"] == pytest.approx(12) and out["buyers_incremental_quantity"] == pytest.approx(50)
    assert out["committed_outside_model"] == pytest.approx(8)
    assert out["residual_quantity"] == pytest.approx(100 - 62 - 8)
    assert out["buyers_total_quantity"] + out["committed_outside_model"] + out["residual_quantity"] == pytest.approx(out["aggregate_quantity"])
    assert out["status"] == "ok" and out["residual_share"] == pytest.approx(0.30)


def test_expected_quantity_is_not_added_on_top_of_the_committed_quantity():
    out = reconcile_horizon(pred([(1, 5.0, 8.0)]), pd.DataFrame(columns=["product_id", "committed_quantity"]), agg(1, 50.0), 30).iloc[0]
    assert out["buyers_total_quantity"] == pytest.approx(8.0)          # y no 13
    out2 = reconcile_horizon(pred([(1, 20.0, 8.0)]), pd.DataFrame(columns=["product_id", "committed_quantity"]), agg(1, 50.0), 30).iloc[0]
    assert out2["buyers_total_quantity"] == pytest.approx(20.0)        # la esperanza ya contiene el comprometido
    assert out2["buyers_committed_quantity"] == pytest.approx(8.0) and out2["buyers_incremental_quantity"] == pytest.approx(12.0)


def test_buyers_above_the_aggregate_are_reported_without_altering_probabilities():
    p = pred([(1, 70.0, 0.0), (1, 60.0, 0.0)])
    before = p.copy()
    out = reconcile_horizon(p, pd.DataFrame(columns=["product_id", "committed_quantity"]), agg(1, 100.0), 30, tolerance=0.10).iloc[0]
    assert out["status"] == "inconsistent" and out["excess_quantity"] == pytest.approx(30.0)
    assert out["residual_quantity"] == 0.0 and out["severity"] == "major"
    pd.testing.assert_frame_equal(p, before)                            # las esperanzas individuales no se tocan
    small = reconcile_horizon(pred([(1, 104.0, 0.0)]), pd.DataFrame(columns=["product_id", "committed_quantity"]), agg(1, 100.0), 30).iloc[0]
    assert small["status"] == "inconsistent" and small["severity"] == "minor"


def test_missing_or_incomplete_aggregate_gives_no_residual():
    p = pred([(1, 10.0, 0.0), (2, 10.0, 0.0)])
    rec = reconcile_horizon(p, pd.DataFrame(columns=["product_id", "committed_quantity"]), agg(1, 100.0, days=12), 30).set_index("product_id")
    assert rec.loc[1, "status"] == "aggregate_incomplete" and np.isnan(rec.loc[1, "residual_quantity"])
    assert rec.loc[2, "status"] == "aggregate_unavailable" and np.isnan(rec.loc[2, "residual_quantity"])
    s = reconciliation_summary(rec.reset_index())
    assert s["status_counts"] == {"aggregate_incomplete": 1, "aggregate_unavailable": 1}


def test_committed_without_a_prediction_stays_outside_the_modelled_buyers():
    """Un comprador sin historial del producto no tiene predicción, pero su pedido confirmado sí es demanda comprometida."""
    out = reconcile_horizon(pred([(1, 10.0, 0.0)]), pd.DataFrame({"product_id": [1, 2], "committed_quantity": [6.0, 4.0]}),
                            pd.DataFrame({"product_id": [1, 2], "days": [30, 30], "yhat": [40.0, 10.0]}), 30).set_index("product_id")
    assert out.loc[1, "residual_quantity"] == pytest.approx(40 - 10 - 6)
    assert out.loc[2, "buyers_total_quantity"] == 0 and out.loc[2, "committed_outside_model"] == 4 and out.loc[2, "residual_quantity"] == pytest.approx(6)


def test_units_are_product_units_end_to_end():
    """E2-11: la reconciliación solo suma cantidades expresadas en la unidad del producto (las demás se excluyen antes)."""
    lines, s = committed_lines(pd.DataFrame([line(unit="caja", quantity=50), line(commitment_id=2, quantity=7)]), AS_OF, 30)
    outside = committed_by_relation(lines)[["product_id", "committed_quantity"]]
    out = reconcile_horizon(pred([(5, 3.0, 0.0)]), outside, agg(5, 20.0), 30).iloc[0]
    assert s["excluded"]["unit_mismatch"] == 1                      # las 50 «cajas» no se suman como unidades
    assert out["committed_outside_model"] == pytest.approx(7.0)
    assert out["residual_quantity"] == pytest.approx(20 - 3 - 7)
