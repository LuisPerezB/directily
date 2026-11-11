"""Ajuste de feriados para los modelos estadísticos (interruptor `holiday_adjustment_models`, apagado por defecto).

Los modelos estadísticos solo ven fechas y unidades: no saben que un día del horizonte es feriado (lo pronostican como un día normal
de su día de la semana) y, peor, aprenden la caída de un feriado pasado como si fuera demanda normal (el naive estacional la repite
cada semana; ETS la mete en su nivel). LightGBM sí recibe el calendario (`is_holiday` y afines). Este módulo cierra esa diferencia con
tres pasos, todos con información disponible al corte:

1. Limpieza del entrenamiento (`clean_training`): la venta de cada feriado pasado se reemplaza por lo esperable ese día sin feriado:
   la media de los mismos días de la semana de las 4 semanas anteriores y las 4 posteriores de la misma serie (sin contar otros
   feriados). Sin vecinos, el día queda como está.
2. Factores (`holiday_factors`): por nombre de feriado, venta real / venta esperable de todas las series del negocio en los feriados
   de los últimos `HOLIDAY_FACTOR_DAYS` días, encogido hacia el factor común de todos los feriados con peso n / (n + 1), n = ocurrencias
   (fechas) de ese feriado. Un feriado que el entrenamiento no ha visto usa el factor común; sin ningún feriado, factor 1.
3. Aplicación (`apply_factors`): los días feriados del horizonte se multiplican por el factor de su feriado.

Qué días cuentan como feriado lo decide el llamador (`features.builder.FeatureBuilder.holiday_days`): el calendario de descanso
obligatorio de la sucursal de cada serie, sin los días pasados que el panel excluye (cierre, fuente ausente o quiebre: su venta ya
está imputada con un día normal y no dice nada del feriado).

Limitaciones: un solo factor por feriado para todo el negocio (no por sucursal ni por producto); no ajusta la víspera ni el día
siguiente; las referencias `naive` y `seasonal_naive` se dejan intactas (son la vara de medir).
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

HOLIDAY_COLUMNS = ["unique_id", "ds", "holiday"]
HOLIDAY_NEIGHBOR_WEEKS = 4
HOLIDAY_FACTOR_DAYS = 1100          # ~3 años: tres ocurrencias de cada feriado anual
HOLIDAY_NAME_SHRINK = 1.0
HOLIDAY_FACTOR_MAX = 3.0
# Familias que NO se ajustan: son la referencia contra la que se mide a las demás.
HOLIDAY_UNADJUSTED = ("naive", "seasonal_naive")
_OFFSETS = [7 * k for k in range(1, HOLIDAY_NEIGHBOR_WEEKS + 1)] + [-7 * k for k in range(1, HOLIDAY_NEIGHBOR_WEEKS + 1)]


def empty_holidays() -> pd.DataFrame:
    return pd.DataFrame({"unique_id": pd.Series(dtype=object), "ds": pd.Series(dtype="datetime64[ns]"),
                         "holiday": pd.Series(dtype=object)})


def expected_without_holiday(train: pd.DataFrame, holidays: pd.DataFrame) -> pd.DataFrame:
    """Feriados de `train` (unique_id, ds, y) con la venta esperable sin feriado.

    Devuelve unique_id, ds, holiday, y, expected (NaN si la serie no tiene ningún mismo día de la semana cercano que no sea feriado)."""
    cols = [*HOLIDAY_COLUMNS, "y", "expected"]
    if train.empty or holidays is None or holidays.empty:
        return pd.DataFrame(columns=cols)
    t = train[["unique_id", "ds", "y"]].merge(holidays[HOLIDAY_COLUMNS].drop_duplicates(["unique_id", "ds"]),
                                             on=["unique_id", "ds"], how="left")
    is_holiday = t["holiday"].notna().to_numpy()
    if not is_holiday.any():
        return pd.DataFrame(columns=cols)
    clean = t.loc[~is_holiday].set_index(["unique_id", "ds"])["y"].astype(float)
    hol = t.loc[is_holiday, ["unique_id", "ds", "holiday", "y"]].reset_index(drop=True)
    neighbors = np.vstack([
        clean.reindex(pd.MultiIndex.from_arrays([hol["unique_id"], hol["ds"] + pd.Timedelta(days=off)])).to_numpy(dtype=float)
        for off in _OFFSETS])
    seen = (~np.isnan(neighbors)).sum(axis=0)
    hol["expected"] = np.where(seen > 0, np.nansum(neighbors, axis=0) / np.maximum(seen, 1), np.nan)
    return hol[cols]


def holiday_factors(observed: pd.DataFrame, cutoff: pd.Timestamp | None = None, days: int = HOLIDAY_FACTOR_DAYS) -> dict[str, Any]:
    """Factores por feriado a partir de `expected_without_holiday`: {"common": factor común, "by_name": {feriado: factor},
    "occurrences": {feriado: fechas vistas}}. Factor = venta real / venta esperable del negocio en ese feriado, encogido hacia el común."""
    out: dict[str, Any] = {"common": 1.0, "by_name": {}, "occurrences": {}}
    if observed.empty:
        return out
    obs = observed[observed["expected"].notna() & (observed["expected"] > 0)]
    if cutoff is not None:
        obs = obs[obs["ds"] > pd.Timestamp(cutoff) - pd.Timedelta(days=days)]
    if obs.empty or obs["expected"].sum() <= 0:
        return out
    common = float(np.clip(obs["y"].sum() / obs["expected"].sum(), 0.0, HOLIDAY_FACTOR_MAX))
    out["common"] = common
    g = obs.groupby("holiday").agg(y=("y", "sum"), expected=("expected", "sum"), n=("ds", "nunique"))
    weight = g["n"] / (g["n"] + HOLIDAY_NAME_SHRINK)
    factor = (weight * (g["y"] / g["expected"]) + (1 - weight) * common).clip(0.0, HOLIDAY_FACTOR_MAX)
    out["by_name"] = {str(k): float(v) for k, v in factor.items()}
    out["occurrences"] = {str(k): int(v) for k, v in g["n"].items()}
    return out


def clean_training(train: pd.DataFrame, holidays: pd.DataFrame) -> tuple[pd.DataFrame, dict[str, Any]]:
    """`train` (unique_id, ds, y) con los feriados pasados reemplazados por su venta esperable, y los factores estimados con ellos.

    Solo usa filas de `train` (datos ≤ corte): un feriado posterior al corte no interviene en la limpieza ni en los factores."""
    observed = expected_without_holiday(train, holidays)
    cutoff = pd.to_datetime(train["ds"]).max() if len(train) else None
    factors = holiday_factors(observed, cutoff)
    usable = observed[observed["expected"].notna()]
    if usable.empty:
        return train, factors
    fixed = usable.set_index(["unique_id", "ds"])["expected"]
    out = train.copy()
    replacement = fixed.reindex(pd.MultiIndex.from_frame(out[["unique_id", "ds"]])).to_numpy(dtype=float)
    out["y"] = np.where(np.isnan(replacement), out["y"].to_numpy(dtype=float), replacement)
    return out, factors


def apply_factors(forecast: pd.DataFrame, holidays: pd.DataFrame, factors: dict[str, Any]) -> pd.DataFrame:
    """Multiplica `yhat` de los días feriados de `forecast` (unique_id, ds, family, yhat) por el factor de su feriado."""
    if forecast.empty or holidays is None or holidays.empty:
        return forecast
    names = holidays.drop_duplicates(["unique_id", "ds"]).set_index(["unique_id", "ds"])["holiday"]
    name = names.reindex(pd.MultiIndex.from_frame(forecast[["unique_id", "ds"]])).to_numpy(dtype=object)
    is_holiday = pd.notna(name)
    if not is_holiday.any():
        return forecast
    by_name, common = factors.get("by_name", {}), float(factors.get("common", 1.0))
    mult = np.ones(len(forecast))
    mult[is_holiday] = [by_name.get(str(n), common) for n in name[is_holiday]]
    out = forecast.copy()
    out["yhat"] = np.clip(out["yhat"].to_numpy(dtype=float) * mult, 0, None)
    return out
