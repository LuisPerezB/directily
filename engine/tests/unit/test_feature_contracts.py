"""ADR-V25-06 · contratos de features: cada columna del builder declara su clase de disponibilidad, fuente y datos requeridos.

Una feature sin contrato hace fallar la construcción; el registro se fija con versión y huella; el mapa feature → datos requeridos
(lo consume el gate de calidad para DEGRADE) y el precio futuro confirmado (KNOWN_IN_ADVANCE) frente al supuesto `frozen_price`.
"""

from __future__ import annotations

from datetime import datetime, time, timedelta
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd
import pytest

from predictily_engine.availability import (
    DATASETS,
    FEATURE_CONTRACTS,
    FEATURE_CONTRACTS_VERSION,
    AvailabilityClass,
    FactorResolution,
    FeatureContractError,
    contract_for,
    contracts_digest,
    degrade_plan,
    disabled_columns,
    features_requiring,
    required_data_map,
    validate_feature_columns,
)
from predictily_engine.availability import contracts as contracts_module
from predictily_engine.availability.contracts import factor_contract
from predictily_engine.calendar.ecuador import EcuadorCalendar
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.features import builder as builder_module
from predictily_engine.features.builder import (
    CALENDAR_FEATURES,
    COMMERCIAL_FEATURES,
    DATE_FEATURES,
    LAGS,
    PRICE_ASSUMPTION,
    STATIC_FEATURES,
    FeatureBuilder,
    feature_registry,
)
from predictily_engine.backtest.rolling import BacktestPlan, run_backtest
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.profiling.series import build_panel

# Huella del registro de contratos de la versión indicada. Si cambia un contrato hay que SUBIR la versión
# (FEATURE_CONTRACTS_VERSION) y actualizar este par, además de docs/data/FEATURE_CONTRACTS.md.
PINNED_VERSION = "fc-2026.11-v1"
PINNED_DIGEST = "c2b854fb4a9421348434feb3fd5d4772a1796da37c98a0f3d3dc553fd3cf344a"


@pytest.fixture(scope="module")
def built(small_data):
    panel = build_panel(small_data)
    horizon_end = (panel.cutoff + pd.Timedelta(days=30)).date()
    fb = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, horizon_end)
    ids = list(panel.df["unique_id"].unique())
    train, ref = fb.training_frame(panel.cutoff, ids)
    future = fb.future_frame(panel.cutoff, ids, 30, ref)
    return {"panel": panel, "fb": fb, "ids": ids, "train": train, "ref": ref, "future": future, "data": small_data}


# ───────────────────────── cobertura del registro ─────────────────────────

def test_every_feature_declared_by_the_builder_has_a_contract():
    declared = [*CALENDAR_FEATURES, *COMMERCIAL_FEATURES, *STATIC_FEATURES, *DATE_FEATURES, *[f"lag{n}" for n in LAGS], "weight"]
    declared += [r["name"] for r in feature_registry()]
    assert declared and all(contract_for(name) for name in declared)
    assert set(CALENDAR_FEATURES) == set(EcuadorCalendar.FEATURES)


def test_every_column_the_builder_and_mlforecast_actually_produce_has_a_contract(built):
    validate_feature_columns(built["train"].columns)
    validate_feature_columns(built["future"].columns)
    model = LightGBMGlobal(seed=1, n_jobs=1)
    model.params["n_estimators"] = 5                       # solo interesa el esquema de columnas, no el ajuste
    model.fit(built["train"])
    produced = list(model.mlf.ts.features_order_)
    assert {"lag1", "lag28", "dayofweek", "rolling_mean_lag1_window_size7",
            "seasonal_rolling_mean_lag7_season_length7_window_size4"} <= set(produced)
    validate_feature_columns(produced)
    for name in produced:
        assert contract_for(name).availability_class in AvailabilityClass


def test_the_contract_classes_follow_the_package_specification():
    by = {c.name: c for c in FEATURE_CONTRACTS}
    for name in CALENDAR_FEATURES + DATE_FEATURES:
        assert by[name].availability_class is AvailabilityClass.KNOWN_IN_ADVANCE and "known_at" not in by[name].future_mode
        assert by[name].known_at.startswith(("statutory_rule", "deterministic_from_date"))     # known_at declarado
    for name in [f"lag{n}" for n in LAGS] + ["rolling_mean_lag1_w7", "rolling_mean_lag1_w28", "rolling_std_lag1_w28",
                                              "seasonal_rolling_mean_lag7_s7_w4"]:
        assert by[name].availability_class is AvailabilityClass.OBSERVED_HISTORICAL and by[name].required_data == ("sales_history",)
    assert by["promo_discount"].availability_class is AvailabilityClass.KNOWN_IN_ADVANCE and by["promo_discount"].future_mode == "known_at_cutoff"
    price = by["price_ratio"]
    assert price.availability_class is AvailabilityClass.OBSERVED_HISTORICAL and price.future_mode == "frozen_price"
    assert price.assumption == PRICE_ASSUMPTION and price.future_availability_class is AvailabilityClass.KNOWN_IN_ADVANCE
    assert "product_price_history" in price.future_condition and "known_at" in price.future_condition
    for name in ("branch_code_num", "category_num", "region_num"):
        assert by[name].availability_class is AvailabilityClass.STATIC
    external = by["external_indicators"]
    assert external.availability_class is AvailabilityClass.OBSERVED_HISTORICAL and external.enabled_by_default is False
    assert "GREATEST(published_at, ingested_at)" in external.known_at
    for c in FEATURE_CONTRACTS:
        assert c.source and c.known_at and c.future_mode and c.description, c.name


def test_feature_registry_keeps_the_rows_it_always_had_and_adds_the_contract_to_their_config():
    legacy = [(f, "calendar", f"Indicador de calendario Ecuador: {f}") for f in EcuadorCalendar.FEATURES]
    legacy += [("dayofweek", "calendar", "Día de la semana"), ("month", "calendar", "Mes del año"),
               ("day", "calendar", "Día del mes (quincenas/fin de mes)")]
    legacy += [(f"lag{n}", "lag", f"Ventas (demanda imputada) hace {n} días") for n in (1, 7, 14, 28)]
    legacy += [("rolling_mean_lag1_w7", "rolling", "Media móvil 7 días"), ("rolling_mean_lag1_w28", "rolling", "Media móvil 28 días"),
               ("rolling_std_lag1_w28", "rolling", "Desviación móvil 28 días"),
               ("seasonal_rolling_mean_lag7_s7_w4", "rolling", "Media del mismo día de semana (4 semanas)"),
               ("promo_discount", "commercial",
                "Descuento de promociones; a futuro solo las conocidas al corte (known_at y versión vigente)"),
               ("price_ratio", "commercial", "Precio neto / precio mediano de la serie; a futuro se congela en el último precio"),
               ("branch_code_num", "static", "Sucursal"), ("category_num", "static", "Categoría del producto"),
               ("region_num", "geographic", "Región (Costa/Sierra) de la sucursal"),
               ("series_level", "static", "Nivel medio log de la serie hasta el corte"),
               ("external_indicators", "external", "Indicadores macro as-of (desactivado por defecto; Fase 4)")]
    rows = feature_registry()
    assert [(r["name"], r["kind"], r["description"]) for r in rows] == legacy
    assert all(r["feature_set_version"] == FEATURE_SET_VERSION for r in rows)
    by = {r["name"]: r["config"] for r in rows}
    assert by["lag7"]["lag"] == 7 and by["external_indicators"]["enabled"] is False          # configuración heredada intacta
    assert by["price_ratio"]["assumption"] == PRICE_ASSUMPTION and by["promo_discount"]["availability"] == "as_of_cutoff"
    assert all({"availability_class", "required_data", "source", "future_mode"} <= set(cfg) for cfg in by.values())


# ───────────────────────── una feature sin contrato hace fallar la construcción ─────────────────────────

def test_a_column_without_contract_is_rejected_by_name():
    with pytest.raises(FeatureContractError, match="sin contrato"):
        validate_feature_columns(["lag1", "precio_competencia"])
    validate_feature_columns(["unique_id", "ds", "y", "lag1"])             # claves y objetivo no son features
    with pytest.raises(FeatureContractError):
        contract_for("precio_competencia")


def test_building_the_frames_fails_when_a_declared_feature_loses_its_contract(built, monkeypatch):
    full = contracts_module._column_index()
    monkeypatch.setattr(contracts_module, "_column_index", lambda: {k: v for k, v in full.items() if k != "price_ratio"})
    with pytest.raises(FeatureContractError, match="price_ratio"):
        built["fb"].training_frame(built["panel"].cutoff, built["ids"])
    with pytest.raises(FeatureContractError, match="price_ratio"):
        built["fb"].future_frame(built["panel"].cutoff, built["ids"], 7, built["ref"])


def test_building_the_frames_fails_when_a_new_feature_is_added_without_a_contract(built, monkeypatch):
    """Alguien añade `precio_competencia` al builder y se olvida del contrato: la construcción falla y nombra la columna."""
    original = FeatureBuilder._exog

    def with_new_feature(self, keys, promos):
        df = original(self, keys, promos)
        df["precio_competencia"] = 1.0
        return df

    monkeypatch.setattr(builder_module, "COMMERCIAL_FEATURES", [*COMMERCIAL_FEATURES, "precio_competencia"])
    monkeypatch.setattr(FeatureBuilder, "_exog", with_new_feature)
    fb = FeatureBuilder(built["panel"].df, built["data"].branches, built["data"].promotions, built["panel"].cutoff.date())
    with pytest.raises(FeatureContractError, match="precio_competencia"):
        fb.training_frame(built["panel"].cutoff, built["ids"])
    with pytest.raises(FeatureContractError, match="precio_competencia"):
        fb.future_frame(built["panel"].cutoff, built["ids"], 7, built["ref"])


def test_a_feature_contract_error_in_a_backtest_window_is_not_disguised_as_a_lightgbm_failure(built, monkeypatch):
    """Una feature sin contrato es un defecto de programación: el backtest NO la trata como un fallo de LightGBM (que sí se tolera)."""
    panel, fb = built["panel"], built["fb"]
    plan = BacktestPlan(horizon=14, step=7, cutoffs=[panel.cutoff - pd.Timedelta(days=30)])
    families = ["seasonal_naive", "lightgbm_global"]

    def flaky(self, cutoff, ids):
        raise RuntimeError("LightGBM roto")

    monkeypatch.setattr(FeatureBuilder, "training_frame", flaky)
    tolerated = run_backtest(panel, fb, plan, families, seed=42, n_jobs=1, use_lgbm=True)
    assert set(tolerated["family"]) == {"seasonal_naive"}, "control: un fallo de LightGBM se tolera y el backtest sigue con los estadísticos"

    def no_contract(self, cutoff, ids):
        raise FeatureContractError("feature sin contrato")

    monkeypatch.setattr(FeatureBuilder, "training_frame", no_contract)
    with pytest.raises(FeatureContractError, match="sin contrato"):
        run_backtest(panel, fb, plan, families, seed=42, n_jobs=1, use_lgbm=True)


def test_a_feature_contract_error_in_the_final_training_stops_the_run_instead_of_serving_the_fallback(small_data, monkeypatch):
    original = FeatureBuilder.training_frame

    def fail_at_the_final_cutoff(exc):
        def training_frame(self, cutoff, ids):
            if pd.Timestamp(cutoff) >= self.data_end:         # solo el entrenamiento final (las ventanas del backtest son anteriores)
                raise exc
            return original(self, cutoff, ids)
        return training_frame

    monkeypatch.setattr(FeatureBuilder, "training_frame", fail_at_the_final_cutoff(RuntimeError("LightGBM roto")))
    tolerated = run_pipeline(small_data, horizon=30, seed=42, n_jobs=1)
    assert "fallback_served" in {x["code"] for x in tolerated.limitations}, "control: un fallo de LightGBM se sirve con el respaldo"
    monkeypatch.setattr(FeatureBuilder, "training_frame", fail_at_the_final_cutoff(FeatureContractError("feature sin contrato")))
    with pytest.raises(FeatureContractError, match="sin contrato"):
        run_pipeline(small_data, horizon=30, seed=42, n_jobs=1)


# ───────────────────────── versión y huella del registro ─────────────────────────

def test_the_contract_registry_is_pinned_by_version_and_digest():
    assert (FEATURE_CONTRACTS_VERSION, contracts_digest()) == (PINNED_VERSION, PINNED_DIGEST), (
        "El registro de contratos cambió: suba FEATURE_CONTRACTS_VERSION, actualice este par y docs/data/FEATURE_CONTRACTS.md")
    assert len({c.name for c in FEATURE_CONTRACTS}) == len(FEATURE_CONTRACTS)


# ───────────────────────── datos requeridos y DEGRADE (consumidor: W2-C) ─────────────────────────

def test_required_data_uses_a_closed_vocabulary_and_maps_every_feature():
    mapping = required_data_map()
    assert set(mapping) == {c.name for c in FEATURE_CONTRACTS}
    assert all(token in DATASETS for tokens in required_data_map(include_optional=True).values() for token in tokens)
    assert mapping["price_ratio"] == ("price",) and required_data_map(True)["price_ratio"] == ("price", "confirmed_future_price")
    assert mapping["promo_discount"] == ("promotions",) and mapping["lag7"] == ("sales_history",)
    assert set(features_requiring("sales_history")) >= {"lag1", "lag28", "series_level", "weight", "rolling_std_lag1_w28"}
    assert features_requiring("price") == ["price_ratio"]
    with pytest.raises(FeatureContractError):
        features_requiring("weather")


def test_degrade_plan_separates_optional_neutral_and_indispensable_features():
    low_price = degrade_plan(["price"])
    assert low_price.disable == ("price_ratio",) and low_price.blocked == () and low_price.feasible
    assert degrade_plan(["promotions"]).disable == ("promo_discount",)
    region = degrade_plan(["branch_region"])
    assert region.disable == () and region.neutral == ("region_num",) and region.feasible
    no_sales = degrade_plan(["sales_history"])
    assert not no_sales.feasible and "lag1" in no_sales.blocked and "series_level" in no_sales.blocked
    both = degrade_plan({"price", "promotions"})
    assert set(both.disable) == {"price_ratio", "promo_discount"} and degrade_plan([]).disable == ()
    assert degrade_plan(["external_indicators"]).disable == ()            # no está habilitada por defecto: nada que degradar
    with pytest.raises(FeatureContractError):
        degrade_plan(["clima"])


def test_disabling_optional_features_drops_their_columns_and_leaves_the_rest_intact(built):
    panel, data = built["panel"], built["data"]
    fb = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=30)).date(),
                        disabled_features=["price_ratio", "promo_discount"])
    train, ref = fb.training_frame(panel.cutoff, built["ids"])
    future = fb.future_frame(panel.cutoff, built["ids"], 30, ref)
    drop = ["price_ratio", "promo_discount"]
    pd.testing.assert_frame_equal(train, built["train"].drop(columns=drop))
    pd.testing.assert_frame_equal(future, built["future"].drop(columns=drop))
    assert disabled_columns(["price_ratio"]) == {"price_ratio"}
    # Sus opciones se pueden reutilizar (p. ej. por cada ventana del backtest).
    again = FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), **fb.options())
    assert again.disabled_features == ("price_ratio", "promo_discount")
    for name in ("lag7", "branch_code_num", "region_num", "is_holiday"):
        with pytest.raises(FeatureContractError, match="imprescindible"):
            FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), disabled_features=[name])
    with pytest.raises(FeatureContractError):
        FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), disabled_features=["inexistente"])


def test_default_options_leave_the_frames_exactly_as_they_were(built):
    panel, data = built["panel"], built["data"]
    fb = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=30)).date(),
                        factors=FactorResolution.disabled(), confirmed_prices=pd.DataFrame(), disabled_features=())
    train, ref = fb.training_frame(panel.cutoff, built["ids"])
    pd.testing.assert_frame_equal(train, built["train"])
    pd.testing.assert_frame_equal(fb.future_frame(panel.cutoff, built["ids"], 30, ref), built["future"])
    assert list(train.columns) == ["unique_id", "ds", "y", *CALENDAR_FEATURES, *COMMERCIAL_FEATURES, *STATIC_FEATURES, "weight"]
    assert not [c for c in train.columns if c.startswith("factor__")]


# ───────────────────────── factores externos en el marco ─────────────────────────

def test_resolved_factor_columns_enter_the_frames_only_with_a_contract(built):
    panel, data = built["panel"], built["data"]
    days = pd.date_range(panel.df["ds"].min(), panel.cutoff + pd.Timedelta(days=30))
    columns = pd.DataFrame({"ds": days, "factor__ipc": np.linspace(100.0, 101.0, len(days))})
    contract = factor_contract("ipc", AvailabilityClass.OBSERVED_HISTORICAL, source="external_indicator_observations: IPC",
                               description="IPC")
    resolved = FactorResolution(enabled=True, columns=columns, contracts={"factor__ipc": contract}, used=["ipc"])
    fb = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=30)).date(), factors=resolved)
    train, ref = fb.training_frame(panel.cutoff, built["ids"])
    future = fb.future_frame(panel.cutoff, built["ids"], 30, ref)
    assert list(train.columns)[-1] == "factor__ipc" and list(future.columns)[-1] == "factor__ipc"
    pd.testing.assert_frame_equal(train.drop(columns="factor__ipc"), built["train"])
    assert train["factor__ipc"].notna().all() and future["factor__ipc"].notna().all()
    # Una columna de factor sin contrato no entra.
    orphan = FactorResolution(enabled=True, columns=columns, contracts={}, used=["ipc"])
    with pytest.raises(FeatureContractError, match="factor__ipc"):
        FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), factors=orphan).training_frame(
            panel.cutoff, built["ids"])
    # Un resolvedor apagado no añade nada aunque traiga columnas.
    off = FactorResolution(enabled=False, columns=columns, contracts={"factor__ipc": contract})
    pd.testing.assert_frame_equal(
        FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), factors=off).training_frame(
            panel.cutoff, built["ids"])[0], built["train"])


def test_a_resolved_factor_column_trains_and_predicts_through_mlforecast(built):
    """La columna del factor viaja como exógena dinámica: el modelo se ajusta con ella y predice con la del futuro."""
    panel, data = built["panel"], built["data"]
    days = pd.date_range(panel.df["ds"].min(), panel.cutoff + pd.Timedelta(days=30))
    values = np.linspace(100.0, 101.0, len(days))
    values[:30] = np.nan                                              # el factor empieza a tener datos 30 días después
    columns = pd.DataFrame({"ds": days, "factor__ipc": values})
    contract = factor_contract("ipc", AvailabilityClass.OBSERVED_HISTORICAL, source="ipc", description="IPC")
    resolved = FactorResolution(enabled=True, columns=columns, contracts={"factor__ipc": contract}, used=["ipc"])
    fb = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=30)).date(), factors=resolved)
    train, ref = fb.training_frame(panel.cutoff, built["ids"])
    future = fb.future_frame(panel.cutoff, built["ids"], 7, ref)
    assert train["factor__ipc"].isna().any() and future["factor__ipc"].notna().all()
    model = LightGBMGlobal(seed=1, n_jobs=1)
    model.params["n_estimators"] = 5
    model.fit(train)
    assert "factor__ipc" in model.mlf.ts.features_order_
    forecast = model.predict(7, future)
    assert len(forecast) == 7 * len(built["ids"]) and forecast["yhat"].notna().all()
    validate_feature_columns(model.mlf.ts.features_order_, extra=resolved.contracts)


# ───────────────────────── precio futuro: supuesto frozen_price o confirmado (KNOWN_IN_ADVANCE) ─────────────────────────

def _local(offset_days: int, cutoff: pd.Timestamp, hour: int = 10) -> pd.Timestamp:
    d = (cutoff + pd.Timedelta(days=offset_days)).date()
    return pd.Timestamp(datetime.combine(d, time(hour), tzinfo=ZoneInfo("America/Guayaquil")))


def test_a_confirmed_future_price_replaces_frozen_price_only_when_it_was_known_at_the_cutoff(built):
    panel, data = built["panel"], built["data"]
    c = panel.cutoff
    series = built["fb"].series.drop_duplicates("product_id").reset_index(drop=True)
    assert len(series) >= 5
    p_ok, p_late, p_unknown, p_nobase, p_other_branch = (int(x) for x in series["product_id"][:5])
    other_branch = int(series.loc[series["product_id"] == p_other_branch, "branch_id"].iloc[0]) + 1000

    def row(product, price, valid_from, known_at, branch=None):
        return {"product_id": product, "branch_id": branch, "price": price, "valid_from": (c + pd.Timedelta(days=valid_from)).date(),
                "valid_to": None, "known_at": known_at}

    history = pd.DataFrame([
        row(p_ok, 10.0, -100, _local(-100, c)), row(p_ok, 12.0, 5, _local(-2, c)),                       # confirmado y conocido: x1,2 desde C+5
        row(p_late, 10.0, -100, _local(-100, c)), row(p_late, 15.0, 3, _local(1, c)),                    # conocido DESPUÉS del corte
        row(p_unknown, 10.0, -100, _local(-100, c)), row(p_unknown, 20.0, 3, None),                      # known_at desconocido
        row(p_nobase, 20.0, 3, _local(-2, c)),                                                           # sin precio vigente al corte
        row(p_other_branch, 10.0, -100, _local(-100, c), branch=other_branch),                           # sucursal inexistente
        row(p_other_branch, 30.0, 3, _local(-2, c), branch=other_branch),
    ])
    horizon_end = (c + pd.Timedelta(days=30)).date()
    base = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end)
    conf = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end, confirmed_prices=history, timezone="America/Guayaquil")
    ids = built["ids"]
    train_b, ref = base.training_frame(c, ids)
    train_c, _ = conf.training_frame(c, ids)
    pd.testing.assert_frame_equal(train_b, train_c)                       # el pasado no cambia
    fut_b = base.future_frame(c, ids, 30, ref)
    fut_c = conf.future_frame(c, ids, 30, ref)
    changed = fut_b.merge(fut_c, on=["unique_id", "ds"], suffixes=("_b", "_c"))
    changed = changed[~np.isclose(changed["price_ratio_b"], changed["price_ratio_c"])]
    meta = built["fb"].series.set_index("unique_id")
    assert set(meta.loc[changed["unique_id"], "product_id"]) == {p_ok}, "solo el precio futuro confirmado y conocido al corte cambia"
    assert (changed["ds"] >= c + pd.Timedelta(days=5)).all() and changed["ds"].nunique() == 26      # C+5 … C+30
    expected = (changed["price_ratio_b"] * 1.2).clip(0.2, 5.0)
    np.testing.assert_allclose(changed["price_ratio_c"], expected)
    untouched = fut_b.drop(columns="price_ratio").equals(fut_c.drop(columns="price_ratio"))
    assert untouched, "las demás features del futuro no cambian"
    n_ok = int((meta["product_id"] == p_ok).sum())
    assert conf.future_price_info == {"series_with_confirmed_price": n_ok, "series_frozen_price": len(meta) - n_ok}
    # Sin precios confirmados rige el supuesto, declarado en el contrato.
    assert contract_for("price_ratio").assumption == PRICE_ASSUMPTION


def test_the_known_at_of_a_confirmed_price_follows_the_local_midnight_boundary(built):
    panel, data = built["panel"], built["data"]
    c = panel.cutoff
    pid = int(built["fb"].series["product_id"].iloc[0])
    tz = ZoneInfo("America/Guayaquil")

    def history(known_at):
        return pd.DataFrame([
            {"product_id": pid, "branch_id": None, "price": 10.0, "valid_from": (c - pd.Timedelta(days=100)).date(), "valid_to": None,
             "known_at": pd.Timestamp(datetime.combine((c - pd.Timedelta(days=100)).date(), time(9), tzinfo=tz))},
            {"product_id": pid, "branch_id": None, "price": 20.0, "valid_from": (c + pd.Timedelta(days=2)).date(), "valid_to": None,
             "known_at": known_at}])

    def changed_with(known_at) -> bool:
        fb = FeatureBuilder(panel.df, data.branches, data.promotions, (c + pd.Timedelta(days=10)).date(),
                            confirmed_prices=history(known_at), timezone="America/Guayaquil")
        _, ref = fb.training_frame(c, built["ids"])
        return fb.future_frame(c, built["ids"], 10, ref)["price_ratio"].gt(
            FeatureBuilder(panel.df, data.branches, data.promotions, (c + pd.Timedelta(days=10)).date()).future_frame(
                c, built["ids"], 10, ref)["price_ratio"]).any()

    day = c.date()
    assert changed_with(pd.Timestamp(datetime.combine(day, time(23, 59, 59), tzinfo=tz)))           # 23:59:59 del día del corte
    assert not changed_with(pd.Timestamp(datetime.combine(day + timedelta(days=1), time(0, 0, 0), tzinfo=tz)))   # medianoche siguiente
