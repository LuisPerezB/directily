"""Servicio de modelos entrenados: probabilidad calibrada, cantidad condicionada y coherencia entre horizontes.

El mismo código se usa en la evaluación del holdout y en la predicción diaria, de modo que lo evaluado es lo servido.
"""

from __future__ import annotations

from typing import Any

import numpy as np

from predictily_engine.customer.calibration import prior_correction


def serve_probability(art: dict[str, Any], X: np.ndarray, ref: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """(probabilidad calibrada, puntaje crudo) con el modelo y el calibrador del artefacto."""
    model = art["model"]
    raw = model.predict_raw(X, ref)
    if model.kind == "complex" and art.get("neg_rate", 1.0) < 1.0:
        raw = prior_correction(raw, art["neg_rate"])
    return art["calibrator"].transform(raw), raw


def serve_quantity(art: dict[str, Any], X: np.ndarray, ref: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """(cantidad condicionada a compra, límite inferior, límite superior) del intervalo conformal multiplicativo."""
    q = art["model"].predict(X, ref) * art["scale"]
    q = np.maximum(q, 1e-3)
    lo = q * np.exp(art["interval_log_q"][0])
    hi = q * np.exp(art["interval_log_q"][1])
    return q, lo, hi


def enforce_nested(probs: dict[int, np.ndarray]) -> tuple[dict[int, np.ndarray], dict[str, Any]]:
    """Coherencia P(H1) ≤ P(H2) ≤ … (eventos anidados): máximo acumulado por horizonte creciente.

    Devuelve las probabilidades corregidas y la tasa de corrección por horizonte (filas modificadas / filas).
    """
    horizons = sorted(probs)
    out: dict[int, np.ndarray] = {}
    stats: dict[str, Any] = {"rule": "cumulative_max", "horizons": horizons}
    running = None
    for h in horizons:
        p = np.asarray(probs[h], dtype=np.float64)
        corrected = p if running is None else np.maximum(p, running)
        changed = corrected > p + 1e-12
        stats[f"corrected_rate_h{h}"] = float(changed.mean()) if len(p) else 0.0
        stats[f"mean_abs_correction_h{h}"] = float((corrected - p).mean()) if len(p) else 0.0
        out[h] = corrected
        running = corrected
    return out, stats


def enforce_nested_quantity(p: dict[int, np.ndarray], q: dict[int, np.ndarray]) -> tuple[dict[int, np.ndarray], dict[str, Any]]:
    """Mantiene E[Q_H] = P_H·q_H no decreciente en H (Q_7 ≤ Q_30 ≤ Q_90 punto a punto).

    Si P_H·q_H < E_{H' anterior}, se sube q_H (la probabilidad no se toca) hasta igualar la esperanza previa.
    """
    horizons = sorted(p)
    out: dict[int, np.ndarray] = {}
    stats: dict[str, Any] = {"rule": "raise_conditional_quantity"}
    prev_e = None
    for h in horizons:
        qh = np.asarray(q[h], dtype=np.float64)
        if prev_e is not None:
            need = prev_e / np.maximum(p[h], 1e-9)
            fixed = np.maximum(qh, need)
            stats[f"corrected_rate_h{h}"] = float((fixed > qh + 1e-12).mean()) if len(qh) else 0.0
            qh = fixed
        else:
            stats[f"corrected_rate_h{h}"] = 0.0
        out[h] = qh
        prev_e = p[h] * qh
    return out, stats
