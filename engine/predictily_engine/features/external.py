"""Indicadores externos con consulta "as-of" (sin fuga temporal).

Para una fecha d solo puede usarse una observación cuya fecha REAL de publicación sea
≤ d, tomando la revisión más reciente publicada hasta ese momento. Así, en un backtest
con corte T, un IPC de septiembre publicado el 8 de octubre no está disponible el 5 de
octubre. Desactivado por defecto en el modelo (Fase 4): la interfaz y sus pruebas existen
para incorporar INEC/BCE/combustibles cuando haya conectores con datos reales.
"""

from __future__ import annotations

import pandas as pd
from sqlalchemy import Connection

OBS_COLUMNS = ["indicator_id", "period_date", "value", "published_at", "revision"]


def _utc_naive(values):
    """Instantes con o sin zona → UTC sin zona (los sin zona se asumen UTC); `load_observations` los devuelve con zona (R-170)."""
    out = pd.to_datetime(values, utc=True)
    return out.dt.tz_localize(None) if isinstance(values, pd.Series) else out.tz_localize(None)


def as_of_value(observations: pd.DataFrame, as_of: pd.Timestamp) -> float | None:
    """Último valor conocido en `as_of` (período más reciente; última revisión publicada).

    Adaptador heredado (se conserva: V25-FAC-069). El lector con estados, antigüedad y frontera del día local de la empresa es
    `availability.FeatureAvailabilityValidator`; aquí solo se normalizan las zonas horarias para que `published_at` con zona no falle."""
    known = observations[_utc_naive(observations["published_at"]) <= _utc_naive(pd.Timestamp(as_of))]
    if known.empty:
        return None
    known = known.sort_values(["period_date", "revision", "published_at"])
    return float(known.iloc[-1]["value"])


def as_of_series(observations: pd.DataFrame, dates: pd.DatetimeIndex) -> pd.Series:
    """Valor conocido en cada fecha (vectorizado con merge_asof sobre published_at)."""
    if observations.empty:
        return pd.Series(index=dates, dtype=float)
    obs = observations.copy()
    obs["published_at"] = _utc_naive(obs["published_at"])
    obs = obs.sort_values(["published_at", "period_date", "revision"])
    # A cada momento de publicación le corresponde el período más reciente conocido hasta entonces.
    obs["best_period"] = obs["period_date"].cummax()
    obs = obs[obs["period_date"] == obs["best_period"]]
    left = pd.DataFrame({"ds": pd.DatetimeIndex(dates)}).sort_values("ds")
    merged = pd.merge_asof(left, obs[["published_at", "value"]], left_on="ds", right_on="published_at",
                           direction="backward")
    return pd.Series(merged["value"].to_numpy(dtype=float), index=pd.DatetimeIndex(merged["ds"]))


def load_observations(conn: Connection, tenant_id: int, indicator_code: str, precedence: str | None = None) -> pd.DataFrame:
    """Observaciones de UN indicador: el público (tenant_id NULL) o el propio de la empresa, nunca combinados.

    Antes se leían juntos los indicadores público y privado que compartían código (R-178). Ahora la resolución es por
    identificador (`db.factors_repository.FactorsRepository`): con dos candidatos hace falta `precedence` ('tenant' o 'public');
    sin ella falla con `AmbiguousIndicatorError`. Sin indicador activo devuelve un marco vacío, como antes."""
    from predictily_engine.db.factors_repository import AmbiguousIndicatorError, FactorsRepository

    repo = FactorsRepository(conn, tenant_id)
    try:
        ref = repo.resolve_indicator(code=indicator_code, precedence=precedence)
    except AmbiguousIndicatorError:
        raise
    except LookupError:
        return pd.DataFrame(columns=OBS_COLUMNS)
    return repo.indicator_observations(int(ref["id"]))[OBS_COLUMNS].reset_index(drop=True)
