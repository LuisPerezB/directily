"""ADR-V25-06 · FeatureAvailabilityValidator: estados y reglas por clase (sin base de datos).

Las pruebas T11 contra PostgreSQL (filas insertadas directamente) están en `tests/integration/test_factors_t11_integration.py`.
Aquí se fijan las reglas puras: frontera del día local (W1), disponibilidad desconocida (supply), revisiones, antigüedad,
vigencia, pronósticos de terceros, escenarios y eventos.
"""

from __future__ import annotations

import json
from datetime import date, datetime, time, timedelta
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd
import pytest

from predictily_engine.availability import (
    AvailabilityClass as C,
)
from predictily_engine.availability import (
    AvailabilityStatus as S,
)
from predictily_engine.availability import (
    FactorSpec,
    FeatureAvailabilityValidator,
    ForecastBacktestForbidden,
    Reason,
    ScenarioInAccuracyError,
    assert_accuracy_eligible,
    filter_for_accuracy,
)
from predictily_engine.availability.visibility import local_date, to_utc_naive, visible_mask
from predictily_engine.data.asof import knowledge_boundary
from predictily_engine.supply.availability import known_by

GYE = "America/Guayaquil"        # UTC-5 todo el año
D0 = date(2026, 10, 5)


def at(day: date | str, hour: int = 10, minute: int = 0, second: int = 0, tz: str = GYE) -> pd.Timestamp:
    d = date.fromisoformat(day) if isinstance(day, str) else day
    return pd.Timestamp(datetime.combine(d, time(hour, minute, second), tzinfo=ZoneInfo(tz)))


def day(offset: int) -> date:
    return D0 + timedelta(days=offset)


def spec(cls: C = C.OBSERVED_HISTORICAL, key: str = "f", stale: int | None = None, missing: str = "disable_feature") -> FactorSpec:
    return FactorSpec(key, cls, max_staleness_days=stale, missing_strategy=missing)


def frame(rows: list[dict]) -> pd.DataFrame:
    return pd.DataFrame(rows)


def validator(retroactive: bool = True, tz: str = GYE) -> FeatureAvailabilityValidator:
    return FeatureAvailabilityValidator(tz, retroactive=retroactive)


# ───────────────────────── la regla de visibilidad es la de W1 y la de supply ─────────────────────────

def test_visibility_is_strict_at_the_local_midnight_of_the_next_day_and_independent_of_the_storage_timezone():
    obs = lambda published: frame([{"id": 1, "period_date": day(-3), "value": 1.0, "published_at": published}])  # noqa: E731
    f = spec()
    # 23:59:59 locales del día del corte (04:59:59 UTC del día siguiente): visible.
    last_second = at(D0, 23, 59, 59)
    assert validator().evaluate(f, obs(last_second), D0).status is S.AVAILABLE
    # 00:00:00 locales del día siguiente (05:00 UTC): todavía NO es del día del corte.
    midnight = at(day(1), 0, 0, 0)
    assert validator().evaluate(f, obs(midnight), D0).status is S.NOT_YET_AVAILABLE
    assert validator().evaluate(f, obs(midnight), day(1)).status is S.AVAILABLE
    # El mismo instante dado en UTC, con otra zona de origen, o sin zona (se interpreta UTC) da el mismo resultado.
    assert validator().evaluate(f, obs(last_second.tz_convert("UTC")), D0).status is S.AVAILABLE
    assert validator().evaluate(f, obs(last_second.tz_convert("Asia/Tokyo")), D0).status is S.AVAILABLE
    assert validator().evaluate(f, obs(midnight.tz_convert("Europe/Madrid")), D0).status is S.NOT_YET_AVAILABLE
    assert validator().evaluate(f, obs(last_second.tz_convert("UTC").tz_localize(None)), D0).status is S.AVAILABLE
    assert validator().evaluate(f, obs(midnight.tz_convert("UTC").tz_localize(None)), D0).status is S.NOT_YET_AVAILABLE


@pytest.mark.parametrize("zone", [GYE, "UTC", "Europe/Madrid", "Asia/Tokyo", "America/New_York"])
def test_the_rule_is_exactly_the_w1_boundary_and_the_supply_day_rule(zone):
    """La visibilidad compone `knowledge_boundary` (W1) y `known_by` (supply): ambas coinciden en cada instante de frontera."""
    for cutoff in (date(2026, 3, 8), date(2026, 3, 29), date(2026, 10, 5), date(2026, 11, 1)):    # incluye cambios de horario
        edge = pd.Timestamp(knowledge_boundary(cutoff, zone))                       # M(C+1) en UTC sin zona
        probes = [edge - pd.Timedelta(days=1), edge - pd.Timedelta(nanoseconds=1), edge, edge + pd.Timedelta(nanoseconds=1),
                  edge + pd.Timedelta(hours=3)]
        mask = visible_mask(to_utc_naive(pd.Series(probes)), cutoff, zone, retroactive=True)
        by_instant = [bool(p < edge) for p in probes]
        by_day = [known_by(local_date(p, zone), cutoff, False)[0] for p in probes]    # regla por día de supply
        assert list(mask) == by_instant == by_day, (zone, cutoff)


def test_unknown_availability_follows_the_supply_rule_live_accepts_retroactive_excludes():
    unknown = pd.Series([pd.NaT, pd.Timestamp("2026-01-01")], dtype="datetime64[ns]")
    assert list(visible_mask(unknown, D0, GYE, retroactive=False)) == [True, True]
    assert list(visible_mask(unknown, D0, GYE, retroactive=True)) == [False, True]
    assert known_by(None, D0, False) == (True, "current_run") and known_by(None, D0, True) == (False, "unknown_availability")


# ───────────────────────── OBSERVED_HISTORICAL ─────────────────────────

IPC = frame([
    {"id": 1, "period_date": "2026-08-01", "value": 100.0, "published_at": "2026-09-08", "revision": 0},
    {"id": 2, "period_date": "2026-09-01", "value": 101.0, "published_at": "2026-10-08", "revision": 0},
    {"id": 3, "period_date": "2026-09-01", "value": 101.5, "published_at": "2026-10-20", "revision": 1},
    {"id": 4, "period_date": "2026-10-01", "value": 102.0, "published_at": "2026-11-08", "revision": 0},
])


def test_publication_lag_and_revisions_give_the_value_in_force_at_each_cutoff():
    f, v = spec(C.OBSERVED_HISTORICAL, stale=90), validator(tz="UTC")
    expected = {"2026-09-07": None, "2026-09-08": (100.0, 0), "2026-10-05": (100.0, 0), "2026-10-08": (101.0, 0),
                "2026-10-19": (101.0, 0),                 # la revisión aún no se publica: se ve la anterior
                "2026-10-20": (101.5, 1), "2026-11-07": (101.5, 1), "2026-11-08": (102.0, 0)}
    for cutoff, want in expected.items():
        r = v.evaluate(f, IPC, date.fromisoformat(cutoff))
        if want is None:
            assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.AFTER_CUTOFF and r.value is None, cutoff
        else:
            assert (r.status, r.value, r.revision) == (S.AVAILABLE, want[0], want[1]), cutoff


def test_it_agrees_with_the_legacy_as_of_reader_wherever_the_legacy_reader_returns_a_value():
    from predictily_engine.features.external import as_of_value

    legacy = IPC.assign(period_date=pd.to_datetime(IPC["period_date"]), published_at=pd.to_datetime(IPC["published_at"]))
    f, v = spec(C.OBSERVED_HISTORICAL, stale=365), validator(tz="UTC")
    for cutoff in pd.date_range("2026-09-01", "2026-11-12"):
        old = as_of_value(legacy, cutoff)
        new = v.evaluate(f, IPC, cutoff.date())
        assert (old is None) == (new.status is S.NOT_YET_AVAILABLE), cutoff
        if old is not None:
            assert new.value == old, cutoff


def test_a_later_revision_never_rewrites_the_past_but_the_series_for_training_uses_each_day_as_of():
    """Entrenar con la revisión de hoy una fila de hace un mes es fuga: la serie usa, para cada fecha, lo visible ESA fecha."""
    f, v = spec(C.OBSERVED_HISTORICAL, stale=90), validator(tz="UTC")
    s = v.evaluate_series(f, IPC, pd.date_range("2026-10-15", "2026-10-25"), final_cutoff=date(2026, 10, 25)).set_index("ds")
    assert s.loc["2026-10-19", "value"] == 101.0 and s.loc["2026-10-20", "value"] == 101.5
    assert (s.loc["2026-10-15":"2026-10-19", "value"] == 101.0).all()


def test_stale_when_the_api_goes_down_the_age_is_labeled_and_the_missing_strategy_decides_the_fallback():
    daily = frame([{"id": i, "period_date": day(-40 + i), "value": float(i), "published_at": at(day(-40 + i), 23)}
                   for i in range(0, 21)])                          # la API dejó de entregar hace 20 días (último período: día -20)
    drop = validator().evaluate(spec(stale=7), daily, D0)
    assert drop.status is S.STALE and drop.reason == Reason.STALE and drop.age_days == 20
    assert drop.fallback == "disable_feature" and not drop.usable_as_feature and drop.value == 20.0 and drop.max_staleness_days == 7
    keep = validator().evaluate(spec(stale=7, missing="last_valid_with_age"), daily, D0)
    assert keep.status is S.STALE and keep.usable_as_feature and keep.value == 20.0 and keep.age_days == 20
    unavailable = validator().evaluate(spec(stale=7, missing="unavailable"), daily, D0)
    assert unavailable.status is S.STALE and unavailable.fallback == "unavailable" and not unavailable.usable_as_feature
    # Con otra antigüedad máxima el mismo dato es utilizable; y sin ninguna no hay caducidad.
    assert validator().evaluate(spec(stale=30), daily, D0).status is S.AVAILABLE
    assert validator().evaluate(spec(stale=None), daily, D0).status is S.AVAILABLE


def test_the_value_is_carried_over_the_horizon_with_a_growing_age_until_it_turns_stale():
    obs = frame([{"id": 1, "period_date": day(-2), "value": 7.0, "published_at": at(day(-1), 9)}])
    f = spec(stale=10)
    near = validator().evaluate(f, obs, D0, target_date=day(5))
    assert near.status is S.AVAILABLE and near.age_days == 7 and "carried_forward" in near.flags
    far = validator().evaluate(f, obs, D0, target_date=day(20))
    assert far.status is S.STALE and far.age_days == 22 and far.value == 7.0


def test_validity_end_marks_stale_even_inside_the_staleness_window():
    obs = frame([{"id": 1, "period_date": day(-5), "value": 3.0, "published_at": at(day(-5), 9), "valid_to": day(-1)}])
    r = validator().evaluate(spec(stale=60), obs, D0)
    assert r.status is S.STALE and r.reason == Reason.VALIDITY_EXPIRED and r.age_days == 1


def test_invalid_observations_are_never_used_and_say_why():
    f, v = spec(stale=60), validator()
    no_value = frame([{"id": 1, "period_date": day(-3), "value": None, "published_at": at(day(-3), 9)}])
    assert (v.evaluate(f, no_value, D0).status, v.evaluate(f, no_value, D0).reason) == (S.INVALID, Reason.MISSING_VALUE)
    early = frame([{"id": 1, "period_date": day(-3), "value": 1.0, "observed_at": at(day(-1), 9), "published_at": at(day(-2), 9)}])
    assert v.evaluate(f, early, D0).reason == Reason.AVAILABLE_BEFORE_OBSERVED
    future_period = frame([{"id": 1, "period_date": day(30), "value": 1.0, "published_at": at(day(-2), 9)}])   # «observado» mañana
    assert v.evaluate(f, future_period, D0).reason == Reason.PERIOD_AFTER_AVAILABILITY
    # Una fila válida y otra inválida: solo cuenta la válida.
    mixed = pd.concat([no_value, frame([{"id": 2, "period_date": day(-4), "value": 9.0, "published_at": at(day(-4), 9)}])])
    r = v.evaluate(f, mixed, D0)
    assert r.status is S.AVAILABLE and r.value == 9.0


def test_unknown_availability_is_excluded_in_a_retroactive_reconstruction_and_accepted_in_a_live_run():
    no_dates = frame([{"id": 1, "period_date": day(-3), "value": 5.0}])
    retro = validator(retroactive=True).evaluate(spec(C.KNOWN_IN_ADVANCE), no_dates.assign(valid_from=day(-3), valid_to=day(9)), D0)
    assert retro.status is S.INVALID and retro.reason == Reason.UNKNOWN_AVAILABILITY
    live = validator(retroactive=False).evaluate(spec(C.KNOWN_IN_ADVANCE), no_dates.assign(valid_from=day(-3), valid_to=day(9)), D0)
    assert live.status is S.AVAILABLE and live.value == 5.0
    retro_obs = validator(retroactive=True).evaluate(spec(), no_dates, D0)
    assert retro_obs.status is S.INVALID and retro_obs.reason == Reason.UNKNOWN_AVAILABILITY


def test_availability_is_the_greatest_of_publication_and_ingestion_unless_declared():
    row = {"id": 1, "period_date": day(-10), "value": 1.0, "published_at": at(day(-8), 9), "ingested_at": at(day(2), 9)}
    f = spec(stale=365)
    # Publicado hace 8 días pero cargado el día +2: en el corte nadie lo tenía.
    assert validator().evaluate(f, frame([row]), D0).status is S.NOT_YET_AVAILABLE
    assert validator().evaluate(f, frame([row]), day(2)).status is S.AVAILABLE
    # Una `available_at` declarada manda sobre GREATEST(published_at, ingested_at).
    declared = {**row, "available_at": at(day(-7), 9)}
    r = validator().evaluate(f, frame([declared]), D0)
    assert r.status is S.AVAILABLE and r.available_at == at(day(-7), 9).tz_convert("UTC").tz_localize(None)


# ───────────────────────── STATIC / KNOWN_IN_ADVANCE ─────────────────────────

def test_static_values_are_versioned_by_availability_and_never_stale():
    rows = frame([{"id": 1, "period_date": "2025-01-01", "value": 1.0, "available_at": at("2025-01-01"), "revision": 0},
                  {"id": 2, "period_date": "2026-10-10", "value": 2.0, "available_at": at("2026-10-10"), "revision": 1}])
    f = spec(C.STATIC, stale=1)
    assert validator().evaluate(f, rows, D0).value == 1.0
    r = validator().evaluate(f, rows, date(2026, 10, 10))
    assert (r.status, r.value) == (S.AVAILABLE, 2.0)


def test_known_in_advance_future_values_are_allowed_when_they_were_known_before_the_cutoff():
    campaign = frame([{"id": 1, "period_date": day(5), "value": 1.0, "valid_from": day(5), "valid_to": day(7),
                       "available_at": at(day(-3), 11), "revision": 0}])
    f, v = spec(C.KNOWN_IN_ADVANCE), validator()
    for offset in (5, 6, 7):
        r = v.evaluate(f, campaign, D0, target_date=day(offset))
        assert r.status is S.AVAILABLE and r.value == 1.0 and "known_in_advance" in r.flags and r.detail["lead_days"] == offset
    assert v.evaluate(f, campaign, D0, target_date=day(8)).reason == Reason.NO_VALUE_FOR_DATE
    assert v.evaluate(f, campaign, D0, target_date=day(4)).reason == Reason.NO_VALUE_FOR_DATE
    # Conocida después del corte: para ese corte NO existía.
    late = campaign.assign(available_at=at(day(2), 11))
    r = v.evaluate(f, late, D0, target_date=day(6))
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.KNOWN_AFTER_CUTOFF
    assert v.evaluate(f, late, day(2), target_date=day(6)).status is S.AVAILABLE


def test_known_in_advance_uses_the_last_revision_known_at_the_cutoff():
    rows = frame([{"id": 1, "period_date": day(5), "value": 1.0, "valid_from": day(5), "valid_to": day(7),
                   "available_at": at(day(-3)), "revision": 0},
                  {"id": 2, "period_date": day(5), "value": 1.0, "valid_from": day(5), "valid_to": day(9),
                   "available_at": at(day(1)), "revision": 1}])
    f, v = spec(C.KNOWN_IN_ADVANCE), validator()
    assert v.evaluate(f, rows, D0, target_date=day(8)).status is S.NOT_YET_AVAILABLE      # la ampliación aún no se conocía
    after = v.evaluate(f, rows, day(1), target_date=day(8))
    assert after.status is S.AVAILABLE and after.revision == 1


def test_the_series_of_a_known_in_advance_factor_is_read_at_the_final_cutoff():
    campaign = frame([{"id": 1, "period_date": day(2), "value": 1.0, "valid_from": day(2), "valid_to": day(3),
                       "available_at": at(day(-3)), "revision": 0}])
    s = validator().evaluate_series(spec(C.KNOWN_IN_ADVANCE), campaign, pd.date_range(day(0), day(4)), final_cutoff=D0)
    assert s["usable"].tolist() == [False, False, True, True, False]
    assert s.loc[s["usable"], "value"].tolist() == [1.0, 1.0]


# ───────────────────────── THIRD_PARTY_FORECAST ─────────────────────────

def forecast_rows(issued: list[pd.Timestamp | None], target: date, values: list[float], registered: pd.Timestamp | None = None) -> pd.DataFrame:
    return frame([{"id": i + 1, "period_date": target, "value": values[i], "revision": i, "issued_at": issued[i],
                   "published_at": registered or issued[i], "ingested_at": registered or issued[i]} for i in range(len(values))])


def test_a_forecast_issued_tomorrow_but_registered_today_is_not_available_today():
    rows = forecast_rows([at(day(1), 6)], day(3), [31.0], registered=at(D0, 9))
    r = validator().evaluate(spec(C.THIRD_PARTY_FORECAST, stale=5), rows, D0, target_date=day(3))
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.ISSUED_AFTER_CUTOFF and r.value is None
    assert "registered_before_issue" in r.flags
    again = validator().evaluate(spec(C.THIRD_PARTY_FORECAST, stale=5), rows, day(1), target_date=day(3))
    assert again.status is S.AVAILABLE and again.value == 31.0 and "registered_before_issue" in again.flags


def test_the_visible_forecast_is_the_latest_issuance_up_to_the_cutoff_and_it_ages_from_its_issue_date():
    rows = forecast_rows([at(day(-4), 6), at(day(-1), 6), at(day(2), 6)], day(3), [10.0, 12.0, 99.0])
    f, v = spec(C.THIRD_PARTY_FORECAST, stale=2), validator()
    r = v.evaluate(f, rows, D0, target_date=day(3))
    assert (r.status, r.value, r.revision, r.age_days) == (S.AVAILABLE, 12.0, 1, 1)
    assert v.evaluate(f, rows, day(2), target_date=day(3)).value == 99.0
    # Si la API que emite pronósticos deja de hacerlo, el último vigente envejece y pasa a STALE.
    old = forecast_rows([at(day(-9), 6)], day(3), [10.0])
    s = v.evaluate(f, old, D0, target_date=day(3))
    assert s.status is S.STALE and s.age_days == 9


def test_a_source_without_archived_issuances_is_invalid_for_a_backtest_and_the_backtest_is_forbidden():
    no_history = forecast_rows([None, None], day(3), [10.0, 11.0], registered=at(day(-1), 9))
    f = spec(C.THIRD_PARTY_FORECAST, stale=5)
    r = validator(retroactive=True).evaluate(f, no_history, D0, target_date=day(3))
    assert r.status is S.INVALID and r.reason == Reason.NO_ISSUANCE_HISTORY and not r.usable_as_feature
    with pytest.raises(ForecastBacktestForbidden):
        validator(retroactive=True).assert_backtest_allowed(f, no_history)
    assert validator().issuance_archive(f, no_history) == {"status": "missing", "rows": 2, "with_issued_at": 0}
    # En una ejecución vigente el pronóstico más reciente sí vale (es información actual, no una reconstrucción).
    live = validator(retroactive=False).evaluate(f, no_history, D0, target_date=day(3))
    assert live.status is S.AVAILABLE
    # Un archivo parcial no se prohíbe, pero la fila sin emisión no cuenta.
    partial = forecast_rows([at(day(-1), 6), None], day(3), [10.0, 77.0], registered=at(day(-1), 9))
    validator().assert_backtest_allowed(f, partial)
    assert validator().issuance_archive(f, partial)["status"] == "partial"
    assert validator(retroactive=True).evaluate(f, partial, D0, target_date=day(3)).value == 10.0
    # Otras clases no tienen historial de emisiones que exigir.
    validator().assert_backtest_allowed(spec(C.OBSERVED_HISTORICAL), no_history)


def test_forecast_series_uses_the_forecast_in_force_the_day_before_each_target():
    rows = forecast_rows([at(day(-2), 6)], day(1), [5.0])
    s = validator().evaluate_series(spec(C.THIRD_PARTY_FORECAST, stale=30), rows, pd.date_range(day(0), day(2)), final_cutoff=D0).set_index("ds")
    assert s.loc[pd.Timestamp(day(1)), "usable"] and not s.loc[pd.Timestamp(day(0)), "usable"]


# ───────────────────────── USER_SCENARIO / UNAVAILABLE_FOR_HORIZON ─────────────────────────

def test_a_user_scenario_is_scenario_only_and_never_enters_accuracy():
    rows = frame([{"id": 1, "period_date": day(3), "value": 0.25, "published_at": at(day(-9), 9)}])
    r = validator().evaluate(spec(C.USER_SCENARIO, key="descuento_supuesto"), rows, D0, target_date=day(3))
    assert r.status is S.SCENARIO_ONLY and r.reason == Reason.USER_SCENARIO and r.value == 0.25
    assert not r.usable_as_feature and not r.eligible_for_accuracy and r.fallback is None
    # Aunque tenga fechas de disponibilidad válidas, no se convierte en AVAILABLE.
    real = validator().evaluate(spec(C.OBSERVED_HISTORICAL, key="real"), rows.assign(period_date=day(-3)), D0)
    results = [r, real]
    assert [x.factor_key for x in filter_for_accuracy(results)] == ["real"]
    with pytest.raises(ScenarioInAccuracyError):
        assert_accuracy_eligible(r)
    assert_accuracy_eligible(real)
    assert validator().evaluate(spec(C.USER_SCENARIO), frame([]), D0).status is S.SCENARIO_ONLY      # sin filas también


def test_unavailable_for_horizon_is_never_visible():
    rows = frame([{"id": 1, "period_date": day(-1), "value": 1.0, "published_at": at(day(-1))}])
    r = validator().evaluate(spec(C.UNAVAILABLE_FOR_HORIZON), rows, D0, target_date=day(30))
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.UNAVAILABLE_FOR_HORIZON and not r.usable_as_feature
    assert r.fallback == "disable_feature"


def test_a_source_with_no_observations_is_not_yet_available_not_a_zero():
    r = validator().evaluate(spec(), frame([]), D0)
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.NO_OBSERVATIONS and r.value is None
    assert validator().evaluate(spec(), None, D0).reason == Reason.NO_OBSERVATIONS


def test_t02_climatology_published_after_the_cutoff_is_not_available_on_a_past_date():
    """T02: la normal climática de hace 30 días se publicó (y se cargó) 10 días DESPUÉS del corte: en ese corte no existe."""
    obs = frame([{"id": 1, "period_date": day(-30), "value": 27.5, "published_at": at(day(10), 9), "ingested_at": at(day(10), 9)}])
    f = spec(stale=400)
    r = validator().evaluate(f, obs, D0)
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.AFTER_CUTOFF and r.value is None and not r.usable_as_feature
    assert validator().evaluate(f, obs, day(9)).status is S.NOT_YET_AVAILABLE
    assert validator().evaluate(f, obs, day(10)).value == 27.5
    # Y la serie de entrenamiento no ve ese dato en ninguna fecha anterior a su publicación.
    series = validator().evaluate_series(f, obs, pd.date_range(day(-5), day(12)), final_cutoff=day(12)).set_index("ds")
    assert not series.loc[: pd.Timestamp(day(9)), "usable"].any() and series.loc[pd.Timestamp(day(10)), "value"] == 27.5


# ───────────────────────── eventos externos ─────────────────────────

def event(**kw) -> dict:
    base = {"id": 7, "status": "VERIFIED", "published_at": at(day(-5), 9), "available_at": None, "created_at": at(day(-5), 10),
            "verified_at": at(day(-4), 9), "effective_from": day(-5), "effective_to": day(30), "is_informative_alert": False,
            "corroboration": "official"}
    return {**base, **kw}


def test_an_event_without_publication_date_is_invalid_whatever_its_status():
    for status in ("PROPOSED", "VERIFIED", "REJECTED", "EXPIRED"):
        r = validator().evaluate_event(event(published_at=None, status=status), D0)
        assert r.status is S.INVALID and r.reason == Reason.MISSING_PUBLISHED_AT and not r.usable_as_feature


def test_only_a_verified_event_known_verified_and_published_before_the_cutoff_is_available():
    v = validator()
    ok = v.evaluate_event(event(), D0, target_date=day(10))
    assert ok.status is S.AVAILABLE and ok.usable_as_feature and ok.detail["applies_on_target"] is True
    assert v.evaluate_event(event(status="PROPOSED"), D0).reason == Reason.EVENT_NOT_VERIFIED
    assert v.evaluate_event(event(status="REJECTED"), D0).reason == Reason.EVENT_REJECTED
    published_later = v.evaluate_event(event(published_at=at(day(1), 9), created_at=at(day(1), 9), verified_at=at(day(2), 9)), D0)
    assert published_later.status is S.NOT_YET_AVAILABLE and published_later.reason == Reason.PUBLISHED_AFTER_CUTOFF
    verified_later = v.evaluate_event(event(verified_at=at(day(1), 9)), D0)
    assert verified_later.status is S.NOT_YET_AVAILABLE and verified_later.reason == Reason.VERIFIED_AFTER_CUTOFF
    assert v.evaluate_event(event(verified_at=at(day(1), 9)), day(1)).status is S.AVAILABLE
    # Publicado antes pero registrado (ingestión) después: la disponibilidad real es la mayor.
    ingested_later = v.evaluate_event(event(created_at=at(day(1), 9), verified_at=at(day(-4), 9)), D0)
    assert ingested_later.status is S.NOT_YET_AVAILABLE and ingested_later.reason == Reason.AFTER_CUTOFF


def test_an_expired_event_is_stale_and_an_informative_alert_is_not_a_predictive_feature():
    v = validator()
    gone = v.evaluate_event(event(effective_to=day(-1)), D0)
    assert gone.status is S.STALE and gone.reason == Reason.VALIDITY_EXPIRED and gone.age_days == 1 and not gone.usable_as_feature
    alert = v.evaluate_event(event(is_informative_alert=True), D0)
    assert alert.status is S.AVAILABLE and not alert.usable_as_feature and "informative_alert" in alert.flags
    shown = v.evaluate_event(event(is_informative_alert=True), D0, as_feature=False)       # para mostrarla como alerta
    assert shown.usable_as_feature


def test_an_expired_event_never_verified_is_not_available_in_any_run_kind():
    """PROPOSED → EXPIRED (venció sin verificarse): sin `verified_at` y `verified_by` no es AVAILABLE ni en vigente ni en retroactivo."""
    never_verified = event(status="EXPIRED", verified_at=None, verified_by=None, effective_to=day(-1))
    for retroactive in (True, False):
        r = validator(retroactive=retroactive).evaluate_event(never_verified, D0)
        assert r.status is S.INVALID and r.reason == Reason.EVENT_NOT_VERIFIED and not r.usable_as_feature, retroactive
    # Con fecha de verificación pero sin quién la hizo, tampoco (segregación: consta el verificador).
    no_verifier = event(status="EXPIRED", verified_at=at(day(-4), 9), verified_by=None)
    assert validator(retroactive=False).evaluate_event(no_verifier, D0).reason == Reason.EVENT_NOT_VERIFIED
    assert validator(retroactive=False).evaluate_event(event(status="EXPIRED", verified_at=None, verified_by=3), D0).reason == \
        Reason.EVENT_NOT_VERIFIED
    # Verificado y luego vencido: vale mientras estuvo vigente (la vigencia vencida antes de la fecha de uso es STALE).
    verified_then_expired = event(status="EXPIRED", verified_at=at(day(-4), 9), verified_by=3, effective_to=day(2))
    for retroactive in (True, False):
        ok = validator(retroactive=retroactive).evaluate_event(verified_then_expired, D0, target_date=day(1))
        assert ok.status is S.AVAILABLE and ok.usable_as_feature, retroactive
    stale = validator().evaluate_event(verified_then_expired, D0, target_date=day(10))
    assert stale.status is S.STALE and stale.reason == Reason.VALIDITY_EXPIRED
    # El mismo evento verificado DESPUÉS del corte no existía como verificado en ese corte.
    later = event(status="EXPIRED", verified_at=at(day(1), 9), verified_by=3)
    assert validator().evaluate_event(later, D0).reason == Reason.VERIFIED_AFTER_CUTOFF
    # PROPOSED sigue siendo INVALID en cualquier modo (el estado actual no demuestra una verificación pasada).
    for retroactive in (True, False):
        assert validator(retroactive=retroactive).evaluate_event(event(status="PROPOSED", verified_at=None), D0).reason == \
            Reason.EVENT_NOT_VERIFIED


def test_a_verified_event_without_verification_date_depends_on_the_run_kind():
    ev = event(verified_at=None)
    assert validator(retroactive=True).evaluate_event(ev, D0).reason == Reason.VERIFICATION_DATE_UNKNOWN
    assert validator(retroactive=False).evaluate_event(ev, D0).status is S.AVAILABLE


# ───────────────────────── especificación del factor y serialización ─────────────────────────

def test_factor_spec_resolves_the_staleness_limit_and_rejects_unknown_values():
    row = {"key": "ipc", "availability_class": "OBSERVED_HISTORICAL", "frequency": "monthly", "max_staleness_days": None,
           "missing_strategy": "last_valid_with_age", "family": "trade_regulatory", "storage": "external_indicator_observations"}
    by_frequency = {"monthly": 75, "daily": 7}
    assert FactorSpec.from_definition(row, 90, by_frequency).max_staleness_days == 75
    assert FactorSpec.from_definition({**row, "frequency": "irregular"}, 90, by_frequency).max_staleness_days == 90
    assert FactorSpec.from_definition({**row, "max_staleness_days": 12}, 90, by_frequency).max_staleness_days == 12
    assert FactorSpec.from_definition({**row, "frequency": None}, None, None).max_staleness_days is None
    with pytest.raises(ValueError):
        FactorSpec.from_definition({**row, "availability_class": "WHENEVER"})
    with pytest.raises(ValueError):
        FactorSpec.from_definition({**row, "missing_strategy": "guess"})


def test_diagnostics_about_rows_after_the_cutoff_stay_out_of_serialized_results():
    """`hidden_after_cutoff` cuenta filas POSTERIORES al corte: sirve para depurar, no para un manifiesto (cambiaría al llegar datos)."""
    rows = [{"id": 1, "period_date": day(-5), "value": 10.0, "published_at": at(day(-4), 8), "revision": 0},
            {"id": 2, "period_date": day(-5), "value": 12.0, "published_at": at(day(2), 8), "revision": 1}]
    r = validator().evaluate(spec(stale=30), frame(rows), D0)
    assert r.value == 10.0 and r.detail["hidden_after_cutoff"] == 1
    assert "hidden_after_cutoff" not in r.as_dict()["detail"]
    # Con o sin la revisión posterior, lo serializado del corte es lo mismo.
    assert validator().evaluate(spec(stale=30), frame(rows[:1]), D0).as_dict() == r.as_dict()


def test_results_are_serializable_and_carry_their_dates():
    obs = frame([{"id": np.int64(3), "period_date": day(-1), "value": 2.0, "published_at": at(day(-1), 9)}])
    r = validator().evaluate(spec(stale=10), obs, D0)
    payload = json.loads(json.dumps(r.as_dict()))
    assert payload["status"] == "AVAILABLE" and payload["record_id"] == 3 and payload["published_at"].startswith("2026-10-04")
    assert payload["usable_as_feature"] is True and payload["target_date"] == D0.isoformat()
