"""Referencias simples (sin ajuste) del Customer Core.

- `reference_probability`: renovación empírica con encogimiento exponencial. Combina FRECUENCIA (los
  últimos intervalos propios de la relación y la tasa histórica) y RECENCIA (días desde la última compra):
  P(compra en (c, c+H] | sin compra hace r días) = [F(r+H) − F(r)] / [1 − F(r)], con F mezcla de la
  distribución empírica de intervalos (m observaciones) y una exponencial (n0 pseudo-observaciones).
- `reference_quantity`: cantidad media por compra (últimas compras) × número esperado de días de compra
  dado que hay al menos una (Poisson truncado en cero con la tasa de los últimos 180 días).

Son las referencias que un modelo complejo debe superar en el holdout (regla versionada).
"""

from __future__ import annotations

import numpy as np

PSEUDO_OBS = 2.0


def reference_probability(recency, tenure, n_purchases, gaps: np.ndarray, horizon: int) -> np.ndarray:
    recency = np.asarray(recency, dtype=np.float64)
    tenure = np.asarray(tenure, dtype=np.float64)
    n_purchases = np.maximum(np.asarray(n_purchases, dtype=np.float64), 1.0)
    m = (~np.isnan(gaps)).sum(axis=1).astype(np.float64)
    with np.errstate(invalid="ignore", divide="ignore"):
        mu_emp = np.where(m > 0, np.nansum(gaps, axis=1) / np.maximum(m, 1), 0.0)
    mu_exp = (tenure + 1.0) / n_purchases
    mu_mix = np.maximum((m * mu_emp + PSEUDO_OBS * mu_exp) / (m + PSEUDO_OBS), 0.5)

    def cdf(x: np.ndarray) -> np.ndarray:
        f_exp = 1.0 - np.exp(-x / mu_mix)
        with np.errstate(invalid="ignore"):
            f_emp = np.where(m > 0, np.nansum(gaps <= x[:, None], axis=1) / np.maximum(m, 1), 0.0)
        return (m * f_emp + PSEUDO_OBS * f_exp) / (m + PSEUDO_OBS)

    f_r, f_rh = cdf(recency), cdf(recency + horizon)
    surv = np.maximum(1.0 - f_r, 1e-6)
    return np.clip((f_rh - f_r) / surv, 0.0, 1.0)


def reference_quantity(qty_mean5, rate_180, horizon: int) -> np.ndarray:
    mu = np.maximum(np.asarray(rate_180, dtype=np.float64), 0.0) * horizon
    with np.errstate(divide="ignore", invalid="ignore"):
        trunc = np.where(mu > 1e-6, mu / (1.0 - np.exp(-mu)), 1.0)
    return np.asarray(qty_mean5, dtype=np.float64) * trunc
