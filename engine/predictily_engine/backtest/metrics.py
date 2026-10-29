"""Métricas de error para pronósticos de demanda.

- WAPE = Σ|real − pred| / Σ real. Robusta con ceros e intermitencia (no divide por cada
  observación). Si Σ real = 0 no está definida (None).
- MAE, RMSE (complementaria), sesgo = Σ(pred − real) / Σ real (positivo = sobreestima).
- Cobertura = proporción de reales dentro del intervalo.
- MASE y RMSSE (etapa 2): error dividido por la escala del naive estacional de 7 días calculada SOLO con
  los datos de entrenamiento previos a cada origen (`backtest.rolling.seasonal_naive_scales`, columnas
  `scale_mae` / `scale_mse` de cada fila). MASE = media de |e|/scale_mae; RMSSE = √(media de e²/scale_mse),
  ambas sobre las observaciones con escala definida (series con < 14 pares o serie constante quedan
  excluidas y se cuentan en `n_scale_undefined`). MASE < 1: mejor que el naive estacional EN ENTRENAMIENTO.
- Dinero (ingresos): las filas llevan `price_fc` (precio supuesto al origen) y `price_act` (precio neto
  observado); el error en dinero se calcula aparte del de unidades, sobre las filas con ambos precios.
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
    """Métricas agregadas por grupo (filas censuradas deben venir excluidas).

    Si `df` trae `scale_mae`/`scale_mse` agrega `mase`, `rmsse`, `n_scaled` y `n_scale_undefined`; si trae
    `price_fc`/`price_act` agrega las métricas en dinero (`money_*`); si trae `unique_id`/`window`, los
    tamaños de muestra `n_series` y `n_windows`.
    """
    d = df.assign(_ae=(df[pred] - df[actual]).abs(), _se=(df[pred] - df[actual]) ** 2, _e=df[pred] - df[actual])
    aggs: dict[str, tuple[str, str]] = dict(sum_actual=(actual, "sum"), sum_ae=("_ae", "sum"), sum_se=("_se", "sum"),
                                            sum_e=("_e", "sum"), n_obs=(actual, "size"))
    has_scale = "scale_mae" in df and "scale_mse" in df
    if has_scale:
        ok = (df["scale_mae"] > 0) & (df["scale_mse"] > 0)
        d["_sae"] = (d["_ae"] / df["scale_mae"]).where(ok)
        d["_sse"] = (d["_se"] / df["scale_mse"]).where(ok)
        aggs.update(sum_sae=("_sae", "sum"), sum_sse=("_sse", "sum"), n_scaled=("_sae", "count"))
    has_money = "price_fc" in df and "price_act" in df
    if has_money:
        okm = df["price_fc"].notna() & df["price_act"].notna()
        d["_m_act"] = (df[actual] * df["price_act"]).where(okm)
        d["_m_fc"] = (df[pred] * df["price_fc"]).where(okm)
        d["_m_ae"] = (d["_m_fc"] - d["_m_act"]).abs()
        d["_m_e"] = d["_m_fc"] - d["_m_act"]
        aggs.update(money_actual=("_m_act", "sum"), money_forecast=("_m_fc", "sum"), money_sum_ae=("_m_ae", "sum"),
                    money_sum_e=("_m_e", "sum"), n_money=("_m_act", "count"))
    if "unique_id" in df and "unique_id" not in by:
        aggs["n_series"] = ("unique_id", "nunique")
    if "window" in df and "window" not in by:
        aggs["n_windows"] = ("window", "nunique")
    g = d.groupby(by, dropna=False)
    out = g.agg(**aggs).reset_index()
    nz = out["sum_actual"] > 0
    out["wape"] = np.where(nz, out["sum_ae"] / out["sum_actual"].where(nz, 1), np.nan)
    out["bias"] = np.where(nz, out["sum_e"] / out["sum_actual"].where(nz, 1), np.nan)
    out["mae"] = out["sum_ae"] / out["n_obs"]
    out["rmse"] = np.sqrt(out["sum_se"] / out["n_obs"])
    if has_scale:
        has = out["n_scaled"] > 0
        out["mase"] = np.where(has, out["sum_sae"] / out["n_scaled"].where(has, 1), np.nan)
        out["rmsse"] = np.where(has, np.sqrt(out["sum_sse"] / out["n_scaled"].where(has, 1)), np.nan)
        out["n_scale_undefined"] = out["n_obs"] - out["n_scaled"]
    if has_money:
        mz = out["money_actual"] > 0
        out["money_wape"] = np.where(mz, out["money_sum_ae"] / out["money_actual"].where(mz, 1), np.nan)
        out["money_bias"] = np.where(mz, out["money_sum_e"] / out["money_actual"].where(mz, 1), np.nan)
        out["money_mae"] = np.where(out["n_money"] > 0, out["money_sum_ae"] / out["n_money"].where(out["n_money"] > 0, 1),
                                    np.nan)
        out["n_price_missing"] = out["n_obs"] - out["n_money"]
    return out


def pinball_loss(q: float, actual, pred) -> float | None:
    """Pérdida pinball media del cuantil `q` (0 < q < 1): max(q·(y−f), (q−1)·(y−f)). Menor es mejor."""
    a, p = np.asarray(actual, float), np.asarray(pred, float)
    ok = np.isfinite(a) & np.isfinite(p)
    if not ok.any():
        return None
    e = a[ok] - p[ok]
    return float(np.maximum(q * e, (q - 1.0) * e).mean())
