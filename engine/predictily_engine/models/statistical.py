"""Nivel A: modelos estadísticos (StatsForecast)."""

from __future__ import annotations

import os

import numpy as np
import pandas as pd

os.environ.setdefault("NIXTLA_ID_AS_COL", "1")

from statsforecast import StatsForecast  # noqa: E402
from statsforecast.models import ADIDA, TSB, AutoETS, CrostonSBA, Naive, SeasonalNaive, WindowAverage  # noqa: E402

from predictily_engine.models.holidays import HOLIDAY_UNADJUSTED, apply_factors, clean_training  # noqa: E402
from predictily_engine.models.registry import (  # noqa: E402
    STATISTICAL,
    WEEKDAY_AVERAGE,
    WEEKDAY_AVERAGE_K,
    WEEKDAY_PROFILE_DAYS,
    WEEKDAY_PROFILE_SHRINK,
    WEEKDAY_SUFFIX,
)


def _recent_by_weekday(train: pd.DataFrame, days: int) -> pd.DataFrame:
    """Filas de los últimos `days` días de `train` (unique_id, ds, y) con su día de la semana (`dow`, 0 = lunes)."""
    t = train[["unique_id", "ds", "y"]].copy()
    t["ds"] = pd.to_datetime(t["ds"])
    recent = t[t["ds"] > t["ds"].max() - pd.Timedelta(days=days)].copy()
    recent["dow"] = recent["ds"].dt.dayofweek
    return recent


def _common_factors(recent: pd.DataFrame) -> np.ndarray:
    total = recent.groupby("dow")["y"].sum().reindex(range(7), fill_value=0.0).astype(float)
    return (total / total.mean()).to_numpy() if total.mean() > 0 else np.ones(7)


def common_weekday_profile(train: pd.DataFrame, days: int = WEEKDAY_PROFILE_DAYS) -> np.ndarray:
    """Ritmo semanal del negocio: 7 factores (índice 0 = lunes) con media 1.

    Unidades de todas las series de `train` (unique_id, ds, y) por día de la semana en los últimos `days` días, entre su media.
    Es el perfil común hacia el que `weekday_profile` encoge el de cada serie, y el que usan las series sin perfil propio (nuevas,
    de historia escasa y las servidas con el respaldo plano; ver `pipeline/core.py`). Sin ventas en la ventana, factores 1."""
    if train.empty:
        return np.ones(7)
    return _common_factors(_recent_by_weekday(train, days))


def weekday_profile(train: pd.DataFrame, days: int = WEEKDAY_PROFILE_DAYS,
                    shrink: float = WEEKDAY_PROFILE_SHRINK) -> pd.DataFrame:
    """Factores por día de la semana (0 = lunes) de cada serie, con media 1 en la semana.

    Se estiman en los últimos `days` días del entrenamiento: el perfil de la serie (venta media de cada día de la semana entre su
    media) se encoge hacia el perfil común de todas las series (el ritmo semanal del negocio) con peso n / (n + `shrink`), n = días
    con venta de la serie en la ventana. Así una serie con poca venta toma el ritmo del negocio y una regular, el suyo. Una serie
    sin datos en la ventana usa el perfil común; sin ventas en absoluto, factores 1.
    Devuelve unique_id, dow, factor."""
    if train.empty:
        return pd.DataFrame(columns=["unique_id", "dow", "factor"])
    recent = _recent_by_weekday(train, days)
    common = _common_factors(recent)
    by_dow = recent.groupby(["unique_id", "dow"])["y"].mean().unstack().reindex(columns=range(7))
    level = by_dow.mean(axis=1)
    own = by_dow.div(level.where(level > 0), axis=0).to_numpy(dtype=float)
    own = np.where(np.isnan(own), common[None, :], own)
    n = (recent["y"] > 0).groupby(recent["unique_id"]).sum().reindex(by_dow.index).fillna(0).to_numpy(dtype=float)
    weight = (n / (n + shrink))[:, None]
    factors = weight * own + (1 - weight) * common[None, :]
    mean = factors.mean(axis=1, keepdims=True)
    factors = np.where(mean > 0, factors / np.where(mean > 0, mean, 1.0), 1.0)
    out = pd.DataFrame(factors, index=by_dow.index, columns=range(7)).stack().rename("factor").reset_index()
    out.columns = ["unique_id", "dow", "factor"]
    absent = sorted(set(train["unique_id"]) - set(out["unique_id"]))
    if absent:
        out = pd.concat([out, pd.DataFrame({"unique_id": np.repeat(absent, 7), "dow": np.tile(np.arange(7), len(absent)),
                                            "factor": np.tile(common, len(absent))})], ignore_index=True)
    return out


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


def weekday_average(train: pd.DataFrame, h: int, k: int = WEEKDAY_AVERAGE_K) -> pd.DataFrame:
    """Familia `weekday_average`: para cada serie y día del horizonte, la media de sus últimas `k` observaciones del mismo día de la semana.

    Con menos de `k` observaciones de ese día usa las que haya; sin ninguna (serie de menos de una semana), 0. Devuelve unique_id, ds,
    family, yhat. Las series de `train` son diarias y contiguas hasta el corte (el panel las construye así)."""
    cols = ["unique_id", "ds", "family", "yhat"]
    if train.empty or h <= 0:
        return pd.DataFrame(columns=cols)
    t = train[["unique_id", "ds", "y"]].copy()
    t["ds"] = pd.to_datetime(t["ds"])
    cutoff = t["ds"].max()
    recent = t[t["ds"] > cutoff - pd.Timedelta(days=7 * k)]
    level = recent.assign(dow=recent["ds"].dt.dayofweek).groupby(["unique_id", "dow"])["y"].mean()
    ids = t["unique_id"].drop_duplicates().to_numpy()
    days = pd.date_range(cutoff + pd.Timedelta(days=1), periods=h, freq="D")
    out = pd.DataFrame({"unique_id": np.repeat(ids, h), "ds": np.tile(days.values, len(ids))})
    key = pd.MultiIndex.from_arrays([out["unique_id"], out["ds"].dt.dayofweek])
    out["family"] = WEEKDAY_AVERAGE
    out["yhat"] = np.clip(level.reindex(key).fillna(0.0).to_numpy(dtype=float), 0, None)
    return out[cols]


def _forecast(train: pd.DataFrame, h: int, families: list[str]) -> pd.DataFrame:
    """Pronóstico de `families` (ya filtradas a estadísticas) sobre `train` tal cual llega."""
    custom = [f for f in families if f == WEEKDAY_AVERAGE]
    profiled = [f for f in families if f.endswith(WEEKDAY_SUFFIX)]
    base = list(dict.fromkeys([f for f in families if f not in profiled and f not in custom]
                              + [f.removesuffix(WEEKDAY_SUFFIX) for f in profiled]))
    if base:
        sf = StatsForecast(models=_build(base), freq="D", n_jobs=1,
                           fallback_model=SeasonalNaive(season_length=7))
        fc = sf.forecast(h=h, df=train[["unique_id", "ds", "y"]])
        long = fc.melt(id_vars=["unique_id", "ds"], value_vars=[f for f in base if f in fc.columns],
                       var_name="family", value_name="yhat")
        long["yhat"] = np.clip(long["yhat"].astype(float).fillna(0.0), 0, None)
    else:
        long = pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    if profiled:
        extra = long[long["family"].isin([f.removesuffix(WEEKDAY_SUFFIX) for f in profiled])].copy()
        extra["dow"] = pd.to_datetime(extra["ds"]).dt.dayofweek
        extra = extra.merge(weekday_profile(train), on=["unique_id", "dow"], how="left")
        extra["yhat"] = extra["yhat"] * extra["factor"].fillna(1.0)
        extra["family"] = extra["family"] + WEEKDAY_SUFFIX
        long = pd.concat([long, extra[long.columns]], ignore_index=True)
    if custom:
        long = pd.concat([long, weekday_average(train, h)], ignore_index=True)
    return long[long["family"].isin(families)].reset_index(drop=True)


def forecast_statistical(train: pd.DataFrame, h: int, families: list[str] | None = None,
                         holidays: pd.DataFrame | None = None, info: dict | None = None) -> pd.DataFrame:
    """Pronostica h días para cada serie de `train` (unique_id, ds, y).

    Devuelve formato largo: unique_id, ds, family, yhat (≥ 0). Si un modelo falla en una
    serie, StatsForecast usa SeasonalNaive como respaldo.

    Las familias `<base>_dow` son la familia base (plana: no ve el día de la semana) multiplicada por el perfil semanal de cada
    serie (`weekday_profile`); conservan el total semanal de la base y solo lo reparten como vende el negocio. `weekday_average` es la
    media de los últimos mismos días de la semana de cada serie.

    `holidays` (unique_id, ds, holiday; ver `models/holidays.py`): ajuste de feriados. Las familias que no son referencia se ajustan con
    el entrenamiento limpio de feriados pasados y sus días feriados del horizonte se multiplican por el factor estimado de cada feriado;
    `naive` y `seasonal_naive` quedan intactas. Sin `holidays` (por defecto) el resultado es exactamente el de siempre. `info`, si se
    entrega, recibe los factores usados (`holiday_factors`).
    """
    families = [f for f in (families or STATISTICAL) if f in STATISTICAL]
    if train.empty or not families:
        return pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    if holidays is None or holidays.empty:
        return _forecast(train, h, families)
    plain = [f for f in families if f in HOLIDAY_UNADJUSTED]
    adjusted = [f for f in families if f not in HOLIDAY_UNADJUSTED]
    parts = []
    if plain:
        parts.append(_forecast(train, h, plain))
    if adjusted:
        cleaned, factors = clean_training(train[["unique_id", "ds", "y"]], holidays)
        if info is not None:
            info["holiday_factors"] = factors
        parts.append(apply_factors(_forecast(cleaned, h, adjusted), holidays, factors))
    return pd.concat(parts, ignore_index=True)
