"""MASE/RMSSE (escala solo de entrenamiento), pinball P10/P50/P90, amplitud de intervalos y dinero."""

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.metrics import pinball_loss, summarize
from predictily_engine.backtest.rolling import BacktestPlan, run_backtest, seasonal_naive_scales
from predictily_engine.demand import evaluation as ev
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket, score_intervals
from predictily_engine.profiling.series import build_panel


def _panel(units, stockout=None, uid="b1-p1", start="2026-01-01") -> pd.DataFrame:
    ds = pd.date_range(start, periods=len(units), freq="D")
    return pd.DataFrame({"unique_id": uid, "ds": ds, "units": np.asarray(units, float),
                         "stockout": np.zeros(len(units), bool) if stockout is None else stockout})


def test_scale_is_the_in_sample_error_of_the_seasonal_naive_7():
    # y_t = t hasta el día 13 y t+7 desde el 14. Diferencias a 7 días (21 pares): 7 (×7), 14 (×7), 7 (×7).
    t = np.arange(28)
    y = np.where(t < 14, t, t + 7)
    s = seasonal_naive_scales(_panel(y), pd.Timestamp("2026-01-28"))
    assert s.loc["b1-p1", "n_pairs"] == 21
    assert s.loc["b1-p1", "mae_scale"] == pytest.approx((7 * 7 + 7 * 14 + 7 * 7) / 21)
    assert s.loc["b1-p1", "mse_scale"] == pytest.approx((7 * 49 + 7 * 196 + 7 * 49) / 21)


def test_scale_ignores_pairs_with_stockout_censored_days():
    t = np.arange(28)
    y = np.where(t < 14, t, t + 7).astype(float)
    so = np.zeros(28, bool)
    so[17] = True  # excluye los pares (17, 10) y (24, 17)
    s = seasonal_naive_scales(_panel(y, so), pd.Timestamp("2026-01-28"))
    assert s.loc["b1-p1", "n_pairs"] == 19
    assert s.loc["b1-p1", "mae_scale"] == pytest.approx((7 * 7 + 6 * 14 + 6 * 7) / 19)


def test_scale_uses_only_training_data_before_each_origin():
    t = np.arange(60)
    y = np.where(t < 28, t % 7, (t % 7) * 3 + 1).astype(float)  # el régimen cambia después del origen
    origin = pd.Timestamp("2026-01-28")  # día 28 de la serie
    before = seasonal_naive_scales(_panel(y), origin)
    shifted = y.copy()
    shifted[28:] = shifted[28:] * 50 + 11  # cualquier cambio posterior al origen
    after = seasonal_naive_scales(_panel(shifted), origin)
    pd.testing.assert_frame_equal(before, after)
    later = seasonal_naive_scales(_panel(y), pd.Timestamp("2026-02-28"))
    assert later.loc["b1-p1", "mae_scale"] != before.loc["b1-p1", "mae_scale"]


def test_scale_is_undefined_not_zero_for_constant_or_very_short_series():
    constant = seasonal_naive_scales(_panel(np.full(40, 5.0)), pd.Timestamp("2026-02-09"))
    assert np.isnan(constant.loc["b1-p1", "mae_scale"]) and np.isnan(constant.loc["b1-p1", "mse_scale"])
    short = seasonal_naive_scales(_panel(np.arange(15.0)), pd.Timestamp("2026-01-15"))  # 8 pares < 14
    assert np.isnan(short.loc["b1-p1", "mae_scale"])


def test_mase_and_rmsse_use_each_rows_scale_and_count_exclusions():
    df = pd.DataFrame({
        "actual": [10.0, 4.0, 6.0, 3.0], "yhat": [12.0, 4.0, 9.0, 5.0],
        "scale_mae": [2.0, 2.0, 3.0, np.nan], "scale_mse": [4.0, 4.0, 9.0, np.nan], "g": 1})
    s = summarize(df, ["g"]).iloc[0]
    # |e|/scale: 2/2, 0/2, 3/3 → MASE = (1+0+1)/3 ; e²/mse: 4/4, 0, 9/9 → RMSSE = √((1+0+1)/3)
    assert s["mase"] == pytest.approx(2 / 3)
    assert s["rmsse"] == pytest.approx(np.sqrt(2 / 3))
    assert s["n_scaled"] == 3 and s["n_scale_undefined"] == 1 and s["n_obs"] == 4
    # Las métricas de siempre no excluyen esa fila: la escala indefinida no oculta el error.
    assert s["wape"] == pytest.approx((2 + 0 + 3 + 2) / 23)


def test_all_scales_undefined_leaves_mase_null_not_zero():
    df = pd.DataFrame({"actual": [1.0, 2.0], "yhat": [1.0, 3.0], "scale_mae": [np.nan, 0.0],
                       "scale_mse": [np.nan, 0.0], "g": 1})
    s = summarize(df, ["g"]).iloc[0]
    assert np.isnan(s["mase"]) and np.isnan(s["rmsse"]) and s["n_scaled"] == 0
    assert ev.build_metrics(s, {"cutoffs": [], "currency": "USD"})["mase"] is None


def test_money_metrics_are_separate_from_units_and_skip_rows_without_price():
    df = pd.DataFrame({
        "actual": [10.0, 10.0, 5.0], "yhat": [8.0, 12.0, 5.0],
        "price_fc": [2.0, 2.0, np.nan], "price_act": [3.0, 1.0, 4.0], "g": 1})
    s = summarize(df, ["g"]).iloc[0]
    # Unidades: |e| = 2, 2, 0 sobre 25 reales.
    assert s["wape"] == pytest.approx(4 / 25)
    # Dinero: real = 30, 10 (la fila sin precio pronosticado se excluye); pronóstico = 16, 24.
    assert s["money_actual"] == pytest.approx(40) and s["money_forecast"] == pytest.approx(40)
    assert s["money_wape"] == pytest.approx((14 + 14) / 40)
    assert s["money_bias"] == pytest.approx(0.0)
    assert s["n_money"] == 2 and s["n_price_missing"] == 1
    m = ev.build_metrics(s, {"cutoffs": ["2026-01-01"], "currency": "USD"})
    assert m["money"]["currency"] == "USD" and m["money"]["wape"] == pytest.approx(0.7)
    assert m["sample"]["exclusions"]["price_missing"] == 1


def test_pinball_loss_definition():
    assert pinball_loss(0.9, [10.0], [8.0]) == pytest.approx(0.9 * 2)  # subestimar cuesta q
    assert pinball_loss(0.9, [6.0], [8.0]) == pytest.approx(0.1 * 2)  # sobrestimar cuesta 1−q
    assert pinball_loss(0.5, [1.0, 3.0], [2.0, 2.0]) == pytest.approx(0.5)
    assert pinball_loss(0.5, [], []) is None


def test_quantile_metrics_report_pinball_width_and_calibration_of_p10_p90():
    scored = pd.DataFrame({
        "actual": [0.0, 5.0, 10.0, 20.0], "yhat": [4.0, 4.0, 4.0, 4.0], "lo_80": [0.0, 1.0, 1.0, 1.0],
        "hi_80": [8.0, 9.0, 9.0, 9.0], "lo_95": [0.0, 0.0, 0.0, 0.0], "hi_95": [12.0, 12.0, 12.0, 12.0],
        "p50": [2.0, 3.0, 3.0, 3.0]})
    q = ev.quantile_metrics(scored)
    assert q["interval_width_80"] == pytest.approx(8.0)
    assert q["interval_width_95"] == pytest.approx(12.0)
    # P90 = hi_80: errores y−f = −8, −4, 1, 11 → pérdidas 0,8; 0,4; 0,9; 9,9.
    assert q["pinball_p90"] == pytest.approx((0.8 + 0.4 + 0.9 + 9.9) / 4)
    # P10 = lo_80: errores 0, 4, 9, 19 → pérdidas 0; 0,4; 0,9; 1,9.
    assert q["pinball_p10"] == pytest.approx((0.4 + 0.9 + 1.9) / 4)
    # P50: errores −2, 2, 7, 17 → 0,5·|e|; la media como mediana: errores −4, 1, 6, 16.
    assert q["pinball_p50"] == pytest.approx((1 + 1 + 3.5 + 8.5) / 4)
    assert q["pinball_p50_yhat_as_median"] == pytest.approx((2 + 0.5 + 3 + 8) / 4)
    assert q["share_actual_le_p10"] == pytest.approx(0.25) and q["share_actual_le_p90"] == pytest.approx(0.5)
    assert q["interval_width_80_rel"] == pytest.approx(32 / 35)
    assert q["n_scored"] == 4


def _zero_inflated(n, seed):
    rng = np.random.default_rng(seed)
    actual = np.where(rng.random(n) < 0.7, 0.0, rng.gamma(3.0, 4.0, n))  # media ≈ 3,6 y mediana 0
    return pd.DataFrame({"group": "g", "step": rng.integers(1, 15, n), "actual": actual, "yhat": 3.6, "scale": 2.0,
                         "stockout": False})


def test_p50_is_published_only_when_the_conformal_median_beats_the_mean_as_median_out_of_sample():
    calib, test = _zero_inflated(3000, 1), _zero_inflated(1200, 2)
    scored = score_intervals(calib, test, "group", day_bucket)
    q = ev.quantile_metrics(scored)
    assert q["pinball_p50"] < q["pinball_p50_yhat_as_median"], "mediana 0 vs media 3,6: la mediana conformal gana"
    decision = ev.p50_decision(q)
    assert decision["published"] and decision["reason"] == "improves_pinball_in_holdout"
    assert decision["n_scored"] == 1200


def test_p50_is_withheld_without_evaluation_improvement_or_sample():
    assert ev.p50_decision(None)["published"] is False
    assert ev.p50_decision(None)["reason"] == "no_evaluated"
    worse = {"pinball_p50": 2.0, "pinball_p50_yhat_as_median": 1.9, "n_scored": 5000}
    assert ev.p50_decision(worse) | {"published": False} == ev.p50_decision(worse)
    assert ev.p50_decision(worse)["reason"] == "no_improvement"
    tiny = {"pinball_p50": 1.0, "pinball_p50_yhat_as_median": 1.9, "n_scored": 50}
    assert ev.p50_decision(tiny)["published"] is False and ev.p50_decision(tiny)["reason"] == "sample_too_small"


def test_absolute_mode_has_no_median():
    rows = _zero_inflated(500, 3)
    out = ConformalCalibrator(rows, "group", day_bucket, "absolute").apply(rows.head(3))
    assert out["p50"].isna().all() and out["lo_80"].notna().all()


def test_pipeline_metrics_json_has_scaled_money_quantile_and_sample_fields(pipeline_result):
    rows = [e for e in pipeline_result.evaluations
            if e["evaluation_type"] == "backtest" and e["level"] == "overall" and e["model_family"] == "selection"]
    assert len(rows) == 1
    m = rows[0]["metrics"]
    assert m["mase"] > 0 and m["rmsse"] > 0 and m["scale"].startswith("naive_estacional_7")
    for k in ("pinball_p10", "pinball_p90", "interval_width_80", "interval_width_95"):
        assert m[k] is not None and m[k] >= 0
    assert m["interval_width_95"] > m["interval_width_80"]
    assert m["sample"]["n_obs"] == rows[0]["n_obs"] and m["sample"]["n_series"] > 0 and m["sample"]["n_windows"] == 1
    assert m["sample"]["exclusions"]["stockout_days"] > 0, "los días con quiebre se excluyen y se cuentan"
    assert m["money"]["actual_total"] > 0 and m["money"]["wape"] is not None
    # P50 solo si se publicó: coherente con la decisión y con lo guardado fila a fila.
    published = pipeline_result.summary["p50"]["published"]
    assert (m["pinball_p50"] is not None) == published
    fv = pipeline_result.forecast_values
    day_series = fv[(fv["granularity"] == "day") & (fv["level"] == "series")]
    assert day_series["p50"].notna().any() == published
    assert fv[fv["level"] != "series"]["p50"].isna().all(), "la suma de medianas no es la mediana de la suma"
    # El baseline también lleva MASE; el seleccionado no empeora al naive de entrenamiento más allá de lo razonable.
    base = next(e for e in pipeline_result.evaluations if e["evaluation_type"] == "backtest"
                and e["level"] == "overall" and e["model_family"] == "seasonal_naive")
    assert base["metrics"]["mase"] > 0 and base["metrics"]["pinball_p10"] is None
    sel_windows = [e for e in pipeline_result.evaluations if e["evaluation_type"] == "selection_windows"
                   and e["level"] == "overall" and e["model_family"] == "selection"][0]
    assert sel_windows["metrics"]["pinball_p10"] is None, "sin prueba independiente no hay pinball"


def test_backtest_scales_and_prices_ignore_data_after_each_origin(small_data):
    panel = build_panel(small_data)
    cutoff = panel.cutoff - pd.Timedelta(days=30)
    plan = BacktestPlan(horizon=14, step=7, cutoffs=[cutoff])
    pert = panel.df.copy()
    after = pert["ds"] > cutoff
    pert.loc[after, "units"] = pert.loc[after, "units"] * 5 + 3
    pert.loc[after, "y"] = pert.loc[after, "units"]
    pert.loc[after, "price"] = pert.loc[after, "price"] * 2
    pert_panel = type(panel)(pert, panel.profile, panel.cutoff)
    fb1 = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, panel.cutoff.date())
    fb2 = FeatureBuilder(pert, small_data.branches, small_data.promotions, panel.cutoff.date())
    b1 = run_backtest(panel, fb1, plan, ["seasonal_naive"], seed=42, n_jobs=1, use_lgbm=False)
    b2 = run_backtest(pert_panel, fb2, plan, ["seasonal_naive"], seed=42, n_jobs=1, use_lgbm=False)
    key = ["unique_id", "ds", "family"]
    m = b1.merge(b2, on=key, suffixes=("_1", "_2"))
    assert len(m) == len(b1) > 0
    for col in ("scale_mae", "scale_mse", "price_fc", "scale"):
        np.testing.assert_allclose(m[f"{col}_1"], m[f"{col}_2"], rtol=1e-12, equal_nan=True)
    assert not np.allclose(m["actual_1"], m["actual_2"]), "lo realizado sí cambió; la escala y el precio del origen no"
    assert not np.allclose(m["price_act_1"], m["price_act_2"])
