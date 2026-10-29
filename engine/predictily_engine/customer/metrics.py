"""Métricas del Customer Core: probabilidad (Brier, log loss, calibración por grupos con n), ranking, cantidad y monto.

AUC/accuracy no sustituyen la calibración. Los denominadores inválidos (suma de reales = 0) devuelven None.
Las observaciones de cortes consecutivos con ventanas solapadas NO son independientes: los intervalos de
confianza por fila no se publican; la variabilidad se estima con bootstrap por bloques de corte.
"""

from __future__ import annotations

import math
from typing import Any

import numpy as np
import pandas as pd
from sklearn.metrics import average_precision_score, roc_auc_score

EPS = 1e-6


def _clip(p: np.ndarray) -> np.ndarray:
    return np.clip(np.asarray(p, dtype=np.float64), EPS, 1.0 - EPS)


def brier_score(y, p) -> float | None:
    y = np.asarray(y, dtype=float)
    return float(np.mean((np.asarray(p, dtype=float) - y) ** 2)) if len(y) else None


def log_loss_score(y, p) -> float | None:
    y = np.asarray(y, dtype=float)
    if not len(y):
        return None
    q = _clip(p)
    return float(-np.mean(y * np.log(q) + (1 - y) * np.log(1 - q)))


def wilson_interval(k: float, n: int, z: float = 1.96) -> tuple[float, float]:
    if n <= 0:
        return (float("nan"), float("nan"))
    ph = k / n
    den = 1 + z * z / n
    centre = (ph + z * z / (2 * n)) / den
    half = z * math.sqrt(ph * (1 - ph) / n + z * z / (4 * n * n)) / den
    return (max(0.0, centre - half), min(1.0, centre + half))


def calibration_curve(y, p, n_bins: int = 10, min_bin: int = 30) -> list[dict[str, Any]]:
    """Curva por grupos de igual frecuencia; los grupos con menos de `min_bin` filas se fusionan con el vecino."""
    y = np.asarray(y, dtype=float)
    p = np.asarray(p, dtype=float)
    n = len(y)
    if n == 0:
        return []
    order = np.argsort(p, kind="mergesort")
    y, p = y[order], p[order]
    k = int(max(1, min(n_bins, n // max(min_bin, 1))))
    edges = np.linspace(0, n, k + 1).astype(int)
    out: list[dict[str, Any]] = []
    for a, b in zip(edges[:-1], edges[1:]):
        if b <= a:
            continue
        yy, pp = y[a:b], p[a:b]
        lo, hi = wilson_interval(float(yy.sum()), len(yy))
        out.append({"n": int(len(yy)), "mean_predicted": float(pp.mean()), "observed_rate": float(yy.mean()),
                    "observed_lo95": lo, "observed_hi95": hi, "p_min": float(pp.min()), "p_max": float(pp.max())})
    return out


def expected_calibration_error(curve: list[dict[str, Any]]) -> float | None:
    n = sum(b["n"] for b in curve)
    if not n:
        return None
    return float(sum(b["n"] * abs(b["observed_rate"] - b["mean_predicted"]) for b in curve) / n)


def probability_metrics(y, p, n_bins: int = 10, min_bin: int = 30) -> dict[str, Any]:
    y = np.asarray(y, dtype=float)
    p = np.asarray(p, dtype=float)
    n = int(len(y))
    pos = int(y.sum()) if n else 0
    base = pos / n if n else None
    brier = brier_score(y, p)
    brier_clim = brier_score(y, np.full(n, base)) if n else None
    curve = calibration_curve(y, p, n_bins, min_bin)
    out = {
        "n": n, "n_positive": pos, "base_rate": base, "mean_predicted": float(p.mean()) if n else None,
        "brier": brier, "brier_climatology": brier_clim,
        "brier_skill_vs_climatology": (1 - brier / brier_clim) if (brier is not None and brier_clim) else None,
        "log_loss": log_loss_score(y, p),
        "pr_auc": float(average_precision_score(y, p)) if 0 < pos < n else None,
        "roc_auc": float(roc_auc_score(y, p)) if 0 < pos < n else None,
        "ece": expected_calibration_error(curve),
        "calibration_curve": curve,
    }
    return out


def quantity_metrics(y, pred) -> dict[str, Any]:
    """MAE, sesgo relativo (Σ(pred − real)/Σreal), WAPE (si Σreal > 0), RMSE y mediana del error absoluto."""
    y = np.asarray(y, dtype=float)
    pred = np.asarray(pred, dtype=float)
    n = int(len(y))
    if n == 0:
        return {"n": 0, "mae": None, "bias": None, "wape": None, "rmse": None, "median_abs_error": None,
                "sum_actual": 0.0, "sum_predicted": 0.0}
    err = pred - y
    total = float(y.sum())
    return {
        "n": n, "mae": float(np.mean(np.abs(err))), "bias": float(err.sum() / total) if total > 0 else None,
        "wape": float(np.abs(err).sum() / total) if total > 0 else None, "rmse": float(np.sqrt(np.mean(err ** 2))),
        "median_abs_error": float(np.median(np.abs(err))), "sum_actual": total, "sum_predicted": float(pred.sum()),
    }


def interval_coverage(y, lo, hi) -> dict[str, Any]:
    y = np.asarray(y, dtype=float)
    if not len(y):
        return {"n": 0, "coverage": None, "mean_width_ratio": None}
    lo, hi = np.asarray(lo, dtype=float), np.asarray(hi, dtype=float)
    return {"n": int(len(y)), "coverage": float(np.mean((y >= lo) & (y <= hi))),
            "mean_width_ratio": float(np.mean((hi - lo) / np.maximum((hi + lo) / 2, 1e-9)))}


def ranking_metrics(units: np.ndarray, score: np.ndarray, y: np.ndarray, ks: list[int], tiebreak: np.ndarray | None = None) -> dict[str, Any]:
    """precision@k y recall@k promediados por unidad (corte × comprador).

    precision@k: unidades con al menos k candidatos (denominador exactamente k). recall@k: unidades con ≥ 1 positivo.
    """
    df = pd.DataFrame({"u": units, "s": score, "y": np.asarray(y, dtype=float)})
    df["t"] = tiebreak if tiebreak is not None else 0.0
    df = df.sort_values(["u", "s", "t"], ascending=[True, False, False], kind="mergesort")
    df["rank"] = df.groupby("u", sort=False).cumcount() + 1
    n_u = df.groupby("u", sort=False)["y"].agg(["size", "sum"])
    out: dict[str, Any] = {"n_units": int(len(n_u)), "n_units_with_positive": int((n_u["sum"] > 0).sum())}
    for k in ks:
        top = df[df["rank"] <= k].groupby("u", sort=False)["y"].sum()
        top = top.reindex(n_u.index).fillna(0.0)
        elig_p = n_u["size"] >= k
        elig_r = n_u["sum"] > 0
        out[f"precision_at_{k}"] = float((top[elig_p] / k).mean()) if elig_p.any() else None
        out[f"recall_at_{k}"] = float((top[elig_r] / n_u["sum"][elig_r]).mean()) if elig_r.any() else None
        out[f"n_units_precision_at_{k}"] = int(elig_p.sum())
    return out


def block_bootstrap_difference(blocks: np.ndarray, loss_a: np.ndarray, loss_b: np.ndarray, n_boot: int = 300, seed: int = 0) -> dict[str, Any]:
    """Diferencia media de pérdida (a − b) con bootstrap por bloques de corte (las ventanas se solapan: no se
    remuestrean filas sueltas). Informativo; no gobierna la promoción."""
    blocks = np.asarray(blocks)
    ub = np.unique(blocks)
    if len(ub) < 3:
        return {"n_blocks": int(len(ub)), "mean_diff": float(np.mean(loss_a - loss_b)) if len(loss_a) else None, "lo95": None, "hi95": None}
    rng = np.random.default_rng(seed)
    sums_a = np.array([loss_a[blocks == b].sum() for b in ub])
    sums_b = np.array([loss_b[blocks == b].sum() for b in ub])
    cnt = np.array([(blocks == b).sum() for b in ub], dtype=float)
    diffs = []
    for _ in range(n_boot):
        idx = rng.integers(0, len(ub), len(ub))
        diffs.append((sums_a[idx].sum() - sums_b[idx].sum()) / cnt[idx].sum())
    return {"n_blocks": int(len(ub)), "mean_diff": float((sums_a.sum() - sums_b.sum()) / cnt.sum()),
            "lo95": float(np.percentile(diffs, 2.5)), "hi95": float(np.percentile(diffs, 97.5))}
