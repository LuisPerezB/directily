"""Customer Core S05 · entrenamiento y predicción integrados sobre un mundo sintético de verdad conocida (sin base de datos).

Comprueba que S05 AGREGA salidas sin cambiar lo servido (probabilidad, cantidad condicionada, intervalos y ranking idénticos con S05 apagado),
el contrato de las columnas nuevas de `customer_predictions`, la coherencia de E(nº pedidos), la evaluación en holdout (segmentos, lift,
utilidad, estabilidad, drift, cold start), los artefactos firmados y la configuración validada.
"""

from __future__ import annotations

import copy
import itertools

import numpy as np
import pandas as pd
import pytest

from customer_s05_fixtures import train_with_s05
from predictily_engine.customer import S05_VERSION
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer import predict as predict_module
from predictily_engine.customer.models import ratio_scale
from predictily_engine.customer.orders import ORDERS_FORM, reference_orders, serve_conditional_orders, ztp_mean
from predictily_engine.customer.predict import predict_all
from predictily_engine.customer.s05_persist import horizon_summary, manifest_block, s05_eval_rows, window_summary
from predictily_engine.customer.service import _artifact_compat, _save_artifact, load_artifact
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.customer.train import train_horizon
from predictily_engine.platform.config import ConfigValidationError, resolve_config

HORIZONS = (7, 30, 90)
_TENANTS = itertools.count(9700, 10)


def cfg(**over):
    c, _, _ = resolve_config("customer", {}, None, tenant_overrides=over or None)
    return c


@pytest.fixture(scope="module")
def world():
    return generate_world(seed=7, n_customers=130, n_products=9, n_days=800, mean_interval_range=(20, 200), churn_share=0.15)


def run_all(world, c):
    data = world.customer_data()
    fb = FeatureBuilder(data, c)
    bundle = build_dataset(fb, c, HORIZONS)
    results, finalization, window_eval = train_with_s05(bundle, c, HORIZONS)
    arts = {h: {"prob": r.artifacts["prob"], "qty": r.artifacts["qty"]} for h, r in results.items() if r.artifacts}
    as_of = int(to_day(world.start)) + world.n_days
    out = predict_all(FeatureBuilder(data, c, max_horizon=90), arts, as_of, c)
    return {"data": data, "bundle": bundle, "results": results, "window_eval": window_eval, "finalization": finalization, "arts": arts, "out": out,
            "cfg": c, "as_of": as_of}


@pytest.fixture(scope="module")
def on(world):
    return run_all(world, cfg())


@pytest.fixture(scope="module")
def off(world):
    return run_all(world, cfg(s05_enabled=False))


# ───────────────────────────── no cambia lo servido ─────────────────────────────

ORIGINAL = ["customer_id", "product_id", "horizon_days", "purchase_probability", "probability_raw", "probability_coherence_adjusted",
            "quantity_if_purchase", "quantity_lo", "quantity_hi", "expected_quantity", "expected_amount", "median_interval_days",
            "model_family", "model_role"]


def test_s05_does_not_change_the_served_probability_quantity_intervals_or_ranking(on, off):
    a = on["out"].predictions[ORIGINAL].reset_index(drop=True)
    b = off["out"].predictions[ORIGINAL].reset_index(drop=True)
    pd.testing.assert_frame_equal(a, b, check_exact=True)
    pd.testing.assert_frame_equal(on["out"].rankings.reset_index(drop=True), off["out"].rankings.reset_index(drop=True), check_exact=True)
    for h in HORIZONS:        # mismas decisiones de promoción que sin S05
        ra, rb = on["results"][h], off["results"][h]
        assert ra.prob["served"]["family"] == rb.prob["served"]["family"] and ra.prob["gate"]["passed"] == rb.prob["gate"]["passed"]
        assert ra.qty["served"]["objective"] == rb.qty["served"]["objective"]
        assert ra.prob["served"]["holdout"]["brier"] == rb.prob["served"]["holdout"]["brier"]
        assert not rb.s05, "con S05 apagado no se calcula nada de S05"
    assert off["out"].predictions["expected_orders"].isna().all() and off["out"].predictions["window_status"].isna().all()


def test_served_probability_never_worsens_the_reference_with_s05(on):
    for h in HORIZONS:
        r = on["results"][h]
        assert r.prob["served"]["holdout"]["brier"] <= r.prob["reference"]["holdout"]["brier"] + 1e-12


# ───────────────────────────── columnas del contrato ─────────────────────────────

def test_new_columns_follow_the_contract_and_are_coherent(on):
    p = on["out"].predictions
    cols = ["expected_orders", "quantity_per_order", "expected_units", "window_p10_days", "window_p50_days", "window_p90_days", "window_status", "segment"]
    assert set(cols) <= set(p.columns)
    assert p["expected_orders"].notna().all() and p["quantity_per_order"].notna().all() and p["segment"].notna().all()
    assert (p["expected_orders"] >= p["purchase_probability"] - 1e-9).all(), "E(N) ≥ P(N ≥ 1) siempre"
    assert (p["expected_units"] - p["expected_orders"] * p["quantity_per_order"]).abs().max() < 1e-9
    assert (p["quantity_per_order_lo"] <= p["quantity_per_order"] + 1e-9).all() and (p["quantity_per_order_hi"] >= p["quantity_per_order"] - 1e-9).all()
    assert set(p["window_status"].dropna()) <= {"estimated", "insufficient_data", "overdue", "censored"}
    assert p["segment"].str.fullmatch(r"frequency:(low|medium|high|all)").all()
    est = p[p["window_status"].isin(["estimated", "overdue"])]
    assert est["window_p10_days"].between(1, 365).all() if len(est) else True
    assert (est["window_p10_days"] <= est["window_p50_days"]).all() and (est["window_p50_days"] <= est["window_p90_days"]).all()
    none = p[p["window_status"].isin(["insufficient_data", "censored"])]
    assert none[["window_p10_days", "window_p50_days", "window_p90_days"]].isna().all().all(), "sin datos suficientes o sin validación no hay cifras"
    # la ventana no depende del horizonte: es la distribución del tiempo hasta la siguiente compra desde el corte
    first = p[p["horizon_days"] == 7].set_index(["customer_id", "product_id"])[["window_p10_days", "window_p90_days"]]
    last = p[p["horizon_days"] == 90].set_index(["customer_id", "product_id"])[["window_p10_days", "window_p90_days"]]
    pd.testing.assert_frame_equal(first.sort_index(), last.sort_index())


def test_window_figures_are_published_only_where_the_validation_backs_them(on):
    """`window_status` (la columna que consumen la API y la UI) refleja la validación: sin ella no hay cifras; con ella, solo en los grupos validados."""
    p = on["out"].predictions[lambda d: d["horizon_days"] == 7]
    we = on["window_eval"]
    has_numbers = p["window_status"].isin(["estimated", "overdue"])
    if we["status"] != "validated":
        assert not has_numbers.any() and p[["window_p10_days", "window_p50_days", "window_p90_days"]].isna().all().all()
        assert on["out"].summary["s05"]["window"]["withheld"] or on["out"].summary["s05"]["window"]["validation_status"] != "validated"
    else:
        ok_groups = {k for k, g in we["groups"].items() if g["status"] == "validated"}
        flags = p["quality_flags"]
        assert flags[has_numbers].map(lambda f: "window_not_validated" not in f).all(), "la bandera antigua ya no existe: las cifras no validadas no se publican"
        if "state:overdue" not in ok_groups:
            assert not (p["window_status"] == "overdue").any(), "el estado overdue sin validar se retiene"
        summary = on["out"].summary["s05"]["window"]
        assert summary["validation_status"] == "validated" and summary["follow_up_days"] == we["follow_up_days"]
        assert summary["not_assessable_share"] == we["rows"]["not_assessable_share"]


def test_rows_whose_p90_exceeds_the_validated_follow_up_are_flagged(on):
    p = on["out"].predictions[lambda d: d["horizon_days"] == 7]
    we = on["window_eval"]
    has_numbers = p["window_status"].isin(["estimated", "overdue"])
    flag = p["quality_flags"].map(lambda f: "window_beyond_validation" in f)
    if we["status"] != "validated":
        assert not flag.any()
        return
    expected = has_numbers & (p["window_p90_days"].isna() | (p["window_p90_days"] > we["follow_up_days"]))
    assert (flag == expected).all() and not flag[~has_numbers].any()
    assert on["out"].summary["s05"]["window"]["beyond_validation_rows"] > 0 or not expected.any()


def test_expected_orders_grow_with_the_horizon_and_the_unit_conversion_differs_from_p_times_q(on):
    p = on["out"].predictions.set_index(["customer_id", "product_id", "horizon_days"])
    e7, e30, e90 = (p.xs(h, level="horizon_days")["expected_orders"] for h in HORIZONS)
    assert (e30 >= e7 - 1e-9).all() and (e90 >= e30 - 1e-9).all(), "ventanas anidadas: E(N) no decrece con H"
    assert (e90 > 1.0).any(), "con horizonte largo caben varios pedidos: E(N) no es una probabilidad"
    diff = (p["expected_units"] - p["expected_quantity"]).abs()
    assert diff.max() > 1e-3, "expected_units y expected_quantity = P × E(cantidad | compra) son magnitudes distintas"


def test_expected_orders_are_a_coherent_hurdle_not_a_function_of_p_and_rarely_equal_to_it(on):
    """E(N) = P × E(N | N ≥ 1) con E(N | N ≥ 1) > 1: E(N) > P en TODAS las filas por construcción y casi nunca coincide con P."""
    p = on["out"].predictions
    assert (p["expected_orders"] > p["purchase_probability"]).all()
    assert float(((p["expected_orders"] - p["purchase_probability"]).abs() <= 1e-12).mean()) < 0.05, "fracción de filas con E(N) = P exacto"
    assert not p["quality_flags"].map(lambda f: "orders_raised_to_probability" in f).any(), "ya no hay cota fila a fila que suba E(N) a P"
    for h in HORIZONS:
        assert on["results"][h].s05["orders"]["served"]["equal_to_probability_share"] < 0.05
    free = p[p["horizon_days"] == 30]
    # filas con la MISMA probabilidad (redondeada a 2 decimales) tienen distinto nº esperado de pedidos
    spread = free.assign(pk=free["purchase_probability"].round(2)).groupby("pk")["expected_orders"].agg(["std", "mean", "size"])
    spread = spread[spread["size"] >= 8]
    assert len(spread) and (spread["std"] / spread["mean"]).max() > 0.02, "E(N) no es función de P: el conteo condicionado aporta información propia"
    rho = pd.Series(free["expected_orders"].to_numpy()).corr(pd.Series(free["purchase_probability"].to_numpy()), method="spearman")
    assert rho < 0.9999
    # el cociente E(N)/P ES el conteo condicionado (≥ 1), salvo el anidamiento entre horizontes que se mide aparte
    ratio = free.loc[~free["quality_flags"].map(lambda f: "orders_nested_adjusted" in f), "expected_orders"] / free["purchase_probability"]
    assert (ratio > 1.0).all()


def test_served_orders_bias_is_within_the_bound_or_the_complex_model_is_not_promoted(on):
    bound = on["cfg"]["orders_max_abs_bias"]
    for h in HORIZONS:
        o = on["results"][h].s05["orders"]
        bias = o["served"]["holdout"]["bias"]
        assert o["served"]["bias_within_bound"] == (bias is not None and abs(bias) <= bound)
        if o["served"]["role"] == "primary":      # promovido: el sesgo del E(N) FINAL servido respeta el tope
            assert abs(bias) <= bound and o["gate"]["passed"] and o["gate"]["final"]["passed"]
            assert o["gate"]["final"]["checks"]["bias_ok"] and o["gate"]["final"]["checks"]["deviance_improvement_ok"]
        else:
            assert not o["gate"]["passed"], "referencia servida ⇒ el retador no se promovió"
        assert o["gate"]["final"] is None or "checks" in o["gate"]["final"]
        dec = o["served"]["bias_decomposition"]      # el hurdle hereda el sesgo de P: (1 + sesgo E) = (1 + sesgo P)(1 + sesgo condicionado)
        assert (1 + bias) == pytest.approx((1 + dec["probability_bias"]) * (1 + dec["conditional_factor_bias"]))


def test_nesting_correction_is_measured_per_horizon_in_training_and_prediction(on):
    fin = on["finalization"]
    assert fin["status"] == "ok" and fin["horizons"] == list(HORIZONS) and "máximo acumulado" in fin["rule"]
    for h in HORIZONS:
        n = on["results"][h].s05["orders"]["served"]["nesting"]
        assert n["rows"] > 0 and 0.0 <= n["adjusted_share"] <= 1.0 and n["mass_share"] >= -1e-12
        assert set(n["p_nesting"]) == {"adjusted_rows", "adjusted_share", "mass_share"} == set(n["orders_nesting"])
        assert n["bias_before"] is not None and n["bias_after"] is not None and n["chain_horizons"] == [a for a in HORIZONS if a <= h]
        assert n["adjusted_share"] >= n["orders_nesting"]["adjusted_share"] - 1e-12
        # el corrector solo SUBE E(N): el sesgo no baja por la corrección
        assert n["bias_after"] >= n["bias_before"] - 1e-12
    pred = on["out"].summary["s05"]["orders_nested"]
    for h in HORIZONS:
        assert f"corrected_rate_h{h}" in pred and f"corrected_mass_share_h{h}" in pred
    assert pred["corrected_rate_h7"] == 0.0, "el primer horizonte no tiene con qué anidarse"
    assert all(0.0 <= on["out"].served[h]["orders_nested_corrected_rate"] <= 1.0 for h in HORIZONS)


def test_prediction_summary_reports_window_states_nesting_and_drift(on):
    s = on["out"].summary["s05"]
    assert set(s["window_status_counts"]) <= {"estimated", "insufficient_data", "overdue", "censored"} and s["rows"] > 0
    assert s["orders_nested"]["rule"] == "cumulative_max" and set(s["drift"]) == {"7", "30", "90"} and "window" in s
    assert all(d["status"] in ("ok", "warn", "alert") for d in s["drift"].values())
    served = on["out"].served
    for h in HORIZONS:
        assert served[h]["orders_family"] in ("reference", "lgbm_poisson") and served[h]["per_order_interval_ratio"][0] < 1 < served[h]["per_order_interval_ratio"][1]
        assert served[h]["window_validation"] in ("validated", "not_validated", "insufficient_data")


# ───────────────────────────── evaluación en holdout ─────────────────────────────

def test_holdout_evaluation_covers_segments_lift_utility_stability_drift_and_cold_start(on):
    for h in HORIZONS:
        r = on["results"][h]
        s = r.s05
        assert s["version"] == S05_VERSION and "orders" in s
        dims = {x["dimension"] for x in s["segments"]["rows"]}
        assert dims == {"frequency", "value", "tenure", "history"}, "frecuencia, valor, antigüedad y cold start"
        assert {x["status"] for x in s["segments"]["rows"]} <= {"calibrated", "miscalibrated", "insufficient_data"}
        for x in s["segments"]["rows"]:
            if x["status"] != "insufficient_data":
                assert x["served"]["brier"] is not None and x["reference"]["brier"] is not None and x["n"] >= on["cfg"]["segment_min_rows"]
            else:
                assert x["served"] is None and x["mean_ratio"] is None
        k = on["cfg"]["ranking_primary_k"]
        rk = s["ranking"]
        assert {"served", "reference", "popularity", "recency"} == set(rk["sources"])
        assert rk["sources"]["served"]["lift"][f"lift_at_{k}"] is not None and rk["sources"]["served"]["pr_auc"] is not None
        assert "served_vs_popularity" in rk["comparison"] and rk["sources"]["served"]["utility"]["margin"] is None and "margen" in rk["margin_limitation"]
        if r.manifest["tier"] == "full":
            assert rk["sources"]["served"]["utility"]["n_units_ranked"] >= 0
        assert s["stability"]["n_cutoffs"] >= 1 and s["stability"]["share_cutoffs_served_not_worse_than_reference"] is not None
        assert s["drift"]["calibration_to_holdout"]["status"] in ("ok", "warn", "alert", "insufficient_data")
        assert s["cold_start"]["definition"] and s["cold_start"].get("n") is not None
        assert s["expected_units"]["independence_assumption"] and s["expected_units"]["s05_orders_times_per_order"]["n"] > 0
        assert s["segment_recalibration"]["served"] is False, "la recalibración por segmento es un candidato, no se sirve"


def test_components_are_fitted_without_the_holdout(on):
    """Escala del conteo y cortes de segmento salen del bloque de CALIBRACIÓN (recalculados aquí de forma independiente)."""
    h = 30
    r, bundle = on["results"][h], on["bundle"]
    if r.manifest.get("tier") not in ("full", "lean"):
        pytest.skip("el horizonte no entrenó modelos complejos")
    fr = bundle.per_horizon[h].frame
    plan_cal = r.manifest["blocks"]["calibration"]
    cal_cut = set(range(int(to_day(plan_cal["cutoff_from"])), int(to_day(plan_cal["cutoff_to"])) + 1))
    cal_rows = np.nonzero(fr["cutoff"].isin(cal_cut).to_numpy())[0]
    base = bundle.base.loc[fr["base_row"].to_numpy()[cal_rows]]
    art = on["arts"][h]["qty"]["s05"]["orders"]
    assert art["form"] == ORDERS_FORM
    n_pd = fr["purchase_days"].to_numpy(dtype=float)[cal_rows]
    pos = n_pd > 0
    if art["kind"] == "reference":       # escala del exceso E(N | N ≥ 1) − 1 con los POSITIVOS de calibración (no el holdout)
        lam = reference_orders(base["rel_n_365"].to_numpy(dtype=float), base["rel_tenure_days"].to_numpy(dtype=float), h)
        assert art["scale"] == pytest.approx(ratio_scale(n_pd[pos] - 1.0, ztp_mean(lam[pos]) - 1.0))
    seg = on["arts"][h]["prob"]["s05"]["segmenter"]
    from predictily_engine.customer.segments import Segmenter
    refit = Segmenter.fit(base, on["cfg"])
    assert refit.frequency_edges == seg.frequency_edges and refit.value_edges == seg.value_edges
    assert "holdout" not in str(r.s05["segments"]["segmenter"]["fitted_on"]) or "nunca" in r.s05["segments"]["segmenter"]["fitted_on"]
    assert "ajustan" in manifest_block(on["results"], on["window_eval"])["holdout_policy"] or "no ajusta" in manifest_block(on["results"], on["window_eval"])["holdout_policy"]


def test_window_validation_is_reported_and_attached_to_the_artifacts(on):
    we = on["window_eval"]
    assert we["status"] in ("validated", "not_validated", "insufficient_data")
    assert max(we["fit_cutoff_days"] or [-1]) + we["follow_up_days"] <= min(we["eval_cutoff_days"])
    # NO tautológica: los inicios de holdout se leen del manifiesto de CADA horizonte y los cortes de ajuste (con su seguimiento) quedan antes de todos
    starts = [int(to_day(on["results"][h].manifest["blocks"]["holdout"]["cutoff_from"])) for h in HORIZONS]
    assert we["holdout_starts"] == starts and we["fit_cutoffs_before_holdout_of_every_horizon"] is True
    assert all(c + we["follow_up_days"] <= min(starts) for c in we["fit_cutoff_days"])
    for h in HORIZONS:
        w = on["arts"][h]["qty"]["s05"]["window"]
        assert w["params"]["eval_days"] == we["follow_up_days"] and w["validation_status"] == we["status"] and w["levels"] == we["levels"]
    summ = window_summary(we)
    assert summ["status"] == we["status"] and summ["nominal"] == 0.8 and summ["reference_coverage"] is not None


def test_persisted_rows_use_segment_level_keys_and_do_not_touch_existing_keys(on):
    rows = s05_eval_rows(on["results"], on["window_eval"])
    seg_rows = [r for r in rows if r["level"] == "segment"]
    assert seg_rows and all(":" in r["key"] and r["key"].split(":")[0] in ("frequency", "value", "tenure", "history") for r in seg_rows)
    keys = {r["key"] for r in rows if r["level"] == "overall"}
    assert {"h7:expected_orders", "h7:quantity_per_order", "h7:expected_units", "h7:ranking_s05", "h7:stability", "h7:segmenter", "window"} <= keys
    assert not keys & {"h7:probability", "h7:expected_quantity", "h7:ranking", "frequency", "inactivity", "coherence"}
    assert all(len(r["key"]) <= 80 for r in rows)
    window_row = next(r for r in rows if r["key"] == "window")
    assert window_row["h"] is None and window_row["etype"] == "out_of_sample"
    summ = horizon_summary(on["results"][7])
    assert summ["status"] == "ok" and summ["ranking"]["margin"] is None and summ["segments"]["calibrated"] >= 0


# ───────────────────────────── artefactos firmados y compatibilidad ─────────────────────────────

def test_artifacts_with_s05_roundtrip_through_the_signed_store_and_predict_identically(on):
    tenant = next(_TENANTS)
    loaded: dict[int, dict] = {}
    for h in HORIZONS:
        got = {}
        for key in ("prob", "qty"):
            stored, sha = _save_artifact(tenant, 1, f"h{h}_{key}", on["arts"][h][key])
            row = {"artifact_path": stored, "artifact_sha256": sha, "artifact_compat": _artifact_compat(), "version": f"t{tenant}-{key}", "tenant_id": tenant}
            art, why = load_artifact(row, tenant)
            assert why is None and art is not None and "s05" in art
            got[key] = art
        loaded[h] = got
    out = predict_all(FeatureBuilder(on["data"], on["cfg"], max_horizon=90), loaded, on["as_of"], on["cfg"])
    cols = ["purchase_probability", "expected_orders", "quantity_per_order", "expected_units", "window_p10_days", "window_p90_days", "window_status", "segment"]
    pd.testing.assert_frame_equal(out.predictions[cols].reset_index(drop=True), on["out"].predictions[cols].reset_index(drop=True), check_exact=True)


def test_a_model_trained_before_s05_still_serves_with_null_s05_columns_and_says_so(on):
    old = {h: {"prob": {k: v for k, v in on["arts"][h]["prob"].items() if k != "s05"}, "qty": {k: v for k, v in on["arts"][h]["qty"].items() if k != "s05"}}
           for h in HORIZONS}
    out = predict_all(FeatureBuilder(on["data"], on["cfg"], max_horizon=90), old, on["as_of"], on["cfg"])
    p = out.predictions
    assert p["expected_orders"].isna().all() and p["window_status"].isna().all() and p["segment"].isna().all()
    assert p["quality_flags"].map(lambda f: "s05_unavailable" in f).all()
    base = on["out"].predictions
    pd.testing.assert_series_equal(p["purchase_probability"].reset_index(drop=True), base["purchase_probability"].reset_index(drop=True))


# ───────────────────────────── configuración ─────────────────────────────

def test_s05_config_is_validated_and_documented_as_pilot_thresholds():
    c = cfg()
    assert c["s05_enabled"] is True and c["window_eval_days"] == 90 and c["drift_psi_alert"] > c["drift_psi_warn"]
    for bad in ({"window_gap_k": 1}, {"drift_bins": 1}, {"window_overdue_quantile": 0.2}, {"cold_start_min_purchases": 0}, {"orders_max_abs_bias": 2.0}):
        with pytest.raises(ConfigValidationError):
            cfg(**bad)
    with pytest.raises(ConfigValidationError):
        cfg(window_nonexistent=3)
    base_cfg = copy.deepcopy(cfg())
    assert cfg(window_gap_k=20)["window_gap_k"] == 20 and base_cfg["window_gap_k"] == 12


def test_s05_works_for_the_catalog_horizons_14_and_15_and_keeps_orders_nested(world):
    """V25-HOR-009: el catálogo de Customer incluye 14 y 15; S05 genera pedidos, cantidad por pedido y ventana para ambos."""
    c = cfg()
    data = world.customer_data()
    bundle = build_dataset(FeatureBuilder(data, c), c, (7, 14, 15, 30))
    results, finalization, _ = train_with_s05(bundle, c, (7, 14, 15, 30))
    assert finalization["status"] == "ok"
    arts = {h: {"prob": r.artifacts["prob"], "qty": r.artifacts["qty"]} for h, r in results.items() if r.artifacts}
    out = predict_all(FeatureBuilder(data, c, max_horizon=30), arts, int(to_day(world.start)) + world.n_days, c)
    p = out.predictions.set_index(["customer_id", "product_id", "horizon_days"])
    assert set(out.predictions["horizon_days"]) == {7, 14, 15, 30}
    for h in (14, 15):
        assert results[h].s05["orders"]["definition"] and p.xs(h, level="horizon_days")["expected_orders"].notna().all()
    e = {h: p.xs(h, level="horizon_days")["expected_orders"] for h in (7, 14, 15, 30)}
    assert (e[14] >= e[7] - 1e-9).all() and (e[15] >= e[14] - 1e-9).all() and (e[30] >= e[15] - 1e-9).all()


def test_a_failure_in_s05_leaves_its_columns_null_and_flagged_without_touching_what_is_served(on, monkeypatch):
    """Aislamiento en la predicción diaria: si S05 falla, probabilidad y cantidad condicionada siguen idénticas; las columnas S05 son nulas con `s05_failed`."""
    def boom(*_a, **_k):
        raise RuntimeError("fallo simulado de S05")

    base = on["out"].predictions
    for target in ("apply_s05", "window_table"):
        monkeypatch.setattr(predict_module, target, boom)
        out = predict_all(FeatureBuilder(on["data"], on["cfg"], max_horizon=90), on["arts"], on["as_of"], on["cfg"])
        p = out.predictions
        assert p[["expected_orders", "quantity_per_order", "expected_units", "window_p10_days"]].isna().all().all()
        assert p["window_status"].isna().all() and p["segment"].isna().all()
        assert p["quality_flags"].map(lambda f: "s05_failed" in f).all()
        assert "fallo simulado" in out.summary["s05"]["error"]
        pd.testing.assert_frame_equal(p[ORIGINAL].reset_index(drop=True), base[ORIGINAL].reset_index(drop=True), check_exact=True)
        monkeypatch.undo()


def test_an_orders_artifact_without_the_hurdle_form_is_not_used(on):
    """Un artefacto con otra forma de `orders` (p. ej. la cota fila a fila anterior) no se sirve: columnas de pedidos nulas, el resto sigue."""
    arts = {h: {"prob": on["arts"][h]["prob"], "qty": {**on["arts"][h]["qty"], "s05": {**on["arts"][h]["qty"]["s05"], "orders": {"family": "lgbm_poisson", "kind": "complex"}}}}
            for h in HORIZONS}
    out = predict_all(FeatureBuilder(on["data"], on["cfg"], max_horizon=90), arts, on["as_of"], on["cfg"])
    p = out.predictions
    assert p["expected_orders"].isna().all() and p["expected_units"].isna().all()
    assert p["quantity_per_order"].notna().all() and p["segment"].notna().all(), "lo demás de S05 sigue disponible"
    assert out.served[7]["orders_family"] is None


def test_list_settings_are_validated_for_order_length_and_range():
    for bad in ({"segment_tenure_edges": [365, 90]}, {"segment_tenure_edges": [30, 90, 365]}, {"segment_tenure_edges": [0, 90]},
                {"lift_global_fractions": [0.1, 0.05]}, {"lift_global_fractions": [0.1, 1.5]}, {"lift_global_fractions": []}):
        with pytest.raises(ConfigValidationError):
            cfg(**bad)
    assert cfg(segment_tenure_edges=[30, 180])["segment_tenure_edges"] == [30, 180]
    assert cfg(lift_global_fractions=[0.05, 1.0])["lift_global_fractions"] == [0.05, 1.0]
