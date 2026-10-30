"""Calibradores de probabilidad (isotónico o Platt) y corrección de prior por submuestreo de negativos.

Se ajustan SOLO con el bloque de calibración (posterior en el tiempo al entrenamiento del modelo y separado
de él por H días). Las salidas se acotan a [1/(n+2), 1 − 1/(n+2)] para no producir log loss infinito.
"""

from __future__ import annotations

import numpy as np
from sklearn.isotonic import IsotonicRegression
from sklearn.linear_model import LogisticRegression


def _logit(p: np.ndarray) -> np.ndarray:
    q = np.clip(p, 1e-6, 1 - 1e-6)
    return np.log(q / (1 - q))


def prior_correction(p: np.ndarray, neg_rate: float) -> np.ndarray:
    """Recupera la probabilidad de la población cuando se conservó la fracción `neg_rate` de los negativos."""
    if neg_rate >= 1.0:
        return np.asarray(p, dtype=np.float64)
    p = np.clip(np.asarray(p, dtype=np.float64), 1e-9, 1 - 1e-9)
    return neg_rate * p / (neg_rate * p + (1.0 - p))


class IsotonicCalibrator:
    """Isotónica sobre grupos de puntaje (≥ ~100 filas por grupo) con interpolación y extremos constantes.

    La isotónica clásica sobre filas sueltas produce escalones en los extremos (grupos diminutos con 0 positivos ⇒
    probabilidad ≈ 0 que luego falla fuera de muestra). Agrupar por cuantiles, suavizar con la tasa global y acotar
    evita probabilidades degeneradas sin perder monotonía.
    """

    method = "isotonic"
    MIN_PER_BIN = 100
    SMOOTH = 2.0

    def __init__(self) -> None:
        self.x = np.array([0.0, 1.0])
        self.y = np.array([0.5, 0.5])
        self.floor = 1e-4

    def fit(self, raw: np.ndarray, y: np.ndarray) -> IsotonicCalibrator:
        raw = np.asarray(raw, dtype=np.float64)
        y = np.asarray(y, dtype=np.float64)
        n = len(y)
        k = int(np.clip(n // self.MIN_PER_BIN, 3, 40))
        order = np.argsort(raw, kind="mergesort")
        r, t = raw[order], y[order]
        edges = np.linspace(0, n, k + 1).astype(int)
        prior = float(y.mean())
        xs, ys, ws = [], [], []
        for a, b in zip(edges[:-1], edges[1:]):
            if b <= a:
                continue
            xs.append(float(r[a:b].mean()))
            ys.append((float(t[a:b].sum()) + self.SMOOTH * prior) / ((b - a) + self.SMOOTH))
            ws.append(float(b - a))
        iso = IsotonicRegression(y_min=0.0, y_max=1.0, increasing=True).fit(np.array(xs), np.array(ys), sample_weight=np.array(ws))
        self.x = np.array(xs)
        self.y = iso.predict(self.x)
        self.floor = 1.0 / (n + 2)
        return self

    def transform(self, raw: np.ndarray) -> np.ndarray:
        return np.clip(np.interp(np.asarray(raw, dtype=np.float64), self.x, self.y), self.floor, 1.0 - self.floor)


class PlattCalibrator:
    method = "platt"

    def __init__(self) -> None:
        self.model = LogisticRegression(C=1e4, max_iter=200)
        self.floor = 1e-4

    def fit(self, raw: np.ndarray, y: np.ndarray) -> PlattCalibrator:
        self.model.fit(_logit(np.asarray(raw, dtype=np.float64)).reshape(-1, 1), np.asarray(y, dtype=int))
        self.floor = 1.0 / (len(y) + 2)
        return self

    def transform(self, raw: np.ndarray) -> np.ndarray:
        p = self.model.predict_proba(_logit(np.asarray(raw, dtype=np.float64)).reshape(-1, 1))[:, 1]
        return np.clip(p, self.floor, 1.0 - self.floor)


class IdentityCalibrator:
    """Sin calibrar (datos de calibración insuficientes): las probabilidades se marcan como no calibradas."""

    method = "none"

    def fit(self, raw, y) -> IdentityCalibrator:
        return self

    def transform(self, raw: np.ndarray) -> np.ndarray:
        return np.clip(np.asarray(raw, dtype=np.float64), 1e-4, 1 - 1e-4)


def fit_calibrator(raw: np.ndarray, y: np.ndarray, isotonic_min_positives: int, min_positives: int):
    """Isotónica con suficientes positivos; Platt con pocos; sin calibrar si no hay datos mínimos."""
    pos = int(np.sum(y))
    if pos < min_positives or pos == len(y):
        return IdentityCalibrator().fit(raw, y)
    if pos >= isotonic_min_positives:
        return IsotonicCalibrator().fit(raw, y)
    return PlattCalibrator().fit(raw, y)
