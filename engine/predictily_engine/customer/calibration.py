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
    method = "isotonic"

    def __init__(self) -> None:
        self.model = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip", increasing=True)
        self.floor = 1e-4

    def fit(self, raw: np.ndarray, y: np.ndarray) -> IsotonicCalibrator:
        self.model.fit(np.asarray(raw, dtype=np.float64), np.asarray(y, dtype=np.float64))
        self.floor = 1.0 / (len(y) + 2)
        return self

    def transform(self, raw: np.ndarray) -> np.ndarray:
        return np.clip(self.model.predict(np.asarray(raw, dtype=np.float64)), self.floor, 1.0 - self.floor)


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
