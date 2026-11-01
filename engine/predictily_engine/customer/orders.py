"""Número esperado de pedidos E(N_H) por comprador × producto y horizonte (V2.5 §7, §25.2; ADR-V25-11): modelo HURDLE coherente por construcción.

Definiciones (docs/ml/CUSTOMER_REPURCHASE.md §3):
- Pedido = día de compra de la relación (todas las líneas del mismo día son UN pedido; dos documentos el mismo día no se cuentan por separado:
  limitación declarada). N_H = nº de pedidos en (corte, corte + H].
- **E(N_H) = P(N_H ≥ 1) × E(N_H | N_H ≥ 1).** El segundo factor lo estima un modelo de conteo PROPIO ajustado SOLO con los casos positivos (relaciones con
  ≥ 1 pedido en la ventana): 1 + Poisson sobre los pedidos adicionales (N − 1) con LightGBM, o la referencia de tasa (media de la Poisson truncada en
  cero con λ = tasa × H). No se deriva de P: P solo compone el hurdle. Como E(N | N ≥ 1) > 1 por construcción, E(N) ≥ P sin cotas filas a fila.
  (Interpretación del arquitecto de ADR-V25-11: el conteo condicionado es un modelo propio; P solo compone el hurdle.)
- Única corrección de coherencia que queda: las ventanas están anidadas ((c, c+7] ⊂ (c, c+30] ⊂ …), así que E(N_H) no puede decrecer con H. Se aplica el
  máximo acumulado sobre el E(N) FINAL y se MIDE (fracción de filas y masa corregidas); la promoción y el gate de sesgo se evalúan sobre ese E(N) servido.
- La dispersión de los pedidos adicionales (varianza = μ + α·μ², método de momentos sobre los positivos del bloque de calibración) se informa; la media servida es μ.

La referencia es deliberadamente simple: tasa de compra de los últimos 365 días (o de la historia disponible, con un mínimo de 30 días de exposición) × H, convertida
a la media condicionada con la Poisson truncada en cero. Un modelo complejo solo se sirve si baja la desvianza de Poisson del E(N) FINAL frente a la referencia en el
holdout y su sesgo agregado de pedidos cumple `orders_max_abs_bias` (regla versionada; el holdout no ajusta nada).
"""

from __future__ import annotations

from typing import Any

import lightgbm as lgb
import numpy as np

from predictily_engine.customer.models import ratio_scale

ORDERS_RATE_FLOOR = 0.5 / 365.0      # pedidos/día: piso de la referencia (evita desvianza infinita)
ORDERS_MIN_EXPOSURE_DAYS = 30.0      # exposición mínima de la tasa de la referencia (una relación de 5 días no extrapola 1/6 por día)
ORDERS_MU_FLOOR = 1e-4
EXCESS_FLOOR = 1e-4                  # E(N | N ≥ 1) = 1 + exceso, con exceso ≥ este piso: nunca exactamente 1
ORDERS_FORM = "hurdle-v1"            # forma del artefacto de pedidos; un artefacto sin ella no se usa
MIN_POSITIVES_TRAIN = 50
MIN_POSITIVES_CALIBRATION = 10


def reference_orders(rel_n_365, rel_tenure_days, horizon: int) -> np.ndarray:
    """Referencia de tasa λ_H: pedidos de los últimos 365 días / exposición (acotada a [30, 365] días) × H, con piso."""
    n = np.nan_to_num(np.asarray(rel_n_365, dtype=np.float64), nan=0.0)
    tenure = np.nan_to_num(np.asarray(rel_tenure_days, dtype=np.float64), nan=0.0)
    exposure = np.clip(tenure + 1.0, ORDERS_MIN_EXPOSURE_DAYS, 365.0)
    return np.maximum(n / exposure, ORDERS_RATE_FLOOR) * float(horizon)


def ztp_mean(lam) -> np.ndarray:
    """E(N | N ≥ 1) de una Poisson de media λ truncada en cero: λ / (1 − e^{−λ}) (≥ 1; tiende a 1 cuando λ → 0)."""
    lam = np.maximum(np.asarray(lam, dtype=np.float64), 1e-9)
    return lam / -np.expm1(-lam)


class ReferenceConditionalOrders:
    """Referencia sin ajuste: media de la Poisson truncada en cero con la tasa histórica × H (`ref` = λ_H)."""

    family = "reference"
    kind = "reference"

    def fit(self, X, n, sample_weight=None) -> ReferenceConditionalOrders:  # noqa: ARG002
        return self

    def predict(self, X, ref: np.ndarray) -> np.ndarray:  # noqa: ARG002
        return ztp_mean(ref)


class LGBMConditionalOrders:
    """E(N | N ≥ 1) = 1 + μ, con μ = Poisson (log-enlace, LightGBM) sobre los pedidos ADICIONALES (N − 1) de las ventanas con compra."""

    family = "lgbm_poisson_shifted"
    kind = "complex"

    def __init__(self, seed: int = 42, n_jobs: int = 1) -> None:
        self.model = lgb.LGBMRegressor(
            objective="poisson", n_estimators=200, learning_rate=0.05, num_leaves=15, min_child_samples=40, subsample=0.8,
            subsample_freq=1, colsample_bytree=0.8, reg_lambda=1.0, random_state=seed, n_jobs=n_jobs, deterministic=True,
            force_row_wise=True, verbose=-1)

    def fit(self, X: np.ndarray, n: np.ndarray, sample_weight: np.ndarray | None = None) -> LGBMConditionalOrders:
        self.model.fit(X, np.maximum(np.asarray(n, dtype=np.float64) - 1.0, 0.0), sample_weight=sample_weight)
        return self

    def predict(self, X: np.ndarray, ref=None) -> np.ndarray:  # noqa: ARG002
        return 1.0 + np.maximum(np.asarray(self.model.predict(X), dtype=np.float64), ORDERS_MU_FLOOR)


def serve_conditional_orders(art: dict[str, Any], X: np.ndarray, ref_lambda: np.ndarray) -> np.ndarray:
    """E(N | N ≥ 1) servido: 1 + escala × (predicción − 1), con el exceso ≥ `EXCESS_FLOOR` (siempre > 1)."""
    pred = art["model"].predict(X, ref_lambda)
    return 1.0 + np.maximum(art["scale"] * (pred - 1.0), EXCESS_FLOOR)


def hurdle_orders(p: np.ndarray, cond: np.ndarray) -> np.ndarray:
    """E(N) = P(N ≥ 1) × E(N | N ≥ 1)."""
    return np.asarray(p, dtype=np.float64) * np.asarray(cond, dtype=np.float64)


def enforce_nested_orders(e: dict[int, np.ndarray]) -> tuple[dict[int, np.ndarray], dict[str, Any]]:
    """Ventanas anidadas: E(N_H) no decreciente en H (máximo acumulado por horizonte creciente). Mide por horizonte la fracción de filas corregidas y la
    masa corregida (Σ aumento / Σ E antes de corregir)."""
    horizons = sorted(e)
    out: dict[int, np.ndarray] = {}
    stats: dict[str, Any] = {"rule": "cumulative_max", "horizons": horizons}
    running = None
    for h in horizons:
        v = np.asarray(e[h], dtype=np.float64)
        fixed = v if running is None else np.maximum(v, running)
        changed = fixed > v + 1e-12
        stats[f"corrected_rate_h{h}"] = float(changed.mean()) if len(v) else 0.0
        stats[f"corrected_mass_share_h{h}"] = float((fixed - v).sum() / v.sum()) if len(v) and v.sum() > 0 else 0.0
        out[h] = fixed
        running = fixed
    return out, stats


def estimate_dispersion(n, mu, max_alpha: float = 10.0) -> float:
    """α de la binomial negativa (Var = μ + α·μ²) por método de momentos: Σ[(n − μ)² − μ] / Σμ², acotado a [0, max_alpha]."""
    n = np.asarray(n, dtype=np.float64)
    mu = np.maximum(np.asarray(mu, dtype=np.float64), ORDERS_MU_FLOOR)
    den = float(np.sum(mu ** 2))
    if not len(n) or den <= 0:
        return 0.0
    return float(np.clip(np.sum((n - mu) ** 2 - mu) / den, 0.0, max_alpha))


def nb_probability_zero(mu, alpha: float) -> np.ndarray:
    """P(N = 0) de la binomial negativa de media μ y dispersión α (α = 0 ⇒ Poisson)."""
    mu = np.maximum(np.asarray(mu, dtype=np.float64), 0.0)
    if alpha <= 1e-9:
        return np.exp(-mu)
    return (1.0 + alpha * mu) ** (-1.0 / alpha)


def poisson_deviance(n, mu) -> float | None:
    """Desvianza media de Poisson: 2·mean[n·ln(n/μ) − (n − μ)] (0·ln 0 = 0)."""
    n = np.asarray(n, dtype=np.float64)
    if not len(n):
        return None
    mu = np.maximum(np.asarray(mu, dtype=np.float64), ORDERS_MU_FLOOR)
    with np.errstate(divide="ignore", invalid="ignore"):
        term = np.where(n > 0, n * np.log(np.where(n > 0, n, 1.0) / mu), 0.0)
    return float(2.0 * np.mean(term - (n - mu)))


def count_metrics(n, mu) -> dict[str, Any]:
    """Métricas del conteo de pedidos: sesgo agregado, MAE, WAPE (si Σ reales > 0), RMSE y desvianza de Poisson."""
    n = np.asarray(n, dtype=np.float64)
    mu = np.asarray(mu, dtype=np.float64)
    k = int(len(n))
    if k == 0:
        return {"n": 0, "sum_actual": 0.0, "sum_predicted": 0.0, "bias": None, "mae": None, "wape": None, "rmse": None,
                "poisson_deviance": None, "mean_actual": None, "mean_predicted": None}
    err = mu - n
    total = float(n.sum())
    return {"n": k, "sum_actual": total, "sum_predicted": float(mu.sum()), "bias": float(err.sum() / total) if total > 0 else None,
            "mae": float(np.mean(np.abs(err))), "wape": float(np.abs(err).sum() / total) if total > 0 else None,
            "rmse": float(np.sqrt(np.mean(err ** 2))), "poisson_deviance": poisson_deviance(n, mu), "mean_actual": float(n.mean()),
            "mean_predicted": float(mu.mean())}


def bias_decomposition(n_hold: np.ndarray, p: np.ndarray, bias: float | None) -> dict[str, Any]:
    """Descompone el sesgo de E(N) = P × E(N | N ≥ 1): (1 + sesgo_E) = (1 + sesgo_P) × (1 + sesgo_condicionado). El hurdle HEREDA el sesgo de P en el holdout
    (deriva temporal entre el bloque de calibración y el holdout); el del condicionado se calcula aparte, sobre los positivos."""
    y = float((np.asarray(n_hold) > 0).sum())
    p_bias = float(np.asarray(p, dtype=np.float64).sum() / y - 1.0) if y > 0 else None
    cond_bias = (((1.0 + bias) / (1.0 + p_bias)) - 1.0) if (bias is not None and p_bias is not None) else None
    return {"probability_bias": p_bias, "conditional_factor_bias": cond_bias,
            "definition": "(1 + sesgo de E(N)) = (1 + sesgo de P: Σ P / Σ y − 1) × (1 + sesgo del factor condicionado, implícito)"}


def conditional_art(model: Any, scale: float, alpha: float, horizon: int | None) -> dict[str, Any]:
    return {"form": ORDERS_FORM, "family": model.family, "kind": model.kind, "model": model, "scale": float(scale), "alpha": float(alpha),
            "horizon": horizon}


def fit_orders_head(X: np.ndarray, n: np.ndarray, y: np.ndarray, ref_lambda: np.ndarray, rows: dict[str, np.ndarray], tier: str, cfg: dict[str, Any],
                    seed: int, n_jobs: int, p_hold: np.ndarray, horizon: int | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
    """Ajusta E(N | N ≥ 1) con los POSITIVOS del bloque de entrenamiento; escala del exceso y dispersión con los positivos de calibración; aplica la regla
    versionada en el holdout (que NO elige ni ajusta nada). La promoción mira el E(N) servido = P × E(N | N ≥ 1) de todas las filas del holdout.

    `p_hold`: probabilidad servida de las filas del holdout. Devuelve (artefacto servido, {"info", "reference_art", "challenger_art"})."""
    cal, hold = rows["calibration"], rows["holdout"]
    max_alpha = float(cfg["orders_dispersion_max"])
    pos = np.nonzero(y == 1)[0]
    rows_pos = {k: np.intersect1d(v, pos) for k, v in rows.items()}
    cal_pos, hold_pos = rows_pos["calibration"], rows_pos["holdout"]

    def scale_for(model: Any) -> float:
        if not len(cal_pos):
            return 1.0
        return ratio_scale(n[cal_pos] - 1.0, model.predict(X[cal_pos], ref_lambda[cal_pos]) - 1.0)

    def alpha_for(model: Any, scale: float) -> float:
        if not len(cal_pos):
            return 0.0
        exc = np.maximum(scale * (model.predict(X[cal_pos], ref_lambda[cal_pos]) - 1.0), EXCESS_FLOOR)
        return estimate_dispersion(n[cal_pos] - 1.0, exc, max_alpha)

    ref_model = ReferenceConditionalOrders()
    ref_scale = scale_for(ref_model)
    art_ref = conditional_art(ref_model, ref_scale, alpha_for(ref_model, ref_scale), horizon)
    cond_ref = serve_conditional_orders(art_ref, X[hold], ref_lambda[hold])
    e_ref = hurdle_orders(p_hold, cond_ref)
    info: dict[str, Any] = {
        "definition": ("N_H = nº de pedidos (días de compra de la relación) en (corte, corte + H]. E(N_H) = P(N_H ≥ 1) × E(N_H | N_H ≥ 1): el segundo factor es "
                       "un modelo de conteo propio ajustado solo con los casos positivos (1 + Poisson sobre los pedidos adicionales); P solo compone el hurdle."),
        "form": ORDERS_FORM,
        "reference": {"scale": ref_scale, "alpha": art_ref["alpha"], "holdout": count_metrics(n[hold], e_ref),
                      "conditional_holdout": count_metrics(n[hold_pos], serve_conditional_orders(art_ref, X[hold_pos], ref_lambda[hold_pos]))},
        "rate_only": {"definition": "tasa × H sin hurdle (solo contexto)", "holdout": count_metrics(n[hold], ref_lambda[hold])},
        "positives": {k: int(len(v)) for k, v in rows_pos.items()}}
    served, challenger = art_ref, None
    gate: dict[str, Any] = {"rule_version": cfg["promotion_rule_version"], "passed": False, "checks": {}}
    tr_pos = rows_pos.get("train", np.zeros(0, dtype=np.int64))
    if tier in ("full", "lean") and len(tr_pos) >= MIN_POSITIVES_TRAIN and len(cal_pos) >= MIN_POSITIVES_CALIBRATION:
        model = LGBMConditionalOrders(seed=seed, n_jobs=n_jobs).fit(X[tr_pos], n[tr_pos])
        scale = scale_for(model)
        art = conditional_art(model, scale, alpha_for(model, scale), horizon)
        challenger = art
        e_ch = hurdle_orders(p_hold, serve_conditional_orders(art, X[hold], ref_lambda[hold]))
        m_ch, m_ref = count_metrics(n[hold], e_ch), info["reference"]["holdout"]
        impr = ((m_ref["poisson_deviance"] - m_ch["poisson_deviance"]) / m_ref["poisson_deviance"] if m_ref["poisson_deviance"] else None)
        bias_ch = abs(m_ch["bias"]) if m_ch["bias"] is not None else None
        bound = float(cfg["orders_max_abs_bias"])
        checks = {
            "deviance_relative_improvement": impr,
            "deviance_improvement_threshold": float(cfg["orders_min_rel_deviance_improvement"]),
            "deviance_improvement_ok": bool(impr is not None and impr >= float(cfg["orders_min_rel_deviance_improvement"])),
            "served_bias": m_ch["bias"], "bias_bound": bound, "bias_ok": bool(bias_ch is not None and bias_ch <= bound),
            "evaluated_on": "E(N) servido = P × E(N | N ≥ 1) de todas las filas del holdout (antes de la corrección entre horizontes, que se evalúa después)",
        }
        gate["checks"], gate["challenger"] = checks, art["family"]
        gate["passed"] = bool(checks["deviance_improvement_ok"] and checks["bias_ok"])
        info["challenger"] = {"family": art["family"], "scale": scale, "alpha": art["alpha"], "holdout": m_ch,
                              "conditional_holdout": count_metrics(n[hold_pos], serve_conditional_orders(art, X[hold_pos], ref_lambda[hold_pos]))}
        if gate["passed"]:
            served = art
    else:
        info["note"] = ("Positivos insuficientes para ajustar el conteo condicionado (o nivel sin entrenamiento): se sirve la referencia de tasa "
                        f"(mínimo {MIN_POSITIVES_TRAIN} de entrenamiento y {MIN_POSITIVES_CALIBRATION} de calibración).")
    info["gate"] = gate
    e_served = hurdle_orders(p_hold, serve_conditional_orders(served, X[hold], ref_lambda[hold]))
    served_metrics = count_metrics(n[hold], e_served)
    info["served"] = {
        "family": served["family"], "role": "primary" if served["kind"] == "complex" else "fallback", "alpha": served["alpha"],
        "holdout": served_metrics, "holdout_pre_nesting": served_metrics,
        "conditional_holdout": count_metrics(n[hold_pos], serve_conditional_orders(served, X[hold_pos], ref_lambda[hold_pos])),
        "bias_within_bound": bool(served_metrics["bias"] is not None and abs(served_metrics["bias"]) <= float(cfg["orders_max_abs_bias"])),
        "bias_decomposition": bias_decomposition(n[hold], np.asarray(p_hold), served_metrics["bias"]),
        "equal_to_probability_share": float((np.abs(e_served - np.asarray(p_hold)) <= 1e-12).mean()) if len(e_served) else 0.0,
        "note": "holdout = E(N) servido (P × E(N | N ≥ 1)); la corrección de anidamiento entre horizontes se mide y se aplica en la finalización."}
    return served, {"info": info, "reference_art": art_ref, "challenger_art": challenger}
