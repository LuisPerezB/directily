"""Lotes con vencimiento y salida FEFO (V2.5 · W5-D, §10): lectura as-of, conciliación con la existencia, simulación diaria, merma proyectada con
los cuantiles persistidos y tope por vida útil. Datos ficticios, sin base de datos."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.inventory.lots import (
    LotPosition,
    fefo_run,
    lots_asof,
    project_expiry,
    reconcile_lots,
    shelf_avail_cap_by_period,
    shelf_cap_by_period,
)
from predictily_engine.supply.balance import expiry_projection

AS_OF = date(2026, 10, 26)


def lots_df(rows):
    """rows: (sucursal, producto, lote, foto, vencimiento o None, cantidad)."""
    return pd.DataFrame(rows, columns=["branch_id", "product_id", "lot_code", "snapshot_date", "expiry_date", "quantity"])


def series(rows):
    df = lots_df([(1, 10, *r) for r in rows])
    df["snapshot_date"] = pd.to_datetime(df["snapshot_date"])
    df["expiry_date"] = pd.to_datetime(df["expiry_date"])
    return df


def day(n):
    return str(pd.Timestamp(AS_OF) + pd.Timedelta(days=n))[:10]


# ── lectura as-of ─────────────────────────────────────────────────────────────────────────────────────────

def test_lots_asof_takes_the_last_snapshot_not_after_the_cutoff():
    df = lots_df([(1, 10, "A", "2026-10-10", "2026-11-01", 5.0), (1, 10, "B", "2026-10-10", "2026-11-05", 7.0),
                  (1, 10, "A", "2026-10-20", "2026-11-01", 3.0),                     # foto más reciente ≤ corte
                  (1, 10, "A", "2026-10-30", "2026-11-01", 99.0),                    # POSTERIOR al corte: no existe para ese corte
                  (1, 11, "Z", "2026-10-29", "2026-12-01", 8.0)])                    # otra serie, solo con una foto posterior
    out = lots_asof(df, AS_OF)
    assert sorted(out["lot_code"]) == ["A"] and float(out["quantity"].iloc[0]) == 3.0
    assert (out["product_id"] == 11).sum() == 0
    assert lots_asof(df, date(2026, 10, 31)).set_index("product_id").loc[10, "quantity"] == 99.0   # con el corte posterior sí aparece
    assert lots_asof(None, AS_OF).empty and lots_asof(df.iloc[0:0], AS_OF).empty


# ── conciliación con la existencia ────────────────────────────────────────────────────────────────────────

def test_without_lots_or_without_expiry_nothing_is_invented():
    assert reconcile_lots(None, 50, 0, AS_OF).status == "not_configured"
    undated = series([("L1", "2026-10-26", None, 40.0)])
    pos = reconcile_lots(undated, 50, 0, AS_OF)
    assert pos.status == "not_configured" and "no declaran vencimiento" in pos.reason
    assert project_expiry(pos, np.full(10, 2.0))["expired_qty"] is None


def test_lots_are_capped_by_on_hand_dropping_the_latest_expiring_first_and_remainder_is_undated():
    df = series([("A", "2026-10-26", day(5), 30.0), ("B", "2026-10-26", day(20), 40.0)])
    pos = reconcile_lots(df, on_hand=50.0, held_back=0.0, as_of=AS_OF)
    # los lotes suman 70 > existencia 50: se descartan 20 u. del que vence después
    assert pos.dropped_qty == pytest.approx(20.0)
    assert [(d, q) for d, q, _ in pos.lots] == [(5, 30.0), (20, 20.0)]
    assert pos.undated_qty == pytest.approx(0.0)
    pos = reconcile_lots(df, on_hand=100.0, held_back=0.0, as_of=AS_OF)
    assert pos.dropped_qty == 0.0 and pos.undated_qty == pytest.approx(30.0)      # 100 − 70 = existencia sin vencimiento declarado
    assert any("sin vencimiento declarado" in n for n in pos.notes)


def test_expired_at_cutoff_is_on_the_shelf_but_not_usable_and_reserved_ships_first_expiring():
    df = series([("OLD", "2026-10-26", day(0), 12.0),          # vence el propio día del corte: ya no es vendible el día 1
                 ("A", "2026-10-26", day(4), 10.0), ("B", "2026-10-26", day(30), 10.0)])
    pos = reconcile_lots(df, on_hand=32.0, held_back=6.0, as_of=AS_OF)
    assert pos.expired_qty == pytest.approx(12.0) and pos.expired_lots == [("OLD", 12.0)]
    # el reservado (6) sale primero del que vence antes (A): quedan A=4 y B=10
    assert [(d, q) for d, q, _ in pos.lots] == [(4, 4.0), (30, 10.0)]
    assert pos.held_back_applied == pytest.approx(6.0)
    assert pos.sellable_qty == pytest.approx(14.0)             # 32 − 12 vencidas − 6 reservadas


def test_stale_lots_snapshot_is_ignored_with_a_reason():
    df = series([("A", "2026-10-01", day(10), 10.0)])
    pos = reconcile_lots(df, on_hand=10.0, held_back=0.0, as_of=AS_OF, inventory_date=AS_OF, max_age_days=7)
    assert pos.status == "stale_ignored" and "ya no describen el stock" in pos.reason and pos.lots == []
    fresh = reconcile_lots(series([("A", "2026-10-24", day(10), 10.0)]), 10.0, 0.0, AS_OF, AS_OF, 7)
    assert fresh.status == "configured"


# ── FEFO diario y merma proyectada ────────────────────────────────────────────────────────────────────────

def test_fefo_sells_the_first_expiring_lot_first_and_loses_what_is_left_at_its_last_day():
    # Lote A: 10 u., último día vendible el día 3. Lote B: 20 u., día 10. Demanda 2/día durante 10 días.
    run = fefo_run([(3, 10.0, "A"), (10, 20.0, "B")], 0.0, np.full(10, 2.0))
    # A vende 6 (días 1-3) y pierde 4; B vende 2/día los días 4-10 = 14 y pierde 6
    assert run.expired_by_day[2] == pytest.approx(4.0) and run.expired_by_day[9] == pytest.approx(6.0)
    assert run.expired_qty == pytest.approx(10.0) and run.sold.sum() == pytest.approx(20.0)
    assert run.lost_lots == [(3, 4.0, "A"), (10, 6.0, "B")]
    assert run.unmet.sum() == pytest.approx(0.0)


def test_fefo_without_waste_when_demand_consumes_everything_and_undated_stock_never_expires():
    run = fefo_run([(5, 10.0, "A")], 100.0, np.full(10, 2.0))     # A (10) se vende en 5 días y el resto de la demanda sale del no fechado (nunca vence)
    assert run.expired_qty == pytest.approx(0.0) and run.end_stock == pytest.approx(90.0)
    low = fefo_run([(5, 10.0, "A")], 100.0, np.full(10, 1.0))     # con demanda baja, 5 u. de A vencen
    assert low.expired_qty == pytest.approx(5.0)


def test_new_receipts_are_fresher_and_never_change_the_loss_of_older_lots():
    base = fefo_run([(3, 10.0, "A")], 0.0, np.full(12, 2.0))
    with_po = fefo_run([(3, 10.0, "A")], 0.0, np.full(12, 2.0), arrivals=[(2, 40.0, 2 + 10 - 1, "OC")])
    assert base.lost_lots[0] == with_po.lost_lots[0] == (3, 4.0, "A")


def test_project_expiry_uses_the_persisted_daily_demand_and_the_low_demand_scenario_loses_more():
    df = series([("A", "2026-10-26", day(6), 30.0)])
    pos = reconcile_lots(df, on_hand=30.0, held_back=0.0, as_of=AS_OF)
    yhat = np.full(14, 4.0)                 # 24 u. en 6 días: 6 u. no se venden antes del vencimiento
    lo = np.full(14, 2.0)                   # cuantil inferior diario (lo_80): se pierden 18
    hi = np.full(14, 6.0)
    exp = project_expiry(pos, yhat)
    assert exp["expired_qty"] == pytest.approx(6.0) and exp["first_loss_day"] == 6
    assert project_expiry(pos, lo)["expired_qty"] == pytest.approx(18.0)
    assert project_expiry(pos, hi)["expired_qty"] == pytest.approx(0.0)
    # un vencimiento más allá de los días cubiertos no se extrapola: queda sin resolver
    far = reconcile_lots(series([("A", "2026-10-26", day(30), 30.0)]), 30.0, 0.0, AS_OF)
    res = project_expiry(far, np.full(14, 1.0))      # 14 u. vendidas en los días cubiertos; las 16 restantes vencen el día 30, fuera de la cobertura
    assert res["expired_qty"] == pytest.approx(0.0) and res["unresolved_qty"] == pytest.approx(16.0)


def test_existing_expiry_projection_of_the_balance_is_consistent_with_the_new_fefo():
    # La función heredada de balance.py y la nueva dan la misma merma para el mismo caso (test_supply_balance.py::test_expiry_is_optional_and_fefo).
    lots = pd.DataFrame({"qty": [10.0, 20.0], "expires_on": [pd.Timestamp(AS_OF) + pd.Timedelta(days=3), pd.Timestamp(AS_OF) + pd.Timedelta(days=10)]})
    old = expiry_projection(lots, np.full(10, 2.0), AS_OF)
    new = fefo_run([(3, 10.0, "A"), (10, 20.0, "B")], 0.0, np.full(10, 2.0))
    assert old["expired_qty"] == pytest.approx(new.expired_qty) and old["expired_by_day"] == pytest.approx(new.expired_by_day.tolist())


def test_undated_stock_never_expires_however_long_the_horizon():
    run = fefo_run([], 50.0, np.zeros(120))                      # 120 días sin demanda: el stock sin vencimiento declarado sigue ahí
    assert run.expired_qty == 0.0 and run.end_stock == pytest.approx(50.0) and run.unresolved_qty == 0.0
    mixed = fefo_run([(5, 10.0, "A")], 50.0, np.full(90, 0.1))   # A (10) vende 0,5 y pierde 9,5; el resto no fechado nunca vence
    assert mixed.expired_qty == pytest.approx(9.5) and mixed.end_stock == pytest.approx(50.0 - (90 * 0.1 - 0.5))


def test_reconciliation_policies_for_surplus_lots_and_for_expired_units_already_written_off():
    df = series([("OLD", "2026-10-26", day(4), 20.0), ("NEW", "2026-10-26", day(50), 40.0)])
    latest = reconcile_lots(df, 40.0, 0.0, AS_OF)                                          # por defecto: se descarta lo que vence después
    earliest = reconcile_lots(df, 40.0, 0.0, AS_OF, excess_drop="earliest_first")
    assert [(d, q) for d, q, _ in latest.lots] == [(4, 20.0), (50, 20.0)] and [(d, q) for d, q, _ in earliest.lots] == [(50, 40.0)]
    with pytest.raises(ValueError):
        reconcile_lots(df, 40.0, 0.0, AS_OF, excess_drop="x")
    # vencidos: dentro de on_hand se restan; ya dados de baja por el ERP, no
    exp = series([("EXP", "2026-10-26", day(-1), 10.0), ("OK", "2026-10-26", day(30), 30.0)])
    inside = reconcile_lots(exp, 30.0, 0.0, AS_OF)
    assert inside.expired_qty == 10.0 and inside.expired_deducted == 10.0
    assert any("ya haya dado de baja" in n for n in inside.notes)                          # el excedente (40 − 30) coincide con lo vencido: se avisa
    written = reconcile_lots(exp, 30.0, 0.0, AS_OF, expired_mode="written_off")
    assert written.expired_qty == 0.0 and written.expired_deducted == 0.0 and written.dropped_qty == 0.0
    assert written.sellable_qty == pytest.approx(30.0) and [(d, q) for d, q, _ in written.lots] == [(30, 30.0)]
    with pytest.raises(ValueError):
        reconcile_lots(exp, 30.0, 0.0, AS_OF, expired_mode="x")


def test_availability_cap_applies_only_when_the_shelf_life_is_shorter_than_the_period():
    d = np.concatenate([np.full(7, 10.0), np.full(7, 20.0), np.full(70, 5.0)])
    avail = shelf_avail_cap_by_period(d, period_days=7, n_periods=4, shelf_life_days=3)
    assert avail.tolist() == [30.0, 60.0, 15.0, 15.0]                    # demanda de los primeros 3 días de cada período
    assert np.isnan(shelf_avail_cap_by_period(d, 7, 4, 7)).all() and np.isnan(shelf_avail_cap_by_period(d, 7, 4, 10)).all()     # S ≥ R: basta el tope de arrastre
    short = shelf_avail_cap_by_period(np.full(15, 10.0), 7, 3, 3)
    assert short[:2].tolist() == [30.0, 30.0] and np.isnan(short[2])      # la ventana del tercer período excede el pronóstico: sin tope (no se extrapola)
    assert shelf_avail_cap_by_period(d, 7, 2, 4, life_fraction=0.5).tolist() == [20.0, 40.0]     # vida útil efectiva 2 días


# ── tope por vida útil ────────────────────────────────────────────────────────────────────────────────────

def test_shelf_cap_is_the_demand_sellable_within_the_shelf_life_after_each_period_close():
    d = np.full(84, 2.0)
    cap = shelf_cap_by_period(d, period_days=7, n_periods=6, shelf_life_days=10)
    # Unidad disponible desde el inicio del período a (a·7+1) vence el día a·7+10; el cierre es el día (a+1)·7: quedan 3 días de venta = 6 u.
    assert cap == pytest.approx([6.0] * 6)
    assert shelf_cap_by_period(d, 7, 3, shelf_life_days=7)[0] == 0.0           # vida útil ≤ período: nada puede quedar de un período al otro
    assert shelf_cap_by_period(d, 7, 3, 21, life_fraction=0.5)[0] == pytest.approx(6.0)      # 10 días efectivos
    # sin pronóstico para toda la ventana: sin tope (NaN), no se extrapola
    short = shelf_cap_by_period(np.full(14, 2.0), 7, 3, 10)
    assert short[0] == pytest.approx(6.0) and np.isnan(short[1]) and np.isnan(short[2])


def test_lot_position_dict_is_serialisable_and_has_no_nan():
    import json

    df = series([("A", "2026-10-26", day(6), 30.0), ("OLD", "2026-10-26", day(-2), 3.0)])
    pos: LotPosition = reconcile_lots(df, 40.0, 5.0, AS_OF)
    text = json.dumps(pos.to_dict())
    assert "NaN" not in text and pos.to_dict()["expired_qty"] == 3.0
