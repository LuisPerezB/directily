import math

import numpy as np
import pandas as pd
import pytest

from predictily_engine.inventory.recommendations import (
    LeadTimeErrorModel,
    build_recommendations,
    resolve_lead_time,
    risk_level,
)

LT = pd.DataFrame([
    {"supplier_id": 1, "product_id": 10, "branch_id": 2, "lead_time_days": 9, "lead_time_std_days": 2.0},
    {"supplier_id": 1, "product_id": 10, "branch_id": np.nan, "lead_time_days": 5, "lead_time_std_days": 1.0},
    {"supplier_id": 1, "product_id": 11, "branch_id": np.nan, "lead_time_days": 6, "lead_time_std_days": None},
    {"supplier_id": 2, "product_id": np.nan, "branch_id": np.nan, "lead_time_days": 12, "lead_time_std_days": None},
    {"supplier_id": 2, "product_id": 13, "branch_id": 1, "lead_time_days": 4, "lead_time_std_days": None},
])
SUP = pd.DataFrame({"supplier_id": [1, 2, 3], "code": ["A", "B", "C"], "default_lead_time_days": [8, 15, 20]})


def test_lead_time_resolution_priority():
    assert resolve_lead_time(10, 2, LT, SUP, 7)[2] == "product_branch"
    assert resolve_lead_time(10, 1, LT, SUP, 7)[:1] == (5,)
    assert resolve_lead_time(10, 1, LT, SUP, 7)[2] == "product"
    # Producto 13 solo tiene fila para la sucursal 1: en la sucursal 3 se usa el plazo del proveedor 2.
    assert resolve_lead_time(13, 3, LT, SUP, 7) == (12, None, "supplier")
    assert resolve_lead_time(99, 1, LT, SUP, 7) == (7, None, "tenant_default")


def test_risk_levels():
    assert risk_level(0.05, 10, 5) == "low"
    assert risk_level(0.2, 10, 5) == "medium"
    assert risk_level(0.4, 10, 5) == "high"
    assert risk_level(0.7, 10, 5) == "critical"
    assert risk_level(0.0, 0, 5) == "critical"


def _fc(daily: float, days: int = 90):
    cutoff = pd.Timestamp("2026-10-26")
    return pd.DataFrame({"unique_id": "b1-p10", "branch_id": 1, "product_id": 10, "step": range(1, days + 1),
                         "ds": pd.date_range(cutoff + pd.Timedelta(days=1), periods=days), "yhat": daily,
                         "lo_80": daily * 0.6, "hi_80": daily * 1.4}), cutoff


def test_recommendation_math_normal_approximation():
    fc, cutoff = _fc(10.0)
    inv = pd.DataFrame({"branch_id": [1], "product_id": [10], "ds": [cutoff], "on_hand": [50.0], "reserved": [5.0],
                        "in_transit": [0.0], "unit_cost": [2.0]})
    em = LeadTimeErrorModel(pd.DataFrame(columns=["unique_id", "window", "step", "actual", "yhat", "stockout",
                                                  "scale"]), pd.Series(dtype=object))
    prof = pd.DataFrame({"unique_id": ["b1-p10"], "demand_class": ["smooth"]})
    recs = build_recommendations(fc, inv, LT, SUP, None, pd.Series(dtype=float), em, prof,
                                 pd.Series({"b1-p10": 10.0}),
                                 {"service_level": 0.95, "review_period_days": 7, "default_lead_time_days": 7}, cutoff)
    r = recs.iloc[0]
    assert r["lead_time_days"] == 5 and r["lead_time_source"] == "product"
    L = 5 + 7
    assert r["demand_lead_time"] == pytest.approx(10 * L)
    sigma_d = (14 - 6) / (2 * 1.2816)
    assert r["demand_lead_time_std"] == pytest.approx(math.sqrt(L) * sigma_d, rel=1e-3)
    assert r["safety_stock"] == pytest.approx(1.6449 * math.sqrt(L) * sigma_d, rel=1e-3)
    assert r["available"] == 45.0
    assert r["suggested_qty"] == math.ceil(r["reorder_point"] - 45.0)
    assert r["estimated_investment"] == pytest.approx(r["suggested_qty"] * 2.0)
    assert r["days_of_cover"] == pytest.approx(4.5)
    assert str(r["stockout_date_estimate"]) == "2026-10-31"
    assert r["risk_level"] == "critical" and r["type"] == "stockout_risk"


def test_no_inventory_means_no_recommendations():
    fc, cutoff = _fc(3.0)
    em = LeadTimeErrorModel(pd.DataFrame(columns=["unique_id", "window", "step", "actual", "yhat", "stockout",
                                                  "scale"]), pd.Series(dtype=object))
    out = build_recommendations(fc, pd.DataFrame(), LT, SUP, None, None, em, pd.DataFrame(
        {"unique_id": ["b1-p10"], "demand_class": ["smooth"]}), pd.Series(dtype=float), {}, cutoff)
    assert out.empty
