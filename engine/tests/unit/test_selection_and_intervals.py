import numpy as np
import pandas as pd
import pytest

from predictily_engine.intervals.conformal import ConformalCalibrator, loo_coverage
from predictily_engine.selection.selector import select_models


def _bt(lgbm_factor: float, windows=3, n_series=6, days=28, seed=0):
    rng = np.random.default_rng(seed)
    rows = []
    for w in range(windows):
        for s in range(n_series):
            actual = rng.poisson(10, days).astype(float) + 10
            base_noise = rng.normal(0, 1, days)
            for fam, err in (("seasonal_naive", 4.0), ("auto_ets", 3.0), ("window_average", 3.5),
                             ("lightgbm_global", 3.0 * lgbm_factor)):
                noise = base_noise * err
                for d in range(days):
                    rows.append({"unique_id": f"b1-p{s}", "window": w, "step": d + 1, "family": fam,
                                 "actual": actual[d], "yhat": max(actual[d] + noise[d], 0), "stockout": False,
                                 "scale": 10.0})
    return pd.DataFrame(rows)


PROFILE = pd.DataFrame({"unique_id": [f"b1-p{s}" for s in range(6)], "demand_class": ["smooth"] * 6})


def test_complex_model_needs_clear_and_stable_improvement():
    sel = select_models(_bt(lgbm_factor=0.99), PROFILE, min_improvement=0.03)
    assert sel.winners["smooth"] == "auto_ets"
    assert "no superó" in sel.reasons["smooth"]
    sel = select_models(_bt(lgbm_factor=0.6), PROFILE, min_improvement=0.03)
    assert sel.winners["smooth"] == "lightgbm_global"


def test_baseline_always_evaluated_and_fallback_simple():
    sel = select_models(_bt(lgbm_factor=0.6), PROFILE)
    assert "seasonal_naive" in set(sel.pooled_table["family"])
    assert sel.fallback in ("seasonal_naive", "window_average")
    assert sel.family_for("insufficient") == "window_average"


def test_conformal_coverage_is_calibrated():
    rng = np.random.default_rng(3)
    n = 6000
    rows = pd.DataFrame({"group": "g", "window": rng.integers(0, 3, n), "step": rng.integers(1, 91, n),
                         "yhat": 50.0, "scale": 10.0})
    rows["actual"] = rows["yhat"] + rng.normal(0, 10, n)
    c80, c95 = loo_coverage(rows)
    assert c80 == pytest.approx(0.80, abs=0.03)
    assert c95 == pytest.approx(0.95, abs=0.02)
    out = ConformalCalibrator(rows).apply(rows.head(5))
    assert (out["lo_95"] <= out["lo_80"]).all() and (out["lo_80"] <= out["yhat"]).all()
    assert (out["hi_80"] <= out["hi_95"]).all() and (out["lo_95"] >= 0).all()


def test_symmetric_mode_and_extrapolation():
    rows = pd.DataFrame({"group": "g", "window": 0, "step": np.tile(np.arange(1, 31), 10), "yhat": 10.0,
                         "scale": 1.0, "actual": 10.0 + np.tile(np.linspace(-2, 2, 30), 10)})
    cal = ConformalCalibrator(rows, mode="absolute")
    near = cal.apply(pd.DataFrame({"group": ["g"], "step": [30], "yhat": [10.0], "scale": [1.0]}))
    far = cal.apply(pd.DataFrame({"group": ["g"], "step": [90], "yhat": [10.0], "scale": [1.0]}))
    assert near["hi_80"].iat[0] - 10 == pytest.approx(10 - near["lo_80"].iat[0])
    assert far["hi_80"].iat[0] > near["hi_80"].iat[0], "más allá del horizonte evaluado el intervalo se ensancha"


def test_selection_uses_class_at_each_window_cutoff():
    """F4: cada fila se agrupa por la clase de su serie en el corte de SU ventana, no al corte final."""
    from predictily_engine.selection.selector import selected_rows

    bt = _bt(lgbm_factor=0.6)
    bt["demand_class"] = np.where(bt["window"] == 0, "intermittent", "smooth")
    final_profile = pd.DataFrame({"unique_id": PROFILE["unique_id"], "demand_class": "lumpy"})
    sel = select_models(bt, final_profile)
    assert "lumpy" not in sel.winners and {"smooth", "intermittent"} <= set(sel.winners)
    rows = selected_rows(bt, final_profile, sel)
    w0 = rows[rows["window"] == 0]
    assert (w0["demand_class"] == "intermittent").all()
    assert set(w0["family"]) == {sel.family_for("intermittent")}
    # Sin columna de clase por ventana se mantiene el comportamiento anterior (clase del perfil).
    legacy = select_models(bt.drop(columns=["demand_class"]), PROFILE)
    assert legacy.winners["smooth"] == "lightgbm_global"


def test_holdout_coverage_is_calibrated_only_with_earlier_residuals():
    """F6: el intervalo evaluado en el holdout se calibra solo con residuos observables en su origen."""
    from predictily_engine.intervals.conformal import holdout_coverage, score_intervals

    rng = np.random.default_rng(5)

    def rows(n, sd, window):
        out = pd.DataFrame({"group": "g", "window": window, "step": rng.integers(1, 91, n), "yhat": 100.0,
                            "scale": 10.0})
        out["actual"] = out["yhat"] + rng.normal(0, sd, n)
        return out

    calib = rows(4000, 10, 0)
    same = rows(4000, 10, 1)
    c80, c95 = holdout_coverage(calib, same)
    assert c80 == pytest.approx(0.80, abs=0.03) and c95 == pytest.approx(0.95, abs=0.02)
    # Si el período evaluado es más volátil, la cobertura cae: sus residuos no se usaron para calibrar.
    wild = rows(4000, 30, 1)
    w80, _ = holdout_coverage(calib, wild)
    assert w80 < 0.45
    scored = score_intervals(calib, wild)
    pd.testing.assert_series_equal(scored["hi_80"].reset_index(drop=True),
                                   score_intervals(calib, wild.assign(actual=0.0))["hi_80"].reset_index(drop=True))
    assert holdout_coverage(calib.iloc[0:0], wild) == (None, None)
