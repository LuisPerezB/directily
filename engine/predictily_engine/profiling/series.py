"""Construcción del panel diario de series y perfilado de la demanda.

- Una serie = (sucursal, producto). Comienza en su primera venta y termina en el corte.
- Días sin registro de venta = 0 unidades.
- Quiebre de stock (stockout): cierre del día con existencias ≤ 0, o cierre del día
  anterior ≤ 0 (el día empezó sin stock). Solo se marca si existe el snapshot.
- La venta de un día con quiebre no refleja la demanda (censura). Para lags y modelos
  estadísticos se imputa de forma conservadora: max(venta, media móvil de 28 días sin
  quiebre). Esos días se excluyen de la pérdida de LightGBM y de las métricas.
- Clasificación Syntetos-Boylan sobre los últimos 365 días sin quiebre.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import pandas as pd

from predictily_engine.pipeline.data import TenantData

ADI_THRESHOLD = 1.32
CV2_THRESHOLD = 0.49
MIN_HISTORY_DAYS = 28
MIN_NONZERO_DAYS = 4
INACTIVE_AFTER_DAYS = 180


def series_id(branch_id: int, product_id: int) -> str:
    return f"b{int(branch_id)}-p{int(product_id)}"


@dataclass
class Panel:
    df: pd.DataFrame  # unique_id, branch_id, product_id, category_id, ds, units, y, stockout, price
    profile: pd.DataFrame  # una fila por serie
    cutoff: pd.Timestamp

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


def build_panel(data: TenantData) -> Panel:
    cutoff = pd.Timestamp(data.cutoff)
    sales = data.sales
    if sales.empty:
        empty = pd.DataFrame(columns=["unique_id", "branch_id", "product_id", "category_id", "ds", "units", "y",
                                      "stockout", "price"])
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
        df = df.drop(columns=["oos", "oos_prev"])
    else:
        df["stockout"] = False

    df = df.sort_values(["branch_id", "product_id", "ds"]).reset_index(drop=True)
    df["unique_id"] = "b" + df["branch_id"].astype(str) + "-p" + df["product_id"].astype(str)
    g = df.groupby("unique_id", sort=False)

    # Imputación conservadora de días censurados
    clean = df["units"].where(~df["stockout"])
    roll = clean.groupby(df["unique_id"], sort=False).transform(lambda s: s.shift(1).rolling(28, min_periods=7).mean())
    df["y"] = np.where(df["stockout"], np.maximum(df["units"], roll.fillna(df["units"])), df["units"])

    # Precio neto unitario observado, propagado hacia adelante
    unit_price = (df["net_amount"] / df["units"]).where(df["units"] > 0)
    unit_price = unit_price.groupby(df["unique_id"], sort=False).transform(lambda s: s.ffill().bfill())
    df["price"] = unit_price

    cat_map = data.products.set_index("product_id")["category_id"] if not data.products.empty else pd.Series(dtype=float)
    df["category_id"] = df["product_id"].map(cat_map)

    profile = _profile(df, data, cutoff)
    return Panel(df.drop(columns=["net_amount"]), profile, cutoff)


def _profile(df: pd.DataFrame, data: TenantData, cutoff: pd.Timestamp) -> pd.DataFrame:
    active_products = set(data.products.loc[data.products["is_active"].astype(bool), "product_id"].astype(int)) \
        if not data.products.empty else set()
    rows = []
    window_start = cutoff - pd.Timedelta(days=364)
    for uid, s in df.groupby("unique_id", sort=False):
        b, p = int(s["branch_id"].iat[0]), int(s["product_id"].iat[0])
        sold = s.loc[s["units"] > 0, "ds"]
        history_days = len(s)
        recent = s[(s["ds"] >= window_start) & ~s["stockout"]]
        cls, adi, cv2 = classify_demand(recent["units"].to_numpy(), history_days)
        last_sale = sold.max() if len(sold) else pd.NaT
        inactive = pd.isna(last_sale) or (cutoff - last_sale).days > INACTIVE_AFTER_DAYS
        if active_products and p not in active_products:
            inactive = True
        rows.append({
            "unique_id": uid, "branch_id": b, "product_id": p,
            "category_id": s["category_id"].iat[0],
            "first_sale_date": s["ds"].iat[0].date(),
            "last_sale_date": last_sale.date() if not pd.isna(last_sale) else None,
            "history_days": history_days,
            "nonzero_days": int((s["units"] > 0).sum()),
            "total_units": float(s["units"].sum()),
            "adi": adi, "cv2": cv2, "demand_class": cls,
            "stockout_days": int(s["stockout"].sum()),
            "censored_days": int((s["stockout"] & (s["units"] == 0)).sum()),
            "is_active": not inactive,
        })
    return pd.DataFrame(rows)
