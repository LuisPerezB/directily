"""Ajuste de feriados de los modelos estadísticos (`holiday_adjustment_models`) y familia `weekday_average`.

Los modelos estadísticos solo ven fechas y unidades. Sin ajuste pronostican un feriado como un día normal y aprenden la caída de un
feriado pasado como demanda. Con el interruptor activo, el entrenamiento se limpia de feriados pasados y los feriados del horizonte se
multiplican por el factor de cada feriado; las referencias naive no se tocan. Apagado, la salida es exactamente la de siempre.
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.features.builder import FeatureBuilder, holidays_by_branch
from predictily_engine.models.holidays import (
    HOLIDAY_UNADJUSTED,
    apply_factors,
    clean_training,
    expected_without_holiday,
    holiday_factors,
)
from predictily_engine.models.registry import (
    CANDIDATES_BY_CLASS,
    FAMILIES,
    STATISTICAL,
    WEEKDAY_AVERAGE,
    WEEKDAY_FAMILIES,
    WEEKDAY_SWITCH_FAMILIES,
)
from predictily_engine.models.statistical import forecast_statistical, weekday_average
from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides

START = pd.Timestamp("2026-01-05")     # lunes
WEEKDAY_UNITS = np.array([10.0, 12.0, 14.0, 16.0, 18.0, 20.0, 0.0])   # lunes … domingo (no vende el domingo)


def series(uid: str = "b1-p1", weeks: int = 12, scale: float = 1.0, holidays: dict[str, float] | None = None) -> pd.DataFrame:
    """Serie diaria con ritmo semanal exacto; `holidays` = {fecha ISO: fracción de la venta normal ese día}."""
    ds = pd.date_range(START, periods=7 * weeks, freq="D")
    y = WEEKDAY_UNITS[ds.dayofweek.to_numpy()] * scale
    for day, frac in (holidays or {}).items():
        y[ds == pd.Timestamp(day)] *= frac
    return pd.DataFrame({"unique_id": uid, "ds": ds, "y": y})


def marks(uids: list[str], days: dict[str, str]) -> pd.DataFrame:
    return pd.DataFrame([{"unique_id": u, "ds": pd.Timestamp(d), "holiday": name} for u in uids for d, name in days.items()])


# ───────────────────────── weekday_average ─────────────────────────

def test_weekday_average_is_the_mean_of_the_last_k_same_weekdays():
    train = series(weeks=10)
    train.loc[train["ds"] == train["ds"].max() - pd.Timedelta(days=1), "y"] = 40.0     # el último sábado fue atípico (normal: 20)
    fc = weekday_average(train, h=14)
    assert set(fc["family"]) == {WEEKDAY_AVERAGE} and len(fc) == 14
    by_dow = fc.assign(dow=fc["ds"].dt.dayofweek).groupby("dow")["yhat"].first()
    assert by_dow.loc[[0, 1, 2, 3, 4]].to_numpy() == pytest.approx(WEEKDAY_UNITS[:5])
    assert by_dow.loc[5] == pytest.approx((20 * 3 + 40) / 4) and by_dow.loc[6] == 0.0
    # el horizonte empieza el día siguiente al corte y repite el patrón semana a semana
    assert fc["ds"].min() == train["ds"].max() + pd.Timedelta(days=1)
    assert fc["yhat"].to_numpy()[:7] == pytest.approx(fc["yhat"].to_numpy()[7:])


def test_weekday_average_with_short_history_uses_what_exists_and_zero_without_data():
    short = series(weeks=2).iloc[:10]              # 10 días: lunes a miércoles tienen 2 observaciones, el resto 1
    short.loc[short.index[0], "y"] = 30.0          # primer lunes
    fc = weekday_average(short, h=7).assign(dow=lambda d: d["ds"].dt.dayofweek).set_index("dow")["yhat"]
    assert fc.loc[0] == pytest.approx((30 + 10) / 2) and fc.loc[3] == pytest.approx(16.0)
    three_days = series(weeks=1).iloc[:3]          # sin ningún jueves observado
    fc = weekday_average(three_days, h=7).assign(dow=lambda d: d["ds"].dt.dayofweek).set_index("dow")["yhat"]
    assert fc.loc[3] == 0.0 and fc.loc[0] == pytest.approx(10.0)
    assert weekday_average(series().iloc[:0], h=7).empty


def test_weekday_average_is_a_statistical_candidate_behind_the_weekday_switch():
    info = FAMILIES[WEEKDAY_AVERAGE]
    assert info.kind == "statistical" and info.level == "A" and info.hyperparameters == {"k": 4} and WEEKDAY_AVERAGE in STATISTICAL
    assert WEEKDAY_SWITCH_FAMILIES == [*WEEKDAY_FAMILIES, WEEKDAY_AVERAGE]
    assert all(WEEKDAY_AVERAGE in cands and cands[-1] == "lightgbm_global" for cands in CANDIDATES_BY_CLASS.values())
    fc = forecast_statistical(pd.concat([series("a"), series("b", scale=2.0)]), h=7, families=["window_average", WEEKDAY_AVERAGE])
    assert set(fc["family"]) == {"window_average", WEEKDAY_AVERAGE} and len(fc) == 28
    b = fc[(fc["family"] == WEEKDAY_AVERAGE) & (fc["unique_id"] == "b")].sort_values("ds")["yhat"].to_numpy()
    assert b == pytest.approx(2.0 * WEEKDAY_UNITS)
    alone = forecast_statistical(series("a"), h=7, families=[WEEKDAY_AVERAGE])      # sin ninguna familia de StatsForecast
    assert alone["yhat"].to_numpy() == pytest.approx(WEEKDAY_UNITS)


# ───────────────────────── limpieza y factores ─────────────────────────

def test_expected_without_holiday_is_the_mean_of_the_neighbouring_same_weekdays():
    train = series(holidays={"2026-02-16": 0.5, "2026-02-23": 0.5})        # dos lunes seguidos
    hol = marks(["b1-p1"], {"2026-02-16": "Carnaval", "2026-02-23": "Otro"})
    obs = expected_without_holiday(train, hol).set_index("ds")
    # el vecino feriado no cuenta: lo esperable sigue siendo el lunes normal (10), no la media con el otro feriado
    assert obs["expected"].to_numpy() == pytest.approx([10.0, 10.0]) and obs["y"].to_numpy() == pytest.approx([5.0, 5.0])
    assert expected_without_holiday(train, marks(["b1-p1"], {"2031-01-01": "Futuro"})).empty
    only = series(weeks=1)
    lonely = expected_without_holiday(only, marks(["b1-p1"], {"2026-01-07": "Sin vecinos"}))
    assert np.isnan(lonely["expected"].iloc[0])


def test_factors_are_per_holiday_shrunk_to_the_common_one_and_use_only_recent_training():
    days = {"2026-01-19": "A", "2026-02-16": "A", "2026-03-09": "B"}
    train = pd.concat([series("x", weeks=12, holidays={"2026-01-19": 0.2, "2026-02-16": 0.2, "2026-03-09": 0.8}),
                       series("z", weeks=12, scale=3.0, holidays={"2026-01-19": 0.2, "2026-02-16": 0.2, "2026-03-09": 0.8})])
    f = holiday_factors(expected_without_holiday(train, marks(["x", "z"], days)), train["ds"].max())
    common = (2 * 0.2 + 0.8) / 3
    assert f["common"] == pytest.approx(common) and f["occurrences"] == {"A": 2, "B": 1}
    assert f["by_name"]["A"] == pytest.approx((2 / 3) * 0.2 + (1 / 3) * common)
    assert f["by_name"]["B"] == pytest.approx(0.5 * 0.8 + 0.5 * common)
    # solo cuentan los feriados dentro de la ventana de estimación
    recent = holiday_factors(expected_without_holiday(train, marks(["x", "z"], days)), train["ds"].max(), days=30)
    assert set(recent["by_name"]) == {"B"} and recent["common"] == pytest.approx(0.8)
    assert holiday_factors(pd.DataFrame(columns=["unique_id", "ds", "holiday", "y", "expected"]))["common"] == 1.0


def test_clean_training_replaces_only_past_holidays_and_never_reads_beyond_the_training():
    train = series(holidays={"2026-02-16": 0.0})
    hol = marks(["b1-p1"], {"2026-02-16": "Carnaval", "2026-04-06": "Posterior al corte"})
    cleaned, factors = clean_training(train, hol)
    assert cleaned.loc[cleaned["ds"] == pd.Timestamp("2026-02-16"), "y"].iloc[0] == pytest.approx(10.0)
    changed = cleaned["y"].to_numpy() != train["y"].to_numpy()
    assert changed.sum() == 1 and "Posterior al corte" not in factors["by_name"]
    assert factors["by_name"]["Carnaval"] == pytest.approx(0.0) and train["y"].iloc[42] == 0.0, "el original no se modifica"
    same, neutral = clean_training(train, marks(["b1-p1"], {"2026-04-06": "Posterior al corte"}))
    assert same is train and neutral == {"common": 1.0, "by_name": {}, "occurrences": {}}


def test_apply_factors_touches_only_holiday_rows_and_unseen_holidays_use_the_common_factor():
    fc = pd.DataFrame({"unique_id": "a", "ds": pd.date_range("2026-04-01", periods=3), "family": "auto_ets", "yhat": 10.0})
    hol = marks(["a"], {"2026-04-02": "Visto", "2026-04-03": "Nuevo"})
    out = apply_factors(fc, hol, {"common": 0.5, "by_name": {"Visto": 0.2}})
    assert out["yhat"].to_numpy() == pytest.approx([10.0, 2.0, 5.0]) and fc["yhat"].eq(10.0).all()
    assert apply_factors(fc, marks(["otra"], {"2026-04-02": "Visto"}), {"common": 0.5, "by_name": {}}) is fc


# ───────────────────────── en el pronóstico estadístico ─────────────────────────

def test_without_holidays_the_forecast_is_exactly_the_same():
    train = pd.concat([series("a"), series("b", scale=2.0)])
    families = ["seasonal_naive", "window_average", "auto_ets", "tsb_dow"]
    base = forecast_statistical(train, h=14, families=families)
    pd.testing.assert_frame_equal(base, forecast_statistical(train, h=14, families=families, holidays=None))
    pd.testing.assert_frame_equal(base, forecast_statistical(train, h=14, families=families, holidays=marks([], {})))


def test_holiday_in_training_no_longer_drags_the_level_and_the_future_holiday_is_scaled():
    # feriado (30 % de la venta normal) el último lunes de la historia y otro, del mismo nombre, el segundo lunes del horizonte
    past, future = "2026-03-23", "2026-04-06"
    train = pd.concat([series("a", holidays={"2026-01-19": 0.3, past: 0.3}), series("b", scale=2.0, holidays={"2026-01-19": 0.3, past: 0.3})])
    hol = marks(["a", "b"], {"2026-01-19": "Feriado", past: "Feriado", future: "Feriado"})
    info: dict = {}
    families = ["seasonal_naive", "window_average", WEEKDAY_AVERAGE]
    plain = forecast_statistical(train, h=14, families=families)
    adj = forecast_statistical(train, h=14, families=families, holidays=hol, info=info)
    assert info["holiday_factors"]["by_name"]["Feriado"] == pytest.approx((2 / 3) * 0.3 + (1 / 3) * 0.3)

    def of(df, fam, uid="a"):
        return df[(df["family"] == fam) & (df["unique_id"] == uid)].set_index("ds")["yhat"]

    # la referencia no se toca (repite la caída del feriado pasado y pronostica el feriado futuro como un lunes normal)
    assert all(f in HOLIDAY_UNADJUSTED for f in ("naive", "seasonal_naive"))
    pd.testing.assert_series_equal(of(plain, "seasonal_naive"), of(adj, "seasonal_naive"))
    # «mismo día de la semana»: sin ajuste el lunes hereda la caída (media de 3 lunes normales y el feriado); con ajuste vuelve a 10
    monday, holiday = pd.Timestamp("2026-03-30"), pd.Timestamp(future)
    assert of(plain, WEEKDAY_AVERAGE).loc[monday] == pytest.approx((3 * 10 + 3) / 4)
    assert of(adj, WEEKDAY_AVERAGE).loc[monday] == pytest.approx(10.0)
    assert of(adj, WEEKDAY_AVERAGE).loc[holiday] == pytest.approx(10.0 * 0.3)
    assert of(adj, WEEKDAY_AVERAGE, "b").loc[holiday] == pytest.approx(20.0 * 0.3)
    # promedio móvil: el nivel deja de arrastrar la caída y solo el día feriado del horizonte se reduce
    flat_plain, flat_adj = of(plain, "window_average"), of(adj, "window_average")
    assert flat_adj.loc[monday] > flat_plain.loc[monday]
    assert flat_adj.loc[holiday] == pytest.approx(flat_adj.loc[monday] * 0.3)
    others = flat_adj.drop(holiday)
    assert others.nunique() == 1


# ───────────────────────── feriados del calendario y configuración ─────────────────────────

def _builder(panel: pd.DataFrame, horizon_end: date) -> FeatureBuilder:
    branches = pd.DataFrame({"branch_id": [1], "region": ["sierra"], "canton": ["Quito"]})
    return FeatureBuilder(panel, branches, pd.DataFrame(), horizon_end)


def _panel(day_status: dict[str, str] | None = None) -> pd.DataFrame:
    ds = pd.date_range("2026-07-01", "2026-08-31", freq="D")
    df = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1, "category_id": 1, "ds": ds, "units": 5.0, "y": 5.0,
                       "stockout": False, "price": 1.0, "day_status": "sale", "day_basis": None})
    for day, status in (day_status or {}).items():
        df.loc[df["ds"] == pd.Timestamp(day), "day_status"] = status
    return df


def test_holidays_by_branch_names_the_same_days_the_calendar_flags():
    branches = pd.DataFrame({"branch_id": [1, 2], "region": ["sierra", "costa"], "canton": ["Quito", "Guayaquil"]})
    hol = holidays_by_branch(branches, date(2026, 7, 1), date(2026, 12, 31))
    assert set(hol.columns) == {"branch_id", "ds", "holiday"}
    quito, guayaquil = (set(hol.loc[hol["branch_id"] == b, "ds"]) for b in (1, 2))
    assert pd.Timestamp("2026-08-10") in quito and pd.Timestamp("2026-08-10") in guayaquil       # nacional
    assert pd.Timestamp("2026-07-24") in guayaquil and pd.Timestamp("2026-07-24") not in quito   # Fundación de Guayaquil (trasladado al viernes)
    builder = _builder(_panel(), date(2026, 12, 31))
    flagged = builder.calendar.loc[(builder.calendar["branch_id"] == 1) & (builder.calendar["is_holiday"] == 1), "ds"]
    assert set(pd.to_datetime(flagged)) == quito


def test_holiday_days_drop_past_excluded_days_but_never_look_at_the_future_status():
    cutoff = pd.Timestamp("2026-08-20")
    open_day = _builder(_panel(), date(2026, 12, 31)).holiday_days(cutoff)
    assert pd.Timestamp("2026-08-10") in set(open_day["ds"]) and (open_day["unique_id"] == "b1-p1").all()
    assert (open_day["ds"] > cutoff).any(), "los feriados del horizonte se conocen de antemano"
    closed = _builder(_panel({"2026-08-10": "closed"}), date(2026, 12, 31))
    assert pd.Timestamp("2026-08-10") not in set(closed.holiday_days(cutoff)["ds"]), "día excluido: su venta imputada no informa del feriado"
    # visto desde un corte anterior al feriado, el estado de ese día (futuro) no se consulta
    assert pd.Timestamp("2026-08-10") in set(closed.holiday_days(pd.Timestamp("2026-08-05"))["ds"])
    assert closed.holiday_days(cutoff, ids=["otra"]).empty


def test_switch_is_off_by_default_and_validated():
    assert CORE_DEFAULTS["demand"]["holiday_adjustment_models"] is False
    assert validate_overrides("demand", {"holiday_adjustment_models": True}) == []
    assert [e["key"] for e in validate_overrides("demand", {"holiday_adjustment_models": "si"})] == ["holiday_adjustment_models"]


# ───────────────────────── en el pipeline ─────────────────────────

@pytest.fixture(scope="module")
def adjusted_run(small_data):
    from dataclasses import replace

    from predictily_engine.pipeline.core import run_pipeline

    settings = {**(small_data.settings or {}), "cores": {"demand": {"holiday_adjustment_models": True}}}
    return run_pipeline(replace(small_data, settings=settings), horizon=90, seed=42, n_jobs=1)


def test_switch_on_adjusts_the_statistical_models_in_the_backtest_and_leaves_the_references_alone(pipeline_result, adjusted_run):
    keys = ["unique_id", "window", "ds"]

    def rows(result, family):
        return result.backtest[result.backtest["family"] == family].sort_values(keys).reset_index(drop=True)

    for reference in ("seasonal_naive", "naive", "lightgbm_global"):      # referencias y LightGBM (ya ve el calendario): idénticas
        pd.testing.assert_series_equal(rows(pipeline_result, reference)["yhat"], rows(adjusted_run, reference)["yhat"])
    before, after = rows(pipeline_result, "auto_ets"), rows(adjusted_run, "auto_ets")
    assert (before[keys].to_numpy() == after[keys].to_numpy()).all()
    assert not np.allclose(before["yhat"], after["yhat"])
    # en los feriados evaluables del backtest el total pronosticado baja hacia lo que de verdad se vendió
    features = FeatureBuilder(adjusted_run.panel.df, pd.DataFrame({"branch_id": sorted(adjusted_run.panel.df["branch_id"].unique()),
                                                                   "region": None, "canton": None}), pd.DataFrame(),
                              (pd.Timestamp(adjusted_run.cutoff) + pd.Timedelta(days=90)).date())
    national = set(features.holiday_days(pd.Timestamp(adjusted_run.cutoff))["ds"])
    on_holiday = before["ds"].isin(national) & ~before["stockout"].astype(bool)
    assert on_holiday.any()
    actual = before.loc[on_holiday, "actual"].sum()
    error_before = abs(before.loc[on_holiday, "yhat"].sum() - actual)
    error_after = abs(after.loc[on_holiday, "yhat"].sum() - actual)
    assert error_after < 0.6 * error_before


def test_switch_on_records_the_assumption_and_off_does_not(pipeline_result, adjusted_run):
    assert any("se ajustan por feriados" in a for a in adjusted_run.assumptions)
    assert not any("se ajustan por feriados" in a for a in pipeline_result.assumptions)
    assert adjusted_run.effective_config["config"]["holiday_adjustment_models"] is True
    assert adjusted_run.config_version != pipeline_result.config_version
