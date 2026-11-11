"""Cierres en el horizonte y días de la semana sin operación (`operating_weekdays`), sin base de datos y con datos FICTICIOS.

Una empresa que nunca abre ciertos días de la semana los declara: esos días son `closed` (no ceros observados), no inflan la
intermitencia de una serie que vende todos los días hábiles y se pronostican en cero. Un cierre del calendario (`closure`) que cae en
el horizonte también se pronostica en cero. Sin declarar nada, todo queda como siempre.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import TenantData
from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides
from predictily_engine.profiling.days import DayRules, closed_future_rows, normalize_weekdays, operating_weekdays_applied
from predictily_engine.profiling.readiness import compute_readiness, quiet_weekdays
from predictily_engine.profiling.series import ADI_THRESHOLD, build_panel

START = pd.Timestamp("2026-01-05")   # lunes
WEEKDAYS = [0, 1, 2, 3, 4]


def office(n_days: int = 84, open_days: tuple[int, ...] = (0, 1, 2, 3, 4), branches: tuple[int, ...] = (1,), units: float = 5.0) -> TenantData:
    """Empresa que vende `units` de cada producto solo los días de la semana de `open_days`."""
    days = [d for d in pd.date_range(START, periods=n_days, freq="D") if d.dayofweek in open_days]
    rows = [{"branch_id": b, "product_id": p, "ds": d, "units": units, "net_amount": units * 10.0, "gross_amount": units * 10.0,
             "discount_amount": 0.0, "cost_amount": units * 4.0, "cost_units": units, "lines": 4}
            for b in branches for p in (1, 2) for d in days]
    last = START + pd.Timedelta(days=n_days - 1)
    return TenantData(tenant_id=1, settings={}, cutoff=last.date(),
                      branches=pd.DataFrame({"branch_id": list(branches), "region": "sierra", "canton": "quito"}),
                      products=pd.DataFrame({"product_id": [1, 2], "category_id": 1, "is_active": True}),
                      categories=pd.DataFrame(), sales=pd.DataFrame(rows))


# ───────────────────────────── configuración ─────────────────────────────

def test_operating_weekdays_is_undefined_by_default_and_validated():
    assert CORE_DEFAULTS["demand"]["operating_weekdays"] is None
    for ok in (None, WEEKDAYS, [0, 1, 2, 3, 4, 5], list(range(7)), [4, 2, 0]):
        assert validate_overrides("demand", {"operating_weekdays": ok}) == [], ok
    for bad in ("lunes", [0, 0, 1], [0, 1, 7], [-1, 1, 2], [0, 1], [True, 1, 2], [0.0, 1, 2], 5):
        errors = validate_overrides("demand", {"operating_weekdays": bad})
        assert [e["code"] for e in errors] == ["invalid_weekdays"], bad


def test_no_declaration_or_the_seven_days_is_no_rule_at_all():
    assert normalize_weekdays(None) is None and normalize_weekdays([]) is None and normalize_weekdays(list(range(7))) is None
    assert normalize_weekdays([4, 0, 2, 2]) == (0, 2, 4)
    assert DayRules.from_config({}).operating_weekdays is None
    assert "operating_weekdays" not in DayRules.from_config({}).as_dict(), "sin declarar, el resumen de reglas queda como siempre"
    assert DayRules.from_config({"operating_weekdays": WEEKDAYS}).as_dict()["operating_weekdays"] == WEEKDAYS


# ───────────────────────────── clasificación de días y perfil ─────────────────────────────

def test_declared_non_operating_weekdays_are_closed_and_stop_inflating_intermittency():
    data = office()
    plain = build_panel(data, DayRules())
    weekend = plain.df["ds"].dt.dayofweek >= 5
    assert (plain.df.loc[weekend, "day_status"] == "zero_confirmed").all(), "sin declarar: sábados y domingos son ceros observados"
    assert (plain.profile["adi"] > ADI_THRESHOLD).all() and set(plain.profile["demand_class"]) == {"intermittent"}, \
        "vende todos los días hábiles y aun así queda como esporádica (ADI 7/5)"
    assert "operating_weekdays" not in plain.day_summary["notes"]

    declared = build_panel(data, DayRules(operating_weekdays=tuple(WEEKDAYS)))
    df = declared.df
    weekend = df["ds"].dt.dayofweek >= 5
    assert (df.loc[weekend, "day_status"] == "closed").all() and (df.loc[weekend, "day_basis"] == "declared").all()
    assert (df.loc[~weekend, "day_status"] == "sale").all()
    assert declared.profile["adi"].to_numpy() == pytest.approx(np.ones(2)) and set(declared.profile["demand_class"]) == {"smooth"}
    note = declared.day_summary["notes"]["operating_weekdays"]
    assert note["applied"] is True and note["weekdays"] == WEEKDAYS and note["recent_units_share_on_closed_weekdays"] == 0
    assert note["series_days"] == int(weekend.sum()) == declared.day_summary["series_days"]["closed"]
    assert operating_weekdays_applied(declared.day_summary) == tuple(WEEKDAYS)
    assert declared.day_summary["branch_days"]["missing_source_engine_inferred"] == 0


def test_a_declaration_the_recent_sales_contradict_is_not_applied():
    """La empresa declara lunes a viernes pero vende los sábados: no se cierra nada (nunca se pronostica cero un día que sí vende)."""
    data = office(open_days=(0, 1, 2, 3, 4, 5))
    panel = build_panel(data, DayRules(operating_weekdays=tuple(WEEKDAYS)))
    note = panel.day_summary["notes"]["operating_weekdays"]
    assert note["applied"] is False and note["recent_units_share_on_closed_weekdays"] == pytest.approx(1 / 6, abs=1e-3)
    assert note["series_days"] == 0 and panel.day_summary["series_days"]["closed"] == 0
    assert (panel.df.loc[panel.df["ds"].dt.dayofweek == 5, "day_status"] == "sale").all()
    assert operating_weekdays_applied(panel.day_summary) is None
    # Una venta suelta en un día declarado sin operación (menos del 1 % de las unidades recientes) no anula la declaración.
    sales = office().sales
    stray = sales.iloc[[0]].assign(ds=START + pd.Timedelta(days=75), units=1.0)        # un sábado de las últimas 8 semanas
    tolerant = build_panel(replace(office(), sales=pd.concat([sales, stray], ignore_index=True)), DayRules(operating_weekdays=tuple(WEEKDAYS)))
    assert tolerant.day_summary["notes"]["operating_weekdays"]["applied"] is True


def test_closed_rows_of_the_horizon_by_calendar_and_by_weekday():
    ds = pd.date_range("2026-03-30", periods=7, freq="D")                              # lunes a domingo
    branch = np.array([1] * 7 + [2] * 7)
    days = np.concatenate([ds.values, ds.values])
    closures = pd.DataFrame([{"branch_id": np.nan, "starts_on": ds[4], "ends_on": ds[4], "code": "viernes_santo"},
                             {"branch_id": 2, "starts_on": ds[1], "ends_on": ds[2], "code": "inventario"}])
    by_cal, by_dow = closed_future_rows(branch, days, closures, (0, 1, 2, 3, 4))
    assert list(np.flatnonzero(by_cal)) == [4, 8, 9, 11], "viernes en las dos sucursales; martes y miércoles solo en la 2"
    assert list(np.flatnonzero(by_dow)) == [5, 6, 12, 13], "sábado y domingo en las dos"
    none_cal, none_dow = closed_future_rows(branch, days, None, None)
    assert not none_cal.any() and not none_dow.any()


# ───────────────────────────── aviso de preparación (sugerencia, nunca decisión) ─────────────────────────────

def test_readiness_suggests_declaring_the_weekdays_that_never_sell():
    data = office()
    assert quiet_weekdays(data.sales, data.sales["ds"].max()) == [5, 6]
    assert quiet_weekdays(office(n_days=40).sales, office(n_days=40).sales["ds"].max()) == [], "con menos de 8 semanas no se sugiere"
    assert quiet_weekdays(office(open_days=tuple(range(7))).sales, data.sales["ds"].max()) == []

    plain = compute_readiness(data, build_panel(data, DayRules()))
    issue = next(i for i in plain["issues"] if i["code"] == "weekday_without_sales")
    assert issue["severity"] == "info" and issue["count"] == 2 and "sábado y domingo" in issue["message"]
    declared = compute_readiness(data, build_panel(data, DayRules(operating_weekdays=tuple(WEEKDAYS))))
    assert not any(i["code"] == "weekday_without_sales" for i in declared["issues"]), "ya declarados: no se vuelve a sugerir"
    # Días hábiles sin venta: con los días de operación declarados los sábados ya no cuentan como días que debían vender.
    assert declared["missing_days"] <= plain["missing_days"] and plain["missing_days"] >= 10 and declared["missing_days"] <= 2


# ───────────────────────────── pronóstico: cero explícito en los días cerrados ─────────────────────────────

def _day_rows(res, level: str):
    fv = res.forecast_values
    out = fv[(fv["granularity"] == "day") & (fv["level"] == level)].copy()
    out["dow"] = pd.to_datetime(out["target_date"]).dt.dayofweek
    return out


@pytest.fixture(scope="module")
def weekdays_only(small_data):
    """Empresa que no vende sábados ni domingos, los declara sin operación y además tiene un cierre futuro en una sucursal."""
    cutoff = pd.Timestamp(small_data.cutoff)
    sales = small_data.sales[pd.to_datetime(small_data.sales["ds"]).dt.dayofweek < 5].reset_index(drop=True)
    closing = next(cutoff + pd.Timedelta(days=k) for k in range(3, 12) if (cutoff + pd.Timedelta(days=k)).dayofweek == 2)   # un miércoles
    closures = pd.DataFrame([{"branch_id": 1, "starts_on": closing, "ends_on": closing, "code": "inventario"}])
    settings = {**(small_data.settings or {}), "cores": {"demand": {"operating_weekdays": WEEKDAYS}}}
    data = replace(small_data, sales=sales, closures=closures, settings=settings)
    return run_pipeline(data, horizon=30, seed=42, n_jobs=1), closing


def test_without_declarations_nothing_is_zeroed(pipeline_result):
    info = pipeline_result.summary["special_cases"]["closed_days_in_horizon"]
    assert info == {"series_days": 0, "dates": 0, "by_calendar_closure": 0, "by_operating_weekdays": 0}
    assert not (pipeline_result.forecast_values["case_label"] == "closed_day").any()
    assert not any("declaradas cerradas" in a for a in pipeline_result.assumptions)
    assert "operating_weekdays_not_applied" not in pipeline_result.summary["limitation_codes"]
    assert "operating_weekdays" not in pipeline_result.summary["day_status"]["rules"]


def test_non_operating_weekdays_are_forecast_as_an_explicit_zero_at_every_level(weekdays_only):
    res, _ = weekdays_only
    assert res.effective_config["config"]["operating_weekdays"] == WEEKDAYS
    assert res.summary["day_status"]["notes"]["operating_weekdays"]["applied"] is True
    series = _day_rows(res, "series")
    weekend = series[series["dow"] >= 5]
    assert len(weekend) and (weekend[["yhat", "lo_80", "hi_80", "lo_95", "hi_95", "revenue_yhat"]] == 0).all().all()
    assert (weekend["case_label"] == "closed_day").all()
    for level in ("total", "branch", "category", "product"):
        rows = _day_rows(res, level)
        closed = rows[rows["dow"] >= 5]
        assert len(closed) and (closed[["yhat", "lo_80", "hi_80", "lo_95", "hi_95"]] == 0).all().all(), level
        assert (rows.loc[rows["dow"] < 5, "yhat"] > 0).any(), level
    info = res.summary["special_cases"]["closed_days_in_horizon"]
    assert info["by_operating_weekdays"] == len(weekend) and info["series_days"] == info["by_operating_weekdays"] + info["by_calendar_closure"]
    assert any("declaradas cerradas" in a for a in res.assumptions)
    assert "days_closed" in res.summary["limitation_codes"] and "operating_weekdays_not_applied" not in res.summary["limitation_codes"]
    # Las series que venden todos los días hábiles ya no son esporádicas por los fines de semana.
    prof = res.panel.profile.query("is_active")
    assert (prof["closed_days"] > 0).all() and prof["adi"].median() < ADI_THRESHOLD


def test_a_calendar_closure_in_the_horizon_zeroes_only_its_branch(weekdays_only):
    res, closing = weekdays_only
    series = _day_rows(res, "series")
    that_day = series[pd.to_datetime(series["target_date"]) == closing]
    b1, others = that_day[that_day["branch_id"] == 1], that_day[that_day["branch_id"] != 1]
    assert len(b1) and (b1[["yhat", "hi_95"]] == 0).all().all() and (b1["case_label"] == "closed_day").all()
    assert len(others) and (others["yhat"] > 0).any() and not (others["case_label"] == "closed_day").any()
    branch = _day_rows(res, "branch")
    closed_branch = branch[(pd.to_datetime(branch["target_date"]) == closing) & (branch["branch_id"] == 1)]
    assert (closed_branch[["yhat", "lo_80", "hi_80", "lo_95", "hi_95"]] == 0).all().all()
    total = _day_rows(res, "total")
    total_day = total[pd.to_datetime(total["target_date"]) == closing].iloc[0]
    assert total_day["yhat"] > 0 and total_day["hi_95"] > 0, "el total suma las sucursales abiertas y conserva su intervalo"
    assert res.summary["special_cases"]["closed_days_in_horizon"]["by_calendar_closure"] == len(b1)
    # La jerarquía sigue siendo coherente.
    day = res.forecast_values.query("granularity == 'day'")
    np.testing.assert_allclose(day[day["level"] == "series"].groupby("target_date")["yhat"].sum(),
                               day[day["level"] == "total"].groupby("target_date")["yhat"].sum(), rtol=1e-6, atol=1e-3)


def test_a_contradicted_declaration_is_reported_and_changes_nothing(small_data, pipeline_result):
    settings = {**(small_data.settings or {}), "cores": {"demand": {"operating_weekdays": WEEKDAYS}}}
    res = run_pipeline(replace(small_data, settings=settings), horizon=30, seed=42, n_jobs=4)
    assert "operating_weekdays_not_applied" in res.summary["limitation_codes"]
    message = next(x["message"] for x in res.limitations if x["code"] == "operating_weekdays_not_applied")
    assert "lunes, martes, miércoles, jueves, viernes" in message and "NO se aplicaron" in message
    assert res.summary["special_cases"]["closed_days_in_horizon"]["series_days"] == 0
    assert not (res.forecast_values["case_label"] == "closed_day").any()
    cols = ["node_key", "granularity", "target_date", "yhat", "lo_80", "hi_80"]
    base = pipeline_result.forecast_values
    base = base[base["horizon_step"] <= 30] if "horizon_step" in base else base
    same = res.forecast_values[cols].merge(base[cols], on=["node_key", "granularity", "target_date"], suffixes=("", "_base"))
    day = same[same["granularity"] == "day"]
    assert len(day) and np.allclose(day["yhat"], day["yhat_base"], atol=1e-6), "la declaración rechazada no cambia ningún valor diario"
