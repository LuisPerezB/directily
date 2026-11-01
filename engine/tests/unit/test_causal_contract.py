"""E2-16 · Causalidad: la falta de soporte o de identificación impide publicar un efecto; la simulación sigue disponible."""

from __future__ import annotations

import numpy as np
import pandas as pd

from predictily_engine.causal.contract import (
    IDENTIFICATION_ASSUMPTIONS,
    OBSERVABLE_CONFOUNDERS,
    UNOBSERVABLE_CONFOUNDERS,
    CausalEvidence,
    check_requirements,
)

FULL_REQUEST = {"treatment": "net_price", "outcome": "units_demanded", "unit_of_analysis": "product_week", "estimand": "price_elasticity",
                "horizon_days": 30, "population": "ferretería A", "price_change_pct": 0.0,
                "confounders_controlled": list(OBSERVABLE_CONFOUNDERS), "unobserved_confounders_acknowledged": list(UNOBSERVABLE_CONFOUNDERS),
                "identification_assumptions_acknowledged": list(IDENTIFICATION_ASSUMPTIONS)}


def weekly(prices, promo=None, stockout=None):
    n = len(prices)
    return pd.DataFrame({"week": pd.date_range("2024-01-01", periods=n, freq="W"), "units": np.full(n, 10.0), "price": prices,
                         "promo": promo if promo is not None else np.zeros(n), "stockout_share": stockout if stockout is not None else np.zeros(n)})


def rich_prices():
    rng = np.random.default_rng(1)
    levels = np.array([9.0, 10.0, 11.0, 12.0, 13.0])
    seq = np.repeat(rng.permutation(np.tile(levels, 6)), 3)[:104]  # 104 semanas, 5 niveles, muchos cambios
    return seq


def status(result):
    return {r["id"]: r["status"] for r in result["requirements"]}


def test_default_answer_is_insufficient_evidence_without_any_effect():
    res = check_requirements({}, None)
    assert res["verdict"] == "insufficient_evidence" and res["effect"] is None and res["estimator_enabled"] is False
    assert res["conditional_simulation"]["available"] is True
    st = status(res)
    assert st["effect_definition"] == "not_met" and st["price_variation"] == "not_met" and st["estimator"] == "not_met"


def test_a_single_price_change_is_not_enough_variation():
    ev = CausalEvidence(weekly(np.r_[np.full(60, 10.0), np.full(60, 12.0)]), True, True)
    st = status(check_requirements(FULL_REQUEST, ev))
    assert st["price_variation"] == "not_met" and st["support"] == "not_met"


def test_even_with_all_requirements_met_no_effect_is_published_while_no_estimator_is_enabled():
    p = rich_prices()
    ev = CausalEvidence(weekly(p), True, True)
    req = {**FULL_REQUEST, "price_change_pct": 0.0}
    res = check_requirements(req, ev)
    st = status(res)
    assert st["price_variation"] == "met" and st["support"] == "met" and st["confounders"] == "met" and st["availability"] == "met"
    assert st["identification"] == "declared_not_verifiable"
    assert res["eligible_for_estimator_evaluation"] is True
    assert res["verdict"] == "insufficient_evidence" and res["effect"] is None and res["estimator_enabled"] is False
    assert "ningún estimador" in res["message"] or "ninguno está habilitado" in res["message"]


def test_extrapolation_outside_the_observed_price_range_breaks_support():
    ev = CausalEvidence(weekly(rich_prices()), True, True)
    res = check_requirements({**FULL_REQUEST, "price_change_pct": 0.9}, ev)  # +90 % sobre el último precio
    sup = next(r for r in res["requirements"] if r["id"] == "support")
    assert sup["status"] == "not_met" and sup["evidence"]["target_outside_observed_range"] is True
    assert res["eligible_for_estimator_evaluation"] is False


def test_price_changes_that_coincide_with_promotions_cannot_be_separated():
    p = rich_prices()
    promo = np.zeros(len(p))
    change = np.where(np.abs(np.diff(p)) > 0)[0] + 1
    promo[change] = 1  # cada cambio de precio ocurre en semana de promoción
    res = check_requirements(FULL_REQUEST, CausalEvidence(weekly(p, promo=promo), True, True))
    conf = next(r for r in res["requirements"] if r["id"] == "confounders")
    assert conf["status"] == "not_met" and any("promociones" in x for x in conf["evidence"]["problems"])


def test_stockouts_censor_demand_and_unacknowledged_confounders_block():
    p = rich_prices()
    res = check_requirements(FULL_REQUEST, CausalEvidence(weekly(p, stockout=np.full(len(p), 0.5)), True, True))
    conf = next(r for r in res["requirements"] if r["id"] == "confounders")
    assert conf["status"] == "not_met" and any("quiebre" in x for x in conf["evidence"]["problems"])
    req = {**FULL_REQUEST, "unobserved_confounders_acknowledged": [], "confounders_controlled": ["promotions"]}
    conf = next(r for r in check_requirements(req, CausalEvidence(weekly(p), True, True))["requirements"] if r["id"] == "confounders")
    assert conf["status"] == "not_met" and set(conf["evidence"]["observable_not_controlled"]) == set(OBSERVABLE_CONFOUNDERS) - {"promotions"}


def test_missing_known_at_blocks_availability_and_missing_assumptions_block_identification():
    ev = CausalEvidence(weekly(rich_prices()), price_known_at_available=False, promotions_known_at_available=True)
    st = status(check_requirements({**FULL_REQUEST, "identification_assumptions_acknowledged": ["positivity"]}, ev))
    assert st["availability"] == "not_met" and st["identification"] == "not_met"


def test_predictive_importance_is_not_causal_evidence_note_is_present():
    res = check_requirements(FULL_REQUEST, None)
    assert "LightGBM" in res["predictive_vs_causal"]
