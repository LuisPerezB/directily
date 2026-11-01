"""Segmentos de comprador × producto para calibración y diagnóstico (V2.5 §7: «calibración por segmento y horizonte»).

Dimensiones (docs/ml/CALIBRATION_AND_RANKING.md §3), todas calculadas con lo conocido al corte:
- `frequency`: compras de la relación en los últimos 365 días (`rel_n_365`), terciles del bloque de CALIBRACIÓN (low/medium/high).
- `value`: gasto aproximado de 180 días = unidades de 180 días × último precio neto de la relación, terciles del bloque de calibración
  (low/medium/high; `unknown` sin precio).
- `tenure`: antigüedad de la relación en días (`rel_tenure_days`, acotada a 365) con cortes fijos de configuración (new/established/veteran).
- `history`: `cold_start` (menos de `cold_start_min_purchases` compras) o `warm`.

Los cortes de frecuencia y valor se ajustan SOLO con el bloque de calibración (nunca con el holdout) y se guardan en el artefacto para que
la predicción diaria asigne el mismo segmento. `segment` (columna de `customer_predictions`) = `frequency:<etiqueta>`, la dimensión primaria.

Recalibración por segmento (candidato, NO se sirve): desplazamiento logit por segmento que iguala la media calibrada a la tasa observada del
bloque de calibración, encogido hacia 0 según los positivos del segmento; se informa si mejoraría el Brier del bloque de selección y, solo como
información, el del holdout. La probabilidad servida no cambia.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.metrics import brier_score, probability_metrics

SEGMENTER_VERSION = "cust-seg-v1"
DIMENSIONS = ("frequency", "value", "tenure", "history")
PRIMARY_DIMENSION = "frequency"
_NAMES = {1: ["all"], 2: ["low", "high"], 3: ["low", "medium", "high"]}
TENURE_NAMES = ["new", "established", "veteran"]
UNKNOWN = "unknown"


def value_proxy(base: pd.DataFrame) -> np.ndarray:
    """Gasto aproximado de 180 días: unidades de 180 días × último precio neto de la relación (NaN sin precio)."""
    return base["qty_sum_180"].to_numpy(dtype=float) * base["price_last"].to_numpy(dtype=float)


def _tercile_edges(x: np.ndarray) -> list[float]:
    x = x[np.isfinite(x)]
    if len(x) < 3:
        return []
    edges = np.unique(np.quantile(x, [1 / 3, 2 / 3]))
    return [float(e) for e in edges if x.min() < e <= x.max()]       # un corte solo existe si hay filas por debajo y por encima


def _digitize(x: np.ndarray, edges: list[float], names: list[str]) -> np.ndarray:
    out = np.full(len(x), UNKNOWN, dtype=object)
    ok = np.isfinite(x)
    out[ok] = np.array(names, dtype=object)[np.digitize(x[ok], edges, right=False)]
    return out


def _names_for(edges: list[float]) -> list[str]:
    return _NAMES[min(len(edges) + 1, 3)]


@dataclass
class Segmenter:
    """Cortes de segmento ajustados con el bloque de calibración (picklable; viaja en el artefacto firmado)."""

    frequency_edges: list[float] = field(default_factory=list)
    value_edges: list[float] = field(default_factory=list)
    tenure_edges: list[float] = field(default_factory=lambda: [90.0, 365.0])
    cold_start_min_purchases: int = 3
    version: str = SEGMENTER_VERSION

    @classmethod
    def fit(cls, base_calibration: pd.DataFrame, cfg: dict[str, Any]) -> Segmenter:
        return cls(frequency_edges=_tercile_edges(base_calibration["rel_n_365"].to_numpy(dtype=float)),
                   value_edges=_tercile_edges(value_proxy(base_calibration)),
                   tenure_edges=[float(e) for e in cfg["segment_tenure_edges"]],
                   cold_start_min_purchases=int(cfg["cold_start_min_purchases"]))

    def labels(self, base: pd.DataFrame) -> dict[str, np.ndarray]:
        """Etiqueta de cada fila en cada dimensión (`low`, `medium`, `high`, `new`, … `cold_start`, `warm`, `unknown`)."""
        freq = _digitize(base["rel_n_365"].to_numpy(dtype=float), self.frequency_edges, _names_for(self.frequency_edges))
        val = _digitize(value_proxy(base), self.value_edges, _names_for(self.value_edges))
        ten_names = TENURE_NAMES[: len(self.tenure_edges) + 1]
        ten = _digitize(base["rel_tenure_days"].to_numpy(dtype=float), self.tenure_edges, ten_names)
        hist = np.where(base["n_purchases_all"].to_numpy(dtype=float) < self.cold_start_min_purchases, "cold_start", "warm").astype(object)
        return {"frequency": freq, "value": val, "tenure": ten, "history": hist}

    def keys(self, base: pd.DataFrame) -> dict[str, np.ndarray]:
        """Claves `<dimensión>:<valor>` (formato de `level_key` en model_evaluations y de `customer_predictions.segment`)."""
        return {d: np.array([f"{d}:{v}" for v in vals], dtype=object) for d, vals in self.labels(base).items()}

    def primary(self, base: pd.DataFrame) -> np.ndarray:
        return self.keys(base)[PRIMARY_DIMENSION]

    def as_dict(self) -> dict[str, Any]:
        return {"version": self.version, "frequency_edges": self.frequency_edges, "value_edges": self.value_edges,
                "tenure_edges": self.tenure_edges, "cold_start_min_purchases": self.cold_start_min_purchases,
                "fitted_on": "bloque de calibración (nunca el holdout)"}


def segment_calibration(y: np.ndarray, p: np.ndarray, p_ref: np.ndarray, keys: dict[str, np.ndarray], cfg: dict[str, Any],
                        extra: dict[str, np.ndarray] | None = None) -> list[dict[str, Any]]:
    """Calibración del holdout por segmento (todas las dimensiones): n, positivos, tasa observada, media predicha, razón media/observada,
    Brier y log loss del modelo servido y de la referencia, ECE y curva (con n por tramo). Un segmento con menos de `segment_min_rows`
    filas o `segment_min_positives` positivos es `insufficient_data` (sin cifras de calibración).
    Estado: `calibrated` si |media/observada − 1| ≤ `segment_calibration_tolerance` y ECE ≤ `ece_alert_threshold`; si no, `miscalibrated`.
    `extra`: columnas adicionales por fila (p. ej. {"stability": …}) no usadas aquí."""
    min_rows, min_pos = int(cfg["segment_min_rows"]), int(cfg["segment_min_positives"])
    tol, ece_thr = float(cfg["segment_calibration_tolerance"]), float(cfg["ece_alert_threshold"])
    rows: list[dict[str, Any]] = []
    for dim, k in keys.items():
        for value in sorted({str(v) for v in k}):
            mask = k == value
            yy, pp, rr = y[mask], p[mask], p_ref[mask]
            n, pos = int(mask.sum()), int(yy.sum())
            item: dict[str, Any] = {"dimension": dim, "segment": value, "n": n, "n_positive": pos,
                                    "base_rate": float(yy.mean()) if n else None, "mean_predicted": float(pp.mean()) if n else None}
            if n < min_rows or pos < min_pos or pos == n:
                item |= {"status": "insufficient_data", "reason": (f"menos de {min_rows} filas o de {min_pos} positivos (o sin negativos): "
                                                                    "sin cifras de calibración (umbral a aprobar en el piloto)"),
                         "served": None, "reference": None, "mean_ratio": None}
            else:
                served = probability_metrics(yy, pp, n_bins=5, min_bin=30)
                ref = probability_metrics(yy, rr, n_bins=5, min_bin=30)
                ratio = item["mean_predicted"] / item["base_rate"] if item["base_rate"] else None
                ok = ratio is not None and abs(ratio - 1.0) <= tol and (served["ece"] is not None and served["ece"] <= ece_thr)
                item |= {"status": "calibrated" if ok else "miscalibrated", "mean_ratio": ratio, "served": served, "reference": ref,
                         "brier_delta_vs_reference": (ref["brier"] - served["brier"]) if (ref["brier"] is not None and served["brier"] is not None) else None}
            rows.append(item)
    return rows


# ── recalibración por segmento (candidato; apagada por defecto) ──

def _logit(p: np.ndarray) -> np.ndarray:
    q = np.clip(p, 1e-6, 1 - 1e-6)
    return np.log(q / (1 - q))


def _sigmoid(z: np.ndarray) -> np.ndarray:
    return 1.0 / (1.0 + np.exp(-z))


def fit_segment_shifts(p_cal: np.ndarray, y_cal: np.ndarray, seg_cal: np.ndarray, min_positives: int, shrink_k: float = 50.0) -> dict[str, float]:
    """Desplazamiento logit δ_s por segmento que iguala Σ p' a Σ y en el bloque de calibración, encogido por n_pos/(n_pos + k).
    Segmentos con menos de `min_positives` positivos no se ajustan (δ = 0)."""
    out: dict[str, float] = {}
    for s in sorted({str(v) for v in seg_cal}):
        m = seg_cal == s
        pos = float(y_cal[m].sum())
        if pos < min_positives or pos >= m.sum():
            continue
        z = _logit(p_cal[m])
        lo, hi = -6.0, 6.0
        for _ in range(40):
            mid = (lo + hi) / 2
            if _sigmoid(z + mid).sum() < pos:
                lo = mid
            else:
                hi = mid
        out[s] = float((lo + hi) / 2 * (pos / (pos + shrink_k)))
    return out


def apply_segment_shifts(p: np.ndarray, seg: np.ndarray, shifts: dict[str, float]) -> np.ndarray:
    if not shifts:
        return np.asarray(p, dtype=np.float64)
    delta = np.array([shifts.get(str(s), 0.0) for s in seg], dtype=np.float64)
    out = _sigmoid(_logit(np.asarray(p, dtype=np.float64)) + delta)
    return np.clip(out, 1e-4, 1 - 1e-4)


def evaluate_segment_recalibration(p_cal, y_cal, seg_cal, p_sel, y_sel, seg_sel, p_hold, y_hold, seg_hold, cfg: dict[str, Any]) -> dict[str, Any]:
    """Compara la probabilidad servida con su versión recalibrada por segmento: ajuste con el bloque de calibración, decisión por Brier de
    SELECCIÓN y, solo como información, Brier del holdout. Devuelve los desplazamientos y si se adoptaría (`adopted`)."""
    shifts = fit_segment_shifts(np.asarray(p_cal), np.asarray(y_cal), np.asarray(seg_cal), int(cfg["segment_min_positives"]))
    out: dict[str, Any] = {"shifts": shifts, "n_segments_shifted": len(shifts), "served": False,
                           "rule": "candidato: se adoptaría si baja el Brier del bloque de selección (≥ 1 % relativo); NO se sirve; el holdout solo informa"}
    if p_sel is None or not len(p_sel) or not shifts:
        out |= {"adopted": False, "reason": "sin bloque de selección o sin segmentos ajustables", "selection": None, "holdout": None}
        return out
    sel_b0 = brier_score(y_sel, p_sel)
    sel_b1 = brier_score(y_sel, apply_segment_shifts(p_sel, seg_sel, shifts))
    hold_b0 = brier_score(y_hold, p_hold)
    hold_b1 = brier_score(y_hold, apply_segment_shifts(p_hold, seg_hold, shifts))
    impr = (sel_b0 - sel_b1) / sel_b0 if sel_b0 else None
    adopted = bool(impr is not None and impr >= 0.01)
    out |= {"adopted": adopted, "selection": {"brier_global": sel_b0, "brier_segmented": sel_b1, "relative_improvement": impr},
            "holdout": {"brier_global": hold_b0, "brier_segmented": hold_b1, "informative_only": True}}
    return out


