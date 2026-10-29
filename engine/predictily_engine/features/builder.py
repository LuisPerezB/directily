"""Ingeniería de características (feature set versionado).

Reglas anti-fuga temporal:
- Las variables de calendario son conocidas de antemano y pueden usarse en el futuro.
- Promociones (regla as-of): para fechas ≤ corte se usa la promoción tal como ocurrió
  (última versión registrada; es un hecho observado). Para fechas > corte solo cuentan las
  promociones que el negocio ya conocía al corte (`known_at` ≤ corte) y con los valores de
  la versión vigente ese día (`promotion_revisions.recorded_at` ≤ corte). Una promoción
  registrada o modificada después del corte no altera el pronóstico de ese corte.
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
         "description": "Descuento de promociones; a futuro solo las conocidas al corte (known_at y versión vigente)",
         "config": {"availability": "as_of_cutoff"}},
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


PROMO_COLUMNS = ["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id"]


def _naive_datetimes(values: pd.Series) -> pd.Series:
    """Fechas-hora sin zona (se esperan en hora local de la empresa; con zona se pasan a UTC)."""
    out = pd.to_datetime(values, errors="coerce")
    if getattr(out.dt, "tz", None) is not None:
        out = out.dt.tz_convert("UTC").dt.tz_localize(None)
    return out


def _deleted(promotions: pd.DataFrame) -> pd.Series:
    mask = pd.Series(False, index=promotions.index)
    if "deleted_at" in promotions:
        mask |= promotions["deleted_at"].notna()
    if "deleted" in promotions:
        mask |= promotions["deleted"].fillna(False).astype(bool)
    return mask


def promotion_known_on(promotions: pd.DataFrame) -> pd.Series:
    """Día (local) desde el que el negocio conocía cada promoción: `known_at`; si falta, `created_at`.

    Sin ninguna de las dos la disponibilidad es desconocida (NaT) y la promoción NO se usa a
    futuro: su fecha de inicio no demuestra que estuviera planificada al corte.
    """
    known = pd.Series(pd.NaT, index=promotions.index, dtype="datetime64[ns]")
    for col in ("known_at", "created_at"):
        if col in promotions:
            known = known.where(known.notna(), _naive_datetimes(promotions[col]))
    return known.dt.normalize()


def observed_promotions(promotions: pd.DataFrame | None) -> pd.DataFrame:
    """Promociones tal como ocurrieron (última versión, sin las dadas de baja): hechos para fechas ≤ corte."""
    if promotions is None or promotions.empty:
        return pd.DataFrame(columns=PROMO_COLUMNS)
    return promotions.loc[~_deleted(promotions)]


def promotions_as_of(promotions: pd.DataFrame | None, revisions: pd.DataFrame | None, known_on) -> pd.DataFrame:
    """Promociones conocidas al final del día `known_on`, con los valores de la versión vigente ese día.

    - Solo cuentan las promociones cuya fecha de disponibilidad (`known_at`, o `created_at`) es ≤ `known_on`.
    - Con revisiones: la última registrada (`recorded_at`) hasta ese día. Si ninguna es anterior (histórico
      importado después con su fecha real de planificación) se usa la primera versión registrada.
    - Sin revisiones: la fila actual, salvo que ya estuviera dada de baja (`deleted_at`) ese día.
    - Una versión de baja (`deleted`) excluye la promoción.
    """
    if promotions is None or promotions.empty:
        return pd.DataFrame(columns=PROMO_COLUMNS)
    as_of = pd.Timestamp(known_on).normalize()
    candidates = promotions.loc[(promotion_known_on(promotions) <= as_of).to_numpy()]
    if candidates.empty:
        return pd.DataFrame(columns=PROMO_COLUMNS)
    chosen = []
    versioned = pd.Series(False, index=candidates.index)
    if revisions is not None and not revisions.empty:
        rev = revisions[revisions["promotion_id"].isin(candidates["promotion_id"])]
        if not rev.empty:
            rev = rev.sort_values(["promotion_id", "revision"])
            recorded_on = _naive_datetimes(rev["recorded_at"]).dt.normalize()
            prior = rev[(recorded_on <= as_of).to_numpy()].groupby("promotion_id").tail(1)
            first = rev.groupby("promotion_id").head(1)
            current = pd.concat([prior, first[~first["promotion_id"].isin(prior["promotion_id"])]])
            current = current[~_deleted(current)]
            chosen.append(current[PROMO_COLUMNS])
            versioned = candidates["promotion_id"].isin(rev["promotion_id"])
    plain = candidates.loc[~versioned]
    if "deleted_at" in plain:
        gone = _naive_datetimes(plain["deleted_at"]).dt.normalize() <= as_of
        plain = plain.loc[~gone.to_numpy()]
    if "deleted" in plain:
        plain = plain.loc[~plain["deleted"].fillna(False).astype(bool)]
    chosen.append(plain[PROMO_COLUMNS])
    return pd.concat(chosen, ignore_index=True)


def promo_discounts(promotions: pd.DataFrame, series: pd.DataFrame, start: date, end: date) -> pd.DataFrame:
    """Máximo descuento promocional por serie y día entre `start` y `end` (ambos incluidos)."""
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
                 horizon_end: date, promotion_revisions: pd.DataFrame | None = None,
                 promotions_known_on: date | None = None) -> None:
        """`promotion_revisions`: versiones históricas (promotion_id, revision, recorded_at, deleted y alcance).
        `promotions_known_on`: día de la ejecución vigente; solo amplía lo conocido para el corte final
        (los cortes anteriores, p. ej. del backtest, usan lo conocido en su propio día)."""
        self.panel_df = panel_df
        start = panel_df["ds"].min().date()
        self.horizon_end = horizon_end
        self.data_end = panel_df["ds"].max()
        self.series = panel_df.drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id", "category_id"]]
        self.calendar = calendar_by_branch(branches, start, horizon_end)
        self.promotions = promotions if promotions is not None else pd.DataFrame(columns=PROMO_COLUMNS)
        self.promotion_revisions = promotion_revisions if promotion_revisions is not None else pd.DataFrame()
        self.promotions_known_on = pd.Timestamp(promotions_known_on) if promotions_known_on is not None else None
        # Fechas ≤ corte: promociones tal como ocurrieron (hechos observados).
        self.promos = promo_discounts(observed_promotions(self.promotions), self.series, start, horizon_end)
        self._future_promos: dict[tuple[pd.Timestamp, pd.Timestamp], pd.DataFrame] = {}
        regions = branches.set_index("branch_id")["region"].map({"costa": 1, "sierra": 2}).fillna(0)
        self.region_map = regions.to_dict()

    def knowledge_date(self, cutoff: pd.Timestamp) -> pd.Timestamp:
        """Día hasta el que se conocen las promociones al pronosticar desde `cutoff`."""
        cutoff = pd.Timestamp(cutoff).normalize()
        if self.promotions_known_on is not None and cutoff >= self.data_end:
            return max(cutoff, self.promotions_known_on.normalize())
        return cutoff

    def future_promotions(self, cutoff: pd.Timestamp) -> pd.DataFrame:
        """Descuentos de los días posteriores al corte, solo con lo conocido al corte (regla as-of)."""
        cutoff = pd.Timestamp(cutoff).normalize()
        key = (cutoff, self.knowledge_date(cutoff))
        if key not in self._future_promos:
            known = promotions_as_of(self.promotions, self.promotion_revisions, key[1])
            self._future_promos[key] = promo_discounts(known, self.series, (cutoff + pd.Timedelta(days=1)).date(),
                                                       self.horizon_end)
        return self._future_promos[key]

    def _exog(self, keys: pd.DataFrame, promos: pd.DataFrame) -> pd.DataFrame:
        df = keys.merge(self.calendar, on=["branch_id", "ds"], how="left")
        df = df.merge(promos, on=["unique_id", "ds"], how="left")
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
        df = self._exog(train[["unique_id", "branch_id", "ds", "y", "price", "stockout"]], self.promos)
        df["price_ratio"] = (df["price"] / df["unique_id"].map(ref_price)).replace([np.inf, -np.inf], np.nan)
        df["price_ratio"] = df["price_ratio"].fillna(1.0).clip(0.2, 5.0)
        df["weight"] = np.where(df["stockout"], 0.0, 1.0)
        df = df.merge(self._statics(train), on="unique_id", how="left")
        cols = ["unique_id", "ds", "y", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES, *STATIC_FEATURES, "weight"]
        return df[cols].sort_values(["unique_id", "ds"]).reset_index(drop=True), ref_price

    def future_frame(self, cutoff: pd.Timestamp, ids: list[str], h: int, ref_price: pd.Series) -> pd.DataFrame:
        """Exógenas para los h días siguientes al corte (precio congelado y promociones conocidas al corte)."""
        series = self.series[self.series["unique_id"].isin(ids)]
        days = pd.date_range(cutoff + pd.Timedelta(days=1), periods=h, freq="D")
        keys = pd.DataFrame({
            "unique_id": np.repeat(series["unique_id"].to_numpy(), h),
            "branch_id": np.repeat(series["branch_id"].to_numpy(), h),
            "ds": np.tile(days.values, len(series)),
        })
        df = self._exog(keys, self.future_promotions(cutoff))
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
