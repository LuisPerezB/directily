"""Regresión de los hallazgos F1–F3 de la validación 2026-10-28 (abastecimiento).

Reproduce los casos de `docs/validation/2026-10-28/probe_findings.py`, pero afirmando el
comportamiento CORRECTO: recepciones por fecha (F1), cobertura plazo + revisión sin
extrapolar (F2) e incertidumbre desconocida ≠ certeza (F3).
"""

import math

import numpy as np
import pandas as pd
import pytest

from predictily_engine.inventory.recommendations import (
    UNKNOWN_SAFETY_FACTOR,
    LeadTimeErrorModel,
    build_recommendations,
    projected_risk_level,
    required_coverage_days,
)

CUTOFF = pd.Timestamp("2026-10-01")
PROFILE = pd.DataFrame({"unique_id": ["b1-p1"], "demand_class": ["smooth"]})
BT_COLS = ["unique_id", "window", "step", "actual", "yhat", "stockout", "scale"]
EMPTY_ERRORS = LeadTimeErrorModel(pd.DataFrame(columns=BT_COLS), pd.Series(dtype=object))


def _orders(qty: float, day: int | None) -> pd.DataFrame:
    exp = CUTOFF + pd.Timedelta(days=day) if day is not None else pd.NaT
    return pd.DataFrame({"purchase_order_id": [1], "branch_id": [1], "product_id": [1], "supplier_id": [None],
                         "qty_open": [qty], "expected_at": [exp]})


def _rec(days=30, on_hand=0.0, transit=0.0, lt=7, review=7, intervals=True, orders=None,
         error_model=EMPTY_ERRORS, daily=10.0):
    fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "step": range(1, days + 1),
                       "ds": pd.date_range(CUTOFF + pd.Timedelta(days=1), periods=days), "yhat": daily,
                       "lo_80": daily * 0.6 if intervals else np.nan, "hi_80": daily * 1.4 if intervals else np.nan})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [CUTOFF], "on_hand": [on_hand],
                        "reserved": [0.0], "in_transit": [transit], "unit_cost": [2.0]})
    out = build_recommendations(fc, inv, pd.DataFrame(), pd.DataFrame(), orders, pd.Series(dtype=float),
                                error_model, PROFILE, pd.Series({"b1-p1": daily}),
                                {"default_lead_time_days": lt, "review_period_days": review, "service_level": 0.95},
                                CUTOFF)
    assert len(out) == 1
    return out.iloc[0]


def _day(r) -> int | None:
    d = r["stockout_date_estimate"]
    return None if d is None or pd.isna(d) else (pd.Timestamp(d) - CUTOFF).days


# ───────────────────────── F1 · recepciones por fecha ─────────────────────────

def test_f1_late_purchase_order_does_not_hide_stockout():
    """Auditoría: 10 u., demanda 10/día, orden de 1.000 u. que llega el día 30."""
    late = _rec(on_hand=10, orders=_orders(1000, 30))
    without = _rec(on_hand=10)
    # Antes: available=1010, riesgo bajo, compra 0, sin fecha de agotamiento.
    assert late["available"] == 10 and late["in_transit"] == 1000
    assert late["assumptions"]["inventory_position"] == 10  # llega después de la cobertura (14 días)
    assert late["assumptions"]["in_transit_after_coverage"] == 1000
    assert _day(late) == 2 and late["risk_level"] == "critical" and late["type"] == "stockout_risk"
    assert late["stockout_probability"] > 0.99
    assert late["assumptions"]["stockout_before_receipt"] is True
    assert any("antes de la recepción prevista" in t for t in late["assumptions"]["limitations"])
    # La orden tardía no reduce la compra: mismo resultado que sin orden.
    assert late["suggested_qty"] == without["suggested_qty"] == math.ceil(140 + 1.6449 * 3.1213 * math.sqrt(14) - 10)


def test_f1_order_within_coverage_reduces_purchase_but_keeps_shortage_alert():
    # Plazo 30 + revisión 7: la orden del día 30 llega dentro de la cobertura → cuenta en la posición,
    # pero el saldo se agota el día 2, antes de su llegada.
    r = _rec(days=60, on_hand=10, lt=30, review=7, orders=_orders(1000, 30))
    assert r["available"] == 10 and r["assumptions"]["inventory_position"] == 1010
    assert r["suggested_qty"] == 0
    assert _day(r) == 2 and r["risk_level"] == "critical" and r["type"] == "stockout_risk"


def test_order_arriving_in_time_counts():
    r = _rec(on_hand=10, orders=_orders(200, 1))
    assert r["available"] == 10 and r["assumptions"]["inventory_position"] == 210
    assert r["suggested_qty"] == 0
    # Sin quiebre dentro de la cobertura (14 días): 10 + 200 − 10·t ≥ 0 hasta el día 21.
    assert _day(r) == 22
    assert r["risk_level"] == "low" and r["type"] == "no_action"
    assert r["stockout_probability"] < 0.01 and r["assumptions"]["uncertainty"] == "intervals"


def test_order_arriving_mid_coverage_checks_balance_before_arrival():
    # La orden del día 5 cubre la cobertura, pero los días 2–4 quedan sin existencias.
    r = _rec(on_hand=10, orders=_orders(500, 5))
    assert r["suggested_qty"] == 0 and _day(r) == 2
    assert r["risk_level"] == "critical" and r["stockout_probability"] > 0.99


@pytest.mark.parametrize("day,status", [(None, "undated"), (-3, "overdue")])
def test_undated_or_overdue_order_assumed_at_lead_time_end(day, status):
    r = _rec(on_hand=10, lt=7, orders=_orders(1000, day))
    receipt = r["assumptions"]["receipts"][0]
    assert receipt["day"] == 7 and receipt["dated"] is False and receipt["status"] == status
    assert r["assumptions"]["undated_arrival_assumption"] == "lead_time_end"
    assert r["assumptions"]["inventory_position"] == 1010 and r["suggested_qty"] == 0
    assert _day(r) == 2 and r["risk_level"] == "critical"
    assert any("sin fecha o vencida" in t for t in r["assumptions"]["limitations"])


def test_snapshot_in_transit_without_date_is_not_available_today():
    r = _rec(on_hand=10, transit=500, lt=7)
    assert r["available"] == 10 and r["in_transit"] == 500
    assert r["assumptions"]["in_transit_source"] == "snapshot"
    assert r["assumptions"]["receipts"][0]["day"] == 7
    assert _day(r) == 2 and r["risk_level"] == "critical"


def test_purchase_orders_take_precedence_over_snapshot_in_transit():
    r = _rec(on_hand=10, transit=500, orders=_orders(300, 3))
    assert r["in_transit"] == 300 and r["assumptions"]["in_transit_source"] == "purchase_orders"
    assert r["assumptions"]["snapshot_in_transit_ignored"] == 500


# ───────────────────────── F2 · cobertura plazo + revisión ─────────────────────────

def test_f2_short_forecast_publishes_no_quantity():
    """Auditoría: demanda 10/día, plazo 30, revisión 7, stock 0; pronóstico de 7 días."""
    short = _rec(days=7, on_hand=0, lt=30, review=7)
    full = _rec(days=90, on_hand=0, lt=30, review=7)
    # Antes: demanda en plazo 70 y compra 84 publicadas como válidas.
    a = short["assumptions"]
    assert a["coverage_insufficient"] is True
    assert a["coverage_days_needed"] == 37 and a["coverage_days_available"] == 7
    for col in ("suggested_qty", "demand_lead_time", "safety_stock", "reorder_point", "estimated_investment",
                "stockout_probability", "demand_lead_time_std"):
        assert pd.isna(short[col]), col
    assert a["suggested_qty_lower_bound"] == 70
    assert a["limitations"] and "7 de los 37 días" in a["limitations"][0]
    assert short["risk_level"] == "critical" and _day(short) == 1
    # Con cobertura suficiente el cálculo completo se mantiene (37 días → 370 u., compra 402).
    assert full["assumptions"]["coverage_insufficient"] is False
    assert full["demand_lead_time"] == pytest.approx(370) and full["suggested_qty"] == 402


def test_f2_all_null_quantities_stay_numeric():
    fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "step": range(1, 8),
                       "ds": pd.date_range(CUTOFF + pd.Timedelta(days=1), periods=7), "yhat": 10.0,
                       "lo_80": 6.0, "hi_80": 14.0})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [CUTOFF], "on_hand": [0.0], "reserved": [0.0],
                        "in_transit": [0.0], "unit_cost": [2.0]})
    out = build_recommendations(fc, inv, None, None, None, None, EMPTY_ERRORS, PROFILE, pd.Series(dtype=float),
                                {"default_lead_time_days": 30, "review_period_days": 7}, CUTOFF)
    assert pd.api.types.is_float_dtype(out["suggested_qty"]) and out["suggested_qty"].isna().all()
    # Lo que hace el pipeline con la inversión no falla con cantidades nulas.
    inv_est = np.where(out["unit_cost"].notna(), out["suggested_qty"] * out["unit_cost"].fillna(0), np.nan)
    assert np.isnan(inv_est).all()


def test_f2_insufficient_coverage_without_visible_shortage_is_not_low_risk():
    r = _rec(days=7, on_hand=1000, lt=30, review=7)
    assert pd.isna(r["suggested_qty"]) and r["risk_level"] == "medium" and r["type"] == "no_action"


def test_required_coverage_days_uses_lead_time_plus_review():
    inv = pd.DataFrame({"branch_id": [1, 2], "product_id": [1, 1], "ds": [CUTOFF, CUTOFF], "on_hand": [1.0, 1.0]})
    lt = pd.DataFrame([{"supplier_id": 1, "product_id": 1, "branch_id": 2, "lead_time_days": 45,
                        "lead_time_std_days": None}])
    settings = {"default_lead_time_days": 10, "review_period_days": 7}
    assert required_coverage_days(inv, lt, pd.DataFrame(), settings) == 52
    assert required_coverage_days(inv, lt, pd.DataFrame(), settings, ["b1-p1"]) == 17
    assert required_coverage_days(pd.DataFrame(), lt, pd.DataFrame(), settings) == 0


def test_f2_pipeline_forecasts_internally_up_to_coverage(small_data, pipeline_result):
    import copy

    from predictily_engine.pipeline.core import run_pipeline

    data = copy.copy(small_data)
    data.lead_times = small_data.lead_times.iloc[0:0]
    data.settings = {**small_data.settings, "default_lead_time_days": 30, "review_period_days": 7}
    res = run_pipeline(data, horizon=7, seed=42, n_jobs=4, horizons=(7,))
    fv = res.forecast_values
    # Solo se persiste el horizonte pedido…
    assert fv.loc[fv["granularity"] == "day", "horizon_step"].max() == 7
    # …pero la recomendación cubre los 37 días de plazo + revisión.
    recs = res.recommendations
    assert not recs.empty and recs["suggested_qty"].notna().all()
    a = pd.DataFrame(list(recs["assumptions"]))
    assert (a["coverage_days_needed"] == 37).all() and (a["coverage_days_available"] >= 37).all()
    assert not a["coverage_insufficient"].any()
    # Los primeros 7 días no cambian por pronosticar más lejos internamente.
    key = ["node_key", "target_date"]
    short = fv[(fv["granularity"] == "day") & (fv["level"] == "series")].set_index(key)["yhat"].sort_index()
    ref = pipeline_result.forecast_values
    ref = ref[(ref["granularity"] == "day") & (ref["level"] == "series") & (ref["horizon_step"] <= 7)]
    ref = ref.set_index(key)["yhat"].sort_index()
    assert ref.index.equals(short.index)
    np.testing.assert_allclose(short, ref, rtol=1e-6, atol=1e-6)


# ───────────────────────── F3 · incertidumbre desconocida ─────────────────────────

@pytest.mark.parametrize("on_hand,expected_level", [(200, "medium"), (100, "high")])
def test_f3_unknown_uncertainty_is_not_certainty(on_hand, expected_level):
    """Auditoría: sin errores históricos e intervalos NaN (antes: SS 0 y probabilidad 0 o 1)."""
    r = _rec(days=30, lt=7, review=7, on_hand=on_hand, intervals=False)
    a = r["assumptions"]
    assert pd.isna(r["stockout_probability"]) and pd.isna(r["demand_lead_time_std"])
    assert a["uncertainty"] == "unknown" and a["safety_stock_policy"] == "fraction_of_coverage_demand"
    assert a["safety_stock_factor"] == UNKNOWN_SAFETY_FACTOR and a["risk_rule"] == "projected_balance"
    assert r["safety_stock"] == pytest.approx(UNKNOWN_SAFETY_FACTOR * 140) and r["safety_stock"] > 0
    assert r["suggested_qty"] == math.ceil(140 * (1 + UNKNOWN_SAFETY_FACTOR) - on_hand)
    assert r["risk_level"] == expected_level
    assert any("incertidumbre es desconocida" in t for t in a["limitations"])


def test_f3_unknown_uncertainty_factor_is_configurable():
    fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "step": range(1, 31),
                       "ds": pd.date_range(CUTOFF + pd.Timedelta(days=1), periods=30), "yhat": 10.0,
                       "lo_80": np.nan, "hi_80": np.nan})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [CUTOFF], "on_hand": [0.0], "reserved": [0.0],
                        "in_transit": [0.0], "unit_cost": [None]})
    r = build_recommendations(fc, inv, None, None, None, None, EMPTY_ERRORS, PROFILE, pd.Series(dtype=float),
                              {"default_lead_time_days": 7, "review_period_days": 7,
                               "unknown_uncertainty_safety_factor": 0.25}, CUTOFF).iloc[0]
    assert r["safety_stock"] == pytest.approx(35) and r["assumptions"]["safety_stock_factor"] == 0.25


def test_known_interval_uncertainty_gives_probability_strictly_between_0_and_1():
    r = _rec(days=30, lt=7, review=7, on_hand=140)  # stock = demanda media en la cobertura
    assert r["assumptions"]["uncertainty"] == "intervals"
    assert 0.3 < r["stockout_probability"] < 0.7 and r["demand_lead_time_std"] > 0


def test_zero_variability_is_distinguished_from_unknown():
    fc_days = 30
    fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "step": range(1, fc_days + 1),
                       "ds": pd.date_range(CUTOFF + pd.Timedelta(days=1), periods=fc_days), "yhat": 10.0,
                       "lo_80": 10.0, "hi_80": 10.0})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [CUTOFF], "on_hand": [200.0], "reserved": [0.0],
                        "in_transit": [0.0], "unit_cost": [1.0]})
    r = build_recommendations(fc, inv, None, None, None, None, EMPTY_ERRORS, PROFILE, pd.Series(dtype=float),
                              {"default_lead_time_days": 7, "review_period_days": 7}, CUTOFF).iloc[0]
    assert r["assumptions"]["uncertainty"] == "zero_variability"
    assert r["safety_stock"] == 0 and r["demand_lead_time_std"] == 0 and r["stockout_probability"] == 0


def test_empirical_uncertainty_keeps_laplace_rule():
    rng = np.random.default_rng(0)
    rows = []
    for s in range(20):
        for step in range(1, 21):
            rows.append({"unique_id": f"b9-p{s}", "window": 0, "step": step, "actual": 10 + rng.normal(0, 3),
                         "yhat": 10.0, "stockout": False, "scale": 10.0})
    bt = pd.DataFrame(rows)
    em = LeadTimeErrorModel(bt, pd.Series("smooth", index=bt["unique_id"].unique()))
    for on_hand in (0, 10, 140, 1000):
        r = _rec(on_hand=on_hand, error_model=em, intervals=False)
        assert r["assumptions"]["method"] == "empirical_backtest"
        assert 0 < r["stockout_probability"] < 1


def test_empirical_errors_widen_beyond_backtest_horizon():
    rng = np.random.default_rng(1)
    rows = [{"unique_id": f"b9-p{s}", "window": 0, "step": step, "actual": 10 + rng.normal(0, 3), "yhat": 10.0,
             "stockout": False, "scale": 10.0} for s in range(20) for step in range(1, 8)]
    bt = pd.DataFrame(rows)
    em = LeadTimeErrorModel(bt, pd.Series("smooth", index=bt["unique_id"].unique()))
    r = _rec(days=30, on_hand=0, lt=7, review=7, error_model=em, intervals=False)
    samples, days = em.samples_for("smooth", 14)
    assert days == 7 and r["assumptions"]["empirical_error_days"] == 7
    assert r["demand_lead_time_std"] == pytest.approx(np.std(samples * 10.0 * math.sqrt(14 / 7)), rel=1e-3)


def test_projected_risk_rules():
    assert projected_risk_level(1, 7, 14) == "critical"
    assert projected_risk_level(7, 7, 14) == "critical"
    assert projected_risk_level(10, 7, 14) == "high"
    assert projected_risk_level(None, 7, 14, min_balance=5, safety_stock=10) == "medium"
    assert projected_risk_level(30, 7, 14, min_balance=50, safety_stock=10) == "low"


def test_supplier_lead_time_variability_widens_safety_stock():
    """σ del plazo del proveedor: σ² = σ_demanda² + (d̄·σ_L)² (antes se registraba sin usarse)."""
    def rec(lt_std):
        fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "step": range(1, 31),
                           "ds": pd.date_range(CUTOFF + pd.Timedelta(days=1), periods=30), "yhat": 10.0,
                           "lo_80": 6.0, "hi_80": 14.0})
        inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [CUTOFF], "on_hand": [0.0],
                            "reserved": [0.0], "in_transit": [0.0], "unit_cost": [2.0]})
        lts = pd.DataFrame({"supplier_id": [None], "product_id": [1], "branch_id": [1], "lead_time_days": [7],
                            "lead_time_std_days": [lt_std], "valid_from": [None]})
        return build_recommendations(fc, inv, lts, pd.DataFrame(), None, pd.Series(dtype=float), EMPTY_ERRORS,
                                     PROFILE, pd.Series({"b1-p1": 10.0}),
                                     {"review_period_days": 7, "service_level": 0.95}, CUTOFF).iloc[0]

    base, wide = rec(None), rec(3.0)
    z = 1.6448536269514722
    expected = math.sqrt(base["safety_stock"] ** 2 + (z * 10.0 * 3.0) ** 2)
    assert wide["safety_stock"] == pytest.approx(expected, rel=1e-3)
    assert wide["suggested_qty"] > base["suggested_qty"]
    assert wide["assumptions"]["lead_time_variability_units"] == pytest.approx(30.0)
    assert base["assumptions"]["lead_time_variability_units"] is None


def test_shortage_before_receipt_suggests_expediting_when_a_new_order_cannot_arrive_in_time():
    r = _rec(days=30, lt=7, review=7, on_hand=10, orders=_orders(1000.0, 5))  # quiebre día 2, llegada día 5
    texts = " ".join(r["assumptions"]["limitations"])
    assert r["assumptions"]["stockout_before_receipt"] is True
    assert "acelerar la recepción pendiente" in texts
    calm = _rec(days=30, lt=7, review=7, on_hand=500)
    assert "acelerar la recepción pendiente" not in " ".join(calm["assumptions"]["limitations"])

