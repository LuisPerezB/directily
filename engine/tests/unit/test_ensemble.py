"""Combinación AutoETS + LightGBM (`ensemble_models`, familia `ets_lightgbm_mean`).

Es el promedio simple de sus dos miembros. Compite como un modelo complejo más (margen mínimo y ventanas ganadas frente al mejor
estadístico; si LightGBM también cumple, debe mejorarlo) y solo se sirve si gana. Sin el interruptor no existe ninguna fila suya y la
selección es exactamente la de siempre.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

import predictily_engine.pipeline.core as core
from predictily_engine.backtest.rolling import eligible_ids
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.models.ensemble import BACKTEST_KEYS, FORECAST_KEYS, ensemble_rows, with_ensemble_rows
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import (
    CANDIDATES_BY_CLASS,
    COMPLEX_FAMILIES,
    ENSEMBLE_FAMILY,
    ENSEMBLE_MEMBERS,
    FAMILIES,
    STATISTICAL,
)
from predictily_engine.models.statistical import forecast_statistical
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides
from predictily_engine.selection.promotion import _legacy_gates
from predictily_engine.selection.selector import LegacyPolicy, select_models

N_SERIES, DAYS, WINDOWS, LEVEL = 12, 28, 3, 20.0
START = pd.Timestamp("2026-03-02")


def _backtest(ets_sd: float, lgbm_sd: float, correlation: float = 0.0, seed: int = 3, with_ensemble: bool = True
              ) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Backtest con naive estacional, AutoETS y LightGBM; el error de cada modelo es ruido propio de desvío `*_sd` (correlación
    `correlation` entre AutoETS y LightGBM). Con errores independientes el promedio reduce el error; con errores iguales, no."""
    rng = np.random.default_rng(seed)
    rows = []
    for w in range(WINDOWS):
        ds = pd.date_range(START + pd.Timedelta(days=28 * w), periods=DAYS, freq="D")
        for s in range(N_SERIES):
            actual = np.full(DAYS, LEVEL) + rng.normal(0, 1.0, DAYS)
            shared, own_e, own_l = rng.normal(0, 1, DAYS), rng.normal(0, 1, DAYS), rng.normal(0, 1, DAYS)
            mix = np.sqrt(1 - correlation ** 2)
            preds = {"seasonal_naive": actual + rng.normal(0, 6.0, DAYS),
                     "auto_ets": actual + ets_sd * (correlation * shared + mix * own_e),
                     "lightgbm_global": actual + lgbm_sd * (correlation * shared + mix * own_l)}
            for fam, yhat in preds.items():
                rows.append(pd.DataFrame({"unique_id": f"b1-p{s}", "window": w, "ds": ds, "step": np.arange(1, DAYS + 1), "family": fam,
                                          "actual": actual, "yhat": np.clip(yhat, 0, None), "stockout": False, "scale": LEVEL,
                                          "demand_class": "smooth"}))
    bt = pd.concat(rows, ignore_index=True)
    profile = pd.DataFrame({"unique_id": [f"b1-p{s}" for s in range(N_SERIES)], "demand_class": "smooth"})
    return (with_ensemble_rows(bt) if with_ensemble else bt), profile


# ───────────────────────── filas de la combinación ─────────────────────────

def test_ensemble_rows_are_the_mean_of_the_members_and_only_where_both_exist():
    ds = pd.date_range("2026-04-01", periods=3)
    rows = pd.DataFrame({
        "unique_id": ["a"] * 3 + ["a"] * 2 + ["a"] * 3, "ds": [*ds, *ds[:2], *ds],
        "family": ["auto_ets"] * 3 + ["lightgbm_global"] * 2 + ["seasonal_naive"] * 3,
        "yhat": [10.0, 20.0, 30.0, 20.0, 10.0, 99.0, 99.0, 99.0], "actual": [1.0, 2.0, 3.0, 1.0, 2.0, 1.0, 2.0, 3.0]})
    out = ensemble_rows(rows, FORECAST_KEYS)
    assert list(out["family"].unique()) == [ENSEMBLE_FAMILY] and len(out) == 2, "el tercer día no tiene LightGBM: no hay medio promedio"
    assert out["yhat"].to_numpy() == pytest.approx([15.0, 15.0]) and out["actual"].to_numpy() == pytest.approx([1.0, 2.0])
    assert ensemble_rows(rows[rows["family"] != "lightgbm_global"], FORECAST_KEYS).empty
    assert ensemble_rows(rows[rows["family"] != "auto_ets"], FORECAST_KEYS).empty


def test_with_ensemble_rows_recomputes_from_the_current_member_rows():
    bt, _ = _backtest(2.0, 2.0, with_ensemble=False)
    once = with_ensemble_rows(bt)
    ens = once[once["family"] == ENSEMBLE_FAMILY].sort_values(BACKTEST_KEYS)
    members = bt[bt["family"].isin(ENSEMBLE_MEMBERS)].groupby(BACKTEST_KEYS)["yhat"].mean().reset_index().sort_values(BACKTEST_KEYS)
    assert ens["yhat"].to_numpy() == pytest.approx(members["yhat"].to_numpy()) and len(ens) == N_SERIES * DAYS * WINDOWS
    assert len(with_ensemble_rows(once)) == len(once), "idempotente: no acumula filas"
    # si el ajuste de hiperparámetros reemplaza las filas de LightGBM, la combinación se rehace con las nuevas
    tuned = once.copy()
    tuned.loc[tuned["family"] == "lightgbm_global", "yhat"] += 4.0
    redone = with_ensemble_rows(tuned)
    assert redone.loc[redone["family"] == ENSEMBLE_FAMILY, "yhat"].sum() == pytest.approx(ens["yhat"].sum() + 2.0 * len(ens))
    no_ml = bt[bt["family"] != "lightgbm_global"]
    assert with_ensemble_rows(no_ml).equals(no_ml)


def test_family_is_registered_as_a_complex_candidate_of_every_class():
    info = FAMILIES[ENSEMBLE_FAMILY]
    assert info.kind == "ml" and info.level == "B" and ENSEMBLE_FAMILY not in STATISTICAL
    assert info.hyperparameters == {"members": ["auto_ets", "lightgbm_global"], "weights": "equal"}
    assert COMPLEX_FAMILIES == ("lightgbm_global", ENSEMBLE_FAMILY)
    assert all(ENSEMBLE_FAMILY in cands and cands[-1] == "lightgbm_global" for cands in CANDIDATES_BY_CLASS.values())
    assert CORE_DEFAULTS["demand"]["ensemble_models"] is False
    assert validate_overrides("demand", {"ensemble_models": True}) == []


# ───────────────────────── en la selección ─────────────────────────

def test_ensemble_wins_when_it_beats_the_best_statistical_and_lightgbm():
    # dos modelos casi igual de buenos (LightGBM apenas peor) con errores independientes: el promedio reduce el error ~28 %
    bt, profile = _backtest(ets_sd=3.0, lgbm_sd=3.1)
    sel = select_models(bt, profile)
    ev = sel.evidence["smooth"]
    assert sel.winners["smooth"] == sel.winners["pooled"] == ENSEMBLE_FAMILY and ev["outcome"] == ENSEMBLE_FAMILY
    assert ev["ensemble"]["passes_improvement"] and ev["ensemble"]["passes_windows"] and ev["ensemble"]["windows_better"] == WINDOWS
    assert ev["ensemble"]["relative_improvement"] > 0.2 and "Combinación" in sel.reasons["smooth"]
    assert ev["passes_improvement"] is False, "LightGBM solo no superaba el margen frente a AutoETS"
    gates = _legacy_gates(ev)
    assert gates["ensemble"]["status"] == "pass" and gates["min_improvement"]["status"] == "fail"
    table = sel.class_table.set_index("family")
    assert table.loc[ENSEMBLE_FAMILY, "windows_better"] == WINDOWS


def test_lightgbm_keeps_the_class_when_the_ensemble_does_not_improve_it():
    # LightGBM mucho mejor que AutoETS: promediarlo con un modelo peor lo empeora
    bt, profile = _backtest(ets_sd=6.0, lgbm_sd=1.5)
    sel = select_models(bt, profile)
    ev = sel.evidence["smooth"]
    assert sel.winners["smooth"] == "lightgbm_global" and ev["outcome"] == "lightgbm_global"
    assert ev["ensemble"]["passes_improvement"] and ev["ensemble"]["beats_lightgbm"] is False
    assert "no mejora a LightGBM" in sel.reasons["smooth"]
    assert _legacy_gates(ev)["ensemble"]["status"] == "fail"


def test_statistical_keeps_the_class_when_neither_complex_model_clears_the_margin():
    # errores casi idénticos (correlación 1) y LightGBM algo peor: ni él ni el promedio mejoran a AutoETS
    bt, profile = _backtest(ets_sd=3.0, lgbm_sd=3.1, correlation=1.0)
    sel = select_models(bt, profile)
    assert sel.winners["smooth"] == "auto_ets" and sel.evidence["smooth"]["outcome"] == "best_statistical"
    assert sel.evidence["smooth"]["ensemble"]["passes_improvement"] is False
    assert "no superó el margen" in sel.reasons["smooth"].split("Combinación")[1]


def test_without_ensemble_rows_the_selection_and_its_evidence_are_unchanged():
    with_rows, profile = _backtest(3.0, 3.0)
    plain = with_rows[with_rows["family"] != ENSEMBLE_FAMILY]
    sel = select_models(plain, profile)
    assert sel.winners["smooth"] == "auto_ets" or sel.winners["smooth"] == "lightgbm_global"
    assert "ensemble" not in sel.evidence["smooth"] and "ensemble" not in _legacy_gates(sel.evidence["smooth"])
    assert ENSEMBLE_FAMILY not in set(sel.pooled_table["family"])


def test_ensemble_also_competes_under_the_total_day_rule_with_its_guards():
    bt, profile = _backtest(ets_sd=3.0, lgbm_sd=3.1)
    policy = LegacyPolicy(selection_metric="total_day")
    sel = select_models(bt, profile, policy=policy)
    ev = sel.evidence["smooth"]
    assert sel.winners["smooth"] == ENSEMBLE_FAMILY and ev["ensemble"]["passes_guards"] is True
    assert "total diario" in sel.reasons["smooth"]
    # una combinación sesgada (se le suma un 40 %) no pasa la salvaguarda aunque se la fuerce a competir
    biased = bt.copy()
    biased.loc[biased["family"] == ENSEMBLE_FAMILY, "yhat"] *= 1.4
    assert select_models(biased, profile, policy=policy).winners["smooth"] != ENSEMBLE_FAMILY


# ───────────────────────── en el pipeline ─────────────────────────

def _with_switch(data):
    return replace(data, settings={**(data.settings or {}), "cores": {"demand": {"ensemble_models": True}}})


def _force_ensemble(mp: pytest.MonkeyPatch) -> None:
    """La etapa de selección real, con la combinación puesta como ganadora de todas las clases (para ejercitar el servido)."""
    real = core.run_selection_stage

    def forced(**kwargs):
        outcome = real(**kwargs)
        for key in list(outcome.selection.winners):
            outcome.selection.winners[key] = ENSEMBLE_FAMILY
        return outcome

    mp.setattr(core, "run_selection_stage", forced)


@pytest.fixture(scope="module")
def ensemble_run(small_data):
    mp = pytest.MonkeyPatch()
    _force_ensemble(mp)
    try:
        return run_pipeline(_with_switch(small_data), horizon=90, seed=42, n_jobs=1)
    finally:
        mp.undo()


def test_switch_off_no_ensemble_rows_exist(pipeline_result):
    assert ENSEMBLE_FAMILY not in set(pipeline_result.backtest["family"])
    assert ENSEMBLE_FAMILY not in pipeline_result.families


def test_switch_on_the_ensemble_is_backtested_as_the_mean_of_its_members(ensemble_run):
    bt = ensemble_run.backtest
    ens = bt[bt["family"] == ENSEMBLE_FAMILY].sort_values(BACKTEST_KEYS)
    assert len(ens) and ens["is_holdout"].any() and (~ens["is_holdout"]).any()
    members = bt[bt["family"].isin(ENSEMBLE_MEMBERS)].groupby(BACKTEST_KEYS).agg(yhat=("yhat", "mean"), n=("yhat", "size")).reset_index()
    members = members[members["n"] == 2].sort_values(BACKTEST_KEYS)
    assert ens["yhat"].to_numpy() == pytest.approx(members["yhat"].to_numpy())
    assert ensemble_run.families[ENSEMBLE_FAMILY]["evaluated"] is True
    assert any(e.get("model_family") == ENSEMBLE_FAMILY for e in ensemble_run.evaluations)


def test_served_ensemble_is_the_mean_of_the_final_autoets_and_lightgbm_forecasts(small_data, ensemble_run):
    res = ensemble_run
    cutoff = pd.Timestamp(res.cutoff)
    ids = eligible_ids(res.panel, cutoff)
    info = res.families[ENSEMBLE_FAMILY]
    assert info["status"] == "production" and info["series_count"] == len(ids) and res.lgbm is not None
    assert res.families["lightgbm_global"]["evaluated"] and ENSEMBLE_FAMILY not in res.failed_families
    # miembros recalculados fuera del pipeline: AutoETS (determinista) y el LightGBM que la ejecución entrenó
    train = res.panel.df[(res.panel.df["ds"] <= cutoff) & res.panel.df["unique_id"].isin(ids)]
    ets = forecast_statistical(train[["unique_id", "ds", "y"]], 90, ["auto_ets"])
    features = FeatureBuilder(res.panel.df, small_data.branches, small_data.promotions, (cutoff + pd.Timedelta(days=90)).date(),
                              promotion_revisions=small_data.promotion_revisions, promotions_known_on=small_data.promotions_known_on)
    _, ref = features.training_frame(cutoff, ids)
    ml = res.lgbm.predict(90, features.future_frame(cutoff, ids, 90, ref))
    expected = ensemble_rows(pd.concat([ets, ml], ignore_index=True), FORECAST_KEYS).set_index(FORECAST_KEYS)["yhat"]
    fv = res.forecast_values
    served = fv[(fv["level"] == "series") & (fv["granularity"] == "day") & (fv["model_family"] == ENSEMBLE_FAMILY)]
    assert served["node_key"].nunique() == len(ids)
    uid = "b" + served["branch_id"].astype(int).astype(str) + "-p" + served["product_id"].astype(int).astype(str)
    got = pd.Series(served["yhat"].to_numpy(dtype=float), index=pd.MultiIndex.from_arrays([uid, pd.to_datetime(served["target_date"])]))
    want = expected.reindex(got.index)
    assert want.notna().all()
    assert got.to_numpy() == pytest.approx(want.to_numpy(), rel=1e-6, abs=1e-3)


def test_if_the_final_lightgbm_fails_the_ensemble_fails_with_it_and_the_fallback_is_served(small_data):
    final_cutoff = pd.Timestamp(small_data.cutoff)

    class FailingFinalFit(LightGBMGlobal):
        def fit(self, train):
            if train["ds"].max() >= final_cutoff:
                raise RuntimeError("fallo forzado del entrenamiento final")
            return super().fit(train)

    mp = pytest.MonkeyPatch()
    _force_ensemble(mp)
    mp.setattr(core, "LightGBMGlobal", FailingFinalFit)
    try:
        res = run_pipeline(_with_switch(small_data), horizon=90, seed=42, n_jobs=1)
    finally:
        mp.undo()
    assert set(res.failed_families) == {"lightgbm_global", ENSEMBLE_FAMILY}
    assert ENSEMBLE_FAMILY not in res.selection.winners.values() and ENSEMBLE_FAMILY in res.planned_selection.winners.values()
    fv = res.forecast_values
    assert ENSEMBLE_FAMILY not in set(fv.loc[fv["level"] == "series", "model_family"].dropna())
    assert res.families[ENSEMBLE_FAMILY]["status"] == "failed"
    assert any(x["code"] == "fallback_served" for x in res.limitations)


def test_bundle_marks_the_lightgbm_artifact_as_served_when_the_ensemble_is_served(ensemble_run, pipeline_result):
    from types import SimpleNamespace

    from predictily_engine.mlops.training import demand_entries

    entry = demand_entries(SimpleNamespace(result=ensemble_run))[0]
    assert entry.model_family == "lightgbm_global" and entry.served is True
    assert entry.series_count == ensemble_run.families[ENSEMBLE_FAMILY]["series_count"] > 0
    # sin la combinación, el paquete es el de siempre
    plain = demand_entries(SimpleNamespace(result=pipeline_result))[0]
    info = pipeline_result.families["lightgbm_global"]
    assert plain.served == (info["status"] == "production") and plain.series_count == info["series_count"]
