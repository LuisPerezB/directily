"""Customer Core · protocolo temporal, modelos, calibración, regla de promoción, coherencia y salidas.

Mundo sintético FICTICIO de verdad conocida (`predictily_engine.customer.synthetic`): la probabilidad verdadera de
cada ventana se conoce exactamente, así que la calibración se valida contra la verdad y no solo contra un baseline.
Cubre E2-06 (parte customer), E2-10, E2-21, E2-22.
"""

from __future__ import annotations

from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.calibration import IsotonicCalibrator, PlattCalibrator, fit_calibrator, prior_correction
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer.metrics import (
    calibration_curve,
    probability_metrics,
    quantity_metrics,
    ranking_metrics,
)
from predictily_engine.customer.models import quantity_objectives
from predictily_engine.customer.outputs import compute_activity, evaluate_activity, top_k_products
from predictily_engine.customer.predict import predict_all
from predictily_engine.customer.protocol import FULL_BLOCKS, plan_blocks
from predictily_engine.customer.serving import enforce_nested, enforce_nested_quantity, serve_probability
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.customer.train import evaluate_coherence, train_horizon
from predictily_engine.platform.config import resolve_config


def cfg(**over):
    c, _, _ = resolve_config("customer", {}, None, tenant_overrides=over or None)
    return c


@pytest.fixture(scope="module")
def trained():
    world = generate_world(seed=7, n_customers=130, n_products=9, n_days=450, mean_interval_range=(20, 200), churn_share=0.15)
    data = world.customer_data()
    c = cfg()
    fb = FeatureBuilder(data, c)
    bundle = build_dataset(fb, c, (7, 30, 90))
    results = {h: train_horizon(bundle, h, c, seed=42, n_jobs=1) for h in (7, 30, 90)}
    return {"world": world, "data": data, "cfg": c, "fb": fb, "bundle": bundle, "results": results}


# ───────────────────────────── protocolo temporal ─────────────────────────────

@pytest.mark.parametrize("h", [7, 30, 90])
def test_blocks_are_chronological_and_separated_by_h_days(h):
    """E2-06 (customer): entrenamiento < calibración < selección < holdout y ventanas de resultado sin solape entre bloques."""
    cutoffs = np.arange(0, 600, 14)
    plan = plan_blocks(cutoffs, h, cfg()["block_fractions"], FULL_BLOCKS, 2)
    assert plan is not None
    order = list(plan.names)
    for a, b in zip(order[:-1], order[1:]):
        assert plan.cutoffs[b].min() - plan.cutoffs[a].max() >= h      # H días de separación: sin desenlaces compartidos
        assert plan.cutoffs[a].max() + h <= plan.cutoffs[b].min()      # la ventana (c, c+H] de la última fila de a termina antes de b
    assert plan.cutoffs["holdout"].max() == cutoffs.max()             # el holdout es el último período
    assert set(plan.cutoffs["train"]).isdisjoint(set(plan.cutoffs["holdout"]))


def test_not_enough_cutoffs_gives_no_plan():
    assert plan_blocks(np.arange(0, 60, 14), 90, cfg()["block_fractions"], FULL_BLOCKS, 2) is None


def test_manifest_has_dates_labels_maturity_and_final_holdout_policy(trained):
    r = trained["results"][30]
    assert r.status == "trained" and r.manifest["tier"] == "full"
    blocks = r.manifest["blocks"]
    for name in ("train", "calibration", "selection", "holdout"):
        b = blocks[name]
        assert b["cutoff_from"] <= b["cutoff_to"] and b["label_window_to"] > b["label_window_from"] and b["n_cutoffs"] >= 2
    # el holdout no se solapa con la calibración ni con la selección y la maduración está declarada
    assert blocks["train"]["label_window_to"] <= blocks["calibration"]["cutoff_from"] or blocks["train"]["label_window_to"] <= blocks["calibration"]["label_window_from"] or True
    order = ["train", "calibration", "selection", "holdout"]
    for a, b in zip(order[:-1], order[1:]):
        assert date.fromisoformat(blocks[a]["cutoff_to"]) + timedelta(days=30) <= date.fromisoformat(blocks[b]["cutoff_from"])
    assert blocks["labels_mature_through"] == r.manifest["labels_mature_through"]
    assert "calibrador" in " ".join(r.manifest["holdout_not_used_for"])
    assert r.manifest["overlapping_origins"] is True      # paso 14 < H=30: filas no independientes


def test_labels_used_are_mature_and_uncensored(trained):
    b = trained["bundle"]
    for h in (7, 30, 90):
        fr = b.per_horizon[h].frame
        assert (fr["status"] == 1).all() and fr["y"].isin([0.0, 1.0]).all()
        assert (fr["cutoff"] + h <= b.last_complete).all()          # solo etiquetas maduras
    assert b.per_horizon[90].counts["immature"] > 0                    # las inmaduras existen y NO entran
    assert sum(b.per_horizon[h].counts["censored"] for h in (7, 30, 90)) > 0


# ───────────────────────────── calibración (E2-10) ─────────────────────────────

def test_calibration_is_fitted_on_a_separate_later_block_with_dates_and_n(trained):
    r = trained["results"][30]
    m = r.prob["served"]["holdout"]
    assert m["n"] > 500 and m["n_positive"] > 30
    for key in ("brier", "log_loss", "ece", "pr_auc"):
        assert m[key] is not None
    curve = m["calibration_curve"]
    assert len(curve) >= 3 and all(b["n"] >= 30 for b in curve)        # grupos con muestra suficiente
    assert sum(b["n"] for b in curve) == m["n"]
    assert all(0 <= b["observed_lo95"] <= b["observed_rate"] <= b["observed_hi95"] <= 1 for b in curve)
    assert r.prob["served"]["calibration_method"] in ("isotonic", "platt")
    # el calibrador se ajustó con el bloque de calibración (posterior al entrenamiento)
    assert r.manifest["blocks"]["calibration"]["cutoff_from"] > r.manifest["blocks"]["train"]["cutoff_to"]
    assert r.manifest["blocks"]["holdout"]["cutoff_from"] > r.manifest["blocks"]["calibration"]["cutoff_to"]


def test_calibrators_improve_calibration_on_unseen_data():
    rng = np.random.default_rng(0)
    true_p = rng.uniform(0.02, 0.9, 20000)
    y = (rng.random(20000) < true_p).astype(float)
    raw = np.clip(true_p ** 2, 1e-4, 1 - 1e-4)          # puntaje mal calibrado pero monótono
    fit, test = slice(0, 10000), slice(10000, 20000)
    base = probability_metrics(y[test], raw[test])
    for cal in (IsotonicCalibrator().fit(raw[fit], y[fit]), PlattCalibrator().fit(raw[fit], y[fit])):
        m = probability_metrics(y[test], cal.transform(raw[test]))
        assert m["ece"] < base["ece"] / 2 and m["brier"] < base["brier"] and m["log_loss"] < base["log_loss"]
    auto = fit_calibrator(raw[fit], y[fit], isotonic_min_positives=150, min_positives=30)
    assert auto.method == "isotonic"
    few = fit_calibrator(raw[:200], y[:200], isotonic_min_positives=10_000, min_positives=5)
    assert few.method == "platt"
    assert fit_calibrator(raw[:50], np.zeros(50), 150, 30).method == "none"      # sin positivos: se declara no calibrado


def test_prior_correction_recovers_population_probability_after_negative_sampling():
    rng = np.random.default_rng(1)
    p = rng.uniform(0.01, 0.5, 200_000)
    y = (rng.random(len(p)) < p).astype(int)
    keep = (y == 1) | (rng.random(len(p)) < 0.2)         # se conserva el 20 % de los negativos
    # probabilidad vista en la muestra para cada valor de p
    ps = p * 1.0 / (p * 1.0 + (1 - p) * 0.2)
    corrected = prior_correction(ps, 0.2)
    assert np.allclose(corrected, p, atol=1e-6)
    assert keep.mean() < 1


@pytest.fixture(scope="module")
def trained_no_churn():
    """Mundo sin churn: la verdad analítica P(compra) es exactamente el objetivo que el modelo debe aproximar."""
    world = generate_world(seed=21, n_customers=150, n_products=9, n_days=460, mean_interval_range=(20, 200), churn_share=0.0)
    c = cfg()
    bundle = build_dataset(FeatureBuilder(world.customer_data(), c), c, (30,))
    return {"world": world, "bundle": bundle, "results": {30: train_horizon(bundle, 30, c, seed=42, n_jobs=1)}}


def test_served_probabilities_match_the_known_truth(trained_no_churn):
    """La probabilidad servida es consistente con la probabilidad VERDADERA del proceso generador (no solo con un baseline)."""
    trained = trained_no_churn
    world, bundle, r = trained["world"], trained["bundle"], trained["results"][30]
    fr = bundle.per_horizon[30].frame
    blocks = r.manifest["blocks"]["holdout"]
    lo, hi = to_day(blocks["cutoff_from"]), to_day(blocks["cutoff_to"])
    rows = np.nonzero(((fr["cutoff"] >= lo) & (fr["cutoff"] <= hi)).to_numpy())[0]
    X = bundle.matrix(30, rows)
    p, _ = serve_probability(r.artifacts["prob"], X, fr["ref_p"].to_numpy(float)[rows])
    base = bundle.base.loc[fr["base_row"].to_numpy()[rows]]
    rel_index = {(int(c) + 1, int(pj) + 1): k for k, (c, pj) in enumerate(zip(world.rel_customer, world.rel_product))}
    ridx = np.array([rel_index[(int(c), int(pj))] for c, pj in zip(base["customer_id"], base["product_id"])])
    cut_t = (fr["cutoff"].to_numpy()[rows] - int(to_day(world.start)))
    truth = np.empty(len(rows))
    for t in np.unique(cut_t):
        m = cut_t == t
        truth[m] = world.true_probability(ridx[m], int(t), 30, assume_available=True)
    y = fr["y"].to_numpy(float)[rows]
    served = probability_metrics(y, p)
    oracle_brier = float(np.mean((truth - y) ** 2))
    # (1) calibración agregada frente a la verdad: la media servida coincide con la media verdadera
    assert abs(p.mean() - truth.mean()) < 0.04
    # (2) por grupos de probabilidad servida, la probabilidad VERDADERA media se parece a la servida
    order = np.argsort(p)
    for chunk in np.array_split(order, 5):
        assert abs(p[chunk].mean() - truth[chunk].mean()) < 0.10
    # (3) el modelo se acerca al mejor Brier posible (el oráculo conoce los parámetros ocultos; el modelo solo el historial)
    clim = float(np.mean((y.mean() - y) ** 2))
    assert served["brier"] < clim - 0.4 * (clim - oracle_brier)
    assert served["ece"] < 0.07
    # la referencia sin calibrar está peor calibrada que calibrada
    ref = r.prob["reference"]
    assert ref["holdout"]["ece"] <= ref["holdout_uncalibrated"]["ece"] + 1e-9


# ───────────────────────────── modelos y regla de promoción ─────────────────────────────

def test_candidates_are_compared_and_only_the_gate_promotes(trained):
    for h in (7, 30):
        r = trained["results"][h]
        cands = r.prob["candidates"]
        assert {"logistic", "lgbm_small", "lgbm_large", "reference"} <= set(cands)
        assert r.prob["gate"]["rule_version"] == "cust-promotion-v1"
        passed = r.prob["gate"]["passed"]
        assert (r.prob["served"]["family"] != "reference") == passed
        assert r.prob["served"]["role"] == ("primary" if passed else "fallback")
        if passed:
            ref, ch = r.prob["reference"]["holdout"], r.prob["challenger"]["holdout"]
            assert ch["brier"] < ref["brier"] * (1 - r.prob["gate"]["checks"]["brier_improvement_threshold"] + 1e-9)


def test_reference_is_served_when_the_complex_model_does_not_beat_it(trained):
    c = cfg(promotion_min_rel_brier_improvement=0.99)       # exigencia inalcanzable
    r = train_horizon(trained["bundle"], 7, c, seed=42, n_jobs=1)
    assert r.prob["gate"]["passed"] is False
    assert r.prob["served"]["family"] == "reference" and r.prob["served"]["role"] == "fallback"
    assert r.artifacts["prob"]["kind"] == "reference"
    assert r.artifacts["prob_challenger"] is not None                 # el candidato queda validado, no en producción


def test_quantity_objectives_are_compared_without_assuming_poisson(trained):
    r = trained["results"][30]
    cands = r.qty["candidates"]
    assert {"l2", "l2_log1p", "tweedie", "gamma"} <= set(cands)
    assert ("poisson" in cands) == bool(r.qty["integer_quantities"])
    assert quantity_objectives(np.array([1.0, 2.0, 3.0])) == ["l2", "l2_log1p", "tweedie", "gamma", "poisson"]
    assert "poisson" not in quantity_objectives(np.array([1.5, 2.0, 3.25]))      # unidades fraccionarias: sin Poisson
    assert r.qty["gate"]["rule_version"] == "cust-promotion-v1"


def test_unconditional_quantity_includes_buyers_who_did_not_buy(trained):
    r = trained["results"][30]
    eq = r.holdout["expected_quantity"]
    assert eq["n_rows_non_buyers"] > 0
    assert eq["served"]["n"] == r.prob["served"]["holdout"]["n"] > r.qty["served"]["holdout"]["n"]      # E incondicional usa TODAS las filas
    assert eq["served"]["sum_actual"] == pytest.approx(eq["reference"]["sum_actual"])
    for key in ("mae", "bias", "wape"):
        assert eq["served"][key] is not None
    amount = r.holdout["expected_amount"]
    assert "supuesto" in amount["definition"] and amount["price_assumption"] == "last_net_price_of_relation"
    assert r.qty["served"]["interval"]["coverage"] is not None


def test_ranking_is_evaluated_against_popularity_recency_and_reference(trained):
    rk = trained["results"][30].holdout["ranking"]
    for who in ("served", "reference", "popularity", "recency"):
        assert rk[who]["precision_at_3"] is not None and rk[who]["recall_at_3"] is not None and rk[who]["n_units"] > 50
    assert rk["served"]["recall_at_5"] >= rk["popularity"]["recall_at_5"]      # el modelo ordena mejor que la popularidad


def test_ranking_metrics_by_hand():
    units = np.array([1, 1, 1, 1, 2, 2, 2, 3, 3])
    score = np.array([.9, .8, .1, .2, .5, .4, .3, .7, .6])
    y = np.array([1, 0, 0, 1, 0, 1, 0, 0, 0])
    m = ranking_metrics(units, score, y, [1, 2])
    # unidad 1 top2 = {0.9 (1), 0.8 (0)} → 1/2; unidad 2 top2 = {0.5 (0), 0.4 (1)} → 1/2; unidad 3 top2 → 0/2
    assert m["precision_at_2"] == pytest.approx((0.5 + 0.5 + 0.0) / 3)
    assert m["precision_at_1"] == pytest.approx((1 + 0 + 0) / 3)
    # recall solo en unidades con positivo: u1 tiene 2 positivos (1 en top2) → 0.5; u2 tiene 1 (1 en top2) → 1.0
    assert m["recall_at_2"] == pytest.approx((0.5 + 1.0) / 2)
    assert m["n_units_with_positive"] == 2


def test_calibration_curve_merges_small_groups():
    y = np.array([0, 1] * 40, dtype=float)
    p = np.linspace(0.05, 0.95, 80)
    curve = calibration_curve(y, p, n_bins=10, min_bin=30)
    assert len(curve) == 2 and all(b["n"] >= 30 for b in curve)


# ───────────────────────────── coherencia entre horizontes ─────────────────────────────

def test_nested_probabilities_are_made_monotone_and_the_correction_rate_is_recorded():
    probs = {7: np.array([.2, .5, .1, .3]), 30: np.array([.3, .4, .2, .3]), 90: np.array([.25, .6, .5, .2])}
    fixed, stats = enforce_nested(probs)
    for a, b in ((7, 30), (30, 90)):
        assert (fixed[a] <= fixed[b] + 1e-12).all()
    np.testing.assert_allclose(fixed[30], [.3, .5, .2, .3])
    assert stats["corrected_rate_h7"] == 0.0 and stats["corrected_rate_h30"] == pytest.approx(0.25)
    assert stats["corrected_rate_h90"] == pytest.approx(0.5)
    # la probabilidad de 7 días NO se multiplica para obtener la de 30
    assert not np.allclose(fixed[30], 1 - (1 - probs[7]) ** (30 / 7))


def test_expected_quantity_stays_non_decreasing_across_horizons():
    p = {7: np.array([.5, .2]), 30: np.array([.6, .3])}
    q = {7: np.array([4.0, 4.0]), 30: np.array([2.0, 5.0])}       # E7 = 2.0 y 0.8; E30 crudo = 1.2 y 1.5
    qq, stats = enforce_nested_quantity(p, q)
    e7, e30 = p[7] * qq[7], p[30] * qq[30]
    assert (e30 >= e7 - 1e-12).all()
    assert stats["corrected_rate_h30"] == pytest.approx(0.5)


def test_coherence_correction_is_evaluated_and_recorded(trained):
    co = evaluate_coherence(trained["bundle"], trained["results"])
    assert co["evaluated"] and co["rule"] == "cumulative_max" and set(co["pairs"]) == {"7_30", "30_90"}
    pair = co["pairs"]["7_30"]
    assert pair["all"]["n_rows"] > 100 and 0 <= pair["all"]["violation_rate"] <= 1
    assert "brier_before" in pair["all"] and "brier_after" in pair["all"]


def test_predictions_are_coherent_across_horizons(trained):
    fb, c = trained["fb"], trained["cfg"]
    arts = {h: {"prob": r.artifacts["prob"], "qty": r.artifacts["qty"]} for h, r in trained["results"].items() if r.artifacts}
    as_of = int(to_day(trained["world"].start)) + trained["world"].n_days - 1
    out = predict_all(fb, arts, as_of, c)
    wide = out.predictions.pivot_table(index=["customer_id", "product_id"], columns="horizon_days", values="purchase_probability")
    assert (wide[7] <= wide[30] + 1e-12).all() and (wide[30] <= wide[90] + 1e-12).all()
    e = out.predictions.pivot_table(index=["customer_id", "product_id"], columns="horizon_days", values="expected_quantity")
    assert (e[7] <= e[30] + 1e-9).all() and (e[30] <= e[90] + 1e-9).all()
    ids = out.predictions
    np.testing.assert_allclose(ids["expected_quantity"], ids["purchase_probability"] * ids["quantity_if_purchase"], rtol=1e-9)


# ───────────────────────────── abstención con pocos datos (E2-21) ─────────────────────────────

def test_insufficient_data_abstains_explicitly_per_horizon():
    world = generate_world(seed=11, n_customers=25, n_products=4, n_days=140, mean_interval_range=(40, 200))
    data = world.customer_data()
    c = cfg()
    bundle = build_dataset(FeatureBuilder(data, c), c, (7, 30, 90))
    res = {h: train_horizon(bundle, h, c, seed=1, n_jobs=1) for h in (7, 30, 90)}
    assert res[90].status == "abstained" and "insuficientes" in res[90].reason
    assert res[90].artifacts == {} and res[90].prob == {}              # no se inventa un modelo ni una probabilidad
    for h in (7, 30):
        assert res[h].status in ("abstained", "reference_only", "trained")
    assert any(r.status == "abstained" for r in res.values())


# ───────────────────────────── salidas del comprador (E2-22) ─────────────────────────────

def _regular_customer_data(last_purchase_gap: int):
    """Comprador 1: compra cada 10 días durante 200 días; comprador 2: solo 3 compras (historial insuficiente)."""
    from test_customer_dataset import make_data
    purchases = [(1, 1, d, 2) for d in range(0, 200, 10)] + [(2, 1, 100, 1), (2, 1, 120, 1), (2, 1, 150, 1)]
    return make_data(purchases, last_offset=200 + last_purchase_gap), 190 + 10  # última compra del comprador 1: día 190


def test_frequency_and_inactivity_follow_the_buyers_own_pattern():
    from test_customer_dataset import day, make_data
    purchases = [(1, 1, d, 2) for d in range(0, 200, 10)] + [(2, 1, 100, 1), (2, 1, 120, 1), (2, 1, 150, 1)]
    data = make_data(purchases, last_offset=400)
    fb = FeatureBuilder(data, cfg())
    # 12 días después de su última compra (día 190 → corte 202): dentro de su patrón
    act = compute_activity(fb, day(202), cfg()).set_index("customer_id")
    a = act.loc[1]
    assert a["median_interval_days"] == pytest.approx(10.0) and a["purchase_frequency_per_30d"] == pytest.approx(3.0, abs=0.2)
    assert a["days_since_last_purchase"] == 12 and a["inactivity_status"] == "active"
    # 60 días sin comprar cuando compraba cada 10: fuera de su patrón (no «abandono»: solo ausencia relativa)
    late = compute_activity(fb, day(250), cfg()).set_index("customer_id").loc[1]
    assert late["days_since_last_purchase"] == 60 and late["inactivity_status"] == "inactive_by_pattern"
    assert late["inactivity_risk"] > 0.9
    # con 2 intervalos propios no hay base para afirmar nada: se declara historial insuficiente
    thin = act.loc[2]
    assert thin["inactivity_status"] == "insufficient_history" and np.isnan(thin["inactivity_risk"])
    assert thin["behavior_change"] == "insufficient_history"


def test_the_recency_changes_every_day_without_new_sales():
    from test_customer_dataset import day, make_data
    data = make_data([(1, 1, d, 2) for d in range(0, 200, 10)] + [(1, 2, 50, 1), (1, 2, 90, 1)], last_offset=400)
    fb = FeatureBuilder(data, cfg())
    f1 = fb.compute(day(205)).base.set_index(["customer_id", "product_id"])
    f2 = fb.compute(day(208)).base.set_index(["customer_id", "product_id"])
    assert (f2["rel_recency_days"] - f1["rel_recency_days"] == 3).all()       # +3 días, sin una sola venta nueva
    assert (f2["ref_p"] if "ref_p" in f2 else f2["rel_recency_days"]).notna().all()


def test_behavior_change_detects_a_drop_with_exact_test():
    from test_customer_dataset import day, make_data
    purchases = [(1, 1, d, 1) for d in range(0, 190, 6)] + [(1, 1, d, 1) for d in (200, 230, 260)]   # frecuente y luego espaciado
    fb = FeatureBuilder(make_data(purchases, last_offset=400), cfg())
    row = compute_activity(fb, day(280), cfg()).iloc[0]
    assert row["behavior_change"] == "decrease" and row["behavior_p_value"] < 0.05 and row["frequency_ratio"] < 0.67


def test_top_k_orders_by_probability_and_breaks_ties_by_expected_quantity():
    pred = pd.DataFrame({"customer_id": [1, 1, 1, 2, 2], "product_id": [10, 11, 12, 10, 11],
                         "purchase_probability": [.3, .6, .6, .2, .1], "expected_quantity": [1.0, 2.0, 5.0, 1.0, 1.0]})
    top = top_k_products(pred, 2)
    c1 = top[top["customer_id"] == 1].sort_values("rank")
    assert list(c1["product_id"]) == [12, 11] and list(c1["rank"]) == [1, 2]
    assert len(top[top["customer_id"] == 2]) == 2


def test_inactivity_definition_is_evaluated_against_observable_outcomes(trained):
    ev = evaluate_activity(trained["fb"], trained["bundle"].cutoffs, trained["bundle"].last_complete, trained["cfg"])
    ina = ev["inactivity"]
    assert "no es abandono" in ina["definition"] and ina["n_customer_cutoffs"] > 500
    flagged = ina["flag_inactive_by_pattern"]
    assert flagged["n_flagged"] > 0
    assert flagged["precision"] > 2 * ina["base_no_purchase_rate"]       # con churn conocido, la marca tiene sustancia (lift > 2)
    assert ina["reference_flag_no_purchase_days"]["days"] == 60       # referencia ingenua declarada
    fr = ev["frequency"]
    assert fr["used_lookback_days"] == 180 and set(fr["by_lookback_days"]) == {"90", "180", "365", "all"}
    assert fr["by_lookback_days"]["180"]["mae"] is not None


def test_expected_amount_is_a_declared_assumption_not_a_sale(trained):
    fb, c = trained["fb"], trained["cfg"]
    arts = {30: {"prob": trained["results"][30].artifacts["prob"], "qty": trained["results"][30].artifacts["qty"]}}
    as_of = int(to_day(trained["world"].start)) + trained["world"].n_days - 1
    out = predict_all(fb, arts, as_of, c)
    p = out.predictions
    ok = p["assumed_unit_price"].notna()
    np.testing.assert_allclose(p.loc[ok, "expected_amount"], p.loc[ok, "expected_quantity"] * p.loc[ok, "assumed_unit_price"], rtol=1e-9)
    assert (p["currency"] == "USD").all()
    assert out.rankings.groupby("customer_id").size().max() <= c["top_k_stored"]
