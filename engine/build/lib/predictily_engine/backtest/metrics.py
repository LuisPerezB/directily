"""Métricas de error para pronósticos de demanda.

- WAPE = Σ|real − pred| / Σ real. Robusta con ceros e intermitencia (no divide por cada
  observación). Si Σ real = 0 no está definida (None).
- MAE, RMSE (complementaria), sesgo = Σ(pred − real) / Σ real (positivo = sobreestima).
- Cobertura = proporción de reales dentro del intervalo.
No se convierte ninguna métrica en un "% de precisión" comercial.
"""

from __future__ import annotations

import numpy as np
import pandas as pd


def wape(actual, pred) -> float | None:
    a, p = np.asarray(actual, float), np.asarray(pred, float)
    denom = np.abs(a).sum()
    return None if denom == 0 else float(np.abs(a - p).sum() / denom)


def mae(actual, pred) -> float | None:
    a, p = np.asarray(actual, float), np.asarray(pred, float)
    return None if a.size == 0 else float(np.abs(a - p).mean())


def rmse(actual, pred) -> float | None:
    a, p = np.asarray(actual, float), np.asarray(pred, float)
    return None if a.size == 0 else float(np.sqrt(((a - p) ** 2).mean()))


def bias(actual, pred) -> float | None:
    a, p = np.asarray(actual, float), np.asarray(pred, float)
    denom = np.abs(a).sum()
    return None if denom == 0 else float((p - a).sum() / denom)


def coverage(actual, lo, hi) -> float | None:
    a = np.asarray(actual, float)
    if a.size == 0:
        return None
    return float(((a >= np.asarray(lo, float) - 1e-9) & (a <= np.asarray(hi, float) + 1e-9)).mean())


def summarize(df: pd.DataFrame, by: list[str], actual: str = "actual", pred: str = "yhat") -> pd.DataFrame:
    """Métricas agregadas por grupo (filas censuradas deben venir excluidas)."""
    d = df.assign(_ae=(df[pred] - df[actual]).abs(), _se=(df[pred] - df[actual]) ** 2, _e=df[pred] - df[actual])
    g = d.groupby(by, dropna=False)
    out = g.agg(sum_actual=(actual, "sum"), sum_ae=("_ae", "sum"), sum_se=("_se", "sum"), sum_e=("_e", "sum"),
                n_obs=(actual, "size")).reset_index()
    nz = out["sum_actual"] > 0
    out["wape"] = np.where(nz, out["sum_ae"] / out["sum_actual"].where(nz, 1), np.nan)
    out["bias"] = np.where(nz, out["sum_e"] / out["sum_actual"].where(nz, 1), np.nan)
    out["mae"] = out["sum_ae"] / out["n_obs"]
    out["rmse"] = np.sqrt(out["sum_se"] / out["n_obs"])
    return out
