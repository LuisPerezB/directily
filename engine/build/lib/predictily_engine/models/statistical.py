"""Nivel A: modelos estadísticos (StatsForecast)."""

from __future__ import annotations

import os

import numpy as np
import pandas as pd

os.environ.setdefault("NIXTLA_ID_AS_COL", "1")

from statsforecast import StatsForecast  # noqa: E402
from statsforecast.models import ADIDA, TSB, AutoETS, CrostonSBA, Naive, SeasonalNaive, WindowAverage  # noqa: E402

from predictily_engine.models.registry import STATISTICAL  # noqa: E402


def _build(families: list[str]):
    makers = {
        "naive": lambda: Naive(alias="naive"),
        "seasonal_naive": lambda: SeasonalNaive(season_length=7, alias="seasonal_naive"),
        "window_average": lambda: WindowAverage(window_size=28, alias="window_average"),
        "auto_ets": lambda: AutoETS(season_length=7, model="ZNZ", alias="auto_ets"),
        "croston_sba": lambda: CrostonSBA(alias="croston_sba"),
        "adida": lambda: ADIDA(alias="adida"),
        "tsb": lambda: TSB(alpha_d=0.2, alpha_p=0.2, alias="tsb"),
    }
    return [makers[f]() for f in families if f in makers]


def forecast_statistical(train: pd.DataFrame, h: int, families: list[str] | None = None) -> pd.DataFrame:
    """Pronostica h días para cada serie de `train` (unique_id, ds, y).

    Devuelve formato largo: unique_id, ds, family, yhat (≥ 0). Si un modelo falla en una
    serie, StatsForecast usa SeasonalNaive como respaldo.
    """
    families = [f for f in (families or STATISTICAL) if f in STATISTICAL]
    if train.empty or not families:
        return pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    sf = StatsForecast(models=_build(families), freq="D", n_jobs=1,
                       fallback_model=SeasonalNaive(season_length=7))
    fc = sf.forecast(h=h, df=train[["unique_id", "ds", "y"]])
    long = fc.melt(id_vars=["unique_id", "ds"], value_vars=[f for f in families if f in fc.columns],
                   var_name="family", value_name="yhat")
    long["yhat"] = np.clip(long["yhat"].astype(float).fillna(0.0), 0, None)
    return long


def window_average_short(train: pd.DataFrame, cutoff: pd.Timestamp, h: int) -> pd.DataFrame:
    """Promedio simple para series con historia insuficiente (< 28 días).

    Sin evaluación posible: se informa como limitación.
    """
    if train.empty:
        return pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    means = train.groupby("unique_id")["y"].mean().clip(lower=0)
    days = pd.date_range(cutoff + pd.Timedelta(days=1), periods=h, freq="D")
    return pd.DataFrame({
        "unique_id": np.repeat(means.index.to_numpy(), h),
        "ds": np.tile(days.values, len(means)),
        "family": "window_average",
        "yhat": np.repeat(means.to_numpy(), h),
    })
