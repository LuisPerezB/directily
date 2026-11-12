"""Regla v2 de la política heredada (`legacy_selection_metric = total_day`): selección con el WAPE del total diario.

El WAPE serie-día está cerca del piso de ruido de los conteos: dos modelos buenos difieren ahí en 1–2 % aunque en el total del negocio
difieran 15–30 %, y un modelo plano (que no reparte la semana) puede ganarle por décimas a uno que sí la reparte. La regla v2 ordena a los
candidatos por el error del total diario y conserva dos salvaguardas (sesgo y error por serie). Con el valor por defecto (`series_day`) la
política es EXACTAMENTE la v1: mismos parámetros y misma huella (la paridad de la selección está en `test_selection_legacy_parity.py`).
"""

from __future__ import annotations

import hashlib
import json

import numpy as np
import pandas as pd
import pytest

from predictily_engine.platform.config import resolve_config, validate_overrides
from predictily_engine.selection.promotion import PromotionConfig, _legacy_gates
from predictily_engine.selection.selector import (
    LEGACY_RULE_VERSION,
    LEGACY_RULE_VERSION_V2,
    LegacyPolicy,
    select_models,
    total_day_wape,
)

N_SERIES, DAYS, WINDOWS, LEVEL = 20, 28, 3, 20.0
WEEKDAY = np.array([1.15, 1.10, 1.05, 1.00, 0.95, 0.90, 0.85])   # ritmo semanal del negocio (media 1)
START = pd.Timestamp("2026-03-02")                                # lunes
V2 = LegacyPolicy(selection_metric="total_day")


def _backtest(extra: dict | None = None, seed: int = 7) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Negocio con ritmo semanal y ruido de conteo independiente por serie.

    - `window_average`: nivel exacto pero PLANO (no reparte la semana): por serie-día casi no se nota (el ruido domina), en el total sí.
    - `auto_ets`: reparte la semana, con un error propio e independiente por serie (se cancela al sumar las series).
    - `seasonal_naive`: reparte la semana con el doble de ruido.
    `extra`: familias adicionales {familia: función(media con ritmo, real, índice de serie, rng) → pronóstico}."""
    rng = np.random.default_rng(seed)
    rows = []
    for w in range(WINDOWS):
        ds = pd.date_range(START + pd.Timedelta(days=28 * w), periods=DAYS, freq="D")
        mean = LEVEL * WEEKDAY[ds.dayofweek.to_numpy()]
        for s in range(N_SERIES):
            actual = rng.poisson(mean).astype(float)
            preds = {
                "window_average": np.full(DAYS, LEVEL),
                "auto_ets": mean + rng.normal(0, 2.3, DAYS),
                "seasonal_naive": mean + rng.normal(0, 4.5, DAYS),
            }
            for fam, fn in (extra or {}).items():
                preds[fam] = fn(mean, actual, s, rng)
            for fam, yhat in preds.items():
                rows.append(pd.DataFrame({"unique_id": f"b1-p{s}", "window": w, "ds": ds, "step": np.arange(1, DAYS + 1), "family": fam,
                                          "actual": actual, "yhat": np.clip(yhat, 0, None), "stockout": False, "scale": LEVEL,
                                          "demand_class": "smooth"}))
    profile = pd.DataFrame({"unique_id": [f"b1-p{s}" for s in range(N_SERIES)], "demand_class": "smooth"})
    return pd.concat(rows, ignore_index=True), profile


def _table(sel) -> pd.DataFrame:
    return sel.pooled_table.set_index("family")


def test_default_policy_keeps_the_v1_parameters_and_fingerprint():
    policy = LegacyPolicy()
    expected = {"min_improvement": 0.03, "windows_won_fraction": 2 / 3, "min_class_series": 3, "min_class_actual": 30,
                "rule_version": LEGACY_RULE_VERSION}
    assert policy.params() == expected
    assert policy.version == hashlib.sha256(json.dumps({"policy": "legacy", **expected}, sort_keys=True).encode()).hexdigest()
    assert set(policy.describe()) == {"min_improvement", "windows_won_fraction", "rule_version", "policy_version"}
    # con la métrica serie-día las salvaguardas de la v2 no intervienen ni cambian la huella
    assert LegacyPolicy(bias_tolerance=0.5, series_tolerance=0.2).version == policy.version


def test_v2_policy_has_its_own_rule_version_and_fingerprint():
    assert V2.by_total and V2.rule_version == LEGACY_RULE_VERSION_V2
    assert V2.params()["selection_metric"] == "total_day" and V2.params()["bias_tolerance"] == 0.15
    assert V2.version != LegacyPolicy().version
    assert LegacyPolicy(selection_metric="total_day", bias_tolerance=0.1).version != V2.version
    with pytest.raises(ValueError):
        LegacyPolicy(selection_metric="otra")
    with pytest.raises(ValueError):
        LegacyPolicy(bias_tolerance=-0.1)


def test_total_day_wape_sums_the_series_before_measuring():
    rows = pd.DataFrame({"family": "f", "window": 0, "ds": pd.to_datetime(["2026-01-01"] * 2 + ["2026-01-02"] * 2),
                         "unique_id": ["a", "b", "a", "b"], "actual": [10.0, 10.0, 10.0, 10.0], "yhat": [14.0, 6.0, 12.0, 12.0]})
    # día 1: los errores (+4 y −4) se cancelan en el total; día 2: el total se pasa por 4 de 20
    assert total_day_wape(rows, ["family"])["wape_total"].iloc[0] == pytest.approx(4 / 40)
    per_window = total_day_wape(rows.assign(window=[0, 0, 1, 1]), ["family", "window"]).set_index("window")["wape_total"]
    assert per_window.to_dict() == pytest.approx({0: 0.0, 1: 0.2})
    empty = total_day_wape(rows.assign(actual=0.0), ["family"])
    assert np.isnan(empty["wape_total"].iloc[0])


def test_series_day_metric_prefers_the_flat_model_and_total_day_the_one_that_follows_the_week():
    bt, profile = _backtest()
    v1 = select_models(bt, profile)
    v2 = select_models(bt, profile, policy=V2)
    t = _table(v2)
    # el caso que motiva la regla: por serie-día el plano es (apenas) mejor; en el total diario es el doble de malo
    assert t.loc["window_average", "wape"] < t.loc["auto_ets", "wape"] < t.loc["window_average", "wape"] * 1.05
    assert t.loc["auto_ets", "wape_total"] < 0.7 * t.loc["window_average", "wape_total"]
    assert v1.winners["pooled"] == v1.winners["smooth"] == "window_average"
    assert v2.winners["pooled"] == v2.winners["smooth"] == "auto_ets"
    assert "total diario" in v2.reasons["smooth"]
    ev = v2.evidence["smooth"]
    assert ev["metric"] == "total_day" and ev["best_statistical"] == "auto_ets" and ev["bias_guard_relaxed"] is False
    assert ev["best_statistical_wape"] == pytest.approx(v2.class_table.set_index("family").loc["auto_ets", "wape_total"])
    assert v2.policy_version == V2.version and v1.policy_version == LegacyPolicy().version
    # el respaldo simple también se ordena con la métrica de la política
    assert v1.fallback == "window_average" and v2.fallback == "seasonal_naive"


def test_statistical_candidate_outside_the_series_tolerance_is_not_eligible():
    # errores que se cancelan entre series: total perfecto, pero cada serie queda lejos (no sirve para reponer producto a producto)
    cancels = lambda mean, actual, s, rng: actual + (6.0 if s % 2 == 0 else -6.0)   # noqa: E731
    bt, profile = _backtest({"croston_sba": cancels})
    sel = select_models(bt, profile, policy=V2)
    t = _table(sel)
    assert t.loc["croston_sba", "wape_total"] < 0.01 and t.loc["croston_sba", "wape"] > t.loc["auto_ets", "wape"] * 1.05
    assert sel.winners["pooled"] == "auto_ets"
    assert "croston_sba" in sel.evidence["pooled"]["statistical_outside_guards"]
    # con la tolerancia abierta, el mismo candidato sí gana por el total
    loose = select_models(bt, profile, policy=LegacyPolicy(selection_metric="total_day", series_tolerance=1.0))
    assert loose.winners["pooled"] == "croston_sba"


def test_biased_candidate_is_excluded_and_the_guard_is_relaxed_only_when_nobody_meets_it():
    low = lambda mean, actual, s, rng: 0.7 * mean      # noqa: E731  (subestima 30 % de forma sistemática)
    bt, profile = _backtest({"tsb": low})
    sel = select_models(bt, profile, policy=LegacyPolicy(selection_metric="total_day", series_tolerance=1.0))
    assert sel.winners["pooled"] == "auto_ets" and "tsb" in sel.evidence["pooled"]["statistical_outside_guards"]
    # si TODOS los estadísticos están sesgados no se queda sin modelo: elige el mejor por el total y lo deja registrado
    only_biased = bt[bt["family"].isin(["tsb", "seasonal_naive"])].copy()
    only_biased.loc[only_biased["family"] == "seasonal_naive", "yhat"] *= 1.4
    sel = select_models(only_biased, profile, policy=LegacyPolicy(selection_metric="total_day", series_tolerance=1.0))
    assert sel.evidence["pooled"]["bias_guard_relaxed"] is True
    assert sel.winners["pooled"] in ("tsb", "seasonal_naive") and "sin esa salvaguarda" in sel.reasons["pooled"]


def test_lightgbm_needs_margin_stability_and_both_guards_on_the_total_metric():
    good = lambda mean, actual, s, rng: mean + rng.normal(0, 0.8, len(mean))          # noqa: E731
    bt, profile = _backtest({"lightgbm_global": good})
    sel = select_models(bt, profile, policy=V2)
    ev = sel.evidence["smooth"]
    assert sel.winners["smooth"] == "lightgbm_global" and ev["outcome"] == "lightgbm_global"
    assert ev["passes_improvement"] and ev["passes_windows"] and ev["passes_guards"]
    assert ev["windows_total"] == WINDOWS and ev["windows_better"] >= ev["windows_needed"] == 2
    assert _legacy_gates(ev)["guards"]["status"] == "pass"

    # mejor total (errores que se cancelan) pero cada serie mucho peor: no reemplaza al estadístico
    cancels = lambda mean, actual, s, rng: actual + (6.0 if s % 2 == 0 else -6.0)     # noqa: E731
    bt, profile = _backtest({"lightgbm_global": cancels})
    sel = select_models(bt, profile, policy=V2)
    ev = sel.evidence["smooth"]
    assert sel.winners["smooth"] == "auto_ets"
    assert ev["passes_improvement"] and ev["passes_windows"] and ev["passes_guards"] is False and ev["passes_series_guard"] is False
    assert "salvaguarda" in sel.reasons["smooth"]
    assert _legacy_gates(ev)["guards"]["status"] == "fail"

    # igual que el estadístico (sin margen): tampoco
    same = lambda mean, actual, s, rng: mean + rng.normal(0, 2.3, len(mean))          # noqa: E731
    bt, profile = _backtest({"lightgbm_global": same})
    sel = select_models(bt, profile, policy=V2)
    assert sel.winners["smooth"] == "auto_ets" and "no superó" in sel.reasons["smooth"]


def test_v1_gates_do_not_gain_a_guards_entry():
    bt, profile = _backtest({"lightgbm_global": lambda mean, actual, s, rng: mean})
    sel = select_models(bt, profile)
    assert "metric" not in sel.evidence["smooth"] and "guards" not in _legacy_gates(sel.evidence["smooth"])
    assert "wape_total" not in sel.pooled_table


def test_metric_is_a_validated_promotion_setting_off_by_default():
    cfg, *_ = resolve_config("promotion", {})
    assert cfg["legacy_selection_metric"] == "series_day"
    assert PromotionConfig.from_config(cfg).legacy == LegacyPolicy()
    errors = {e["key"]: e["code"] for e in validate_overrides(
        "promotion", {"legacy_selection_metric": "weekly", "legacy_bias_tolerance": 9, "legacy_series_tolerance": -1})}
    assert errors == {"legacy_selection_metric": "invalid_choice", "legacy_bias_tolerance": "out_of_range",
                      "legacy_series_tolerance": "out_of_range"}
    cfg, *_ = resolve_config("promotion", {}, tenant_overrides={"legacy_selection_metric": "total_day", "legacy_bias_tolerance": 0.1})
    policy = PromotionConfig.from_config(cfg, min_improvement=0.03).legacy
    assert policy.by_total and policy.bias_tolerance == 0.1 and policy.series_tolerance == 0.05
