"""Customer Core S05 · segmentos y calibración por segmento, lift@k, utilidad económica del ranking, drift y degradación a agregado.

Todo con datos FICTICIOS construidos en la prueba (verdad conocida); sin pronósticos reales.
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.degrade import (
    individual_recommendations_block,
    ranking_from_demand,
    ranking_from_purchases,
)
from predictily_engine.customer.drift import (
    drift_across_cutoffs,
    drift_between,
    level,
    psi,
    psi_against_reference,
    reference_distribution,
)
from predictily_engine.customer.ranking_eval import (
    global_lift,
    lift_at_k,
    pr_auc,
    ranking_extension,
    ranking_utility,
    topk_mask,
)
from predictily_engine.customer.segments import (
    Segmenter,
    apply_segment_shifts,
    evaluate_segment_recalibration,
    fit_segment_shifts,
    segment_calibration,
)
from predictily_engine.platform.config import resolve_config

CFG, _, _ = resolve_config("customer", {}, None)


def frame(n: int, seed: int = 0) -> pd.DataFrame:
    rng = np.random.default_rng(seed)
    return pd.DataFrame({
        "rel_n_365": rng.integers(1, 40, n).astype(float), "qty_sum_180": rng.uniform(1, 100, n), "price_last": rng.uniform(1, 10, n),
        "rel_tenure_days": rng.integers(1, 366, n).astype(float), "n_purchases_all": rng.integers(1, 30, n).astype(float)})


# ───────────────────────────── segmentos ─────────────────────────────

def test_segmenter_fits_terciles_on_the_calibration_block_only_and_labels_every_dimension():
    cal, other = frame(900, 1), frame(900, 2)
    seg = Segmenter.fit(cal, CFG)
    assert len(seg.frequency_edges) == 2 and seg.frequency_edges == sorted(seg.frequency_edges)
    again = Segmenter.fit(cal, CFG)
    assert again.frequency_edges == seg.frequency_edges and again.value_edges == seg.value_edges, "determinista"
    keys = seg.keys(other)
    assert set(keys) == {"frequency", "value", "tenure", "history"}
    assert set(keys["frequency"]) <= {"frequency:low", "frequency:medium", "frequency:high"}
    assert set(keys["tenure"]) <= {"tenure:new", "tenure:established", "tenure:veteran"}
    assert set(keys["history"]) == {"history:cold_start", "history:warm"}
    share = pd.Series(keys["frequency"]).value_counts(normalize=True)
    assert share.min() > 0.2, "terciles de la calibración ≈ un tercio de otra muestra de la misma población"
    assert (seg.primary(other) == keys["frequency"]).all()
    # nada del holdout entra en los cortes: cambiar el bloque «otro» no mueve los cortes
    assert Segmenter.fit(cal, CFG).as_dict()["fitted_on"].startswith("bloque de calibración")


def test_segmenter_labels_tenure_cold_start_and_unknown_value():
    seg = Segmenter(frequency_edges=[3.0, 8.0], value_edges=[10.0, 50.0], tenure_edges=[90.0, 365.0], cold_start_min_purchases=3)
    base = pd.DataFrame({"rel_n_365": [1.0, 5.0, 20.0], "qty_sum_180": [1.0, 10.0, 100.0], "price_last": [np.nan, 2.0, 2.0],
                         "rel_tenure_days": [10.0, 100.0, 365.0], "n_purchases_all": [1.0, 3.0, 40.0]})
    lab = seg.labels(base)
    assert lab["frequency"].tolist() == ["low", "medium", "high"] and lab["value"].tolist() == ["unknown", "medium", "high"]
    assert lab["tenure"].tolist() == ["new", "established", "veteran"] and lab["history"].tolist() == ["cold_start", "warm", "warm"]
    flat = Segmenter.fit(pd.DataFrame({"rel_n_365": np.ones(50), "qty_sum_180": np.ones(50), "price_last": np.ones(50), "rel_tenure_days": np.ones(50),
                                       "n_purchases_all": np.ones(50)}), CFG)
    assert set(flat.labels(base.iloc[:1].assign(price_last=1.0))["frequency"]) == {"all"}, "sin variación: un solo segmento"


def test_segment_calibration_flags_miscalibration_and_refuses_figures_for_thin_segments():
    rng = np.random.default_rng(3)
    n = 6000
    keys = {"frequency": np.array(["frequency:high"] * 2500 + ["frequency:low"] * 3000 + ["frequency:rare"] * 500, dtype=object)}
    p = np.where(keys["frequency"] == "frequency:high", 0.30, np.where(keys["frequency"] == "frequency:low", 0.60, 0.5))
    true = np.where(keys["frequency"] == "frequency:high", 0.30, np.where(keys["frequency"] == "frequency:low", 0.30, 0.0005))
    y = (rng.random(n) < true).astype(float)
    rows = {r["segment"]: r for r in segment_calibration(y, p, np.full(n, 0.3), keys, CFG)}
    assert rows["frequency:high"]["status"] == "calibrated"
    assert rows["frequency:low"]["status"] == "miscalibrated" and rows["frequency:low"]["mean_ratio"] > 1.5
    assert rows["frequency:rare"]["status"] == "insufficient_data" and rows["frequency:rare"]["served"] is None and rows["frequency:rare"]["mean_ratio"] is None
    assert rows["frequency:high"]["served"]["calibration_curve"] and all(b["n"] > 0 for b in rows["frequency:high"]["served"]["calibration_curve"])
    assert rows["frequency:high"]["n"] == 2500 and rows["frequency:high"]["reference"]["brier"] is not None


def test_segment_recalibration_is_a_candidate_decided_on_selection_and_never_served():
    rng = np.random.default_rng(4)
    n = 4000
    seg = np.where(rng.random(n) < 0.5, "frequency:high", "frequency:low").astype(object)
    true = np.where(seg == "frequency:high", 0.5, 0.1)
    p = np.where(seg == "frequency:high", 0.35, 0.18)               # sesgos opuestos por segmento
    y = (rng.random(n) < true).astype(float)
    cal, sel, hold = slice(0, 1500), slice(1500, 3000), slice(3000, 4000)
    shifts = fit_segment_shifts(p[cal], y[cal], seg[cal], 20)
    assert shifts["frequency:high"] > 0 > shifts["frequency:low"]
    out = evaluate_segment_recalibration(p[cal], y[cal], seg[cal], p[sel], y[sel], seg[sel], p[hold], y[hold], seg[hold], CFG)
    assert out["adopted"] is True and out["served"] is False
    assert out["selection"]["brier_segmented"] < out["selection"]["brier_global"] and out["holdout"]["informative_only"] is True
    shifted = apply_segment_shifts(p[hold], seg[hold], shifts)
    assert np.mean((shifted - y[hold]) ** 2) < np.mean((p[hold] - y[hold]) ** 2)
    no_sel = evaluate_segment_recalibration(p[cal], y[cal], seg[cal], None, None, None, p[hold], y[hold], seg[hold], CFG)
    assert no_sel["adopted"] is False


# ───────────────────────────── lift, PR-AUC y utilidad económica ─────────────────────────────

def toy():
    # dos unidades (comprador × corte) con 4 candidatos cada una
    units = np.array([1, 1, 1, 1, 2, 2, 2, 2])
    y = np.array([1, 0, 0, 0, 0, 0, 1, 1])
    perfect = y.astype(float) + np.array([0.1, 0.2, 0.3, 0.0, 0.0, 0.1, 0.0, 0.2]) * 0       # ordena los positivos primero
    bad = 1.0 - perfect
    return units, y, perfect, bad


def test_lift_at_k_is_precision_over_random_precision_per_unit():
    units, y, perfect, bad = toy()
    good = lift_at_k(units, perfect, y, [1, 2])
    # precisión aleatoria: unidad 1 → 1/4, unidad 2 → 2/4 ⇒ media 0,375
    assert good["random_precision_at_1"] == pytest.approx(0.375)
    assert good["lift_at_1"] == pytest.approx(1.0 / 0.375)          # ambos primeros candidatos son positivos
    assert lift_at_k(units, bad, y, [1])["lift_at_1"] == pytest.approx(0.0)
    assert lift_at_k(units, perfect, y, [5])["lift_at_5"] is None, "ninguna unidad con ≥ 5 candidatos"


def test_global_lift_and_pr_auc_against_a_random_score():
    rng = np.random.default_rng(5)
    y = (rng.random(5000) < 0.1).astype(float)
    informative = y * 0.5 + rng.random(5000)
    noise = rng.random(5000)
    g = global_lift(informative, y, [0.05, 0.10])
    assert g["top_5pct"]["lift"] > 3.0 and g["top_5pct"]["n_rows"] == 250
    assert global_lift(noise, y, [0.10])["top_10pct"]["lift"] < 1.5
    assert pr_auc(informative, y) > pr_auc(noise, y) > 0
    assert pr_auc(np.zeros(3), np.zeros(3)) is None and global_lift(noise, np.zeros(5000), [0.1])["top_10pct"] is None


def test_topk_mask_selects_k_per_unit_with_a_deterministic_tiebreak():
    units = np.array([1, 1, 1, 2, 2])
    score = np.array([0.5, 0.5, 0.1, 0.9, 0.2])
    m = topk_mask(units, score, 2, tiebreak=np.array([0.0, 1.0, 0.0, 0.0, 0.0]))
    assert m.tolist() == [True, True, False, True, True]
    assert topk_mask(units, score, 1, tiebreak=np.array([0.0, 1.0, 0.0, 0.0, 0.0])).tolist() == [False, True, False, True, False]


def test_economic_utility_counts_realized_units_amount_and_margin_only_with_known_cost():
    units = np.array([1, 1, 1, 2, 2, 2, 3])                          # la unidad 3 tiene un solo candidato (≤ k): no se rankea
    qty = np.array([10.0, 0.0, 4.0, 0.0, 6.0, 0.0, 9.0])
    price = np.array([2.0, 2.0, 3.0, 1.0, 1.0, np.nan, 5.0])
    good = np.array([0.9, 0.1, 0.8, 0.1, 0.9, 0.2, 0.5])
    bad = 1 - good
    u = ranking_utility(units, good, 2, qty, price)
    assert u["n_units_ranked"] == 2 and "más de 2 candidatos" in u["scope"]
    assert u["units"]["captured"] == 20.0 and u["units"]["oracle"] == 20.0 and u["units"]["capture_ratio"] == 1.0
    assert u["amount_at_cutoff_price"]["captured"] == pytest.approx(10 * 2 + 4 * 3 + 6 * 1)
    assert u["margin"] is None and u["rows_without_cost"] is None, "sin costo conocido al corte no hay margen"
    b = ranking_utility(units, bad, 2, qty, price)
    assert b["units"]["captured"] < u["units"]["captured"] and b["units"]["capture_ratio"] < 1.0
    cost = np.array([1.0, 1.0, 1.0, 0.5, 0.5, 0.5, 1.0])
    m = ranking_utility(units, good, 2, qty, price, cost=cost)
    assert m["margin"]["captured"] == pytest.approx(10 * 1 + 4 * 2 + 6 * 0.5) and m["rows_without_cost"] == 1 and m["rows_without_price"] == 1
    empty = ranking_utility(np.array([1, 2]), np.array([0.1, 0.2]), 5, np.zeros(2), np.ones(2))
    assert empty["units"] is None and empty["n_units_ranked"] == 0


def test_ranking_extension_compares_the_served_source_with_popularity_and_recency():
    rng = np.random.default_rng(6)
    n_units, per = 400, 8
    units = np.repeat(np.arange(n_units), per)
    latent = rng.random(n_units * per)
    y = (rng.random(n_units * per) < latent * 0.5).astype(float)
    qty = y * rng.integers(1, 10, n_units * per)
    price = np.full(n_units * per, 2.0)
    sources = {"served": (latent, None), "reference": (latent + rng.normal(0, 0.3, len(latent)), None),
               "popularity": (rng.random(len(latent)), None), "recency": (rng.random(len(latent)), None)}
    out = ranking_extension(units, y, qty, price, sources, [1, 3, 5], 3, [0.05, 0.1])
    assert out["comparison"]["served_vs_popularity"]["lift_at_3_ratio"] > 1.2
    assert out["comparison"]["served_vs_popularity"]["units_captured_ratio"] > 1.0
    assert out["sources"]["served"]["pr_auc"] > out["sources"]["popularity"]["pr_auc"]
    assert "margen" in out["margin_limitation"], "la limitación de no calcular margen se declara"
    with_cost = ranking_extension(units, y, qty, price, sources, [3], 3, [0.1], cost=np.full(len(y), 1.0))
    assert with_cost["margin_limitation"] is None and with_cost["sources"]["served"]["utility"]["margin"]["captured"] > 0


# ───────────────────────────── drift ─────────────────────────────

def test_psi_is_zero_for_the_same_distribution_and_grows_with_the_shift():
    rng = np.random.default_rng(7)
    ref_p = rng.beta(2, 6, 20000)
    ref = reference_distribution(ref_p, 10)
    assert sum(ref["proportions"]) == pytest.approx(1.0) and ref["n"] == 20000
    same = psi_against_reference(rng.beta(2, 6, 20000), ref, CFG)
    shifted = psi_against_reference(rng.beta(4, 4, 20000), ref, CFG)
    assert same["status"] == "ok" and same["psi_adjusted"] < CFG["drift_psi_warn"]
    assert shifted["status"] == "alert" and shifted["psi"] > 0.25
    assert psi(np.array([0.5, 0.5]), np.array([0.5, 0.5])) == pytest.approx(0.0)
    assert level(0.05, 0.1, 0.25) == "ok" and level(0.12, 0.1, 0.25) == "warn" and level(0.3, 0.1, 0.25) == "alert" and level(None, 0.1, 0.25) == "insufficient_data"


def test_psi_adjusted_discounts_sampling_noise_in_small_samples():
    rng = np.random.default_rng(8)
    ref = reference_distribution(rng.beta(2, 6, 5000), 10)
    small = psi_against_reference(rng.beta(2, 6, 60), ref, CFG)
    assert small["psi"] > small["psi_adjusted"] >= 0.0, "con 60 filas el PSI crudo se infla por ruido; el ajustado lo descuenta"
    assert small["status"] in ("ok", "warn")
    assert psi_against_reference(np.array([0.3]), None, CFG)["status"] == "insufficient_data"


def test_drift_between_flags_probability_and_observed_rate_changes_with_enough_positives():
    rng = np.random.default_rng(9)
    pa, pb = rng.beta(2, 6, 4000), rng.beta(2, 6, 4000) * 0.4
    ya, yb = (rng.random(4000) < 0.25).astype(float), (rng.random(4000) < 0.08).astype(float)
    d = drift_between(pa, ya, pb, yb, CFG)
    assert d["status"] == "alert" and d["observed_rate"]["status"] == "alert" and d["observed_rate"]["relative_change"] < -0.5
    stable = drift_between(pa, ya, rng.beta(2, 6, 4000), (rng.random(4000) < 0.25).astype(float), CFG)
    assert stable["status"] == "ok" and stable["observed_rate"]["status"] == "ok"
    few = drift_between(pa[:200], ya[:200] * 0, pb[:200], yb[:200] * 0, CFG)
    assert few["observed_rate"]["status"] == "insufficient_data" and "positivos" in few["observed_rate"]["reason"]
    assert drift_between(np.array([]), np.array([]), pb, yb, CFG)["status"] == "insufficient_data"


def test_drift_across_cutoffs_reports_the_worst_consecutive_shift():
    rng = np.random.default_rng(10)
    cut = np.repeat([100, 114, 128, 142], 400)
    p = np.concatenate([rng.beta(2, 6, 400), rng.beta(2, 6, 400), rng.beta(5, 3, 400), rng.beta(5, 3, 400)])
    y = (rng.random(1600) < p).astype(float)
    out = drift_across_cutoffs(cut, p, y, CFG)
    assert out["n_cutoffs"] == 4 and out["status"] == "alert" and out["max_psi_adjusted"] > 0.25
    assert out["per_cutoff"][0].get("psi_vs_previous") is None and out["per_cutoff"][2]["status"] == "alert"
    assert out["per_cutoff"][3]["status"] == "ok"
    thin = drift_across_cutoffs(np.repeat([1, 2], 10), np.full(20, 0.3), np.zeros(20), CFG)
    assert thin["n_cutoffs"] == 0 and thin["status"] == "insufficient_data"


# ───────────────────────────── degradación a agregado ─────────────────────────────

class FakeAssessment:
    def __init__(self, blocked=False):
        self.blocked = blocked
        self.outcome = "BLOCK" if blocked else "DEGRADE"
        self.disabled_features = ["customer.individual_predictions", "customer.individual_rankings", "customer.activity_risk"]
        self.individual_stop_code = "dq_gate_block" if blocked else "dq_individual_recommendations_disabled"

    def individual_stop_message(self):
        return "Recomendaciones individuales deshabilitadas por la calidad de los datos"

    def reason_codes(self, *_):
        return ["dq_customer_customer_ids_unstable"]


def test_aggregate_ranking_from_demand_and_from_identified_purchases_never_names_buyers():
    agg = pd.DataFrame({"product_id": [3, 1, 2], "days": [30, 30, 30], "yhat": [10.0, 50.0, 50.0]})
    r = ranking_from_demand(agg, 2, 30, forecast_run_id=77)
    assert [i["product_id"] for i in r["items"]] == [1, 2] and r["partial"] is False and r["source"] == "demand_forecast"
    assert all(set(i) <= {"rank", "product_id", "forecast_units", "forecast_days"} for i in r["items"]), "agregado: sin compradores"
    purchases = pd.DataFrame({"customer_id": [1, 2, 3, 1], "product_id": [5, 5, 6, 6], "qty": [4.0, 6.0, 3.0, 3.0],
                              "ds": pd.to_datetime(["2026-01-05", "2026-01-06", "2026-01-07", "2025-01-01"])})
    p = ranking_from_purchases(purchases, date(2026, 1, 10), 90, 5)
    assert p["partial"] is True and "genéricos" in p["partial_note"]
    assert [(i["product_id"], i["units"], i["buyers"]) for i in p["items"]] == [(5, 10.0, 2), (6, 3.0, 1)], "solo la ventana de 90 días"
    assert all("customer_id" not in i for i in p["items"])
    assert ranking_from_purchases(pd.DataFrame(), date(2026, 1, 10), 90, 5)["items"] == []


def test_individual_recommendations_block_documents_the_disabled_state():
    qa = FakeAssessment()
    block = individual_recommendations_block(qa, ranking_from_demand(pd.DataFrame({"product_id": [1], "days": [30], "yhat": [5.0]}), 5, 30, 1))
    assert block["status"] == "disabled" and block["individual_rows_written"] == 0 and block["reason_code"] == "dq_individual_recommendations_disabled"
    assert "customer.individual_predictions" in block["disabled_features"] and block["aggregate_ranking"]["items"]
    blocked = individual_recommendations_block(FakeAssessment(blocked=True), None)
    assert blocked["reason_code"] == "dq_gate_block" and blocked["aggregate_ranking"] is None
