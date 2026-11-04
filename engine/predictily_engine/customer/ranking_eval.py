"""Evaluación del ranking del Customer Core frente a las referencias de popularidad y recencia (V2.5 §7, §25.2).

Complementa `metrics.ranking_metrics` (precision/recall@k por unidad) con:
- lift@k por unidad = precision@k / precision@k de un orden aleatorio (media sobre las unidades con ≥ k candidatos de
  positivos/candidatos). Lift 1 = no mejora al azar.
- PR-AUC del puntaje sobre todas las filas del holdout y lift global del `lift_global_fractions` superior de las filas.
- UTILIDAD ECONÓMICA del ranking: lo realizado en la ventana por los k candidatos mejor puntuados de cada unidad (corte × comprador):
  unidades, importe a precio conocido AL CORTE (último precio neto de la relación) y, solo si hay costo unitario conocido al corte,
  margen = unidades × (precio − costo). Se compara contra el óptimo (los k candidatos con mayor valor realizado de la unidad) y entre
  fuentes. Sin costo conocido al corte NO se calcula margen (queda `null` con la limitación); no se aproxima con el costo vigente.
Unidad = (corte, comprador). Los cortes con ventanas solapadas no son independientes: no hay intervalos de confianza por fila.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
from sklearn.metrics import average_precision_score


def _ranked(units: np.ndarray, score: np.ndarray, tiebreak: np.ndarray | None) -> pd.DataFrame:
    df = pd.DataFrame({"u": units, "s": np.asarray(score, dtype=float), "i": np.arange(len(units))})
    df["t"] = np.asarray(tiebreak, dtype=float) if tiebreak is not None else 0.0
    df = df.sort_values(["u", "s", "t"], ascending=[True, False, False], kind="mergesort")
    df["rank"] = df.groupby("u", sort=False).cumcount() + 1
    return df


def topk_mask(units: np.ndarray, score: np.ndarray, k: int, tiebreak: np.ndarray | None = None) -> np.ndarray:
    """Máscara (en el orden original) de los k candidatos mejor puntuados de cada unidad."""
    df = _ranked(units, score, tiebreak)
    out = np.zeros(len(units), dtype=bool)
    out[df.loc[df["rank"] <= k, "i"].to_numpy()] = True
    return out


def lift_at_k(units: np.ndarray, score: np.ndarray, y: np.ndarray, ks: list[int], tiebreak: np.ndarray | None = None) -> dict[str, Any]:
    """precision@k, precision@k aleatoria y lift@k sobre unidades con ≥ k candidatos (media sobre unidades)."""
    y = np.asarray(y, dtype=float)
    df = _ranked(units, score, tiebreak)
    df["y"] = y[df["i"].to_numpy()]
    g = df.groupby("u", sort=False)["y"].agg(["size", "sum"])
    out: dict[str, Any] = {}
    for k in ks:
        top = df[df["rank"] <= k].groupby("u", sort=False)["y"].sum().reindex(g.index).fillna(0.0)
        elig = g["size"] >= k
        if not elig.any():
            out[f"lift_at_{k}"] = out[f"random_precision_at_{k}"] = None
            continue
        prec = float((top[elig] / k).mean())
        rand = float((g["sum"][elig] / g["size"][elig]).mean())
        out[f"random_precision_at_{k}"] = rand
        out[f"lift_at_{k}"] = prec / rand if rand > 0 else None
    return out


def global_lift(score: np.ndarray, y: np.ndarray, fractions: list[float], tiebreak: np.ndarray | None = None) -> dict[str, Any]:
    """Lift y recall de la fracción superior de TODAS las filas (sin agrupar por unidad)."""
    y = np.asarray(y, dtype=float)
    n = len(y)
    out: dict[str, Any] = {}
    if n == 0 or y.sum() == 0:
        return {f"top_{int(round(f * 100))}pct": None for f in fractions}
    tie = np.asarray(tiebreak, dtype=float) if tiebreak is not None else np.zeros(n)
    order = np.lexsort((-tie, -np.asarray(score, dtype=float)))
    base = y.mean()
    for f in fractions:
        m = max(1, int(np.ceil(f * n)))
        hit = float(y[order[:m]].sum())
        out[f"top_{int(round(f * 100))}pct"] = {"n_rows": m, "precision": hit / m, "lift": (hit / m) / base, "recall": hit / float(y.sum())}
    return out


def pr_auc(score: np.ndarray, y: np.ndarray) -> float | None:
    y = np.asarray(y, dtype=float)
    return float(average_precision_score(y, score)) if 0 < y.sum() < len(y) else None


def _capture(units: np.ndarray, selected: np.ndarray, value: np.ndarray, oracle_k: int) -> dict[str, Any]:
    vals = np.where(np.isfinite(value), value, 0.0)
    captured = float(vals[selected].sum())
    df = pd.DataFrame({"u": units, "v": vals}).sort_values(["u", "v"], ascending=[True, False], kind="mergesort")
    rank = df.groupby("u", sort=False).cumcount() + 1
    oracle = float(df.loc[rank <= oracle_k, "v"].sum())
    return {"captured": captured, "oracle": oracle, "capture_ratio": captured / oracle if oracle > 0 else None}


def ranking_utility(units: np.ndarray, score: np.ndarray, k: int, qty: np.ndarray, price: np.ndarray, cost: np.ndarray | None = None,
                    tiebreak: np.ndarray | None = None) -> dict[str, Any]:
    """Utilidad realizada del top-k de cada unidad (unidades, importe a precio al corte y margen si hay costo conocido al corte).

    Solo unidades con MÁS de k candidatos: si la unidad tiene k o menos, el top-k las contiene a todas y el orden no aporta ni quita nada
    (incluirlas acercaría todas las fuentes al óptimo y ocultaría la diferencia)."""
    units = np.asarray(units)
    qty = np.asarray(qty, dtype=float)
    price = np.asarray(price, dtype=float)
    size = pd.Series(units).map(pd.Series(units).value_counts()).to_numpy()
    keep = size > k
    out: dict[str, Any] = {"k": int(k), "n_rows": int(len(units)), "n_rows_ranked": int(keep.sum()), "n_units_ranked": int(len(np.unique(units[keep]))),
                           "scope": f"unidades con más de {k} candidatos (en las demás el top-{k} contiene a todos)",
                           "n_selected": 0, "units": None, "amount_at_cutoff_price": None, "rows_without_price": 0,
                           "margin": None, "rows_without_cost": None}
    if not keep.any():
        return out
    u, sc = units[keep], np.asarray(score, dtype=float)[keep]
    tb = np.asarray(tiebreak, dtype=float)[keep] if tiebreak is not None else None
    q, p = qty[keep], price[keep]
    sel = topk_mask(u, sc, k, tb)
    ok_price = np.isfinite(p)
    out |= {"n_selected": int(sel.sum()), "units": _capture(u, sel, q, k), "rows_without_price": int((~ok_price).sum()),
            "amount_at_cutoff_price": _capture(u, sel, np.where(ok_price, q * p, np.nan), k)}
    if cost is not None:
        c = np.asarray(cost, dtype=float)[keep]
        ok_cost = ok_price & np.isfinite(c)
        out["margin"] = _capture(u, sel, np.where(ok_cost, q * (p - c), np.nan), k)
        out["rows_without_cost"] = int((~ok_cost).sum())
    return out


def ranking_extension(units: np.ndarray, y: np.ndarray, qty: np.ndarray, price: np.ndarray, sources: dict[str, tuple[np.ndarray, np.ndarray | None]],
                      ks: list[int], primary_k: int, fractions: list[float], cost: np.ndarray | None = None) -> dict[str, Any]:
    """Lift@k, PR-AUC, lift global y utilidad económica de cada fuente (`sources`: nombre → (puntaje, desempate)) y comparación del modelo
    servido contra popularidad y recencia."""
    res: dict[str, Any] = {}
    for name, (score, tie) in sources.items():
        res[name] = {"lift": lift_at_k(units, score, y, ks, tie), "pr_auc": pr_auc(score, y), "global_lift": global_lift(score, y, fractions, tie),
                     "utility": ranking_utility(units, score, primary_k, qty, price, cost, tie)}
    comparison: dict[str, Any] = {}
    served = res.get("served")
    if served is not None:
        for other in ("popularity", "recency", "reference"):
            o = res.get(other)
            if o is None:
                continue
            comp: dict[str, Any] = {}
            ls, lo = served["lift"].get(f"lift_at_{primary_k}"), o["lift"].get(f"lift_at_{primary_k}")
            comp[f"lift_at_{primary_k}_ratio"] = (ls / lo) if (ls is not None and lo) else None
            comp["pr_auc_delta"] = (served["pr_auc"] - o["pr_auc"]) if (served["pr_auc"] is not None and o["pr_auc"] is not None) else None
            for measure in ("units", "amount_at_cutoff_price"):
                us, uo = served["utility"].get(measure), o["utility"].get(measure)
                cs, co = (us or {}).get("captured"), (uo or {}).get("captured")
                comp[f"{measure}_captured_ratio"] = (cs / co) if (cs is not None and co) else None
            comparison[f"served_vs_{other}"] = comp
    return {"sources": res, "comparison": comparison, "primary_k": int(primary_k),
            "margin_limitation": None if cost is not None else ("Sin costo unitario conocido al corte en la lectura de compras del Customer Core: "
                                                              "la utilidad se mide en unidades e importe a precio al corte; no se calcula margen.")}
