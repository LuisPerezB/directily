import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.metrics import bias, coverage, mae, rmse, summarize, wape


def test_basic_metrics():
    a, p = [10, 0, 5, 5], [8, 1, 5, 7]
    assert wape(a, p) == pytest.approx((2 + 1 + 0 + 2) / 20)
    assert mae(a, p) == pytest.approx(5 / 4)
    assert rmse(a, p) == pytest.approx(np.sqrt((4 + 1 + 0 + 4) / 4))
    assert bias(a, p) == pytest.approx((-2 + 1 + 0 + 2) / 20)


def test_all_zero_actuals_are_undefined_not_infinite():
    assert wape([0, 0, 0], [1, 0, 2]) is None
    assert bias([0, 0], [1, 1]) is None
    assert mae([0, 0], [1, 1]) == 1.0


def test_intermittent_series_wape_is_finite():
    actual = [0, 0, 3, 0, 0, 0, 1, 0]
    assert wape(actual, [0.5] * 8) == pytest.approx((0.5 * 6 + 2.5 + 0.5) / 4)


def test_coverage():
    assert coverage([1, 2, 3, 4], [0, 0, 0, 5], [1.5, 1.5, 4, 6]) == 0.5


def test_summarize_groups():
    df = pd.DataFrame({"g": ["x", "x", "y"], "actual": [2.0, 0.0, 0.0], "yhat": [1.0, 1.0, 1.0]})
    out = summarize(df, ["g"]).set_index("g")
    assert out.loc["x", "wape"] == pytest.approx(1.0)
    assert np.isnan(out.loc["y", "wape"])
    assert out.loc["x", "n_obs"] == 2
