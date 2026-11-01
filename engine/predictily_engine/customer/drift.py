"""Drift del Customer Core: distribución de probabilidades y tasas observadas entre cortes (V2.5 §7: «Evaluar … cold start y drift»).

- PSI (population stability index) entre dos distribuciones de probabilidad servida, con tramos por deciles de la distribución de
  REFERENCIA. Convención: PSI < 0,10 estable · 0,10–0,25 cambio moderado · ≥ 0,25 cambio mayor (umbrales de configuración, «a aprobar en el
  piloto»). Con pocas filas el PSI crudo se infla por ruido muestral; el estado se decide con el PSI AJUSTADO (PSI − (tramos − 1)·(1/n_ref + 1/n_act)),
  nunca por debajo de 0, y ambos se informan.
- Tasa observada: cambio relativo de la tasa de compra entre dos grupos de cortes, solo con ≥ `drift_min_positives` positivos en cada uno.
- Las ventanas de cortes consecutivos se solapan: las filas no son independientes, por lo que el aviso es una señal de revisión, no una prueba
  estadística.
En entrenamiento se compara bloque de calibración vs holdout y los cortes consecutivos del holdout; en la predicción diaria, la distribución
actual contra la guardada en el artefacto (probabilidades servidas en el holdout del entrenamiento).
"""

from __future__ import annotations

from typing import Any

import numpy as np


def reference_distribution(p: np.ndarray, n_bins: int = 10) -> dict[str, Any]:
    """Tramos por cuantiles de `p` (deciles por defecto; los cuantiles repetidos se funden) con la proporción de filas de cada tramo."""
    p = np.asarray(p, dtype=np.float64)
    p = p[np.isfinite(p)]
    if len(p) == 0:
        return {"edges": [], "proportions": [], "n": 0, "mean": None}
    edges = np.unique(np.quantile(p, np.linspace(0, 1, n_bins + 1)[1:-1]))
    counts = np.bincount(np.digitize(p, edges), minlength=len(edges) + 1)
    return {"edges": [float(e) for e in edges], "proportions": [float(c / len(p)) for c in counts], "n": int(len(p)), "mean": float(p.mean())}


def _proportions(p: np.ndarray, edges: list[float]) -> np.ndarray:
    p = np.asarray(p, dtype=np.float64)
    p = p[np.isfinite(p)]
    counts = np.bincount(np.digitize(p, np.asarray(edges)), minlength=len(edges) + 1).astype(float)
    return counts / max(len(p), 1)


def psi(expected: np.ndarray, actual: np.ndarray, eps: float = 1e-4) -> float:
    e = np.maximum(np.asarray(expected, dtype=float), eps)
    a = np.maximum(np.asarray(actual, dtype=float), eps)
    return float(np.sum((a - e) * np.log(a / e)))


def level(value: float | None, warn: float, alert: float) -> str:
    if value is None:
        return "insufficient_data"
    return "alert" if value >= alert else ("warn" if value >= warn else "ok")


def psi_against_reference(p_current: np.ndarray, reference: dict[str, Any] | None, cfg: dict[str, Any]) -> dict[str, Any]:
    """PSI de la distribución actual contra una distribución de referencia guardada (`reference_distribution`)."""
    if not reference or not reference.get("n"):
        return {"status": "insufficient_data", "reason": "El artefacto no guarda la distribución de referencia (entrenado antes de W3-C)."}
    n_act = int(np.isfinite(np.asarray(p_current, dtype=float)).sum())
    if n_act == 0:
        return {"status": "insufficient_data", "reason": "Sin probabilidades actuales."}
    exp = np.asarray(reference["proportions"], dtype=float)
    act = _proportions(p_current, reference["edges"])
    raw = psi(exp, act)
    adj = max(0.0, raw - (len(exp) - 1) * (1.0 / reference["n"] + 1.0 / n_act))
    st = level(adj, float(cfg["drift_psi_warn"]), float(cfg["drift_psi_alert"]))
    return {"status": st, "psi": raw, "psi_adjusted": adj, "n_reference": int(reference["n"]), "n_current": n_act,
            "mean_reference": reference.get("mean"), "mean_current": float(np.nanmean(np.asarray(p_current, dtype=float))),
            "warn": float(cfg["drift_psi_warn"]), "alert": float(cfg["drift_psi_alert"])}


def drift_between(p_a: np.ndarray, y_a: np.ndarray, p_b: np.ndarray, y_b: np.ndarray, cfg: dict[str, Any]) -> dict[str, Any]:
    """Cambio de distribución de probabilidades (PSI, referencia = a) y de tasa observada entre dos grupos de filas (a → b)."""
    p_a, p_b = np.asarray(p_a, dtype=float), np.asarray(p_b, dtype=float)
    y_a, y_b = np.asarray(y_a, dtype=float), np.asarray(y_b, dtype=float)
    if len(p_a) == 0 or len(p_b) == 0:
        return {"status": "insufficient_data", "n_a": int(len(p_a)), "n_b": int(len(p_b))}
    ref = reference_distribution(p_a, int(cfg["drift_bins"]))
    out = psi_against_reference(p_b, ref, cfg)
    out |= {"n_a": int(len(p_a)), "n_b": int(len(p_b))}
    pos_a, pos_b = int(y_a.sum()), int(y_b.sum())
    rate_a, rate_b = float(y_a.mean()), float(y_b.mean())
    min_pos = int(cfg["drift_min_positives"])
    if pos_a >= min_pos and pos_b >= min_pos and rate_a > 0:
        rel = (rate_b - rate_a) / rate_a
        out["observed_rate"] = {"rate_a": rate_a, "rate_b": rate_b, "relative_change": rel,
                                "status": "alert" if abs(rel) >= float(cfg["drift_rate_rel_alert"]) else "ok", "n_positive_a": pos_a, "n_positive_b": pos_b}
    else:
        out["observed_rate"] = {"rate_a": rate_a, "rate_b": rate_b, "relative_change": None, "status": "insufficient_data",
                                "n_positive_a": pos_a, "n_positive_b": pos_b, "reason": f"menos de {min_pos} positivos en algún grupo"}
    return out


def drift_across_cutoffs(cutoffs: np.ndarray, p: np.ndarray, y: np.ndarray, cfg: dict[str, Any]) -> dict[str, Any]:
    """Probabilidad media, tasa observada y PSI entre cortes consecutivos (con ≥ `stability_min_rows` filas), referencia = el corte anterior."""
    cutoffs = np.asarray(cutoffs)
    min_rows = int(cfg["stability_min_rows"])
    items: list[dict[str, Any]] = []
    prev = None
    for c in np.unique(cutoffs):
        m = cutoffs == c
        if int(m.sum()) < min_rows:
            continue
        pp, yy = np.asarray(p, dtype=float)[m], np.asarray(y, dtype=float)[m]
        item: dict[str, Any] = {"cutoff": int(c), "n": int(m.sum()), "mean_predicted": float(pp.mean()), "observed_rate": float(yy.mean())}
        if prev is not None:
            d = psi_against_reference(pp, reference_distribution(prev[0], int(cfg["drift_bins"])), cfg)
            item["psi_vs_previous"] = d.get("psi")
            item["psi_adjusted_vs_previous"] = d.get("psi_adjusted")
            item["status"] = d["status"]
        items.append(item)
        prev = (pp, yy)
    adj = [i["psi_adjusted_vs_previous"] for i in items if i.get("psi_adjusted_vs_previous") is not None]
    rates = [i["observed_rate"] for i in items]
    worst = max(adj) if adj else None
    return {"per_cutoff": items, "n_cutoffs": len(items), "max_psi_adjusted": worst,
            "status": level(worst, float(cfg["drift_psi_warn"]), float(cfg["drift_psi_alert"])) if worst is not None else "insufficient_data",
            "observed_rate_range": [float(min(rates)), float(max(rates))] if rates else None,
            "note": "PSI ajustado por ruido muestral; cortes con ventanas solapadas no son independientes: señal de revisión, no prueba estadística."}
