"""W3-B · la política heredada parametrizada da EXACTAMENTE la misma selección que el selector anterior (ADR-V25-21).

El oráculo es `legacy_selector_reference.py`: copia literal del selector del commit 4eb34f4. Con los valores por defecto (mejora mínima 3 %,
⌈2n/3⌉ ventanas) ambos deben coincidir en ganadores, respaldo, razones, clases agregadas y tablas, sobre backtests sintéticos de todas las
formas relevantes y sobre los backtests reales de la DEMO (los mismos que produce el pipeline).
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd
import pytest

import legacy_selector_reference as reference
from predictily_engine.platform.config import ConfigValidationError, resolve_config, validate_overrides
from predictily_engine.selection.selector import (
    DEFAULT_WINDOWS_WON_FRACTION,
    LEGACY_RULE_VERSION,
    LegacyPolicy,
    select_models,
    selected_rows,
)

FAMILY_ERR = {"seasonal_naive": 4.0, "naive": 4.6, "window_average": 3.5, "auto_ets": 3.0, "croston_sba": 3.4, "adida": 3.8, "tsb": 3.6}


def make_backtest(lgbm_factor: float, windows: int = 3, class_sizes: dict[str, int] | None = None, days: int = 28, seed: int = 0,
                  class_column: bool = True, stockouts: bool = False) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Backtest sintético (todas las familias) y su perfil. `lgbm_factor` escala el error de LightGBM respecto de auto_ets."""
    class_sizes = class_sizes or {"smooth": 6}
    rng = np.random.default_rng(seed)
    rows, profile, uid = [], [], 0
    for cls, n in class_sizes.items():
        for _ in range(n):
            u = f"b1-p{uid}"
            profile.append({"unique_id": u, "demand_class": cls})
            uid += 1
            for w in range(windows):
                actual = rng.poisson(10, days).astype(float) + 10
                z = rng.normal(0, 1, days)
                out = rng.random(days) < 0.05 if stockouts else np.zeros(days, bool)
                for fam, err in {**FAMILY_ERR, "lightgbm_global": 3.0 * lgbm_factor}.items():
                    for d in range(days):
                        row = {"unique_id": u, "window": w, "step": d + 1, "family": fam, "actual": actual[d],
                               "yhat": max(actual[d] + z[d] * err, 0), "stockout": bool(out[d]), "scale": 10.0}
                        if class_column:
                            row["demand_class"] = cls
                        rows.append(row)
    return pd.DataFrame(rows), pd.DataFrame(profile)


def assert_same_selection(new, ref) -> None:
    assert new.winners == ref.winners
    assert new.fallback == ref.fallback
    assert new.reasons == ref.reasons
    assert new.used_pooled == ref.used_pooled
    pd.testing.assert_frame_equal(new.class_table, ref.class_table)
    pd.testing.assert_frame_equal(new.pooled_table, ref.pooled_table)


# ───────────────────────────── parámetros y rango ─────────────────────────────

def test_default_policy_is_the_legacy_rule():
    p = LegacyPolicy()
    assert p.min_improvement == 0.03 and p.windows_won_fraction == DEFAULT_WINDOWS_WON_FRACTION == 2 / 3
    assert [p.needed_windows(n) for n in (1, 2, 3, 4, 5)] == [1, 2, 2, 3, 4], "en producción (3 ventanas + período final = 4) se exigen 3 de 4"


def test_needed_windows_equals_ceil_two_thirds_for_every_window_count():
    """La rama de punto flotante no puede subir una ventana (n × 2/3 con n múltiplo de 3 es exacto)."""
    policy = LegacyPolicy()
    assert all(policy.needed_windows(n) == math.ceil(n * 2 / 3) for n in range(0, 2000))


def test_parameters_are_range_validated_in_the_policy_and_in_the_config():
    for bad in ({"min_improvement": -0.01}, {"min_improvement": 0.6}, {"windows_won_fraction": 0.4}, {"windows_won_fraction": 1.2}):
        with pytest.raises(ValueError):
            LegacyPolicy(**bad)
    LegacyPolicy(min_improvement=0.0, windows_won_fraction=0.5)
    LegacyPolicy(min_improvement=0.5, windows_won_fraction=1.0)
    errors = {e["key"]: e["code"] for e in validate_overrides("demand", {"min_improvement": 0.9})}
    assert errors == {"min_improvement": "out_of_range"}
    errors = {e["key"]: e["code"] for e in validate_overrides("promotion", {"legacy_windows_won_fraction": 0.3, "governing_policy": "otra"})}
    assert errors == {"legacy_windows_won_fraction": "out_of_range", "governing_policy": "invalid_choice"}
    with pytest.raises(ConfigValidationError):
        resolve_config("promotion", {}, tenant_overrides={"legacy_windows_won_fraction": 2})


def test_critical_segments_must_be_dimensions_the_segment_provider_knows():
    # Arquitecto: una dimensión inventada haría pasar el gate de segmentos sin evaluar nada.
    errors = validate_overrides("promotion", {"v25_critical_segments": ["inventada"]})
    assert [(e["key"], e["code"]) for e in errors] == [("v25_critical_segments", "invalid_list")]
    assert "inventada" in errors[0]["message"]
    assert validate_overrides("promotion", {"v25_critical_segments": ["demand_class", "volume_tier", "series_life"]}) == []


def test_policy_version_is_a_sha256_of_the_parameters():
    a, b = LegacyPolicy(), LegacyPolicy()
    assert a.version == b.version and len(a.version) == 64 and int(a.version, 16) >= 0
    assert LegacyPolicy(min_improvement=0.05).version != a.version
    assert LegacyPolicy(windows_won_fraction=0.75).version != a.version
    assert a.describe()["rule_version"] == LEGACY_RULE_VERSION


# ───────────────────────────── paridad exacta ─────────────────────────────

@pytest.mark.parametrize("lgbm_factor", [0.5, 0.6, 0.9, 0.97, 0.99, 1.0, 1.3])
@pytest.mark.parametrize("windows", [1, 2, 3, 4])
def test_parity_on_synthetic_backtests(lgbm_factor, windows):
    bt, profile = make_backtest(lgbm_factor, windows=windows, class_sizes={"smooth": 5, "intermittent": 4, "lumpy": 2}, seed=windows)
    for min_improvement in (0.03, 0.0, 0.1):
        assert_same_selection(select_models(bt, profile, min_improvement=min_improvement),
                              reference.select_models(bt, profile, min_improvement=min_improvement))
    default_call = select_models(bt, profile)       # firma original: sin política ni mejora explícitas
    assert_same_selection(default_call, reference.select_models(bt, profile))
    assert default_call.policy_version == LegacyPolicy().version


@pytest.mark.parametrize("seed", [0, 1, 2])
def test_parity_with_profile_classes_and_stockouts(seed):
    """Sin columna de clase por ventana rige la del perfil; los días no evaluables (quiebre) se excluyen igual."""
    bt, profile = make_backtest(0.6, windows=3, class_sizes={"smooth": 4, "erratic": 3, "intermittent": 3}, seed=seed,
                                class_column=False, stockouts=True)
    assert_same_selection(select_models(bt, profile), reference.select_models(bt, profile))
    new, ref = select_models(bt, profile), reference.select_models(bt, profile)
    pd.testing.assert_frame_equal(selected_rows(bt, profile, new), reference.selected_rows(bt, profile, ref))


def test_parity_when_there_is_no_lightgbm_or_no_statistical_evidence():
    bt, profile = make_backtest(0.6, windows=2)
    no_lgbm = bt[bt["family"] != "lightgbm_global"]
    assert_same_selection(select_models(no_lgbm, profile), reference.select_models(no_lgbm, profile))
    only_lgbm = bt[bt["family"] == "lightgbm_global"]
    assert_same_selection(select_models(only_lgbm, profile), reference.select_models(only_lgbm, profile))


def test_parity_on_the_demo_backtests(pipeline_result):
    """Los mismos backtests que produce el pipeline de la DEMO: producción (todas las ventanas) y evaluación (solo selección)."""
    bt, profile = pipeline_result.backtest, pipeline_result.panel.profile
    assert bt["is_holdout"].any() and (~bt["is_holdout"]).any()
    full, selection_only = select_models(bt, profile, min_improvement=0.03), select_models(bt[~bt["is_holdout"]], profile, min_improvement=0.03)
    ref_full = reference.select_models(bt, profile, min_improvement=0.03)
    ref_sel = reference.select_models(bt[~bt["is_holdout"]], profile, min_improvement=0.03)
    assert_same_selection(full, ref_full)
    assert_same_selection(selection_only, ref_sel)
    # y es lo que la ejecución sirvió con la configuración por defecto
    assert pipeline_result.planned_selection.winners == ref_full.winners
    assert pipeline_result.planned_selection.reasons == ref_full.reasons
    assert pipeline_result.evaluation_selection is not None
    assert pipeline_result.evaluation_selection.winners == ref_sel.winners


def test_default_pipeline_is_governed_by_the_legacy_policy(pipeline_result):
    promo = pipeline_result.promotion
    assert promo is not None and promo.governing == "legacy"
    assert promo.selection is promo.legacy and promo.eval_selection is promo.legacy_eval
    assert pipeline_result.summary["promotion"]["governing_policy"] == "legacy"
    assert pipeline_result.manifest["promotion"]["governing_policy"] == "legacy"


# ───────────────────────────── los parámetros sí gobiernan ─────────────────────────────

def test_windows_fraction_changes_the_outcome_only_through_the_windows_rule():
    """LightGBM mejora mucho el WAPE total pero pierde la tercera ventana: con ⌈2n/3⌉ = 2 de 3 gana; si se exige 3/3 (fracción 1.0), no."""
    bt, profile = make_backtest(1.0, windows=3, class_sizes={"smooth": 6}, seed=5)
    lg = bt["family"] == "lightgbm_global"
    for window, mult in {0: 0.4, 1: 0.4, 2: 1.1}.items():
        m = lg & (bt["window"] == window)
        bt.loc[m, "yhat"] = bt.loc[m, "actual"] + (bt.loc[m, "yhat"] - bt.loc[m, "actual"]) * mult
    default = select_models(bt, profile)
    strict = select_models(bt, profile, policy=LegacyPolicy(windows_won_fraction=1.0))
    assert default.evidence["smooth"]["windows_needed"] == 2 and strict.evidence["smooth"]["windows_needed"] == 3
    assert default.evidence["smooth"]["windows_better"] == strict.evidence["smooth"]["windows_better"] == 2
    assert default.winners["smooth"] == "lightgbm_global" and strict.winners["smooth"] == "auto_ets"
    assert strict.policy_version != default.policy_version


def test_min_improvement_is_the_wape_margin():
    bt, profile = make_backtest(0.97, windows=3, seed=2)
    assert select_models(bt, profile, policy=LegacyPolicy(min_improvement=0.0)).winners["smooth"] == "lightgbm_global"
    assert select_models(bt, profile, policy=LegacyPolicy(min_improvement=0.5)).winners["smooth"] != "lightgbm_global"


def test_every_scope_carries_the_numeric_evidence_of_the_rule():
    bt, profile = make_backtest(0.6, windows=3, class_sizes={"smooth": 5, "lumpy": 2})
    sel = select_models(bt, profile)
    smooth = sel.evidence["smooth"]
    assert {"best_statistical", "best_statistical_wape", "lightgbm_wape", "windows_total", "windows_better", "windows_needed",
            "relative_improvement", "passes_improvement", "passes_windows", "required_relative_improvement"} <= set(smooth)
    assert smooth["outcome"] == "lightgbm_global" and smooth["passes_improvement"] and smooth["passes_windows"]
    assert sel.evidence["lumpy"]["used_pooled"] is True, "una clase con menos de 3 series usa la decisión agregada"
    assert sel.evidence["pooled"]["windows_total"] == 3


# ───────────────────────────── el límite del 3 % y el conteo de ventanas (V25-SEL-036 / R-063) ─────────────────────────────

def boundary_backtest(lgbm_error: float, windows: int = 3, wins=None) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Reales constantes de 100; auto_ets se equivoca 10 %, LightGBM `lgbm_error` (en cada ventana o solo donde `wins[w]`)."""
    rows = []
    for w in range(windows):
        for s in range(4):
            for d in range(10):
                for fam, err in (("seasonal_naive", 0.2), ("window_average", 0.15), ("auto_ets", 0.1), ("naive", 0.25),
                                 ("lightgbm_global", lgbm_error if (wins is None or wins[w]) else 0.3)):
                    rows.append({"unique_id": f"b1-p{s}", "window": w, "step": d + 1, "family": fam, "actual": 100.0, "yhat": 100.0 * (1 + err),
                                 "stockout": False, "scale": 10.0, "demand_class": "smooth"})
    bt = pd.DataFrame(rows)
    return bt, pd.DataFrame({"unique_id": [f"b1-p{s}" for s in range(4)], "demand_class": "smooth"})


@pytest.mark.parametrize("lgbm_error", [0.0969, 0.09699999999999999, 0.097, 0.09700000000000002, 0.0971, 0.05])
def test_parity_at_the_three_percent_boundary_including_float_noise(lgbm_error):
    """El umbral inclusivo (≤) se conserva bit a bit: lo que la regla anterior decide en el límite, lo decide la parametrizada (R-063 queda
    documentado en la V2.5, que compara con tolerancia; la heredada NO se toca por la paridad exacta)."""
    bt, profile = boundary_backtest(lgbm_error)
    new, ref = select_models(bt, profile), reference.select_models(bt, profile)
    assert_same_selection(new, ref)
    assert new.evidence["smooth"]["required_relative_improvement"] == 0.03


def test_with_four_windows_the_legacy_rule_requires_three_not_two_thirds_of_four():
    """Producción = 3 ventanas de selección + período final = 4 ventanas: ⌈8/3⌉ = 3 (75 %), no «≥ 2/3»."""
    bt, profile = boundary_backtest(0.05, windows=4, wins=[True, True, True, True])
    sel = select_models(bt, profile)
    assert sel.evidence["smooth"]["windows_total"] == 4 and sel.evidence["smooth"]["windows_needed"] == 3
    assert sel.winners["smooth"] == "lightgbm_global"
    only_two, profile = boundary_backtest(0.05, windows=4, wins=[True, False, True, False])
    two = select_models(only_two, profile)
    assert two.evidence["smooth"]["windows_better"] == 2 and two.evidence["smooth"]["passes_windows"] is False
    assert two.winners["smooth"] != "lightgbm_global"
    assert_same_selection(two, reference.select_models(only_two, profile))
    exactly_three, profile = boundary_backtest(0.05, windows=4, wins=[True, True, False, True])
    three = select_models(exactly_three, profile)
    assert three.evidence["smooth"]["windows_better"] == 3 and three.evidence["smooth"]["passes_windows"] is True
    assert_same_selection(three, reference.select_models(exactly_three, profile))
