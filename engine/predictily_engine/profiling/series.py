"""Construcción del panel diario de series y perfilado de la demanda.

- Una serie = (sucursal, producto). Comienza en su primera venta y termina en el corte.
- Días sin registro de venta = 0 unidades SOLO si la fuente cubre ese día (`zero_confirmed`). Cada día se clasifica en
  `day_status` (ADR-V25-03, ver `profiling.days`): `sale`, `zero_confirmed`, `missing_source` (fuera de la cobertura de la
  fuente, dentro de un hueco o inferido por la regla de actividad de la sucursal), `stockout_censored` y `closed` (cierre
  del calendario). `day_basis` indica si un `missing_source`/`closed` es `declared` o `inferred`.
- Quiebre de stock (stockout): cierre del día con existencias ≤ 0, o cierre del día
  anterior ≤ 0 (el día empezó sin stock). Solo se marca si existe el snapshot. `stockout` conserva ese significado (inventario).
- La venta de un día con quiebre, de fuente ausente o de cierre no refleja la demanda (censura / dato ausente). Para lags y
  modelos estadísticos se imputa de forma conservadora: max(venta, media móvil de 28 días de los días limpios); en un hueco de
  fuente ausente o cierre de más de 21 días (la ventana ya no tiene 7 días limpios) se propaga el último nivel disponible. Esos días se
  excluyen de la pérdida de LightGBM, de las métricas y de la proporción de ceros del perfilado; nunca cuentan como cero observado.
- Clasificación Syntetos-Boylan sobre los últimos 365 días limpios (sin quiebre, fuente ausente ni cierre).
- El perfil (actividad, clase, elegibilidad) se calcula SIEMPRE con información disponible
  a un corte (`profile_at`): el del corte final para producción y uno propio para cada
  ventana de backtest, de modo que ventas posteriores a una ventana no cambian qué series
  entrenan/evalúan en ella ni su clase (hallazgo F4, validación del 28-oct-2026).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.days import (
    BASIS_INFERRED,
    EXCLUDED_STATUSES,
    STOCKOUT_CENSORED,
    DayRules,
    censored_days,
    classify_days,
    summarize_days,
)
from predictily_engine.profiling.stockouts import infer_stockouts

ADI_THRESHOLD = 1.32
CV2_THRESHOLD = 0.49
MIN_HISTORY_DAYS = 28
MIN_NONZERO_DAYS = 4
INACTIVE_AFTER_DAYS = 180


def series_id(branch_id: int, product_id: int) -> str:
    return f"b{int(branch_id)}-p{int(product_id)}"


@dataclass
class Panel:
    df: pd.DataFrame  # unique_id, branch_id, product_id, category_id, ds, units, y, stockout, price, day_status, day_basis
    profile: pd.DataFrame  # una fila por serie
    cutoff: pd.Timestamp
    # ADR-V25-03: series-día por estado y base, regla inferida usada, y cuántos días excluidos se imputaron (para el manifiesto)
    day_summary: dict[str, Any] = field(default_factory=dict)

    @property
    def active_ids(self) -> list[str]:
        return self.profile.loc[self.profile["is_active"], "unique_id"].tolist()


def classify_demand(values: np.ndarray, history_days: int) -> tuple[str, float | None, float | None]:
    """Devuelve (clase, ADI, CV²) a partir de una serie diaria sin días censurados."""
    values = np.asarray(values, dtype=float)
    values = values[~np.isnan(values)]
    nonzero = values[values > 0]
    if history_days < MIN_HISTORY_DAYS or len(nonzero) < MIN_NONZERO_DAYS:
        adi = (len(values) / len(nonzero)) if len(nonzero) else None
        return "insufficient", adi, None
    adi = len(values) / len(nonzero)
    mean = nonzero.mean()
    cv2 = float((nonzero.std(ddof=0) / mean) ** 2) if mean > 0 else 0.0
    if adi < ADI_THRESHOLD:
        cls = "smooth" if cv2 < CV2_THRESHOLD else "erratic"
    else:
        cls = "intermittent" if cv2 < CV2_THRESHOLD else "lumpy"
    return cls, float(adi), cv2


def build_panel(data: TenantData, rules: DayRules | None = None, current_catalog: bool = True) -> Panel:
    """Panel diario del corte `data.cutoff` con los datos de `data` (ya as-of a ese corte).

    `rules`: regla inferida de días sin fuente (`DayRules.from_config`); sin ella solo se clasifica lo DECLARADO (cobertura,
    huecos y cierres de `data`). `current_catalog`: aplica el estado actual del catálogo (`products.is_active`) a la actividad;
    solo corresponde al corte final (ver `profile_at`)."""
    cutoff = pd.Timestamp(data.cutoff)
    sales = data.sales
    if sales.empty:
        empty = pd.DataFrame(columns=["unique_id", "branch_id", "product_id", "category_id", "ds", "units", "y",
                                      "stockout", "price", "day_status", "day_basis"])
        return Panel(empty, pd.DataFrame(columns=["unique_id", "branch_id", "product_id", "is_active"]), cutoff)

    sales = sales[sales["ds"] <= cutoff]
    first = sales.groupby(["branch_id", "product_id"])["ds"].min().reset_index(name="first_ds")
    lengths = ((cutoff - first["first_ds"]).dt.days + 1).to_numpy()
    rep = np.repeat(np.arange(len(first)), lengths)
    offsets = np.concatenate([np.arange(n) for n in lengths])
    df = pd.DataFrame({
        "branch_id": first["branch_id"].to_numpy()[rep].astype(int),
        "product_id": first["product_id"].to_numpy()[rep].astype(int),
        "ds": first["first_ds"].to_numpy()[rep] + offsets.astype("timedelta64[D]"),
    })
    df = df.merge(sales[["branch_id", "product_id", "ds", "units", "net_amount"]], on=["branch_id", "product_id", "ds"],
                  how="left")
    df["units"] = df["units"].fillna(0.0).astype(float)
    df["net_amount"] = df["net_amount"].fillna(0.0).astype(float)

    # Quiebres de stock según snapshots
    if not data.inventory.empty:
        inv = data.inventory[["branch_id", "product_id", "ds", "on_hand"]].copy()
        inv["oos"] = inv["on_hand"].astype(float) <= 0
        inv = inv.sort_values(["branch_id", "product_id", "ds"])
        # el día siguiente a un cierre sin stock también empieza sin stock
        nxt = inv[["branch_id", "product_id", "ds", "oos"]].copy()
        nxt["ds"] = nxt["ds"] + pd.Timedelta(days=1)
        nxt = nxt.rename(columns={"oos": "oos_prev"})
        df = df.merge(inv[["branch_id", "product_id", "ds", "oos"]], on=["branch_id", "product_id", "ds"], how="left")
        df = df.merge(nxt, on=["branch_id", "product_id", "ds"], how="left")
        df["stockout"] = df["oos"].eq(True) | df["oos_prev"].eq(True)
        in_stock = (df["oos"].eq(False) & ~df["oos_prev"].eq(True)).to_numpy()   # existencias confirmadas ese día
        df = df.drop(columns=["oos", "oos_prev"])
    else:
        df["stockout"] = False
        in_stock = np.zeros(len(df), dtype=bool)

    order = np.lexsort((df["ds"].to_numpy(), df["product_id"].to_numpy(), df["branch_id"].to_numpy()))
    in_stock = in_stock[order]
    df = df.sort_values(["branch_id", "product_id", "ds"]).reset_index(drop=True)
    df["unique_id"] = "b" + df["branch_id"].astype(str) + "-p" + df["product_id"].astype(str)
    g = df.groupby("unique_id", sort=False)

    # Semántica de días (ADR-V25-03): fuente ausente y cierres se excluyen igual que los quiebres y nunca son ceros observados.
    classification = classify_days(df, data, cutoff, rules)
    df["day_status"], df["day_basis"] = classification.status, classification.basis
    excluded = df["day_status"].isin(EXCLUDED_STATUSES).to_numpy()
    # Quiebres inferidos de las ventas (interruptor `stockout_inference`): se suman a los del inventario y reciben el mismo trato.
    inferred_stockout = np.zeros(len(df), dtype=bool)
    inference = None
    if rules is not None and rules.stockouts is not None:
        inference = infer_stockouts(df, excluded, in_stock, rules.stockouts)
        inferred_stockout = inference.inferred
        if inferred_stockout.any():
            df.loc[inferred_stockout, "stockout"] = True
            df.loc[inferred_stockout, "day_status"] = STOCKOUT_CENSORED
            df.loc[inferred_stockout, "day_basis"] = BASIS_INFERRED
    censored = df["stockout"].to_numpy(bool) | excluded

    # Imputación conservadora de días censurados / excluidos (misma regla para quiebres, fuente ausente y cierres)
    clean = df["units"].where(~censored)
    roll = clean.groupby(df["unique_id"], sort=False).transform(lambda s: s.shift(1).rolling(28, min_periods=7).mean())
    # Un hueco de más de 21 días agota la ventana de 28 días (menos de 7 días limpios) y la media móvil queda nula: sin más, el día
    # excluido quedaba con su valor observado (0) y los modelos estadísticos veían ceros. Los días de fuente ausente o cierre propagan
    # el último nivel disponible (ffill dentro de la serie); los quiebres conservan la regla de siempre. Solo queda sin imputar lo que no
    # tiene ningún nivel previo (el hueco abre la serie). Un quiebre inferido puede durar más de 21 días: también propaga su último nivel.
    level = roll.groupby(df["unique_id"], sort=False).ffill()
    fill = roll.where(~(excluded | inferred_stockout), level)
    df["y"] = np.where(censored, np.maximum(df["units"], fill.fillna(df["units"])), df["units"])
    imputed_excluded = int((excluded & fill.notna().to_numpy()).sum())
    carried_forward = int((excluded & roll.isna().to_numpy() & fill.notna().to_numpy()).sum())
    not_imputable = int((excluded & fill.isna().to_numpy()).sum())

    # Precio neto unitario observado, propagado hacia adelante
    unit_price = (df["net_amount"] / df["units"]).where(df["units"] > 0)
    unit_price = unit_price.groupby(df["unique_id"], sort=False).transform(lambda s: s.ffill().bfill())
    df["price"] = unit_price

    cat_map = data.products.set_index("product_id")["category_id"] if not data.products.empty else pd.Series(dtype=float)
    df["category_id"] = df["product_id"].map(cat_map)

    active_products = set(data.products.loc[data.products["is_active"].astype(bool), "product_id"].astype(int)) \
        if not data.products.empty else set()
    profile = profile_at(df, cutoff, active_products if current_catalog else None)
    day_summary = summarize_days(df, classification.summary, imputed_excluded, not_imputable, carried_forward)
    if inference is not None:      # apagado, el resumen queda como siempre
        day_summary["stockout_inference"] = inference.summary
    return Panel(df.drop(columns=["net_amount"]), profile, cutoff, day_summary)


PROFILE_COLUMNS = ["unique_id", "branch_id", "product_id", "category_id", "first_sale_date", "last_sale_date",
                   "history_days", "nonzero_days", "total_units", "adi", "cv2", "demand_class", "stockout_days",
                   "censored_days", "missing_source_days", "closed_days", "is_active"]


def profile_at(df: pd.DataFrame, cutoff: pd.Timestamp, active_products: set[int] | None = None) -> pd.DataFrame:
    """Perfil por serie usando SOLO las filas del panel con `ds ≤ cutoff`.

    Equivale a perfilar el panel que se habría construido con corte `cutoff`: historia,
    días con venta, última venta, actividad (venta en los últimos 180 días) y clase
    Syntetos-Boylan sobre los 365 días previos limpios (sin quiebre, fuente ausente ni cierre: esos días no cuentan como
    ceros en la proporción de ceros). Las series cuya primera venta es posterior al corte no aparecen.

    `active_products` (estado actual del catálogo) solo debe pasarse en el corte final: su
    historia no está versionada y aplicarlo a un corte pasado usaría una decisión posterior
    (p. ej. descontinuar un producto) para decidir qué series se evaluaban entonces.
    """
    cutoff = pd.Timestamp(cutoff)
    d = df[df["ds"] <= cutoff]
    if d.empty:
        return pd.DataFrame(columns=PROFILE_COLUMNS)
    units = d["units"]
    sold = units > 0
    g = d.groupby("unique_id", sort=False)
    out = g.agg(branch_id=("branch_id", "first"), product_id=("product_id", "first"),
                category_id=("category_id", "first"), first_ds=("ds", "min"), history_days=("ds", "size"),
                total_units=("units", "sum"), stockout_days=("stockout", "sum"))
    out["nonzero_days"] = sold.groupby(d["unique_id"], sort=False).sum().reindex(out.index).fillna(0)
    out["last_ds"] = d.loc[sold].groupby("unique_id", sort=False)["ds"].max().reindex(out.index)
    out["censored_days"] = (d["stockout"] & (units == 0)).groupby(d["unique_id"], sort=False).sum() \
        .reindex(out.index).fillna(0)
    if "day_status" in d:  # ADR-V25-03: días que la fuente no cubre o en que la sucursal cerró (no son ceros)
        out["missing_source_days"] = (d["day_status"] == "missing_source").groupby(d["unique_id"], sort=False).sum() \
            .reindex(out.index).fillna(0)
        out["closed_days"] = (d["day_status"] == "closed").groupby(d["unique_id"], sort=False).sum() \
            .reindex(out.index).fillna(0)
    else:
        out["missing_source_days"] = 0
        out["closed_days"] = 0

    # Clasificación sobre los 365 días previos al corte limpios (misma regla que classify_demand)
    recent = d[(d["ds"] >= cutoff - pd.Timedelta(days=364)) & ~censored_days(d)]
    n_recent = recent.groupby("unique_id", sort=False).size().reindex(out.index).fillna(0).to_numpy(float)
    nz = recent.loc[recent["units"] > 0].groupby("unique_id", sort=False)["units"]
    nz_n = nz.size().reindex(out.index).fillna(0).to_numpy(float)
    nz_mean = nz.mean().reindex(out.index).to_numpy(float)
    nz_std = nz.std(ddof=0).reindex(out.index).to_numpy(float)
    hist = out["history_days"].to_numpy()
    insufficient = (hist < MIN_HISTORY_DAYS) | (nz_n < MIN_NONZERO_DAYS)
    with np.errstate(divide="ignore", invalid="ignore"):
        adi = np.where(nz_n > 0, n_recent / np.where(nz_n > 0, nz_n, 1), np.nan)
        cv2 = np.where(nz_mean > 0, (nz_std / np.where(nz_mean > 0, nz_mean, 1)) ** 2, 0.0)
    cv2 = np.where(insufficient, np.nan, cv2)
    cls = np.where(adi < ADI_THRESHOLD, np.where(cv2 < CV2_THRESHOLD, "smooth", "erratic"),
                   np.where(cv2 < CV2_THRESHOLD, "intermittent", "lumpy"))
    cls = np.where(insufficient, "insufficient", cls)

    last = out["last_ds"]
    inactive = last.isna().to_numpy() | ((cutoff - last).dt.days > INACTIVE_AFTER_DAYS).fillna(True).to_numpy()
    if active_products:
        inactive |= ~out["product_id"].astype(int).isin(active_products).to_numpy()
    res = pd.DataFrame({
        "unique_id": out.index.to_numpy(),
        "branch_id": out["branch_id"].astype(int).to_numpy(),
        "product_id": out["product_id"].astype(int).to_numpy(),
        "category_id": out["category_id"].to_numpy(),
        "first_sale_date": [ts.date() for ts in out["first_ds"]],
        "last_sale_date": [None if pd.isna(ts) else ts.date() for ts in last],
        "history_days": out["history_days"].astype(int).to_numpy(),
        "nonzero_days": out["nonzero_days"].astype(int).to_numpy(),
        "total_units": out["total_units"].astype(float).to_numpy(),
        "adi": adi, "cv2": cv2, "demand_class": cls,
        "stockout_days": out["stockout_days"].astype(int).to_numpy(),
        "censored_days": out["censored_days"].astype(int).to_numpy(),
        "missing_source_days": out["missing_source_days"].astype(int).to_numpy(),
        "closed_days": out["closed_days"].astype(int).to_numpy(),
        "is_active": ~inactive,
    })
    return res.reset_index(drop=True)
