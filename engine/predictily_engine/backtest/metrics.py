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
- W3-A (V2.5 §8 «Métricas Demand»): WAPE con DENOMINADOR explícito. `wape_status` distingue `ok`, `unstable` (denominador positivo
  pero menor que el umbral configurable `wape_min_denominator`) e `undefined` (denominador 0, nunca un WAPE); en los dos últimos casos
  la métrica alternativa explícita es el MAE escalado (MASE) y, si la escala no existe, el error absoluto agregado. La estabilidad
  entre ventanas es el desvío del WAPE por ventana (`window_stability`).
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


# ───────────────────────────── W3-A · WAPE con denominador, métrica alternativa y estabilidad ─────────────────────────────

WAPE_OK = "ok"
WAPE_UNSTABLE = "unstable"      # denominador > 0 pero menor que el umbral: el cociente depende de muy pocas unidades
WAPE_UNDEFINED = "undefined"    # denominador 0 (o no finito): no existe WAPE
ALT_MASE = "mase"
ALT_AGGREGATE_ABSOLUTE_ERROR = "aggregate_absolute_error"


def wape_status(denominator: float | None, min_denominator: float = 0.0) -> str:
    """Estado del WAPE según su denominador (Σ de reales): `undefined` si es 0 o no finito, `unstable` si es positivo pero
    menor que `min_denominator`, `ok` en otro caso. Con `min_denominator = 0` solo hay `ok` o `undefined`."""
    if denominator is None or not np.isfinite(denominator) or denominator <= 0:
        return WAPE_UNDEFINED
    return WAPE_UNSTABLE if denominator < min_denominator else WAPE_OK


def guarded_wape(sum_abs_error: float, denominator: float, min_denominator: float = 0.0) -> dict:
    """WAPE = Σ|error| / Σ reales con su estado. `value` solo existe si el denominador es positivo (nunca divide por 0);
    `trusted` es el valor únicamente cuando el estado es `ok` (lo que se persiste en la columna `wape`)."""
    status = wape_status(denominator, min_denominator)
    value = float(sum_abs_error / denominator) if status != WAPE_UNDEFINED else None
    return {"value": value, "trusted": value if status == WAPE_OK else None, "status": status,
            "unstable": status != WAPE_OK, "denominator": float(denominator), "sum_abs_error": float(sum_abs_error)}


def alternative_metric(sum_abs_error: float, n_obs: int, sum_scaled_abs: float | None = None, n_scaled: int = 0) -> dict:
    """Métrica alternativa explícita para cuando el WAPE es indefinido o inestable (series de ceros o casi ceros).

    1) MAE escalado (MASE = media de |e| / MAE del naive estacional de entrenamiento) si la escala existe en alguna observación;
    2) si no (serie constante o de ceros: escala 0), el error absoluto agregado Σ|e| y su promedio por día evaluable."""
    if n_scaled and sum_scaled_abs is not None and np.isfinite(sum_scaled_abs):
        return {"name": ALT_MASE, "value": float(sum_scaled_abs / n_scaled), "n_scaled": int(n_scaled),
                "definition": "MAE escalado: media de |error| / MAE del naive estacional (7 d) de entrenamiento"}
    return {"name": ALT_AGGREGATE_ABSOLUTE_ERROR, "value": float(sum_abs_error),
            "per_evaluable_day": None if n_obs <= 0 else float(sum_abs_error / n_obs), "unit": "unidades",
            "definition": "Σ|error| sobre los días evaluables (la escala del naive de entrenamiento no existe o es 0)"}


def window_stability(per_window: dict, cv_warn: float | None = None, min_denominator: float = 0.0) -> dict:
    """Estabilidad entre ventanas: desvío del WAPE por ventana. `per_window`: {ventana: (wape | None, denominador)}.

    Solo cuentan las ventanas con WAPE definido (denominador > 0) Y denominador ≥ `min_denominator` (el mismo umbral que hace estable al
    WAPE: con menos unidades el WAPE de la ventana es ruido); las demás se listan con `used = false` y se cuentan en
    `n_windows_small_denominator`. Con una sola ventana usable el desvío no es estimable (`single_window`), no cero; si todas las ventanas
    con datos tienen denominador insuficiente el estado es `insufficient_denominator`. `unstable_across_windows` = coeficiente de variación
    mayor que `cv_warn` (umbral configurable)."""
    defined = {w: (v, d) for w, (v, d) in per_window.items() if v is not None and np.isfinite(v)}
    pts = {w: (v, d) for w, (v, d) in defined.items() if d >= min_denominator}
    vals = np.array([v for v, _ in pts.values()], float)
    out: dict = {"n_windows": int(len(pts)), "n_windows_without_wape": int(len(per_window) - len(defined)),
                 "n_windows_small_denominator": int(len(defined) - len(pts)), "min_denominator": float(min_denominator),
                 "cv_warn": cv_warn,
                 "per_window": [{"window": w, "wape": float(v), "denominator": float(d), "used": w in pts}
                                for w, (v, d) in sorted(defined.items())],
                 "wape_mean": None, "wape_std": None, "wape_cv": None, "wape_min": None, "wape_max": None,
                 "unstable_across_windows": None}
    if len(vals) == 0:
        return {**out, "status": "insufficient_denominator" if defined else "no_data"}
    out.update(wape_mean=float(vals.mean()), wape_min=float(vals.min()), wape_max=float(vals.max()))
    if len(vals) == 1:
        return {**out, "status": "single_window"}
    std = float(vals.std(ddof=1))
    out.update(wape_std=std, status="estimated")
    if out["wape_mean"] > 0:
        out["wape_cv"] = std / out["wape_mean"]
        if cv_warn is not None:
            out["unstable_across_windows"] = bool(out["wape_cv"] > cv_warn)
    return out
