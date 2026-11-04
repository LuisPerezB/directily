"""W6-E (revisión adversarial) · los valores de factor TAL COMO SE SIRVEN: bloques de entrenamiento, antigüedad, revisiones, placebos y herencia de la política.

Sin base de datos ni LightGBM (rápido): `availability/served.py`, `FactorResolution` en modo servido, `shift_observations` (placebo), la revisión que acorta una campaña
(M3), un anuncio posterior al corte en una ejecución histórica (L3), una serie diaria fresca (e15), y los mutantes M10/M17 (la política del constructor viaja a las
ventanas as-of y al brazo con factores). Todo FICTICIO.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.availability import AvailabilityStatus as S
from predictily_engine.availability import FactorPipeline, FactorSpec, FeatureAvailabilityValidator
from predictily_engine.availability.pipeline import shift_observations
from predictily_engine.availability.served import block_origins, served_series, served_stats
from predictily_engine.backtest.factor_arm import ArmContext, prepare_factor_arm
from predictily_engine.backtest.rolling import asof_windows
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.data import DEFAULT_SETTINGS, FactorInputs
from predictily_engine.platform.config import effective_config_record, resolve_config
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic.factors import CAMPAIGN_KEY, NOISE_KEY, fictional_factor_demo

TZ = "America/Guayaquil"
D0 = pd.Timestamp("2026-01-01")


def D(k: int) -> pd.Timestamp:
    return D0 + pd.Timedelta(days=k)


def definition(key: str, cls: str = "OBSERVED_HISTORICAL", storage: str = "external_indicator_observations", **over) -> dict:
    base = {"id": None, "tenant_id": None, "family": "competition", "key": key, "name": key, "availability_class": cls, "storage": storage,
            "external_indicator_id": None, "unit": None, "frequency": "daily", "geo_scope": "country", "source_key": None, "license": None,
            "missing_strategy": "disable_feature", "max_staleness_days": None, "retention_days": None, "schema": {}, "is_active": False,
            "is_informative_alert": False}
    return {**base, **over}


def daily_obs(days: pd.DatetimeIndex, seed: int = 0, lag_days: int = 0) -> pd.DataFrame:
    values = np.random.default_rng(seed).normal(size=len(days))
    published = days + pd.Timedelta(days=lag_days, hours=20)
    return pd.DataFrame({"id": range(len(days)), "period_date": days, "value": values, "revision": 0, "observed_at": days, "published_at": published,
                         "ingested_at": published})


def resolve(inputs: FactorInputs, cutoff, dates, block: int | None = 30, **settings):
    config = resolve_config("factors", {})[0]
    pipeline = FactorPipeline({**config, "enabled": True, **settings}, {k: {"active": True} for k in inputs.keys})
    return pipeline.resolve(inputs, dates, cutoff, timezone=TZ, retroactive=True, served_block_days=block)


def inputs_of(key: str, defn: dict, obs: pd.DataFrame) -> FactorInputs:
    return FactorInputs(keys=[key], definitions={key: defn}, observations={key: obs})


# ───────────────────────── los bloques y el camino vectorizado ─────────────────────────

def test_block_origins_give_ages_one_to_b_in_the_past_and_the_cutoff_for_the_future():
    idx = pd.date_range(D(0), D(100))
    cutoff = D(80)
    origins = pd.DatetimeIndex(block_origins(idx, cutoff, 30))
    age = (idx - origins).days
    assert set(age[idx <= cutoff]) == set(range(1, 31)) and (origins[idx > cutoff] == cutoff).all() and list(age[idx > cutoff][:3]) == [1, 2, 3]
    assert origins[idx == cutoff][0] == D(50) and origins[idx == D(51)][0] == D(50) and origins[idx == D(50)][0] == D(20)
    with pytest.raises(ValueError):
        block_origins(idx, cutoff, 0)


@pytest.mark.parametrize("cls", ["OBSERVED_HISTORICAL", "STATIC"])
def test_the_vectorized_path_equals_evaluating_every_date_from_its_origin(cls):
    """El camino rápido (una evaluación por origen) da EXACTAMENTE lo que daría evaluar cada fecha desde su origen con el validador."""
    days = pd.date_range(D(0), D(200))
    rng = np.random.default_rng(3)
    rows = []
    for i, day in enumerate(days):
        for rev in range(int(rng.integers(1, 3))):
            rows.append({"id": len(rows), "period_date": day, "value": float(rng.normal()), "revision": rev, "observed_at": day,
                         "published_at": day + pd.Timedelta(days=int(rng.integers(0, 6)) + 3 * rev, hours=int(rng.integers(0, 24)))})
    obs = pd.DataFrame(rows).assign(ingested_at=lambda f: f["published_at"])
    spec = FactorSpec.from_definition(definition("f", cls, max_staleness_days=10, missing_strategy="last_valid_with_age"))
    validator = FeatureAvailabilityValidator(TZ, retroactive=True)
    prepared = validator.prepare(spec, obs)
    cutoff, dates = D(150), pd.date_range(D(10), D(190))
    got = served_series(validator, spec, prepared, dates, cutoff, 30)
    origins = pd.DatetimeIndex(block_origins(dates, pd.Timestamp(cutoff), 30))
    for i, (day, origin) in enumerate(zip(dates, origins)):
        r = validator.evaluate(spec, prepared, origin.date(), target_date=day.date())
        expected = float(r.value) if r.status is S.AVAILABLE and r.value is not None else np.nan
        assert (np.isnan(expected) and np.isnan(got["value"].iat[i])) or expected == got["value"].iat[i], (day, origin)
        assert got["status"].iat[i] == r.status.value and got["origin"].iat[i] == origin


def test_a_value_older_than_the_staleness_limit_is_missing_and_reported_never_presented_as_current():
    days = pd.date_range(D(0), D(60))
    spec = FactorSpec.from_definition(definition("f", max_staleness_days=5, missing_strategy="last_valid_with_age"))
    validator = FeatureAvailabilityValidator(TZ, retroactive=True)
    prepared = validator.prepare(spec, daily_obs(days, lag_days=0))
    series = served_series(validator, spec, prepared, pd.date_range(D(55), D(120)), D(60), 30)
    future = series[series["ds"] > D(60)]
    assert future["value"].notna().sum() <= 6 and future["value"].iloc[10:].isna().all(), "pasado el límite, faltante (NaN)"
    assert (future["status"].iloc[10:] == S.STALE.value).all() and future["age_days"].max() >= 59
    stats = served_stats(series, D(60))
    assert stats["future"]["stale_fraction"] > 0.8 and stats["future"]["max_age_days"] >= 59 and 0 <= stats["future"]["missing_fraction"] <= 1
    static = FactorSpec.from_definition(definition("g", "STATIC", max_staleness_days=5))
    st = served_series(validator, static, validator.prepare(static, daily_obs(days)), pd.date_range(D(55), D(120)), D(60), 30)
    assert st["value"].notna().all() and (st["status"] == S.AVAILABLE.value).all(), "un atributo estático no caduca"


# ───────────────────────── e15: una serie fresca no se descarta; una fuente caída sí (según su estrategia) ─────────────────────────

def test_a_fresh_daily_series_is_kept_with_its_stale_days_missing_and_a_dead_source_is_dropped_or_kept_by_strategy():
    days = pd.date_range(D(0), D(300))
    dates = pd.date_range(D(0), D(390))
    fresh = inputs_of("fx", definition("fx", missing_strategy="disable_feature"), daily_obs(days, lag_days=0))      # frecuencia diaria ⇒ 7 días; el horizonte es de 90
    res = resolve(fresh, D(300), dates, block=60)
    assert res.used == ["fx"] and res.dropped == [], "antes se descartaba entera por el simple arrastre sobre el horizonte (e15)"
    stats = res.stats["fx"]
    assert stats["source_stale_at_origin"] is False and stats["future"]["stale_fraction"] > 0.9 and stats["future"]["missing_fraction"] > 0.9
    col = res.columns.set_index("ds")["factor__fx"]
    assert col.loc[D(301):D(307)].notna().all() or col.loc[D(301):D(302)].notna().all()
    assert col.loc[D(320):D(390)].isna().all()
    # La fuente se cayó (la última observación es de hace 100 días): `disable_feature` saca el factor; `last_valid_with_age` lo conserva con el horizonte faltante.
    dead_obs = daily_obs(days[:200], lag_days=0)
    dropped = resolve(inputs_of("fx", definition("fx", missing_strategy="disable_feature"), dead_obs), D(300), dates, block=60)
    assert dropped.used == [] and [d["reason"] for d in dropped.dropped] == ["stale"]
    kept = resolve(inputs_of("fx", definition("fx", missing_strategy="last_valid_with_age"), dead_obs), D(300), dates, block=60)
    assert kept.used == ["fx"] and kept.stats["fx"]["source_stale_at_origin"] is True
    after = kept.columns.set_index("ds")["factor__fx"]
    assert after.loc[D(301):].isna().all() and after.loc[D(0):D(150)].notna().any(), "el pasado conserva sus valores; el futuro vencido es faltante"


# ───────────────────────── L3: lo anunciado después del corte se ignora, no descarta el factor ─────────────────────────

def test_an_announcement_after_the_cutoff_is_ignored_in_a_historical_run_without_dropping_the_factor():
    campaigns = pd.DataFrame({"id": [1, 2], "period_date": [D(120), D(135)], "value": 1.0, "revision": 0,
                              "available_at": [D(60) + pd.Timedelta(hours=12), D(110) + pd.Timedelta(hours=12)],      # la 2.ª se anunció el día 110, DESPUÉS del corte (día 100)
                              "valid_from": [D(120), D(135)], "valid_to": [D(125), D(140)]})
    defn = definition("camp", "KNOWN_IN_ADVANCE", storage="calendar_events", frequency="event")
    res = resolve(inputs_of("camp", defn, campaigns), D(100), pd.date_range(D(0), D(190)), block=60)
    assert res.used == ["camp"] and res.dropped == [], "antes un solo anuncio posterior al corte descartaba el factor entero"
    col = res.columns.set_index("ds")["factor__camp"]
    assert (col.loc[D(120):D(125)] == 1.0).all() and col.loc[D(135):D(140)].isna().all() and not (col == 0).any()
    # Con un corte posterior al anuncio (día 112) la segunda campaña sí es conocida.
    later = resolve(inputs_of("camp", defn, campaigns), D(112), pd.date_range(D(0), D(190)), block=60)
    assert (later.columns.set_index("ds")["factor__camp"].loc[D(135):D(140)] == 1.0).all()


# ───────────────────────── M3: una revisión posterior que acorta el evento reemplaza a la anterior ─────────────────────────

def test_a_later_revision_that_shortens_a_known_in_advance_event_supersedes_the_previous_one_on_every_date():
    obs = pd.DataFrame([
        {"id": 1, "period_date": D(10), "value": 1.0, "revision": 0, "valid_from": D(10), "valid_to": D(20), "available_at": D(0) + pd.Timedelta(hours=12)},
        {"id": 2, "period_date": D(10), "value": 1.0, "revision": 1, "valid_from": D(10), "valid_to": D(15), "available_at": D(5) + pd.Timedelta(hours=12)},
    ])
    spec = FactorSpec.from_definition(definition("camp", "KNOWN_IN_ADVANCE", storage="calendar_events", frequency="event"))
    validator = FeatureAvailabilityValidator(TZ, retroactive=True)
    after = validator.evaluate(spec, obs, D(30).date(), target_date=D(18).date())
    assert after.status is S.NOT_YET_AVAILABLE and after.reason == "no_value_for_date" and after.detail.get("superseded_revision") is True and after.value is None
    assert validator.evaluate(spec, obs, D(30).date(), target_date=D(13).date()).revision == 1, "las fechas que la revisión conserva usan la revisión vigente"
    before = validator.evaluate(spec, obs, D(3).date(), target_date=D(18).date())
    assert before.status is S.AVAILABLE and before.revision == 0, "al corte día 3 solo se conocía la revisión 0 (que cubre el 18)"
    res = FactorPipeline({"enabled": True}, {"camp": {"active": True}}).resolve(inputs_of("camp", definition("camp", "KNOWN_IN_ADVANCE", storage="calendar_events",
                                                                                                            frequency="event"), obs),
                                                                              pd.date_range(D(0), D(40)), D(30), timezone=TZ, retroactive=True)
    col = res.columns_at(D(30)).set_index("ds")["factor__camp"]
    assert col.loc[D(16):D(20)].isna().all() and (col.loc[D(10):D(15)] == 1.0).all()
    assert (res.columns_at(D(3)).set_index("ds")["factor__camp"].loc[D(16):D(20)] == 1.0).all()


# ───────────────────────── el placebo: mismas fechas relativas y distribución, otra alineación ─────────────────────────

def test_the_placebo_shifts_every_date_together_and_keeps_the_lag_the_distribution_and_the_missing_pattern():
    days = pd.date_range(D(0), D(199))
    obs = daily_obs(days, seed=4, lag_days=2)
    obs.loc[obs.index % 7 == 3, "value"] = np.nan
    moved = shift_observations(obs, 40)
    assert sorted(moved["value"].dropna()) == sorted(obs["value"].dropna()) and moved["value"].isna().sum() == obs["value"].isna().sum()
    lag = (pd.to_datetime(moved["published_at"]) - pd.to_datetime(moved["period_date"])).dt.total_seconds()
    base = (pd.to_datetime(obs["published_at"]) - pd.to_datetime(obs["period_date"])).dt.total_seconds()
    assert np.allclose(np.sort(lag.to_numpy()), np.sort(base.to_numpy())), "el rezago de publicación se conserva fila por fila"
    changed = (pd.to_datetime(moved["period_date"]).reset_index(drop=True) != pd.to_datetime(obs["period_date"]).reset_index(drop=True)).mean()
    assert changed > 0.95 and pd.to_datetime(moved["period_date"]).min() >= D(0) and pd.to_datetime(moved["period_date"]).max() <= D(199), "gira dentro del mismo rango"
    assert shift_observations(obs, 0).equals(obs) and shift_observations(pd.DataFrame(), 5).empty
    assert not shift_observations(obs, 40)["period_date"].equals(shift_observations(obs, 80)["period_date"])


def test_a_shifted_resolution_keeps_the_availability_shape_but_breaks_the_alignment_and_select_keeps_a_subset():
    demo = fictional_factor_demo(n_products=8)
    data = replace(demo.data, settings={**DEFAULT_SETTINGS, "cores": {"factors": {"enabled": True}}, "factor_activation": {CAMPAIGN_KEY: {"active": True}, NOISE_KEY: {"active": True}}},
                   factor_inputs=demo.inputs)
    core = effective_config_record("demand", data.settings, data.sector)["effective_config"]
    panel = build_panel(data, DayRules.from_config(core))
    features = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=90)).date())
    outcome = prepare_factor_arm(data, panel, features, (panel.cutoff + pd.Timedelta(days=90)).date(), core, block_days=60)
    res = outcome.resolution
    only = res.select([CAMPAIGN_KEY])
    assert only.used == [CAMPAIGN_KEY] and list(only.columns.columns) == ["ds", f"factor__{CAMPAIGN_KEY}"] and set(only.contracts) == {f"factor__{CAMPAIGN_KEY}"}
    placebo = only.shifted({CAMPAIGN_KEY: 120})
    real, fake = only.columns[f"factor__{CAMPAIGN_KEY}"], placebo.columns[f"factor__{CAMPAIGN_KEY}"]
    assert placebo.used == only.used and placebo.served_block_days == 60 and placebo.definition_version(CAMPAIGN_KEY) == only.definition_version(CAMPAIGN_KEY)
    assert (real.notna() & fake.notna()).sum() < min(real.notna().sum(), fake.notna().sum()), "las campañas del placebo caen en OTRAS fechas"
    assert abs(int(real.notna().sum()) - int(fake.notna().sum())) <= 0.35 * max(int(real.notna().sum()), 1), "pero de forma parecida (misma proporción de faltantes)"
    assert not (fake == 0).any() and set(fake.dropna().unique()) <= {1.0}


# ───────────────────────── M5: mutantes M10 y M17 (la política del constructor viaja) ─────────────────────────

def _versioned(data):
    from predictily_engine.data.asof import SalesVersions

    s = data.sales.reset_index(drop=True)
    last = s["ds"].max()
    late = ((s["ds"] - s["ds"].min()).dt.days % 3 == 0) & (s["ds"] > last - pd.Timedelta(days=150))
    units = s["units"].where(s["units"] > 0, 1.0)
    lines = pd.DataFrame({
        "sale_id": np.arange(len(s)), "line_number": 1, "branch_id": s["branch_id"], "product_id": s["product_id"], "ds": s["ds"], "quantity": s["units"],
        "unit_price": s["net_amount"] / units, "discount_amount": s["discount_amount"], "net_amount": s["net_amount"], "unit_cost": 4.0, "is_return": False,
        "document_type": "invoice",
        "known_from": s["ds"] + pd.to_timedelta(np.where(late, 5, 0), unit="D") + pd.Timedelta(hours=17), "known_until": pd.NaT})
    versions = SalesVersions.from_lines(lines, "America/Guayaquil", last)
    return replace(data, sales_versions=versions, sales=versions.sales_cells(last), sales_knowledge=versions.summary())


def test_the_builder_policy_travels_to_the_rebuilt_as_of_windows_and_to_the_factor_arm():
    """M10: sin `**features.options()` en `asof_windows`, una ventana reconstruida perdería las features deshabilitadas, la zona y los factores.
    M17: el brazo con factores debe heredar la MISMA política (features deshabilitadas y zona) del constructor sin factores."""
    demo = fictional_factor_demo(n_products=8)
    data = _versioned(replace(demo.data, settings={**DEFAULT_SETTINGS, "cores": {"factors": {"enabled": True}}, "factor_activation": {CAMPAIGN_KEY: {"active": True}}},
                              factor_inputs=demo.inputs))
    core = effective_config_record("demand", data.settings, data.sector)["effective_config"]
    panel = build_panel(data, DayRules.from_config(core))
    horizon_end = (panel.cutoff + pd.Timedelta(days=90)).date()
    base = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end, promotion_revisions=data.promotion_revisions,
                          disabled_features=["price_ratio"], timezone="America/Lima")
    assert base.disabled_features == ("price_ratio",) and base.timezone == "America/Lima"
    windows = asof_windows(data, panel, base, horizon_end, DayRules.from_config(core))
    assert windows is not None
    rebuilt = windows.make_features(panel.df)
    assert rebuilt.disabled_features == ("price_ratio",) and rebuilt.timezone == "America/Lima", "la ventana reconstruida hereda la política (mata M10)"
    ids = list(panel.df["unique_id"].unique())[:6]
    train, _ = rebuilt.training_frame(panel.cutoff - pd.Timedelta(days=40), ids)
    assert "price_ratio" not in train.columns
    # M17: el brazo con factores
    context = ArmContext(data=data, panel=panel, features=base, horizon_end=horizon_end)
    outcome = prepare_factor_arm(data, panel, base, horizon_end, core, block_days=60)
    arm = outcome.arm_factory(outcome.resolution)
    for builder in (arm.features, arm.build(panel.df), context.make_arm(outcome.resolution).features):
        assert builder.disabled_features == ("price_ratio",) and builder.timezone == "America/Lima" and builder.factors is outcome.resolution
    frame, _ = arm.features.training_frame(panel.cutoff - pd.Timedelta(days=40), ids)
    assert "price_ratio" not in frame.columns and f"factor__{CAMPAIGN_KEY}" in frame.columns
