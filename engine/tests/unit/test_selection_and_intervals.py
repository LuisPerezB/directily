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
