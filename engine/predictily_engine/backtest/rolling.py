"""Validación temporal rolling-origin (nunca división aleatoria).

Para cada corte c_k se entrena SOLO con datos ≤ c_k y se pronostican los h días
siguientes; las exógenas futuras se construyen como en producción (calendario y
promociones conocidas, precio congelado en c_k). Luego se compara con las ventas reales
de días sin quiebre de stock.

Información disponible al corte (F4): en cada ventana la elegibilidad, la actividad y la
clase de demanda se recalculan con `profile_at(panel.df, c_k)`; cada fila del backtest lleva
la clase que su serie tenía en el corte de SU ventana (`demand_class`).

Período final reservado (F6): el plan reserva, cuando la historia alcanza, una ventana
final `holdout` con origen `H = corte − h` y fechas objetivo (H, corte]. Las ventanas de
selección terminan sus fechas objetivo en o antes de H, de modo que no comparten fechas con
el holdout y sus residuos ya son observables en H. La evaluación final usa solo las
ventanas de selección para elegir modelos y calibrar intervalos y mide en el holdout; el
modelo de producción sí puede elegirse con todas las ventanas.
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
from predictily_engine.profiling.series import MIN_HISTORY_DAYS, Panel, profile_at

log = logging.getLogger(__name__)

# (días de historia mínimos para las ventanas de selección, horizonte, paso entre cortes, ventanas).
# Con período final reservado se exigen además `horizonte` días (la ventana holdout).
TIERS = [(300, 90, 28, 3), (200, 60, 21, 3), (120, 30, 14, 3), (70, 14, 7, 2)]


@dataclass
class BacktestPlan:
    horizon: int
    step: int
    cutoffs: list[pd.Timestamp]  # orígenes de las ventanas de SELECCIÓN (y calibración)
    holdout_cutoff: pd.Timestamp | None = None  # origen del período final reservado

    @property
    def windows(self) -> int:
        """Número de ventanas de selección (sin contar el holdout)."""
        return len(self.cutoffs)

    @property
    def has_holdout(self) -> bool:
        return self.holdout_cutoff is not None

    @property
    def holdout_window(self) -> int | None:
        """Índice `window` de la ventana holdout en las filas del backtest."""
        return len(self.cutoffs) if self.has_holdout else None

    @property
    def origins(self) -> list[tuple[int, pd.Timestamp, bool]]:
        """(índice de ventana, corte, es_holdout) en orden temporal."""
        out = [(k, c, False) for k, c in enumerate(self.cutoffs)]
        if self.holdout_cutoff is not None:
            out.append((len(self.cutoffs), self.holdout_cutoff, True))
        return out


def _window_cutoffs(end: pd.Timestamp, h: int, step: int, windows: int) -> list[pd.Timestamp]:
    """Cortes cuya última fecha objetivo (corte + h) es ≤ `end`."""
    last = end - pd.Timedelta(days=h)
    return [last - pd.Timedelta(days=step * k) for k in range(windows - 1, -1, -1)]


def plan_backtest(panel: Panel, max_windows: int = 3, holdout: bool = True) -> BacktestPlan | None:
    """Plan de ventanas. Prefiere el nivel de mayor horizonte que admita un holdout independiente;
    si ninguno lo admite, devuelve el plan sin holdout (el pipeline lo advierte)."""
    if panel.df.empty:
        return None
    n_days = int((panel.cutoff - panel.df["ds"].min()).days) + 1
    if holdout:
        for min_days, h, step, windows in TIERS:
            if n_days >= min_days + h:
                origin = panel.cutoff - pd.Timedelta(days=h)
                return BacktestPlan(h, step, _window_cutoffs(origin, h, step, min(windows, max_windows)), origin)
    for min_days, h, step, windows in TIERS:
        if n_days >= min_days:
            return BacktestPlan(h, step, _window_cutoffs(panel.cutoff, h, step, min(windows, max_windows)))
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


def eligible_ids(panel: Panel, cutoff: pd.Timestamp, profile: pd.DataFrame | None = None) -> list[str]:
    """Series que se entrenan/evalúan al corte: activas, no `insufficient` y con ≥ 28 días.

    Usa el perfil calculado con datos ≤ `cutoff`. Si no se entrega y el corte es anterior al
    del panel, se recalcula (nunca se usa el perfil del corte final para un corte pasado).
    """
    cutoff = pd.Timestamp(cutoff)
    if profile is None:
        profile = panel.profile if cutoff >= panel.cutoff else profile_at(panel.df, cutoff)
    if profile.empty:
        return []
    ok = profile["is_active"].astype(bool) & (profile["demand_class"] != "insufficient")
    first = pd.to_datetime(profile["first_sale_date"])
    ok &= (cutoff - first).dt.days + 1 >= MIN_HISTORY_DAYS
    return profile.loc[ok, "unique_id"].tolist()


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
    """Devuelve filas: unique_id, window, cutoff, ds, step, family, yhat, actual, stockout, scale,
    demand_class (clase de la serie en el corte de la ventana) e is_holdout."""
    stat_fams = [f for f in families if f in STATISTICAL]
    results = []
    origins = plan.origins
    for n, (k, cutoff, is_holdout) in enumerate(origins):
        prof_k = profile_at(panel.df, cutoff)
        ids = eligible_ids(panel, cutoff, prof_k)
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
        fc["demand_class"] = fc["unique_id"].map(prof_k.set_index("unique_id")["demand_class"])
        fc["is_holdout"] = is_holdout
        results.append(fc)
        if progress:
            progress((n + 1) / len(origins))
    if not results:
        return pd.DataFrame(columns=["unique_id", "window", "cutoff", "ds", "step", "family", "yhat", "actual",
                                     "stockout", "scale", "demand_class", "is_holdout"])
    out = pd.concat(results, ignore_index=True)
    out["yhat"] = out["yhat"].astype(float)
    out["is_holdout"] = out["is_holdout"].astype(bool)
    return out


HORIZON_BUCKETS = [("h1-7", 1, 7, 7), ("h8-30", 8, 30, 30), ("h31-90", 31, 90, 90)]


def horizon_bucket(step: pd.Series) -> pd.Series:
    labels = np.select([step <= 7, step <= 30], ["h1-7", "h8-30"], default="h31-90")
    return pd.Series(labels, index=step.index)
