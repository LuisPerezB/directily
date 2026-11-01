"""T11 · factores externos, fuentes y tiempo — pruebas adversariales contra PostgreSQL (ADR-V25-06 y ADR-V25-25).

Las filas se insertan DIRECTAMENTE en `external_indicators`, `external_indicator_observations`, `external_events`, `calendar_events` y
`factor_definitions` (contrato de la migración `2026_03_04_000100`) y se leen con `FactorsRepository`; el estado lo decide el
`FeatureAvailabilityValidator`. Cada prueba trabaja dentro de una transacción que se revierte. Todo es FICTICIO.

Los siete casos de V2.5 §26 T11 (documento sanitario publicado después, pronóstico meteorológico emitido mañana pero registrado
hoy, precio de competencia actualizado retroactivamente, noticia sin fecha, nueva revisión de estadísticas, API fallida y evento de
campaña conocido por anticipado) más el escenario del usuario: cada uno afirma su estado exacto, su motivo y que no usa conocimiento
futuro.
"""

from __future__ import annotations

import json
from contextlib import contextmanager
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest
from asof_helpers import local, make_world
from sqlalchemy import event, text

from predictily_engine.availability import (
    AvailabilityClass as AC,
)
from predictily_engine.availability import (
    AvailabilityStatus as S,
)
from predictily_engine.availability import (
    FactorPipeline,
    FactorResolution,
    FactorSpec,
    FeatureAvailabilityValidator,
    ForecastBacktestForbidden,
    Reason,
    filter_for_accuracy,
)
from predictily_engine.db.factors_repository import (
    AmbiguousCalendarError,
    AmbiguousIndicatorError,
    FactorsRepository,
    ScopeUnsupportedError,
)
from predictily_engine.db.repository import TenantRepository, TenantScopeError
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.features.external import as_of_series, as_of_value, load_observations
from predictily_engine.profiling.series import build_panel

pytestmark = pytest.mark.integration

C = date(2026, 10, 5)
GYE = "America/Guayaquil"


def d(offset: int) -> date:
    return C + timedelta(days=offset)


@contextmanager
def rolled_back(db_engine):
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


@pytest.fixture
def conn(db_engine):
    with rolled_back(db_engine) as connection:
        yield connection


@pytest.fixture
def world(conn):
    return make_world(conn, n_branches=1, n_products=1, n_customers=0, name="Empresa T11 A (ficticia)")


@pytest.fixture
def other_world(conn):
    return make_world(conn, n_branches=1, n_products=1, n_customers=0, name="Empresa T11 B (ficticia)")


# ───────────────────────── inserciones directas ─────────────────────────

def add_indicator(conn, tenant_id, code, *, frequency="daily", active=True) -> int:
    return int(conn.execute(text(
        "INSERT INTO external_indicators (tenant_id, code, name, source, frequency, is_active, created_at, updated_at) "
        "VALUES (:t, :code, :name, 'prueba ficticia', :f, :a, now(), now()) RETURNING id"
    ), {"t": tenant_id, "code": code, "name": f"Indicador {code}", "f": frequency, "a": active}).scalar_one())


def add_obs(conn, indicator_id, period, value, published, *, revision=0, ingested=None, **cols) -> int:
    """`ingested` por defecto = publicación (ingestión rutinaria el mismo día); las columnas nuevas van por nombre."""
    params = {"i": indicator_id, "p": period, "v": value, "pub": published, "ing": ingested or published, "rev": revision,
              **{k: cols.get(k) for k in ("observed_at", "available_at", "valid_from", "valid_to", "issued_at",
                                          "forecast_horizon_days", "scope_ref")}}
    return int(conn.execute(text(
        "INSERT INTO external_indicator_observations (indicator_id, period_date, value, published_at, ingested_at, revision, "
        "observed_at, available_at, valid_from, valid_to, issued_at, forecast_horizon_days, scope_ref) "
        "VALUES (:i, :p, :v, :pub, :ing, :rev, :observed_at, :available_at, :valid_from, :valid_to, :issued_at, "
        ":forecast_horizon_days, :scope_ref) RETURNING id"), params).scalar_one())


def add_definition(conn, tenant_id, key, availability_class, storage, *, indicator_id=None, frequency="daily",
                   missing="disable_feature", max_stale=None, schema=None, family="competition", active=False) -> int:
    return int(conn.execute(text(
        'INSERT INTO factor_definitions (tenant_id, family, key, name, availability_class, storage, external_indicator_id, frequency, '
        'missing_strategy, max_staleness_days, "schema", is_active, created_at, updated_at) '
        "VALUES (:t, :fam, :key, :name, :cls, :storage, :ind, :freq, :miss, :stale, CAST(:schema AS jsonb), :act, now(), now()) RETURNING id"
    ), {"t": tenant_id, "fam": family, "key": key, "name": f"Factor {key}", "cls": availability_class, "storage": storage,
        "ind": indicator_id, "freq": frequency, "miss": missing, "stale": max_stale, "schema": json.dumps(schema or {}),
        "act": active}).scalar_one())


def add_event(conn, tenant_id, *, published, status="VERIFIED", verified=None, created=None, family="food_safety_alerts",
              effective_from=None, effective_to=None, alert=False, title="Aviso sanitario ficticio") -> int:
    return int(conn.execute(text(
        "INSERT INTO external_events (tenant_id, family, event_type, title, published_at, status, verified_at, created_at, updated_at, "
        "effective_from, effective_to, is_informative_alert) "
        "VALUES (:t, :fam, 'notice', :title, :pub, :st, :ver, :cre, :cre, :ef, :et, :al) RETURNING id"
    ), {"t": tenant_id, "fam": family, "title": title, "pub": published, "st": status, "ver": verified,
        "cre": created or published or local(C, 8), "ef": effective_from, "et": effective_to, "al": alert}).scalar_one())


def add_calendar_event(conn, tenant_id, code, starts, ends, known_at, *, revision=0, status="confirmed", scope="country",
                       scope_ref="EC") -> int:
    return int(conn.execute(text(
        "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, source, "
        "revision, created_at, updated_at) VALUES (:t, :scope, :ref, :code, :code, 'exceptional', :s, :e, :st, :k, 'manual', :rev, "
        "now(), now()) RETURNING id"
    ), {"t": tenant_id, "code": code, "s": starts, "e": ends, "st": status, "k": known_at, "rev": revision, "scope": scope,
        "ref": scope_ref}).scalar_one())


def validator(retroactive: bool = True) -> FeatureAvailabilityValidator:
    return FeatureAvailabilityValidator(GYE, retroactive=retroactive)


def spec_for(repo: FactorsRepository, key: str) -> FactorSpec:
    return FactorSpec.from_definition(repo.resolve_definition(key))


# ───────────────────────── los siete casos de T11 ─────────────────────────

def test_t11_sanitary_document_published_after_the_cutoff_is_not_yet_available(conn, world):
    """Documento sanitario con vigencia desde antes del corte, pero PUBLICADO después: para el corte no existía."""
    event_id = add_event(conn, world.tenant_id, published=local(d(2), 10), verified=local(d(3), 9), created=local(d(2), 10),
                         effective_from=d(-5), effective_to=d(60))
    row = FactorsRepository(conn, world.tenant_id).events(["food_safety_alerts"]).set_index("id").loc[event_id]
    v = validator()
    r = v.evaluate_event(row.to_dict() | {"id": event_id}, C)
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.PUBLISHED_AFTER_CUTOFF and not r.usable_as_feature
    assert v.evaluate_event(row.to_dict() | {"id": event_id}, d(2)).reason == Reason.VERIFIED_AFTER_CUTOFF
    # Una vez publicado Y verificado, y solo entonces, es visible.
    now_visible = v.evaluate_event(row.to_dict() | {"id": event_id}, d(3))
    assert now_visible.status is S.AVAILABLE and now_visible.record_id == event_id


def test_t11_weather_forecast_issued_tomorrow_but_registered_today_is_not_yet_available(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_temperatura_pronostico")
    add_definition(conn, world.tenant_id, "t11_clima", "THIRD_PARTY_FORECAST", "external_indicator_observations",
                   indicator_id=ind, family="weather", max_stale=5)
    # Para el día C+3: emitido mañana (C+1, 06:00) pero registrado HOY (C, 09:00).
    add_obs(conn, ind, d(3), 31.0, local(C, 9), issued_at=local(d(1), 6), forecast_horizon_days=3)
    repo = FactorsRepository(conn, world.tenant_id)
    spec, obs = spec_for(repo, "t11_clima"), repo.indicator_observations(ind)
    r = validator().evaluate(spec, obs, C, target_date=d(3))
    assert r.status is S.NOT_YET_AVAILABLE and r.reason == Reason.ISSUED_AFTER_CUTOFF and r.value is None
    assert "registered_before_issue" in r.flags and not r.usable_as_feature
    assert validator().evaluate(spec, obs, d(1), target_date=d(3)).value == 31.0         # desde su emisión sí
    # Para el día C+4 hay una emisión ANTERIOR (ayer) y otra de mañana: el valor visible es el de ayer, nunca el de mañana.
    add_obs(conn, ind, d(4), 28.0, local(d(-1), 7), revision=0, issued_at=local(d(-1), 6), forecast_horizon_days=5)
    add_obs(conn, ind, d(4), 31.0, local(d(1), 7), revision=1, issued_at=local(d(1), 6), forecast_horizon_days=3)
    obs = repo.indicator_observations(ind)
    seen = validator().evaluate(spec, obs, C, target_date=d(4))
    assert seen.status is S.AVAILABLE and seen.value == 28.0 and seen.revision == 0 and seen.age_days == 1
    assert validator().evaluate(spec, obs, d(1), target_date=d(4)).value == 31.0


def test_t11_competitor_price_revised_retroactively_shows_the_previous_revision_at_the_cutoff(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_precio_competencia")
    add_definition(conn, world.tenant_id, "t11_competencia", "OBSERVED_HISTORICAL", "external_indicator_observations",
                   indicator_id=ind, max_stale=30)
    add_obs(conn, ind, d(-5), 10.0, local(d(-4), 8), revision=0)                     # precio del día C-5 conocido en C-4
    add_obs(conn, ind, d(-5), 12.0, local(d(2), 8), revision=1)                      # corregido retroactivamente en C+2
    repo = FactorsRepository(conn, world.tenant_id)
    spec, obs = spec_for(repo, "t11_competencia"), repo.indicator_observations(ind)
    at_cutoff = validator().evaluate(spec, obs, C)
    assert (at_cutoff.status, at_cutoff.value, at_cutoff.revision) == (S.AVAILABLE, 10.0, 0)
    assert at_cutoff.detail["hidden_after_cutoff"] == 1                               # la revisión existe pero no era conocida
    assert validator().evaluate(spec, obs, d(1)).value == 10.0
    after = validator().evaluate(spec, obs, d(2))
    assert (after.value, after.revision) == (12.0, 1)


def test_t11_news_without_date_is_invalid(conn, world):
    event_id = add_event(conn, world.tenant_id, published=None, status="VERIFIED", verified=local(d(-3), 9), created=local(d(-3), 9),
                         family="trade_regulatory", title="Noticia sin fecha")
    row = FactorsRepository(conn, world.tenant_id).events(["trade_regulatory"]).set_index("id").loc[event_id].to_dict() | {"id": event_id}
    assert row["published_at"] is None or pd.isna(row["published_at"])
    for cutoff in (d(-100), C, d(100)):                                                # ni antes ni después: nunca se puede situar
        r = validator().evaluate_event(row, cutoff)
        assert r.status is S.INVALID and r.reason == Reason.MISSING_PUBLISHED_AT and not r.usable_as_feature
    assert validator(retroactive=False).evaluate_event(row, C).status is S.INVALID    # tampoco en una ejecución vigente


def test_t11_new_statistical_revision_is_the_one_in_force_at_each_cutoff(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_ipc", frequency="monthly")
    add_definition(conn, world.tenant_id, "t11_ipc_factor", "OBSERVED_HISTORICAL", "external_indicator_observations",
                   indicator_id=ind, frequency="monthly", max_stale=365, family="trade_regulatory")
    add_obs(conn, ind, date(2026, 8, 1), 100.0, local(date(2026, 9, 8), 9), revision=0)
    add_obs(conn, ind, date(2026, 9, 1), 101.0, local(date(2026, 10, 8), 9), revision=0)
    add_obs(conn, ind, date(2026, 9, 1), 101.5, local(date(2026, 10, 20), 9), revision=1)       # nueva revisión estadística
    repo = FactorsRepository(conn, world.tenant_id)
    spec, obs = spec_for(repo, "t11_ipc_factor"), repo.indicator_observations(ind)
    expected = {date(2026, 9, 7): None, date(2026, 9, 8): (100.0, 0), date(2026, 10, 7): (100.0, 0), date(2026, 10, 8): (101.0, 0),
                date(2026, 10, 19): (101.0, 0), date(2026, 10, 20): (101.5, 1), date(2026, 12, 31): (101.5, 1)}
    for cutoff, want in expected.items():
        r = validator().evaluate(spec, obs, cutoff)
        if want is None:
            assert r.status is S.NOT_YET_AVAILABLE, cutoff
        else:
            assert (r.status, r.value, r.revision) == (S.AVAILABLE, want[0], want[1]), cutoff


def test_t11_failed_api_is_stale_with_its_age_and_the_factor_is_dropped(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_api_caida")
    add_definition(conn, world.tenant_id, "t11_api", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=7, missing="disable_feature")
    for back in range(40, 19, -1):                       # la API dejó de responder: el último dato es de hace 20 días
        add_obs(conn, ind, d(-back), float(back), local(d(-back), 22))
    repo = FactorsRepository(conn, world.tenant_id)
    spec = spec_for(repo, "t11_api")
    r = validator().evaluate(spec, repo.indicator_observations(ind), C)
    assert r.status is S.STALE and r.reason == Reason.STALE and r.age_days == 20 and r.max_staleness_days == 7
    assert r.fallback == "disable_feature" and not r.usable_as_feature and r.value == 20.0
    # Con otra estrategia de ausencia se conserva el último valor válido, etiquetado con su antigüedad.
    keep = FactorSpec("t11_api", AC.OBSERVED_HISTORICAL, 7, "last_valid_with_age")
    labeled = validator().evaluate(keep, repo.indicator_observations(ind), C)
    assert labeled.status is S.STALE and labeled.usable_as_feature and labeled.age_days == 20
    # Un indicador sin NINGUNA observación (la API nunca respondió) no es STALE: no hay nada que envejezca.
    empty = add_indicator(conn, world.tenant_id, "t11_api_nunca")
    nothing = validator().evaluate(spec, repo.indicator_observations(empty), C)
    assert nothing.status is S.NOT_YET_AVAILABLE and nothing.reason == Reason.NO_OBSERVATIONS


def test_t11_campaign_known_in_advance_is_available_for_the_future_days_of_the_horizon(conn, world):
    add_calendar_event(conn, world.tenant_id, "T11_CYBER", d(5), d(7), local(d(-3), 11))
    add_definition(conn, world.tenant_id, "t11_campana", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": "T11_CYBER"})
    repo = FactorsRepository(conn, world.tenant_id)
    spec = spec_for(repo, "t11_campana")
    obs = repo.observations_for(repo.resolve_definition("t11_campana"))
    horizon = {offset: validator().evaluate(spec, obs, C, target_date=d(offset)) for offset in range(1, 11)}
    for offset in (5, 6, 7):
        r = horizon[offset]
        assert r.status is S.AVAILABLE and r.value == 1.0 and "known_in_advance" in r.flags and r.detail["lead_days"] == offset
    assert [o for o, r in horizon.items() if r.status is S.AVAILABLE] == [5, 6, 7]
    assert {r.reason for o, r in horizon.items() if o not in (5, 6, 7)} == {Reason.NO_VALUE_FOR_DATE}
    # La misma campaña anunciada DESPUÉS del corte no existía para ese corte.
    add_calendar_event(conn, world.tenant_id, "T11_TARDE", d(5), d(7), local(d(2), 11))
    add_definition(conn, world.tenant_id, "t11_campana_tarde", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": "T11_TARDE"})
    late_spec = spec_for(repo, "t11_campana_tarde")
    late_obs = repo.observations_for(repo.resolve_definition("t11_campana_tarde"))
    late = validator().evaluate(late_spec, late_obs, C, target_date=d(6))
    assert late.status is S.NOT_YET_AVAILABLE and late.reason == Reason.KNOWN_AFTER_CUTOFF
    assert validator().evaluate(late_spec, late_obs, d(2), target_date=d(6)).status is S.AVAILABLE
    # Una revisión posterior que la cancela solo se ve desde que se conoce.
    add_calendar_event(conn, world.tenant_id, "T11_CYBER", d(5), d(7), local(d(4), 9), revision=1, status="cancelled")
    cancelled = repo.observations_for(repo.resolve_definition("t11_campana"))
    assert validator().evaluate(spec, cancelled, d(3), target_date=d(6)).value == 1.0
    assert validator().evaluate(spec, cancelled, d(4), target_date=d(6)).value == 0.0
    # known_at desconocido: en una reconstrucción retroactiva no se puede demostrar; en una vigente se acepta.
    add_calendar_event(conn, world.tenant_id, "T11_SIN_FECHA", d(5), d(7), None)
    add_definition(conn, world.tenant_id, "t11_campana_sin_fecha", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": "T11_SIN_FECHA"})
    unknown_spec = spec_for(repo, "t11_campana_sin_fecha")
    unknown = repo.observations_for(repo.resolve_definition("t11_campana_sin_fecha"))
    assert validator().evaluate(unknown_spec, unknown, d(3), target_date=d(6)).reason == Reason.UNKNOWN_AVAILABILITY
    assert validator(retroactive=False).evaluate(unknown_spec, unknown, d(3), target_date=d(6)).status is S.AVAILABLE


def test_t11_user_scenario_is_scenario_only_and_excluded_from_accuracy(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_supuesto_dolar")
    add_definition(conn, world.tenant_id, "t11_escenario", "USER_SCENARIO", "external_indicator_observations", indicator_id=ind,
                   family="trade_regulatory")
    add_obs(conn, ind, d(3), 1.25, local(d(-30), 9))                                   # supuesto «dólar a 1,25»
    repo = FactorsRepository(conn, world.tenant_id)
    spec = spec_for(repo, "t11_escenario")
    scenario = validator().evaluate(spec, repo.indicator_observations(ind), C, target_date=d(3))
    assert scenario.status is S.SCENARIO_ONLY and scenario.reason == Reason.USER_SCENARIO
    assert not scenario.usable_as_feature and not scenario.eligible_for_accuracy
    real = validator().evaluate(FactorSpec("real", AC.OBSERVED_HISTORICAL, 30), pd.DataFrame(
        [{"id": 1, "period_date": d(-1), "value": 5.0, "published_at": local(d(-1), 9)}]), C)
    assert [r.factor_key for r in filter_for_accuracy([scenario, real])] == ["real"]


def test_t11_forecast_source_without_issuance_history_is_invalid_and_its_backtest_forbidden(conn, world):
    ind = add_indicator(conn, world.tenant_id, "t11_pronostico_sin_historial")
    add_definition(conn, world.tenant_id, "t11_sin_historial", "THIRD_PARTY_FORECAST", "external_indicator_observations",
                   indicator_id=ind, family="weather", max_stale=5)
    for n in range(1, 4):
        add_obs(conn, ind, d(n), 20.0 + n, local(d(-1), 6), revision=0)               # sin issued_at: no se archivaron las emisiones
    repo = FactorsRepository(conn, world.tenant_id)
    spec, obs = spec_for(repo, "t11_sin_historial"), repo.indicator_observations(ind)
    r = validator(retroactive=True).evaluate(spec, obs, C, target_date=d(2))
    assert r.status is S.INVALID and r.reason == Reason.NO_ISSUANCE_HISTORY
    with pytest.raises(ForecastBacktestForbidden):
        validator(retroactive=True).assert_backtest_allowed(spec, obs)
    assert validator(retroactive=False).evaluate(spec, obs, C, target_date=d(2)).status is S.AVAILABLE   # ejecución vigente


# ───────────────────────── indicadores públicos y privados con el mismo código (R-178) ─────────────────────────

def test_public_and_private_indicators_with_the_same_code_are_never_combined(conn, world, other_world):
    public = add_indicator(conn, None, "t11_ipc_compartido", frequency="monthly")
    private = add_indicator(conn, world.tenant_id, "t11_ipc_compartido", frequency="monthly")
    other_private = add_indicator(conn, other_world.tenant_id, "t11_ipc_compartido", frequency="monthly")
    # Publicaciones INTERCALADAS: el público 100, 102, 104; el privado 500, 502 en fechas entre medias.
    for i, value in enumerate((100.0, 102.0, 104.0)):
        add_obs(conn, public, date(2026, 6 + i, 1), value, local(date(2026, 7 + i, 8), 9))
    for i, value in enumerate((500.0, 502.0)):
        add_obs(conn, private, date(2026, 6 + i, 1), value, local(date(2026, 7 + i, 12), 9))
    add_obs(conn, other_private, date(2026, 6, 1), 9999.0, local(date(2026, 7, 1), 9))
    repo = FactorsRepository(conn, world.tenant_id)
    # Sin precedencia declarada, el código ambiguo falla: no se elige en silencio.
    with pytest.raises(AmbiguousIndicatorError) as ambiguous:
        repo.resolve_indicator(code="t11_ipc_compartido")
    assert {c["id"] for c in ambiguous.value.candidates} == {public, private}
    mine = repo.resolve_indicator(code="t11_ipc_compartido", precedence="tenant")
    theirs = repo.resolve_indicator(code="t11_ipc_compartido", precedence="public")
    assert (mine["id"], theirs["id"]) == (private, public)
    assert sorted(repo.indicator_observations(mine["id"])["value"]) == [500.0, 502.0]
    assert sorted(repo.indicator_observations(theirs["id"])["value"]) == [100.0, 102.0, 104.0]
    # Por identificador: el indicador de OTRA empresa no existe para esta.
    with pytest.raises(LookupError):
        repo.resolve_indicator(indicator_id=other_private)
    assert repo.indicator_observations(other_private).empty
    # El lector heredado ya no mezcla: exige la precedencia y devuelve una sola serie.
    with pytest.raises(AmbiguousIndicatorError):
        load_observations(conn, world.tenant_id, "t11_ipc_compartido")
    series = load_observations(conn, world.tenant_id, "t11_ipc_compartido", precedence="tenant")
    assert set(series["indicator_id"]) == {private} and sorted(series["value"]) == [500.0, 502.0]
    # La otra empresa ve el público y el suyo, nunca el de la primera.
    other_repo = FactorsRepository(conn, other_world.tenant_id)
    assert {c["id"] for c in other_repo.indicator_candidates("t11_ipc_compartido")} == {public, other_private}
    assert other_repo.resolve_indicator(code="t11_ipc_compartido", precedence="public")["id"] == public
    # Un código con un solo candidato no necesita precedencia.
    solo = add_indicator(conn, world.tenant_id, "t11_solo_mio")
    assert repo.resolve_indicator(code="t11_solo_mio")["id"] == solo
    assert load_observations(conn, world.tenant_id, "t11_no_existe").empty


def test_inactive_indicators_and_foreign_definitions_are_not_visible(conn, world, other_world):
    off = add_indicator(conn, world.tenant_id, "t11_apagado", active=False)
    repo = FactorsRepository(conn, world.tenant_id)
    with pytest.raises(LookupError):
        repo.resolve_indicator(indicator_id=off)
    with pytest.raises(LookupError):
        repo.resolve_indicator(code="t11_apagado")
    add_definition(conn, other_world.tenant_id, "t11_definicion_ajena", "OBSERVED_HISTORICAL", "external_indicator_observations")
    assert repo.factor_definitions(["t11_definicion_ajena"]).empty
    with pytest.raises(LookupError):
        repo.resolve_definition("t11_definicion_ajena")
    add_event(conn, other_world.tenant_id, published=local(d(-1), 9), title="Evento ajeno")
    assert "Evento ajeno" not in set(repo.events()["title"])
    with pytest.raises(TenantScopeError):
        repo._read("SELECT 1")                                    # toda consulta debe filtrar por :tenant_id
    for bad in (0, -1, None, True):
        with pytest.raises(TenantScopeError):
            FactorsRepository(conn, bad)  # type: ignore[arg-type]


def test_timezone_aware_instants_from_postgres_are_read_and_judged_in_the_company_timezone(conn, world):
    """R-031 / R-170: `timestamptz` llega con zona; la frontera es el fin del día LOCAL de la empresa (Guayaquil, UTC-5)."""
    ind = add_indicator(conn, world.tenant_id, "t11_tz")
    add_definition(conn, world.tenant_id, "t11_tz_factor", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=30)
    # 23:30 locales del día C-1 = 04:30 UTC del día C.
    add_obs(conn, ind, d(-3), 1.0, local(d(-1), 23, 30))
    # 23:30 locales del día C = 04:30 UTC del día C+1.
    add_obs(conn, ind, d(-2), 2.0, local(C, 23, 30), revision=0)
    repo = FactorsRepository(conn, world.tenant_id)
    obs = repo.indicator_observations(ind)
    assert str(obs["published_at"].iloc[0].tzinfo) not in ("None", "")                # llega con zona
    spec = spec_for(repo, "t11_tz_factor")
    at_c = validator().evaluate(spec, obs, C)
    assert at_c.status is S.AVAILABLE and at_c.value == 2.0                           # 23:30 locales de C aún es el día C
    assert validator().evaluate(spec, obs, d(-1)).value == 1.0
    assert validator().evaluate(spec, obs, d(-2)).status is S.NOT_YET_AVAILABLE
    # Con la frontera en UTC (otra zona) cada dato se vería un día DESPUÉS (04:30 UTC ya es el día siguiente): decide la zona de la empresa.
    assert FeatureAvailabilityValidator("UTC").evaluate(spec, obs, C).value == 1.0
    assert FeatureAvailabilityValidator("UTC").evaluate(spec, obs, d(-1)).status is S.NOT_YET_AVAILABLE
    # El lector heredado, alimentado con instantes con zona, ya no falla.
    legacy = load_observations(conn, world.tenant_id, "t11_tz")
    assert as_of_value(legacy, pd.Timestamp(C) + pd.Timedelta(days=2)) == 2.0
    assert as_of_series(legacy, pd.date_range(d(-3), d(3))).dropna().iloc[-1] == 2.0


# ───────────────────────── pipeline desactivable ─────────────────────────

@pytest.fixture(scope="module")
def panel_bundle(small_data):
    panel = build_panel(small_data)
    horizon_end = (panel.cutoff + pd.Timedelta(days=30)).date()
    ids = list(panel.df["unique_id"].unique())
    fb = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, horizon_end)
    train, ref = fb.training_frame(panel.cutoff, ids)
    return {"panel": panel, "data": small_data, "horizon_end": horizon_end, "ids": ids, "train": train, "ref": ref,
            "future": fb.future_frame(panel.cutoff, ids, 30, ref), "dates": pd.date_range(panel.df["ds"].min(), horizon_end),
            "cutoff": panel.cutoff.date()}


def _builder(bundle, resolution: FactorResolution | None) -> FeatureBuilder:
    return FeatureBuilder(bundle["panel"].df, bundle["data"].branches, bundle["data"].promotions, bundle["horizon_end"], factors=resolution)


def _frames(bundle, resolution):
    fb = _builder(bundle, resolution)
    train, ref = fb.training_frame(bundle["panel"].cutoff, bundle["ids"])
    return train, fb.future_frame(bundle["panel"].cutoff, bundle["ids"], 30, ref)


def _settings(active_factors=(), **factors) -> dict:
    """`tenants.settings`: bloque `factors` (interruptor y parámetros) y activación por factor (`factor_activation`, backend W2-F)."""
    settings: dict = {"cores": {"factors": factors}} if factors else {}
    if active_factors:
        settings["factor_activation"] = {key: {"active": True, "updated_by": 1} for key in active_factors}
    return settings


def _seed_factors(conn, tenant_id, cutoff: date) -> None:
    """Un factor sano y uno de cada tipo problemático, TODOS definidos y con datos (solo el interruptor decide)."""
    def day(n):
        return cutoff + timedelta(days=n)

    fresh = add_indicator(conn, tenant_id, "t11_p_sano", frequency="monthly")
    for back in range(0, 14):                         # mensual, publicado el día 8 del mes siguiente
        period = (pd.Timestamp(cutoff).replace(day=1) - pd.DateOffset(months=back + 1)).date()
        add_obs(conn, fresh, period, 100.0 + (13 - back), local((pd.Timestamp(period) + pd.DateOffset(months=1)).date().replace(day=8), 9))
    add_definition(conn, tenant_id, "t11_sano", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=fresh,
                   frequency="monthly", max_stale=200, family="trade_regulatory")

    down = add_indicator(conn, tenant_id, "t11_p_caido")
    for back in range(60, 19, -1):
        add_obs(conn, down, day(-back), float(back), local(day(-back), 22))
    add_definition(conn, tenant_id, "t11_caido", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=down, max_stale=7)
    add_definition(conn, tenant_id, "t11_caido_etiquetado", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=down,
                   max_stale=7, missing="last_valid_with_age")

    scenario = add_indicator(conn, tenant_id, "t11_p_supuesto")
    add_obs(conn, scenario, day(3), 1.25, local(day(-30), 9))
    add_definition(conn, tenant_id, "t11_supuesto", "USER_SCENARIO", "external_indicator_observations", indicator_id=scenario)

    forecast = add_indicator(conn, tenant_id, "t11_p_pronostico")
    for n in range(-60, 31):
        add_obs(conn, forecast, day(n), 20.0, local(day(-61), 6))                    # sin issued_at
    add_definition(conn, tenant_id, "t11_pronostico", "THIRD_PARTY_FORECAST", "external_indicator_observations", indicator_id=forecast,
                   family="weather", max_stale=5)


def test_with_the_pipeline_switched_off_no_external_factor_enters_and_nothing_is_queried(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    _seed_factors(conn, world.tenant_id, cutoff)
    keys = ["t11_sano", "t11_caido", "t11_supuesto", "t11_pronostico"]
    queries: list[str] = []
    listener = lambda c, cur, statement, params, ctx, many: queries.append(statement)   # noqa: E731
    event.listen(conn, "before_cursor_execute", listener)
    try:
        for settings in ({}, _settings(active_factors=keys), _settings(enabled=False, active_factors=keys)):
            resolution = FactorPipeline.from_settings(settings).resolve(
                TenantRepository(conn, world.tenant_id).factors(), panel_bundle["dates"], cutoff, timezone=GYE)
            assert (resolution.enabled, resolution.reason, resolution.feature_columns, resolution.used) == (False, "disabled", [], [])
    finally:
        event.remove(conn, "before_cursor_execute", listener)
    assert queries == [], "con el pipeline apagado no se consulta ninguna tabla de factores"
    train, future = _frames(panel_bundle, resolution)
    pd.testing.assert_frame_equal(train, panel_bundle["train"])
    pd.testing.assert_frame_equal(future, panel_bundle["future"])
    assert not [c for c in train.columns if c.startswith("factor__")]


def test_with_the_pipeline_on_only_usable_factors_enter_and_the_rest_fall_back_to_the_model_without_them(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    _seed_factors(conn, world.tenant_id, cutoff)
    settings = _settings(enabled=True, active_factors=["t11_sano", "t11_caido", "t11_caido_etiquetado", "t11_supuesto", "t11_pronostico",
                                                       "t11_no_existe"])
    resolution = FactorPipeline.from_settings(settings).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE, retroactive=True)
    report = resolution.report()
    assert resolution.enabled and set(resolution.used) == {"t11_sano", "t11_caido_etiquetado"}
    assert resolution.scenario_only == ["t11_supuesto"]
    dropped = {x["key"]: x for x in resolution.dropped}
    assert set(dropped) == {"t11_caido", "t11_pronostico", "t11_no_existe"}
    assert dropped["t11_caido"]["status"] == "STALE" and dropped["t11_caido"]["fallback"] == "disable_feature"
    assert dropped["t11_caido"]["age_days"] == 20
    assert dropped["t11_pronostico"]["status"] == "INVALID" and dropped["t11_pronostico"]["reason"] == Reason.NO_ISSUANCE_HISTORY
    assert dropped["t11_no_existe"]["reason"] == "definition_not_found"
    assert report["factors"]["t11_caido_etiquetado"]["status"] == "STALE" and report["factors"]["t11_caido_etiquetado"]["age_days"] == 20
    assert report["factors"]["t11_supuesto"]["status"] == "SCENARIO_ONLY"
    # El escenario NUNCA llega al marco; los factores descartados tampoco; los usables sí, con su contrato.
    train, future = _frames(panel_bundle, resolution)
    extra = [c for c in train.columns if c.startswith("factor__")]
    assert extra == ["factor__t11_caido_etiquetado", "factor__t11_sano"] and [c for c in future.columns if c.startswith("factor__")] == extra
    assert not any("supuesto" in c or "pronostico" in c or c == "factor__t11_caido" for c in train.columns)
    pd.testing.assert_frame_equal(train.drop(columns=extra), panel_bundle["train"])
    pd.testing.assert_frame_equal(future.drop(columns=extra), panel_bundle["future"])
    assert future["factor__t11_sano"].notna().all() and set(resolution.contracts) == set(extra)
    assert resolution.contracts["factor__t11_sano"].availability_class is AC.OBSERVED_HISTORICAL


def test_a_factor_that_is_stale_at_the_cutoff_leaves_the_frames_identical_to_the_model_without_it(conn, world, panel_bundle):
    """Respaldo sin el factor: con la API caída (STALE) el resultado es exactamente el del modelo base."""
    cutoff = panel_bundle["cutoff"]
    _seed_factors(conn, world.tenant_id, cutoff)
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_caido"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert resolution.used == [] and resolution.dropped[0]["reason"] == Reason.STALE and resolution.feature_columns == []
    train, future = _frames(panel_bundle, resolution)
    pd.testing.assert_frame_equal(train, panel_bundle["train"])
    pd.testing.assert_frame_equal(future, panel_bundle["future"])


def test_the_training_rows_of_a_factor_use_what_was_known_each_day_not_the_latest_revision(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    ind = add_indicator(conn, world.tenant_id, "t11_p_rev")
    for back in range(120, 4, -1):                                       # un dato por día, publicado a la mañana siguiente
        period = cutoff - timedelta(days=back)
        add_obs(conn, ind, period, 10.0, local(period + timedelta(days=1), 8))
    # El último período se REVISA el día cutoff-1 por la tarde: el entrenamiento ve la revisión solo desde ese día.
    add_obs(conn, ind, cutoff - timedelta(days=5), 999.0, local(cutoff - timedelta(days=1), 17), revision=1)
    add_definition(conn, world.tenant_id, "t11_rev", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind, max_stale=400)
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_rev"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    column = resolution.columns.set_index("ds")["factor__t11_rev"]
    at = lambda n: column.loc[pd.Timestamp(cutoff) + pd.Timedelta(days=n)]    # noqa: E731
    assert [at(-3), at(-2)] == [10.0, 10.0]                               # antes de publicarse la revisión
    assert [at(-1), at(0), at(5)] == [999.0, 999.0, 999.0]                # desde su publicación y arrastrada al futuro
    assert resolution.results["t11_rev"].revision == 1


def test_the_future_of_an_earlier_backtest_window_only_sees_what_was_published_before_its_cutoff(conn, world, panel_bundle):
    """Cada ventana del backtest usa lo conocido en SU corte: el futuro de la ventana no puede ver publicaciones posteriores."""
    final = panel_bundle["cutoff"]
    window = final - timedelta(days=20)
    ind = add_indicator(conn, world.tenant_id, "t11_p_ventana")
    for back in range(120, 0, -1):
        period = final - timedelta(days=back)
        add_obs(conn, ind, period, 1.0 if period <= window else 5.0, local(period, 20))       # el nivel salta DESPUÉS del corte de la ventana
    add_definition(conn, world.tenant_id, "t11_ventana", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=400)
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_ventana"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], final, timezone=GYE)
    assert resolution.used == ["t11_ventana"]
    fb = _builder(panel_bundle, resolution)
    ids = panel_bundle["ids"]
    train_f, ref_f = fb.training_frame(pd.Timestamp(final), ids)
    future_f = fb.future_frame(pd.Timestamp(final), ids, 5, ref_f)
    train_w, ref_w = fb.training_frame(pd.Timestamp(window), ids)
    future_w = fb.future_frame(pd.Timestamp(window), ids, 5, ref_w)
    column = "factor__t11_ventana"
    assert set(future_f[column]) == {5.0}                                  # corte final: ya se conoce el nivel nuevo
    assert set(future_w[column]) == {1.0}                                  # ventana anterior: el nivel nuevo aún no existía
    assert set(train_w[column].dropna()) == {1.0} and train_w["ds"].max() <= pd.Timestamp(window)
    # Y no cambia aunque haya publicaciones posteriores al corte de la ventana (misma ventana con y sin ellas).
    pd.testing.assert_frame_equal(future_w, _builder(panel_bundle, FactorPipeline.from_settings(
        _settings(enabled=True, active_factors=["t11_ventana"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], window, timezone=GYE)).future_frame(
        pd.Timestamp(window), ids, 5, ref_w))


def test_a_known_in_advance_campaign_enters_with_values_only_on_its_days(conn, world, panel_bundle):
    """Un día sin campaña no es una falla de la fuente: queda en NaN; la campaña conocida por anticipado entra para sus días futuros."""
    cutoff = panel_bundle["cutoff"]
    add_calendar_event(conn, world.tenant_id, "T11_PIPE_CAMPANA", cutoff + timedelta(days=5), cutoff + timedelta(days=7),
                       local(cutoff - timedelta(days=3), 11))
    add_calendar_event(conn, world.tenant_id, "T11_PIPE_TARDE", cutoff + timedelta(days=10), cutoff + timedelta(days=12),
                       local(cutoff + timedelta(days=2), 11))                       # anunciada después del corte
    for key, code in (("t11_pipe_campana", "T11_PIPE_CAMPANA"), ("t11_pipe_tarde", "T11_PIPE_TARDE")):
        add_definition(conn, world.tenant_id, key, "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events", schema={"event_code": code})
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_pipe_campana", "t11_pipe_tarde"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert resolution.used == ["t11_pipe_campana"] and [x["key"] for x in resolution.dropped] == ["t11_pipe_tarde"]
    assert resolution.dropped[0]["reason"] == Reason.KNOWN_AFTER_CUTOFF and resolution.dropped[0]["status"] == "NOT_YET_AVAILABLE"
    _, future = _frames(panel_bundle, resolution)
    by_day = future.groupby("ds")["factor__t11_pipe_campana"].first()
    campaign_days = [pd.Timestamp(cutoff) + pd.Timedelta(days=n) for n in (5, 6, 7)]
    assert by_day.loc[campaign_days].tolist() == [1.0, 1.0, 1.0]
    assert by_day.drop(campaign_days).isna().all()


def test_an_ambiguous_indicator_is_dropped_unless_the_precedence_is_declared(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    public = add_indicator(conn, None, "t11_amb", frequency="monthly")
    private = add_indicator(conn, world.tenant_id, "t11_amb", frequency="monthly")
    for ind, base in ((public, 100.0), (private, 500.0)):
        for back in range(1, 8):
            add_obs(conn, ind, (pd.Timestamp(cutoff).replace(day=1) - pd.DateOffset(months=back)).date(), base,
                    local((pd.Timestamp(cutoff).replace(day=1) - pd.DateOffset(months=back - 1)).date().replace(day=8), 9))
    add_definition(conn, world.tenant_id, "t11_amb_factor", "OBSERVED_HISTORICAL", "external_indicator_observations", frequency="monthly",
                   max_stale=400, schema={"indicator_code": "t11_amb"}, family="trade_regulatory")
    repo = FactorsRepository(conn, world.tenant_id)
    plain = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_amb_factor"])).resolve(
        repo, panel_bundle["dates"], cutoff, timezone=GYE)
    assert plain.used == [] and plain.dropped[0]["reason"] == "ambiguous_indicator"
    declared = FactorPipeline.from_settings(_settings(
        enabled=True, active_factors=["t11_amb_factor"], indicator_precedence={"t11_amb": "tenant"})).resolve(
        repo, panel_bundle["dates"], cutoff, timezone=GYE)
    assert declared.used == ["t11_amb_factor"]
    assert set(declared.columns["factor__t11_amb_factor"].dropna()) == {500.0}, "serie del indicador privado, sin mezclar el público"


# ───────────────────────── calendario: origen, alcance e identidad ─────────────────────────

def test_t11_public_and_private_calendar_events_with_the_same_code_are_never_combined(conn, world, other_world, panel_bundle):
    code = "T11_PUB_PRIV"
    # Público: campaña C+5…C+7 conocida desde C-3; una revisión pública posterior la CANCELA (conocida en C+1).
    add_calendar_event(conn, None, code, d(5), d(7), local(d(-3), 9))
    add_calendar_event(conn, None, code, d(5), d(7), local(d(1), 9), revision=1, status="cancelled")
    # De la empresa: la misma campaña ampliada hasta C+9, conocida desde C-1. Y la de OTRA empresa (nunca visible).
    add_calendar_event(conn, world.tenant_id, code, d(5), d(9), local(d(-1), 9))
    add_calendar_event(conn, other_world.tenant_id, code, d(5), d(30), local(d(-10), 9))
    repo = FactorsRepository(conn, world.tenant_id)
    with pytest.raises(AmbiguousCalendarError, match="precedencia"):
        repo.calendar_observations(code)
    own = repo.calendar_observations(code, precedence="tenant")
    public = repo.calendar_observations(code, precedence="public")
    assert set(own["origin"]) == {"tenant"} and own["valid_to"].tolist() == [d(9)] and len(own) == 1
    assert set(public["origin"]) == {"public"} and public["revision"].tolist() == [0, 1] and public["value"].tolist() == [1.0, 0.0]
    # Los valores salen de UN origen: con la precedencia de la empresa, la cancelación pública no existe; con la pública, no la ampliación.
    spec = FactorSpec("campana", AC.KNOWN_IN_ADVANCE)
    assert validator().evaluate(spec, own, d(2), target_date=d(8)).status is S.AVAILABLE
    assert validator().evaluate(spec, own, d(2), target_date=d(6)).value == 1.0                  # la cancelación pública no se mezcla
    assert validator().evaluate(spec, public, d(2), target_date=d(8)).reason == Reason.NO_VALUE_FOR_DATE
    assert validator().evaluate(spec, public, d(2), target_date=d(6)).value == 0.0               # cancelada, en su propio origen
    assert FactorsRepository(conn, other_world.tenant_id).calendar_observations(code, precedence="tenant")["valid_to"].tolist() == [d(30)]
    with pytest.raises(ValueError):
        repo.calendar_observations(code, precedence="ambos")
    # Con el pipeline: sin precedencia declarada el factor sale por ambigüedad; con `calendar_precedence` (o `definition_precedence`) entra.
    cutoff = panel_bundle["cutoff"]
    shifted = {"cores": {"factors": {"enabled": True}}, "factor_activation": {"t11_pub_priv": {"active": True}}}
    add_definition(conn, world.tenant_id, "t11_pub_priv", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": code})
    # (las fechas de la campaña son relativas a C; se recalculan relativas al corte del panel)
    conn.execute(text("UPDATE calendar_events SET starts_on = starts_on + :delta, ends_on = ends_on + :delta, "
                      "known_at = known_at + make_interval(days => :delta) WHERE code = :code"),
                 {"delta": (cutoff - C).days, "code": code})
    plain = FactorPipeline.from_settings(shifted).resolve(FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert plain.used == [] and plain.dropped[0]["reason"] == "ambiguous_calendar"
    by_calendar = {**shifted, "cores": {"factors": {"enabled": True, "calendar_precedence": {code: "tenant"}}}}
    resolved = FactorPipeline.from_settings(by_calendar).resolve(FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert resolved.used == ["t11_pub_priv"]
    by_definition = {**shifted, "cores": {"factors": {"enabled": True, "definition_precedence": {"t11_pub_priv": "tenant"}}}}
    again = FactorPipeline.from_settings(by_definition).resolve(FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert again.used == ["t11_pub_priv"]
    column = resolved.columns.set_index("ds")["factor__t11_pub_priv"]
    days = [pd.Timestamp(cutoff) + pd.Timedelta(days=n) for n in range(5, 10)]
    assert column.loc[days].tolist() == [1.0] * 5, "campaña de la empresa C+5…C+9, sin la cancelación pública"


def test_t11_calendar_events_with_an_unsupported_scope_are_discarded_with_a_reason(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    code = "T11_ALCANCES"
    known = local(cutoff - timedelta(days=3), 9)
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=5), cutoff + timedelta(days=7), known)          # country/EC
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=10), cutoff + timedelta(days=12), known, scope="branch", scope_ref="branch:1")
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=13), cutoff + timedelta(days=14), known, scope="branch", scope_ref="branch:2")
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=15), cutoff + timedelta(days=16), known, scope="product", scope_ref="product:1")
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=17), cutoff + timedelta(days=18), known, scope="category", scope_ref="category:1")
    add_calendar_event(conn, world.tenant_id, code, cutoff + timedelta(days=19), cutoff + timedelta(days=20), known, scope="region", scope_ref="costa")
    repo = FactorsRepository(conn, world.tenant_id)
    frame = repo.calendar_observations(code)
    assert set(frame["scope"]) == {"country"} and len(frame) == 1
    assert frame.attrs["discarded"] == [{"reason": "scope_unsupported", "scope": "branch", "rows": 2},
                                        {"reason": "scope_unsupported", "scope": "category", "rows": 1},
                                        {"reason": "scope_unsupported", "scope": "product", "rows": 1},
                                        {"reason": "scope_unsupported", "scope": "region", "rows": 1}]
    # Solo hay alcances no soportados: error explícito, no un factor global construido con eventos de una sucursal.
    add_calendar_event(conn, world.tenant_id, "T11_SOLO_SUCURSAL", cutoff + timedelta(days=5), cutoff + timedelta(days=7), known,
                       scope="branch", scope_ref="branch:1")
    with pytest.raises(ScopeUnsupportedError, match="scope_unsupported"):
        repo.calendar_observations("T11_SOLO_SUCURSAL")
    # Pipeline: el descarte queda en el informe y un factor sin alcance soportado sale con el motivo scope_unsupported.
    for key, event_code in (("t11_alcances", code), ("t11_solo_sucursal", "T11_SOLO_SUCURSAL")):
        add_definition(conn, world.tenant_id, key, "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                       schema={"event_code": event_code})
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_alcances", "t11_solo_sucursal"])).resolve(
        repo, panel_bundle["dates"], cutoff, timezone=GYE)
    assert resolution.used == ["t11_alcances"]
    assert [(x["key"], x["reason"]) for x in resolution.dropped] == [("t11_solo_sucursal", "scope_unsupported")]
    assert {(x["key"], x["scope"], x["rows"]) for x in resolution.discarded} == {
        ("t11_alcances", "branch", 2), ("t11_alcances", "category", 1), ("t11_alcances", "product", 1), ("t11_alcances", "region", 1)}
    assert resolution.report()["discarded"] == resolution.discarded
    column = resolution.columns.set_index("ds")["factor__t11_alcances"]
    assert column.dropna().index.tolist() == [pd.Timestamp(cutoff) + pd.Timedelta(days=n) for n in (5, 6, 7)]     # solo la campaña country


def test_t11_calendar_events_accept_country_tenant_and_sector_but_never_mix_their_identities(conn, world):
    code = "T11_IDENTIDADES"
    known = local(d(-3), 9)
    add_calendar_event(conn, world.tenant_id, code, d(5), d(7), known, scope="country", scope_ref="EC")
    add_calendar_event(conn, world.tenant_id, code, d(5), d(9), known, scope="sector", scope_ref="ferreteria")
    repo = FactorsRepository(conn, world.tenant_id)
    with pytest.raises(AmbiguousCalendarError, match="alcances"):
        repo.calendar_observations(code)
    country = repo.calendar_observations(code, scope="country")
    sector = repo.calendar_observations(code, scope="sector", scope_ref="ferreteria")
    assert country["valid_to"].tolist() == [d(7)] and sector["valid_to"].tolist() == [d(9)]
    add_calendar_event(conn, world.tenant_id, "T11_TENANT", d(5), d(7), known, scope="tenant", scope_ref=None)
    assert len(repo.calendar_observations("T11_TENANT")) == 1


# ───────────────────────── pipeline: cortes anteriores, antigüedad, definiciones ─────────────────────────

def test_the_windows_before_the_final_cutoff_are_always_retroactive_even_when_the_final_run_is_live(conn, world, panel_bundle):
    """Solo el corte final puede aceptar lo de disponibilidad desconocida: un corte anterior es una reconstrucción retroactiva."""
    final = panel_bundle["cutoff"]
    window = final - timedelta(days=20)
    # (1) campaña con `known_at` desconocido  (2) pronóstico de tercero SIN historial de emisiones.
    add_calendar_event(conn, world.tenant_id, "T11_VIGENTE", final + timedelta(days=5), final + timedelta(days=7), None)
    add_definition(conn, world.tenant_id, "t11_vigente", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": "T11_VIGENTE"})
    forecast = add_indicator(conn, world.tenant_id, "t11_p_pron_vigente")
    for n in range(0, 31):
        add_obs(conn, forecast, final + timedelta(days=n), 20.0 + n, local(final - timedelta(days=10), 6))     # sin issued_at
    add_definition(conn, world.tenant_id, "t11_pron_vigente", "THIRD_PARTY_FORECAST", "external_indicator_observations",
                   indicator_id=forecast, family="weather", max_stale=400)
    settings = _settings(enabled=True, active_factors=["t11_vigente", "t11_pron_vigente"])
    repo = FactorsRepository(conn, world.tenant_id)
    # Retroactiva (por defecto): ninguno de los dos puede demostrarse conocido.
    retro = FactorPipeline.from_settings(settings).resolve(repo, panel_bundle["dates"], final, timezone=GYE)
    assert retro.used == [] and {x["key"]: x["reason"] for x in retro.dropped} == {
        "t11_vigente": Reason.UNKNOWN_AVAILABILITY, "t11_pron_vigente": Reason.NO_ISSUANCE_HISTORY}
    # Vigente: el corte final los acepta (información actual de la empresa)…
    live = FactorPipeline.from_settings(settings).resolve(repo, panel_bundle["dates"], final, timezone=GYE, retroactive=False)
    assert live.used == ["t11_pron_vigente", "t11_vigente"]            # orden alfabético
    at_final = live.columns_at(final).set_index("ds")
    campaign = [pd.Timestamp(final) + pd.Timedelta(days=n) for n in (5, 6, 7)]
    assert at_final.loc[campaign, "factor__t11_vigente"].tolist() == [1.0, 1.0, 1.0]
    assert at_final.loc[pd.Timestamp(final) + pd.Timedelta(days=3), "factor__t11_pron_vigente"] == 23.0
    # …pero toda ventana ANTERIOR es retroactiva: esos factores quedan sin valor (NaN), no «visibles» por haber sido vigente el final.
    earlier = live.columns_at(window)
    assert earlier[["factor__t11_vigente", "factor__t11_pron_vigente"]].isna().all().all()
    fb = _builder(panel_bundle, live)
    ids = panel_bundle["ids"]
    _, ref = fb.training_frame(pd.Timestamp(window), ids)
    assert fb.future_frame(pd.Timestamp(window), ids, 10, ref)[["factor__t11_vigente", "factor__t11_pron_vigente"]].isna().all().all()
    _, ref_f = fb.training_frame(pd.Timestamp(final), ids)
    assert fb.future_frame(pd.Timestamp(final), ids, 10, ref_f)["factor__t11_vigente"].notna().any()
    # Un corte posterior al final no existe para esta resolución.
    with pytest.raises(ValueError, match="posterior al corte final"):
        live.columns_at(final + timedelta(days=1))
    # Una resolución retroactiva usa el mismo validador para el corte final y los anteriores.
    assert retro.columns_at(final).shape == retro.columns.shape


def test_staleness_and_horizon_interact_the_carried_value_ages_until_it_turns_stale(conn, world, panel_bundle):
    """Antigüedad × horizonte: el valor visible al corte se arrastra y envejece día a día; el límite debe cubrir antigüedad + horizonte."""
    cutoff = panel_bundle["cutoff"]
    ind = add_indicator(conn, world.tenant_id, "t11_p_edad")
    for back in range(90, 4, -1):                                       # el último dato es de hace 5 días (antigüedad 5 al corte)
        period = cutoff - timedelta(days=back)
        add_obs(conn, ind, period, 3.0, local(period, 20))
    add_definition(conn, world.tenant_id, "t11_edad", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind, max_stale=10)
    add_definition(conn, world.tenant_id, "t11_edad_etiquetada", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=10, missing="last_valid_with_age")
    add_definition(conn, world.tenant_id, "t11_edad_holgada", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=40)
    settings = _settings(enabled=True, active_factors=["t11_edad", "t11_edad_etiquetada", "t11_edad_holgada"])
    resolution = FactorPipeline.from_settings(settings).resolve(FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert set(resolution.used) == {"t11_edad_etiquetada", "t11_edad_holgada"}
    dropped = resolution.dropped[0]
    assert dropped["key"] == "t11_edad" and dropped["reason"] == Reason.STALE and dropped["fallback"] == "disable_feature"
    assert dropped["age_days"] == 5                                     # antigüedad AL CORTE; el día +6 llega a 11 > 10
    assert dropped["first_unusable_date"] == (cutoff + timedelta(days=6)).isoformat() and dropped["unusable_days"] == 25   # días +6 … +30
    assert resolution.results["t11_edad_etiquetada"].age_days == 5 and resolution.results["t11_edad_etiquetada"].status is S.AVAILABLE


def test_a_factor_whose_column_has_no_values_is_dropped_not_reported_as_used(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    add_calendar_event(conn, world.tenant_id, "T11_ANTIGUA", date(2020, 3, 1), date(2020, 3, 3), local(date(2020, 2, 1), 9))
    add_definition(conn, world.tenant_id, "t11_sin_valores", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events",
                   schema={"event_code": "T11_ANTIGUA"})
    resolution = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_sin_valores"])).resolve(
        FactorsRepository(conn, world.tenant_id), panel_bundle["dates"], cutoff, timezone=GYE)
    assert resolution.used == [] and resolution.feature_columns == []
    assert [(x["key"], x["reason"]) for x in resolution.dropped] == [("t11_sin_valores", "no_values")]
    train, future = _frames(panel_bundle, resolution)
    pd.testing.assert_frame_equal(train, panel_bundle["train"])
    pd.testing.assert_frame_equal(future, panel_bundle["future"])


def test_definitions_ambiguity_precedence_and_unreadable_storage_are_reported_not_silently_resolved(conn, world, panel_bundle):
    cutoff = panel_bundle["cutoff"]
    # Mismo factor en el catálogo público y propio de la empresa: cada uno apunta a su indicador.
    pub_ind = add_indicator(conn, None, "t11_def_pub", frequency="monthly")
    own_ind = add_indicator(conn, world.tenant_id, "t11_def_own", frequency="monthly")
    for ind, value in ((pub_ind, 100.0), (own_ind, 500.0)):
        for back in range(1, 8):
            first = (pd.Timestamp(cutoff).replace(day=1) - pd.DateOffset(months=back)).date()
            add_obs(conn, ind, first, value, local((pd.Timestamp(first) + pd.DateOffset(months=1)).date().replace(day=8), 9))
    add_definition(conn, None, "t11_def_clave", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=pub_ind,
                   frequency="monthly", max_stale=400, family="trade_regulatory")
    add_definition(conn, world.tenant_id, "t11_def_clave", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=own_ind,
                   frequency="monthly", max_stale=400, family="trade_regulatory")
    # Almacenamientos sin lectura en el pipeline y definiciones sin apuntar a datos.
    add_definition(conn, world.tenant_id, "t11_def_ventas", "OBSERVED_HISTORICAL", "sales", family="sales_history")
    add_definition(conn, world.tenant_id, "t11_def_sin_indicador", "OBSERVED_HISTORICAL", "external_indicator_observations")
    add_definition(conn, world.tenant_id, "t11_def_sin_evento", "KNOWN_IN_ADVANCE", "calendar_events", family="calendar_events")
    repo = FactorsRepository(conn, world.tenant_id)
    keys = ["t11_def_clave", "t11_def_ventas", "t11_def_sin_indicador", "t11_def_sin_evento"]
    plain = FactorPipeline.from_settings(_settings(enabled=True, active_factors=keys)).resolve(repo, panel_bundle["dates"], cutoff, timezone=GYE)
    reasons = {x["key"]: x["reason"] for x in plain.dropped}
    assert plain.used == [] and reasons == {"t11_def_clave": "ambiguous_definition", "t11_def_ventas": "storage_unreadable",
                                            "t11_def_sin_indicador": "storage_unreadable", "t11_def_sin_evento": "storage_unreadable"}
    assert "sales" in next(x for x in plain.dropped if x["key"] == "t11_def_ventas")["message"]
    assert all(x["fallback"] == "disable_feature" for x in plain.dropped if x["reason"] == "storage_unreadable")
    for precedence, expected in (("tenant", {500.0}), ("public", {100.0})):
        declared = FactorPipeline.from_settings(_settings(
            enabled=True, active_factors=["t11_def_clave"], definition_precedence={"t11_def_clave": precedence})).resolve(
            repo, panel_bundle["dates"], cutoff, timezone=GYE)
        assert declared.used == ["t11_def_clave"], precedence
        assert set(declared.columns["factor__t11_def_clave"].dropna()) == expected, "una sola definición, nunca mezcladas"
    assert repo.resolve_definition("t11_def_clave", "tenant")["tenant_id"] == world.tenant_id
    assert repo.resolve_definition("t11_def_clave", "public")["tenant_id"] is None


def test_the_pipeline_follows_the_company_activation_and_not_the_catalog_flag_is_active(conn, world, panel_bundle):
    """`factor_definitions.is_active` es el «ninguna familia activa por defecto» del catálogo: la elección de la empresa es `factor_activation`."""
    cutoff = panel_bundle["cutoff"]
    ind = add_indicator(conn, world.tenant_id, "t11_p_activo")
    for back in range(60, 0, -1):
        period = cutoff - timedelta(days=back)
        add_obs(conn, ind, period, 1.0, local(period, 20))
    add_definition(conn, None, "t11_cat_inactiva", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=None,
                   active=False)            # catálogo público, is_active = false (valor por defecto)
    conn.execute(text("UPDATE factor_definitions SET external_indicator_id = :i WHERE key = 't11_cat_inactiva'"), {"i": ind})
    add_definition(conn, world.tenant_id, "t11_cat_activa", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind,
                   max_stale=400, active=True)
    repo = FactorsRepository(conn, world.tenant_id)
    conn.execute(text("UPDATE factor_definitions SET max_staleness_days = 400 WHERE key = 't11_cat_inactiva'"))
    # Activada por la empresa aunque su definición pública diga is_active = false: entra.
    on = FactorPipeline.from_settings(_settings(enabled=True, active_factors=["t11_cat_inactiva"])).resolve(
        repo, panel_bundle["dates"], cutoff, timezone=GYE)
    assert on.used == ["t11_cat_inactiva"]
    # is_active = true en la definición pero SIN activación de la empresa: no entra.
    off = FactorPipeline.from_settings(_settings(enabled=True, active_factors=[])).resolve(repo, panel_bundle["dates"], cutoff, timezone=GYE)
    assert off.used == [] and off.reason == "no_active_factors"
    only_flag = {"cores": {"factors": {"enabled": True}}, "factor_activation": {"t11_cat_activa": {"active": False}}}
    assert FactorPipeline.from_settings(only_flag).resolve(repo, panel_bundle["dates"], cutoff, timezone=GYE).used == []


# ───────────────────────── contrato de metadatos temporales (V25-FAC-069) ─────────────────────────

def test_temporal_metadata_columns_and_legacy_adapters_are_pinned(conn):
    """Eliminar una columna de metadatos temporales o un adaptador existente hace fallar esta prueba."""
    def columns(table):
        rows = conn.execute(text("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() "
                                 "AND table_name = :t"), {"t": table}).scalars()
        return set(rows)

    assert {"period_date", "published_at", "ingested_at", "revision", "observed_at", "available_at", "valid_from", "valid_to",
            "issued_at", "forecast_horizon_days", "source_version_id", "scope_ref", "quality_flags"} <= columns("external_indicator_observations")
    assert {"published_at", "available_at", "effective_from", "effective_to", "status", "verified_at", "created_at", "corroboration",
            "is_informative_alert", "extraction"} <= columns("external_events")
    assert {"availability_class", "storage", "missing_strategy", "max_staleness_days", "external_indicator_id"} <= columns("factor_definitions")
    assert {"known_at", "valid_from", "valid_to"} <= columns("product_price_history") and "known_at" in columns("calendar_events")
    # Todas las columnas que el repositorio pide existen (si no, la lectura fallaría en producción).
    from predictily_engine.db import factors_repository as fr
    assert set(fr.OBSERVATION_COLUMNS) <= columns("external_indicator_observations")
    assert set(fr.EVENT_COLUMNS) <= columns("external_events") and set(fr.DEFINITION_COLUMNS) <= columns("factor_definitions")
    assert set(fr.PRICE_COLUMNS) <= columns("product_price_history")
    import predictily_engine.features.external as legacy
    assert all(callable(getattr(legacy, name)) for name in ("as_of_value", "as_of_series", "load_observations"))


def test_the_price_history_of_the_company_is_read_with_its_known_at(conn, world):
    pid = world.product_ids[0]
    conn.execute(text(
        "INSERT INTO product_price_history (tenant_id, product_id, price, valid_from, valid_to, source, known_at, created_at) VALUES "
        "(:t, :p, 10, :v1, NULL, 'manual', :k1, now()), (:t, :p, 12, :v2, NULL, 'manual', NULL, now())"),
        {"t": world.tenant_id, "p": pid, "v1": d(-100), "v2": d(5), "k1": local(d(-100), 9)})
    history = FactorsRepository(conn, world.tenant_id).price_history([pid])
    assert history["price"].tolist() == [10.0, 12.0] and history["known_at"].notna().tolist() == [True, False]
    assert FactorsRepository(conn, world.tenant_id).price_history([pid + 999]).empty
    # Entra al builder tal cual: el known_at nulo no demuestra que el precio futuro se conociera (no se usa).
    assert history.columns.tolist() == ["id", "product_id", "branch_id", "price", "currency", "valid_from", "valid_to", "source", "known_at"]
    assert np.isclose(history["price"].sum(), 22.0)
