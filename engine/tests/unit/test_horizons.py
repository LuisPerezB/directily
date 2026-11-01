"""Horizontes 7/14/30/60/90 y abstención explícita (E2-21, demanda)."""

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.rolling import BacktestPlan
from predictily_engine.demand import horizons as hz
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.pipeline.schemas import ExecuteRequest
from predictily_engine.synthetic import generate_company


def _plan(horizon: int, holdout: bool = True) -> BacktestPlan:
    cutoff = pd.Timestamp("2026-10-26")
    return BacktestPlan(horizon, 14, [cutoff - pd.Timedelta(days=horizon * 2)],
                        cutoff - pd.Timedelta(days=horizon) if holdout else None)


def test_domain_supports_the_five_horizons_and_rejects_others():
    assert hz.SUPPORTED_HORIZONS == (7, 14, 30, 60, 90)
    assert ExecuteRequest(tenant_id=1).horizons == [7, 14, 30, 60, 90]
    assert ExecuteRequest(tenant_id=1, horizons=[90, 7, 7]).horizons == [7, 90]
    for bad in ([21], [0], [120], []):
        with pytest.raises(ValueError):
            ExecuteRequest(tenant_id=1, horizons=bad)


def test_every_step_belongs_to_the_smallest_horizon_that_contains_it():
    steps = np.array([1, 7, 8, 14, 15, 30, 31, 60, 61, 90])
    assert hz.horizon_for_step(steps).tolist() == [7, 7, 14, 14, 30, 30, 60, 60, 90, 90]


def test_history_below_twice_h_or_backtest_without_a_window_of_h_days_abstains():
    # 150 días: backtest de 30 días con período final → 7/14/30 respaldados; 60 y 90 no.
    sup = hz.horizon_support(90, history_days=150, plan=_plan(30))
    assert {h: v["status"] for h, v in sup.items()} == {
        7: "ok", 14: "ok", 30: "ok", 60: "insufficient_data", 90: "insufficient_data"}
    assert [r["code"] for r in sup[60]["reasons"]] == ["backtest_horizon_lt_h"]
    assert {r["code"] for r in sup[90]["reasons"]} == {"history_lt_2h", "backtest_horizon_lt_h"}
    assert sup[90]["required_history_days"] == 180 and sup[90]["history_days"] == 150
    assert hz.run_quality(sup) == "degraded"


def test_backtest_window_long_enough_is_still_not_enough_without_twice_the_history():
    # Caso borde: ventanas de 60 días pero solo 100 días de historia → H=60 exige 120.
    sup = hz.horizon_support(60, history_days=100, plan=_plan(60))
    assert sup[60]["status"] == "insufficient_data"
    assert [r["code"] for r in sup[60]["reasons"]] == ["history_lt_2h"]
    assert sup[30]["status"] == "ok"


def test_without_backtest_nothing_is_supported():
    sup = hz.horizon_support(30, history_days=50, plan=None)
    assert set(sup) == {7, 14, 30}
    assert all(v["status"] == "insufficient_data" and v["reasons"][0]["code"] in ("history_lt_2h", "no_backtest")
               for v in sup.values())
    assert hz.run_quality(sup) == "insufficient_data"


def test_without_independent_holdout_the_supported_horizons_are_degraded_not_ok():
    sup = hz.horizon_support(14, history_days=80, plan=_plan(14, holdout=False))
    assert {v["status"] for v in sup.values()} == {"degraded"}
    assert sup[7]["reasons"][0]["code"] == "no_independent_holdout"
    assert hz.run_quality(sup) == "degraded"


def test_ad_hoc_horizon_still_gets_a_status_for_every_step():
    sup = hz.horizon_support(45, history_days=574, plan=_plan(90))
    assert max(sup) == 60, "los pasos 31-45 pertenecen al horizonte de 60 y deben tener estado"


def test_row_status_uses_company_support_and_the_history_of_the_series():
    sup = hz.horizon_support(90, history_days=150, plan=_plan(30))
    steps = np.array([1, 14, 30, 31, 90])
    assert hz.row_status(steps, sup).tolist() == ["ok", "ok", "ok", "insufficient_data", "insufficient_data"]
    # Una serie de 40 días de historia solo respalda H ≤ 20 → 7 y 14; el paso 20 (H=30) ya no.
    assert hz.row_status(np.array([1, 14, 15, 30, 31]), sup, np.full(5, 40)).tolist() == [
        "ok", "ok", "insufficient_data", "insufficient_data", "insufficient_data"]


@pytest.fixture(scope="module")
def short_history_run():
    """~118 días de historia: backtest de 14 días con período final → solo 7 y 14 respaldados."""
    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=6, start="2026-07-01"))
    return run_pipeline(data, horizon=90, seed=42, n_jobs=1)


def test_pipeline_marks_horizons_the_history_does_not_support(short_history_run):
    res = short_history_run
    fv = res.forecast_values
    assert res.plan.horizon == 14 and res.plan.has_holdout
    status = {h: v["status"] for h, v in res.summary["horizon_support"].items()}
    assert status == {"7": "ok", "14": "ok", "30": "insufficient_data", "60": "insufficient_data",
                      "90": "insufficient_data"}
    assert res.summary["max_supported_horizon"] == 14
    assert res.summary["quality_status"] == "degraded"
    codes = res.summary["limitation_codes"]
    assert {"horizon_30_insufficient_data", "horizon_60_insufficient_data", "horizon_90_insufficient_data"} <= set(codes)
    hrows = fv[fv["granularity"] == "horizon"].set_index("period_days")
    assert set(hrows.index) == {7, 14, 30, 60, 90}
    # Sin respaldo: el número existe pero está marcado y NO lleva intervalo (no se extrapola).
    for h in (30, 60, 90):
        assert (hrows.loc[[h], "quality_status"] == "insufficient_data").all()
        assert hrows.loc[[h], ["lo_80", "hi_80", "lo_95", "hi_95"]].isna().all().all()
    for h in (7, 14):
        assert (hrows.loc[[h], "quality_status"] == "ok").all()
        assert hrows.loc[[h], ["lo_80", "hi_80"]].notna().all().all()
    # Ningún paso diario fuera del horizonte respaldado se presenta como completo.
    day = fv[(fv["granularity"] == "day") & (fv["level"] == "total")]
    assert (day.loc[day["horizon_step"] <= 14, "quality_status"] == "ok").all()
    assert (day.loc[day["horizon_step"] > 14, "quality_status"] == "insufficient_data").all()
    assert fv["quality_status"].notna().all(), "toda fila declara su estado"


def test_pipeline_evaluates_the_total_of_each_horizon_and_publishes_n_obs(pipeline_result):
    ev = pd.DataFrame(pipeline_result.evaluations)
    ht = ev[(ev["level"] == "horizon_total") & (ev["evaluation_type"] == "backtest")]
    sel = ht[ht["model_family"] == "selection"].set_index("horizon_days")
    base = ht[ht["model_family"] == "seasonal_naive"].set_index("horizon_days")
    assert sorted(sel.index) == [7, 14, 30, 60, 90] and sorted(base.index) == [7, 14, 30, 60, 90]
    n_series = pipeline_result.backtest.query("is_holdout")["unique_id"].nunique()
    assert (sel["n_obs"] == n_series).all(), "una observación por serie en el único período final"
    daily = ev[(ev["level"] == "overall") & (ev["model_family"] == "selection") & (ev["evaluation_type"] == "backtest")]
    # El error del total de varios días es menor que el del paso diario (los errores se compensan).
    assert sel.loc[90, "wape"] < float(daily["wape"].iat[0])
    assert all(pipeline_result.horizon_support[h]["evaluated_obs"] == n_series for h in (7, 14, 30, 60, 90))


def test_revenue_horizon_totals_are_published_per_horizon(pipeline_result):
    totals = pipeline_result.summary["horizon_totals"]
    assert set(totals) == {"7", "14", "30", "60", "90"}
    assert all(t["status"] == "ok" and t["requested"] for t in totals.values())
    assert totals["7"]["units"] < totals["14"]["units"] < totals["30"]["units"] < totals["60"]["units"] < totals["90"]["units"]
