"""W6-E · el pipeline de factores CONECTADO a la demanda (ADR-V25-16 y ADR-V25-25), sin base de datos. Todo con datos FICTICIOS
(`predictily_engine.synthetic.factors`: campañas anunciadas por anticipado con efecto sembrado y un «clima» de ruido).

Cubre: la garantía as-of de los valores de factor en cada ventana (conocido en el origen o faltante, nunca cero), el entrenamiento TAL COMO SE SIRVE (sin la huella de
fecha del valor del propio día), la paridad exacta con el pipeline apagado, las reglas de activación (`factors.enabled` Y `factor_activation.<clave>.active`), la
competencia de cada factor con el LightGBM sin factores y con sus placebos solo con las ventanas de selección (el período final reservado no decide), la
selección hacia delante, la huella de linaje (H2) y la integración con las ventanas as-of reconstruidas.
"""

from __future__ import annotations

import json
from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.availability.pipeline import FactorPipeline
from predictily_engine.availability.served import block_origins
from predictily_engine.backtest.factor_arm import KEPT, NOT_EVALUATED, SELECTED, FactorWindows, prepare_factor_arm
from predictily_engine.backtest.rolling import asof_windows
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.models import ml as ml_module
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import DEFAULT_SETTINGS, FactorInputs, FactorReadError
from predictily_engine.platform.config import effective_config_record, validate_overrides
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic.factors import CAMPAIGN_KEY, NOISE_KEY, fictional_factor_demo

ACTIVATION = {CAMPAIGN_KEY: {"active": True}, NOISE_KEY: {"active": True}}
ON = {"cores": {"factors": {"enabled": True}}, "factor_activation": ACTIVATION}
ON2 = {"cores": {"factors": {"enabled": True, "arm_placebo_arms": 2}}, "factor_activation": ACTIVATION}      # 2 placebos: la misma lógica con menos cómputo


@pytest.fixture(scope="module")
def demo():
    return fictional_factor_demo()


def with_settings(demo, settings: dict, inputs: bool = True):
    """Los MISMOS datos ficticios con otros ajustes de empresa (y con o sin los datos de los factores cargados)."""
    return replace(demo.data, settings={**DEFAULT_SETTINGS, **settings}, factor_inputs=demo.inputs if inputs else None)


def prepare(data, horizon_days: int = 90, block_days: int | None = None):
    """Panel, constructor sin factores y resolución de factores como los arma `run_pipeline` (`block_days` = horizonte servido en el backtest)."""
    core = effective_config_record("demand", data.settings, data.sector)["effective_config"]
    panel = build_panel(data, DayRules.from_config(core))
    horizon_end = (panel.cutoff + pd.Timedelta(days=horizon_days)).date()
    features = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end, promotion_revisions=data.promotion_revisions,
                              promotions_known_on=data.promotions_known_on)
    outcome = prepare_factor_arm(data, panel, features, horizon_end, core, block_days=block_days)
    return panel, features, outcome


def arm_of(outcome, *keys: str):
    """El constructor CON factores (todos los resueltos o solo `keys`) como lo arma la competencia."""
    return outcome.arm_factory(outcome.resolution.select(list(keys)) if keys else outcome.resolution)


def day_values(result) -> pd.DataFrame:
    fv = result.forecast_values
    cols = ["node_key", "granularity", "horizon_step", "yhat", "lo_80", "hi_80", "lo_95", "hi_95", "model_family", "quality_status"]
    return fv[fv["granularity"] == "day"][cols].sort_values(["node_key", "horizon_step"]).reset_index(drop=True)


# ───────────────────────── garantía as-of: conocido en el corte o faltante, jamás cero ─────────────────────────

def test_training_rows_get_the_value_served_from_their_origin_never_the_one_of_their_own_day(demo):
    """H1 (a): una fila de entrenamiento recibe el valor visible en el origen de SU bloque y arrastrado como al servir; los valores de todas las fechas de un
    bloque son el mismo y la fila nunca ve el valor de su propio día (la «huella de fecha» con que el ruido ganaba)."""
    B = 30
    panel, _, outcome = prepare(with_settings(demo, ON), block_days=B)
    assert outcome.served_block_days == B and outcome.resolution.served_block_days == B
    fb = arm_of(outcome, NOISE_KEY).features
    obs = demo.inputs.observations[NOISE_KEY].set_index("period_date")["value"]
    ids = list(panel.df["unique_id"].unique())[:8]
    cutoff = panel.cutoff - pd.Timedelta(days=60)
    train, ref = fb.training_frame(cutoff, ids)
    future = fb.future_frame(cutoff, ids, 14, ref)
    col = f"factor__{NOISE_KEY}"
    by_day = train.groupby("ds")[col].first()
    origins = pd.DatetimeIndex(block_origins(by_day.index, pd.Timestamp(cutoff), B))
    ages = (by_day.index - origins).days
    assert ages.min() == 1 and ages.max() == B, "edades 1…B, como un horizonte servido"
    # La observación del día d se publica a las 11:00 UTC del día d+1 (06:00 en Ecuador) y se mide a las 22:00 locales del día anterior: al origen o solo
    # es visible hasta la del día o−1 (referencia o−2) y, con un límite de 3 días, solo el primer día del bloque (edad 1) conserva su valor; el resto es FALTANTE.
    expected_on = origins + pd.Timedelta(days=0)
    for day, origin, value in zip(by_day.index, expected_on, by_day.to_numpy()):
        if (day - origin).days <= 1:
            assert value == obs[origin - pd.Timedelta(days=1)], (day, origin)
        elif (day - origin).days > 1:
            assert np.isnan(value), "más viejo que max_staleness_days = faltante, no un valor vencido presentado como vigente"
    same_day = obs.reindex(by_day.index)
    served = by_day.dropna()
    assert (served != same_day.loc[served.index]).all() and (served != obs.shift(1).reindex(served.index)).all(), "ninguna fila ve el valor de su propio día (ni el del día previo)"
    assert by_day.dropna().nunique() <= len(set(origins)), "como mucho un valor distinto por bloque (antes: uno por día)"
    # Hacia delante: el valor visible en el corte; con límite de 3 días solo el primer día del horizonte lo conserva.
    fut = future.groupby("ds")[col].first()
    assert fut.notna().sum() == 1 and fut.dropna().iat[0] == obs[cutoff - pd.Timedelta(days=1)]
    assert float(obs[cutoff]) not in set(np.round(pd.concat([train[col], future[col]]).dropna(), 12)), "lo publicado desde el corte no se ve en la ventana"
    assert not (train[col] == 0).any() and not (future[col] == 0).any(), "ningún valor se rellena con cero"
    # Contraste: el modo anterior (W2-D) sí entregaba un valor distinto por día (capacidad de memorizar fechas).
    pipeline = FactorPipeline.from_settings(with_settings(demo, ON).settings)
    dates = pd.date_range(panel.df["ds"].min(), panel.cutoff + pd.Timedelta(days=90))
    legacy = pipeline.resolve(demo.inputs, dates, panel.cutoff, timezone=None, retroactive=True)
    legacy_col = legacy.columns_at(cutoff)[f"factor__{NOISE_KEY}"]
    assert legacy_col[legacy.columns_at(cutoff)["ds"] <= pd.Timestamp(cutoff)].nunique() > 100


def test_a_known_in_advance_factor_is_missing_not_zero_when_it_was_announced_after_the_cutoff(demo):
    panel, _, outcome = prepare(with_settings(demo, ON), block_days=90)
    fb = arm_of(outcome, CAMPAIGN_KEY).features
    camp = demo.campaigns
    col = f"factor__{CAMPAIGN_KEY}"
    # Ventana cuyo corte `c` deja una campaña «k» que empieza en c+85 (anunciada 80 días antes: en c+5, DESPUÉS del corte) y la anterior
    # (empieza en c+30, anunciada en c−50: conocida).
    known_start, unknown_start = camp["starts_on"].iloc[3], camp["starts_on"].iloc[4]
    cutoff = unknown_start - pd.Timedelta(days=85)
    assert known_start == cutoff + pd.Timedelta(days=30) and camp["announced_on"].iloc[3] <= cutoff < camp["announced_on"].iloc[4]
    ids = list(panel.df["unique_id"].unique())[:8]
    train, ref = fb.training_frame(cutoff, ids)
    future = fb.future_frame(cutoff, ids, 90, ref)
    by_day = future.groupby("ds")[col].first()
    known_days = pd.date_range(known_start, periods=9)
    unknown_days = pd.date_range(unknown_start, cutoff + pd.Timedelta(days=90))
    assert (by_day.loc[known_days] == 1.0).all(), "la campaña anunciada antes del corte es un valor previsto conocido"
    assert by_day.loc[unknown_days].isna().all(), "la anunciada DESPUÉS del corte es desconocida: faltante, no cero"
    for frame in (train, future):
        assert not (frame[col] == 0).any(), "ningún valor de factor se rellena con cero"
    assert train[col].notna().any() and (train[col].dropna() == 1.0).all()


def test_each_window_reads_the_factor_as_known_at_its_own_origin_through_the_as_of_windows(demo):
    """Con ventanas as-of reconstruidas (documentos tardíos), `FactorWindows` entrega el constructor CON factores de cada origen."""
    data = with_settings(demo, ON)
    versioned = versioned_data(data)
    panel, features, outcome = prepare(versioned, block_days=30)
    assert outcome.resolution.used == [CAMPAIGN_KEY, NOISE_KEY]
    arm = arm_of(outcome, NOISE_KEY)
    core = effective_config_record("demand", versioned.settings, versioned.sector)["effective_config"]
    horizon_end = (panel.cutoff + pd.Timedelta(days=90)).date()
    windows = asof_windows(versioned, panel, features, horizon_end, DayRules.from_config(core))
    assert windows is not None
    view = FactorWindows(windows, arm)
    late = panel.cutoff - pd.Timedelta(days=60)
    wp, wf = view.at(late)
    assert windows.info()[-1]["panel_rebuilt"] is True and wp is not panel, "el panel de la ventana se reconstruyó con lo conocido en su corte"
    assert wf is not arm.features and wf.factors is arm.resolution
    ids = list(wp.df["unique_id"].unique())[:8]
    train, _ = wf.training_frame(late, ids)
    col = f"factor__{NOISE_KEY}"
    assert col in train.columns and train["ds"].max() <= late, "solo filas ≤ corte"
    obs = demo.inputs.observations[NOISE_KEY].set_index("period_date")["value"]
    by_day = train.groupby("ds")[col].first()
    origins = pd.DatetimeIndex(block_origins(by_day.index, pd.Timestamp(late), 30))
    for day, origin, value in zip(by_day.index, origins, by_day.to_numpy()):
        if not np.isnan(value):
            assert value == obs[origin - pd.Timedelta(days=1)], "el valor viene del origen de su bloque (conocido entonces), no de su día"
    truth_panel, truth_builder = view.at(panel.cutoff)
    assert truth_panel is panel and truth_builder is arm.features, "una ventana igual a la verdad final reutiliza el constructor de la verdad"
    assert view.info() == windows.info()


def versioned_data(data):
    """Los mismos datos con versiones de documentos: cada tercer día de los últimos 150 se conoció 5 días TARDE ⇒ ventanas as-of reconstruidas."""
    from predictily_engine.data.asof import SalesVersions

    s = data.sales.reset_index(drop=True)
    last = s["ds"].max()
    late = ((s["ds"] - s["ds"].min()).dt.days % 3 == 0) & (s["ds"] > last - pd.Timedelta(days=150))
    units = s["units"].where(s["units"] > 0, 1.0)
    lines = pd.DataFrame({
        "sale_id": np.arange(len(s)), "line_number": 1, "branch_id": s["branch_id"], "product_id": s["product_id"], "ds": s["ds"],
        "quantity": s["units"], "unit_price": s["net_amount"] / units, "discount_amount": s["discount_amount"], "net_amount": s["net_amount"],
        "unit_cost": 4.0, "is_return": False, "document_type": "invoice",
        "known_from": s["ds"] + pd.to_timedelta(np.where(late, 5, 0), unit="D") + pd.Timedelta(hours=17), "known_until": pd.NaT})
    versions = SalesVersions.from_lines(lines, "America/Guayaquil", last)
    assert versions.has_dynamic
    return replace(data, sales_versions=versions, sales=versions.sales_cells(last), sales_knowledge=versions.summary())


# ───────────────────────── qué factores entran: disponibilidad y activación ─────────────────────────

def hand_inputs(rows: dict[str, tuple[dict, pd.DataFrame]]) -> FactorInputs:
    return FactorInputs(keys=sorted(rows), definitions={k: d for k, (d, _) in rows.items()}, observations={k: o for k, (_, o) in rows.items()})


def definition(key: str, cls: str, storage: str = "external_indicator_observations", **over) -> dict:
    base = {"id": None, "tenant_id": None, "family": "competition", "key": key, "name": f"Factor ficticio {key}", "availability_class": cls,
            "storage": storage, "external_indicator_id": None, "unit": None, "frequency": "daily", "geo_scope": "country", "source_key": None,
            "license": None, "missing_strategy": "disable_feature", "max_staleness_days": None, "retention_days": None, "schema": {},
            "is_active": False, "is_informative_alert": False}
    return {**base, **over}


def test_unusable_factors_are_dropped_with_their_reason_and_never_reach_the_model(demo):
    """Pronóstico sin historial de emisiones, disponibilidad desconocida, antigüedad vencida, escenario del usuario y almacenamiento ilegible:
    ninguno entra; el respaldo es el modelo sin el factor y la limitación lo explica."""
    last = demo.data.cutoff
    days = pd.date_range(pd.Timestamp(last) - pd.Timedelta(days=200), pd.Timestamp(last), freq="D")
    daily = pd.DataFrame({"id": range(len(days)), "period_date": days, "value": np.linspace(0, 1, len(days)), "revision": 0,
                          "published_at": days + pd.Timedelta(days=1)})
    no_dates = daily.assign(published_at=pd.NaT, ingested_at=pd.NaT, available_at=pd.NaT)
    old = daily.iloc[:60]          # la fuente se cayó hace 140 días
    inputs = hand_inputs({
        "f.forecast": (definition("f.forecast", "THIRD_PARTY_FORECAST"), daily.assign(issued_at=pd.NaT)),
        "f.unknown": (definition("f.unknown", "OBSERVED_HISTORICAL"), no_dates),
        "f.stale": (definition("f.stale", "OBSERVED_HISTORICAL", max_staleness_days=5), old),
        "f.scenario": (definition("f.scenario", "USER_SCENARIO"), daily),
    })
    # Fallos de lectura de la base (p. ej. tabla ausente): la definición o las observaciones ilegibles dejan el factor fuera, no abortan la demanda.
    inputs.definitions["f.broken_definition"] = FactorReadError("UndefinedTable: relation does not exist")
    inputs.definitions["f.broken_data"] = definition("f.broken_data", "OBSERVED_HISTORICAL")
    inputs.observations["f.broken_data"] = FactorReadError("UndefinedTable: relation does not exist")
    # L1: una definición MALFORMADA (clase, estrategia o antigüedad inválidas) saca solo a ese factor con `invalid_definition`; no aborta la demanda.
    for key, bad in (("f.bad_class", {"availability_class": "FOO"}), ("f.bad_strategy", {"missing_strategy": "whatever"}), ("f.neg_stale", {"max_staleness_days": -3})):
        inputs.definitions[key] = definition(key, "OBSERVED_HISTORICAL", **bad)
        inputs.observations[key] = daily
    inputs.keys = sorted([*inputs.keys, "f.broken_definition", "f.broken_data", "f.bad_class", "f.bad_strategy", "f.neg_stale"])
    activation = {k: {"active": True} for k in inputs.keys}
    data = replace(demo.data, settings={**DEFAULT_SETTINGS, "cores": {"factors": {"enabled": True}}, "factor_activation": activation},
                   factor_inputs=inputs)
    _, _, outcome = prepare(data)
    dropped = {d["key"]: d["reason"] for d in outcome.resolution.dropped}
    assert dropped == {"f.forecast": "no_issuance_history", "f.unknown": "unknown_availability", "f.stale": "stale",
                       "f.broken_definition": "definition_not_found", "f.broken_data": "storage_unreadable",
                       "f.bad_class": "invalid_definition", "f.bad_strategy": "invalid_definition", "f.neg_stale": "invalid_definition"}
    assert outcome.resolution.scenario_only == ["f.scenario"] and outcome.resolution.used == [] and outcome.arm is None
    assert outcome.reason == "no_usable_factors" and outcome.decision == NOT_EVALUATED
    assert [x["code"] for x in outcome.limitations] == ["factors_dropped"]
    assert all(k in outcome.limitations[0]["message"] for k in dropped)


def test_the_run_resolves_factors_with_the_retroactive_rule_even_when_the_final_cutoff_is_live(demo):
    """Una ejecución vigente aceptaría disponibilidad desconocida; el cableado NO: el modelo final ve lo mismo que demostró el backtest."""
    days = pd.date_range(pd.Timestamp(demo.data.cutoff) - pd.Timedelta(days=200), pd.Timestamp(demo.data.cutoff), freq="D")
    unknown = pd.DataFrame({"id": range(len(days)), "period_date": days, "value": 1.0, "revision": 0,
                            "published_at": pd.NaT, "ingested_at": pd.NaT, "available_at": pd.NaT})
    inputs = hand_inputs({"f.unknown": (definition("f.unknown", "OBSERVED_HISTORICAL"), unknown)})
    live = replace(demo.data, settings={**DEFAULT_SETTINGS, "cores": {"factors": {"enabled": True}}, "factor_activation": {"f.unknown": {"active": True}}},
                   factor_inputs=inputs, promotions_known_on=demo.data.cutoff)        # ejecución vigente (conoce hasta hoy)
    _, _, outcome = prepare(live)
    assert outcome.resolution.used == [] and [d["reason"] for d in outcome.resolution.dropped] == ["unknown_availability"]


def test_a_factor_needs_both_the_company_switch_and_its_own_activation(demo):
    """Sin el interruptor, o sin la activación del factor, no se resuelve nada (y no se tocan los datos)."""
    cases = {
        "switch_off_activation_on": {"factor_activation": ACTIVATION},
        "switch_on_no_activation": {"cores": {"factors": {"enabled": True}}},
        "switch_on_activation_false": {"cores": {"factors": {"enabled": True}}, "factor_activation": {CAMPAIGN_KEY: {"active": False}}},
        "switch_on_malformed_activation": {"cores": {"factors": {"enabled": True}}, "factor_activation": {CAMPAIGN_KEY: "si"}},
    }
    for name, settings in cases.items():
        _, _, outcome = prepare(with_settings(demo, settings))
        if name == "switch_off_activation_on":
            assert outcome is None, name
        else:
            assert outcome is not None and outcome.arm is None and outcome.reason == "no_active_factors" and outcome.used == [], name
    # Solo el factor activado entra.
    _, _, outcome = prepare(with_settings(demo, {"cores": {"factors": {"enabled": True}}, "factor_activation": {CAMPAIGN_KEY: {"active": True}}}))
    assert outcome.active == [CAMPAIGN_KEY] and outcome.used == [CAMPAIGN_KEY]
    assert arm_of(outcome).features.factors.feature_columns == [f"factor__{CAMPAIGN_KEY}"]


def test_enabled_and_active_but_without_loaded_data_nothing_enters_and_the_run_says_so(demo):
    _, _, outcome = prepare(with_settings(demo, ON, inputs=False))
    assert outcome.arm is None and outcome.reason == "inputs_not_loaded" and outcome.used == []
    assert [x["code"] for x in outcome.limitations] == ["factors_inputs_not_loaded"]


def test_the_arm_rule_is_configured_from_the_factors_block_with_validated_ranges():
    for key, bad in (("arm_min_improvement", 0.9), ("arm_windows_won_fraction", 0.4), ("arm_min_windows", 0)):
        assert [e["code"] for e in validate_overrides("factors", {key: bad})] == ["out_of_range"], key
    assert validate_overrides("factors", {"arm_min_improvement": 0.05, "arm_windows_won_fraction": 0.75, "arm_min_windows": 3}) == []


# ───────────────────────── el pipeline completo: paridad, competencia y registro ─────────────────────────

class OriginSpy:
    """Registra, por ejecución, los orígenes que `compete` pide al entrenar el brazo con factores (para probar que el período final no decide)."""

    def __init__(self, monkeypatch):
        import predictily_engine.selection.tuning as tuning

        self.label = "?"
        self.calls: dict[str, list[list[pd.Timestamp]]] = {}
        real = tuning.lightgbm_backtest_rows

        def spy(panel, features, plan, windows, params, seed, n_jobs, origins=None):
            self.calls.setdefault(self.label, []).append([pd.Timestamp(o) for o in (origins if origins is not None else [])])
            return real(panel, features, plan, windows, params, seed, n_jobs, origins=origins)

        monkeypatch.setattr(tuning, "lightgbm_backtest_rows", spy)


@pytest.fixture(scope="module")
def runs(demo):
    """Seis ejecuciones sobre los MISMOS datos ficticios: apagado por defecto (A), activación sin interruptor (B), interruptor sin activación (C),
    factores activos que NO superan la regla (D, exigiendo 50 % de mejora), factores activos de los que gana la campaña (E) y lo mismo con los valores del
    factor de ruido PERTURBADOS (E2: el caso e3 de la revisión)."""
    monkeypatch = pytest.MonkeyPatch()
    spy = OriginSpy(monkeypatch)
    out = {}

    def run(name, settings, inputs=True, data=None):
        spy.label = name
        out[name] = run_pipeline(data if data is not None else with_settings(demo, settings, inputs), horizon=30, seed=42, n_jobs=2)

    run("A", {}, inputs=False)
    run("B", {"factor_activation": ACTIVATION})
    run("C", {"cores": {"factors": {"enabled": True}}})
    run("D", {"cores": {"factors": {"enabled": True, "arm_min_improvement": 0.5, "arm_placebo_arms": 2}}, "factor_activation": ACTIVATION})
    run("E", ON2)
    perturbed = {k: v.copy() for k, v in demo.inputs.observations.items()}
    perturbed[NOISE_KEY]["value"] = np.random.default_rng(5).normal(size=len(perturbed[NOISE_KEY]))
    run("E2", ON2, data=replace(with_settings(demo, ON2), factor_inputs=FactorInputs(keys=demo.inputs.keys, definitions=demo.inputs.definitions,
                                                                                  observations=perturbed)))
    yield out, spy.calls
    monkeypatch.undo()


def test_with_the_pipeline_off_the_run_is_exactly_the_one_of_an_engine_without_factors(runs):
    """Paridad exacta: sin el interruptor (aunque haya activación y datos cargados) y con el interruptor sin activación, lo servido y evaluado es idéntico."""
    out, spies = runs
    a = out["A"]
    assert a.factors == {} and "factors" not in a.summary and "factors" not in a.manifest and "factors" not in a.effective_config and a.factor_compat is None
    for name in ("B", "C"):
        r = out[name]
        pd.testing.assert_frame_equal(day_values(a), day_values(r), check_exact=True)
        pd.testing.assert_frame_equal(a.forecast_values, r.forecast_values, check_exact=True)
        assert json.dumps(a.evaluations, default=str, sort_keys=True) == json.dumps(r.evaluations, default=str, sort_keys=True)
        pd.testing.assert_frame_equal(a.recommendations, r.recommendations, check_exact=True)
        pd.testing.assert_frame_equal(a.backtest, r.backtest, check_exact=True)
        assert r.selection.winners == a.selection.winners and r.lgbm is not None
        assert name not in spies, "apagado o sin activación: el brazo con factores ni se entrena"
    assert out["B"].factors == {} and out["B"].config_version == a.config_version, "apagado: la huella de la ejecución es la de siempre"
    assert out["B"].snapshot.hash == a.snapshot.hash and out["B"].training_data_hash == a.training_data_hash, "apagado: la huella de datos es la de siempre"
    assert out["C"].factors["reason"] == "no_active_factors" and out["C"].factors["used_factors"] == [] and "no tiene ningún factor activado" in out["C"].factors["message"]
    assert out["C"].config_version != a.config_version and out["C"].effective_config["factors"]["active_factors"] == []


def test_active_factors_that_do_not_beat_the_rule_change_nothing_that_is_served(runs):
    """Activar no cambia lo servido mientras el modelo con factores no gane: con la regla exigente (50 %) se mantiene el modelo sin factores y todo
    lo servido y evaluado es idéntico, bit a bit, a la ejecución sin factores."""
    out, spies = runs
    a, d = out["A"], out["D"]
    assert d.factors["decision"] == KEPT and d.factors["reason"] == "no_sufficient_improvement" and d.factors["factor_model_served"] is False
    assert d.factors["used_factors"] == [CAMPAIGN_KEY, NOISE_KEY] and d.factors["selected_factors"] == [] and d.factors["evidence"]["passes_improvement"] is False
    pd.testing.assert_frame_equal(a.forecast_values, d.forecast_values, check_exact=True)
    assert json.dumps(a.evaluations, default=str, sort_keys=True) == json.dumps(d.evaluations, default=str, sort_keys=True)
    pd.testing.assert_frame_equal(a.backtest, d.backtest, check_exact=True)
    assert "factor_model_not_selected" in [x["code"] for x in d.limitations] and d.factors["holdout_evaluated_after_decision"] is False
    assert d.training_data_hash == a.training_data_hash and d.factor_compat is None, "sin modelo con factores servido, el hash de entrenamiento y el registro son los de siempre"
    # El período final reservado NO se tocó: cada factor solo se entrenó en las ventanas de selección (y, al no pasar la regla, sin placebos).
    plan = d.plan
    assert len(spies["D"]) == 2 and all(c == list(plan.cutoffs) for c in spies["D"]) and plan.holdout_cutoff not in sum(spies["D"], [])


def test_the_campaign_wins_on_its_own_and_the_noise_is_left_out_by_forward_selection(runs):
    out, spies = runs
    a, e = out["A"], out["E"]
    f = e.factors
    assert f["decision"] == SELECTED and f["reason"] == "improvement_and_windows" and f["factor_model_served"] is True
    assert f["used_factors"] == [CAMPAIGN_KEY, NOISE_KEY] and f["selected_factors"] == [CAMPAIGN_KEY], "selección hacia delante: solo pasa el factor con efecto"
    assert f["per_factor"][CAMPAIGN_KEY]["decision"] == SELECTED and f["per_factor"][NOISE_KEY]["decision"] == KEPT and f["combination"] is None
    ev = f["evidence"]
    assert ev["passes_improvement"] and ev["passes_windows"] and ev["wape_factor"] < ev["wape_plain"] * (1 - 0.03)
    assert ev["windows_better"] >= ev["windows_needed"] and ev["windows_total"] >= 2 and ev["scope"] == "selection_windows"
    placebo = ev["placebo"]
    assert placebo["passed"] is True and placebo["evaluated"] == 2 and placebo["best_placebo_wape"] > ev["wape_factor"] and len(placebo["arms"]) == 2
    assert f["holdout_used_for_duel"] is False and f["holdout_evaluated_after_decision"] is True
    # Orígenes pedidos: cada factor y sus placebos SOLO en las ventanas de selección; el período final al final, solo del ganador.
    plan = e.plan
    calls = spies["E"]
    assert calls[-1] == [plan.holdout_cutoff] and all(c == list(plan.cutoffs) for c in calls[:-1]) and len(calls) == 1 + 2 + 1 + 1, "campaña, 2 placebos, ruido y el período final"
    # Lo servido cambia (el modelo servido es el que usa la campaña) y el modelo entrenado recibió SOLO la columna ganadora.
    assert not np.allclose(day_values(a)["yhat"].to_numpy(), day_values(e)["yhat"].to_numpy())
    names = e.lgbm.mlf.models_["lightgbm_global"].booster_.feature_name()
    assert f"factor__{CAMPAIGN_KEY}" in names and f"factor__{NOISE_KEY}" not in names
    assert sorted(a.lgbm.mlf.models_["lightgbm_global"].booster_.feature_name()) == sorted(n for n in names if not n.startswith("factor__"))
    # Registro: resumen, manifiesto (con contratos de columna y estado de cada factor), limitaciones y reporte del período final (solo evidencia).
    assert e.summary["factors"] == f and e.manifest["factors"]["decision"] == SELECTED
    contracts = {c["name"]: c for c in e.manifest["factors"]["feature_contracts"]}
    assert contracts[f"factor__{CAMPAIGN_KEY}"]["availability_class"] == "KNOWN_IN_ADVANCE"
    assert contracts[f"factor__{NOISE_KEY}"]["availability_class"] == "OBSERVED_HISTORICAL"
    assert e.manifest["factors"]["resolution"]["used"] == [CAMPAIGN_KEY, NOISE_KEY] and f["retroactive_visibility"] is True and f["served_block_days"] == plan.horizon
    assert f["holdout_comparison"]["report_only"] is True and f["holdout_comparison"]["wape_plain"] is not None and f["holdout_comparison"]["wape_factor"] is not None
    if f["holdout_comparison"]["factor_better"] is False:
        assert "factor_model_worse_on_holdout" in [x["code"] for x in e.limitations]
    assert f["holdout_used_for_decision"] is True and f["governing_policy"] == "legacy", "la política heredada elige con TODAS las ventanas, período final incluido: el flag lo dice"
    assert "factor_model_not_selected" not in [x["code"] for x in e.limitations]
    # M1: el factor de clima vence casi todo el horizonte: se registra la fracción y la antigüedad máxima, con una limitación.
    stale = f["factor_stats"][NOISE_KEY]
    assert stale["future_stale_fraction"] > 0.9 and stale["future_max_age_days"] > stale["max_staleness_days"] and "factors_stale" in [x["code"] for x in e.limitations]
    assert e.effective_config["factors"]["active_factors"] == [CAMPAIGN_KEY, NOISE_KEY] and e.config_version != a.config_version
    json.dumps(e.summary["factors"]), json.dumps(e.manifest["factors"], default=str)        # serializable
    overall = [r for r in e.evaluations if r["level"] == "overall" and r["model_family"] == "selection" and r["evaluation_type"] == "backtest"]
    assert len(overall) == 1 and overall[0]["is_selected"]


def test_the_data_lineage_changes_with_the_factor_data_and_the_served_model_carries_it(runs):
    """H2: con factores encendidos la huella de datos cambia con los datos de los factores (aunque el factor no se use) y, si se sirve el modelo con factores,
    entra a `training_data_hash` y al registro (versión de features y compatibilidad del artefacto). Con factores apagados, nada cambia."""
    out, _ = runs
    a, d, e, e2 = out["A"], out["D"], out["E"], out["E2"]
    assert a.snapshot.hash != e.snapshot.hash and a.snapshot.hash != d.snapshot.hash, "los factores resueltos son parte de los datos de la ejecución"
    assert e.snapshot.components["external_indicators"] != a.snapshot.components["external_indicators"]
    assert e.snapshot.hash != e2.snapshot.hash, "otros valores del factor de ruido: otra huella de datos, aunque ese factor no se sirva"
    assert e.summary["data_snapshot_hash"] == e.snapshot.hash != e2.summary["data_snapshot_hash"]
    assert e.training_data_hash == e2.training_data_hash, "el factor de ruido no se sirve: no entra al hash de entrenamiento del modelo servido (solo a la huella de datos)"
    assert e.training_data_hash != a.training_data_hash, "se sirve un modelo con factores: su hash de entrenamiento incluye la huella de los factores"
    assert d.training_data_hash == a.training_data_hash and d.factor_compat is None
    compat = e.factor_compat
    assert compat["keys"] == [CAMPAIGN_KEY] and compat["columns"] == [f"factor__{CAMPAIGN_KEY}"] and compat["fingerprint"] == e.factors["data_fingerprint"]
    assert compat["definition_versions"][CAMPAIGN_KEY] == e.factors["definition_versions"][CAMPAIGN_KEY] and compat["served_block_days"] == e.plan.horizon
    from predictily_engine.config import FEATURE_SET_VERSION
    from predictily_engine.pipeline.forecast_run import factor_registry_fields

    fsv, registered = factor_registry_fields(e, "lightgbm_global")
    assert fsv == f"{FEATURE_SET_VERSION}+factors:{compat['fingerprint'][:12]}" and len(fsv) <= 40 and registered == compat
    assert factor_registry_fields(e, "seasonal_naive") == (FEATURE_SET_VERSION, None), "solo el LightGBM servido con factores lleva el linaje"
    assert factor_registry_fields(a, "lightgbm_global") == (FEATURE_SET_VERSION, None) and factor_registry_fields(d, "lightgbm_global") == (FEATURE_SET_VERSION, None)


def test_the_ablation_tool_and_the_pipeline_reach_the_same_evidence_for_the_same_factor(demo, runs):
    """La herramienta de ablation y el pipeline usan la MISMA regla, el MISMO entrenamiento y las MISMAS filas: para el mismo factor, la misma evidencia."""
    from predictily_engine.backtest import factor_ablation

    out, _ = runs
    report = factor_ablation.run_ablation(with_settings(demo, ON2), factor_sets=[[CAMPAIGN_KEY]], seed=42, n_jobs=2)
    [entry] = report["sets"]
    mine, pipeline = entry["evidence"], out["E"].factors["evidence"]
    for key in ("wape_plain", "wape_factor", "denominator", "windows_total", "windows_better", "windows_needed", "relative_improvement", "rows_compared"):
        assert mine[key] == pytest.approx(pipeline[key]), key
    assert mine["placebo"]["best_placebo_wape"] == pytest.approx(pipeline["placebo"]["best_placebo_wape"]), "los mismos placebos (misma semilla y desplazamientos)"
    assert (entry["decision"] == "promote") == (out["E"].factors["decision"] == SELECTED)
    assert report["plan"]["selection_origins"] == [c.date().isoformat() for c in out["E"].plan.cutoffs]


# ───────────────────────── ventanas as-of reconstruidas + factores, de punta a punta ─────────────────────────

def test_the_pipeline_with_late_documents_trains_the_factor_arm_on_each_rebuilt_window(demo, monkeypatch):
    data = versioned_data(with_settings(demo, {"cores": {"factors": {"enabled": True, "arm_placebo_arms": 1}}, "factor_activation": ACTIVATION}))
    seen = {"fits": []}
    real_fit = ml_module.LightGBMGlobal.fit

    def spy_fit(self, train):
        seen["fits"].append(tuple(c for c in train.columns if c.startswith("factor__")))
        return real_fit(self, train)

    monkeypatch.setattr(ml_module.LightGBMGlobal, "fit", spy_fit)
    result = run_pipeline(data, horizon=30, seed=42, n_jobs=2, max_windows=2)
    assert result.manifest["knowledge"]["as_of_windows_active"] is True
    assert any(w["panel_rebuilt"] for w in result.manifest["knowledge"]["windows"]), "alguna ventana se reconstruyó con lo conocido en su corte"
    campaign, noise = f"factor__{CAMPAIGN_KEY}", f"factor__{NOISE_KEY}"
    assert seen["fits"].count(()) >= 2 and seen["fits"].count((campaign,)) >= 2 and seen["fits"].count((noise,)) >= 2, \
        "el brazo sin factores y el de cada factor entrenan en cada ventana de selección"
    assert result.factors["decision"] in (SELECTED, KEPT) and result.factors["used_factors"] == [CAMPAIGN_KEY, NOISE_KEY]
    assert result.factors["per_factor"][CAMPAIGN_KEY]["windows_total"] == 2
    if result.factors["decision"] == SELECTED:       # ganó: el entrenamiento final usa el constructor con el factor ganador
        assert seen["fits"][-1] == tuple(f"factor__{k}" for k in result.factors["selected_factors"])
