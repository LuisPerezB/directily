"""E2-19: si falla el ganador (LightGBM final) y se sirve el respaldo, intervalos, métricas, etiquetas y
registro corresponden al modelo SERVIDO, no al ganador del backtest."""

import numpy as np
import pandas as pd
import pytest

import predictily_engine.pipeline.core as core
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.pipeline.core import run_pipeline


@pytest.fixture(scope="module")
def failed_run(small_data, pipeline_result):
    """Misma empresa y semilla, con el entrenamiento FINAL de LightGBM forzado a fallar (el backtest funciona)."""
    assert "lightgbm_global" in pipeline_result.planned_selection.winners.values(), \
        "precondición: LightGBM gana alguna clase en la ejecución normal"
    final_cutoff = pd.Timestamp(small_data.cutoff)

    class FailingFinalFit(LightGBMGlobal):
        def fit(self, train):
            if train["ds"].max() >= final_cutoff:
                raise RuntimeError("fallo forzado del entrenamiento final")
            return super().fit(train)

    mp = pytest.MonkeyPatch()
    mp.setattr(core, "LightGBMGlobal", FailingFinalFit)
    try:
        return run_pipeline(small_data, horizon=90, seed=42, n_jobs=1)
    finally:
        mp.undo()


def test_fallback_is_served_and_labeled(failed_run, pipeline_result):
    fv = failed_run.forecast_values
    assert set(failed_run.failed_families) == {"lightgbm_global"}
    assert "fallo forzado" in failed_run.failed_families["lightgbm_global"]
    served = failed_run.selection.winners
    assert "lightgbm_global" not in served.values()
    fallback = failed_run.selection.fallback
    planned = pipeline_result.planned_selection.winners
    for cls, fam in planned.items():
        assert served[cls] == (fallback if fam == "lightgbm_global" else fam)
    assert "lightgbm_global" not in set(fv["model_family"].dropna()), "ninguna fila se etiqueta con el modelo que falló"
    series = fv[(fv["granularity"] == "day") & (fv["level"] == "series")]
    assert set(series["model_family"]) <= set(served.values()) | {"window_average"}
    # Honestidad en el resultado: bandera, motivo y limitación.
    s = failed_run.summary
    assert s["served"]["fallback_used"] is True and s["served"]["failed_families"].keys() == {"lightgbm_global"}
    assert s["served"]["planned_models_by_class"]["intermittent"] == "lightgbm_global"
    assert s["served"]["served_models_by_class"]["intermittent"] == fallback
    assert "fallback_served" in s["limitation_codes"] and s["quality_status"] == "degraded"
    assert any("LightGBM falló" in w for w in failed_run.warnings)
    assert failed_run.manifest["served"]["fallback_used"] is True


def test_metrics_describe_the_served_model_not_the_planned_winner(failed_run, pipeline_result):
    """El WAPE publicado es el de las filas del backtest del modelo SERVIDO en cada clase (período final)."""
    hold = failed_run.backtest.query("is_holdout and not stockout")
    served = failed_run.evaluation_selection.winners
    num = den = 0.0
    for cls, fam in served.items():
        if cls == "pooled":
            continue
        rows = hold[(hold["demand_class"] == cls) & (hold["family"] == fam)]
        num += float((rows["actual"] - rows["yhat"]).abs().sum())
        den += float(rows["actual"].sum())
    reported = failed_run.summary["metrics"]["selected_wape"]
    assert reported == pytest.approx(num / den, rel=1e-9)
    planned = pipeline_result.summary["metrics"]["selected_wape"]
    assert reported != pytest.approx(planned), "con el respaldo servido las métricas cambian: no se reutilizan las del ganador"
    # Las cifras del run normal siguen intactas (no se contaminaron).
    assert pipeline_result.summary["served"]["fallback_used"] is False


def test_intervals_are_calibrated_with_the_residuals_of_the_served_family(failed_run):
    bt = failed_run.backtest
    fallback = failed_run.selection.fallback
    fv = failed_run.forecast_values
    uid = failed_run.panel.profile.query("demand_class == 'intermittent'")["unique_id"].iat[0]
    node = f"series:{uid[1:].split('-p')[0]}:{uid.split('-p')[1]}"
    row = fv[(fv["node_key"] == node) & (fv["granularity"] == "day") & (fv["horizon_step"] == 1)].iloc[0]
    assert row["model_family"] == fallback
    scale = failed_run.summary  # noqa: F841 - la escala se obtiene del propio resultado
    from predictily_engine.backtest.rolling import series_scale

    sc = float(series_scale(failed_run.panel.df, pd.Timestamp(failed_run.cutoff))[uid])

    def interval(family: str) -> tuple[float, float]:
        rows = bt[(bt["family"] == family) & (bt["demand_class"] == "intermittent") & ~bt["stockout"]]
        cal = ConformalCalibrator(rows.assign(group="g"), "group", day_bucket)
        out = cal.apply(pd.DataFrame({"group": ["g"], "step": [1], "yhat": [row["yhat"]], "scale": [sc]}))
        return float(out["lo_80"].iat[0]), float(out["hi_80"].iat[0])

    lo_served, hi_served = interval(fallback)
    assert row["lo_80"] == pytest.approx(lo_served, abs=1e-3) and row["hi_80"] == pytest.approx(hi_served, abs=1e-3)
    lo_planned, hi_planned = interval("lightgbm_global")
    assert (hi_planned - lo_planned) != pytest.approx(hi_served - lo_served, abs=1e-3), \
        "los intervalos del ganador que falló serían otros: no se usan"


def test_registry_info_marks_the_failed_family_and_the_fallback_role(failed_run, pipeline_result):
    fams = failed_run.families
    assert fams["lightgbm_global"]["status"] == "failed" and "fallo forzado" in fams["lightgbm_global"]["error"]
    fb = failed_run.selection.fallback
    native = fb in pipeline_result.planned_selection.winners.values()  # aquí el respaldo también gana la clase errática
    assert fams[fb]["status"] == "production" and fams[fb]["series_count"] > 0
    assert fams[fb]["role"] == ("primary" if native else "fallback")
    # Sin fallo, LightGBM sirve y las demás familias evaluadas quedan validadas (evaluadas y no elegidas).
    normal = pipeline_result.families
    assert normal["lightgbm_global"]["status"] == "production"
    assert {v["status"] for k, v in normal.items() if v["series_count"] == 0 and k != pipeline_result.selection.fallback} \
        <= {"validated"}
    # La selección persistida indica dónde se aplicó el respaldo.
    sel = failed_run.selection.as_json({}, failed_run.planned_selection)
    assert sel["intermittent"]["fallback_applied"] and sel["intermittent"]["planned_family"] == "lightgbm_global"
    assert "fallback_applied" not in sel["smooth"]


def test_hierarchy_stays_coherent_when_the_fallback_is_served(failed_run):
    day = failed_run.forecast_values.query("granularity == 'day'")
    total = day[day["level"] == "total"].groupby("target_date")["yhat"].sum()
    series = day[day["level"] == "series"].groupby("target_date")["yhat"].sum()
    np.testing.assert_allclose(series.loc[total.index], total, rtol=1e-6, atol=1e-3)


def test_a_standby_or_replacing_fallback_gets_the_fallback_role_and_losers_are_validated():
    from predictily_engine.selection.selector import Selection

    planned = Selection({"pooled": "auto_ets", "smooth": "auto_ets", "intermittent": "lightgbm_global"}, "seasonal_naive",
                        {}, pd.DataFrame(), pd.DataFrame())
    served = planned.served({"lightgbm_global"})
    assert served.winners["intermittent"] == "seasonal_naive" and served.winners["smooth"] == "auto_ets"
    fc = pd.DataFrame({"unique_id": ["b1-p1", "b1-p2"], "model_family": ["auto_ets", "seasonal_naive"]})
    bt = pd.DataFrame({"family": ["auto_ets", "seasonal_naive", "lightgbm_global", "window_average"]})
    active = pd.DataFrame({"demand_class": ["smooth", "intermittent"]})
    info = core._families_info(served, planned, fc, bt, active, {"lightgbm_global": "boom"})
    assert info["lightgbm_global"]["status"] == "failed" and info["lightgbm_global"]["error"] == "boom"
    assert (info["seasonal_naive"]["status"], info["seasonal_naive"]["role"]) == ("production", "fallback")
    assert (info["auto_ets"]["status"], info["auto_ets"]["role"]) == ("production", "primary")
    assert info["window_average"]["status"] == "validated" and info["window_average"]["series_count"] == 0
    # Sin fallo y sin series servidas por el respaldo: queda en espera, también en producción con su rol.
    standby = core._families_info(planned, planned, fc[fc["model_family"] == "auto_ets"], bt, active, {})
    assert (standby["seasonal_naive"]["status"], standby["seasonal_naive"]["role"]) == ("production", "fallback")
