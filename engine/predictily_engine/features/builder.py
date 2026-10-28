"""Ingeniería de características (feature set versionado).

Reglas anti-fuga temporal:
- Las variables de calendario y las promociones registradas son conocidas de antemano y
  pueden usarse en el futuro.
- El precio futuro NO se conoce: en la predicción (y en cada ventana de backtest) se
  congela en el último precio neto observado hasta el corte (supuesto explícito).
- Las referencias (precio mediano, nivel medio de la serie) se calculan solo con datos
  hasta el corte.
- Lags y medias móviles los calcula MLForecast de forma recursiva (solo pasado).
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd

from predictily_engine.calendar.ecuador import EcuadorCalendar
from predictily_engine.config import FEATURE_SET_VERSION

CALENDAR_FEATURES = list(EcuadorCalendar.FEATURES)
COMMERCIAL_FEATURES = ["promo_discount", "price_ratio"]
STATIC_FEATURES = ["branch_code_num", "category_num", "region_num", "series_level"]
LAGS = [1, 7, 14, 28]
DATE_FEATURES = ["dayofweek", "month", "day"]
PRICE_ASSUMPTION = "último precio neto promedio observado (28 días) hasta el corte"


def feature_registry() -> list[dict]:
    """Definiciones registradas en forecast_features (documentación y trazabilidad)."""
    rows = [
        {"name": f, "kind": "calendar", "description": f"Indicador de calendario Ecuador: {f}", "config": {}}
        for f in CALENDAR_FEATURES
    ]
    rows += [
        {"name": "dayofweek", "kind": "calendar", "description": "Día de la semana", "config": {}},
        {"name": "month", "kind": "calendar", "description": "Mes del año", "config": {}},
        {"name": "day", "kind": "calendar", "description": "Día del mes (quincenas/fin de mes)", "config": {}},
    ]
    rows += [
        {"name": f"lag{lag}", "kind": "lag", "description": f"Ventas (demanda imputada) hace {lag} días",
         "config": {"lag": lag}} for lag in LAGS
    ]
    rows += [
        {"name": "rolling_mean_lag1_w7", "kind": "rolling", "description": "Media móvil 7 días", "config": {"window": 7}},
        {"name": "rolling_mean_lag1_w28", "kind": "rolling", "description": "Media móvil 28 días", "config": {"window": 28}},
        {"name": "rolling_std_lag1_w28", "kind": "rolling", "description": "Desviación móvil 28 días", "config": {"window": 28}},
        {"name": "seasonal_rolling_mean_lag7_s7_w4", "kind": "rolling",
         "description": "Media del mismo día de semana (4 semanas)", "config": {"season": 7, "window": 4}},
        {"name": "promo_discount", "kind": "commercial",
         "description": "Descuento de promociones registradas (conocido a futuro)", "config": {}},
        {"name": "price_ratio", "kind": "commercial",
         "description": "Precio neto / precio mediano de la serie; a futuro se congela en el último precio",
         "config": {"assumption": PRICE_ASSUMPTION}},
        {"name": "branch_code_num", "kind": "static", "description": "Sucursal", "config": {}},
        {"name": "category_num", "kind": "static", "description": "Categoría del producto", "config": {}},
        {"name": "region_num", "kind": "geographic", "description": "Región (Costa/Sierra) de la sucursal", "config": {}},
        {"name": "series_level", "kind": "static", "description": "Nivel medio log de la serie hasta el corte", "config": {}},
        {"name": "external_indicators", "kind": "external",
         "description": "Indicadores macro as-of (desactivado por defecto; Fase 4)", "config": {"enabled": False}},
    ]
    for r in rows:
        r["feature_set_version"] = FEATURE_SET_VERSION
    return rows


def calendar_by_branch(branches: pd.DataFrame, start: date, end: date) -> pd.DataFrame:
    """Calendario por sucursal (los feriados locales y el régimen regional varían)."""
    frames = []
    cache: dict[tuple, pd.DataFrame] = {}
    for row in branches.itertuples(index=False):
        key = (getattr(row, "region", None), (row.canton or "").lower() if isinstance(row.canton, str) else None)
        if key not in cache:
            cache[key] = EcuadorCalendar(region=key[0], canton=key[1]).frame(start, end)
        cal = cache[key].copy()
        cal["branch_id"] = int(row.branch_id)
        frames.append(cal.rename_axis("ds").reset_index())
    if not frames:
        return pd.DataFrame(columns=["ds", "branch_id", *CALENDAR_FEATURES])
    return pd.concat(frames, ignore_index=True)


def promo_discounts(promotions: pd.DataFrame, series: pd.DataFrame, start: date, end: date) -> pd.DataFrame:
    """Máximo descuento promocional por serie y día (desde la tabla promotions)."""
    cols = ["unique_id", "ds", "promo_discount"]
    if promotions is None or promotions.empty or series.empty:
        return pd.DataFrame(columns=cols)
    out = []
    start_ts, end_ts = pd.Timestamp(start), pd.Timestamp(end)
    for pr in promotions.itertuples(index=False):
        lo, hi = max(pd.Timestamp(pr.starts_on), start_ts), min(pd.Timestamp(pr.ends_on), end_ts)
        if lo > hi or pd.isna(pr.discount_pct):
            continue
        mask = np.ones(len(series), dtype=bool)
        if not pd.isna(pr.branch_id):
            mask &= series["branch_id"].to_numpy() == int(pr.branch_id)
        if not pd.isna(pr.product_id):
            mask &= series["product_id"].to_numpy() == int(pr.product_id)
        if not pd.isna(pr.category_id):
            mask &= series["category_id"].to_numpy() == int(pr.category_id)
        ids = series.loc[mask, "unique_id"].to_numpy()
        if len(ids) == 0:
            continue
        days = pd.date_range(lo, hi, freq="D")
        out.append(pd.DataFrame({
            "unique_id": np.repeat(ids, len(days)),
            "ds": np.tile(days.values, len(ids)),
            "promo_discount": float(pr.discount_pct),
        }))
    if not out:
        return pd.DataFrame(columns=cols)
    return pd.concat(out).groupby(["unique_id", "ds"], as_index=False)["promo_discount"].max()


class FeatureBuilder:
    """Construye entradas de entrenamiento y de predicción para el modelo global."""

    def __init__(self, panel_df: pd.DataFrame, branches: pd.DataFrame, promotions: pd.DataFrame,
                 horizon_end: date) -> None:
        self.panel_df = panel_df
        start = panel_df["ds"].min().date()
        self.series = panel_df.drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id", "category_id"]]
        self.calendar = calendar_by_branch(branches, start, horizon_end)
        self.promos = promo_discounts(promotions, self.series, start, horizon_end)
        regions = branches.set_index("branch_id")["region"].map({"costa": 1, "sierra": 2}).fillna(0)
        self.region_map = regions.to_dict()

    def _exog(self, keys: pd.DataFrame) -> pd.DataFrame:
        df = keys.merge(self.calendar, on=["branch_id", "ds"], how="left")
        df = df.merge(self.promos, on=["unique_id", "ds"], how="left")
        # Sin promociones el merge deja la columna como object (todo NaN): forzar numérico.
        df["promo_discount"] = pd.to_numeric(df["promo_discount"], errors="coerce").fillna(0.0).astype(float)
        for c in CALENDAR_FEATURES:
            df[c] = df[c].fillna(0).astype(np.int8)
        return df

    def _statics(self, train: pd.DataFrame) -> pd.DataFrame:
        level = train.groupby("unique_id")["y"].mean().apply(lambda v: float(np.log1p(max(v, 0.0))))
        st = self.series.set_index("unique_id").loc[level.index].copy()
        st["series_level"] = level
        st["branch_code_num"] = st["branch_id"].astype(int)
        st["category_num"] = st["category_id"].fillna(0).astype(int)
        st["region_num"] = st["branch_id"].map(self.region_map).fillna(0).astype(int)
        return st[STATIC_FEATURES].reset_index()

    def training_frame(self, cutoff: pd.Timestamp, ids: list[str]) -> tuple[pd.DataFrame, pd.Series]:
        """Datos ≤ corte con exógenas, estáticas y peso (0 en días con quiebre de stock)."""
        train = self.panel_df[(self.panel_df["ds"] <= cutoff) & self.panel_df["unique_id"].isin(ids)]
        ref_price = train.groupby("unique_id")["price"].median()
        df = self._exog(train[["unique_id", "branch_id", "ds", "y", "price", "stockout"]])
        df["price_ratio"] = (df["price"] / df["unique_id"].map(ref_price)).replace([np.inf, -np.inf], np.nan)
        df["price_ratio"] = df["price_ratio"].fillna(1.0).clip(0.2, 5.0)
        df["weight"] = np.where(df["stockout"], 0.0, 1.0)
        df = df.merge(self._statics(train), on="unique_id", how="left")
        cols = ["unique_id", "ds", "y", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES, *STATIC_FEATURES, "weight"]
        return df[cols].sort_values(["unique_id", "ds"]).reset_index(drop=True), ref_price

    def future_frame(self, cutoff: pd.Timestamp, ids: list[str], h: int, ref_price: pd.Series) -> pd.DataFrame:
        """Exógenas para los h días siguientes al corte (precio congelado al corte)."""
        series = self.series[self.series["unique_id"].isin(ids)]
        days = pd.date_range(cutoff + pd.Timedelta(days=1), periods=h, freq="D")
        keys = pd.DataFrame({
            "unique_id": np.repeat(series["unique_id"].to_numpy(), h),
            "branch_id": np.repeat(series["branch_id"].to_numpy(), h),
            "ds": np.tile(days.values, len(series)),
        })
        df = self._exog(keys)
        last_price = last_known_price(self.panel_df, cutoff, ids)
        df["price_ratio"] = (df["unique_id"].map(last_price) / df["unique_id"].map(ref_price)).fillna(1.0).clip(0.2, 5.0)
        return df[["unique_id", "ds", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES]]


def last_known_price(panel_df: pd.DataFrame, cutoff: pd.Timestamp, ids: list[str] | None = None,
                     window_days: int = 28) -> pd.Series:
    """Precio neto unitario medio en los últimos `window_days` hasta el corte (por serie)."""
    df = panel_df[panel_df["ds"] <= cutoff]
    if ids is not None:
        df = df[df["unique_id"].isin(ids)]
    recent = df[df["ds"] > cutoff - pd.Timedelta(days=window_days)]
    weighted = recent.assign(rev=recent["price"] * recent["units"]).groupby("unique_id")[["rev", "units"]].sum()
    price = (weighted["rev"] / weighted["units"]).where(weighted["units"] > 0)
    fallback = df.groupby("unique_id")["price"].last()
    return price.reindex(fallback.index).fillna(fallback)
