"""Manifiesto del protocolo de evaluación (E2-06): se construye de las filas reales y es verificable."""

import copy

import pandas as pd

from predictily_engine.demand.manifest import MANIFEST_VERSION, build_manifest, verify


def test_manifest_dates_come_from_the_backtest_rows_and_respect_the_protocol(pipeline_result):
    m, plan, bt = pipeline_result.manifest, pipeline_result.plan, pipeline_result.backtest
    assert m["manifest_version"] == MANIFEST_VERSION and m["core"] == "demand"
    assert verify(m) == []
    assert m["as_of"] == pipeline_result.cutoff.isoformat()
    assert m["config_version"] == pipeline_result.config_version
    # Ventanas de selección y período final: orígenes, rangos objetivo y días de entrenamiento.
    fits = {f["window"]: f for f in m["training"]["backtest_fits"]}
    assert [f["role"] for f in fits.values()] == ["selection"] * plan.windows + ["holdout"]
    for k, cutoff in enumerate(plan.cutoffs):
        assert fits[k]["origin"] == fits[k]["train_through"] == cutoff.date().isoformat()
        assert fits[k]["target_from"] == (cutoff + pd.Timedelta(days=1)).date().isoformat()
        assert fits[k]["target_to"] == (cutoff + pd.Timedelta(days=plan.horizon)).date().isoformat()
    h = m["holdout"]
    assert h["present"] and h["independent"] and h["origin"] == plan.holdout_cutoff.date().isoformat()
    assert h["target_to"] == pipeline_result.cutoff.isoformat()
    # El período final empieza después de que termina TODO lo que se usó para elegir y calibrar.
    assert m["selection"]["target_to"] <= h["origin"] < h["target_from"]
    assert m["calibration"]["residual_target_to"] <= m["calibration"]["observable_at"] == h["origin"]
    assert m["calibration"]["residuals_observable_when_calibrating"] is True
    assert not any(h[k] for k in h if k.startswith("used_for_"))
    # Maduración de etiquetas: lo evaluado ya había terminado cuando se usó.
    assert m["label_maturity"]["selection_evidence_end"] == h["origin"]
    assert m["label_maturity"]["holdout_evidence_end"] == m["as_of"]
    # Orígenes superpuestos: se declaran (no son observaciones independientes) y no tocan el período final.
    assert m["overlap"]["overlapping_origins"] is True
    assert m["overlap"]["shared_target_dates_with_holdout"] == 0
    assert m["decisions"]["evaluation_windows_that_also_took_decisions"] == []
    assert set(m["decisions"]["model_selection_for_evaluation"]["windows"]).isdisjoint({plan.holdout_window})
    assert plan.holdout_window in m["decisions"]["production_selection_and_calibration"]["windows"]
    assert set(m["horizon_support"]) == {"7", "14", "30", "60", "90"}
    assert m["p50"]["published"] in (True, False)
    assert m["served"]["fallback_used"] is False


def test_manifest_overlap_counts_match_the_windows_in_the_backtest(pipeline_result):
    bt = pipeline_result.backtest
    sel = bt[~bt["is_holdout"]]
    per_window = [set(pd.to_datetime(g["ds"])) for _, g in sel.groupby("window")]
    shared = {d for i, a in enumerate(per_window) for b in per_window[i + 1:] for d in a & b}
    assert pipeline_result.manifest["overlap"]["shared_target_dates_between_selection_windows"] == len(shared) > 0


def test_verify_detects_a_holdout_that_took_part_in_a_decision(pipeline_result):
    bad = copy.deepcopy(pipeline_result.manifest)
    bad["holdout"]["used_for_model_selection"] = True
    assert "el período final participó en una decisión" in verify(bad)
    bad = copy.deepcopy(pipeline_result.manifest)
    bad["checks"]["holdout_disjoint_from_selection"] = False
    assert any("holdout_disjoint_from_selection" in p for p in verify(bad))


def test_manifest_without_independent_holdout_says_so():
    from predictily_engine.pipeline.core import run_pipeline
    from predictily_engine.pipeline.data import tenant_data_from_synthetic
    from predictily_engine.synthetic import generate_company

    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=6, start="2026-08-10"))
    res = run_pipeline(data, horizon=30, seed=42, n_jobs=1)
    m = res.manifest
    assert m["holdout"]["present"] is False and m["holdout"]["independent"] is False
    assert "optimistas" in m["holdout"]["reason"]
    assert m["protocol"]["independent_holdout"] is False
    assert m["decisions"]["evaluation_windows_that_also_took_decisions"] != []
    assert m["calibration"]["observable_at"] == res.cutoff.isoformat()
    assert {v["status"] for v in m["horizon_support"].values()} <= {"degraded", "insufficient_data"}


def test_manifest_without_backtest_has_no_fabricated_dates():
    m = build_manifest(cutoff=pd.Timestamp("2026-10-26").date(), data_from=pd.Timestamp("2026-10-01").date(), plan=None,
                       bt=pd.DataFrame(), min_improvement=0.03, config_version="x", coverage=None,
                       horizon_support={}, has_eval_selection=False, p50=None, seed=42)
    assert m["protocol"]["backtest"] is False
    assert m["selection"] is None and m["calibration"] is None and m["label_maturity"] is None
    assert m["holdout"] == {"present": False, "independent": False}
    assert m["history"]["days"] == 26
    assert verify(m) == []
