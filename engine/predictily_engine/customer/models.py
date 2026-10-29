"""Modelos del Customer Core.

Probabilidad de compra (por horizonte H):  referencia frecuencia/recencia · regresión logística · LGBMClassifier.
Cantidad condicionada a compra (por H):    referencia histórica · LGBMRegressor con objetivos comparados
                                            (L2, L2 sobre log1p con corrección de retransformación, Tweedie, Gamma y,
                                            solo si las cantidades son enteras, Poisson). No se asume ninguno.

Los modelos son compartidos dentro de la empresa (nunca uno por comprador). Todas las clases son picklables
(joblib) para el registro de artefactos.
"""

from __future__ import annotations

from typing import Any

import lightgbm as lgb
import numpy as np
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import FunctionTransformer, StandardScaler


def signed_log(x: np.ndarray) -> np.ndarray:
    return np.sign(x) * np.log1p(np.abs(x))


# ───────────────────────────── probabilidad ─────────────────────────────

class ReferenceProbability:
    """Referencia sin ajuste: devuelve la columna `ref_p` (frecuencia/recencia)."""

    family = "reference"
    kind = "reference"

    def fit(self, X, y) -> ReferenceProbability:  # noqa: ARG002
        return self

    def predict_raw(self, X: np.ndarray, ref: np.ndarray) -> np.ndarray:  # noqa: ARG002
        return np.asarray(ref, dtype=np.float64)


class LogisticProbability:
    family = "logistic"
    kind = "complex"

    def __init__(self, C: float = 1.0, seed: int = 42) -> None:
        self.C = C
        self.pipe = Pipeline([
            ("slog", FunctionTransformer(signed_log, validate=False)),
            ("impute", SimpleImputer(strategy="median", add_indicator=True, keep_empty_features=True)),
            ("scale", StandardScaler()),
            ("lr", LogisticRegression(C=C, max_iter=400, solver="lbfgs", random_state=seed)),
        ])

    def fit(self, X: np.ndarray, y: np.ndarray) -> LogisticProbability:
        self.pipe.fit(np.clip(X, -1e9, 1e9), y)
        return self

    def predict_raw(self, X: np.ndarray, ref=None) -> np.ndarray:  # noqa: ARG002
        return self.pipe.predict_proba(np.clip(X, -1e9, 1e9))[:, 1]


class LGBMProbability:
    family = "lgbm_classifier"
    kind = "complex"

    def __init__(self, params: dict[str, Any], seed: int = 42, n_jobs: int = 1) -> None:
        self.params = dict(params)
        self.model = lgb.LGBMClassifier(
            objective="binary", subsample=0.8, subsample_freq=1, colsample_bytree=0.8, reg_lambda=1.0,
            random_state=seed, n_jobs=n_jobs, deterministic=True, force_row_wise=True, verbose=-1, **self.params)

    def fit(self, X: np.ndarray, y: np.ndarray) -> LGBMProbability:
        self.model.fit(X, y)
        return self

    def predict_raw(self, X: np.ndarray, ref=None) -> np.ndarray:  # noqa: ARG002
        return self.model.predict_proba(X)[:, 1]


LGBM_GRIDS: dict[str, dict[str, Any]] = {
    "lgbm_small": {"n_estimators": 200, "learning_rate": 0.05, "num_leaves": 15, "min_child_samples": 100},
    "lgbm_large": {"n_estimators": 300, "learning_rate": 0.05, "num_leaves": 31, "min_child_samples": 50},
}


def fit_probability_candidates(X: np.ndarray, y: np.ndarray, seed: int, n_jobs: int) -> dict[str, Any]:
    """Ajusta los candidatos complejos con el bloque de entrenamiento. La referencia no se ajusta."""
    out: dict[str, Any] = {}
    out["logistic"] = LogisticProbability(C=1.0, seed=seed).fit(X, y)
    for name, params in LGBM_GRIDS.items():
        out[name] = LGBMProbability(params, seed=seed, n_jobs=n_jobs).fit(X, y)
    return out


# ───────────────────────────── cantidad condicionada ─────────────────────────────

class ReferenceQuantity:
    """Referencia histórica: cantidad media por compra × días de compra esperados dada ≥ 1 compra (`ref_q`)."""

    objective = "reference"
    kind = "reference"

    def fit(self, X, y) -> ReferenceQuantity:  # noqa: ARG002
        return self

    def predict(self, X: np.ndarray, ref: np.ndarray) -> np.ndarray:  # noqa: ARG002
        return np.maximum(np.asarray(ref, dtype=np.float64), 1e-3)


class LGBMQuantity:
    kind = "complex"

    def __init__(self, objective: str, seed: int = 42, n_jobs: int = 1) -> None:
        self.objective = objective
        self.smear = 1.0
        base = dict(n_estimators=200, learning_rate=0.05, num_leaves=15, min_child_samples=20, subsample=0.8, subsample_freq=1,
                    colsample_bytree=0.8, reg_lambda=1.0, random_state=seed, n_jobs=n_jobs, deterministic=True,
                    force_row_wise=True, verbose=-1)
        if objective == "l2":
            base["objective"] = "regression"
        elif objective == "l2_log1p":
            base["objective"] = "regression"
        elif objective == "tweedie":
            base.update(objective="tweedie", tweedie_variance_power=1.5)
        elif objective in ("gamma", "poisson"):
            base["objective"] = objective
        else:
            raise ValueError(objective)
        self.model = lgb.LGBMRegressor(**base)

    def fit(self, X: np.ndarray, y: np.ndarray) -> LGBMQuantity:
        if self.objective == "l2_log1p":
            ly = np.log1p(y)
            self.model.fit(X, ly)
            resid = ly - self.model.predict(X)
            self.smear = float(np.mean(np.exp(resid)))  # Duan: retransformación sin sesgo multiplicativo
        else:
            self.model.fit(X, y)
        return self

    def predict(self, X: np.ndarray, ref=None) -> np.ndarray:  # noqa: ARG002
        raw = self.model.predict(X)
        if self.objective == "l2_log1p":
            raw = np.exp(raw) * self.smear - 1.0
        return np.maximum(np.asarray(raw, dtype=np.float64), 1e-3)


def quantity_objectives(y: np.ndarray) -> list[str]:
    """Objetivos candidatos: Poisson solo con cantidades enteras (no se asume para unidades fraccionarias)."""
    objectives = ["l2", "l2_log1p", "tweedie", "gamma"]
    if len(y) and np.all(np.abs(y - np.round(y)) < 1e-9):
        objectives.append("poisson")
    return objectives


def fit_quantity_candidates(X: np.ndarray, y: np.ndarray, seed: int, n_jobs: int) -> dict[str, Any]:
    return {obj: LGBMQuantity(obj, seed=seed, n_jobs=n_jobs).fit(X, y) for obj in quantity_objectives(y)}


def ratio_scale(y: np.ndarray, pred: np.ndarray, lo: float = 0.5, hi: float = 2.0) -> float:
    """Factor multiplicativo Σreal/Σpredicho del bloque de calibración (acotado)."""
    s = float(np.sum(pred))
    return float(np.clip(np.sum(y) / s, lo, hi)) if s > 0 else 1.0


def ratio_interval(y: np.ndarray, pred: np.ndarray, level: float) -> tuple[float, float]:
    """Cuantiles de log(real/predicho) del bloque de calibración para un intervalo conformal multiplicativo."""
    r = np.log(np.maximum(y, 1e-3) / np.maximum(pred, 1e-3))
    a = (1.0 - level) / 2.0
    return float(np.quantile(r, a)), float(np.quantile(r, 1.0 - a))
