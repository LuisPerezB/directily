"""Customer Core S05 · número esperado de pedidos (modelo de conteo), cantidad por pedido y unidades (V2.5 §25.2, ADR-V25-11).

Datos FICTICIOS generados en la prueba con verdad conocida (Poisson / binomial negativa con parámetros fijos).
"""

from __future__ import annotations

import numpy as np
import pytest

from predictily_engine.customer.orders import (
    EXCESS_FLOOR,
    ORDERS_FORM,
    ReferenceConditionalOrders,
    count_metrics,
    enforce_nested_orders,
    estimate_dispersion,
    fit_orders_head,
    hurdle_orders,
    nb_probability_zero,
    poisson_deviance,
    reference_orders,
    serve_conditional_orders,
    ztp_mean,
)
from predictily_engine.customer.per_order import fit_per_order_head, reference_per_order
from predictily_engine.platform.config import resolve_config

CFG, _, _ = resolve_config("customer", {}, None)


def blocks(n_each: int = 1500) -> tuple[dict[str, np.ndarray], int]:
    """Bloques consecutivos train | calibration | selection | holdout de igual tamaño."""
    names = ("train", "calibration", "selection", "holdout")
    return {n: np.arange(i * n_each, (i + 1) * n_each) for i, n in enumerate(names)}, 4 * n_each


def test_reference_orders_is_a_rate_times_h_with_floors():
    n365 = np.array([12.0, 1.0, 0.0])
    tenure = np.array([365.0, 365.0, 365.0])
    r7, r30 = reference_orders(n365, tenure, 7), reference_orders(n365, tenure, 30)
    assert r7[0] == pytest.approx(12 / 365 * 7) and r30[0] == pytest.approx(12 / 365 * 30)
    assert r30[0] / r7[0] == pytest.approx(30 / 7), "escala lineal con H"
    assert r7[2] > 0, "piso: la referencia nunca es 0 (desvianza finita)"
    young = reference_orders(np.array([1.0]), np.array([4.0]), 30)[0]
    assert young == pytest.approx(30 / 30.0), "una relación de 5 días no extrapola 1/6 por día: exposición mínima de 30 días"


def test_zero_truncated_poisson_mean_is_at_least_one_and_matches_the_closed_form():
    lam = np.array([1e-9, 0.01, 0.5, 2.0, 10.0])
    m = ztp_mean(lam)
    assert (m >= 1.0).all() and m[0] == pytest.approx(1.0, abs=1e-6) and (np.diff(m) > 0).all(), "E(N | N ≥ 1) ≥ 1 y crece con λ"
    assert m[3] == pytest.approx(2.0 / (1.0 - np.exp(-2.0)))
    rng = np.random.default_rng(0)
    draws = rng.poisson(1.3, 400000)
    assert ztp_mean(np.array([1.3]))[0] == pytest.approx(draws[draws > 0].mean(), rel=0.01), "coincide con la media simulada de los positivos"


def test_hurdle_orders_are_coherent_by_construction_without_any_row_level_bound():
    """E(N) = P × E(N | N ≥ 1) con E(N | N ≥ 1) > 1 ⇒ E(N) > P en TODAS las filas, sin subir nada a P."""
    rng = np.random.default_rng(1)
    p = rng.uniform(0.001, 0.999, 5000)
    art = {"form": ORDERS_FORM, "family": "reference", "kind": "reference", "model": ReferenceConditionalOrders(), "scale": 0.5, "alpha": 0.0}
    cond = serve_conditional_orders(art, np.zeros((5000, 1)), rng.uniform(1e-4, 20, 5000))
    assert (cond > 1.0).all() and (cond - 1.0 >= EXCESS_FLOOR - 1e-12).all(), "el exceso nunca baja del piso, aun con escala 0,5"
    e = hurdle_orders(p, cond)
    assert (e > p).all() and (np.abs(e - p) <= 1e-12).sum() == 0, "ninguna fila queda con E(N) = P exacto"


def test_orders_are_non_decreasing_in_the_horizon_and_the_correction_is_measured():
    e = {7: np.array([0.4, 0.9]), 30: np.array([0.3, 2.0]), 90: np.array([1.0, 1.5])}
    fixed, stats = enforce_nested_orders(e)
    assert fixed[30].tolist() == [0.4, 2.0] and fixed[90].tolist() == [1.0, 2.0]
    assert stats["corrected_rate_h30"] == 0.5 and stats["corrected_rate_h7"] == 0.0
    assert stats["corrected_mass_share_h30"] == pytest.approx(0.1 / 2.3) and stats["corrected_mass_share_h90"] == pytest.approx(0.5 / 2.5)


def test_dispersion_recovers_the_negative_binomial_parameter_and_is_zero_for_poisson():
    rng = np.random.default_rng(0)
    mu = np.full(40000, 2.0)
    alpha = 0.6
    shape = 1.0 / alpha
    nb = rng.negative_binomial(shape, shape / (shape + mu))     # media μ, varianza μ + α·μ²
    assert estimate_dispersion(nb, mu) == pytest.approx(alpha, abs=0.06)
    assert estimate_dispersion(rng.poisson(mu), mu) == pytest.approx(0.0, abs=0.06)
    assert estimate_dispersion(np.array([0.0, 1.0]), np.array([0.5, 0.5]), max_alpha=0.0) == 0.0, "acotada por el máximo configurado"
    assert estimate_dispersion(np.array([]), np.array([])) == 0.0


def test_nb_probability_of_zero_matches_the_closed_forms():
    mu = np.array([0.0, 0.5, 3.0])
    assert nb_probability_zero(mu, 0.0) == pytest.approx(np.exp(-mu))
    assert nb_probability_zero(mu, 1.0) == pytest.approx(1.0 / (1.0 + mu)), "α = 1 ⇒ geométrica"
    assert (nb_probability_zero(mu, 0.5) >= nb_probability_zero(mu, 0.0) - 1e-12).all(), "más dispersión ⇒ más probabilidad de cero"


def test_poisson_deviance_is_zero_at_the_truth_and_count_metrics_have_no_fake_ratios():
    n = np.array([0.0, 1.0, 3.0])
    assert poisson_deviance(n, np.array([1e-4, 1.0, 3.0])) < 1e-3
    assert poisson_deviance(n, np.array([2.0, 2.0, 2.0])) > poisson_deviance(n, n.clip(1e-4))
    m = count_metrics(n, np.array([1.0, 1.0, 1.0]))
    assert m["sum_actual"] == 4.0 and m["bias"] == pytest.approx((3.0 - 4.0) / 4.0) and m["wape"] == pytest.approx((1 + 0 + 2) / 4)
    zeros = count_metrics(np.zeros(5), np.full(5, 0.3))
    assert zeros["bias"] is None and zeros["wape"] is None, "Σ reales = 0 ⇒ sin razón ni WAPE (nunca divide por 0)"
    assert count_metrics(np.array([]), np.array([]))["n"] == 0


def hurdle_data(seed: int, n_each: int = 1500, signal: bool = True):
    """Mundo con P y conteo condicionado conocidos: N = 0 con prob. 1 − P y, si ocurre, N = 1 + Poisson(μ_extra)."""
    rng = np.random.default_rng(seed)
    rows, n = blocks(n_each)
    x = rng.normal(size=(n, 3)).astype(np.float32)
    p_true = 1.0 / (1.0 + np.exp(-(-0.4 + 0.5 * x[:, 2])))
    mu_extra = np.exp(-0.2 + (0.9 * x[:, 0] - 0.6 * x[:, 1] if signal else 0.0))
    y = (rng.random(n) < p_true).astype(float)
    counts = np.where(y == 1, 1 + rng.poisson(mu_extra), 0).astype(float)
    return rows, n, x, y, counts, p_true, mu_extra


def test_conditional_count_model_beats_the_rate_reference_when_there_is_signal_and_the_served_bias_is_within_bound():
    rows, n, x, y, counts, p_true, mu_extra = hurdle_data(1)
    ref_lambda = np.full(n, 0.5)                                        # referencia sin información de las variables
    art, pack = fit_orders_head(x, counts, y, ref_lambda, rows, "full", CFG, seed=3, n_jobs=1, p_hold=p_true[rows["holdout"]], horizon=30)
    info = pack["info"]
    assert info["gate"]["passed"] and art["kind"] == "complex" and art["family"] == "lgbm_poisson_shifted" and art["form"] == ORDERS_FORM
    chk = info["gate"]["checks"]
    assert chk["deviance_relative_improvement"] > CFG["orders_min_rel_deviance_improvement"] and chk["bias_ok"]
    assert abs(info["served"]["holdout"]["bias"]) <= CFG["orders_max_abs_bias"] and info["served"]["bias_within_bound"]
    assert info["served"]["holdout"]["poisson_deviance"] < info["reference"]["holdout"]["poisson_deviance"]
    assert info["positives"]["train"] > 100, "se ajusta SOLO con los casos positivos"
    cond = serve_conditional_orders(art, x[rows["holdout"]], ref_lambda[rows["holdout"]])
    assert np.corrcoef(cond, 1 + mu_extra[rows["holdout"]])[0, 1] > 0.85, "el condicionado sigue al proceso verdadero"
    assert info["served"]["equal_to_probability_share"] == 0.0 and (cond > 1.0).all()
    assert abs(info["served"]["conditional_holdout"]["bias"]) < 0.1


def test_the_conditional_count_is_fitted_only_with_positive_cases():
    """Cambiar los pedidos de las filas SIN compra (que no pueden ser > 0) y el conjunto de ceros no altera el ajuste: el modelo solo ve positivos."""
    rows, n, x, y, counts, p_true, _ = hurdle_data(2)
    ref = np.full(n, 0.5)
    a1, _ = fit_orders_head(x, counts, y, ref, rows, "full", CFG, seed=3, n_jobs=1, p_hold=p_true[rows["holdout"]])
    x2 = x.copy()
    x2[y == 0] = np.random.default_rng(9).normal(size=(int((y == 0).sum()), 3)).astype(np.float32)      # otras variables en las filas sin compra
    a2, _ = fit_orders_head(x2, counts, y, ref, rows, "full", CFG, seed=3, n_jobs=1, p_hold=p_true[rows["holdout"]])
    probe = x[y == 1][:200]
    assert np.allclose(serve_conditional_orders(a1, probe, np.full(200, 0.5)), serve_conditional_orders(a2, probe, np.full(200, 0.5)))


def test_count_model_is_not_promoted_without_signal_the_reference_is_served():
    rows, n, x, y, counts, p_true, _ = hurdle_data(3, signal=False)
    ref = np.full(n, 0.7)
    art, pack = fit_orders_head(x, counts, y, ref, rows, "full", CFG, seed=3, n_jobs=1, p_hold=p_true[rows["holdout"]], horizon=7)
    assert not pack["info"]["gate"]["passed"] and art["kind"] == "reference" and pack["info"]["served"]["role"] == "fallback"
    assert pack["info"]["challenger"]["family"] == "lgbm_poisson_shifted", "el retador se evaluó y se informa aunque no se sirva"


def test_a_complex_count_model_whose_final_bias_exceeds_the_bound_is_not_promoted_even_if_it_improves_the_deviance():
    rows, n, x, y, counts, p_true, _ = hurdle_data(4)
    strict = {**CFG, "orders_max_abs_bias": 0.0}
    art, pack = fit_orders_head(x, counts, y, np.full(n, 0.5), rows, "full", strict, seed=3, n_jobs=1, p_hold=p_true[rows["holdout"]])
    chk = pack["info"]["gate"]["checks"]
    assert chk["deviance_improvement_ok"] is True and chk["bias_ok"] is False
    assert art["kind"] == "reference" and not pack["info"]["gate"]["passed"], "sesgo servido fuera del tope ⇒ no se promueve"


def test_reference_only_tier_never_fits_a_complex_count_model():
    rng = np.random.default_rng(4)
    rows = {"calibration": np.arange(0, 1000), "holdout": np.arange(1000, 2000)}
    x = rng.normal(size=(2000, 2)).astype(np.float32)
    y = (rng.random(2000) < 0.3).astype(float)
    counts = np.where(y == 1, 1 + rng.poisson(0.3, 2000), 0).astype(float)
    art, pack = fit_orders_head(x, counts, y, np.full(2000, 0.5), rows, "reference_only", CFG, seed=1, n_jobs=1, p_hold=np.full(1000, 0.3))
    assert art["kind"] == "reference" and "challenger" not in pack["info"] and isinstance(art["model"], ReferenceConditionalOrders)
    assert "Positivos insuficientes" in pack["info"]["note"]


def test_per_order_quantity_weights_by_orders_and_serves_the_reference_when_it_does_not_improve():
    rng = np.random.default_rng(5)
    rows, n = blocks(1200)
    x = rng.normal(size=(n, 3)).astype(np.float32)
    true_q = np.exp(1.2 + 0.5 * x[:, 0])
    n_orders = rng.integers(1, 4, size=n).astype(float)
    target = true_q * rng.lognormal(0, 0.15, size=n)
    ref = reference_per_order(np.full(n, np.nan), np.full(n, np.nan))     # sin referencia histórica: mediana global (=1.0 por defecto)
    assert np.all(ref == 1.0)
    ref = np.full(n, float(np.mean(target)))
    art, pack = fit_per_order_head(x, target, n_orders, ref, rows, "full", CFG, seed=2, n_jobs=1, horizon=30)
    info = pack["info"]
    assert info["served"]["objective"] != "reference" and info["gate"]["passed"], "con señal, el modelo supera a la referencia"
    assert info["served"]["holdout"]["mae"] < info["reference"]["holdout"]["mae"]
    cov = info["served"]["interval"]["coverage"]
    assert 0.65 <= cov <= 0.95, "intervalo conformal del 80 % con cobertura cercana a la nominal en el holdout"
    # sin señal gana la referencia
    flat = rng.normal(5.0, 0.3, size=n)
    art2, pack2 = fit_per_order_head(x, flat, n_orders, np.full(n, 5.0), rows, "full", CFG, seed=2, n_jobs=1, horizon=30)
    assert art2["kind"] == "reference" and not pack2["info"]["gate"]["passed"]
    # nivel «lean»: sin bloque de selección, el holdout sigue intacto
    lean_rows = {"train": rows["train"], "calibration": rows["calibration"], "holdout": rows["holdout"]}
    _, pack3 = fit_per_order_head(x, target, n_orders, ref, lean_rows, "lean", CFG, seed=2, n_jobs=1)
    assert "calibración" in pack3["info"]["selection_rule"]
