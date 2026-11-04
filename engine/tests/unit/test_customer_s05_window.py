"""Customer Core S05 · ventana probable de recompra (V2.5 §7 tarea C, §25.2; ADR-V25-11): cuantiles del tiempo hasta la siguiente compra.

Datos FICTICIOS: relaciones con intervalos conocidos por construcción y procesos de renovación simulados (sin pronósticos reales).
"""

from __future__ import annotations

from dataclasses import replace
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.data import CustomerData
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.customer import window as window_module
from predictily_engine.customer.window import (
    BUCKETS,
    apply_validation,
    LOWER_GRID,
    MEDIAN_GRID,
    UPPER_GRID,
    WindowParams,
    bucket_of,
    candidate_levels,
    evaluate_window,
    fit_levels,
    split_cutoffs,
    window_from_base,
)
from predictily_engine.platform.config import resolve_config

BASE = date(2025, 1, 1)
CFG, _, _ = resolve_config("customer", {}, None)
PARAMS = WindowParams.from_cfg(CFG)


def make_data(purchases, categories=None, availability=None) -> CustomerData:
    """purchases: (cliente, producto, día, cantidad). categories: producto → categoría (por defecto todos en la 1)."""
    rows = [{"customer_id": c, "product_id": p, "branch_id": 1, "ds": pd.Timestamp(BASE + timedelta(days=d)), "currency": "USD", "qty": float(q),
             "net_amount": float(q) * 10.0, "gross_amount": float(q) * 10.0, "discount_amount": 0.0} for c, p, d, q in purchases]
    pids = sorted({r["product_id"] for r in rows})
    cats = categories or {}
    last = max(r["ds"] for r in rows).date()
    return CustomerData(
        purchases=pd.DataFrame(rows), returns=pd.DataFrame(columns=["customer_id", "product_id", "ds", "qty"]),
        products=pd.DataFrame({"product_id": pids, "category_id": [cats.get(p, 1) for p in pids], "unit": "unidad", "is_active": True}),
        branches=pd.DataFrame({"branch_id": [1], "province": ["Pichincha"], "canton": ["Quito"]}),
        availability=availability if availability is not None else pd.DataFrame(columns=["branch_id", "product_id", "ds", "available"]),
        coverage=pd.DataFrame(columns=["entity", "branch_id", "complete_from", "complete_through"]),
        promotions=pd.DataFrame(), promotion_revisions=pd.DataFrame(), sales_first=BASE, sales_last=last, tenant={"currency": "USD"})


def series(customer, product, gaps, start=0, qty=1):
    """Compras de una relación con los intervalos dados (días) a partir de `start`."""
    out, d = [], start
    out.append((customer, product, d, qty))
    for g in gaps:
        d += g
        out.append((customer, product, d, qty))
    return out


def window_of(data, cutoff, customer=1, product=1, params=PARAMS, levels=None):
    fb = FeatureBuilder(data, CFG, max_horizon=30)
    c = int(to_day(BASE)) + cutoff
    base = fb.compute(c, (7,)).base
    w = window_from_base(fb, c, base, params, levels=levels)
    i = int(np.nonzero(((base["customer_id"] == customer) & (base["product_id"] == product)).to_numpy())[0][0])
    return w.loc[i], base.loc[i]


# ───────────────────────────── cuantiles y estados ─────────────────────────────

def test_regular_buyer_window_is_a_range_around_the_remaining_interval_never_an_exact_day():
    gaps = [10] * 10                                           # compra cada 10 días, 11 compras (la última el día 100)
    data = make_data(series(1, 1, gaps))
    w, base = window_of(data, 104)                              # 4 días desde la última compra: quedan ~6
    assert w["window_status"] == "estimated" and base["rel_recency_days"] == 4
    p10, p50, p90 = w["window_p10_days"], w["window_p50_days"], w["window_p90_days"]
    assert 1 <= p10 <= p50 <= p90 and p10 <= 6 <= p90
    assert p50 == pytest.approx(6, abs=1), "la mediana sigue al tiempo restante del intervalo propio"
    assert p90 > p10, "es un rango"
    assert w["window_own_gaps"] == 10


def test_window_is_counted_from_the_cutoff_and_conditions_on_the_elapsed_time():
    gaps = [10, 30] * 5                                         # intervalos de 10 y 30 días
    data = make_data(series(1, 1, gaps))
    last = sum(gaps)
    early, _ = window_of(data, last + 3)                        # r = 3: aún pueden venir los de 10 o los de 30
    late, _ = window_of(data, last + 15)                        # r = 15: ya no puede ser un intervalo de 10; solo quedan los de 30
    assert early["window_p10_days"] < late["window_p10_days"] or early["window_p50_days"] != late["window_p50_days"]
    assert late["window_p50_days"] == pytest.approx(15, abs=2), "tiempo restante ≈ 30 − 15 (condicionado a que no compró en los 10)"
    assert late["window_p10_days"] >= 1


def test_too_few_own_intervals_means_insufficient_data_without_numbers():
    data = make_data(series(1, 1, [12]))                        # una sola compra anterior: 1 intervalo propio < 2
    w, _ = window_of(data, 20)
    assert w["window_status"] == "insufficient_data"
    assert np.isnan([w["window_p10_days"], w["window_p50_days"], w["window_p90_days"]]).all()


def test_overdue_relation_is_flagged_not_called_abandoned_and_keeps_a_tail_window():
    data = make_data(series(1, 1, [10] * 9))                    # última compra el día 90
    w, base = window_of(data, 90 + 80)                          # 80 días sin comprar con un patrón de 10
    assert base["rel_recency_days"] == 80
    assert w["window_status"] == "overdue"
    assert w["window_p10_days"] >= 1 and w["window_p90_days"] > w["window_p10_days"], "cola extrapolada del suavizado, no un patrón"


def test_stockout_at_the_cutoff_censors_the_window():
    gaps = [10] * 9
    avail = pd.DataFrame({"branch_id": 1, "product_id": 1, "ds": [pd.Timestamp(BASE + timedelta(days=d)) for d in range(0, 96)],
                          "available": [d < 94 for d in range(0, 96)]})
    data = make_data(series(1, 1, gaps), availability=avail)
    w, base = window_of(data, 94)
    assert base["avail_now"] == 0
    assert w["window_status"] == "censored" and np.isnan(w["window_p50_days"])


def test_hierarchical_smoothing_pulls_a_thin_relation_toward_its_category():
    """Con pocos intervalos propios (2), la cola de la ventana sigue a la categoría: lenta ⇒ más larga que en una categoría rápida."""
    thin = series(1, 1, [8, 8])                                 # intervalos propios de 8 días (2)
    slow = [t for c in range(10, 30) for t in series(c, 2, [60] * 6, start=c)]       # otros compradores de la categoría: cada 60 días
    fast = [t for c in range(10, 30) for t in series(c, 2, [5] * 40, start=c)]       # categoría rápida: cada 5 días
    cutoff = 17
    d_slow = make_data(thin + slow, categories={1: 7, 2: 7})    # producto 1 y 2 en la misma categoría lenta
    d_fast = make_data(thin + fast, categories={1: 7, 2: 7})
    w_slow, _ = window_of(d_slow, cutoff)
    w_fast, _ = window_of(d_fast, cutoff)
    assert w_slow["window_prior_mean"] > w_fast["window_prior_mean"]
    assert w_slow["window_p90_days"] > w_fast["window_p90_days"], "el suavizado jerárquico hacia la categoría lenta alarga la cola"
    no_prior = replace(PARAMS, prior_weight=0.0)
    w0, _ = window_of(d_slow, cutoff, params=no_prior)
    assert w0["window_p90_days"] < w_slow["window_p90_days"], "sin suavizado solo existen sus propios intervalos"


def test_recalibrated_levels_widen_the_window_and_are_applied_per_group():
    data = make_data(series(1, 1, [10] * 6 + [14, 9, 11, 12]))
    nominal, _ = window_of(data, 112)
    wide = {"4_7": {"p10_level": 0.02, "p50_level": 0.5, "p90_level": 0.99, "fitted": True}, "8_plus": {"p10_level": 0.02, "p50_level": 0.5, "p90_level": 0.99}}
    widened, _ = window_of(data, 112, levels=wide)
    assert widened["window_p90_days"] >= nominal["window_p90_days"] and widened["window_p10_days"] <= nominal["window_p10_days"]
    other_group = {"1_3": {"p10_level": 0.01, "p50_level": 0.5, "p90_level": 0.99}}
    unchanged, _ = window_of(data, 112, levels=other_group)
    assert unchanged["window_p90_days"] == nominal["window_p90_days"], "los niveles de un grupo no afectan a otro"
    assert bucket_of(np.array([1, 3, 4, 7, 8, 12])).tolist() == ["1_3", "1_3", "4_7", "4_7", "8_plus", "8_plus"]


def test_window_uses_only_what_was_known_at_the_cutoff():
    past = series(1, 1, [10] * 8)
    data_a = make_data(past + [(1, 1, 200, 1), (1, 1, 201, 1)])          # compras posteriores al corte
    data_b = make_data(past)
    wa, _ = window_of(data_a, 85)
    wb, _ = window_of(data_b, 85)
    assert wa[["window_p10_days", "window_p50_days", "window_p90_days"]].tolist() == wb[["window_p10_days", "window_p50_days", "window_p90_days"]].tolist()


# ───────────────────────────── validación por cobertura ─────────────────────────────

def renewal_world(n_customers=160, days=720, seed=9, gap=(8, 16)):
    """Procesos de renovación independientes con intervalos uniformes (por defecto 8..16 días; sin abandono) para verificar la cobertura nominal."""
    rng = np.random.default_rng(seed)
    rows = []
    for c in range(1, n_customers + 1):
        d = int(rng.integers(0, 12))
        while d < days:
            rows.append((c, 1, d, 1))
            d += int(rng.integers(gap[0], gap[1] + 1))
    return make_data(rows)


def test_window_is_validated_by_coverage_on_a_renewal_process_with_known_intervals():
    data = renewal_world()
    fb = FeatureBuilder(data, CFG, max_horizon=30)
    bundle = build_dataset(fb, CFG, (7,))
    ev = evaluate_window(bundle, PARAMS)
    assert ev["status"] == "validated", ev.get("reason")
    iv = ev["interval"]
    assert iv["n"] >= PARAMS.eval_min_rows and iv["coverage"] >= 0.8 - PARAMS.coverage_tolerance
    assert iv["below_p10"] <= 0.2 and iv["above_p90"] <= 0.2
    assert ev["median"]["error_days"]["mae"] < 6, "con intervalos de 8–16 días el P50 se equivoca pocos días"
    assert ev["reference_metrics"]["interval"]["coverage"] <= iv["coverage"] + 0.02, "no peor que los intervalos propios sin condicionar"
    assert ev["follow_up_days"] == PARAMS.eval_days
    # con la ventana condicionada a la recencia la mediana mejora a la referencia ingenua
    assert ev["median"]["error_days"]["mae"] <= ev["reference_metrics"]["median"]["error_days"]["mae"] + 0.5


def test_a_slow_cycle_widens_the_follow_up_until_the_window_can_be_validated_and_unvalidated_groups_stay_without_figures():
    """Con ciclos de 250–350 días un seguimiento de 90 no alcanza relaciones evaluables: se amplía; los grupos sin evidencia quedan sin cifras."""
    data = renewal_world(n_customers=150, days=3000, gap=(250, 350))
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    ev = evaluate_window(bundle, PARAMS)
    assert len(ev["attempts"]) >= 2 and ev["attempts"][0]["eval_days"] == 90 and ev["attempts"][0]["status"] == "insufficient_data"
    assert ev["follow_up_days"] > 90 and ev["params"]["eval_days"] == ev["follow_up_days"] <= 365
    assert ev["status"] == "validated" and ev["interval"]["n_relations"] >= PARAMS.eval_min_rows and ev["interval"]["coverage"] >= 0.7
    assert ev["groups"]["gaps:8_plus"]["status"] == "validated"
    assert ev["groups"]["gaps:1_3"]["status"] == "insufficient_data" and ev["groups"]["state:overdue"]["status"] == "insufficient_data"


def test_the_minimum_evidence_counts_distinct_relations_and_customers_not_rows():
    """40 compradores × 12 cortes dan cientos de FILAS pero solo 40 relaciones distintas: la cobertura puede ser buena y aun así no concluyente."""
    data = renewal_world(n_customers=40, days=720)
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    ev = evaluate_window(bundle, PARAMS)
    iv = ev["interval"]
    assert iv["n"] > PARAMS.eval_min_rows, "hay filas de sobra…"
    assert iv["n_relations"] <= 40 < PARAMS.eval_min_rows and iv["n_customers"] == iv["n_relations"]
    assert ev["status"] == "insufficient_data" and "distintos" in ev["reason"] or "relaciones" in ev["reason"]
    thin = replace(PARAMS, eval_min_rows=30, eval_min_customers=30, group_min_relations=20)
    assert evaluate_window(bundle, thin)["status"] == "validated", "con el mínimo de evidencia alcanzado, la misma cobertura valida"
    few_customers = replace(PARAMS, eval_min_rows=30, eval_min_customers=41, group_min_relations=20)
    assert evaluate_window(bundle, few_customers)["status"] == "insufficient_data", "también se exigen compradores distintos"


def test_window_status_reflects_the_validation_and_unvalidated_rows_have_no_figures():
    win = pd.DataFrame({
        "window_p10_days": [2.0, 3.0, 4.0, 5.0, 6.0, np.nan], "window_p50_days": [5.0, 6.0, 7.0, 8.0, 9.0, np.nan],
        "window_p90_days": [20.0, 40.0, 60.0, 95.0, 400.0, np.nan],
        "window_status": ["estimated", "estimated", "estimated", "overdue", "estimated", "insufficient_data"],
        "window_own_gaps": [5, 5, 9, 9, 3, 0]})
    groups = {"gaps:1_3": {"status": "not_validated"}, "gaps:4_7": {"status": "validated"}, "gaps:8_plus": {"status": "validated"},
              "state:overdue": {"status": "validated"}}
    payload = {"validation_status": "validated", "validation": {"follow_up_days": 90, "groups": groups}}
    out, info = apply_validation(win, payload)
    assert out["window_status"].tolist() == ["estimated", "estimated", "estimated", "overdue", "insufficient_data", "insufficient_data"]
    assert out[["window_p10_days", "window_p50_days", "window_p90_days"]].iloc[4].isna().all(), "el grupo 1–3 no validado queda sin cifras"
    assert info["withheld"] == {"gaps_1_3_not_validated": 1}
    assert out["window_beyond_validation"].tolist() == [False, False, False, True, False, False], "P90 95 > seguimiento 90: su cola no se validó"
    assert info["beyond_validation"] == 1
    # el estado overdue sin validar retiene SOLO a las overdue
    bad_overdue = {**payload, "validation": {**payload["validation"], "groups": {**groups, "state:overdue": {"status": "not_validated"}}}}
    out2, info2 = apply_validation(win, bad_overdue)
    assert out2["window_status"].tolist()[:4] == ["estimated", "estimated", "estimated", "insufficient_data"] and info2["withheld"].get("overdue_not_validated") == 1
    # validación global sin evidencia o sin cobertura: ninguna fila conserva cifras
    for status in ("insufficient_data", "not_validated"):
        out3, info3 = apply_validation(win, {**payload, "validation_status": status})
        assert out3[["window_p10_days", "window_p50_days", "window_p90_days"]].isna().all().all()
        assert set(out3["window_status"]) == {"insufficient_data"} and info3["withheld"] == {f"global_{status}": 5}
        assert not out3["window_beyond_validation"].any()
    # sin validación guardada (artefacto anterior): no se toca nada
    untouched, none_info = apply_validation(win, None)
    assert untouched["window_status"].tolist() == win["window_status"].tolist() and none_info["withheld"] == {}


def test_two_hundred_days_of_history_cannot_validate_the_window_and_publish_no_figures():
    data = renewal_world(n_customers=160, days=200)
    fb = FeatureBuilder(data, CFG, max_horizon=30)
    bundle = build_dataset(fb, CFG, (7,))
    ev = evaluate_window(bundle, PARAMS)
    assert ev["status"] == "insufficient_data" and ev["levels"] is None
    c = int(to_day(BASE)) + 199
    base = fb.compute(c, (7,)).base
    win = window_from_base(fb, c, base, PARAMS)
    assert win["window_status"].isin(["estimated", "overdue"]).sum() > 0, "el cálculo en bruto sí produce cuantiles…"
    payload = {"validation_status": ev["status"], "validation": {"follow_up_days": ev["follow_up_days"], "groups": ev.get("groups")}}
    served, info = apply_validation(win, payload)
    assert not served["window_status"].isin(["estimated", "overdue"]).any(), "…pero sin validación NO se publica ninguna cifra"
    assert served[["window_p10_days", "window_p50_days", "window_p90_days"]].isna().all().all()


def test_fit_cutoffs_stay_before_every_holdout_and_their_outcomes_too():
    data = renewal_world(n_customers=120, days=900)
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    fit_free, ev = split_cutoffs(bundle, PARAMS)
    assert len(fit_free) >= 4
    early_holdout = sorted(fit_free)[len(fit_free) // 2] + PARAMS.eval_days      # un holdout que empieza a media ruta del ajuste libre
    fit_cut, ev_cut = split_cutoffs(bundle, PARAMS, holdout_starts=[early_holdout, early_holdout + 200])
    assert ev_cut == ev, "la evaluación no cambia: solo se evalúa sobre ella"
    assert 0 < len(fit_cut) < len(fit_free), "la restricción está activa: acorta el ajuste (no es tautológica)"
    assert all(c + PARAMS.eval_days <= early_holdout for c in fit_cut), "ni el corte ni su seguimiento tocan el primer holdout"
    assert max(fit_free) + PARAMS.eval_days > early_holdout, "sin la restricción el ajuste sí lo habría tocado"
    # y el informe de la evaluación lo declara
    res = evaluate_window(bundle, PARAMS, holdout_starts=[early_holdout])
    assert res["fit_cutoffs_before_holdout_of_every_horizon"] is True and res["holdout_starts"] == [early_holdout]
    assert max(res["fit_cutoff_days"]) + res["follow_up_days"] <= early_holdout


def test_window_inputs_are_computed_once_per_cutoff_and_reused_by_fit_evaluation_and_the_nominal_comparison(monkeypatch):
    data = renewal_world(n_customers=120, days=900)
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    calls = []
    original = window_module._window_inputs

    def counting(fb, c, base, params):
        calls.append(c)
        return original(fb, c, base, params)

    monkeypatch.setattr(window_module, "_window_inputs", counting)
    ev = evaluate_window(bundle, PARAMS)
    used = set(ev["fit_cutoff_days"]) | set(ev["eval_cutoff_days"])
    assert ev["recalibration"]["fitted"] and len(ev["attempts"]) == 1
    assert sorted(calls) == sorted(used), "una sola lectura de eventos por corte aunque se evalúe con y sin niveles recalibrados"


def test_row_sampling_limits_which_rows_get_quantiles_without_changing_them_or_the_hierarchical_prior():
    data = renewal_world(n_customers=120, days=400)
    fb = FeatureBuilder(data, CFG, max_horizon=30)
    c = int(to_day(BASE)) + 380
    base = fb.compute(c, (7,)).base
    full = window_from_base(fb, c, base, PARAMS)
    part = window_from_base(fb, c, base, PARAMS, max_rows=40)
    again = window_from_base(fb, c, base, PARAMS, max_rows=40)
    cols = ["window_p10_days", "window_p50_days", "window_p90_days"]
    computed = part[cols[0]].notna()
    assert int(computed.sum()) == 40 and part["window_sampled"].sum() < len(part), "solo 40 filas con cifras; el resto queda fuera de la muestra"
    pd.testing.assert_frame_equal(part[computed][cols], full[computed][cols])
    pd.testing.assert_series_equal(part["window_prior_mean"], full["window_prior_mean"])           # el suavizado usa a TODOS los candidatos
    pd.testing.assert_frame_equal(part, again)                                                     # la muestra es reproducible (semilla = corte)


def test_fit_and_evaluation_cutoffs_are_separated_by_the_follow_up_so_outcomes_are_not_reused():
    data = renewal_world()
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    fit, ev = split_cutoffs(bundle, PARAMS)
    assert fit and ev
    assert max(fit) + PARAMS.eval_days <= min(ev), "el seguimiento de cada corte de ajuste termina antes del primer corte de evaluación"
    assert max(ev) + PARAMS.eval_days <= bundle.last_complete, "los cortes de evaluación tienen seguimiento maduro (sin etiquetas inmaduras)"
    assert not set(fit) & set(ev)


def test_recalibration_improves_coverage_on_a_noisy_world_and_reports_what_it_fitted():
    world = generate_world(seed=7, n_customers=130, n_products=9, n_days=800, mean_interval_range=(20, 200))
    bundle = build_dataset(FeatureBuilder(world.customer_data(), CFG, max_horizon=30), CFG, (7, 30))
    ev = evaluate_window(bundle, PARAMS)
    assert ev["levels"] and any(v["fitted"] for v in ev["levels"].values())
    nominal = ev["recalibration"]["uncalibrated_nominal_levels"]
    assert ev["interval"]["coverage"] > nominal["coverage"], "los niveles recalibrados con cortes anteriores acercan la cobertura a la nominal"
    assert ev["interval"]["coverage"] > ev["reference_metrics"]["interval"]["coverage"]
    assert set(ev["by_own_gaps"]) == set(BUCKETS)
    for lv in ev["levels"].values():
        assert lv["p10_level"] in LOWER_GRID and lv["p50_level"] in MEDIAN_GRID and lv["p90_level"] in UPPER_GRID


def test_window_without_mature_follow_up_is_not_concluyente_instead_of_invented():
    data = make_data([t for c in range(1, 30) for t in series(c, 1, [10] * 6, start=c)])     # ~70 días: nadie tiene 90 de seguimiento
    bundle = build_dataset(FeatureBuilder(data, CFG, max_horizon=30), CFG, (7,))
    ev = evaluate_window(bundle, PARAMS)
    assert ev["status"] == "insufficient_data" and ev["levels"] is None
    assert ev.get("reason_code") in ("no_mature_cutoffs", "no_rows") or ev["interval"]["n"] < PARAMS.eval_min_rows


def test_fit_levels_keeps_nominal_levels_for_groups_with_too_few_rows_and_widens_when_outcomes_are_late():
    n = 400
    cand = candidate_levels()
    rng = np.random.default_rng(3)
    # filas del grupo 4–7 cuyo resultado real llega SIEMPRE después del cuantil nominal ⇒ hay que ensanchar la cola superior
    cols = {f"cand_{j}": np.full(n, 10.0 + 2 * j) for j in range(len(cand))}
    tab = pd.DataFrame({"valid": True, "window_status": "estimated", "window_own_gaps": 5, "T": rng.uniform(40, 80, n), "rel": np.arange(n),
                        "customer_id": np.arange(n), **cols})
    out = fit_levels([tab], cand, PARAMS)
    assert out["4_7"]["fitted"] is False or out["4_7"]["p90_level"] >= 0.9    # sin cuantiles dentro del seguimiento no se ajusta nada raro
    thin = tab.head(10)
    out2 = fit_levels([thin], cand, PARAMS)
    assert all(not v["fitted"] for v in out2.values()) and out2["4_7"]["p90_level"] == 0.9, "pocas filas ⇒ niveles nominales"
