"""Reconciliación jerárquica bottom-up y agregación por períodos.

Los pronósticos se generan a nivel serie (sucursal × producto) y se suman hacia
producto, categoría, sucursal, sucursal×categoría y total. Así la jerarquía es coherente
por construcción (la suma de los hijos es exactamente el padre). Los productos sin
categoría se incluyen en el total y en sucursal, pero no en nodos de categoría.
MinT u otros métodos óptimos quedan como mejora futura.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

AGG_LEVELS = ["product", "category", "branch", "branch_category", "total"]
ALL_LEVELS = ["series", *AGG_LEVELS]

_KEYS = {
    "series": ["branch_id", "product_id"],
    "product": ["product_id"],
    "category": ["category_id"],
    "branch": ["branch_id"],
    "branch_category": ["branch_id", "category_id"],
    "total": [],
}


def node_key(level: str, row: dict) -> str:
    if level == "total":
        return "total"
    if level == "series":
        return f"series:{int(row['branch_id'])}:{int(row['product_id'])}"
    if level == "branch_category":
        return f"branch_category:{int(row['branch_id'])}:{int(row['category_id'])}"
    col = _KEYS[level][0]
    return f"{level}:{int(row[col])}"


def _keys_series(level: str, df: pd.DataFrame) -> pd.Series:
    if level == "total":
        return pd.Series("total", index=df.index)
    if level == "series":
        return "series:" + df["branch_id"].astype(int).astype(str) + ":" + df["product_id"].astype(int).astype(str)
    if level == "branch_category":
        return ("branch_category:" + df["branch_id"].astype(int).astype(str) + ":"
                + df["category_id"].astype(int).astype(str))
    col = _KEYS[level][0]
    return f"{level}:" + df[col].astype(int).astype(str)


def aggregate(df: pd.DataFrame, value_cols: list[str], extra_keys: list[str], levels: list[str] = ALL_LEVELS) -> pd.DataFrame:
    """Suma `value_cols` por nodo de cada nivel. `extra_keys` (p. ej. ds, step, window) se conservan.

    Devuelve: node_key, level, branch_id, product_id, category_id, *extra_keys, *value_cols.
    """
    out = []
    for level in levels:
        keys = _KEYS[level]
        src = df
        if "category_id" in keys:
            src = df[df["category_id"].notna()]
        if src.empty:
            continue
        g = src.groupby(keys + extra_keys, as_index=False, dropna=False)[value_cols].sum(min_count=1)
        g["level"] = level
        for col in ("branch_id", "product_id", "category_id"):
            if col not in keys:
                g[col] = np.nan
        if level == "series" or level == "product":
            # la categoría del producto se conserva como atributo informativo
            cat = df.drop_duplicates("product_id").set_index("product_id")["category_id"]
            g["category_id"] = g["product_id"].map(cat)
        g["node_key"] = _keys_series(level, g)
        out.append(g)
    res = pd.concat(out, ignore_index=True)
    return res[["node_key", "level", "branch_id", "product_id", "category_id", *extra_keys, *value_cols]]


def to_periods(daily: pd.DataFrame, granularity: str, value_cols: list[str],
               extra_keys: list[str] | None = None) -> pd.DataFrame:
    """Agrega valores diarios por semana ISO (lunes) o mes (día 1).

    horizon_step = índice del período desde el corte (1, 2, ...) dentro de cada grupo de
    `extra_keys` (p. ej. ventana de backtest); period_days = días incluidos en el período.
    """
    extra_keys = extra_keys or []
    df = daily.copy()
    ds = pd.to_datetime(df["ds"])
    if granularity == "week":
        df["period"] = (ds - pd.to_timedelta(ds.dt.dayofweek, unit="D")).dt.normalize()
    elif granularity == "month":
        df["period"] = ds.dt.to_period("M").dt.to_timestamp()
    else:
        raise ValueError(granularity)
    keys = ["node_key", "level", "branch_id", "product_id", "category_id", *extra_keys, "period"]
    g = df.groupby(keys, as_index=False, dropna=False).agg(
        **{c: (c, "sum") for c in value_cols}, period_days=("ds", "nunique"))
    if extra_keys:
        g["horizon_step"] = g.groupby(extra_keys)["period"].rank(method="dense").astype(int)
    else:
        g["horizon_step"] = g["period"].rank(method="dense").astype(int)
    return g.rename(columns={"period": "ds"})
