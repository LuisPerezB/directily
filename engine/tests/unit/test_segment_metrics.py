"""W3-A · métricas por horizonte y segmento (V2.5 §8 «Métricas Demand»): fórmulas, denominadores, cobertura, métrica alternativa,
insuficiencia, estabilidad y comparación, contra un cálculo de referencia independiente (sin base de datos).

Los datos son FICTICIOS y generados en la prueba: se afirman fórmulas y propiedades, no precisión real.
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.metrics import (
    ALT_AGGREGATE_ABSOLUTE_ERROR,
    ALT_MASE,
    WAPE_OK,
    WAPE_UNDEFINED,
    WAPE_UNSTABLE,
    alternative_metric,
    guarded_wape,
    wape_status,
    window_stability,
)
from predictily_engine.demand import segments as sg
from predictily_engine.platform.config import CORE_DEFAULTS, resolve_config, validate_overrides
from predictily_engine.profiling.series import build_panel
from _scenario_builders import scenario_data

CUTOFF0 = pd.Timestamp("2026-01-01")
FAMILIES = ("seasonal_naive", "naive", "auto_ets", "lightgbm_global")
CTX = {"evaluation_type": "backtest", "scope": "holdout", "cutoffs": ["2026-01-01"]}
# 5 series: 4 en la sucursal 1 y 1 en la sucursal 2; la categoría sale de CAT.
SERIES = ["b1-p1", "b1-p2", "b1-p3", "b1-p4", "b2-p5"]
CAT = pd.Series({1: 10, 2: 10, 3: 20, 4: 20, 5: 20})
LOOSE = sg.SegmentConfig(wape_min_denominator=0.0, min_series=1, min_obs=1)


def make_bt(seed: int = 0, windows: int = 2, steps: int = 14, censor: bool = True, uids: list[str] | None = None,
            drop_rows: tuple[tuple[str, int, int], ...] = ()) -> pd.DataFrame:
    """Backtest sintético: reales comunes a todas las familias, pronósticos distintos por familia, días censurados de las tres causas,
    escalas MASE por serie y ventana (una indefinida) y una serie que cambia de clase de demanda entre ventanas (as-of)."""
    rng = np.random.default_rng(seed)
    uids = uids or SERIES
    rows = []
    for w in range(windows):
        cutoff = CUTOFF0 + pd.Timedelta(days=28 * w)
        for u in uids:
            actual = np.round(rng.gamma(3.0, 2.0, steps), 1)
            scale = (float(rng.uniform(1.0, 3.0)), float(rng.uniform(2.0, 9.0)))
            if u == "b1-p4" and w == 0:
                scale = (np.nan, np.nan)             # escala indefinida: su error cuenta en WAPE/MAE pero no en MASE
            cls = "erratic" if (u == "b1-p3" and w == 1) else "smooth"
            status = np.array(["sale"] * steps, dtype=object)
            if censor and u == "b1-p1" and w == 0:
                status[2], status[3], status[9] = "stockout_censored", "missing_source", "closed"
            for fam_i, fam in enumerate(FAMILIES):
                yhat = np.clip(actual + rng.normal(0.0, 1.0 + fam_i, steps), 0.0, None)
                for s in range(steps):
                    if (u, w, s + 1) in drop_rows:
                        continue
                    rows.append({"unique_id": u, "window": w, "cutoff": cutoff, "ds": cutoff + pd.Timedelta(days=s + 1), "step": s + 1,
                                 "family": fam, "yhat": float(yhat[s]), "actual": float(actual[s]),
                                 "stockout": status[s] != "sale", "day_status": status[s], "scale": 1.0, "demand_class": cls,
                                 "scale_mae": scale[0], "scale_mse": scale[1], "is_holdout": False})
    return pd.DataFrame(rows)


def run_segments(bt: pd.DataFrame, cfg: sg.SegmentConfig = LOOSE, sel_family: str = "auto_ets", calib: pd.DataFrame | None = None,
                 calib_sel_family: str | None = None, ctx: dict | None = None) -> list[dict]:
    test = sg.annotate(bt, CAT, None)
    sel_rows = test[test["family"] == sel_family]
    cal = sg.annotate(calib, CAT, None) if calib is not None else None
    cal_sel = cal[cal["family"] == calib_sel_family] if cal is not None and calib_sel_family else None
    return sg.segment_rows(test, cal, sel_rows, ctx or CTX, cfg, None, cal_sel)


def pick(rows: list[dict], key: str, h: int, family: str = "selection") -> dict:
    found = [r for r in rows if r["level_key"] == key and r["horizon_days"] == h and r["model_family"] == family]
    assert len(found) == 1, f"{key} H{h} {family}: {len(found)} filas"
    return found[0]


def reference(bt: pd.DataFrame, family: str, mask: pd.Series, h: int) -> dict:
    """Cálculo directo (sin la maquinaria de agregados) de las métricas de un (familia, segmento, H)."""
    d = bt[(bt["family"] == family) & mask & (bt["step"] <= h)]
    ok = d[~d["stockout"]]
    e = ok["yhat"] - ok["actual"]
    scaled = ok[(ok["scale_mae"] > 0) & (ok["scale_mse"] > 0)]
    se = scaled["yhat"] - scaled["actual"]
    den = ok["actual"].sum()
    out = {"n_ok": len(ok), "den": den, "sum_abs": e.abs().sum(), "wape": e.abs().sum() / den, "mae": e.abs().mean(),
           "rmse": math.sqrt((e ** 2).mean()), "bias": e.sum() / den, "mean_error": e.mean(),
           "mase": (se.abs() / scaled["scale_mae"]).mean(), "rmsse": math.sqrt(((se ** 2) / scaled["scale_mse"]).mean()),
           "n_scaled": len(scaled), "stockout": int((d["day_status"] == "stockout_censored").sum()),
           "missing": int((d["day_status"] == "missing_source").sum()), "closed": int((d["day_status"] == "closed").sum()),
           "total_days": len(d)}
    pairs = []
    for (u, w), g in d.groupby(["unique_id", "window"]):
        if len(g) == h:
            gok = g[~g["stockout"]]
            if len(gok):
                pairs.append((gok["actual"].sum(), gok["yhat"].sum()))
    a = np.array([p[0] for p in pairs])
    y = np.array([p[1] for p in pairs])
    out["pairs"] = len(pairs)
    out["total_den"] = a.sum()
    out["total_wape"] = np.abs(y - a).sum() / a.sum() if len(a) else None
    out["total_mae"] = np.abs(y - a).mean() if len(a) else None
    return out


# ───────────────────────────────────────── funciones puras de backtest/metrics.py ─────────────────────────────────────────

def test_wape_status_separates_undefined_unstable_and_ok():
    assert wape_status(0.0, 30.0) == WAPE_UNDEFINED and wape_status(None, 30.0) == WAPE_UNDEFINED
    assert wape_status(float("nan"), 30.0) == WAPE_UNDEFINED
    assert wape_status(12.0, 30.0) == WAPE_UNSTABLE and wape_status(29.999, 30.0) == WAPE_UNSTABLE
    assert wape_status(30.0, 30.0) == WAPE_OK, "el umbral es inclusivo: denominador == umbral es estable"
    assert wape_status(0.5, 0.0) == WAPE_OK, "sin umbral solo existen ok e undefined"


def test_guarded_wape_never_divides_by_a_zero_denominator():
    zero = guarded_wape(7.0, 0.0, 30.0)
    assert zero["value"] is None and zero["trusted"] is None and zero["status"] == WAPE_UNDEFINED and zero["unstable"] is True
    assert zero["denominator"] == 0.0 and zero["sum_abs_error"] == 7.0, "el error absoluto agregado sí existe con denominador 0"
    small = guarded_wape(9.0, 10.0, 30.0)
    assert small["value"] == pytest.approx(0.9) and small["trusted"] is None and small["status"] == WAPE_UNSTABLE
    stable = guarded_wape(9.0, 30.0, 30.0)
    assert stable["trusted"] == pytest.approx(0.3) and stable["unstable"] is False


def test_alternative_metric_prefers_the_scaled_mae_and_falls_back_to_the_aggregate_absolute_error():
    scaled = alternative_metric(sum_abs_error=12.0, n_obs=10, sum_scaled_abs=5.0, n_scaled=10)
    assert scaled["name"] == ALT_MASE and scaled["value"] == pytest.approx(0.5)
    aggregate = alternative_metric(sum_abs_error=12.0, n_obs=10, sum_scaled_abs=None, n_scaled=0)
    assert aggregate["name"] == ALT_AGGREGATE_ABSOLUTE_ERROR and aggregate["value"] == 12.0
    assert aggregate["per_evaluable_day"] == pytest.approx(1.2) and aggregate["unit"] == "unidades"
    assert alternative_metric(0.0, 0)["per_evaluable_day"] is None, "sin días evaluables no se inventa un promedio"


def test_window_stability_is_not_estimable_with_one_window_and_flags_a_dispersed_series():
    one = window_stability({3: (0.4, 100.0)}, cv_warn=0.5)
    assert one["status"] == "single_window" and one["wape_std"] is None and one["unstable_across_windows"] is None
    stable = window_stability({0: (0.30, 90.0), 1: (0.32, 95.0), 2: (0.31, 80.0)}, cv_warn=0.5)
    values = np.array([0.30, 0.32, 0.31])
    assert stable["status"] == "estimated" and stable["wape_std"] == pytest.approx(values.std(ddof=1))
    assert stable["wape_cv"] == pytest.approx(values.std(ddof=1) / values.mean()) and stable["unstable_across_windows"] is False
    wild = window_stability({0: (0.2, 50.0), 1: (1.4, 50.0), 2: (0.3, 50.0)}, cv_warn=0.5)
    assert wild["unstable_across_windows"] is True
    skipped = window_stability({0: (None, 0.0), 1: (0.3, 40.0)})
    assert skipped["n_windows"] == 1 and skipped["n_windows_without_wape"] == 1 and skipped["status"] == "single_window"
    assert window_stability({0: (None, 0.0)})["status"] == "no_data"


# ───────────────────────────────────────── fórmulas, denominadores y cobertura por segmento ─────────────────────────────────────────

@pytest.fixture(scope="module")
def bt() -> pd.DataFrame:
    return make_bt()


@pytest.fixture(scope="module")
def rows(bt) -> list[dict]:
    return run_segments(bt)


def test_every_metric_matches_the_independent_reference_for_every_dimension_horizon_and_family(bt, rows):
    ids = bt["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    prod = ids[1]
    masks = {
        "demand_class:smooth": bt["demand_class"] == "smooth", "demand_class:erratic": bt["demand_class"] == "erratic",
        "branch:b1": ids[0] == 1, "branch:b2": ids[0] == 2,
        "category:c10": prod.map(CAT) == 10, "category:c20": prod.map(CAT) == 20,
    }
    checked = 0
    for key, mask in masks.items():
        for h in (7, 14):
            for family, label in (("auto_ets", "selection"), ("seasonal_naive", "seasonal_naive"), ("lightgbm_global", "lightgbm_global")):
                ref = reference(bt, family, mask, h)
                r = pick(rows, key, h, label)
                m = r["metrics"]
                assert r["n_obs"] == ref["n_ok"] == m["sample"]["evaluable_days"]
                assert m["denominator"]["sum_actual"] == pytest.approx(ref["den"])
                assert r["wape"] == pytest.approx(ref["wape"]) and m["wape_raw"] == pytest.approx(ref["wape"])
                assert m["sum_abs_error"] == pytest.approx(ref["sum_abs"])
                assert r["mae"] == pytest.approx(ref["mae"]) and r["rmse"] == pytest.approx(ref["rmse"])
                assert r["bias"] == pytest.approx(ref["bias"]) and m["mean_error"] == pytest.approx(ref["mean_error"])
                assert m["mase"] == pytest.approx(ref["mase"]) and m["rmsse"] == pytest.approx(ref["rmsse"])
                assert m["sample"]["exclusions"]["scale_undefined"] == ref["n_ok"] - ref["n_scaled"]
                exc = m["sample"]["exclusions"]
                assert (exc["stockout_days"], exc["missing_source_days"], exc["closed_days"]) == (ref["stockout"], ref["missing"], ref["closed"])
                assert m["sample"]["total_days"] == ref["total_days"] and m["sample"]["evaluable_share"] == pytest.approx(ref["n_ok"] / ref["total_days"])
                assert m["horizon_total"]["n_pairs"] == ref["pairs"]
                assert m["horizon_total"]["denominator"] == pytest.approx(ref["total_den"])
                assert m["horizon_total"]["wape"] == pytest.approx(ref["total_wape"]) and m["horizon_total"]["mae"] == pytest.approx(ref["total_mae"])
                checked += 1
    assert checked == 6 * 2 * 3


def test_the_wape_denominator_is_the_sum_of_actuals_and_excluded_days_are_counted_by_class_not_zeroed(bt, rows):
    r = pick(rows, "branch:b1", 14)
    d = bt[(bt["family"] == "auto_ets") & bt["unique_id"].str.startswith("b1-") & (bt["step"] <= 14)]
    assert r["metrics"]["denominator"]["sum_actual"] == pytest.approx(d.loc[~d["stockout"], "actual"].sum())
    assert r["metrics"]["sample"]["exclusions"] == {"scale_undefined": r["n_obs"] - int(
        ((d["scale_mae"] > 0) & (d["scale_mse"] > 0) & ~d["stockout"]).sum()), "stockout_days": 1, "missing_source_days": 1, "closed_days": 1}
    assert r["n_obs"] + 3 == len(d), "los 3 días no evaluables NO entran como ceros: solo se cuentan"
    wrong = d["yhat"].sub(d["actual"]).abs().sum() / d["actual"].sum()   # lo que daría si los censurados se contaran
    assert r["wape"] != pytest.approx(wrong)


def test_horizons_are_cumulative_and_only_the_ones_the_backtest_reaches_are_emitted(bt, rows):
    assert {r["horizon_days"] for r in rows} == {7, 14}, "el backtest llega a 14 pasos: 15, 30, 60 y 90 no se evalúan (sin inventar)"
    seven, fourteen = pick(rows, "branch:b1", 7), pick(rows, "branch:b1", 14)
    assert fourteen["n_obs"] > seven["n_obs"] and fourteen["metrics"]["segment"]["horizon_basis"] == "daily_steps_1_to_H"
    longer = run_segments(make_bt(steps=31))
    assert {r["horizon_days"] for r in longer} == {7, 14, 15, 30}
    assert pick(longer, "branch:b1", 15)["n_obs"] > pick(longer, "branch:b1", 14)["n_obs"], "15 contiene los 14 primeros días y uno más"


def test_a_series_changes_demand_class_between_windows_without_leaking_the_other_window(bt, rows):
    erratic = pick(rows, "demand_class:erratic", 14)
    assert erratic["metrics"]["sample"]["n_series"] == 1 and erratic["metrics"]["sample"]["n_windows"] == 1
    smooth = pick(rows, "demand_class:smooth", 14)
    assert smooth["metrics"]["sample"]["n_series"] == 5 and smooth["metrics"]["sample"]["n_windows"] == 2
    cut = bt[(bt["family"] == "auto_ets") & (bt["step"] <= 14)]
    assert smooth["n_obs"] + erratic["n_obs"] + 3 == len(cut), "las clases particionan las filas evaluables (3 días excluidos)"


def test_roles_baseline_best_statistical_ml_and_selected_are_labeled(bt, rows):
    by_family = {r["model_family"]: set(r["extra"]["roles"]) for r in rows if r["level_key"] == "branch:b1" and r["horizon_days"] == 14}
    assert by_family["selection"] == {"selected"} and "baseline" in by_family["seasonal_naive"] and by_family["lightgbm_global"] == {"ml"}
    best = [f for f, roles in by_family.items() if "best_statistical" in roles]
    assert len(best) == 1 and best[0] in ("seasonal_naive", "naive", "auto_ets")
    # en esta muestra el baseline tiene el menor error en la sucursal 1 (ruido 1,0 frente a 2,0 y 3,0 de los demás): es a la vez el mejor estadístico
    assert best == ["seasonal_naive"] and by_family["seasonal_naive"] == {"baseline", "best_statistical"}
    sel = pick(rows, "branch:b1", 14)
    assert sel["is_selected"] is True and sel["metrics"]["comparison"]["best_statistical_family"] == best[0]
    assert all(r["metrics"]["comparison"] is None for r in rows if r["model_family"] != "selection"), "la comparación vive en la fila del servido"
    assert {r["is_baseline"] for r in rows if r["model_family"] == "seasonal_naive"} == {True}


def test_the_best_statistical_is_chosen_with_the_selection_windows_never_with_the_final_period():
    """El período final reservado (test) favorece a `naive`; las ventanas de selección (calib), a `auto_ets`: se etiqueta la de calib."""
    def frame(best_family: str, window: int, cutoff: pd.Timestamp) -> pd.DataFrame:
        rng = np.random.default_rng(window)
        out = []
        for u in SERIES:
            actual = np.round(rng.gamma(3.0, 2.0, 14), 1)
            for fam in ("seasonal_naive", "naive", "auto_ets"):
                err = 0.1 if fam == best_family else 3.0
                for s in range(14):
                    out.append({"unique_id": u, "window": window, "cutoff": cutoff, "ds": cutoff + pd.Timedelta(days=s + 1), "step": s + 1,
                                "family": fam, "yhat": float(actual[s] + err), "actual": float(actual[s]), "stockout": False,
                                "day_status": "sale", "scale": 1.0, "demand_class": "smooth", "scale_mae": 2.0, "scale_mse": 5.0,
                                "is_holdout": False})
        return pd.DataFrame(out)

    calib, test = frame("auto_ets", 0, CUTOFF0), frame("naive", 1, CUTOFF0 + pd.Timedelta(days=28))
    out = run_segments(test, calib=calib, sel_family="auto_ets", calib_sel_family="auto_ets")
    best = [r for r in out if r["level_key"] == "branch:b1" and r["horizon_days"] == 14 and "best_statistical" in r["extra"]["roles"]]
    assert [r["model_family"] for r in best] == ["auto_ets"]
    cmp_ = pick(out, "branch:b1", 14)["metrics"]["comparison"]
    assert cmp_["best_statistical_chosen_with"] == "selection_windows"
    # sin período final independiente (sin calib) la evidencia son las mismas ventanas: queda declarado como optimista
    alone = run_segments(test, sel_family="auto_ets")
    assert pick(alone, "branch:b1", 14)["metrics"]["comparison"]["best_statistical_family"] == "naive"
    assert pick(alone, "branch:b1", 14)["metrics"]["comparison"]["best_statistical_chosen_with"] == "same_windows_optimistic"


# ───────────────────────────────────────── WAPE inestable o indefinido, insuficiencia y estabilidad ─────────────────────────────────────────

def _flat_frame(actual: float, yhat: float, n_series: int = 4, steps: int = 14, windows: int = 1, scale: float = 2.0,
                cls: str = "intermittent") -> pd.DataFrame:
    rows = []
    for w in range(windows):
        cutoff = CUTOFF0 + pd.Timedelta(days=28 * w)
        for u in SERIES[:n_series]:
            for fam in FAMILIES:
                y = yhat if fam != "seasonal_naive" else yhat + 1.0
                for s in range(steps):
                    rows.append({"unique_id": u, "window": w, "cutoff": cutoff, "ds": cutoff + pd.Timedelta(days=s + 1), "step": s + 1,
                                 "family": fam, "yhat": y, "actual": actual, "stockout": False, "day_status": "sale", "scale": 1.0,
                                 "demand_class": cls, "scale_mae": scale, "scale_mse": scale * 2, "is_holdout": False})
    return pd.DataFrame(rows)


def test_all_zero_actuals_give_no_wape_but_an_explicit_alternative_and_never_a_zero_division():
    out = run_segments(_flat_frame(actual=0.0, yhat=0.5), cfg=sg.SegmentConfig(min_series=1, min_obs=1))
    r = pick(out, "demand_class:intermittent", 14)
    m = r["metrics"]
    assert r["wape"] is None and m["wape"] is None and m["wape_raw"] is None, "denominador 0: nunca un WAPE"
    assert m["wape_status"] == "undefined" and m["wape_unstable"] is True and r["bias"] is None and m["bias_raw"] is None
    assert m["denominator"]["sum_actual"] == 0.0
    assert m["alternative_metric"]["name"] == ALT_MASE and m["alternative_metric"]["used_because"] == "wape_undefined"
    assert m["alternative_metric"]["value"] == pytest.approx(0.5 / 2.0), "MAE escalado: |0,5 − 0| / escala 2"
    assert m["sum_abs_error"] == pytest.approx(0.5 * 4 * 14) and m["mean_error"] == pytest.approx(0.5), "el error absoluto agregado sí existe"
    assert r["mae"] == pytest.approx(0.5) and r["rmse"] == pytest.approx(0.5), "MAE y RMSE no dividen por los reales"


def test_without_a_defined_scale_the_alternative_is_the_aggregate_absolute_error():
    frame = _flat_frame(actual=0.0, yhat=0.5)
    frame[["scale_mae", "scale_mse"]] = np.nan
    out = run_segments(frame, cfg=sg.SegmentConfig(min_series=1, min_obs=1))
    m = pick(out, "demand_class:intermittent", 14)["metrics"]
    assert m["mase"] is None and m["rmsse"] is None and m["sample"]["exclusions"]["scale_undefined"] == m["sample"]["n_obs"]
    alt = m["alternative_metric"]
    assert alt["name"] == ALT_AGGREGATE_ABSOLUTE_ERROR and alt["value"] == pytest.approx(0.5 * 4 * 14)
    assert alt["per_evaluable_day"] == pytest.approx(0.5)


def test_a_small_denominator_is_wape_unstable_keeps_the_raw_value_aside_and_nulls_the_column():
    frame = _flat_frame(actual=0.1, yhat=0.3)                                     # Σ reales = 0,1 × 4 series × 14 días = 5,6 < 30
    out = run_segments(frame, cfg=sg.SegmentConfig(min_series=1, min_obs=1))
    r = pick(out, "demand_class:intermittent", 14)
    m = r["metrics"]
    assert m["denominator"]["sum_actual"] == pytest.approx(5.6) and m["denominator"]["min_required"] == 30.0
    assert m["wape_status"] == "unstable" and m["wape_unstable"] is True
    assert r["wape"] is None and m["wape"] is None and r["bias"] is None, "las columnas solo llevan cifras confiables"
    assert m["wape_raw"] == pytest.approx(0.2 / 0.1) and m["bias_raw"] == pytest.approx(2.0), "el valor crudo queda aparte para auditarlo"
    assert m["alternative_metric"]["used_because"] == "wape_unstable" and m["alternative_metric"]["name"] == ALT_MASE
    assert m["horizon_total"]["wape"] is None and m["horizon_total"]["wape_unstable"] is True
    # con el umbral en 0 el mismo dato sí produce WAPE: la marca depende del umbral configurable, no de la serie
    lax = pick(run_segments(frame, cfg=sg.SegmentConfig(wape_min_denominator=0.0, min_series=1, min_obs=1)), "demand_class:intermittent", 14)
    assert lax["wape"] == pytest.approx(2.0) and lax["metrics"]["wape_unstable"] is False and lax["metrics"]["alternative_metric"] is None


def test_segments_with_few_series_or_observations_are_insufficient_data_without_figures():
    out = run_segments(_flat_frame(actual=5.0, yhat=5.0, n_series=2), cfg=sg.SegmentConfig(min_series=3, min_obs=1))
    r = pick(out, "demand_class:intermittent", 14)
    assert r["metrics"]["status"] == "insufficient_data" and r["metrics"]["insufficient_reasons"][0]["code"] == "few_series"
    assert r["metrics"]["insufficient_reasons"][0] == {"code": "few_series", "n_series": 2, "min": 3}
    assert all(r[k] is None for k in ("wape", "mae", "rmse", "bias", "coverage_80", "coverage_95")), "sin cifras de error"
    m = r["metrics"]
    assert m["mase"] is None and m["rmsse"] is None and m["sum_abs_error"] is None and m["horizon_total"] is None
    assert m["withheld"] and m["sample"]["n_obs"] == 2 * 14 and m["sample"]["n_series"] == 2, "sí se informan los tamaños de muestra"
    assert m["denominator"]["sum_actual"] == pytest.approx(5.0 * 28), "y el denominador"
    assert m["stability"]["status"] == "withheld_insufficient_data" and m["stability"]["per_window"] == []
    cmp_ = m["comparison"]["selected_vs_baseline"]
    assert cmp_["verdict"] == "not_comparable" and cmp_["reason"] == "insufficient_data" and "wape" not in cmp_ and "sum_abs_error" not in cmp_
    few_obs = pick(run_segments(_flat_frame(actual=5.0, yhat=5.0, n_series=4), cfg=sg.SegmentConfig(min_series=1, min_obs=100)),
                   "demand_class:intermittent", 14)
    assert few_obs["metrics"]["insufficient_reasons"] == [{"code": "few_observations", "evaluable_days": 56, "min": 100}]
    enough = pick(run_segments(_flat_frame(actual=5.0, yhat=5.0, n_series=4), cfg=sg.SegmentConfig(min_series=4, min_obs=56)),
                  "demand_class:intermittent", 14)
    assert enough["metrics"]["status"] == "ok", "los umbrales son inclusivos"


def test_stability_reports_the_wape_of_each_window_and_the_std_of_the_independent_period_is_not_estimable():
    bt = make_bt(seed=3, windows=3, censor=False)
    out = run_segments(bt)
    stab = pick(out, "branch:b1", 14)["metrics"]["stability"]
    assert stab["status"] == "estimated" and stab["n_windows"] == 3
    per = []
    for w in range(3):
        d = bt[(bt["family"] == "auto_ets") & bt["unique_id"].str.startswith("b1-") & (bt["step"] <= 14) & (bt["window"] == w)]
        per.append((d["yhat"] - d["actual"]).abs().sum() / d["actual"].sum())
    assert [p["wape"] for p in stab["per_window"]] == pytest.approx(per)
    assert stab["wape_std"] == pytest.approx(np.std(per, ddof=1)) and stab["wape_mean"] == pytest.approx(np.mean(per))
    assert [p["cutoff"] for p in stab["per_window"]] == sorted(p["cutoff"] for p in stab["per_window"])
    # período final reservado: una sola ventana ⇒ desvío no estimable, con la estabilidad de las ventanas de selección como referencia
    hold = bt[bt["window"] == 2].assign(window=3)
    final = pick(run_segments(hold, calib=bt[bt["window"] < 2], sel_family="auto_ets", calib_sel_family="auto_ets"), "branch:b1", 14)
    assert final["metrics"]["stability"]["status"] == "single_window" and final["metrics"]["stability"]["wape_std"] is None
    ref = final["metrics"]["stability_selection_windows"]
    assert ref["source"] == "selection_windows" and ref["n_windows"] == 2 and ref["wape_std"] is not None
    assert final["n_windows"] == 1


def test_comparison_verdicts_use_the_configurable_tolerance_and_declare_the_metric():
    # auto_ets (servido) tiene error 0 y el baseline 1,0 por día sobre reales 10 ⇒ mejora relativa 100 %
    good = pick(run_segments(_flat_frame(actual=10.0, yhat=10.0, cls="smooth")), "demand_class:smooth", 14)["metrics"]["comparison"]
    entry = good["selected_vs_baseline"]
    assert entry["metric"] == "wape" and entry["verdict"] == "better" and entry["same_support"] is True
    assert entry["value"] == pytest.approx(0.0) and entry["baseline_value"] == pytest.approx(0.1)
    assert entry["relative_improvement"] == pytest.approx(1.0)
    frame = _flat_frame(actual=10.0, yhat=10.0, cls="smooth")
    frame.loc[frame["family"] == "auto_ets", "yhat"] = 11.2                         # servido peor que el baseline (11,0)
    worse = pick(run_segments(frame), "demand_class:smooth", 14)["metrics"]["comparison"]["selected_vs_baseline"]
    assert worse["verdict"] == "worse" and worse["relative_improvement"] == pytest.approx(1 - 0.12 / 0.10)
    frame.loc[frame["family"] == "auto_ets", "yhat"] = 11.0                         # igual al baseline ⇒ similar
    similar = pick(run_segments(frame), "demand_class:smooth", 14)["metrics"]["comparison"]["selected_vs_baseline"]
    assert similar["verdict"] == "similar" and similar["relative_improvement"] == pytest.approx(0.0)
    frame.loc[frame["family"] == "auto_ets", "yhat"] = 10.97                         # 3 % de diferencia relativa
    tol = pick(run_segments(frame, cfg=sg.SegmentConfig(wape_min_denominator=0.0, min_series=1, min_obs=1, similar_tolerance=0.2)),
               "demand_class:smooth", 14)["metrics"]["comparison"]["selected_vs_baseline"]
    assert tol["verdict"] == "similar"
    # con el WAPE inestable la comparación pasa a error absoluto agregado sobre las MISMAS observaciones
    small = _flat_frame(actual=0.1, yhat=0.1, cls="smooth")
    unstable = pick(run_segments(small, cfg=sg.SegmentConfig(min_series=1, min_obs=1)), "demand_class:smooth", 14)["metrics"]["comparison"]
    assert unstable["selected_vs_baseline"]["metric"] == "aggregate_absolute_error"
    assert unstable["selected_vs_baseline"]["verdict"] == "better"


def test_a_model_with_different_support_is_not_compared_on_absolute_error():
    small = _flat_frame(actual=0.1, yhat=0.1, cls="smooth")
    ml = small["family"] == "lightgbm_global"
    small = small[~(ml & (small["window"] == 0) & (small["step"] > 7))]            # LightGBM sin filas en parte del horizonte
    cmp_ = pick(run_segments(small, cfg=sg.SegmentConfig(min_series=1, min_obs=1)), "demand_class:smooth", 14)["metrics"]["comparison"]
    assert cmp_["ml_vs_baseline"]["same_support"] is False
    assert cmp_["ml_vs_baseline"]["verdict"] == "not_comparable" and cmp_["ml_vs_baseline"]["reason"] == "wape_unstable_and_different_support"


def test_incomplete_windows_are_left_out_of_the_h_day_total_and_counted():
    bt = make_bt(seed=5, censor=False, drop_rows=(("b1-p2", 0, 10),))
    r = pick(run_segments(bt), "branch:b1", 14)["metrics"]["horizon_total"]
    assert r["n_incomplete_pairs"] == 1 and r["n_pairs"] == 7, "8 pares serie×ventana en la sucursal 1; el de b1-p2 en la ventana 0 no tiene los 14 días"
    assert "basis" not in r, "el texto de la definición vive en el manifiesto, no en cada fila"


def test_the_categories_and_branches_cap_keeps_the_ones_with_most_volume_and_declares_the_omitted():
    uids = [f"b1-p{p}" for p in range(1, 7)]
    bt = make_bt(uids=uids, censor=False)
    cat = pd.Series({p: p for p in range(1, 7)})                                   # 6 categorías de una serie cada una
    test = sg.annotate(bt, cat, None)
    out = sg.segment_rows(test, None, test[test["family"] == "auto_ets"], CTX, sg.SegmentConfig(min_series=1, min_obs=1, wape_min_denominator=0.0,
                                                                                             max_values=3))
    kept = {r["level_key"] for r in out if r["level_key"].startswith("category:")}
    assert len(kept) == 3 and all(r["extra"]["omitted_values"] == {"category": 3} for r in out)
    volume = bt[(bt["family"] == "auto_ets") & ~bt["stockout"]].assign(c=lambda d: d["unique_id"].str.extract(r"p(\d+)")[0].astype(int))
    expected = set(f"category:c{c}" for c in volume.groupby("c")["actual"].sum().sort_values(ascending=False).head(3).index)
    assert kept == expected


# ───────────────────────────────────────── contexto as-of: tramo de volumen y vida de la serie ─────────────────────────────────────────

def _context_world():
    """Panel ficticio de 420 días: p1..p5 constantes (5..9 u/día) desde el día 0; p2 pasa de 1 a 100 u/día DESPUÉS del día 330;
    p6 empieza el día 340 (nueva al corte del día 400); p7 solo vendió hasta el día 200 (sin volumen reciente en ningún corte)."""
    n = 420
    pat = {(1, p): np.full(n, 4.0 + p) for p in (1, 3, 4, 5)}
    pat[(1, 2)] = np.where(np.arange(n) > 330, 100.0, 1.0)
    p6 = np.zeros(n)
    p6[340:] = 3.0
    p7 = np.zeros(n)
    p7[:200] = 2.0
    pat[(1, 6)], pat[(1, 7)] = p6, p7
    data = scenario_data(pat)
    return build_panel(data), data.cutoff


def test_volume_tier_and_series_age_use_only_what_was_known_at_each_cutoff():
    panel, _ = _context_world()
    c1, c2 = panel.df["ds"].min() + pd.Timedelta(days=320), panel.df["ds"].min() + pd.Timedelta(days=400)
    ids1 = ["b1-p1", "b1-p2", "b1-p3", "b1-p4", "b1-p5", "b1-p7"]
    ids2 = ids1 + ["b1-p6"]
    bt = pd.DataFrame({"cutoff": [c1] * len(ids1) + [c2] * len(ids2), "unique_id": ids1 + ids2})
    ctx = sg.build_context(bt, panel, None, sg.SegmentConfig(volume_top_share=0.2, volume_lookback_days=90, new_series_days=90))
    a = ctx.attrs
    top1 = [u for u in ids1 if a.loc[(c1, u), "volume_tier"] == "top20"]
    top2 = [u for u in ids2 if a.loc[(c2, u), "volume_tier"] == "top20"]
    assert top1 == ["b1-p4", "b1-p5"], "6 series ⇒ ⌈20 %⌉ = 2 «top»; al corte del día 320 p2 aún vendía 1 u/día: la explosión posterior NO lo hace «top»"
    assert top2 == ["b1-p2", "b1-p5"], "al corte del día 400 (7 series ⇒ 2 «top») sí: 70 días a 100 u/día"
    assert a.loc[(c2, "b1-p7"), "volume_tier"] == "rest" and a.loc[(c2, "b1-p7"), "trailing_units"] == 0.0
    assert (c1, "b1-p6") not in a.index, "p6 no existía al corte del día 320"
    assert a.loc[(c2, "b1-p6"), "series_age"] == "new" and a.loc[(c2, "b1-p6"), "age_days"] == 61
    assert a.loc[(c2, "b1-p1"), "series_age"] == "mature" and a.loc[(c1, "b1-p1"), "age_days"] == 321
    # annotate asigna por (corte, serie): la misma serie cambia de tramo entre ventanas
    rows = pd.DataFrame({"cutoff": [c1, c2], "unique_id": ["b1-p2", "b1-p2"], "demand_class": "smooth"})
    out = sg.annotate(rows, pd.Series({2: 7}), ctx)
    assert list(out["seg_volume_tier"]) == ["rest", "top20"] and list(out["seg_category"]) == ["c7", "c7"]
    assert list(out["seg_branch"]) == ["b1", "b1"] and list(out.index) == list(rows.index)


def test_the_provider_for_the_promotion_policy_returns_the_same_segments_as_the_metrics():
    panel, _ = _context_world()
    c1, c2 = panel.df["ds"].min() + pd.Timedelta(days=320), panel.df["ds"].min() + pd.Timedelta(days=400)
    ids = ["b1-p1", "b1-p2", "b1-p3"]
    bt = pd.DataFrame({"cutoff": [c1] * 3 + [c2] * 3, "unique_id": ids * 2, "demand_class": "smooth", "family": "auto_ets"},
                      index=[10, 11, 12, 20, 21, 22])
    provider = sg.make_provider(bt, panel, None, pd.Series({1: 1, 2: 1, 3: 2}), sg.SegmentConfig(volume_top_share=0.34))
    out = provider(bt.iloc[1:5])
    assert list(out.index) == [11, 12, 20, 21] and {"demand_class", "category", "branch", "volume_tier", "series_age", "series_life"} <= set(out.columns)
    assert list(out["series_life"]) == list(out["series_age"]) == ["mature"] * 4
    assert list(out["volume_tier"]) == ["rest", "top34", "rest", "top34"], "3 series ⇒ ⌈34 %⌉ = 2 «top»: p3 y p1 al corte 320; p2 (100 u/día) y p3 al corte 400"
    assert out.loc[11, "volume_tier"] == "rest" and out.loc[21, "volume_tier"] == "top34", "p2 es «top» en el corte del día 400 pero no en el del 320"
    assert list(out["category"]) == ["c1", "c2", "c1", "c1"] and list(out["branch"]) == ["b1"] * 4
    assert sg.make_provider(bt.iloc[0:0], panel, None, pd.Series(dtype=float), sg.SegmentConfig()) is None


def test_the_top_share_and_the_new_series_threshold_are_configurable():
    panel, _ = _context_world()
    c2 = panel.df["ds"].min() + pd.Timedelta(days=400)
    ids = ["b1-p1", "b1-p2", "b1-p3", "b1-p4", "b1-p5", "b1-p6"]
    bt = pd.DataFrame({"cutoff": [c2] * 6, "unique_id": ids})
    half = sg.build_context(bt, panel, None, sg.SegmentConfig(volume_top_share=0.5, new_series_days=30)).attrs
    assert (half["volume_tier"] == "top50").sum() == 3, "50 % de 6 series = 3"
    assert (half["series_age"] == "new").sum() == 0, "p6 tiene 61 días: con umbral de 30 ya no es nueva"
    assert sg.build_context(bt.iloc[0:0], panel, None, sg.SegmentConfig()) is None
    assert sg.build_context(bt, panel, None, sg.SegmentConfig(enabled=False)) is None


def test_config_defaults_ranges_and_tenant_overrides_flow_into_the_thresholds():
    core = CORE_DEFAULTS["demand"]
    cfg = sg.SegmentConfig.from_config(core)
    assert cfg == sg.SegmentConfig() and cfg.enabled is True and cfg.top_label == "top20"
    assert (core["wape_min_denominator"], core["segment_min_series"], core["segment_min_obs"]) == (30.0, 3, 90)
    tuned, _, provenance = resolve_config("demand", {"cores": {"demand": {"wape_min_denominator": 50, "segment_min_series": 5,
                                                                          "segment_volume_top_share": 0.1}}}, None)
    got = sg.SegmentConfig.from_config(tuned)
    assert got.wape_min_denominator == 50.0 and got.min_series == 5 and got.top_label == "top10"
    assert provenance["wape_min_denominator"] == "tenant" and provenance["segment_min_obs"] == "core"
    assert got.thresholds()["justification"].endswith("a aprobar en el piloto con datos reales")
    errors = validate_overrides("demand", {"wape_min_denominator": -1, "segment_volume_top_share": 1.5, "segment_min_series": 0,
                                           "segment_new_series_days": 3, "segment_metrics_enabled": "si", "segment_min_obs": 2.5})
    assert {e["key"] for e in errors} == {"wape_min_denominator", "segment_volume_top_share", "segment_min_series",
                                          "segment_new_series_days", "segment_metrics_enabled", "segment_min_obs"}
    assert validate_overrides("demand", {"wape_min_denominator": 0, "segment_stability_cv_warn": 2.0, "segment_max_values": 10}) == []


def test_the_summary_block_is_off_and_empty_when_there_are_no_segment_rows_or_the_switch_is_off():
    off = sg.summary_block([], None)
    assert off["enabled"] is False and off["attention"] == [] and off["counts"]["cells"] == 0 and "desactivadas" in off["note"]
    empty = sg.summary_block([], sg.SegmentConfig())
    assert empty["enabled"] is True and empty["available"] is True and empty["attention"] == [] and "Sin backtesting" in empty["note"]
    failed = sg.summary_block([], sg.SegmentConfig(), error="ValueError: boom")
    assert failed["available"] is False and failed["error"] == "ValueError: boom" and "segment_metrics_unavailable" in failed["note"]


# ───────────────────────────────────────── revisión adversarial: resumen compacto, series evaluables, categoría ausente ─────────────────────────────────────────

def _many_segments(n: int = 50, steps: int = 90, **cfg_kw):
    """n series, cada una en su propia sucursal y su propia categoría (n categorías y n sucursales) → filas de segmento y su resumen."""
    uids = [f"b{i}-p{i}" for i in range(1, n + 1)]
    bt = make_bt(uids=uids, steps=steps, windows=2, censor=False)
    cat = pd.Series({i: i for i in range(1, n + 1)})
    test = sg.annotate(bt, cat, None)
    cfg = sg.SegmentConfig(**cfg_kw)
    rows = sg.segment_rows(test, None, test[test["family"] == "auto_ets"], CTX, cfg)
    return rows, cfg


def test_the_run_summary_stays_small_with_fifty_categories_and_fifty_branches_and_points_to_the_detail():
    import json

    rows, cfg = _many_segments()
    keys = {r["level_key"] for r in rows if r["model_family"] == "selection"}
    assert sum(k.startswith("category:") for k in keys) == 50 and sum(k.startswith("branch:") for k in keys) == 50
    block = sg.summary_block(rows, cfg)
    size = len(json.dumps(block))
    detail = len(json.dumps([r["metrics"] for r in rows if r["model_family"] == "selection"]))
    assert size < 8000 and detail > 100 * size, f"resumen {size} B frente a {detail} B de detalle"
    assert len(block["attention"]) == 20 and block["attention_truncated"] is True and block["attention_total"] > 20
    assert block["counts"]["segments"] >= 100 and block["counts"]["cells"] == block["counts"]["segments"] * 6
    assert block["counts"]["by_dimension"]["category"]["segments"] == 50 and block["counts"]["by_dimension"]["branch"]["segments"] == 50
    assert block["counts"]["by_dimension"]["category"]["insufficient_data"] == 300, "cada categoría tiene 1 serie: insufficient_data (few_series)"
    assert block["detail"]["table"] == "model_evaluations" and block["definitions_ref"] == sg.DEFINITIONS_REF
    smaller = sg.summary_block(rows, sg.SegmentConfig(summary_max_cells=3))
    assert len(smaller["attention"]) == 3 and smaller["attention_total"] == block["attention_total"]
    assert len(json.dumps(smaller)) < size
    assert sg.summary_block(rows, sg.SegmentConfig(summary_max_cells=0))["attention"] == []


def test_attention_cells_list_the_worse_than_baseline_first_then_undefined_unstable_and_insufficient():
    frame = _flat_frame(actual=10.0, yhat=10.0, cls="smooth", n_series=4)
    frame.loc[frame["family"] == "auto_ets", "yhat"] = 12.0                         # servido peor que el baseline (11,0)
    rows = run_segments(frame)
    block = sg.summary_block(rows, LOOSE)
    reasons = [c["reason"] for c in block["attention"]]
    assert reasons and set(reasons) == {"worse"} and block["counts"]["by_reason"] == {"worse": block["attention_total"]}
    ok = sg.summary_block(run_segments(_flat_frame(actual=10.0, yhat=10.0, cls="smooth")), LOOSE)
    assert ok["attention"] == [] and ok["attention_total"] == 0 and ok["counts"]["by_dimension"]["demand_class"]["no_alert"] == 2
    mixed = rows + run_segments(_flat_frame(actual=0.0, yhat=0.5, n_series=4))
    order = [c["reason"] for c in sg.summary_block(mixed, LOOSE)["attention"]]
    assert order == sorted(order, key=lambda r: sg.REASON_RANK[r]), "peor que el baseline primero, luego indefinido, inestable e insuficiente"
    assert {"worse", "wape_undefined"} <= set(order)


def test_min_series_counts_only_series_with_evaluable_days():
    frame = _flat_frame(actual=5.0, yhat=5.0, n_series=4)
    dead = frame["unique_id"] == "b1-p4"
    frame.loc[dead, "stockout"] = True
    frame.loc[dead, "day_status"] = "stockout_censored"                              # una serie 100 % censurada en todas sus filas
    cfg = sg.SegmentConfig(min_series=4, min_obs=1)
    r = pick(run_segments(frame, cfg=cfg), "demand_class:intermittent", 14)
    assert r["metrics"]["sample"]["n_series"] == 3 and r["metrics"]["status"] == "insufficient_data"
    assert r["metrics"]["insufficient_reasons"] == [{"code": "few_series", "n_series": 3, "min": 4}]
    assert r["metrics"]["sample"]["excluded_days"] == 14, "sus días sí se cuentan como excluidos"
    enough = pick(run_segments(frame, cfg=sg.SegmentConfig(min_series=3, min_obs=1)), "demand_class:intermittent", 14)
    assert enough["metrics"]["status"] == "ok" and enough["metrics"]["sample"]["n_series"] == 3
    partial = _flat_frame(actual=5.0, yhat=5.0, n_series=4)
    partial.loc[(partial["unique_id"] == "b1-p4") & (partial["step"] <= 13), ["stockout", "day_status"]] = [True, "stockout_censored"]
    assert pick(run_segments(partial, cfg=cfg), "demand_class:intermittent", 14)["metrics"]["sample"]["n_series"] == 4, "con un día evaluable cuenta"


def test_series_without_a_category_go_to_category_none_so_the_dimension_still_partitions():
    bt = make_bt(censor=False)
    cat = CAT.drop([3, 5])                                                          # los productos 3 y 5 no tienen categoría
    test = sg.annotate(bt, cat, None)
    rows = sg.segment_rows(test, None, test[test["family"] == "auto_ets"], CTX, LOOSE)
    by_dim = {}
    for r in rows:
        if r["model_family"] == "selection" and r["horizon_days"] == 14:
            by_dim.setdefault(r["level_key"].split(":")[0], {})[r["level_key"]] = r["n_obs"]
    assert set(by_dim["category"]) == {"category:c10", "category:c20", "category:none"}
    assert sum(by_dim["category"].values()) == sum(by_dim["branch"].values()) == sum(by_dim["demand_class"].values())
    none = pick(rows, "category:none", 14)
    assert none["metrics"]["sample"]["n_series"] == 2 and none["wape"] is not None
    assert sg.annotate(bt.iloc[:3], pd.Series(dtype=float), None)["seg_category"].tolist() == ["none"] * 3


def test_static_texts_live_once_in_the_manifest_not_in_every_row():
    rows = run_segments(make_bt(censor=False))
    sel = pick(rows, "branch:b1", 14)
    m = sel["metrics"]
    assert "thresholds" not in m and "horizon_basis_text" not in m["segment"] and "basis" not in m["comparison"] and "basis" not in m["horizon_total"]
    assert m["definitions_ref"] == sg.DEFINITIONS_REF and m["comparison"]["similar_tolerance"] == LOOSE.similar_tolerance
    assert m["comparison"]["best_statistical_chosen_with"] in ("selection_windows", "same_windows_optimistic")
    assert m["stability"]["cv_warn"] == LOOSE.stability_cv_warn and m["denominator"]["min_required"] == LOOSE.wape_min_denominator
    manifest = sg.manifest_block(sg.SegmentConfig())
    assert manifest["thresholds"]["justification"].endswith("a aprobar en el piloto con datos reales") and manifest["detail"]["table"] == "model_evaluations"
    assert set(manifest) >= {"horizon_basis", "total_basis", "interval_basis", "comparison_basis", "best_statistical_chosen_with", "stability_basis"}
    assert sg.HORIZON_BASIS in manifest["horizon_basis"] and "ventanas de selección" in manifest["best_statistical_chosen_with"]["selection_windows"]
    assert sg.manifest_block(None) == {"version": sg.SEGMENT_METRICS_VERSION, "enabled": False}


def test_stability_leaves_out_the_windows_whose_denominator_is_below_the_wape_minimum():
    one = window_stability({0: (1.9, 5.0), 1: (0.30, 90.0), 2: (0.32, 95.0)}, cv_warn=0.5, min_denominator=30.0)
    assert one["status"] == "estimated" and one["n_windows"] == 2 and one["n_windows_small_denominator"] == 1
    assert one["wape_std"] == pytest.approx(np.std([0.30, 0.32], ddof=1)) and one["unstable_across_windows"] is False
    assert [w["used"] for w in one["per_window"]] == [False, True, True] and one["per_window"][0]["wape"] == 1.9 and one["min_denominator"] == 30.0
    single = window_stability({0: (1.9, 5.0), 1: (0.30, 90.0)}, min_denominator=30.0)
    assert single["status"] == "single_window" and single["wape_std"] is None
    none = window_stability({0: (1.9, 5.0), 1: (0.4, 8.0)}, cv_warn=0.5, min_denominator=30.0)
    assert none["status"] == "insufficient_denominator" and none["n_windows"] == 0 and none["wape_std"] is None and none["unstable_across_windows"] is None
    # el comportamiento anterior sigue disponible con umbral 0
    assert window_stability({0: (1.9, 5.0), 1: (0.4, 8.0)}, cv_warn=0.5)["status"] == "estimated"

    bt = make_bt(seed=3, windows=3, censor=False)
    bt.loc[bt["window"] == 0, "actual"] = 0.01                                      # ventana 0 con casi 0 unidades vendidas (denominador ≈ 0,5)
    cfg = sg.SegmentConfig(wape_min_denominator=30.0, min_series=1, min_obs=1)
    stab = pick(run_segments(bt, cfg=cfg), "branch:b1", 14)["metrics"]["stability"]
    assert stab["n_windows"] == 2 and stab["n_windows_small_denominator"] == 1 and [w["used"] for w in stab["per_window"]] == [False, True, True]
    used = [w["wape"] for w in stab["per_window"] if w["used"]]
    assert stab["wape_std"] == pytest.approx(np.std(used, ddof=1)) and stab["per_window"][0]["denominator"] < 30.0


def test_the_context_covers_the_pairs_it_was_built_for_and_a_failure_is_isolated():
    panel, _ = _context_world()
    c2 = panel.df["ds"].min() + pd.Timedelta(days=400)
    bt = pd.DataFrame({"cutoff": [c2] * 3, "unique_id": ["b1-p1", "b1-p2", "b1-p3"]})
    ctx, err = sg.safe_build_context(bt, panel, None, sg.SegmentConfig())
    assert err is None and ctx.covers(bt) and ctx.covers(bt.iloc[:2])
    assert not ctx.covers(pd.concat([bt, pd.DataFrame({"cutoff": [c2], "unique_id": ["b1-p6"]})])), "un par nuevo obliga a reconstruir"
    again, err = sg.safe_build_context(bt, panel, None, sg.SegmentConfig(), reuse=ctx)
    assert again is ctx and err is None, "con un contexto que cubre el backtest no se reconstruye"
    other, _ = sg.safe_build_context(pd.concat([bt, pd.DataFrame({"cutoff": [c2], "unique_id": ["b1-p6"]})]), panel, None, sg.SegmentConfig(), reuse=ctx)
    assert other is not ctx and other.covers(pd.concat([bt, pd.DataFrame({"cutoff": [c2], "unique_id": ["b1-p6"]})]))
    broken = sg.safe_build_context(bt.assign(unique_id="b1-p1"), object(), None, sg.SegmentConfig())      # panel inválido
    assert broken[0] is None and broken[1] and "AttributeError" in broken[1]
    assert sg.safe_build_context(bt.iloc[0:0], panel, None, sg.SegmentConfig()) == (None, None)
