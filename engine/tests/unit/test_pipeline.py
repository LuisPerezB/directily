"""Pipeline completo en memoria sobre datos sintéticos (sin base de datos)."""

import numpy as np
import pandas as pd
import pytest

from predictily_engine.pipeline.core import InsufficientDataError, run_pipeline


def test_outputs_and_levels(pipeline_result):
    fv = pipeline_result.forecast_values
    day = fv[fv["granularity"] == "day"]
    assert set(day["level"]) == {"series", "product", "category", "branch", "branch_category", "total"}
    assert day["horizon_step"].max() == 90
    assert (fv["yhat"] >= 0).all()
    assert (day["lo_80"] <= day["yhat"] + 1e-6).all() and (day["yhat"] <= day["hi_80"] + 1e-6).all()
    assert not fv.duplicated(["node_key", "granularity", "target_date"]).any()
    hz = fv[fv["granularity"] == "horizon"]
    assert set(hz["period_days"]) == {7, 30, 90} and hz["lo_80"].notna().all()
    assert {"week", "month"} <= set(fv["granularity"])


def test_hierarchy_is_coherent(pipeline_result):
    day = pipeline_result.forecast_values.query("granularity == 'day'")
    by = lambda lvl: day[day["level"] == lvl].groupby("target_date")["yhat"].sum()  # noqa: E731
    total = by("total")
    for lvl in ("series", "product", "branch"):
        np.testing.assert_allclose(by(lvl).loc[total.index], total, rtol=1e-6, atol=1e-3)
    # suma de series de un producto = nodo producto
    series = day[day["level"] == "series"].groupby(["product_id", "target_date"])["yhat"].sum()
    product = day[day["level"] == "product"].set_index(["product_id", "target_date"])["yhat"]
    np.testing.assert_allclose(series.loc[product.index], product, rtol=1e-6, atol=1e-3)
    # revenue también se agrega
    rev_series = day[day["level"] == "series"]["revenue_yhat"].sum()
    assert rev_series == pytest.approx(day[day["level"] == "total"]["revenue_yhat"].sum(), rel=1e-6)


def test_compares_against_baseline(pipeline_result):
    ev = pd.DataFrame(pipeline_result.evaluations)
    overall = ev[ev["level"] == "overall"]
    base = overall[overall["is_baseline"]]
    sel = overall[overall["model_family"] == "selection"]
    assert len(base) == 1 and base["model_family"].iat[0] == "seasonal_naive"
    assert len(sel) == 1 and bool(sel["is_selected"].iat[0])
    assert sel["wape"].iat[0] <= base["wape"].iat[0] + 1e-9
    assert sel["coverage_80"].iat[0] is not None
    assert set(ev[ev["level"] == "horizon"]["level_key"]) == {"h1-7", "h8-30", "h31-90"}
    assert ev[ev["level"] == "series"]["level_key"].str.match(r"^b\d+-p\d+$").all()
    assert ev[ev["level"] == "product"]["level_key"].str.match(r"^p\d+$").all()


def test_interval_coverage_is_reasonable(pipeline_result):
    m = pipeline_result.summary["metrics"]
    assert 0.65 <= m["coverage_80"] <= 0.92
    assert 0.85 <= m["coverage_95"] <= 0.99


def test_reproducible_with_same_seed(small_data, pipeline_result):
    again = run_pipeline(small_data, horizon=90, seed=42, n_jobs=4)
    a = pipeline_result.forecast_values.sort_values(["node_key", "granularity", "target_date"]).reset_index(drop=True)
    b = again.forecast_values.sort_values(["node_key", "granularity", "target_date"]).reset_index(drop=True)
    pd.testing.assert_frame_equal(a, b)
    assert again.training_data_hash == pipeline_result.training_data_hash


def test_recommendations_and_censoring(pipeline_result):
    recs = pipeline_result.recommendations
    assert not recs.empty
    assert (recs["suggested_qty"] >= 0).all()
    assert (recs["reorder_point"] >= recs["demand_lead_time"] - 1e-6).all()
    assert recs["risk_level"].isin(["low", "medium", "high", "critical"]).all()
    assert (recs["stockout_probability"].between(0, 1)).all()
    prof = pipeline_result.panel.profile
    assert prof["stockout_days"].sum() > 0


def test_insufficient_data_raises(small_data):
    import copy

    empty = copy.copy(small_data)
    empty.sales = small_data.sales.iloc[0:0]
    with pytest.raises(InsufficientDataError):
        run_pipeline(empty)
