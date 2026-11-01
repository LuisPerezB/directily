"""W3-A · escenarios de forma de serie (V2.5 §26 T03) a través del pipeline completo, con datos SINTÉTICOS generados aquí.

NO son pronósticos reales ni evidencia de precisión: cada escenario afirma una propiedad verificable del pipeline (qué modelo se sirve,
qué métricas quedan definidas o indefinidas, qué días se excluyen, qué conocimiento se usa) sobre series construidas a propósito.
Lo que exige datos reales de un cliente (precisión por segmento, calibración, estabilidad de verdad) queda BLOQUEADO hasta el piloto
(docs/ml/FORECAST_VALIDATION.md §6).
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd
import pytest

from _scenario_builders import N_DAYS, days, noisy, scenario_data, weekly
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import effective_config_record

IDX = days()


def run(data, overrides: dict | None = None):
    record = effective_config_record("demand", None, None, tenant_overrides=overrides) if overrides else None
    return run_pipeline(data, horizon=90, seed=42, n_jobs=1, config_record=record)


def seg(result, key: str, h: int, family: str = "selection", etype: str = "backtest") -> dict:
    found = [e for e in result.evaluations if e["level"] == "segment" and e["level_key"] == key and e["horizon_days"] == h
             and e["model_family"] == family and e["evaluation_type"] == etype]
    assert len(found) == 1, f"{etype}/{key}/H{h}/{family}: {len(found)} filas"
    return found[0]


def segment_rows(result) -> list[dict]:
    return [e for e in result.evaluations if e["level"] == "segment"]


def assert_all_finite(obj, path="") -> None:
    """Ninguna cifra de ninguna fila de segmento es NaN ni infinita: lo no estimable es null con su motivo."""
    if isinstance(obj, dict):
        for k, v in obj.items():
            assert_all_finite(v, f"{path}.{k}")
    elif isinstance(obj, (list, tuple)):
        for i, v in enumerate(obj):
            assert_all_finite(v, f"{path}[{i}]")
    elif isinstance(obj, (float, np.floating)):
        assert math.isfinite(obj), f"cifra no finita en {path}"


def reference_sums(result, key_filter, family: str, h: int) -> tuple[float, int]:
    """(Σ reales, días evaluables) del período final reservado para las series de `key_filter`, calculado desde las filas del backtest."""
    bt = result.backtest
    d = bt[bt["is_holdout"] & (bt["family"] == family) & bt["unique_id"].map(key_filter) & (bt["step"] <= h) & ~bt["stockout"]]
    return float(d["actual"].sum()), len(d)


# ───────────────────────────────────────────── 1. serie constante ─────────────────────────────────────────────

@pytest.fixture(scope="module")
def constant():
    return run(scenario_data({(1, p): np.full(N_DAYS, 4.0 + p) for p in range(1, 7)}))


def test_constant_series_the_served_model_does_not_worsen_the_baseline_and_the_scaled_metrics_are_undefined(constant):
    assert set(constant.summary["series_by_class"]) == {"smooth"}
    # período final: 6 series × H días ≥ 90 series-día solo desde H = 15 (a H = 7 y 14 el segmento es insufficient_data); ventanas de selección: todos
    for etype, horizons in (("backtest", (15, 30, 60, 90)), ("selection_windows", (7, 14, 15, 30, 60, 90))):
        for h in horizons:
            sel, base = seg(constant, "branch:b1", h, "selection", etype), seg(constant, "branch:b1", h, "seasonal_naive", etype)
            assert sel["metrics"]["status"] == "ok" and sel["metrics"]["wape_unstable"] is False
            assert sel["wape"] == pytest.approx(0.0, abs=1e-9) and base["wape"] == pytest.approx(0.0, abs=1e-9), "una serie constante se pronostica exacta"
            assert sel["wape"] <= base["wape"] + 1e-9, "lo servido no empeora al baseline"
            assert sel["metrics"]["denominator"]["sum_actual"] > 0, "WAPE 0 con denominador explícito, no 0/0"
    assert seg(constant, "branch:b1", 7)["metrics"]["status"] == "insufficient_data"
    sel = seg(constant, "branch:b1", 30)
    assert sel["metrics"]["mase"] is None and sel["metrics"]["rmsse"] is None, "escala del naive de entrenamiento = 0: MASE indefinido, no cero"
    assert sel["metrics"]["sample"]["exclusions"]["scale_undefined"] == sel["n_obs"]
    den, n = reference_sums(constant, lambda u: u.startswith("b1-"), "seasonal_naive", 30)
    assert sel["metrics"]["denominator"]["sum_actual"] == pytest.approx(den) and sel["n_obs"] == n == 6 * 30
    assert sel["metrics"]["comparison"]["selected_vs_baseline"]["verdict"] == "similar"
    assert_all_finite([e["metrics"] for e in segment_rows(constant)])


# ───────────────────────────────────────────── 2. serie irregular ─────────────────────────────────────────────

@pytest.fixture(scope="module")
def irregular():
    return run(scenario_data({(1, p): noisy(5 + p, seed=p) for p in range(1, 9)}))


def test_irregular_series_metrics_are_defined_with_explicit_denominators_and_cover_every_dimension(irregular):
    r = seg(irregular, "branch:b1", 30)
    m = r["metrics"]
    den, n = reference_sums(irregular, lambda u: u.startswith("b1-"), "seasonal_naive", 30)
    assert m["status"] == "ok" and m["wape_status"] == "ok" and r["wape"] is not None and 0.0 < r["wape"] < 1.5
    assert m["denominator"]["sum_actual"] == pytest.approx(den) and r["n_obs"] == n
    assert m["mase"] is not None and m["rmsse"] is not None and m["sample"]["exclusions"]["scale_undefined"] == 0
    assert m["pinball_p10"] is not None and m["pinball_p90"] is not None and 0.0 < r["coverage_80"] <= 1.0 and 0.0 < r["coverage_95"] <= 1.0
    assert m["interval_width_95"] > 0 and m["interval"]["n_scored"] == r["n_obs"]
    keys = {e["level_key"].split(":")[0] for e in segment_rows(irregular)}
    assert keys == {"demand_class", "category", "branch", "volume_tier", "series_age"}
    # en las ventanas que eligieron el modelo el servido no pierde contra el baseline (es su criterio de selección)
    assert seg(irregular, "branch:b1", 30, "selection", "selection_windows")["wape"] <= seg(
        irregular, "branch:b1", 30, "seasonal_naive", "selection_windows")["wape"] + 1e-9
    stab = seg(irregular, "branch:b1", 30, "selection", "selection_windows")["metrics"]["stability"]
    assert stab["status"] == "estimated" and stab["n_windows"] == 3 and stab["wape_std"] is not None
    assert m["stability"]["status"] == "single_window" and m["stability_selection_windows"]["n_windows"] == 3


def test_every_dimension_partitions_the_evaluable_observations_of_the_served_model(irregular):
    overall = [e for e in irregular.evaluations if e["level"] == "overall" and e["model_family"] == "selection"
               and e["evaluation_type"] == "backtest"][0]
    for dim in ("demand_class", "category", "branch", "volume_tier", "series_age"):
        parts = [e for e in segment_rows(irregular) if e["level_key"].startswith(dim + ":") and e["horizon_days"] == 90
                 and e["model_family"] == "selection" and e["evaluation_type"] == "backtest"]
        assert sum(p["n_obs"] for p in parts) == overall["n_obs"], f"{dim}: los segmentos son una partición de las observaciones"
        total_abs = sum(p["metrics"]["sum_abs_error"] for p in parts if p["metrics"]["sum_abs_error"] is not None)
        if all(p["metrics"]["status"] == "ok" for p in parts):
            assert total_abs / sum(p["metrics"]["denominator"]["sum_actual"] for p in parts) == pytest.approx(overall["wape"])
    top = seg(irregular, "volume_tier:top20", 90)
    assert top["metrics"]["sample"]["n_series"] == 2, "20 % de 8 series = ⌈1,6⌉ = 2"


def test_segment_pinball_and_interval_width_equal_the_overall_ones_when_the_segment_is_the_whole_population(irregular):
    overall = [e for e in irregular.evaluations if e["level"] == "overall" and e["model_family"] == "selection"
               and e["evaluation_type"] == "backtest"][0]["metrics"]
    whole = seg(irregular, "series_age:mature", 90)["metrics"]
    assert whole["sample"]["n_series"] == 8
    for k in ("pinball_p10", "pinball_p90", "interval_width_80", "interval_width_95"):
        assert whole[k] == pytest.approx(overall[k]), k
    assert whole["pinball_p50"] == overall["pinball_p50"], "la mediana se publica (o no) con la misma decisión que el resto de las filas"


# ───────────────────────────────────────────── 3. ceros / intermitente ─────────────────────────────────────────────

@pytest.fixture(scope="module")
def zeros():
    pat = {(1, p): noisy(5 + p, seed=p) for p in range(1, 6)}
    for p in range(6, 9):          # una venta de 1 unidad cada 14 días y NADA en los últimos ~100 días (activas, pero sin demanda en el período final)
        a = np.zeros(N_DAYS)
        a[0:320:14] = 1.0
        pat[(1, p)] = a
    return run(scenario_data(pat))


def test_zero_demand_segment_has_no_wape_but_an_explicit_alternative_and_no_invented_number(zeros):
    assert zeros.summary["series_by_class"] == {"smooth": 5, "intermittent": 3}
    r = seg(zeros, "demand_class:intermittent", 30)
    m = r["metrics"]
    assert m["denominator"]["sum_actual"] == 0.0 and reference_sums(zeros, lambda u: u in {"b1-p6", "b1-p7", "b1-p8"}, "seasonal_naive", 30)[0] == 0.0
    assert r["wape"] is None and m["wape"] is None and m["wape_raw"] is None and r["bias"] is None
    assert m["wape_status"] == "undefined" and m["wape_unstable"] is True
    assert m["alternative_metric"] is not None and m["alternative_metric"]["used_because"] == "wape_undefined"
    assert m["alternative_metric"]["name"] in ("mase", "aggregate_absolute_error")
    assert r["mae"] is not None and math.isfinite(r["mae"]) and m["sum_abs_error"] is not None and m["mean_error"] is not None
    assert m["horizon_total"]["wape"] is None and m["horizon_total"]["wape_status"] == "undefined"
    healthy = seg(zeros, "demand_class:smooth", 30)
    assert healthy["wape"] is not None and healthy["metrics"]["wape_unstable"] is False and healthy["metrics"]["alternative_metric"] is None
    assert_all_finite([e["metrics"] for e in segment_rows(zeros)])


def test_few_sales_make_the_wape_unstable_in_the_selection_windows_and_few_days_make_the_segment_insufficient(zeros):
    # ventanas de selección: algunas ventas (denominador > 0 pero < 30 u): WAPE inestable, con su valor crudo aparte
    r = seg(zeros, "demand_class:intermittent", 30, "selection", "selection_windows")
    den = r["metrics"]["denominator"]["sum_actual"]
    assert 0 < den < 30 and r["metrics"]["wape_status"] == "unstable" and r["wape"] is None
    assert r["metrics"]["wape_raw"] is not None and r["metrics"]["alternative_metric"]["used_because"] == "wape_unstable"
    # H = 7: 3 series × 7 días = 21 series-día < 90 ⇒ insufficient_data, sin cifras
    short = seg(zeros, "demand_class:intermittent", 7)
    assert short["metrics"]["status"] == "insufficient_data" and short["wape"] is None and short["mae"] is None and short["metrics"]["mase"] is None
    assert short["metrics"]["insufficient_reasons"][0]["code"] == "few_observations" and short["n_obs"] == 21
    # el resumen compacto de la ejecución lo declara (celdas de atención) y el detalle completo está en model_evaluations
    block = zeros.summary["segment_metrics"]
    cells = {(c["segment"], c["horizon"]): c for c in block["attention"]}
    assert len(block["attention"]) <= 20 and block["attention_total"] == sum(block["counts"]["by_reason"].values())
    undefined = cells[("demand_class:intermittent", 30)]
    assert undefined["reason"] == "wape_undefined" and undefined["wape"] is None and undefined["denominator"] == 0.0
    ranks = [{"worse": 0, "wape_undefined": 1, "wape_unstable": 2, "insufficient_data": 3}[c["reason"]] for c in block["attention"]]
    assert ranks == sorted(ranks), "las celdas se listan por gravedad: el WAPE indefinido antes que la falta de datos"
    assert block["counts"]["by_reason"]["insufficient_data"] >= 1 and block["counts"]["by_dimension"]["demand_class"]["insufficient_data"] >= 1
    assert block["counts"]["by_dimension"]["demand_class"]["wape_undefined"] >= 1
    assert ("demand_class:smooth", 30) not in cells, "el segmento sano no genera alerta"


# ───────────────────────────────────────────── 4. varios picos ─────────────────────────────────────────────

@pytest.fixture(scope="module")
def spikes():
    rng = np.random.default_rng(7)
    pat = {(1, p): weekly(5 + p, 0.0, N_DAYS) for p in range(1, 5)}
    for p in range(5, 9):
        a = np.full(N_DAYS, 4.0)
        idx = rng.choice(N_DAYS, 28, replace=False)
        a[idx] = 30 + rng.integers(0, 10, 28)
        pat[(1, p)] = a
    return run(scenario_data(pat))


def test_series_with_several_spikes_are_erratic_keep_their_spikes_in_the_error_and_widen_the_intervals(spikes):
    classes = spikes.panel.profile.set_index("unique_id")["demand_class"]
    assert set(classes[[f"b1-p{p}" for p in range(5, 9)]]) <= {"erratic", "lumpy"} and set(classes[[f"b1-p{p}" for p in range(1, 5)]]) == {"smooth"}
    erratic, smooth = seg(spikes, "demand_class:erratic", 30), seg(spikes, "demand_class:smooth", 30)
    assert erratic["metrics"]["status"] == smooth["metrics"]["status"] == "ok"
    assert smooth["wape"] == pytest.approx(0.0, abs=1e-9) and erratic["wape"] > 0.1, "los picos NO se descartan: están en el error"
    den, n = reference_sums(spikes, lambda u: u in {f"b1-p{p}" for p in range(5, 9)}, "seasonal_naive", 30)
    assert erratic["metrics"]["denominator"]["sum_actual"] == pytest.approx(den) and erratic["n_obs"] == n
    assert den > 4.0 * n, "el denominador incluye los días de pico"
    assert erratic["metrics"]["sample"]["excluded_days"] == 0, "un pico no es un día no evaluable"
    assert erratic["metrics"]["interval_width_95"] > smooth["metrics"]["interval_width_95"] == 0.0, "la incertidumbre se refleja en la anchura"
    assert erratic["metrics"]["interval"]["interval_width_95_rel"] > smooth["metrics"]["interval"]["interval_width_95_rel"]
    cmp_ = erratic["metrics"]["comparison"]["selected_vs_baseline"]
    assert cmp_["verdict"] in ("better", "similar", "worse") and cmp_["metric"] == "wape"


# ───────────────────────────────────────────── 5. ruptura estacional ─────────────────────────────────────────────

def _break_pattern(level: float, seed: int, at: int | None) -> np.ndarray:
    rng = np.random.default_rng(seed)
    if at is None:
        lam = weekly(level, 3.0, N_DAYS)
    else:        # cambia el nivel (×2) y la fase semanal (3 días) en el día `at`
        lam = np.concatenate([weekly(level, 3.0, at), weekly(level * 2.0, 3.0, N_DAYS - at, phase=3)])
    return rng.poisson(np.clip(lam, 0.1, None)).astype(float)


@pytest.fixture(scope="module")
def seasonal_break():
    cfg = {"segment_stability_cv_warn": 0.2}
    brk = run(scenario_data({(1, p): _break_pattern(5 + p, p, 250) for p in range(1, 7)}), cfg)
    ctl = run(scenario_data({(1, p): _break_pattern(5 + p, p, None) for p in range(1, 7)}), cfg)
    return brk, ctl


def test_a_seasonal_break_shows_up_as_instability_between_windows_but_a_stable_series_does_not(seasonal_break):
    brk, ctl = seasonal_break
    sb = seg(brk, "branch:b1", 30, "selection", "selection_windows")["metrics"]["stability"]
    sc = seg(ctl, "branch:b1", 30, "selection", "selection_windows")["metrics"]["stability"]
    assert sb["n_windows"] == sc["n_windows"] == 3 and sb["status"] == sc["status"] == "estimated"
    first, second, last = (w["wape"] for w in sb["per_window"])
    assert last > 1.4 * max(first, second), "la ventana cuyo horizonte contiene la ruptura (día 250) empeora claramente"
    assert sb["wape_std"] > 3 * sc["wape_std"]
    assert sb["unstable_across_windows"] is True and sc["unstable_across_windows"] is False
    base = seg(brk, "branch:b1", 30, "seasonal_naive", "selection_windows")["metrics"]["stability"]
    assert [w["wape"] for w in base["per_window"]][-1] > [w["wape"] for w in base["per_window"]][0], "el baseline también se degrada tras la ruptura"
    assert [w["cutoff"] for w in sb["per_window"]] == [c.date().isoformat() for c in brk.plan.cutoffs]


def test_the_stability_threshold_is_configurable_and_recorded(seasonal_break):
    brk, _ = seasonal_break
    assert seg(brk, "branch:b1", 30)["metrics"]["stability"]["cv_warn"] == 0.2
    assert brk.manifest["segment_metrics"]["thresholds"]["stability_cv_warn"] == 0.2, "el umbral queda registrado una vez en el manifiesto"
    default = run(scenario_data({(1, p): _break_pattern(5 + p, p, 250) for p in range(1, 7)}))
    stab = seg(default, "branch:b1", 30, "selection", "selection_windows")["metrics"]["stability"]
    assert stab["wape_cv"] == pytest.approx(
        seg(brk, "branch:b1", 30, "selection", "selection_windows")["metrics"]["stability"]["wape_cv"])
    assert stab["unstable_across_windows"] is False, "con el umbral por defecto (0,5) el mismo dato no se marca: el umbral decide, no el motor"


# ───────────────────────────────────────────── 6. promoción conocida ex ante ─────────────────────────────────────────────

def _promo_data(late: bool, keep_future: bool = True):
    """Seis series con promociones de 7 días cada 28 (unidades ×2,5, precio −25 %). `late`: las del período final se registran DESPUÉS
    del origen del período final reservado (no se conocían). `keep_future=False`: esas promociones no existen en la tabla."""
    origin = IDX[N_DAYS - 91]
    pat, price, promos, pid = {}, {}, [], 0
    for p in range(1, 7):
        rng = np.random.default_rng(p)
        base = np.clip(weekly(6 + p, 2.0, N_DAYS) + rng.normal(0, 0.6, N_DAYS), 0.1, None)
        mult, pr = np.ones(N_DAYS), np.full(N_DAYS, 10.0)
        for s0 in range(10 + 3 * p, N_DAYS, 28):
            e0 = min(s0 + 7, N_DAYS)
            mult[s0:e0], pr[s0:e0] = 2.5, 7.5
            starts, ends = IDX[s0], IDX[e0 - 1]
            if starts > origin and not keep_future:
                continue
            known = starts - pd.Timedelta(days=21)
            if late and starts > origin:
                known = origin + pd.Timedelta(days=5)
            pid += 1
            promos.append({"promotion_id": pid, "discount_pct": 25.0, "starts_on": starts, "ends_on": ends, "branch_id": 1, "product_id": p,
                           "category_id": 1 + (p % 2), "known_at": known})
        pat[(1, p)], price[(1, p)] = np.round(base * mult), pr
    return scenario_data(pat, price=price, promotions=pd.DataFrame(promos)), pd.DataFrame(promos)


@pytest.fixture(scope="module")
def promo_runs():
    known_data, promos = _promo_data(late=False)
    out = {"known": (run(known_data), promos), "late": (run(_promo_data(late=True)[0]), promos),
           "removed": (run(_promo_data(late=False, keep_future=False)[0]), promos)}
    return out


def _promo_day_wape(result, promos: pd.DataFrame, family: str) -> float:
    h = result.backtest
    h = h[h["is_holdout"] & (h["family"] == family) & ~h["stockout"]]
    mask = np.zeros(len(h), bool)
    for p in promos.itertuples():
        mask |= ((h["unique_id"] == f"b1-p{p.product_id}") & (h["ds"] >= p.starts_on) & (h["ds"] <= p.ends_on)).to_numpy()
    assert mask.sum() > 50, "el período final tiene días de promoción que evaluar"
    return float((h["yhat"] - h["actual"]).abs()[mask].sum() / h["actual"][mask].sum())


def test_a_promotion_known_at_the_origin_helps_ml_but_one_registered_later_does_not_and_the_baseline_ignores_both(promo_runs):
    known, promos = promo_runs["known"]
    late, _ = promo_runs["late"]
    assert _promo_day_wape(known, promos, "seasonal_naive") == pytest.approx(_promo_day_wape(late, promos, "seasonal_naive")), \
        "Seasonal Naive no usa promociones: su error es idéntico con o sin conocimiento previo"
    ml_known, ml_late = _promo_day_wape(known, promos, "lightgbm_global"), _promo_day_wape(late, promos, "lightgbm_global")
    assert ml_known < 0.9 * ml_late, f"conocida ex ante ({ml_known:.3f}) debe reducir el error en días de promoción frente a conocida tarde ({ml_late:.3f})"


def test_a_promotion_registered_after_the_origin_changes_no_backtest_forecast_at_all(promo_runs):
    late, _ = promo_runs["late"]
    removed, _ = promo_runs["removed"]
    keys = ["unique_id", "window", "ds", "family"]
    both = late.backtest.merge(removed.backtest, on=keys, suffixes=("_late", "_removed"), validate="one_to_one")
    assert len(both) == len(late.backtest) == len(removed.backtest) > 0
    np.testing.assert_allclose(both["yhat_late"], both["yhat_removed"], rtol=1e-9, atol=1e-9)


def test_the_segment_metrics_separate_baseline_and_ml_in_the_promotion_scenario(promo_runs):
    known, _ = promo_runs["known"]
    base, ml = seg(known, "branch:b1", 30, "seasonal_naive"), seg(known, "branch:b1", 30, "lightgbm_global")
    assert base["metrics"]["status"] == ml["metrics"]["status"] == "ok" and base["wape"] is not None and ml["wape"] is not None
    cmp_ = seg(known, "branch:b1", 30)["metrics"]["comparison"]
    assert cmp_["ml_vs_baseline"]["family"] == "lightgbm_global" and cmp_["ml_vs_baseline"]["verdict"] in ("better", "similar", "worse")
    assert cmp_["ml_vs_baseline"]["baseline_value"] == pytest.approx(base["wape"])


# ───────────────────────────────────────────── 7. quiebre de 10 días ─────────────────────────────────────────────

STOCKOUT_FROM, STOCKOUT_DAYS = 350, 10          # dentro del período final reservado (origen en el día 329)


def _stockout_data(with_inventory: bool):
    pat = {(1, p): noisy(6 + p, seed=p) for p in range(1, 7)}
    pat[(1, 1)] = pat[(1, 1)].copy()
    pat[(1, 1)][STOCKOUT_FROM:STOCKOUT_FROM + STOCKOUT_DAYS] = 0.0         # no hubo venta porque no había existencias
    inv = None
    if with_inventory:
        rows = [{"branch_id": 1, "product_id": p, "ds": d, "on_hand": 0.0 if (p == 1 and STOCKOUT_FROM <= k < STOCKOUT_FROM + STOCKOUT_DAYS) else 50.0,
                 "reserved": 0.0, "in_transit": 0.0, "unit_cost": 4.0} for p in range(1, 7) for k, d in enumerate(IDX)]
        inv = pd.DataFrame(rows)
    return scenario_data(pat, inventory=inv)


@pytest.fixture(scope="module")
def stockout():
    return run(_stockout_data(True)), run(_stockout_data(False)), _stockout_data(True)


def test_a_ten_day_stockout_is_censored_counted_and_kept_out_of_the_wape(stockout):
    marked, unmarked, _ = stockout
    flagged = marked.panel.df[marked.panel.df["stockout"]]
    assert set(flagged["unique_id"]) == {"b1-p1"} and len(flagged) == STOCKOUT_DAYS + 1, \
        "10 días con existencias 0 + el día siguiente, que empieza sin stock (regla vigente del panel)"
    assert (marked.panel.df["day_status"] == "stockout_censored").sum() == STOCKOUT_DAYS + 1 and not unmarked.panel.df["stockout"].any()
    r = seg(marked, "branch:b1", 90)
    assert r["metrics"]["sample"]["exclusions"] == {"scale_undefined": 0, "stockout_days": STOCKOUT_DAYS + 1, "missing_source_days": 0, "closed_days": 0}
    assert r["n_obs"] == 6 * 90 - (STOCKOUT_DAYS + 1) and r["metrics"]["sample"]["total_days"] == 6 * 90
    bt = marked.backtest
    h = bt[bt["is_holdout"] & (bt["family"] == "seasonal_naive") & bt["unique_id"].str.startswith("b1-")]
    clean, dirty = h[~h["stockout"]], h
    assert r["metrics"]["denominator"]["sum_actual"] == pytest.approx(
        seg(marked, "branch:b1", 90, "seasonal_naive")["metrics"]["denominator"]["sum_actual"]) == pytest.approx(clean["actual"].sum())
    wape_clean = (clean["yhat"] - clean["actual"]).abs().sum() / clean["actual"].sum()
    wape_if_counted = (dirty["yhat"] - dirty["actual"]).abs().sum() / dirty["actual"].sum()
    assert seg(marked, "branch:b1", 90, "seasonal_naive")["wape"] == pytest.approx(wape_clean)
    assert wape_if_counted > 1.01 * wape_clean, "si los días sin venta por quiebre contaran como ceros observados, el WAPE sería mayor"
    assert seg(marked, "category:c2", 90)["metrics"]["sample"]["exclusions"]["stockout_days"] == STOCKOUT_DAYS + 1
    assert seg(marked, "category:c1", 90)["metrics"]["sample"]["exclusions"]["stockout_days"] == 0


def test_the_stockout_does_not_contaminate_the_training_of_the_global_model_or_the_forecast_level(stockout):
    marked, unmarked, data = stockout
    cutoff = pd.Timestamp(data.cutoff)
    fb = FeatureBuilder(marked.panel.df, data.branches, data.promotions, (cutoff + pd.Timedelta(days=90)).date())
    train, _ = fb.training_frame(cutoff, ["b1-p1", "b1-p2"])
    censored = marked.panel.df.loc[marked.panel.df["stockout"], ["unique_id", "ds"]]
    t = train.merge(censored.assign(c=True), on=["unique_id", "ds"], how="left")
    assert (t.loc[t["c"].eq(True), "weight"] == 0.0).all() and t["c"].eq(True).sum() == STOCKOUT_DAYS + 1, "los días con quiebre pesan 0 en LightGBM"
    assert (t.loc[~t["c"].eq(True), "weight"] == 1.0).all()

    def level(res):
        fv = res.forecast_values
        f = fv[(fv["granularity"] == "day") & (fv["level"] == "series") & (fv["node_key"] == "series:1:1") & (fv["horizon_step"] <= 14)]
        assert len(f) == 14
        return float(f["yhat"].mean())

    series = marked.panel.df[(marked.panel.df["unique_id"] == "b1-p1") & ~marked.panel.df["stockout"]].tail(120)["units"].mean()
    assert abs(level(marked) - series) / series < 0.05, "con el quiebre censurado el nivel pronosticado es el de la serie sin quiebre"
    assert level(marked) > 1.05 * level(unmarked), "tratado como ceros observados, el quiebre arrastraría el pronóstico hacia abajo"


# ───────────────────────────────────────────── 8. historia corta: sin período final independiente ─────────────────────────────────────────────

def test_without_an_independent_final_period_the_segments_are_labeled_optimistic_and_have_no_interval_evidence():
    n = 75          # < 84 días: el plan no alcanza para reservar un período final (solo horizontes de 7 y 14 días, 2 ventanas)
    result = run_pipeline(scenario_data({(1, p): noisy(5 + p, seed=p, n=n) for p in range(1, 8)}), horizon=14, seed=42, n_jobs=1)
    assert result.plan.holdout_cutoff is None and {e["evaluation_type"] for e in result.evaluations} == {"backtest"}
    rows = segment_rows(result)
    assert rows and {e["horizon_days"] for e in rows} == {7, 14}, "solo los horizontes que el backtest alcanza"
    assert {e["extra"]["evaluation_scope"] for e in rows} == {"selection_windows"} and not any(e["extra"]["independent_holdout"] for e in rows)
    r = seg(result, "branch:b1", 14)
    assert r["metrics"]["status"] == "ok" and r["wape"] is not None and r["n_windows"] == 2
    assert r["coverage_80"] is None and r["metrics"]["pinball_p10"] is None and r["metrics"]["interval"]["available"] is False
    assert r["metrics"]["comparison"]["best_statistical_chosen_with"] == "same_windows_optimistic"
    assert "optimista" in result.manifest["segment_metrics"]["best_statistical_chosen_with"]["same_windows_optimistic"]
    block = result.summary["segment_metrics"]
    assert block["scope"] == "selection_windows" and block["independent_holdout"] is False and block["horizons_evaluated"] == [7, 14]
    assert block["horizons_not_evaluated"] == [15, 30, 60, 90]


# ───────────────────────────────────────────── 9. aislamiento: las métricas por segmento nunca abortan la ejecución ─────────────────────────────────────────────

def _irregular_data():
    return scenario_data({(1, p): noisy(5 + p, seed=p) for p in range(1, 9)})


@pytest.mark.parametrize("target", ["segment_rows", "build_context"])
def test_a_failure_in_the_segment_metrics_is_recorded_and_changes_nothing_that_is_served(irregular, monkeypatch, target):
    from predictily_engine.demand import segments as sg

    def boom(*a, **k):
        raise RuntimeError("fallo simulado de segmentos")

    monkeypatch.setattr(sg, target, boom)
    failed = run(_irregular_data())
    assert not any(e["level"] == "segment" for e in failed.evaluations)
    codes = [x["code"] for x in failed.limitations]
    assert codes.count("segment_metrics_unavailable") == 1 and "segment_metrics_unavailable" in failed.summary["limitation_codes"]
    msg = next(x["message"] for x in failed.limitations if x["code"] == "segment_metrics_unavailable")
    assert "RuntimeError" in msg and "no cambiaron" in msg
    block = failed.summary["segment_metrics"]
    assert block["available"] is False and "RuntimeError" in block["error"] and block["attention"] == [] and block["counts"]["cells"] == 0
    assert "segment_metrics_unavailable" not in irregular.summary["limitation_codes"]
    pd.testing.assert_frame_equal(failed.forecast_values.reset_index(drop=True), irregular.forecast_values.reset_index(drop=True))
    assert failed.selection.winners == irregular.selection.winners and failed.summary["metrics"] == irregular.summary["metrics"]
    assert [e for e in irregular.evaluations if e["level"] != "segment"] == failed.evaluations, "las filas previas siguen completas e iguales"


def test_the_segment_context_is_built_once_and_shared_by_the_promotion_gate_and_the_metrics(monkeypatch):
    from predictily_engine.demand import segments as sg

    calls = []
    original = sg.build_context

    def counting(*a, **k):
        calls.append(1)
        return original(*a, **k)

    monkeypatch.setattr(sg, "build_context", counting)
    result = run(_irregular_data())
    assert len(calls) == 1, "el gate de segmentos de W3-B y las métricas por segmento comparten el mismo contexto as-of"
    assert any(e["level"] == "segment" for e in result.evaluations)
    import json

    evidence = json.dumps(result.promotion.rows(), default=str)
    assert "demand.segments.make_provider" in evidence, "el gate de W3-B usó los segmentos as-of de W3-A (no los de respaldo)"
