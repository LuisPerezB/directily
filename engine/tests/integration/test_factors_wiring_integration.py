"""W6-E · el pipeline de factores conectado, contra PostgreSQL (ADR-V25-16 y ADR-V25-25). Todo FICTICIO.

- Lectura: apagado (por defecto) no se consulta ninguna tabla de factores; encendido, `factors.enabled` Y `factor_activation.<clave>.active` cargan los
  insumos (`load_factor_inputs`); lo publicado después del corte no entra; el indicador público y el privado con el mismo código nunca se mezclan; una
  empresa jamás lee los factores de otra.
- Ablation en modo base de datos: sesión de solo lectura, nunca una base que no sea de pruebas.
- De punta a punta (`execute_forecast_run`): una empresa con factores activos que no ganan la competencia sirve EXACTAMENTE lo mismo que su gemela sin
  factores, y la ejecución registra qué pasó con cada factor.
"""

from __future__ import annotations

import json
from contextlib import contextmanager
from datetime import date, datetime, timedelta, timezone

import numpy as np
import pandas as pd
import pytest
from asof_helpers import make_world
from sqlalchemy import event, text
from sqlalchemy.exc import DBAPIError

from predictily_engine.availability.pipeline import FactorPipeline
from predictily_engine.backtest import factor_ablation
from predictily_engine.db import safety
from predictily_engine.db.repository import TenantRepository
from predictily_engine.pipeline.data import FactorReadError, load_factor_inputs, load_tenant_data

pytestmark = pytest.mark.integration

C = date(2026, 10, 5)
ENABLED = {"cores": {"factors": {"enabled": True}}}


def utc(day: date, hour: int = 12) -> datetime:
    return datetime(day.year, day.month, day.day, hour, tzinfo=timezone.utc)


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


def set_settings(conn, tenant_id: int, settings: dict) -> None:
    conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"), {"s": json.dumps(settings), "t": tenant_id})


def add_indicator(conn, tenant_id, code: str, *, frequency="daily") -> int:
    return int(conn.execute(text(
        "INSERT INTO external_indicators (tenant_id, code, name, source, frequency, is_active, created_at, updated_at) "
        "VALUES (:t, :code, :name, 'prueba ficticia', :f, true, now(), now()) RETURNING id"
    ), {"t": tenant_id, "code": code, "name": f"Indicador {code}", "f": frequency}).scalar_one())


def add_obs(conn, indicator_id: int, period: date, value: float, published: datetime, *, revision: int = 0) -> None:
    conn.execute(text(
        "INSERT INTO external_indicator_observations (indicator_id, period_date, value, published_at, ingested_at, revision) "
        "VALUES (:i, :p, :v, :pub, :pub, :rev)"), {"i": indicator_id, "p": period, "v": value, "pub": published, "rev": revision})


def add_definition(conn, tenant_id, key: str, availability_class: str, storage: str, *, schema: dict | None = None, indicator_id=None,
                   family="competition", missing="last_valid_with_age", max_stale=30, frequency="daily") -> None:
    conn.execute(text(
        'INSERT INTO factor_definitions (tenant_id, family, key, name, availability_class, storage, external_indicator_id, frequency, '
        'missing_strategy, max_staleness_days, "schema", is_active, created_at, updated_at) '
        "VALUES (:t, :fam, :key, :name, :cls, :storage, :ind, :freq, :miss, :stale, CAST(:schema AS jsonb), false, now(), now())"
    ), {"t": tenant_id, "fam": family, "key": key, "name": f"Factor {key}", "cls": availability_class, "storage": storage, "ind": indicator_id,
        "freq": frequency, "miss": missing, "stale": max_stale, "schema": json.dumps(schema or {})})


def add_calendar_event(conn, tenant_id, code: str, starts: date, ends: date, known_at: datetime, *, revision=0, status="confirmed", scope="tenant",
                       scope_ref=None) -> None:
    conn.execute(text(
        "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, source, revision, "
        "created_at, updated_at) VALUES (:t, :scope, :ref, :code, :code, 'campaign', :s, :e, :st, :k, 'manual', :rev, now(), now())"
    ), {"t": tenant_id, "code": code, "s": starts, "e": ends, "st": status, "k": known_at, "rev": revision, "scope": scope, "ref": scope_ref})


def inputs_of(conn, tenant_id: int, keys: list[str] | None = None):
    repo = TenantRepository(conn, tenant_id)
    return load_factor_inputs(repo, repo.tenant(), keys=keys), repo


def resolve(conn, tenant_id: int, cutoff: date, horizon: int = 10, block: int | None = 10):
    """Resolución como la hace el pipeline de demanda: regla retroactiva y modo «tal como se sirve» (`block` = horizonte servido)."""
    repo = TenantRepository(conn, tenant_id)
    tenant = repo.tenant()
    inputs = load_factor_inputs(repo, tenant)
    pipeline = FactorPipeline.from_settings(tenant["settings"], tenant.get("sector"))
    dates = pd.date_range(pd.Timestamp(cutoff) - pd.Timedelta(days=20), pd.Timestamp(cutoff) + pd.Timedelta(days=horizon), freq="D")
    return pipeline.resolve(inputs, dates, cutoff, timezone="America/Guayaquil", retroactive=True, served_block_days=block)


@pytest.fixture
def world(conn):
    return make_world(conn, n_branches=1, n_products=1, n_customers=0, name="Empresa W6E A (ficticia)")


@pytest.fixture
def other_world(conn):
    return make_world(conn, n_branches=1, n_products=1, n_customers=0, name="Empresa W6E B (ficticia)")


# ───────────────────────── lectura: apagado no consulta; encendido exige las dos condiciones ─────────────────────────

class Statements:
    def __init__(self, db_engine):
        self.engine, self.sql = db_engine, []

    def __enter__(self):
        event.listen(self.engine, "before_cursor_execute", self._record)
        return self

    def __exit__(self, *exc):
        event.remove(self.engine, "before_cursor_execute", self._record)

    def _record(self, conn, cursor, statement, parameters, context, executemany):
        self.sql.append(statement)

    def touching_factors(self):
        return [s for s in self.sql if "factor_definitions" in s or "external_indicator" in s]


def test_a_switched_off_pipeline_runs_no_query_on_the_factor_tables_even_with_activations_and_data(db_engine, world, conn):
    ind = add_indicator(conn, world.tenant_id, "w6e_clima")
    add_obs(conn, ind, d(-3), 1.0, utc(d(-2)))
    add_definition(conn, world.tenant_id, "w6e.clima", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind)
    for settings in ({}, {"factor_activation": {"w6e.clima": {"active": True}}}, ENABLED,
                     {**ENABLED, "factor_activation": {"w6e.clima": {"active": False}}}):
        set_settings(conn, world.tenant_id, settings)
        repo = TenantRepository(conn, world.tenant_id)
        with Statements(db_engine) as seen:
            assert load_factor_inputs(repo, repo.tenant()) is None, settings
        assert seen.touching_factors() == [], "apagado o sin factores activos: ni una consulta a las tablas de factores"
    set_settings(conn, world.tenant_id, {**ENABLED, "factor_activation": {"w6e.clima": {"active": True}}})
    with Statements(db_engine) as seen:
        inputs, _ = inputs_of(conn, world.tenant_id)
    assert inputs.keys == ["w6e.clima"] and seen.touching_factors()
    assert list(inputs.observations["w6e.clima"]["value"]) == [1.0] and inputs.resolve_definition("w6e.clima")["availability_class"] == "OBSERVED_HISTORICAL"


# ───────────────────────── as-of sobre filas reales ─────────────────────────

def test_loaded_inputs_never_expose_what_was_published_after_each_cutoff(conn, world):
    ind = add_indicator(conn, world.tenant_id, "w6e_ipc", frequency="daily")
    add_definition(conn, world.tenant_id, "w6e.ipc", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind, max_stale=40)
    add_obs(conn, ind, d(-10), 100.0, utc(d(-9)))                       # conocida desde C-9
    add_obs(conn, ind, d(-3), 105.0, utc(d(-2)))                        # conocida desde C-2
    add_obs(conn, ind, d(-3), 150.0, utc(d(2)), revision=1)             # revisión publicada en C+2: DESPUÉS del corte
    add_obs(conn, ind, d(4), 999.0, utc(d(5)))                          # período futuro publicado en C+5
    set_settings(conn, world.tenant_id, {**ENABLED, "factor_activation": {"w6e.ipc": {"active": True}}})
    at_cutoff = resolve(conn, world.tenant_id, C)
    assert at_cutoff.used == ["w6e.ipc"] and at_cutoff.served_block_days == 10, at_cutoff.dropped
    column = at_cutoff.columns.set_index("ds")["factor__w6e.ipc"]
    # Hacia delante se arrastra el último valor visible AL CORTE (105: la revisión y el período futuro no existían todavía).
    assert (column.loc[pd.Timestamp(d(1)):pd.Timestamp(d(10))] == 105.0).all()
    assert 150.0 not in set(column.dropna()) and 999.0 not in set(column.dropna()), "ni la revisión ni el período publicados después del corte"
    # El entrenamiento recibe el valor visible en el ORIGEN de cada bloque: en C−10 aún no se había publicado ni la primera observación ⇒ faltante, no cero.
    assert column.loc[pd.Timestamp(d(-9)):pd.Timestamp(C)].isna().all() and not (column == 0).any()
    # Más tarde, con el mismo insumo, la revisión sí se ve: el pasado del corte anterior no se reescribe.
    later = resolve(conn, world.tenant_id, d(3))
    assert (later.columns.set_index("ds")["factor__w6e.ipc"].loc[pd.Timestamp(d(4)):pd.Timestamp(d(13))] == 150.0).all()
    assert (at_cutoff.columns_at(C).set_index("ds")["factor__w6e.ipc"].loc[pd.Timestamp(d(1)):pd.Timestamp(d(10))] == 105.0).all()
    earlier = at_cutoff.columns_at(d(-5)).set_index("ds")["factor__w6e.ipc"]
    assert (earlier.loc[pd.Timestamp(d(-4)):pd.Timestamp(d(5))] == 100.0).all(), "una ventana anterior ve lo conocido en SU corte"


def test_an_announcement_made_after_the_cutoff_is_missing_for_that_cutoff_not_zero(conn, world):
    add_definition(conn, world.tenant_id, "w6e.campana", "KNOWN_IN_ADVANCE", "calendar_events", schema={"event_code": "W6E_CAMPANA"},
                   family="calendar_events", missing="disable_feature", max_stale=None, frequency="event")
    add_calendar_event(conn, world.tenant_id, "W6E_CAMPANA", d(3), d(5), utc(d(-20)))            # anunciada con tiempo
    set_settings(conn, world.tenant_id, {**ENABLED, "factor_activation": {"w6e.campana": {"active": True}}})
    res = resolve(conn, world.tenant_id, C)
    col = res.columns.set_index("ds")["factor__w6e.campana"]
    assert res.used == ["w6e.campana"] and (col.loc[pd.Timestamp(d(3)):pd.Timestamp(d(5))] == 1.0).all()
    assert col.drop(pd.date_range(d(3), d(5))).isna().all(), "fuera de la campaña no hay valor conocido: faltante, nunca cero"
    # L3: una campaña anunciada DESPUÉS del corte (reconstrucción histórica) se ignora sin descartar el factor entero: esos días quedan faltantes.
    add_calendar_event(conn, world.tenant_id, "W6E_CAMPANA", d(7), d(8), utc(d(2)))
    again = resolve(conn, world.tenant_id, C)
    assert again.used == ["w6e.campana"] and again.dropped == []
    col2 = again.columns.set_index("ds")["factor__w6e.campana"]
    assert (col2.loc[pd.Timestamp(d(3)):pd.Timestamp(d(5))] == 1.0).all() and col2.loc[pd.Timestamp(d(7)):pd.Timestamp(d(8))].isna().all()
    assert (resolve(conn, world.tenant_id, d(2)).columns.set_index("ds")["factor__w6e.campana"].loc[pd.Timestamp(d(7)):pd.Timestamp(d(8))] == 1.0).all(), \
        "con el corte posterior a su anuncio sí es conocida"


def test_public_and_private_indicators_with_the_same_code_need_a_declared_precedence_and_are_never_mixed(conn, world):
    public = add_indicator(conn, None, "w6e_dolar")
    private = add_indicator(conn, world.tenant_id, "w6e_dolar")
    for day in range(-5, 1):
        add_obs(conn, public, d(day), 1.0, utc(d(day + 1)))
        add_obs(conn, private, d(day), 2.0, utc(d(day + 1)))
    add_definition(conn, world.tenant_id, "w6e.dolar", "OBSERVED_HISTORICAL", "external_indicator_observations", schema={"indicator_code": "w6e_dolar"})
    activation = {"factor_activation": {"w6e.dolar": {"active": True}}}
    set_settings(conn, world.tenant_id, {**ENABLED, **activation})
    ambiguous = resolve(conn, world.tenant_id, C)
    assert ambiguous.used == [] and [x["reason"] for x in ambiguous.dropped] == ["ambiguous_indicator"]
    for choice, expected in (("tenant", 2.0), ("public", 1.0)):
        set_settings(conn, world.tenant_id, {"cores": {"factors": {"enabled": True, "indicator_precedence": {"w6e_dolar": choice}}}, **activation})
        res = resolve(conn, world.tenant_id, C)
        assert res.used == ["w6e.dolar"] and set(res.columns["factor__w6e.dolar"].dropna()) == {expected}, "una sola serie: la elegida; nunca una mezcla"


def test_a_company_never_loads_the_factor_rows_of_another_company(conn, world, other_world):
    ind = add_indicator(conn, other_world.tenant_id, "w6e_ajeno")
    add_obs(conn, ind, d(-1), 7.0, utc(d(0)))
    add_definition(conn, other_world.tenant_id, "w6e.ajeno", "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind)
    set_settings(conn, world.tenant_id, {**ENABLED, "factor_activation": {"w6e.ajeno": {"active": True}}})
    inputs, _ = inputs_of(conn, world.tenant_id)
    assert inputs.keys == ["w6e.ajeno"]
    res = resolve(conn, world.tenant_id, C)
    assert res.used == [] and [x["reason"] for x in res.dropped] == ["definition_not_found"], "la definición de otra empresa no existe para esta"
    with pytest.raises(LookupError):
        inputs.resolve_definition("w6e.ajeno")


def test_native_storages_and_unreadable_factors_are_kept_as_drops_and_the_transaction_stays_usable(conn, world):
    add_definition(conn, world.tenant_id, "w6e.ventas", "OBSERVED_HISTORICAL", "sales", family="sales_history")
    add_definition(conn, world.tenant_id, "w6e.sin_indicador", "OBSERVED_HISTORICAL", "external_indicator_observations")
    keys = {"w6e.ventas": {"active": True}, "w6e.sin_indicador": {"active": True}, "w6e.inexistente": {"active": True}}
    set_settings(conn, world.tenant_id, {**ENABLED, "factor_activation": keys})
    res = resolve(conn, world.tenant_id, C)
    reasons = {x["key"]: x["reason"] for x in res.dropped}
    assert reasons == {"w6e.ventas": "storage_unreadable", "w6e.sin_indicador": "storage_unreadable", "w6e.inexistente": "definition_not_found"}
    assert conn.execute(text("SELECT 1")).scalar_one() == 1


# ───────────────────────── ablation en modo base de datos: solo lectura ─────────────────────────

def test_the_database_mode_of_the_ablation_is_read_only_per_transaction_and_never_a_production_database(db_engine):
    url = db_engine.url.render_as_string(hide_password=False)
    engine = factor_ablation.read_only_engine(url)
    try:
        with engine.connect() as c:
            assert c.execute(text("SHOW transaction_read_only")).scalar() == "on"
            assert safety.assert_connected_to_test_database(c) == db_engine.url.database
            before = c.execute(text("SELECT count(*) FROM factor_evaluations")).scalar_one()
            for statement in ("INSERT INTO tenants (uuid, name, status, created_at, updated_at) VALUES (gen_random_uuid(), 'x', 'active', now(), now())",
                              "INSERT INTO factor_evaluations (tenant_id, horizon_days, cutoff, holdout_untouched, decision) "
                              "VALUES (1, 7, now()::date, true, 'promote')"):
                with pytest.raises(DBAPIError) as raised:
                    c.execute(text(statement))
                assert raised.value.orig.sqlstate == "25006", "ReadOnlySqlTransaction: PostgreSQL rechaza la escritura"
                c.rollback()
            # Aunque alguien cambie el valor por defecto de la sesión, la SIGUIENTE transacción vuelve a abrir en solo lectura (SET TRANSACTION READ ONLY).
            c.execute(text("SET default_transaction_read_only = off"))
            c.commit()
            with pytest.raises(DBAPIError) as raised:
                c.execute(text("INSERT INTO factor_evaluations (tenant_id, horizon_days, cutoff, holdout_untouched, decision) VALUES (1, 7, now()::date, true, 'promote')"))
            assert raised.value.orig.sqlstate == "25006"
            c.rollback()
            assert c.execute(text("SELECT count(*) FROM factor_evaluations")).scalar_one() == before
    finally:
        engine.dispose()
    base = url.rsplit("/", 1)[0]
    for bypass in (base + "/predictily", f"{url}&dbname=predictily" if "?" in url else f"{url}?dbname=predictily", f"{base}/predictily?x=/test",
                   f"{url}?options=-c%20default_transaction_read_only%3Doff"):
        with pytest.raises(PermissionError):
            factor_ablation.read_only_engine(bypass)


# ───────────────────────── L2: los eventos de calendario solo aplican a su país y a su rubro ─────────────────────────

def test_calendar_events_of_another_country_or_sector_do_not_apply_to_the_company(conn, world):
    from predictily_engine.db.factors_repository import FactorsRepository, ScopeUnsupportedError

    known = utc(d(-30))
    repo = FactorsRepository(conn, world.tenant_id)
    code = "W6E_ALCANCE"
    # (la empresa de prueba es de EC y su rubro es «ferreteria»)
    add_calendar_event(conn, None, code, d(5), d(6), known, scope="country", scope_ref="PE")           # feriado de OTRO país
    with pytest.raises(ScopeUnsupportedError, match="scope_not_applicable"):
        repo.calendar_observations(code)
    add_calendar_event(conn, None, code, d(7), d(8), known, scope="sector", scope_ref="agro")          # campaña de OTRO rubro
    with pytest.raises(ScopeUnsupportedError, match="scope_not_applicable"):
        repo.calendar_observations(code)
    add_calendar_event(conn, None, code, d(9), d(10), known, scope="country", scope_ref="ec")          # de su país (sin importar mayúsculas)
    frame = repo.calendar_observations(code)
    assert frame["valid_from"].tolist() == [d(9)], "solo la fila que aplica"
    assert sorted((x["reason"], x["scope"], x["scope_ref"]) for x in frame.attrs["discarded"]) == [("scope_not_applicable", "country", "PE"), ("scope_not_applicable", "sector", "agro")]
    add_calendar_event(conn, None, code, d(11), d(12), known, scope="sector", scope_ref="Ferreteria")  # de su rubro
    assert sorted(repo.calendar_observations(code, scope="sector")["valid_from"].tolist()) == [d(11)]
    other = make_world(conn, n_branches=1, n_products=1, n_customers=0, name="Empresa W6E C (ficticia)")
    conn.execute(text("UPDATE tenants SET country = 'PE', sector = 'agro' WHERE id = :t"), {"t": other.tenant_id})
    peru = FactorsRepository(conn, other.tenant_id).calendar_observations(code, scope="country")
    assert peru["valid_from"].tolist() == [d(5)], "la empresa de Perú ve el evento de Perú y no el de Ecuador"


# ───────────────────────── de punta a punta: la ejecución de demanda ─────────────────────────

FACTORS = ["calendar_events.campana_ficticia", "weather.observed"]


@pytest.fixture(scope="module")
def company(db_engine):
    """UNA empresa ficticia con dos ejecuciones de demanda sobre los MISMOS datos: la primera sin factores (por defecto) y la segunda tras encender el
    pipeline, activar los dos factores y cargar sus datos reales en la base, con una regla exigente (50 % de mejora) para que NO ganen la competencia."""
    from loader import create_run, load_company
    from predictily_engine.pipeline.forecast_run import execute_forecast_run
    from predictily_engine.pipeline.schemas import ExecuteRequest
    from predictily_engine.synthetic import generate_company

    out: dict = {}

    def run(tenant_id: int, name: str) -> None:
        with db_engine.begin() as conn:
            run_id, job_id = create_run(conn, tenant_id)
        assert execute_forecast_run(run_id, ExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))["status"] == "completed"
        out[name] = run_id

    with db_engine.begin() as conn:
        tenant_id = load_company(conn, generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01"), "W6E empresa con factores (ficticia)")
    out["tenant_id"] = tenant_id
    run(tenant_id, "before")
    settings = {"cores": {"factors": {"enabled": True, "arm_min_improvement": 0.5, "arm_placebo_arms": 2}}, "factor_activation": {k: {"active": True} for k in FACTORS}}
    with db_engine.begin() as conn:
        last = pd.Timestamp(TenantRepository(conn, tenant_id).last_sale_date())
        first = last - pd.Timedelta(days=500)
        ind = add_indicator(conn, tenant_id, "CLIMA_FICTICIO")
        days = pd.date_range(first, last, freq="D")
        for day, value in zip(days, np.random.default_rng(7).normal(size=len(days))):
            add_obs(conn, ind, day.date(), float(value), utc(day.date() + timedelta(days=1), 11))
        add_definition(conn, tenant_id, FACTORS[1], "OBSERVED_HISTORICAL", "external_indicator_observations", indicator_id=ind, family="weather", max_stale=3)
        add_definition(conn, tenant_id, FACTORS[0], "KNOWN_IN_ADVANCE", "calendar_events", schema={"event_code": "CAMPANA_FICTICIA"}, family="calendar_events",
                       missing="disable_feature", max_stale=None, frequency="event")
        for start in pd.date_range(first + pd.Timedelta(days=40), last, freq="55D"):
            add_calendar_event(conn, tenant_id, "CAMPANA_FICTICIA", start.date(), (start + pd.Timedelta(days=8)).date(), utc((start - pd.Timedelta(days=80)).date()))
        set_settings(conn, tenant_id, settings)
    run(tenant_id, "after")
    return out


def served(db_engine, run_id: int) -> dict[str, pd.DataFrame]:
    """Lo servido y evaluado de UNA ejecución (se comparan dos ejecuciones de la misma empresa: mismos identificadores)."""
    out = {}
    with db_engine.connect() as conn:
        out["values"] = pd.read_sql(text(
            "SELECT level, granularity, node_key, target_date, horizon_step, yhat, lo_80, hi_80, lo_95, hi_95, model_family, quality_status, case_label, "
            "unit_price_assumed, revenue_yhat, p50 FROM forecast_values WHERE forecast_run_id = :r"), conn, params={"r": run_id})
        out["evaluations"] = pd.read_sql(text(
            "SELECT model_family, evaluation_type, level, level_key, horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80, "
            "coverage_95, n_obs, n_windows, metrics::text AS metrics FROM model_evaluations WHERE forecast_run_id = :r"), conn, params={"r": run_id})
        out["recommendations"] = pd.read_sql(text(
            "SELECT branch_id, product_id, type, suggested_qty, safety_stock, reorder_point, stockout_probability, risk_level, status "
            "FROM recommendations WHERE forecast_run_id = :r"), conn, params={"r": run_id})
    return {k: v.sort_values(list(v.columns), kind="mergesort").reset_index(drop=True) for k, v in out.items()}


def test_activating_factors_that_do_not_win_serves_exactly_what_the_same_company_served_before(db_engine, company):
    """Misma empresa, mismos datos: tras activar los factores (que no ganan la competencia) lo servido y lo evaluado es IDÉNTICO a la ejecución anterior."""
    before, after = served(db_engine, company["before"]), served(db_engine, company["after"])
    for name in ("values", "evaluations", "recommendations"):
        assert len(before[name]) > 0
        pd.testing.assert_frame_equal(before[name], after[name], check_exact=True)


def test_the_run_records_what_happened_with_each_factor_and_the_run_before_records_nothing(db_engine, company):
    with db_engine.connect() as conn:
        rows = {name: conn.execute(text("SELECT summary, evaluation_manifest, effective_config, config_version FROM forecast_runs WHERE id = :r"),
                                   {"r": company[name]}).mappings().one() for name in ("before", "after")}
    before, after = rows["before"], rows["after"]
    assert "factors" not in before["summary"] and "factors" not in before["evaluation_manifest"] and "factors" not in before["effective_config"]
    f = after["summary"]["factors"]
    assert f["enabled"] is True and sorted(f["active_factors"]) == sorted(FACTORS) and sorted(f["used_factors"]) == sorted(FACTORS)
    assert f["decision"] == "plain_arm_kept" and f["reason"] == "no_sufficient_improvement" and f["factor_model_served"] is False
    assert f["holdout_used_for_duel"] is False and f["holdout_used_for_decision"] is False and f["evidence"]["scope"] == "selection_windows" and f["retroactive_visibility"] is True
    assert f["selected_factors"] == [] and "Se mantiene el modelo sin factores" in f["message"] and f["served_block_days"] > 0
    assert "factor_model_not_selected" in after["summary"]["limitation_codes"]
    manifest = after["evaluation_manifest"]["factors"]
    assert {c["name"] for c in manifest["feature_contracts"]} == {f"factor__{k}" for k in FACTORS}
    states = manifest["resolution"]["factors"]
    assert manifest["resolution"]["used"] == sorted(FACTORS) and states[FACTORS[1]]["status"] == "AVAILABLE"
    assert states[FACTORS[0]]["reason"] in ("ok", "no_value_for_date"), "la campaña solo tiene valor los días de campaña: el resto es faltante, no cero"
    assert after["effective_config"]["factors"]["config"]["arm_min_improvement"] == 0.5 and after["config_version"] != before["config_version"]


def test_the_loader_gives_the_pipeline_the_factor_inputs_of_the_company_and_its_timezone(db_engine, company):
    with db_engine.connect() as conn:
        data = load_tenant_data(TenantRepository(conn, company["tenant_id"]), None)
    assert data.factor_inputs.keys == sorted(FACTORS) and data.timezone
    assert isinstance(data.factor_inputs.observations[FACTORS[1]], pd.DataFrame) and not isinstance(data.factor_inputs.observations[FACTORS[0]], FactorReadError)
    assert data.factor_inputs.observations[FACTORS[0]]["value"].eq(1.0).all()


def test_the_ablation_reads_a_test_database_without_writing_anything(db_engine, company, tenants):
    url = db_engine.url.render_as_string(hide_password=False)
    tables = ("factor_evaluations", "forecast_runs", "forecast_values", "model_evaluations", "tenants", "external_indicator_observations", "calendar_events")
    with db_engine.connect() as conn:
        before = {t: conn.execute(text(f"SELECT count(*) FROM {t}")).scalar_one() for t in tables}
    data = factor_ablation.load_from_database(url, company["tenant_id"])
    assert data.factor_inputs.keys == sorted(FACTORS) and data.tenant_id == company["tenant_id"]
    with pytest.raises(ValueError, match="Indique los factores"):
        factor_ablation.load_from_database(url, tenants["A"])
    explicit = factor_ablation.load_from_database(url, tenants["A"], factors=[FACTORS[0]])
    assert explicit.factor_inputs.keys == [FACTORS[0]], "claves explícitas: se cargan aunque la empresa no las haya activado"
    assert isinstance(explicit.factor_inputs.definitions[FACTORS[0]], LookupError), "esa empresa no tiene esa definición"
    with db_engine.connect() as conn:
        after = {t: conn.execute(text(f"SELECT count(*) FROM {t}")).scalar_one() for t in tables}
    assert before == after
