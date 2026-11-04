"""W3-A · con la configuración por defecto las métricas por segmento SOLO se agregan: lo servido (pronósticos, selección, intervalos,
recomendaciones y las métricas ya existentes) es idéntico al de la ejecución sin ellas; las filas nuevas respetan los límites de la tabla.
Datos sintéticos de la fixture `small_data` (sin base de datos)."""

from __future__ import annotations

import json
import math

import pandas as pd
import pytest

from predictily_engine.db.writer import jdump
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.forecast_run import EVALUATION_COLUMN_LIMITS, _fit_evaluation_columns
from predictily_engine.platform.config import effective_config_record


@pytest.fixture(scope="module")
def without_segments(small_data):
    record = effective_config_record("demand", None, None, tenant_overrides={"segment_metrics_enabled": False})
    return run_pipeline(small_data, horizon=90, seed=42, n_jobs=4, config_record=record)


def _plain(evaluations):
    return [e for e in evaluations if e["level"] != "segment"]


def test_the_default_run_serves_exactly_what_the_run_without_segment_metrics_serves(pipeline_result, without_segments):
    a, b = pipeline_result, without_segments
    pd.testing.assert_frame_equal(a.forecast_values.reset_index(drop=True), b.forecast_values.reset_index(drop=True))
    assert a.selection.winners == b.selection.winners and a.selection.reasons == b.selection.reasons
    assert a.planned_selection.winners == b.planned_selection.winners and a.failed_families == b.failed_families
    pd.testing.assert_frame_equal(a.recommendations.reset_index(drop=True), b.recommendations.reset_index(drop=True))
    for key in ("metrics", "models", "series_by_class", "selection_reasons", "horizon_totals", "horizon_support", "quality_status", "p50",
                "warnings", "backtest", "recommendations", "limitation_codes"):
        assert a.summary[key] == b.summary[key], key
    assert a.warnings == b.warnings and a.quality_status == b.quality_status and a.horizon_support == b.horizon_support
    protocol = ("history", "seed", "min_improvement", "protocol", "training", "selection", "calibration", "holdout", "label_maturity",
                "overlap", "checks", "horizon_support", "p50", "served", "day_status", "knowledge", "data_quality_gate")
    assert {k: a.manifest.get(k) for k in protocol} == {k: b.manifest.get(k) for k in protocol}, "el manifiesto del protocolo no cambia"
    assert a.manifest["config_version"] != b.manifest["config_version"], "solo la huella de la configuración refleja el interruptor"
    assert a.training_data_hash == b.training_data_hash


def test_the_pre_existing_evaluation_rows_are_unchanged_and_come_first(pipeline_result, without_segments):
    plain_a, plain_b = _plain(pipeline_result.evaluations), _plain(without_segments.evaluations)
    assert plain_a == plain_b and len(plain_a) > 100
    # los segmentos se anexan al final de CADA alcance (primero el principal, luego el secundario): ningún alcance pierde ni reordena sus filas
    for etype in ("backtest", "selection_windows"):
        scope = [e["level"] == "segment" for e in pipeline_result.evaluations if e["evaluation_type"] == etype]
        assert scope == sorted(scope), f"{etype}: las filas de segmento van después de las demás"
    assert {e["evaluation_type"] for e in pipeline_result.evaluations if e["level"] == "segment"} == {"backtest", "selection_windows"}


def test_every_reported_wape_declares_its_denominator_and_never_has_a_zero_one(pipeline_result):
    """V25-SEL-013: la fila declara Σ reales y el estado de su WAPE. Las filas previas conservan su columna `wape` tal cual
    (solo avisan con `wape_unstable`); las de segmento dejan la columna nula cuando el denominador no alcanza."""
    with_wape = 0
    for e in pipeline_result.evaluations:
        m = e["metrics"]
        den = m["denominator"]["sum_actual"]
        assert m["wape_status"] in ("ok", "unstable", "undefined") and m["wape_unstable"] == (m["wape_status"] != "ok") and den is not None
        assert (den > 0) == (m["wape_status"] != "undefined"), (e["level"], e["level_key"])
        if e["wape"] is not None:
            with_wape += 1
            assert den > 0, "un WAPE persistido siempre tiene denominador positivo"
            if e["level"] == "segment":
                assert m["wape_status"] == "ok" and den >= m["denominator"]["min_required"]
        elif e["level"] != "segment":
            assert den == 0 or e["n_obs"] == 0
    assert with_wape > 500
    overall = [e for e in pipeline_result.evaluations if e["level"] == "overall" and e["model_family"] == "selection"
               and e["evaluation_type"] == "backtest"][0]
    assert overall["metrics"]["denominator"]["min_required"] == 30.0, "el umbral de la configuración efectiva llega a todas las filas"


def test_the_switch_turns_the_segment_rows_and_the_summary_off(without_segments, pipeline_result):
    assert not any(e["level"] == "segment" for e in without_segments.evaluations)
    block = without_segments.summary["segment_metrics"]
    assert block["enabled"] is False and block["attention"] == [] and block["counts"]["cells"] == 0 and "desactivadas" in block["note"]
    assert without_segments.manifest["segment_metrics"] == {"version": "demand-segment-metrics-v1", "enabled": False}
    on = pipeline_result.summary["segment_metrics"]
    assert on["enabled"] is True and on["available"] is True and on["scope"] == "holdout" and on["independent_holdout"] is True
    assert on["horizons_evaluated"] == [7, 14, 15, 30, 60, 90] and on["horizons_not_evaluated"] == []
    assert on["counts"]["segments"] > 5 and on["counts"]["cells"] == on["counts"]["segments"] * 6
    assert set(on["dimensions"]) == {"demand_class", "category", "branch", "volume_tier", "series_age"}
    assert pipeline_result.effective_config["config"]["segment_metrics_enabled"] is True, "queda registrado en la configuración efectiva de la ejecución"


def test_the_summary_block_is_compact_and_mirrors_the_persisted_rows_of_the_served_model(pipeline_result):
    block = pipeline_result.summary["segment_metrics"]
    rows = {(e["level_key"], e["horizon_days"], e["model_family"]): e for e in pipeline_result.evaluations
            if e["level"] == "segment" and e["evaluation_type"] == "backtest"}
    served = [e for (k, h, f), e in rows.items() if f == "selection"]
    counts = block["counts"]
    assert counts["cells"] == len(served) and counts["segments"] == len({e["level_key"] for e in served})
    assert counts["by_status"] == {st: sum(1 for e in served if e["metrics"]["status"] == st) for st in {e["metrics"]["status"] for e in served}}
    for dim, c in counts["by_dimension"].items():
        mine = [e for e in served if e["extra"]["segment_dimension"] == dim]
        assert c["cells"] == len(mine) and c["segments"] == len({e["level_key"] for e in mine})
        assert c.get("insufficient_data", 0) == sum(1 for e in mine if e["metrics"]["status"] == "insufficient_data")
    assert block["attention_total"] == sum(counts["by_reason"].values()) and len(block["attention"]) == min(20, block["attention_total"])
    assert block["attention_truncated"] is (block["attention_total"] > 20)
    for cell in block["attention"]:       # cada celda de atención coincide con la fila persistida y remite a ella por (segmento, horizonte)
        row = rows[(cell["segment"], cell["horizon"], "selection")]
        assert cell["wape"] == row["wape"] and cell["denominator"] == row["metrics"]["denominator"]["sum_actual"]
        assert cell["evaluable_days"] == row["n_obs"] and cell["wape_status"] == row["metrics"]["wape_status"]
        base = rows.get((cell["segment"], cell["horizon"], "seasonal_naive"))
        assert cell["baseline_wape"] == (base["wape"] if base else None)
    assert block["detail"]["filters"] == {"core": "demand", "level": "segment", "evaluation_type": "backtest", "model_family": "selection"}
    assert len(json.dumps(block)) < 8000, "el resumen no crece con el número de segmentos"
    assert "segments" not in block, "el detalle por segmento y horizonte ya no viaja en forecast_runs.summary"


def test_every_segment_row_fits_the_table_columns_and_serializes_without_non_finite_numbers(pipeline_result):
    rows = [e for e in pipeline_result.evaluations if e["level"] == "segment"]
    assert len(rows) > 500
    for e in rows:
        assert len(e["level"]) <= 20 and len(e["level_key"]) <= 80 and len(e["model_family"]) <= 40 and ":" in e["level_key"]
        assert e["horizon_days"] in (7, 14, 15, 30, 60, 90) and e["n_obs"] >= 0
        scalars, overflow = _fit_evaluation_columns(e)
        assert overflow == [], f"{e['level_key']} H{e['horizon_days']}: {overflow}"
        json.loads(jdump(e["metrics"]))
        for k in EVALUATION_COLUMN_LIMITS:
            assert e[k] is None or math.isfinite(e[k])
    assert len({(e["evaluation_type"], e["model_family"], e["level_key"], e["horizon_days"]) for e in rows}) == len(rows), "una fila por clave"


def test_a_value_that_does_not_fit_its_column_is_stored_null_and_annotated_instead_of_aborting_the_run():
    huge = {"model_family": "selection", "evaluation_type": "backtest", "level": "segment", "level_key": "branch:b1", "horizon_days": 7,
            "wape": 5_000_000.0, "bias": float("nan"), "mae": 12.5, "rmse": float("inf"), "coverage_80": 0.9, "n_obs": 10,
            "cutoffs": ["2026-01-01"], "extra": {}, "metrics": {}}
    scalars, overflow = _fit_evaluation_columns(huge)
    assert overflow == ["wape"] and scalars["wape"] is None and scalars["bias"] is None and scalars["rmse"] is None
    assert scalars["mae"] == 12.5 and scalars["coverage_80"] == 0.9 and "cutoffs" not in scalars and "metrics" not in scalars
    ok, none = _fit_evaluation_columns({**huge, "wape": 999_999.0})
    assert none == [] and ok["wape"] == 999_999.0


# ───────────────────────────────────────── el período final reservado sigue intacto ─────────────────────────────────────────

def test_the_final_period_decides_nothing_in_the_segment_metrics_of_the_selection_windows(small_data, pipeline_result):
    """Alterar las ventas del período final cambia sus métricas por segmento, pero NO las de las ventanas de selección ni el mejor
    estadístico con que se etiqueta la comparación (se elige con la evidencia de selección, nunca con el período final)."""
    from test_pipeline import _scaled_after   # noqa: PLC0415 (helper de la prueba F6 de este mismo directorio)

    from predictily_engine.demand.manifest import verify

    origin = pipeline_result.plan.holdout_cutoff
    perturbed = run_pipeline(_scaled_after(small_data, origin, seed=11), horizon=90, seed=42, n_jobs=4)
    key = lambda e: (e["evaluation_type"], e["model_family"], e["level_key"], e["horizon_days"])    # noqa: E731
    a = {key(e): e for e in pipeline_result.evaluations if e["level"] == "segment"}
    b = {key(e): e for e in perturbed.evaluations if e["level"] == "segment"}
    assert set(a) == set(b)
    for k, e in a.items():
        if k[0] == "selection_windows":
            f = b[k]
            for col in ("wape", "mae", "rmse", "bias", "n_obs"):
                assert f[col] == pytest.approx(e[col], rel=1e-9, abs=1e-12) if e[col] is not None else f[col] is None, (k, col)
            assert f["metrics"]["denominator"] == e["metrics"]["denominator"] and f["metrics"]["status"] == e["metrics"]["status"]
    changed = [k for k, e in a.items() if k[0] == "backtest" and k[1] == "selection" and e["wape"] is not None
               and e["wape"] != pytest.approx(b[k]["wape"], rel=1e-6)]
    assert len(changed) > 10, "el período final sí cambió las métricas del período final"
    best = lambda r: {(e["level_key"], e["horizon_days"]): [x for x in e["metrics"]["comparison"].items() if x[0] == "best_statistical_family"]   # noqa: E731
                      for e in r.evaluations if e["level"] == "segment" and e["evaluation_type"] == "backtest" and e["model_family"] == "selection"}
    assert best(perturbed) == best(pipeline_result), "el mejor estadístico se decide sin el período final"
    assert verify(pipeline_result.manifest) == [] and verify(perturbed.manifest) == []
    hold = pipeline_result.manifest["holdout"]
    assert hold["independent"] is True and not any(hold[k] for k in ("used_for_model_selection", "used_for_hyperparameters", "used_for_thresholds",
                                                                    "used_for_interval_calibration"))


def test_segment_rows_carry_numbers_and_codes_not_repeated_texts(pipeline_result):
    rows = [e for e in pipeline_result.evaluations if e["level"] == "segment"]
    banned = ("thresholds", "horizon_basis_text")
    for e in rows:
        m = e["metrics"]
        assert not any(k in m for k in banned) and not any(k in m["segment"] for k in banned)
        assert m["definitions_ref"] == "forecast_runs.evaluation_manifest.segment_metrics"
        assert all("basis" not in (m.get(k) or {}) or k == "interval" for k in ("comparison", "horizon_total", "stability"))
        if m["interval"]:
            assert m["interval"]["basis"] in (None, "series_day_conformal_holdout")
    sizes = [len(jdump(e["metrics"])) for e in rows]
    assert sum(sizes) / len(sizes) < 3200, "media por fila (antes de quitar los textos estáticos: 3.384 B)"
    definitions = pipeline_result.manifest["segment_metrics"]
    assert definitions["enabled"] is True and definitions["thresholds"]["justification"].endswith("a aprobar en el piloto con datos reales")
    assert definitions["thresholds"]["wape_min_denominator"] == 30.0 and definitions["thresholds"]["summary_max_cells"] == 20
    assert set(definitions["best_statistical_chosen_with"]) == {"selection_windows", "same_windows_optimistic"}
