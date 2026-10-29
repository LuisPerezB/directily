"""Prevención de fuga temporal: nada posterior al corte puede influir en lo calculado al corte."""

import numpy as np
import pandas as pd
import pandas.testing as pdt

from predictily_engine.backtest.rolling import BacktestPlan, run_backtest
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.profiling.series import Panel, build_panel


def _perturb_after(panel: Panel, cutoff: pd.Timestamp) -> Panel:
    df = panel.df.copy()
    after = df["ds"] > cutoff
    rng = np.random.default_rng(1)
    df.loc[after, "y"] = rng.integers(0, 500, after.sum()).astype(float)
    df.loc[after, "units"] = df.loc[after, "y"]
    df.loc[after, "price"] = df.loc[after, "price"] * 3
    return Panel(df, panel.profile, panel.cutoff)


def test_features_do_not_use_future_data(small_data):
    panel = build_panel(small_data)
    cutoff = panel.cutoff - pd.Timedelta(days=60)
    ids = panel.profile["unique_id"].tolist()[:6]
    fb1 = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, panel.cutoff.date())
    pert = _perturb_after(panel, cutoff)
    fb2 = FeatureBuilder(pert.df, small_data.branches, small_data.promotions, panel.cutoff.date())
    tr1, ref1 = fb1.training_frame(cutoff, ids)
    tr2, ref2 = fb2.training_frame(cutoff, ids)
    pdt.assert_frame_equal(tr1, tr2)
    pdt.assert_frame_equal(fb1.future_frame(cutoff, ids, 30, ref1), fb2.future_frame(cutoff, ids, 30, ref2))
    assert tr1["ds"].max() <= cutoff


def test_backtest_predictions_ignore_data_after_cutoff(small_data):
    panel = build_panel(small_data)
    cutoff = panel.cutoff - pd.Timedelta(days=30)
    plan = BacktestPlan(horizon=14, step=7, cutoffs=[cutoff])
    pert = _perturb_after(panel, cutoff)
    fb1 = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, panel.cutoff.date())
    fb2 = FeatureBuilder(pert.df, small_data.branches, small_data.promotions, panel.cutoff.date())
    fams = ["seasonal_naive", "window_average", "auto_ets", "lightgbm_global"]
    bt1 = run_backtest(panel, fb1, plan, fams, seed=42, n_jobs=2, use_lgbm=True)
    bt2 = run_backtest(pert, fb2, plan, fams, seed=42, n_jobs=2, use_lgbm=True)
    key = ["unique_id", "ds", "family"]
    m = bt1.merge(bt2, on=key, suffixes=("_orig", "_pert"))
    assert len(m) == len(bt1) > 0
    assert set(bt1["family"]) >= {"seasonal_naive", "lightgbm_global"}
    np.testing.assert_allclose(m["yhat_orig"], m["yhat_pert"], rtol=1e-9, atol=1e-9)
    # Los reales sí cambian (prueba de que la perturbación ocurrió en el período evaluado).
    assert not np.allclose(m["actual_orig"], m["actual_pert"])


def _panel_with_days(n_days: int) -> Panel:
    cutoff = pd.Timestamp("2026-10-26")
    ds = pd.date_range(cutoff - pd.Timedelta(days=n_days - 1), cutoff, freq="D")
    return Panel(pd.DataFrame({"unique_id": "b1-p1", "ds": ds}), pd.DataFrame(), cutoff)


def test_plan_reserves_disjoint_holdout_or_degrades():
    from predictily_engine.backtest.rolling import plan_backtest

    for n_days, horizon in ((730, 90), (390, 90), (389, 60), (150, 30), (84, 14)):
        plan = plan_backtest(_panel_with_days(n_days))
        assert plan.has_holdout and plan.horizon == horizon, n_days
        cutoff = pd.Timestamp("2026-10-26")
        assert plan.holdout_cutoff == cutoff - pd.Timedelta(days=horizon)
        # Las ventanas de selección terminan sus fechas objetivo en o antes del origen del holdout.
        assert max(plan.cutoffs) + pd.Timedelta(days=horizon) <= plan.holdout_cutoff
        assert [k for k, _, h in plan.origins if h] == [plan.holdout_window]
    plan = plan_backtest(_panel_with_days(80))  # alcanza para 2 ventanas de 14 días, no para el holdout
    assert plan is not None and not plan.has_holdout and plan.horizon == 14
    assert plan_backtest(_panel_with_days(60)) is None
