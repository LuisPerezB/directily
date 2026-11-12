"""Ingeniería de características (feature set versionado).

Reglas anti-fuga temporal:
- Las variables de calendario son conocidas de antemano y pueden usarse en el futuro.
- Promociones (regla as-of): para fechas ≤ corte se usa la promoción tal como ocurrió
  (última versión registrada; es un hecho observado). Para fechas > corte solo cuentan las
  promociones que el negocio ya conocía al corte (`known_at` ≤ corte) y con los valores de
  la versión vigente ese día (`promotion_revisions.recorded_at` ≤ corte). Una promoción
  registrada o modificada después del corte no altera el pronóstico de ese corte.
- El precio futuro NO se conoce: en la predicción (y en cada ventana de backtest) se
  congela en el último precio neto observado hasta el corte (supuesto explícito `frozen_price`),
  salvo un precio futuro CONFIRMADO en `product_price_history` con `known_at` visible al corte
  (regla de W1: `known_at` < medianoche local de C+1), que es KNOWN_IN_ADVANCE.
- Las referencias (precio mediano, nivel medio de la serie) se calculan solo con datos
  hasta el corte.
- Lags y medias móviles los calcula MLForecast de forma recursiva (solo pasado).

Contratos de features (ADR-V25-06): CADA columna que produce el builder tiene un contrato en
`availability/contracts.py` (clase de disponibilidad, fuente, regla temporal y datos requeridos). Una columna sin contrato
hace fallar `training_frame` / `future_frame`. Los factores externos (`factors=`) y la omisión de features opcionales
(`disabled_features=`, DEGRADE) son opcionales: con los valores por defecto los marcos son idénticos a los de siempre.
"""

from __future__ import annotations

from datetime import date
from typing import Any, Iterable

import numpy as np
import pandas as pd

from predictily_engine.availability.contracts import (  # noqa: F401  (PRICE_ASSUMPTION se reexporta: lo importan otros módulos)
    PRICE_ASSUMPTION,
    disabled_columns,
    registry_rows,
    validate_feature_columns,
)
from predictily_engine.availability.pipeline import FactorResolution
from predictily_engine.availability.visibility import resolve_timezone, to_utc_naive, visible_mask
from predictily_engine.calendar.ecuador import EcuadorCalendar, rest_days
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.profiling.days import censored_days

CALENDAR_FEATURES = list(EcuadorCalendar.FEATURES)
COMMERCIAL_FEATURES = ["promo_discount", "price_ratio"]
STATIC_FEATURES = ["branch_code_num", "category_num", "region_num", "series_level"]
LAGS = [1, 7, 14, 28]
DATE_FEATURES = ["dayofweek", "month", "day"]


def feature_registry() -> list[dict]:
    """Definiciones registradas en forecast_features (documentación y trazabilidad).

    Se derivan del registro de contratos (`availability/contracts.py`, fuente única): mismos nombres, tipos y descripciones que
    siempre; la configuración de cada fila añade su clase de disponibilidad, fuente y datos requeridos."""
    rows = registry_rows()
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


def holidays_by_branch(branches: pd.DataFrame, start: date, end: date) -> pd.DataFrame:
    """Días de descanso obligatorio de cada sucursal entre `start` y `end`, con su nombre: branch_id, ds, holiday.

    Es el mismo calendario que marca `is_holiday` (nacionales trasladados + locales del cantón), conocido de antemano."""
    frames = []
    cache: dict[str | None, pd.DataFrame] = {}
    for row in branches.itertuples(index=False):
        canton = (row.canton or "").lower() if isinstance(getattr(row, "canton", None), str) else None
        if canton not in cache:
            days: dict[date, str] = {}
            for year in range(start.year, end.year + 1):
                days.update({d: n for d, n in rest_days(year, canton).items() if start <= d <= end})
            cache[canton] = pd.DataFrame({"ds": pd.to_datetime(list(days.keys())), "holiday": list(days.values())})
        frames.append(cache[canton].assign(branch_id=int(row.branch_id)))
    if not frames:
        return pd.DataFrame(columns=["branch_id", "ds", "holiday"])
    return pd.concat(frames, ignore_index=True)[["branch_id", "ds", "holiday"]]


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
                 promotions_known_on: date | None = None, *, factors: FactorResolution | None = None,
                 confirmed_prices: pd.DataFrame | None = None, disabled_features: Iterable[str] = (),
                 timezone: str | None = None) -> None:
        """`promotion_revisions`: versiones históricas (promotion_id, revision, recorded_at, deleted y alcance).
        `promotions_known_on`: día de la ejecución vigente; solo amplía lo conocido para el corte final
        (los cortes anteriores, p. ej. del backtest, usan lo conocido en su propio día).

        Opciones del contrato de features (ADR-V25-06; con los valores por defecto el builder no cambia):
        - `factors`: factores externos ya resueltos (`FactorPipeline.resolve`); solo entra el que el validador declaró utilizable.
          Apagado/ausente ⇒ ninguna columna externa.
        - `confirmed_prices`: `product_price_history` (product_id, branch_id, price, valid_from, valid_to, known_at). Un precio futuro
          con `known_at` visible al corte es KNOWN_IN_ADVANCE y reemplaza al supuesto `frozen_price` en esos días.
        - `disabled_features`: features OPCIONALES a omitir (DEGRADE del gate de calidad); una imprescindible falla.
        - `timezone`: zona de la empresa (frontera del día local de `known_at`)."""
        self.factors = factors if factors is not None and factors.enabled else None
        self.disabled_features = tuple(dict.fromkeys(disabled_features))
        self._disabled_columns = disabled_columns(self.disabled_features)
        self.timezone = resolve_timezone(timezone)
        self.confirmed_prices = None if confirmed_prices is None or confirmed_prices.empty else confirmed_prices.copy()
        self.future_price_info: dict[str, int] = {"series_with_confirmed_price": 0, "series_frozen_price": 0}
        self.panel_df = panel_df
        start = panel_df["ds"].min().date()
        self.horizon_end = horizon_end
        self.data_end = panel_df["ds"].max()
        self.series = panel_df.drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id", "category_id"]]
        self.calendar = calendar_by_branch(branches, start, horizon_end)
        self._branches, self._start = branches, start
        self._holidays: pd.DataFrame | None = None      # feriados con nombre por sucursal (perezoso: solo con `holiday_adjustment_models`)
        self.promotions = promotions if promotions is not None else pd.DataFrame(columns=PROMO_COLUMNS)
        self.promotion_revisions = promotion_revisions if promotion_revisions is not None else pd.DataFrame()
        self.promotions_known_on = pd.Timestamp(promotions_known_on) if promotions_known_on is not None else None
        # Fechas ≤ corte: promociones tal como ocurrieron (hechos observados).
        self.promos = promo_discounts(observed_promotions(self.promotions), self.series, start, horizon_end)
        self._future_promos: dict[tuple[pd.Timestamp, pd.Timestamp], pd.DataFrame] = {}
        regions = branches.set_index("branch_id")["region"].map({"costa": 1, "sierra": 2}).fillna(0)
        self.region_map = regions.to_dict()

    def holiday_days(self, cutoff: pd.Timestamp, ids: list[str] | None = None) -> pd.DataFrame:
        """Feriados por serie para el ajuste de los modelos estadísticos (`models/holidays.py`): unique_id, ds, holiday.

        Incluye los feriados del calendario de la sucursal de cada serie, pasados y del horizonte (el calendario se conoce de antemano).
        De los PASADOS (ds ≤ `cutoff`) se quitan los días que el panel excluye (quiebre, fuente ausente o cierre): su venta está imputada
        con un día normal y no informa del efecto del feriado. El estado de los días posteriores al corte no se consulta."""
        if self._holidays is None:
            self._holidays = holidays_by_branch(self._branches, self._start, self.horizon_end)
        series = self.series if ids is None else self.series[self.series["unique_id"].isin(ids)]
        out = series[["unique_id", "branch_id"]].merge(self._holidays, on="branch_id", how="inner")[["unique_id", "ds", "holiday"]]
        if out.empty:
            return out
        cutoff = pd.Timestamp(cutoff)
        past = self.panel_df[self.panel_df["ds"] <= cutoff]
        excluded = past.loc[censored_days(past).to_numpy(), ["unique_id", "ds"]]
        if not excluded.empty:
            drop = pd.MultiIndex.from_frame(excluded)
            out = out[~pd.MultiIndex.from_frame(out[["unique_id", "ds"]]).isin(drop)]
        return out.reset_index(drop=True)

    def knowledge_date(self, cutoff: pd.Timestamp) -> pd.Timestamp:
        """Día hasta el que se conocen las promociones al pronosticar desde `cutoff`."""
        cutoff = pd.Timestamp(cutoff).normalize()
        if self.promotions_known_on is not None and cutoff >= self.data_end:
            return max(cutoff, self.promotions_known_on.normalize())
        return cutoff

    def options(self) -> dict[str, Any]:
        """Opciones de contrato para construir otro builder con la misma política (p. ej. cada ventana del backtest)."""
        return {"factors": self.factors, "confirmed_prices": self.confirmed_prices, "disabled_features": self.disabled_features,
                "timezone": self.timezone}

    def _finalize(self, frame: pd.DataFrame, cutoff: pd.Timestamp) -> pd.DataFrame:
        """Omite las features deshabilitadas, agrega las columnas de factores utilizables (con lo visible al corte `cutoff`: cada
        ventana del backtest usa lo conocido en SU corte) y exige un contrato para cada columna."""
        if self._disabled_columns:
            frame = frame.drop(columns=[c for c in frame.columns if c in self._disabled_columns])
        extra = None
        if self.factors is not None and self.factors.feature_columns:
            frame = frame.merge(self.factors.columns_at(cutoff), on="ds", how="left")
            extra = self.factors.contracts
        validate_feature_columns(frame.columns, extra=extra)
        return frame

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
        """Datos ≤ corte con exógenas, estáticas y peso (0 en días con quiebre de stock, fuente ausente o cierre)."""
        train = self.panel_df[(self.panel_df["ds"] <= cutoff) & self.panel_df["unique_id"].isin(ids)]
        ref_price = train.groupby("unique_id")["price"].median()
        keep = ["unique_id", "branch_id", "ds", "y", "price", "stockout"] + (["day_status"] if "day_status" in train else [])
        df = self._exog(train[keep], self.promos)
        df["price_ratio"] = (df["price"] / df["unique_id"].map(ref_price)).replace([np.inf, -np.inf], np.nan)
        df["price_ratio"] = df["price_ratio"].fillna(1.0).clip(0.2, 5.0)
        df["weight"] = np.where(censored_days(df), 0.0, 1.0)
        df = df.merge(self._statics(train), on="unique_id", how="left")
        cols = ["unique_id", "ds", "y", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES, *STATIC_FEATURES, "weight"]
        out = df[cols].sort_values(["unique_id", "ds"]).reset_index(drop=True)
        return self._finalize(out, cutoff), ref_price

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
        frozen = (df["unique_id"].map(last_price) / df["unique_id"].map(ref_price)).fillna(1.0)
        if self.confirmed_prices is not None and "price_ratio" not in self._disabled_columns:
            frozen = frozen * self._confirmed_price_multiplier(cutoff, series, days).reindex(
                pd.MultiIndex.from_frame(df[["unique_id", "ds"]])).fillna(1.0).to_numpy()
        df["price_ratio"] = frozen.clip(0.2, 5.0)
        return self._finalize(df[["unique_id", "ds", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES]], cutoff)

    def _confirmed_price_multiplier(self, cutoff: pd.Timestamp, series: pd.DataFrame, days: pd.DatetimeIndex) -> pd.Series:
        """Cociente (precio confirmado del día / precio vigente al corte) por (serie, día), SOLO donde existe un precio futuro confirmado.

        Un precio de `product_price_history` es visible al corte si su `known_at` es anterior a la medianoche local de C+1 (regla de
        W1; un `known_at` desconocido no demuestra que se conociera: se excluye). El día `d` toma el precio cuyo `valid_from` es el más
        reciente ≤ d que cubre d (a igual `valid_from` gana el de la sucursal sobre el general). Se usa el cociente respecto del
        precio vigente al corte del MISMO historial, no el precio absoluto: así no se mezclan el precio de lista y el neto observado.
        Sin precio vigente al corte no hay base y se mantiene el supuesto `frozen_price`. `future_price_info` cuenta las series con un
        precio futuro confirmado DISTINTO del vigente (`series_with_confirmed_price`) y las que siguen en `frozen_price`."""
        hist = self.confirmed_prices
        known = to_utc_naive(hist["known_at"])
        hist = hist[visible_mask(known, cutoff, self.timezone, retroactive=True)].copy()
        hist["valid_from"] = pd.to_datetime(hist["valid_from"]).dt.normalize()
        hist["valid_to"] = pd.to_datetime(hist["valid_to"]).dt.normalize()
        hist["_specific"] = hist["branch_id"].notna()
        hist = hist.sort_values(["valid_from", "_specific"], kind="mergesort")
        by_product = {int(pid): g for pid, g in hist.groupby("product_id")}
        grid = np.concatenate([[np.datetime64(pd.Timestamp(cutoff).normalize(), "ns")], days.to_numpy("datetime64[ns]")])
        index, values, confirmed = [], [], 0
        for row in series.itertuples(index=False):
            g = by_product.get(int(row.product_id))
            if g is not None:
                g = g[g["branch_id"].isna() | (g["branch_id"] == row.branch_id)]
            if g is None or g.empty:
                continue
            price = np.full(len(grid), np.nan)
            for r in g.itertuples(index=False):
                covers = grid >= np.datetime64(r.valid_from, "ns")
                if pd.notna(r.valid_to):
                    covers &= grid <= np.datetime64(r.valid_to, "ns")
                price[covers] = float(r.price)
            if np.isnan(price[0]) or price[0] <= 0:
                continue            # sin precio vigente al corte: no hay base de comparación
            multiplier = price[1:] / price[0]
            if np.allclose(multiplier[~np.isnan(multiplier)], 1.0):
                continue            # ningún precio futuro confirmado distinto del vigente: rige el supuesto frozen_price
            confirmed += 1
            index.extend((row.unique_id, d) for d in days)
            values.extend(multiplier)
        self.future_price_info = {"series_with_confirmed_price": confirmed,
                                  "series_frozen_price": int(len(series)) - confirmed}
        if not index:
            return pd.Series(dtype=float, index=pd.MultiIndex.from_arrays([[], []], names=["unique_id", "ds"]))
        out = pd.Series(values, index=pd.MultiIndex.from_tuples(index, names=["unique_id", "ds"]), dtype=float)
        return out[~out.index.duplicated(keep="last")]


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
