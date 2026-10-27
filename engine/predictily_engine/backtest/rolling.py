"""Validación temporal rolling-origin (nunca división aleatoria).

Para cada corte c_k se entrena SOLO con datos ≤ c_k y se pronostican los h días
siguientes; las exógenas futuras se construyen como en producción (calendario y
promociones conocidas, precio congelado en c_k). Luego se compara con las ventas reales
de días sin quiebre de stock.
"""

from __future__ import annotations

import logging
from collections.abc import Callable
from dataclasses import dataclass

import numpy as np
import pandas as pd

from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import STATISTICAL
from predictily_engine.models.statistical import forecast_statistical
from predictily_engine.profiling.series import MIN_HISTORY_DAYS, Panel

log = logging.getLogger(__name__)

# (días de historia mínimos, horizonte, paso entre cortes, ventanas)
TIERS = [(300, 90, 28, 3), (200, 60, 21, 3), (120, 30, 14, 3), (70, 14, 7, 2)]


@dataclass
class BacktestPlan:
    horizon: int
    step: int
    cutoffs: list[pd.Timestamp]

    @property
    def windows(self) -> int:
        return len(self.cutoffs)


def plan_backtest(panel: Panel, max_windows: int = 3) -> BacktestPlan | None:
    if panel.df.empty:
        return None
    n_days = int((panel.cutoff - panel.df["ds"].min()).days) + 1
    for min_days, h, step, windows in TIERS:
        if n_days >= min_days:
            windows = min(windows, max_windows)
            last = panel.cutoff - pd.Timedelta(days=h)
            cutoffs = [last - pd.Timedelta(days=step * k) for k in range(windows - 1, -1, -1)]
            return BacktestPlan(h, step, cutoffs)
    return None


def series_scale(panel_df: pd.DataFrame, cutoff: pd.Timestamp, ids: list[str] | None = None) -> pd.Series:
    """Escala por serie al corte: media de 28 días (o 90) sin quiebre; mínimo 0.05."""
    df = panel_df[panel_df["ds"] <= cutoff]
    if ids is not None:
        df = df[df["unique_id"].isin(ids)]
    clean = df[~df["stockout"]]
    m28 = clean[clean["ds"] > cutoff - pd.Timedelta(days=28)].groupby("unique_id")["y"].mean()
    m90 = clean[clean["ds"] > cutoff - pd.Timedelta(days=90)].groupby("unique_id")["y"].mean()
    allm = df.groupby("unique_id")["y"].mean()
    scale = m28.reindex(allm.index).fillna(m90).fillna(allm)
    return scale.clip(lower=0.05)


def eligible_ids(panel: Panel, cutoff: pd.Timestamp) -> list[str]:
    prof = panel.profile
    ok = prof["is_active"] & (prof["demand_class"] != "insufficient")
    first = pd.to_datetime(prof["first_sale_date"])
    ok &= (cutoff - first).dt.days + 1 >= MIN_HISTORY_DAYS
    return prof.loc[ok, "unique_id"].tolist()


def run_backtest(
    panel: Panel,
    features: FeatureBuilder,
    plan: BacktestPlan,
    families: list[str],
    seed: int,
    n_jobs: int,
    use_lgbm: bool,
    progress: Callable[[float], None] | None = None,
) -> pd.DataFrame:
    """Devuelve filas: unique_id, window, cutoff, ds, step, family, yhat, actual, stockout, scale."""
    stat_fams = [f for f in families if f in STATISTICAL]
    results = []
    for k, cutoff in enumerate(plan.cutoffs):
        ids = eligible_ids(panel, cutoff)
        if not ids:
            continue
        train = panel.df[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(ids)]
        preds = [forecast_statistical(train[["unique_id", "ds", "y"]], plan.horizon, stat_fams)]
        if use_lgbm and LightGBMGlobal.can_train(train):
            try:
                tr, ref = features.training_frame(cutoff, ids)
                model = LightGBMGlobal(seed=seed, n_jobs=n_jobs).fit(tr)
                preds.append(model.predict(plan.horizon, features.future_frame(cutoff, ids, plan.horizon, ref)))
            except Exception:  # el backtest continúa con los estadísticos
                log.exception("LightGBM falló en la ventana %s", cutoff.date())
        fc = pd.concat(preds, ignore_index=True)
        end = cutoff + pd.Timedelta(days=plan.horizon)
        test = panel.df[(panel.df["ds"] > cutoff) & (panel.df["ds"] <= end) & panel.df["unique_id"].isin(ids)]
        fc = fc.merge(test[["unique_id", "ds", "units", "stockout"]], on=["unique_id", "ds"], how="inner")
        fc = fc.rename(columns={"units": "actual"})
        fc["step"] = (fc["ds"] - cutoff).dt.days.astype(int)
        fc["window"] = k
        fc["cutoff"] = cutoff
        fc["scale"] = fc["unique_id"].map(series_scale(panel.df, cutoff, ids))
        results.append(fc)
        if progress:
            progress((k + 1) / plan.windows)
    if not results:
        return pd.DataFrame(columns=["unique_id", "window", "cutoff", "ds", "step", "family", "yhat", "actual",
                                     "stockout", "scale"])
    out = pd.concat(results, ignore_index=True)
    out["yhat"] = out["yhat"].astype(float)
    return out


HORIZON_BUCKETS = [("h1-7", 1, 7, 7), ("h8-30", 8, 30, 30), ("h31-90", 31, 90, 90)]


def horizon_bucket(step: pd.Series) -> pd.Series:
    labels = np.select([step <= 7, step <= 30], ["h1-7", "h8-30"], default="h31-90")
    return pd.Series(labels, index=step.index)
