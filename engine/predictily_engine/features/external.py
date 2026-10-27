"""Indicadores externos con consulta "as-of" (sin fuga temporal).

Para una fecha d solo puede usarse una observación cuya fecha REAL de publicación sea
≤ d, tomando la revisión más reciente publicada hasta ese momento. Así, en un backtest
con corte T, un IPC de septiembre publicado el 8 de octubre no está disponible el 5 de
octubre. Desactivado por defecto en el modelo (Fase 4): la interfaz y sus pruebas existen
para incorporar INEC/BCE/combustibles cuando haya conectores con datos reales.
"""

from __future__ import annotations

import pandas as pd
from sqlalchemy import Connection, text

OBS_COLUMNS = ["indicator_id", "period_date", "value", "published_at", "revision"]


def as_of_value(observations: pd.DataFrame, as_of: pd.Timestamp) -> float | None:
    """Último valor conocido en `as_of` (período más reciente; última revisión publicada)."""
    known = observations[pd.to_datetime(observations["published_at"]) <= pd.Timestamp(as_of)]
    if known.empty:
        return None
    known = known.sort_values(["period_date", "revision", "published_at"])
    return float(known.iloc[-1]["value"])


def as_of_series(observations: pd.DataFrame, dates: pd.DatetimeIndex) -> pd.Series:
    """Valor conocido en cada fecha (vectorizado con merge_asof sobre published_at)."""
    if observations.empty:
        return pd.Series(index=dates, dtype=float)
    obs = observations.copy()
    obs["published_at"] = pd.to_datetime(obs["published_at"]).dt.tz_localize(None)
    obs = obs.sort_values(["published_at", "period_date", "revision"])
    # A cada momento de publicación le corresponde el período más reciente conocido hasta entonces.
    obs["best_period"] = obs["period_date"].cummax()
    obs = obs[obs["period_date"] == obs["best_period"]]
    left = pd.DataFrame({"ds": pd.DatetimeIndex(dates)}).sort_values("ds")
    merged = pd.merge_asof(left, obs[["published_at", "value"]], left_on="ds", right_on="published_at",
                           direction="backward")
    return pd.Series(merged["value"].to_numpy(dtype=float), index=pd.DatetimeIndex(merged["ds"]))


def load_observations(conn: Connection, tenant_id: int, indicator_code: str) -> pd.DataFrame:
    """Indicadores públicos (tenant_id NULL) o propios de la empresa."""
    result = conn.execute(text(
        "SELECT o.indicator_id, o.period_date, o.value::float8 AS value, o.published_at, o.revision "
        "FROM external_indicator_observations o JOIN external_indicators i ON i.id = o.indicator_id "
        "WHERE i.code = :code AND (i.tenant_id IS NULL OR i.tenant_id = :tenant_id) AND i.is_active"
    ), {"code": indicator_code, "tenant_id": tenant_id})
    return pd.DataFrame(result.fetchall(), columns=OBS_COLUMNS)
