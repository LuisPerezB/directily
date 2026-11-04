"""W6-E · la regla con que el modelo con factores COMPITE con el LightGBM sin factores y la carga de insumos de factores (sin base de datos).

`duel` decide solo con las ventanas de selección (WAPE con denominador estable, mejora relativa mínima y fracción de ventanas ganadas);
`FactorOutcome.compete` entrena el período final reservado SOLO después de ganar; `load_factor_inputs` no consulta nada con el pipeline apagado.
Todo con datos FICTICIOS.
"""

from __future__ import annotations

import contextlib
import json

import numpy as np
import pandas as pd
import pytest

from predictily_engine.availability.contracts import FeatureContractError
from predictily_engine.availability.pipeline import FactorResolution
from predictily_engine.backtest.factor_arm import (
    KEPT,
    NOT_EVALUATED,
    SELECTED,
    FactorArm,
    FactorArmRule,
    FactorOutcome,
    duel,
    factor_config_record,
    placebo_gate,
)
from predictily_engine.backtest.rolling import BacktestPlan
from predictily_engine.db.factors_repository import AmbiguousDefinitionError
from predictily_engine.pipeline.data import FactorInputs, FactorReadError, TenantData, load_factor_inputs

ENABLED = {"cores": {"factors": {"enabled": True}}}
DAY0 = pd.Timestamp("2026-01-01")


def rows(errors: dict[int, tuple[float, float]], *, family: str = "lightgbm_global", n: int = 60, actual: float = 10.0, holdout_window: int | None = None,
         stockout_days: int = 0) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Filas del backtest de un solo brazo plain/factor. `errors[w] = (error_plain, error_factor)` por día (WAPE de la ventana = error / actual)."""
    plain, factor = [], []
    for w, (e_plain, e_factor) in errors.items():
        cutoff = DAY0 + pd.Timedelta(days=28 * w)
        days = pd.date_range(cutoff + pd.Timedelta(days=1), periods=n, freq="D")
        base = pd.DataFrame({"window": w, "unique_id": "b1-p1", "ds": days, "actual": actual, "stockout": False, "cutoff": cutoff, "family": family,
                             "is_holdout": w == holdout_window})
        if stockout_days:
            base.loc[: stockout_days - 1, "stockout"] = True
        plain.append(base.assign(yhat=actual + e_plain))
        factor.append(base.assign(yhat=actual + e_factor))
    return pd.concat(plain, ignore_index=True), pd.concat(factor, ignore_index=True)


RULE = FactorArmRule()


# ───────────────────────── la regla ─────────────────────────

def test_the_factor_model_is_selected_only_with_enough_improvement_in_enough_windows():
    plain, factor = rows({0: (4.0, 3.0), 1: (4.0, 3.2), 2: (4.0, 3.1)})              # ~22 % de mejora en las tres
    verdict = duel(plain, factor, RULE)
    ev = verdict["evidence"]
    assert verdict["decision"] == SELECTED and verdict["reason"] == "improvement_and_windows"
    assert ev["windows_total"] == 3 and ev["windows_better"] == 3 and ev["windows_needed"] == 2 and ev["passes_improvement"] and ev["passes_windows"]
    assert ev["wape_plain"] == pytest.approx(0.4) and ev["wape_factor"] == pytest.approx(0.31) and ev["relative_improvement"] > 0.2
    assert ev["denominator"] == pytest.approx(1800.0) and ev["scope"] == "selection_windows" and ev["rule"]["holdout_used_for_duel"] is False
    assert [w["used"] for w in ev["windows"]] == [True, True, True] and ev["rows_compared"] == 180


def test_an_improvement_below_the_minimum_margin_keeps_the_plain_model():
    plain, factor = rows({0: (4.0, 3.9), 1: (4.0, 3.9), 2: (4.0, 3.9)})              # 2.5 % < 3 %
    verdict = duel(plain, factor, RULE)
    assert verdict["decision"] == KEPT and verdict["reason"] == "no_sufficient_improvement"
    assert verdict["evidence"]["passes_improvement"] is False and verdict["evidence"]["windows_better"] == 3
    assert duel(plain, factor, FactorArmRule(min_improvement=0.02))["decision"] == SELECTED, "el margen es configurable"


def test_a_global_improvement_that_is_not_stable_across_windows_is_not_enough():
    plain, factor = rows({0: (4.0, 1.0), 1: (4.0, 4.2), 2: (4.0, 4.2)})              # gana una ventana por mucho y pierde las otras dos
    verdict = duel(plain, factor, RULE)
    assert verdict["evidence"]["passes_improvement"] is True and verdict["evidence"]["windows_better"] == 1 and verdict["evidence"]["windows_needed"] == 2
    assert verdict["decision"] == KEPT and verdict["reason"] == "not_stable_across_windows"


def test_too_few_comparable_windows_or_a_tiny_denominator_is_insufficient_evidence_not_a_win():
    plain, factor = rows({0: (4.0, 1.0)})
    assert duel(plain, factor, RULE)["reason"] == "insufficient_windows"
    assert duel(plain, factor, FactorArmRule(min_windows=1))["decision"] == SELECTED
    plain, factor = rows({0: (0.2, 0.1), 1: (0.2, 0.1)}, n=3, actual=1.0)             # Σ reales = 3 por ventana < 30
    verdict = duel(plain, factor, RULE)
    assert verdict["decision"] == KEPT and verdict["reason"] == "denominator_below_minimum"
    assert verdict["evidence"]["wape_status"] != "ok" and all(w["used"] is False for w in verdict["evidence"]["windows"])


def test_an_incomplete_factor_arm_is_never_compared_on_the_windows_it_has():
    plain, factor = rows({0: (4.0, 1.0), 1: (4.0, 1.0), 2: (4.0, 1.0)})
    verdict = duel(plain, factor[factor["window"] != 1], RULE)
    assert verdict["decision"] == KEPT and verdict["reason"] == "factor_arm_incomplete" and verdict["evidence"]["windows_missing_in_factor_arm"] == [1]
    assert duel(plain, factor.iloc[0:0], RULE)["reason"] == "factor_arm_incomplete"
    assert duel(plain.iloc[0:0], factor, RULE)["reason"] == "no_plain_rows"


def test_a_tie_or_a_perfect_baseline_is_not_an_improvement():
    plain, factor = rows({0: (4.0, 4.0), 1: (4.0, 4.0)})
    assert duel(plain, factor, FactorArmRule(min_improvement=0.0))["decision"] == KEPT, "con margen 0 empatar no es mejorar (mejora estricta)"
    plain, factor = rows({0: (0.0, 0.0), 1: (0.0, 0.0)})
    assert duel(plain, factor, FactorArmRule(min_improvement=0.0))["decision"] == KEPT
    plain, factor = rows({0: (0.0, 1.0), 1: (0.0, 1.0)})                                # baseline perfecto: no hay mejora posible, ni división por cero
    verdict = duel(plain, factor, RULE)
    assert verdict["decision"] == KEPT and verdict["evidence"]["relative_improvement"] is None


def test_non_evaluable_days_are_excluded_from_both_arms_alike():
    plain, factor = rows({0: (4.0, 3.0), 1: (4.0, 3.0)}, stockout_days=10)
    factor.loc[factor["stockout"], "yhat"] = 1e6                                         # un error enorme en un día no evaluable (quiebre): no cuenta
    verdict = duel(plain, factor, RULE)
    assert verdict["decision"] == SELECTED and verdict["evidence"]["rows_compared"] == 100


def test_the_rule_comes_from_the_factors_block_and_has_a_stable_version():
    rule = FactorArmRule.from_config({"arm_min_improvement": 0.05, "arm_windows_won_fraction": 0.75, "arm_min_windows": 3}, {"wape_min_denominator": 50.0})
    assert (rule.min_improvement, rule.windows_won_fraction, rule.min_windows, rule.wape_min_denominator) == (0.05, 0.75, 3, 50.0)
    assert rule.needed_windows(4) == 3 and FactorArmRule().needed_windows(3) == 2 and FactorArmRule().needed_windows(4) == 3
    assert FactorArmRule().version == FactorArmRule().version != rule.version
    assert FactorArmRule.from_config({}, None) == FactorArmRule()


# ───────────────────────── el control placebo ─────────────────────────

def placebo_entry(wape: float, windows: dict[int, float], k: int = 0, failed: bool = False) -> dict:
    return {"k": k, "seed": 1, "shifts_days": {"f.uno": 30}, "failed": failed, "wape": None if failed else wape,
            "windows": [{"window": w, "wape_factor": v, "used": True} for w, v in windows.items()]}


def real_evidence(wape: float, windows: dict[int, float]) -> dict:
    return {"wape_factor": wape, "windows": [{"window": w, "wape_factor": v, "used": True} for w, v in windows.items()]}


def test_the_real_arm_must_beat_the_best_placebo_by_the_margin_and_in_enough_windows():
    rule = FactorArmRule(placebo_arms=3)
    real = real_evidence(0.40, {0: 0.40, 1: 0.40, 2: 0.40})
    ok = placebo_gate(real, [placebo_entry(0.45, {0: 0.45, 1: 0.46, 2: 0.44}), placebo_entry(0.47, {0: 0.47, 1: 0.47, 2: 0.47}, 1),
                             placebo_entry(0.46, {0: 0.46, 1: 0.46, 2: 0.46}, 2)], rule)
    assert ok["passed"] is True and ok["reason"] == "passes_placebo" and ok["best_placebo_wape"] == 0.45 and ok["evaluated"] == 3
    assert ok["windows_better_than_best_placebo"] == 3 and ok["relative_improvement_vs_best_placebo"] == pytest.approx(0.1111, abs=1e-3)
    # El mejor placebo casi iguala al real (dentro del margen de 3 %): no pasa aunque el real sea ligeramente mejor.
    close = placebo_gate(real, [placebo_entry(0.405, {0: 0.405, 1: 0.405, 2: 0.405}), placebo_entry(0.50, {0: 0.5, 1: 0.5, 2: 0.5}, 1),
                                placebo_entry(0.50, {0: 0.5, 1: 0.5, 2: 0.5}, 2)], rule)
    assert close["passed"] is False and close["reason"] == "placebo_not_beaten" and close["passes_margin"] is False
    # Gana el margen en el agregado pero no en suficientes ventanas frente al mejor placebo.
    unstable = placebo_gate(real_evidence(0.40, {0: 0.30, 1: 0.45, 2: 0.45}), [placebo_entry(0.44, {0: 0.44, 1: 0.44, 2: 0.44}),
                                                                            placebo_entry(0.50, {0: 0.5, 1: 0.5, 2: 0.5}, 1),
                                                                            placebo_entry(0.50, {0: 0.5, 1: 0.5, 2: 0.5}, 2)], rule)
    assert unstable["passes_margin"] is True and unstable["passes_windows"] is False and unstable["passed"] is False


def test_without_enough_placebos_nothing_is_promoted_and_a_disabled_control_is_declared():
    rule = FactorArmRule(placebo_arms=4)
    real = real_evidence(0.30, {0: 0.3, 1: 0.3})
    few = placebo_gate(real, [placebo_entry(0.5, {0: 0.5, 1: 0.5}), placebo_entry(0, {}, 1, failed=True), placebo_entry(0, {}, 2, failed=True),
                              placebo_entry(0, {}, 3, failed=True)], rule)
    assert few["passed"] is False and few["reason"] == "placebo_unavailable" and few["evaluated"] == 1
    off = placebo_gate(real, [], FactorArmRule(placebo_arms=0))
    assert off["enabled"] is False and off["passed"] is True and off["reason"] == "placebo_disabled"


# ───────────────────────── la competencia: período final, selección hacia delante y placebos ─────────────────────────

def plan_with_holdout() -> BacktestPlan:
    return BacktestPlan(horizon=60, step=28, cutoffs=[DAY0, DAY0 + pd.Timedelta(days=28), DAY0 + pd.Timedelta(days=56)],
                        holdout_cutoff=DAY0 + pd.Timedelta(days=84))


class FakeBuilder:
    """Constructor con factores de pega: `compete` solo se lo entrega a `lightgbm_backtest_rows` (sustituida en estas pruebas)."""

    def __init__(self, keys=(), placebo=False):
        self.keys, self.placebo = tuple(keys), placebo


def resolution_of(*keys: str) -> FactorResolution:
    frame = pd.DataFrame({"ds": pd.date_range(DAY0, periods=5), **{f"factor__{k}": 1.0 for k in keys}})
    return FactorResolution(enabled=True, columns=frame, used=list(keys), served_block_days=30)


def fake_arm(res: FactorResolution) -> FactorArm:
    return FactorArm(resolution=res, features=FakeBuilder(res.used, bool(res.stats.get("placebo"))), build=lambda df: FakeBuilder(res.used))


class Scenario:
    """Qué devuelve el brazo con factores según el conjunto de factores (o placebo): errores (plain, factor) por ventana y para el período final."""

    def __init__(self, selection: dict, holdout=(4.0, 3.0)):
        self.selection, self.holdout, self.calls = selection, holdout, []
        # selection: {frozenset(claves) | ("placebo", claves): {ventana: (error_plain, error_factor)}}

    def frames(self, key):
        errors = self.selection[key]
        plain, factor = rows({**errors, 3: self.holdout}, holdout_window=3)
        return plain, factor

    def plain(self):
        key = next(iter(self.selection))
        plain, _ = self.frames(key)
        stat = plain.assign(family="seasonal_naive", yhat=plain["actual"] + 5.0)
        return pd.concat([plain, stat], ignore_index=True)


def install(monkeypatch, scenario: Scenario, fail_on=None):
    import predictily_engine.selection.tuning as tuning

    monkeypatch.setattr(FactorResolution, "shifted", lambda self, shifts: dataclasses_replace(self, stats={"placebo": True, "shifts": shifts}))

    def fake(panel, features, plan, windows, params, seed, n_jobs, origins=None):
        origins = [pd.Timestamp(o) for o in origins]
        scenario.calls.append((features.keys, features.placebo, origins))
        if fail_on is not None and fail_on(features, origins):
            raise RuntimeError("boom")
        key = ("placebo", frozenset(features.keys)) if features.placebo else frozenset(features.keys)
        _, factor = scenario.frames(key)
        return factor[factor["cutoff"].isin(origins)]

    monkeypatch.setattr(tuning, "lightgbm_backtest_rows", fake)


def dataclasses_replace(obj, **changes):
    import dataclasses

    return dataclasses.replace(obj, **changes)


def outcome_for(*keys: str, rule: FactorArmRule | None = None) -> FactorOutcome:
    return FactorOutcome(rule=rule or FactorArmRule(placebo_arms=2), active=list(keys), resolution=resolution_of(*keys), arm_factory=fake_arm, served_block_days=60)


def compete(outcome: FactorOutcome, scenario: Scenario, plan: BacktestPlan | None = None, use_lgbm: bool = True, bt=None):
    return outcome.compete(panel=None, features=FakeBuilder(), plan=plan if plan is not None else plan_with_holdout(), windows=None,
                           bt=scenario.plain() if bt is None else bt, use_lgbm=use_lgbm, seed=1, n_jobs=1)


GOOD = {0: (4.0, 3.0), 1: (4.0, 3.1), 2: (4.0, 3.2)}
BAD = {0: (4.0, 4.2), 1: (4.0, 4.1), 2: (4.0, 4.0)}
PLACEBO_WORSE = {0: (4.0, 3.9), 1: (4.0, 3.9), 2: (4.0, 3.9)}          # el placebo mejora poco: el real lo supera con margen
PLACEBO_AS_GOOD = {0: (4.0, 3.05), 1: (4.0, 3.1), 2: (4.0, 3.15)}      # el placebo es tan bueno como el real: el real NO lo supera


def test_a_factor_that_beats_the_plain_arm_and_its_placebos_wins_and_the_holdout_is_trained_only_afterwards(monkeypatch):
    sc = Scenario({frozenset({"f.uno"}): GOOD, ("placebo", frozenset({"f.uno"})): PLACEBO_WORSE}, holdout=(4.0, 9.0))      # en el período final sería PEOR
    install(monkeypatch, sc)
    outcome = outcome_for("f.uno")
    new_bt, features, windows = compete(outcome, sc)
    plan = plan_with_holdout()
    calls = [(c[0], c[1], tuple(c[2])) for c in sc.calls]
    assert calls[0] == (("f.uno",), False, tuple(plan.cutoffs)), "primero el factor real en las ventanas de selección"
    assert [c[1] for c in calls[1:3]] == [True, True] and all(c[2] == tuple(plan.cutoffs) for c in calls[1:3]), "después sus K=2 placebos, también solo en selección"
    assert calls[3] == (("f.uno",), False, (plan.holdout_cutoff,)) and len(calls) == 4, "el período final se entrena DESPUÉS de ganar"
    assert outcome.decision == SELECTED and outcome.selected == ["f.uno"] and outcome.arm is not None and outcome.holdout_evaluated
    assert outcome.evidence["placebo"]["passed"] is True and outcome.evidence["placebo"]["evaluated"] == 2
    lgbm = new_bt[new_bt["family"] == "lightgbm_global"]
    assert set(lgbm["window"]) == {0, 1, 2, 3} and (lgbm.loc[lgbm["window"] == 3, "yhat"] == 19.0).all(), "el período final mide al modelo servido"
    assert isinstance(features, FakeBuilder) and features.keys == ("f.uno",) and windows is None
    # M2: el período final NO decidió, pero se registra cómo le fue a cada brazo y se avisa de que el brazo con factores es peor.
    hold = outcome.holdout_report
    assert hold["report_only"] is True and hold["factor_better"] is False and hold["wape_factor"] > hold["wape_plain"]
    assert "factor_model_worse_on_holdout" in [x["code"] for x in outcome.limitations]
    report = outcome.report()
    assert report["holdout_used_for_duel"] is False and report["holdout_comparison"]["factor_better"] is False and report["selected_factors"] == ["f.uno"]


def test_changing_only_the_holdout_never_changes_the_decision(monkeypatch):
    results = []
    for holdout in ((4.0, 0.5), (4.0, 50.0)):
        sc = Scenario({frozenset({"f.uno"}): GOOD, ("placebo", frozenset({"f.uno"})): PLACEBO_WORSE}, holdout=holdout)
        install(monkeypatch, sc)
        outcome = outcome_for("f.uno")
        compete(outcome, sc)
        results.append((outcome.decision, outcome.evidence["wape_factor"], outcome.evidence["placebo"]["best_placebo_wape"]))
    assert results[0] == results[1] and results[0][0] == SELECTED


def test_a_factor_as_good_as_its_placebo_is_noise_and_is_not_promoted(monkeypatch):
    sc = Scenario({frozenset({"f.uno"}): GOOD, ("placebo", frozenset({"f.uno"})): PLACEBO_AS_GOOD})
    install(monkeypatch, sc)
    outcome = outcome_for("f.uno")
    new_bt, features, _ = compete(outcome, sc)
    assert outcome.decision == KEPT and outcome.reason == "placebo_not_beaten" and outcome.selected == [] and outcome.arm is None
    assert outcome.evidence["placebo"]["passed"] is False and outcome.evidence["placebo"]["best_placebo_wape"] < outcome.evidence["wape_plain"]
    assert not any(c[2] == [plan_with_holdout().holdout_cutoff] for c in sc.calls), "perdió: el período final reservado ni se entrena"
    assert isinstance(features, FakeBuilder) and features.keys == () and outcome.limitations[0]["code"] == "factor_model_not_selected"
    assert "placebo" in outcome.limitations[0]["message"]
    assert new_bt[new_bt["family"] == "lightgbm_global"]["window"].nunique() == 4


def test_forward_selection_keeps_only_the_factors_that_pass_individually(monkeypatch):
    sc = Scenario({frozenset({"f.bueno"}): GOOD, ("placebo", frozenset({"f.bueno"})): PLACEBO_WORSE, frozenset({"f.ruido"}): BAD})
    install(monkeypatch, sc)
    outcome = outcome_for("f.bueno", "f.ruido")
    compete(outcome, sc)
    assert outcome.decision == SELECTED and outcome.selected == ["f.bueno"] and outcome.combination is None
    assert outcome.per_factor["f.bueno"]["decision"] == SELECTED and outcome.per_factor["f.ruido"]["decision"] == KEPT
    assert outcome.per_factor["f.ruido"]["placebo"] is None, "un factor que ni siquiera pasa la regla no gasta placebos"
    assert outcome.used == ["f.bueno", "f.ruido"] and outcome.arm.features.keys == ("f.bueno",)


def test_a_combination_is_promoted_only_if_every_member_passed_and_the_combination_passes_too(monkeypatch):
    both = frozenset({"f.a", "f.b"})
    members = {frozenset({"f.a"}): GOOD, ("placebo", frozenset({"f.a"})): PLACEBO_WORSE, frozenset({"f.b"}): GOOD, ("placebo", frozenset({"f.b"})): PLACEBO_WORSE}
    # (1) la combinación también pasa ⇒ se sirve la combinación
    sc = Scenario({**members, both: {0: (4.0, 2.5), 1: (4.0, 2.6), 2: (4.0, 2.7)}, ("placebo", both): PLACEBO_WORSE})
    install(monkeypatch, sc)
    outcome = outcome_for("f.a", "f.b")
    compete(outcome, sc)
    assert outcome.decision == SELECTED and outcome.selected == ["f.a", "f.b"] and outcome.combination["decision"] == SELECTED
    # (2) los dos pasan solos pero la combinación NO pasa ⇒ se queda el mejor individual (que sí pasó), nunca la combinación
    sc = Scenario({**members, both: BAD})
    install(monkeypatch, sc)
    outcome = outcome_for("f.a", "f.b")
    compete(outcome, sc)
    assert outcome.decision == SELECTED and len(outcome.selected) == 1 and outcome.combination["decision"] == KEPT and "mejor factor individual" in outcome.combination["fallback"]
    # (3) un miembro no pasa ⇒ la combinación ni se entrena
    sc = Scenario({frozenset({"f.a"}): GOOD, ("placebo", frozenset({"f.a"})): PLACEBO_WORSE, frozenset({"f.b"}): BAD})
    install(monkeypatch, sc)
    outcome = outcome_for("f.a", "f.b")
    compete(outcome, sc)
    assert outcome.selected == ["f.a"] and outcome.combination is None
    assert not any(set(c[0]) == {"f.a", "f.b"} for c in sc.calls)


def test_a_factor_arm_that_fails_or_cannot_cover_the_holdout_keeps_the_plain_model(monkeypatch):
    sc = Scenario({frozenset({"f.uno"}): GOOD, ("placebo", frozenset({"f.uno"})): PLACEBO_WORSE})
    install(monkeypatch, sc, fail_on=lambda features, origins: True)
    outcome = outcome_for("f.uno")
    bt = sc.plain()
    new_bt, _, _ = compete(outcome, sc, bt=bt)
    assert outcome.decision == KEPT and outcome.reason == "factor_arm_failed" and new_bt is bt and "boom" in outcome.evidence["error"]
    plan = plan_with_holdout()
    install(monkeypatch, sc, fail_on=lambda features, origins: origins == [plan.holdout_cutoff])        # ganó en selección pero falla en el período final
    outcome = outcome_for("f.uno")
    new_bt, _, _ = compete(outcome, sc, bt=bt)
    assert outcome.decision == KEPT and outcome.reason == "factor_arm_failed_in_holdout_window" and outcome.selected == [] and new_bt is bt
    import predictily_engine.selection.tuning as tuning

    monkeypatch.setattr(tuning, "lightgbm_backtest_rows", lambda *a, **k: (_ for _ in ()).throw(FeatureContractError("sin contrato")))
    with pytest.raises(FeatureContractError):      # un defecto de programación no se disfraza de fallo del brazo
        compete(outcome_for("f.uno"), sc, bt=bt)


def test_without_a_backtest_or_lightgbm_the_factor_model_is_not_evaluated_and_the_run_says_so():
    sc = Scenario({frozenset({"f.uno"}): GOOD})
    bt = sc.plain()
    for kwargs, reason in (({"bt": pd.DataFrame()}, "no_backtest"), ({"bt": bt, "use_lgbm": False}, "lightgbm_not_used"),
                           ({"bt": bt[bt["family"] != "lightgbm_global"]}, "no_lightgbm_rows")):
        outcome = outcome_for("f.uno")
        new_bt, _, _ = compete(outcome, sc, **kwargs)
        assert outcome.decision == NOT_EVALUATED and outcome.reason == reason and new_bt is kwargs["bt"]
        assert [x["code"] for x in outcome.limitations] == ["factors_not_evaluated"] and "f.uno" in outcome.limitations[0]["message"]
    outcome = outcome_for("f.uno")
    assert outcome.compete(panel=None, features=None, plan=None, windows=None, bt=bt, use_lgbm=True, seed=1, n_jobs=1)[0] is bt
    assert outcome.reason == "no_backtest" and outcome.decision == NOT_EVALUATED
    assert FactorOutcome(rule=RULE, active=["f.uno"]).compete(panel=None, features=None, plan=None, windows=None, bt=bt, use_lgbm=True, seed=1, n_jobs=1)[0] is bt


def test_the_report_says_what_happened_even_when_nothing_was_selected():
    outcome = FactorOutcome(rule=RULE, active=[], reason="no_active_factors")
    report = outcome.report()
    assert report["decision"] == NOT_EVALUATED and report["reason"] == "no_active_factors" and "no tiene ningún factor activado" in report["message"]
    assert report["selected_factors"] == [] and report["active_factors"] == [] and report["holdout_used_for_duel"] is False
    missing = FactorOutcome(rule=RULE, active=["f.uno"], reason="inputs_not_loaded").report()
    assert "no se cargaron" in missing["message"]
    kept = FactorOutcome(rule=RULE, active=["f.uno"], resolution=resolution_of("f.uno"), decision=KEPT, reason="placebo_not_beaten").report()
    assert "Se mantiene el modelo sin factores" in kept["message"] and "placebos" in kept["message"]
    json.dumps(kept, default=str)


def test_the_holdout_flag_reflects_the_policy_that_governs_the_final_selection():
    won = FactorOutcome(rule=RULE, active=["f.uno"], resolution=resolution_of("f.uno"), decision=SELECTED, selected=["f.uno"])
    won.note_downstream("legacy", True)
    assert won.report()["holdout_used_for_decision"] is True and won.report()["holdout_used_for_duel"] is False and won.report()["governing_policy"] == "legacy"
    won.note_downstream("v25", True)
    assert won.report()["holdout_used_for_decision"] is False
    won.note_downstream("legacy", False)
    assert won.report()["holdout_used_for_decision"] is False, "sin período final independiente no hay nada que usar"
    lost = FactorOutcome(rule=RULE, active=["f.uno"], resolution=resolution_of("f.uno"), decision=KEPT)
    lost.note_downstream("legacy", True)
    assert lost.report()["holdout_used_for_decision"] is False


def test_the_report_is_compact_and_the_manifest_adds_the_per_factor_detail():
    outcome = outcome_for("f.uno")
    outcome.decision, outcome.reason = KEPT, "no_sufficient_improvement"
    outcome.evidence = {"scope": "selection_windows", "wape_plain": 0.4, "wape_factor": 0.39, "windows": [{"big": "detail"}]}
    report = outcome.report()
    assert report["decision"] == KEPT and report["factor_model_served"] is False and report["holdout_used_for_duel"] is False
    assert "windows" not in report["evidence"] and report["evidence"]["wape_plain"] == 0.4 and report["used_factors"] == ["f.uno"]
    manifest = outcome.manifest_block()
    assert manifest["evidence"]["windows"] == [{"big": "detail"}] and manifest["resolution"]["used"] == ["f.uno"] and manifest["feature_contracts"] == []


def test_the_lineage_fingerprint_changes_with_the_factor_values_and_only_the_served_model_folds_into_the_hashes():
    """M11: la huella de los factores NO es una constante: cambia con los valores visibles y con la definición; solo el modelo servido modifica los hashes."""
    def outcome_with(values, definition_ttl=7):
        res = resolution_of("f.uno")
        res.columns["factor__f.uno"] = values
        res.definitions["f.uno"] = {"key": "f.uno", "max_staleness_days": definition_ttl, "availability_class": "OBSERVED_HISTORICAL"}
        return FactorOutcome(rule=RULE, active=["f.uno"], resolution=res, served_block_days=30)

    a, same, other = outcome_with([1.0, 2.0, 3.0, 4.0, 5.0]), outcome_with([1.0, 2.0, 3.0, 4.0, 5.0]), outcome_with([1.0, 2.0, 3.0, 4.0, 6.0])
    redefined = outcome_with([1.0, 2.0, 3.0, 4.0, 5.0], definition_ttl=30)
    assert a.fingerprint() == same.fingerprint() and a.fingerprint() != other.fingerprint() and a.fingerprint() != redefined.fingerprint()
    assert a.factor_fingerprints()["f.uno"] != other.factor_fingerprints()["f.uno"] and len(a.fingerprint()) == 64
    block = a.snapshot_block()
    assert block["enabled"] is True and block["factors"][0]["key"] == "f.uno" and block["factors"][0]["fingerprint"] == a.factor_fingerprints()["f.uno"]
    assert block["factors"][0]["definition_version"] == a.resolution.definition_version("f.uno") != redefined.resolution.definition_version("f.uno")
    assert block["factors"][0]["active"] is True and block["factors"][0]["selected"] is False
    # Hashes: solo cuando el modelo con factores se SIRVE.
    assert a.fold_training_hash("base") == "base" and a.registry_compat() is None and a.feature_set_version("fs-x") == "fs-x"
    for o in (a, other):
        o.decision, o.selected = SELECTED, ["f.uno"]
        o.arm = fake_arm(o.resolution)
        o.finalize({"lightgbm_global"}, set())
    assert a.served and a.fold_training_hash("base") != "base" and a.fold_training_hash("base") != other.fold_training_hash("base")
    assert a.registry_compat()["fingerprint"] == a.fingerprint() and a.registry_compat()["keys"] == ["f.uno"]
    assert a.feature_set_version("fs-2026.10-v2").startswith("fs-2026.10-v2+factors:") and len(a.feature_set_version("fs-2026.10-v2")) <= 40


def test_the_factor_model_counts_as_served_only_if_it_won_and_lightgbm_is_really_served():
    probe = FactorOutcome(rule=RULE, active=["f.uno"], decision=SELECTED)
    probe.finalize({"lightgbm_global"}, set())
    assert probe.served is True
    probe.finalize({"lightgbm_global"}, {"lightgbm_global"})            # el entrenamiento final falló: se sirve el respaldo
    assert probe.served is False
    probe.finalize({"seasonal_naive"}, set())                           # ganó el brazo, pero se sirve un estadístico
    assert probe.served is False
    probe.decision = KEPT
    probe.finalize({"lightgbm_global"}, set())
    assert probe.served is False


def test_the_run_fingerprint_records_the_factor_config_only_when_the_pipeline_is_on():
    assert factor_config_record({}, None) is None and factor_config_record({"factor_activation": {"a": {"active": True}}}) is None
    assert factor_config_record({"cores": {"factors": {"enabled": True, "indicator_precedence": {"a": "x"}}}}) is None, "configuración inválida: se ignora"
    record = factor_config_record({"cores": {"factors": {"enabled": True}}, "factor_activation": {"b": {"active": True}, "a": {"active": True}, "c": {"active": False}}})
    assert record["active_factors"] == ["a", "b"] and record["config"]["enabled"] is True and record["config"]["arm_min_improvement"] == 0.03
    assert record["config"]["arm_placebo_arms"] == 5 and record["provenance"]["enabled"] == "tenant" and record["core_config_version"]


# ───────────────────────── carga de insumos: apagado no consulta nada ─────────────────────────

class UntouchableRepo:
    def __getattr__(self, name):
        raise AssertionError(f"el pipeline de factores apagado no debe tocar el repositorio ({name})")


class FakeConn:
    def __init__(self):
        self.savepoints = 0

    @contextlib.contextmanager
    def begin_nested(self):
        self.savepoints += 1
        yield


class FakeReader:
    def __init__(self):
        self.seen: list[tuple] = []

    def resolve_definition(self, key, precedence=None):
        self.seen.append(("definition", key, precedence))
        if key == "f.ambiguous":
            raise AmbiguousDefinitionError("pública y de la empresa")
        if key == "f.boom":
            raise RuntimeError("conexión perdida")
        schema = {"indicator_code": "IPC"} if key == "f.ipc" else {}
        return {"key": key, "storage": "external_indicator_observations", "schema": schema, "availability_class": "OBSERVED_HISTORICAL"}

    def observations_for(self, definition, precedence=None):
        self.seen.append(("observations", definition["key"], precedence))
        if definition["key"] == "f.nolect":
            raise NotImplementedError("almacenamiento nativo")
        if definition["key"] == "f.dbfail":
            raise RuntimeError("deadlock")
        return pd.DataFrame({"period_date": [DAY0], "value": [1.0]})


class FakeRepo:
    def __init__(self):
        self.conn = FakeConn()
        self.reader = FakeReader()

    def factors(self):
        return self.reader


def tenant_of(settings: dict) -> dict:
    return {"settings": settings, "sector": None}


def test_a_switched_off_pipeline_loads_nothing_and_never_touches_the_database():
    for settings in ({}, {"factor_activation": {"a": {"active": True}}}, {"cores": {"factors": {"enabled": True}}},
                     {"cores": {"factors": {"enabled": True}}, "factor_activation": {"a": {"active": False}}},
                     {"cores": {"factors": {"enabled": True, "indicator_precedence": {"a": "x"}}}, "factor_activation": {"a": {"active": True}}}):
        assert load_factor_inputs(UntouchableRepo(), tenant_of(settings)) is None, settings
    data = TenantData(tenant_id=1, settings={}, cutoff=DAY0.date(), branches=pd.DataFrame(), products=pd.DataFrame(), categories=pd.DataFrame(), sales=pd.DataFrame())
    assert data.factor_inputs is None and data.timezone is None


def test_enabled_and_active_factors_are_read_once_with_the_declared_precedence_and_read_failures_are_kept():
    repo = FakeRepo()
    settings = {"cores": {"factors": {"enabled": True, "indicator_precedence": {"IPC": "tenant"}, "definition_precedence": {"f.ipc": "public"}}},
                "factor_activation": {key: {"active": True} for key in ("f.ipc", "f.ambiguous", "f.boom", "f.nolect", "f.dbfail")}}
    inputs = load_factor_inputs(repo, tenant_of(settings))
    assert inputs.keys == sorted(["f.ipc", "f.ambiguous", "f.boom", "f.nolect", "f.dbfail"])
    assert ("definition", "f.ipc", "public") in repo.reader.seen and ("observations", "f.ipc", "tenant") in repo.reader.seen
    assert inputs.resolve_definition("f.ipc")["key"] == "f.ipc" and list(inputs.observations_for({"key": "f.ipc"}).columns) == ["period_date", "value"]
    with pytest.raises(AmbiguousDefinitionError):
        inputs.resolve_definition("f.ambiguous")
    with pytest.raises(FactorReadError, match="RuntimeError"):
        inputs.resolve_definition("f.boom")
    with pytest.raises(NotImplementedError):
        inputs.observations_for({"key": "f.nolect"})
    with pytest.raises(FactorReadError, match="deadlock"):
        inputs.observations_for({"key": "f.dbfail"})
    with pytest.raises(LookupError):
        inputs.resolve_definition("f.desconocido")
    with pytest.raises(LookupError):
        inputs.observations_for({"key": "f.desconocido"})
    assert repo.conn.savepoints >= 5, "cada lectura va en su SAVEPOINT: un fallo no envenena la transacción de la ejecución"


def test_explicit_keys_are_loaded_even_with_the_switch_off_for_the_ablation_tool():
    repo = FakeRepo()
    inputs = load_factor_inputs(repo, tenant_of({}), keys=["f.ipc", "f.ipc", "f.otro"])
    assert inputs is not None and inputs.keys == ["f.ipc", "f.otro"]
    assert load_factor_inputs(repo, tenant_of({}), keys=[]) is None
    assert isinstance(inputs, FactorInputs) and np.isfinite(inputs.observations["f.ipc"]["value"]).all()
