"""Variantes con perfil semanal (`<base>_dow`) de los modelos estadísticos planos (sin base de datos, datos FICTICIOS).

Un negocio que casi no vende un día de la semana (los domingos de una distribuidora por rutas): los modelos planos (promedio móvil,
Croston SBA, ADIDA, TSB) reparten la demanda por igual entre los 7 días. Sus variantes `_dow` conservan el total semanal de la base y
lo reparten con el perfil de día de la semana de cada serie, encogido hacia el del negocio cuando la serie tiene poca venta.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from predictily_engine.models.registry import (
    CANDIDATES_BY_CLASS,
    FAMILIES,
    STATISTICAL,
    WEEKDAY_BASES,
    WEEKDAY_FAMILIES,
)
from predictily_engine.models.statistical import common_weekday_profile, forecast_statistical, weekday_profile

START = pd.Timestamp("2026-01-05")   # lunes


def route_sales(weeks: int = 10, weekday_units: float = 10.0, sunday_units: float = 0.0, uid: str = "1:1") -> pd.DataFrame:
    days = pd.date_range(START, periods=7 * weeks, freq="D")
    y = np.where(days.dayofweek == 6, sunday_units, weekday_units)
    return pd.DataFrame({"unique_id": uid, "ds": days, "y": y.astype(float)})


def test_the_profile_of_a_business_that_does_not_sell_on_sundays():
    f = weekday_profile(route_sales()).set_index("dow")["factor"]
    assert f.loc[6] == pytest.approx(0.0, abs=1e-9)
    assert f.loc[:5].to_numpy() == pytest.approx(np.full(6, 7 / 6))
    assert f.mean() == pytest.approx(1.0), "los factores conservan el total semanal"


def test_a_series_with_little_history_takes_the_rhythm_of_the_business():
    regular = route_sales(uid="regular")
    sparse = route_sales(uid="escasa", weekday_units=0.0, sunday_units=0.0)
    sparse.loc[sparse["ds"] == sparse["ds"].max() - pd.Timedelta(days=0), "y"] = 3.0     # una sola venta, un domingo
    prof = weekday_profile(pd.concat([regular, sparse])).pivot(index="unique_id", columns="dow", values="factor")
    own_sunday = 7.0   # su perfil propio pondría toda la semana en el domingo
    assert prof.loc["escasa", 6] < 0.5 < own_sunday, "con 1 día de venta pesa sobre todo el perfil común (domingo ≈ 0)"
    assert prof.loc["regular", 6] < 0.05, "la serie regular conserva su domingo casi nulo (el común solo aporta la venta suelta)"
    assert prof.mean(axis=1).to_numpy() == pytest.approx(np.ones(2))


def test_series_without_recent_rows_use_the_common_profile_and_no_sales_means_flat():
    old = route_sales(uid="antigua", weeks=2)
    old["ds"] = old["ds"] - pd.Timedelta(days=200)
    prof = weekday_profile(pd.concat([route_sales(uid="viva"), old])).pivot(index="unique_id", columns="dow", values="factor")
    assert prof.loc["antigua"].to_numpy() == pytest.approx(prof.loc["viva"].to_numpy())
    flat = weekday_profile(route_sales(weekday_units=0.0))
    assert flat["factor"].to_numpy() == pytest.approx(np.ones(7))


def test_dow_variants_keep_the_weekly_total_of_their_base_and_follow_the_weekday_pattern():
    train = route_sales(weeks=10)
    fc = forecast_statistical(train, h=14, families=["window_average", "window_average_dow"])
    assert set(fc["family"]) == {"window_average", "window_average_dow"}
    base = fc[fc["family"] == "window_average"].sort_values("ds")
    dow = fc[fc["family"] == "window_average_dow"].sort_values("ds")
    assert dow["yhat"].sum() == pytest.approx(base["yhat"].sum(), rel=1e-9), "mismo total en semanas completas"
    sunday = dow[pd.to_datetime(dow["ds"]).dt.dayofweek == 6]["yhat"]
    weekday = dow[pd.to_datetime(dow["ds"]).dt.dayofweek < 6]["yhat"]
    assert (sunday == 0).all() and weekday.to_numpy() == pytest.approx(np.full(12, 10.0))
    assert base["yhat"].to_numpy() == pytest.approx(np.full(14, 60 / 7)), "la base plana reparte la semana por igual"


@pytest.mark.parametrize("base", WEEKDAY_BASES)
def test_every_dow_variant_is_its_base_times_the_profile(base):
    train = pd.concat([route_sales(uid="a"), route_sales(uid="b", weekday_units=4.0, sunday_units=1.0)])
    fc = forecast_statistical(train, h=7, families=[base, base + "_dow"])
    prof = weekday_profile(train)
    wide = fc.pivot_table(index=["unique_id", "ds"], columns="family", values="yhat").reset_index()
    wide["dow"] = pd.to_datetime(wide["ds"]).dt.dayofweek
    wide = wide.merge(prof, on=["unique_id", "dow"])
    assert wide[base + "_dow"].to_numpy() == pytest.approx((wide[base] * wide["factor"]).to_numpy())


def test_asking_only_for_base_families_is_unchanged():
    train = route_sales()
    alone = forecast_statistical(train, h=7, families=["window_average", "seasonal_naive"]).reset_index(drop=True)
    with_dow = forecast_statistical(train, h=7, families=["window_average", "seasonal_naive", "tsb_dow"])
    kept = with_dow[with_dow["family"].isin(["window_average", "seasonal_naive"])].reset_index(drop=True)
    pd.testing.assert_frame_equal(alone, kept)
    assert "tsb" not in set(with_dow["family"]), "la base que solo se usa para la variante no se devuelve"


def test_the_variants_are_registered_statistical_candidates_of_every_class():
    assert WEEKDAY_FAMILIES == [b + "_dow" for b in WEEKDAY_BASES]
    for fam in WEEKDAY_FAMILIES:
        info = FAMILIES[fam]
        assert info.kind == "statistical" and info.level == "A" and fam in STATISTICAL
        assert "perfil semanal" in info.label and info.hyperparameters["weekday_profile_days"] == 56
        assert all(fam in cands for cands in CANDIDATES_BY_CLASS.values())
    assert all(cands[-1] == "lightgbm_global" for cands in CANDIDATES_BY_CLASS.values())


# ───────────────────────────── interruptor por empresa ─────────────────────────────

def test_the_variants_are_off_by_default_and_the_switch_is_validated():
    from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides

    assert CORE_DEFAULTS["demand"]["weekday_profile_models"] is False
    assert validate_overrides("demand", {"weekday_profile_models": True}) == []
    assert {e["key"] for e in validate_overrides("demand", {"weekday_profile_models": "si"})} == {"weekday_profile_models"}


def test_off_they_never_compete_and_on_they_compete_in_every_class(small_data, pipeline_result):
    from dataclasses import replace

    from predictily_engine.pipeline.core import run_pipeline

    assert not pipeline_result.backtest["family"].isin(WEEKDAY_FAMILIES).any(), "apagado: ni se evalúan"
    assert pipeline_result.effective_config["config"]["weekday_profile_models"] is False
    settings = {**(small_data.settings or {}), "cores": {"demand": {"weekday_profile_models": True}}}
    on = run_pipeline(replace(small_data, settings=settings), horizon=90, seed=42, n_jobs=4)
    assert set(WEEKDAY_FAMILIES) <= set(on.backtest["family"]), "encendido: las cuatro variantes entran al backtest"
    assert on.effective_config["config"]["weekday_profile_models"] is True
    assert any(f in WEEKDAY_FAMILIES for f in on.selection.winners.values()), \
        "en estos datos sintéticos (venta con ritmo semanal) alguna variante gana su clase"


# ───────────────────────────── ritmo del negocio para las series sin perfil propio ─────────────────────────────

def test_the_common_profile_is_the_rhythm_of_the_business():
    f = common_weekday_profile(pd.concat([route_sales(uid="a"), route_sales(uid="b", weekday_units=2.0)]))
    assert f.shape == (7,) and f[6] == pytest.approx(0.0, abs=1e-9)
    assert f[:6] == pytest.approx(np.full(6, 7 / 6)) and f.mean() == pytest.approx(1.0)
    assert common_weekday_profile(route_sales(weekday_units=0.0)) == pytest.approx(np.ones(7)), "sin ventas: plano"
    assert common_weekday_profile(route_sales().iloc[:0]) == pytest.approx(np.ones(7)), "sin filas: plano"


def test_the_common_profile_only_looks_at_the_recent_window():
    old = route_sales(uid="antes", weeks=8, weekday_units=0.0, sunday_units=50.0)     # antes solo vendía los domingos
    old["ds"] = old["ds"] - pd.Timedelta(days=7 * 8)
    f = common_weekday_profile(pd.concat([old, route_sales(uid="ahora", weeks=8)]))
    assert f[6] == pytest.approx(0.0, abs=1e-9), "los últimos 56 días no tienen domingos: la venta antigua no cuenta"


def test_a_series_without_recent_rows_gets_exactly_the_common_profile():
    old = route_sales(uid="antigua", weeks=2)
    old["ds"] = old["ds"] - pd.Timedelta(days=200)
    train = pd.concat([route_sales(uid="viva", weekday_units=4.0, sunday_units=1.0), old])
    prof = weekday_profile(train).pivot(index="unique_id", columns="dow", values="factor")
    assert prof.loc["antigua"].to_numpy() == pytest.approx(common_weekday_profile(train))


def _short_plan(levels: dict[str, float]):
    from predictily_engine.demand.lifecycle import LifecyclePlan

    return LifecyclePlan(labels=pd.Series(dtype=object), zero_ids=[], abstain={}, short_levels=pd.Series(levels, dtype=float),
                         short_family=pd.Series({k: "window_average" for k in levels}, dtype=object))


def test_short_series_follow_the_rhythm_of_the_business_and_keep_their_weekly_total():
    from predictily_engine.demand.lifecycle import short_forecast

    plan, cutoff = _short_plan({"b1-p1": 6.0, "b1-p2": 0.5}), START + pd.Timedelta(days=69)     # corte en domingo
    factors = common_weekday_profile(route_sales())
    flat = short_forecast(plan, ["b1-p1", "b1-p2"], cutoff, 14)
    prof = short_forecast(plan, ["b1-p1", "b1-p2"], cutoff, 14, weekday_factors=factors)
    assert flat.groupby("unique_id")["yhat"].nunique().eq(1).all(), "sin factores: el nivel plano de siempre"
    pd.testing.assert_frame_equal(flat, short_forecast(plan, ["b1-p1", "b1-p2"], cutoff, 14, weekday_factors=None))
    pd.testing.assert_frame_equal(flat.drop(columns="yhat"), prof.drop(columns="yhat"))
    sunday = pd.to_datetime(prof["ds"]).dt.dayofweek == 6
    assert (prof.loc[sunday, "yhat"] == 0).all(), "el negocio no vende los domingos: la serie nueva tampoco"
    assert prof.loc[~sunday & (prof["unique_id"] == "b1-p1"), "yhat"].to_numpy() == pytest.approx(np.full(12, 7.0))
    assert prof.groupby("unique_id")["yhat"].sum().to_numpy() == pytest.approx(flat.groupby("unique_id")["yhat"].sum().to_numpy())


def test_a_flat_fallback_follows_the_rhythm_of_the_business_and_a_seasonal_one_is_untouched():
    from predictily_engine.pipeline.core import fill_missing_with_fallback

    days = pd.date_range(START + pd.Timedelta(days=70), periods=7, freq="D")           # lunes a domingo
    wide = pd.DataFrame({"unique_id": ["b1-p1"] * 7 + ["b1-p2"] * 7, "ds": list(days) * 2,
                         "window_average": 6.0, "seasonal_naive": [5.0] * 6 + [0.0] + [9.0] * 7})
    factors = common_weekday_profile(route_sales())

    def fill(fallback, weekday):
        yhat = np.array([np.nan] * 7 + [3.0] * 7)      # la serie 1 se quedó sin pronóstico de su familia
        fam = np.array(["tsb"] * 14, dtype=object)
        return fill_missing_with_fallback(wide, yhat, fam, fallback, weekday), yhat, fam

    n, yhat, fam = fill("window_average", factors)
    assert n == 1 and list(fam[:7]) == ["window_average"] * 7 and list(fam[7:]) == ["tsb"] * 7
    assert yhat[:6] == pytest.approx(np.full(6, 7.0)) and yhat[6] == 0 and yhat[:7].sum() == pytest.approx(42.0)
    assert yhat[7:] == pytest.approx(np.full(7, 3.0)), "las filas con pronóstico propio no se tocan"
    n, yhat, _ = fill("window_average", None)
    assert n == 0 and yhat[:7] == pytest.approx(np.full(7, 6.0)), "interruptor apagado: el respaldo plano de siempre"
    n, yhat, fam = fill("seasonal_naive", factors)
    assert n == 0 and yhat[:7] == pytest.approx([5.0] * 6 + [0.0]) and fam[0] == "seasonal_naive", \
        "un respaldo que ya ve el día de la semana no se multiplica otra vez"
    n, yhat, _ = fill("auto_ets", factors)
    assert n == 0 and np.isnan(yhat[:7]).all(), "sin columna del respaldo no hay con qué rellenar"

