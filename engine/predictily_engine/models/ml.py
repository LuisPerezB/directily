"""Nivel B: modelo global LightGBM por empresa (MLForecast).

Un único modelo aprende de todas las series de la empresa (sucursal × producto) usando
lags, medias móviles, calendario de Ecuador, promociones, precio relativo y variables
estáticas. Los días con quiebre de stock tienen peso 0 en el entrenamiento. Objetivo
Tweedie, adecuado para conteos con ceros. Pronóstico recursivo.

El entrenamiento compartido entre empresas (modelo global multi-tenant, Nivel B/C) NO se
realiza en el MVP: requeriría consentimiento (tenants.allow_shared_training) y una base
jurídica adecuada.
"""

from __future__ import annotations

import warnings

import lightgbm as lgb
import numpy as np
import pandas as pd
from mlforecast import MLForecast
from mlforecast.lag_transforms import RollingMean, RollingStd, SeasonalRollingMean

from predictily_engine.features.builder import DATE_FEATURES, LAGS, STATIC_FEATURES

MIN_SERIES = 5
MIN_TRAIN_ROWS = 1500


def lgbm_params(seed: int, n_jobs: int) -> dict:
    return {
        "objective": "tweedie",
        "tweedie_variance_power": 1.2,
        "n_estimators": 350,
        "learning_rate": 0.05,
        "num_leaves": 31,
        "min_child_samples": 40,
        "subsample": 0.8,
        "subsample_freq": 1,
        "colsample_bytree": 0.8,
        "reg_lambda": 1.0,
        "random_state": seed,
        "deterministic": True,
        "force_row_wise": True,
        "n_jobs": n_jobs,
        "verbose": -1,
    }


class LightGBMGlobal:
    family = "lightgbm_global"

    def __init__(self, seed: int = 42, n_jobs: int = 4) -> None:
        self.seed = seed
        self.n_jobs = n_jobs
        self.params = lgbm_params(seed, n_jobs)
        self.mlf: MLForecast | None = None

    @staticmethod
    def can_train(train: pd.DataFrame) -> bool:
        return train["unique_id"].nunique() >= MIN_SERIES and len(train) >= MIN_TRAIN_ROWS

    def fit(self, train: pd.DataFrame) -> "LightGBMGlobal":
        self.mlf = MLForecast(
            models={self.family: lgb.LGBMRegressor(**self.params)},
            freq="D",
            lags=LAGS,
            lag_transforms={
                1: [RollingMean(window_size=7), RollingMean(window_size=28), RollingStd(window_size=28)],
                7: [SeasonalRollingMean(season_length=7, window_size=4)],
            },
            date_features=DATE_FEATURES,
            num_threads=1,
        )
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            self.mlf.fit(train, static_features=STATIC_FEATURES, weight_col="weight")
        return self

    def predict(self, h: int, future_exog: pd.DataFrame) -> pd.DataFrame:
        assert self.mlf is not None, "modelo no entrenado"
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            fc = self.mlf.predict(h=h, X_df=future_exog)
        out = fc.rename(columns={self.family: "yhat"})[["unique_id", "ds", "yhat"]].copy()
        out["yhat"] = np.clip(out["yhat"].astype(float), 0, None)
        out["family"] = self.family
        return out

    def feature_importance(self, top: int = 15) -> dict[str, float]:
        if self.mlf is None:
            return {}
        model = self.mlf.models_[self.family]
        names = model.booster_.feature_name()
        gains = model.booster_.feature_importance(importance_type="gain")
        total = float(gains.sum()) or 1.0
        pairs = sorted(zip(names, gains), key=lambda kv: -kv[1])[:top]
        return {k: round(float(v) / total, 4) for k, v in pairs}
