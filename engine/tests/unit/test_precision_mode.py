"""Modo de precisión automático (`demand.precision_mode = "auto"`, `pipeline/precision.py`).

En `manual` (por defecto) nada cambia. En `auto` el motor enciende los interruptores que solo agregan candidatos, elige con la regla del
total diario y adopta el ajuste de feriados solo si gana en las ventanas de selección con el margen configurado.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.models.registry import ENSEMBLE_FAMILY, FAMILIES, WEEKDAY_AVERAGE
from predictily_engine.pipeline import precision
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides
from predictily_engine.selection.selector import LEGACY_RULE_VERSION_V2, LegacyPolicy

N_SERIES, DAYS, WINDOWS, LEVEL = 8, 28, 4, 30.0
START = pd.Timestamp("2026-03-02")
HOLIDAY_STEP = 10      # un feriado por ventana: ese día se vende la mitad


def _backtest(adjusted_error: float, plain_error: float = 0.5, holdout_only: bool = False, series_noise: float = 0.0, seed: int = 5
              ) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Backtests sin y con ajuste de feriados. Fuera del feriado todo modelo acierta; en el feriado AutoETS falla en `plain_error` (fracción
    del nivel) sin ajuste y en `adjusted_error` con él. `holdout_only`: el feriado solo cae en el período final. `series_noise`: ruido que
    se suma a la variante ajustada en días normales (empeora el error por serie sin tocar casi el total)."""
    rng = np.random.default_rng(seed)
    plain, adjusted = [], []
    for w in range(WINDOWS):
        is_holdout = w == WINDOWS - 1
        ds = pd.date_range(START + pd.Timedelta(days=28 * w), periods=DAYS, freq="D")
        holiday = (np.arange(1, DAYS + 1) == HOLIDAY_STEP) & (is_holdout or not holdout_only)
        for s in range(N_SERIES):
            actual = np.where(holiday, LEVEL / 2, LEVEL)
            base = {"unique_id": f"b1-p{s}", "window": w, "ds": ds, "step": np.arange(1, DAYS + 1), "actual": actual, "stockout": False,
                    "is_holdout": is_holdout, "demand_class": "smooth"}
            noise = rng.choice([-1.0, 1.0], DAYS) * series_noise * LEVEL
            for fam, target in (("auto_ets", plain), ("window_average", plain), ("seasonal_naive", plain), ("lightgbm_global", plain)):
                err = plain_error if fam in ("auto_ets", "window_average") else 0.3
                target.append(pd.DataFrame({**base, "family": fam, "yhat": actual + np.where(holiday, err * LEVEL, 0.0)}))
            for fam in ("auto_ets", "window_average"):
                adjusted.append(pd.DataFrame({**base, "family": fam, "yhat": actual + np.where(holiday, adjusted_error * LEVEL, noise)}))
    return pd.concat(plain, ignore_index=True), pd.concat(adjusted, ignore_index=True)


# ───────────────────────── configuración ─────────────────────────

def test_mode_is_manual_by_default_and_validated():
    assert CORE_DEFAULTS["demand"]["precision_mode"] == "manual"
    assert validate_overrides("demand", {"precision_mode": "auto", "precision_auto_margin": 0.02}) == []
    assert [e["key"] for e in validate_overrides("demand", {"precision_mode": "siempre"})] == ["precision_mode"]
    assert [e["key"] for e in validate_overrides("demand", {"precision_auto_margin": 0.9})] == ["precision_auto_margin"]


def test_auto_turns_on_the_candidate_switches_without_touching_the_original_config():
    cfg = dict(CORE_DEFAULTS["demand"])
    assert not precision.is_auto(cfg) and precision.is_auto({**cfg, "precision_mode": "auto"})
    auto = precision.auto_demand_config(cfg)
    assert auto["weekday_profile_models"] is True and auto["ensemble_models"] is True
    assert cfg["weekday_profile_models"] is False and cfg["ensemble_models"] is False
    assert auto["holiday_adjustment_models"] is False, "el ajuste de feriados no se enciende: se decide con el backtest"
    assert precision.auto_promotion_config({"legacy_selection_metric": "series_day"})["legacy_selection_metric"] == "total_day"


def test_trial_families_are_the_statistical_ones_that_the_adjustment_changes():
    assert precision.trial_families(["naive", "seasonal_naive", "auto_ets", "window_average_dow", "lightgbm_global"]) == [
        "auto_ets", "window_average_dow"]


# ───────────────────────── decisión del ajuste de feriados ─────────────────────────

def test_adjustment_is_adopted_when_it_lowers_the_total_day_error_beyond_the_margin():
    plain, adjusted = _backtest(adjusted_error=0.05)
    decision = precision.decide_holiday_adjustment(plain, adjusted, margin=0.01)
    assert decision.adopted and decision.reason == precision.REASON_ADOPTED
    ev = decision.evidence
    assert ev["adjusted"]["total_day_wape"] < ev["plain"]["total_day_wape"] and ev["relative_change"] < -0.01
    assert ev["windows"] == WINDOWS - 1, "solo deciden las ventanas de selección"


def test_adjustment_is_not_adopted_when_the_gain_is_below_the_margin():
    plain, adjusted = _backtest(adjusted_error=0.49)
    decision = precision.decide_holiday_adjustment(plain, adjusted, margin=0.05)
    assert not decision.adopted and decision.reason == precision.REASON_NO_GAIN


def test_adjustment_is_not_adopted_when_it_is_worse():
    plain, adjusted = _backtest(adjusted_error=0.9)
    assert precision.decide_holiday_adjustment(plain, adjusted).reason == precision.REASON_NO_GAIN


def test_without_holidays_in_the_selection_windows_there_is_no_evidence():
    plain, adjusted = _backtest(adjusted_error=0.05, holdout_only=True)
    decision = precision.decide_holiday_adjustment(plain, adjusted)
    assert not decision.adopted and decision.reason == precision.REASON_NO_EVIDENCE, "el período final reservado nunca decide"


def test_adjustment_is_rejected_when_the_error_per_series_gets_worse():
    plain, adjusted = _backtest(adjusted_error=0.05, series_noise=0.05)
    decision = precision.decide_holiday_adjustment(plain, adjusted, margin=0.01)
    assert decision.evidence["adjusted"]["series_day_wape"] > decision.evidence["plain"]["series_day_wape"] * (1 + precision.SERIES_SLACK)
    assert not decision.adopted and decision.reason == precision.REASON_NO_GAIN


def test_empty_backtests_give_no_evidence():
    plain, adjusted = _backtest(adjusted_error=0.05)
    assert precision.decide_holiday_adjustment(plain, adjusted.iloc[0:0]).reason == precision.REASON_NO_EVIDENCE
    assert precision.decide_holiday_adjustment(plain.iloc[0:0], adjusted).reason == precision.REASON_NO_EVIDENCE


def test_replace_family_rows_swaps_only_the_adjusted_families():
    plain, adjusted = _backtest(adjusted_error=0.05)
    out = precision.replace_family_rows(plain, adjusted)
    assert len(out) == len(plain) and set(out["family"]) == set(plain["family"])
    keys = ["unique_id", "window", "ds"]
    for fam, source in (("auto_ets", adjusted), ("window_average", adjusted), ("seasonal_naive", plain), ("lightgbm_global", plain)):
        got = out[out["family"] == fam].sort_values(keys)["yhat"].to_numpy()
        assert np.array_equal(got, source[source["family"] == fam].sort_values(keys)["yhat"].to_numpy())
    assert precision.replace_family_rows(plain, adjusted.iloc[0:0]) is plain


# ───────────────────────── en el pipeline ─────────────────────────

def _settings(data, demand: dict, promotion: dict | None = None):
    cores = {"demand": demand, **({"promotion": promotion} if promotion else {})}
    return replace(data, settings={**(data.settings or {}), "cores": cores})


@pytest.fixture(scope="module")
def auto_run(small_data):
    return run_pipeline(_settings(small_data, {"precision_mode": "auto"}), horizon=90, seed=42, n_jobs=4)


def test_manual_mode_leaves_the_run_exactly_as_before(pipeline_result):
    assert "precision" not in pipeline_result.summary
    assert pipeline_result.effective_config["config"]["precision_mode"] == "manual"
    assert ENSEMBLE_FAMILY not in set(pipeline_result.backtest["family"])


def test_auto_mode_enables_the_candidates_and_the_total_day_rule(auto_run):
    cfg = auto_run.effective_config
    assert cfg["config"]["precision_mode"] == "auto"
    assert cfg["config"]["weekday_profile_models"] is True and cfg["config"]["ensemble_models"] is True
    expected = LegacyPolicy(selection_metric="total_day", min_improvement=cfg["run"]["min_improvement"])
    assert expected.rule_version == LEGACY_RULE_VERSION_V2 and cfg["promotion"]["policy_versions"]["legacy"] == expected.version
    families = set(auto_run.backtest["family"])
    assert {ENSEMBLE_FAMILY, WEEKDAY_AVERAGE} <= families
    served = set(auto_run.forecast_values["model_family"].dropna())
    assert served and served <= set(FAMILIES)


def test_auto_mode_records_the_holiday_decision_and_applies_it_consistently(auto_run):
    block = auto_run.summary["precision"]
    assert block["mode"] == "auto" and block["selection_metric"] == "total_day"
    assert block["candidates_enabled"] == ["ensemble_models", "weekday_profile_models"]
    holiday = block["holiday_adjustment"]
    assert holiday["reason"] in {precision.REASON_ADOPTED, precision.REASON_NO_GAIN, precision.REASON_NO_EVIDENCE}
    assert holiday["adopted"] == (holiday["reason"] == precision.REASON_ADOPTED)
    adjusted_note = any("se ajustan por feriados" in a for a in auto_run.summary["assumptions"])
    assert adjusted_note == holiday["adopted"], "la nota del ajuste aparece solo si el ajuste se adoptó"


def test_when_the_adjustment_is_adopted_the_run_equals_the_one_with_every_switch_on(small_data, monkeypatch):
    """Adoptado el ajuste, el backtest y lo servido son los de una empresa que encendió los cuatro interruptores a mano."""
    manual = run_pipeline(_settings(small_data, {"weekday_profile_models": True, "ensemble_models": True, "holiday_adjustment_models": True},
                                    {"legacy_selection_metric": "total_day"}), horizon=90, seed=42, n_jobs=4)
    monkeypatch.setattr(precision, "decide_holiday_adjustment",
                        lambda plain, adjusted, margin=0.01: precision.HolidayDecision(True, precision.REASON_ADOPTED, {"forced": True}))
    auto = run_pipeline(_settings(small_data, {"precision_mode": "auto"}), horizon=90, seed=42, n_jobs=4)
    assert auto.summary["precision"]["holiday_adjustment"] == {"adopted": True, "reason": precision.REASON_ADOPTED, "forced": True}
    keys = ["family", "unique_id", "window", "ds"]
    a = auto.backtest.sort_values(keys).reset_index(drop=True)
    m = manual.backtest.sort_values(keys).reset_index(drop=True)
    assert len(a) == len(m) and (a[keys].to_numpy() == m[keys].to_numpy()).all()
    assert np.allclose(a["yhat"].to_numpy(float), m["yhat"].to_numpy(float), rtol=1e-9, atol=1e-9)
    assert auto.summary["models"] == manual.summary["models"]
    fk = ["node_key", "granularity", "target_date"] if "node_key" in auto.forecast_values else list(auto.forecast_values.columns[:3])
    fa = auto.forecast_values.sort_values(fk)["yhat"].to_numpy(float)
    fm = manual.forecast_values.sort_values(fk)["yhat"].to_numpy(float)
    assert len(fa) == len(fm) and np.allclose(fa, fm, rtol=1e-9, atol=1e-9)


def test_auto_mode_respects_a_holiday_switch_the_company_already_turned_on(small_data, monkeypatch):
    def fail(*args, **kwargs):
        raise AssertionError("con el interruptor encendido por la empresa no se compara")

    monkeypatch.setattr(precision, "decide_holiday_adjustment", fail)
    run = run_pipeline(_settings(small_data, {"precision_mode": "auto", "holiday_adjustment_models": True}), horizon=90, seed=42, n_jobs=4)
    assert run.summary["precision"]["holiday_adjustment"] == {"adopted": True, "reason": precision.REASON_ALREADY_ON}
    assert any("se ajustan por feriados" in a for a in run.summary["assumptions"])
