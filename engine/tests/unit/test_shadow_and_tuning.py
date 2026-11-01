"""W3-B · champion/challenger, shadow mode y Optuna acotado (ADR-V25-21), sobre el pipeline completo de la demo y con datos sintéticos.

- Shadow: el challenger es el mejor candidato no servido de cada clase; sus predicciones no se sirven (lo publicado es idéntico con shadow
  apagado), respetan el tope de series y no usan el período final reservado para elegirse.
- Optuna: opcional (sin él, modo no disponible con mensaje explícito), acotado (n_trials, timeout, parada temprana, CPU) y sin tocar el
  período final reservado (guarda en cada prueba + registro de los orígenes usados en el manifiesto).
"""

from __future__ import annotations

import importlib.util
import time
from types import SimpleNamespace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.rolling import BacktestPlan, plan_backtest, run_backtest, series_scale
from predictily_engine.demand import segments as w3a_segments
from predictily_engine.demand.manifest import verify
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.models.registry import CANDIDATES_BY_CLASS
import predictily_engine.pipeline.core as pipeline_core
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import config_hash
from predictily_engine.platform.config import effective_config_record
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.selection import shadow as shadow_mod
from predictily_engine.selection import promotion as promo_mod
from predictily_engine.selection import tuning
from predictily_engine.selection.selector import row_classes

OPTUNA_MISSING = "Optuna no está instalado en este entorno: se omiten las pruebas del ajuste real (el modo queda no disponible con mensaje explícito)"
needs_optuna = pytest.mark.skipif(importlib.util.find_spec("optuna") is None, reason=OPTUNA_MISSING)


def promotion(**overrides):
    return effective_config_record("promotion", None, None, overrides)


@pytest.fixture(scope="module")
def variants(small_data):
    """Misma empresa con otras configuraciones de promoción (la ejecución por defecto es `pipeline_result`)."""
    def run(**overrides):
        return run_pipeline(small_data, horizon=90, seed=42, n_jobs=4, promotion_record=promotion(**overrides))

    return {"noshadow": run(shadow_enabled=False), "cap3": run(shadow_max_series=3), "v25": run(governing_policy="v25")}


# ───────────────────────────── segmentos críticos: proveedor de W3-A ─────────────────────────────

def global_v25(result):
    return next(d for d in result.promotion.decisions if d.policy == "v25" and d.scope_key == "global")


@pytest.fixture(scope="module")
def provider_runs(small_data):
    """Misma demo con las métricas por segmento apagadas (sin proveedor de W3-A) y con un proveedor explícito."""
    off = effective_config_record("demand", small_data.settings, small_data.sector, tenant_overrides={"segment_metrics_enabled": False})
    explicit = lambda r: pd.DataFrame({"demand_class": r["demand_class"], "volume_tier": "rest", "category": "unica"}, index=r.index)    # noqa: E731
    return {"off": run_pipeline(small_data, horizon=90, seed=42, n_jobs=4, config_record=off),
            "explicit": run_pipeline(small_data, horizon=90, seed=42, n_jobs=4, segment_provider=explicit,
                                     promotion_record=promotion(v25_critical_segments=["demand_class", "volume_tier", "category"]))}


def test_the_segment_gate_uses_the_w3a_provider_by_default(pipeline_result):
    """Los segmentos críticos del gate son los de las métricas por segmento de W3-A (volumen y vida AS-OF en el corte de cada ventana)."""
    gate = global_v25(pipeline_result).gates["segment_regression"]
    assert gate["evidence"]["provider"] == promo_mod.W3A_SEGMENT_SOURCE
    # se recalcula el gate con el proveedor de W3-A sobre las mismas filas y da la MISMA evidencia: el cableado es real
    bt, profile = pipeline_result.backtest, pipeline_result.panel.profile
    cfg = w3a_segments.SegmentConfig.from_config(pipeline_result.effective_config["config"])
    rows = promo_mod._prepare(bt, profile, only_selection=True)
    cat = {}   # categoría por producto no interviene en las dimensiones críticas por defecto
    provider = w3a_segments.make_provider(bt, pipeline_result.panel, None, pd.Series(cat, dtype=float), cfg)
    metrics = global_v25(pipeline_result).metrics
    again = promo_mod.gate_segments(rows, metrics["candidate"]["family"], metrics["reference"]["family"], pipeline_result.promotion.config.v25,
                                    provider, promo_mod.W3A_SEGMENT_SOURCE)
    assert again["status"] == gate["status"] and again["evidence"]["evaluated"] == gate["evidence"]["evaluated"]
    # y NO es la definición por defecto (tramo de volumen con las unidades del propio período evaluado): difiere en alguna fila
    default_tier = promo_mod.default_segments(rows)["volume_tier"].to_numpy()
    w3a_tier = provider(rows)["volume_tier"].to_numpy()
    assert (default_tier != w3a_tier).any(), "el volumen as-of por corte no coincide con el del período evaluado"


def test_without_w3a_metrics_or_with_an_explicit_provider_the_gate_says_which_one_it_used(provider_runs, pipeline_result):
    off, explicit = provider_runs["off"], provider_runs["explicit"]
    assert global_v25(off).gates["segment_regression"]["evidence"]["provider"] == promo_mod.DEFAULT_SEGMENT_SOURCE
    gate = global_v25(explicit).gates["segment_regression"]
    assert gate["evidence"]["provider"] == "proveedor entregado a run_pipeline"
    assert {e["dimension"] for e in gate["evidence"]["evaluated"]} == {"demand_class", "volume_tier", "category"}
    assert {e["segment"] for e in gate["evidence"]["evaluated"] if e["dimension"] == "category"} == {"unica"}
    # lo servido no cambia con el proveedor (solo el registro de la sombra de decisión)
    assert off.selection.winners == explicit.selection.winners == pipeline_result.selection.winners
    pd.testing.assert_frame_equal(off.forecast_values.reset_index(drop=True), pipeline_result.forecast_values.reset_index(drop=True))


# ───────────────────────────── trazabilidad: huella, criterio y lo realmente servido ─────────────────────────────

def test_the_promotion_policy_is_part_of_the_run_fingerprint_and_the_criterion_is_generated(pipeline_result, variants):
    eff = pipeline_result.effective_config
    assert eff["promotion"]["governing_policy"] == "legacy" and eff["promotion"]["policy_versions"] == pipeline_result.promotion.config.policy_versions()
    assert eff["promotion"]["core_config_version"] == effective_config_record("promotion", None, None)["config_version"]
    assert pipeline_result.config_version == config_hash(eff), "la huella de la ejecución incluye la política de promoción"
    other = variants["v25"]
    assert other.config_version != pipeline_result.config_version and other.effective_config["promotion"]["governing_policy"] == "v25"
    crit = pipeline_result.manifest["selection"]["criterion"]
    assert "⌈n × 0.6667⌉" in crit and "(con 4 ventanas: 3)" in crit and "≥ 2/3" not in crit
    assert variants["v25"].manifest["selection"]["criterion"].startswith("política de promoción V2.5")


@pytest.fixture(scope="module")
def failed_run(small_data, pipeline_result):
    """Entrenamiento FINAL de LightGBM forzado a fallar (el backtest funciona): se sirve el respaldo, no la familia planeada."""
    final_cutoff = pd.Timestamp(small_data.cutoff)

    class FailingFinalFit(LightGBMGlobal):
        def fit(self, train):
            if train["ds"].max() >= final_cutoff:
                raise RuntimeError("fallo forzado del entrenamiento final")
            return super().fit(train)

    mp = pytest.MonkeyPatch()
    mp.setattr(pipeline_core, "LightGBMGlobal", FailingFinalFit)
    try:
        return run_pipeline(small_data, horizon=90, seed=42, n_jobs=1)
    finally:
        mp.undo()


def test_decisions_record_the_family_really_served_after_a_final_training_fallback(failed_run, pipeline_result):
    r, promo = failed_run, failed_run.promotion
    assert set(r.failed_families) == {"lightgbm_global"} and promo.fallback_applied
    effective = {d.scope_key: d for d in promo.decisions if d.is_effective}
    for cls, fam in r.selection.winners.items():
        key = "global" if cls == "pooled" else f"class:{cls}"
        assert effective[key].served_family == fam, "el registro dice lo realmente servido"
    changed = [d for d in effective.values() if d.metrics.get("fallback_applied")]
    assert changed and all(d.metrics["planned_family"] == "lightgbm_global" and d.served_family == r.selection.fallback for d in changed)
    assert {(f["scope_key"], f["planned_family"]) for f in r.summary["promotion"]["fallback_applied"]} == {(d.scope_key, "lightgbm_global") for d in changed}
    assert all(row["metrics"]["served_family"] == r.selection.fallback for row in promo.rows() if row["is_effective"] and row["metrics"].get("fallback_applied"))
    assert [d["served"] for d in r.manifest["promotion"]["decisions"] if d["is_effective"] and d["scope_key"] in {c.scope_key for c in changed}] == \
        [r.selection.fallback] * len(changed)
    assert not pipeline_result.promotion.fallback_applied, "sin fallo no se reconcilia nada"
    assert "lightgbm_global" not in set(r.shadow["model_family"]), "el modelo que falló tampoco se usa de challenger"


# ───────────────────────────── shadow ─────────────────────────────

def test_shadow_never_changes_what_is_served(pipeline_result, variants):
    """Con shadow apagado, todo lo publicado es idéntico: el challenger solo agrega filas aparte."""
    off = variants["noshadow"]
    assert off.shadow.empty and pipeline_result.shadow is not None and not pipeline_result.shadow.empty
    pd.testing.assert_frame_equal(pipeline_result.forecast_values.reset_index(drop=True), off.forecast_values.reset_index(drop=True))
    pd.testing.assert_frame_equal(pipeline_result.recommendations.reset_index(drop=True), off.recommendations.reset_index(drop=True))
    assert pipeline_result.selection.winners == off.selection.winners
    assert pipeline_result.summary["models"] == off.summary["models"]
    assert pipeline_result.summary["metrics"]["selected_wape"] == off.summary["metrics"]["selected_wape"]
    assert off.summary["promotion"]["shadow"]["enabled"] is False and off.summary["promotion"]["shadow"]["rows"] == 0


def test_shadow_rows_are_unserved_series_level_predictions_of_the_challenger(pipeline_result):
    sh, fv = pipeline_result.shadow, pipeline_result.forecast_values
    assert set(sh["level"]) == {"series"} and (sh["horizon_step"].between(1, 90)).all()
    assert (pd.to_datetime(sh["target_date"]) > pd.Timestamp(pipeline_result.cutoff)).all()
    assert not sh.duplicated(["model_family", "node_key", "target_date"]).any(), "clave única del contrato"
    assert (sh["yhat"] >= 0).all()
    banded = sh.dropna(subset=["lo_80", "hi_80"])
    assert len(banded) > 0 and (banded["lo_80"] <= banded["yhat"] + 1e-6).all() and (banded["yhat"] <= banded["hi_80"] + 1e-6).all()
    # lo servido de cada serie es OTRO modelo: el shadow nunca es el que se publica
    served = fv[(fv["level"] == "series") & (fv["granularity"] == "day")].drop_duplicates("node_key").set_index("node_key")["model_family"]
    merged = sh.drop_duplicates("node_key").set_index("node_key").join(served.rename("served_family"))
    assert merged["served_family"].notna().all() and (merged["model_family"] != merged["served_family"]).all()
    # y las series del shadow son series publicadas (pero con otro modelo)
    assert set(sh["node_key"]) <= set(fv[fv["level"] == "series"]["node_key"].unique())


def test_the_challenger_is_the_best_unserved_candidate_by_selection_window_wape(pipeline_result):
    bt, profile = pipeline_result.backtest, pipeline_result.panel.profile
    sel_rows = bt[~bt["is_holdout"]].assign(demand_class=lambda d: row_classes(d, profile))
    sel_rows = sel_rows[~sel_rows["stockout"]]
    challengers = pipeline_result.summary["promotion"]["shadow"]["challengers"]
    assert challengers
    for ch in challengers:
        cls = ch["scope_key"].split(":")[1]
        champion = pipeline_result.selection.family_for(cls)
        sub = sel_rows[sel_rows["demand_class"] == cls]
        wape = {f: float((g["actual"] - g["yhat"]).abs().sum() / g["actual"].sum()) for f, g in sub.groupby("family")
                if f in CANDIDATES_BY_CLASS[cls] and f != champion}
        best = min(wape, key=lambda f: (wape[f], f))
        assert ch["champion"] == champion and ch["challenger"] == best != champion
        assert ch["selection_wape_challenger"] == pytest.approx(wape[best])


def test_the_final_holdout_does_not_pick_the_challenger(pipeline_result):
    """Si el período final se vuelve catastrófico para todos los candidatos, el challenger elegido no cambia."""
    bt, profile = pipeline_result.backtest, pipeline_result.panel.profile
    eligible = {c: sorted(set(bt.loc[row_classes(bt, profile) == c, "unique_id"])) for c in shadow_mod.CLASSES}
    scales = series_scale(pipeline_result.panel.df, pd.Timestamp(pipeline_result.cutoff))
    base = shadow_mod.choose_challengers(bt, profile, pipeline_result.selection, eligible, scales, 100)
    tampered = bt.copy()
    hold = tampered["is_holdout"] & (tampered["family"] != "seasonal_naive")
    tampered.loc[hold, "yhat"] = tampered.loc[hold, "actual"] * 9 + 50
    again = shadow_mod.choose_challengers(tampered, profile, pipeline_result.selection, eligible, scales, 100)
    assert [(c.scope_key, c.family) for c in base] == [(c.scope_key, c.family) for c in again] and base


def test_series_cap_keeps_the_highest_volume_series_per_scope(pipeline_result, variants):
    capped, full = variants["cap3"], pipeline_result.shadow
    per_scope = capped.shadow.groupby("scope_key")["node_key"].nunique()
    assert len(per_scope) > 0 and (per_scope <= 3).all()
    assert capped.summary["promotion"]["shadow"]["max_series_per_scope"] == 3
    scales = series_scale(capped.panel.df, pd.Timestamp(capped.cutoff))
    uid = lambda node: "b{}-p{}".format(*node.split(":")[1:])      # noqa: E731
    for scope, group in capped.shadow.groupby("scope_key"):
        universe = [uid(n) for n in full[full["scope_key"] == scope]["node_key"].unique()]       # sin tope (100 > series de la demo)
        expected = sorted(universe, key=lambda u: (-float(scales[u]), u))[:3]
        assert {uid(n) for n in group["node_key"].unique()} == set(expected), "las de mayor volumen reciente, de forma determinista"


def test_summary_and_manifest_cite_both_policies_and_the_shadow(pipeline_result):
    summary, manifest = pipeline_result.summary["promotion"], pipeline_result.manifest["promotion"]
    assert set(summary["policies"]) == {"legacy", "v25"} and summary["shadow"]["served"] is False
    assert summary["shadow"]["rows"] == len(pipeline_result.shadow) and summary["shadow"]["series"] > 0
    assert manifest["policy_versions"] == pipeline_result.promotion.config.policy_versions()
    assert manifest["shadow"]["challengers"] and manifest["holdout_used_for_model_selection_v25"] is False
    assert manifest["config"]["optuna"]["enabled"] is False, "Optuna está apagado por defecto"
    rule = manifest["legacy_windows_rule"]["pooled"]
    assert rule["windows_total"] == 4 and rule["windows_needed"] == 3, "la regla exacta de producción: 3 de 4 ventanas"
    assert manifest["holdout_used_for_model_selection_v25"] is False and manifest["holdout_used_for_hyperparameters"] is False
    assert pipeline_result.summary["promotion"]["shadow"]["elapsed_seconds"] >= 0, "costo del shadow registrado en el resumen"
    assert "elapsed_seconds" not in manifest["shadow"] and "elapsed_seconds" not in str(manifest), "el manifiesto es reproducible: sin tiempos"
    assert verify(pipeline_result.manifest) == []
    assert pipeline_result.manifest["decisions"]["model_selection_production"]["policy"] == "legacy"


class _FakeML:
    """LightGBM de mentira: registra con cuántas series se entrenó y predice un valor constante."""

    trained_on: list = []
    params: dict = {}

    def __init__(self, seed=42, n_jobs=1):
        self.params = {}

    @staticmethod
    def can_train(tr):
        return tr["unique_id"].nunique() >= 2

    def fit(self, tr):
        _FakeML.trained_on.append(sorted(tr["unique_id"].unique()))
        return self

    def predict(self, h, future):
        return future[["unique_id", "ds"]].assign(yhat=1.0, family="lightgbm_global")


class _FakeFeatures:
    calls: list = []

    def training_frame(self, cutoff, ids):
        _FakeFeatures.calls.append(("train", sorted(ids)))
        return pd.DataFrame({"unique_id": [u for u in ids for _ in range(3)], "ds": pd.Timestamp("2026-01-01")}), pd.Series(1.0, index=ids)

    def future_frame(self, cutoff, ids, h, ref):
        _FakeFeatures.calls.append(("future", sorted(ids)))
        days = pd.date_range(pd.Timestamp(cutoff) + pd.Timedelta(days=1), periods=h)
        return pd.DataFrame({"unique_id": np.repeat(sorted(ids), h), "ds": np.tile(days, len(ids))})


def _shadow_inputs(cutoff=pd.Timestamp("2026-03-01")):
    ch = shadow_mod.Challenger("class:smooth", "smooth", "lightgbm_global", "auto_ets", 0.2, 0.3, ["b1-p1", "b1-p2", "b1-p3"], 40)
    bt = pd.DataFrame(columns=["unique_id", "window", "family", "demand_class", "stockout", "actual", "yhat", "step", "scale"])
    return ch, dict(bt=bt, profile=pd.DataFrame(columns=["unique_id", "demand_class"]), train=pd.DataFrame(columns=["unique_id", "ds", "y"]),
                    features=_FakeFeatures(), full_ids=[f"b1-p{i}" for i in range(1, 41)], cutoff=cutoff, fc_horizon=5, horizon=5,
                    scales=pd.Series(1.0, index=[f"b1-p{i}" for i in range(1, 41)]), seed=1, n_jobs=1)


def test_the_shadow_lightgbm_is_trained_only_on_the_capped_series(monkeypatch):
    """Costo proporcional al tope: el LightGBM de sombra no se entrena con las 40 series de la empresa sino con las 3 acotadas, y queda anotado."""
    monkeypatch.setattr(shadow_mod, "LightGBMGlobal", _FakeML)
    _FakeML.trained_on, _FakeFeatures.calls = [], []
    ch, kw = _shadow_inputs()
    out, notes = shadow_mod.forecast_challengers([ch], lgbm=None, lgbm_ref=None, train_ml=True, **kw)
    assert _FakeML.trained_on == [["b1-p1", "b1-p2", "b1-p3"]], "entrenado solo con las series acotadas"
    assert ("train", ["b1-p1", "b1-p2", "b1-p3"]) in _FakeFeatures.calls and all(len(ids) == 3 for _, ids in _FakeFeatures.calls)
    assert [n["code"] for n in notes] == ["shadow_ml_capped_training"] and "aproxima" in notes[0]["message"]
    assert set(out["node_key"]) == {"series:1:1", "series:1:2", "series:1:3"} and set(out["model_family"]) == {"lightgbm_global"}
    # el ya entrenado por el servido se reutiliza tal cual (sin costo) y sin nota de aproximación
    _FakeML.trained_on, _FakeFeatures.calls = [], []
    served_model = _FakeML()
    out, notes = shadow_mod.forecast_challengers([ch], lgbm=served_model, lgbm_ref=pd.Series(1.0), train_ml=True, **kw)
    assert _FakeML.trained_on == [] and notes == [] and len(out) > 0
    assert all(len(ids) == 40 for kind, ids in _FakeFeatures.calls if kind == "future"), "predice con el modelo exacto (todas las series) y filtra"
    # desactivado: no se entrena nada y se dice
    _FakeML.trained_on = []
    out, notes = shadow_mod.forecast_challengers([ch], lgbm=None, lgbm_ref=None, train_ml=False, **kw)
    assert out.empty and _FakeML.trained_on == [] and [n["code"] for n in notes] == ["shadow_ml_not_trained"]


def test_the_shadow_lightgbm_is_skipped_with_a_note_when_the_capped_series_cannot_train_it(monkeypatch):
    monkeypatch.setattr(shadow_mod, "LightGBMGlobal", _FakeML)
    ch, kw = _shadow_inputs()
    ch.series = ["b1-p1"]
    out, notes = shadow_mod.forecast_challengers([ch], lgbm=None, lgbm_ref=None, train_ml=True, **kw)
    assert out.empty and [n["code"] for n in notes] == ["shadow_ml_not_trainable"]


# ───────────────────────────── V2.5 gobierna ─────────────────────────────

def test_v25_governance_serves_the_v25_decision_and_keeps_the_holdout_honest(pipeline_result, variants):
    r = variants["v25"]
    promo = r.promotion
    assert promo.governing == "v25" and r.summary["promotion"]["governing_policy"] == "v25"
    v25 = promo.by_scope("v25")
    effective = {d.scope_key for d in promo.decisions if d.is_effective}
    assert effective == {k for k, d in v25.items() if not d.informational} and all(d.policy == "v25" for d in promo.decisions if d.is_effective)
    for cls, fam in r.planned_selection.winners.items():
        key = "global" if cls == "pooled" else f"class:{cls}"
        assert fam == v25[key].served_family
    # la evaluación del período final usa la MISMA selección que producción (nada la contamina) y el protocolo sigue limpio
    assert r.evaluation_selection is not None and r.evaluation_selection.winners == r.selection.winners
    assert verify(r.manifest) == [] and r.manifest["holdout"]["independent"] is True
    production = r.manifest["decisions"]["model_selection_production"]
    assert production["policy"] == "v25" and production["windows"] == promo.windows["selection_windows"]
    assert set(production["windows"]).isdisjoint(promo.windows["holdout_windows"]), "la selección de producción no usa el período final"
    assert r.summary["metrics"]["independent_holdout"] is True and r.summary["metrics"]["selected_wape"] is not None
    # en la demo ambas políticas ven la misma evidencia de partida: los hechos son la selección servida y el valor del backtest
    assert set(promo.summary()["agreement"]).issuperset({"scopes", "same_served_model", "different"})


# ───────────────────────────── Optuna: disponibilidad y guardas ─────────────────────────────

def test_optuna_availability_is_explicit(monkeypatch):
    ok, message = tuning.optuna_status()
    if ok:
        assert message.startswith("Optuna ") and "disponible" in message
    monkeypatch.setattr(tuning, "_import_optuna", lambda: (_ for _ in ()).throw(ImportError("no module named optuna")))
    ok, message = tuning.optuna_status()
    assert ok is False and "no está instalado" in message and "pip install optuna" in message and "no es una dependencia obligatoria" in message


def test_unavailable_mode_is_reported_and_leaves_the_run_unchanged(monkeypatch, small_data, pipeline_result):
    monkeypatch.setattr(tuning, "_import_optuna", lambda: (_ for _ in ()).throw(ImportError("sin optuna")))
    r = run_pipeline(small_data, horizon=90, seed=42, n_jobs=4, promotion_record=promotion(optuna_enabled=True))
    codes = [x["code"] for x in r.limitations]
    assert tuning.UNAVAILABLE_CODE in codes
    message = next(x["message"] for x in r.limitations if x["code"] == tuning.UNAVAILABLE_CODE)
    assert "no está instalado" in message
    exp = r.promotion.tuning
    assert exp["enabled"] is True and exp["available"] is False and exp["holdout_used"] is False
    pd.testing.assert_frame_equal(r.forecast_values.reset_index(drop=True), pipeline_result.forecast_values.reset_index(drop=True))
    assert r.promotion.lgbm_params == {} and r.manifest["promotion"]["tuning"]["available"] is False


def test_holdout_guard_rejects_any_window_that_reaches_the_final_period():
    hold = pd.Timestamp("2026-05-01")
    tuning.assert_selection_windows_only([pd.Timestamp("2026-02-01"), pd.Timestamp("2026-03-01")], 30, hold)     # termina el 31-mar < 1-may
    tuning.assert_selection_windows_only([pd.Timestamp("2026-04-01")], 30, hold)                                  # termina justo en el origen: permitido
    with pytest.raises(tuning.HoldoutLeakError, match="invade el período final"):
        tuning.assert_selection_windows_only([pd.Timestamp("2026-04-02")], 30, hold)
    with pytest.raises(tuning.HoldoutLeakError):
        tuning.assert_selection_windows_only([hold], 30, hold)
    with pytest.raises(tuning.HoldoutLeakError):
        tuning.assert_selection_windows_only([pd.Timestamp("2026-06-01")], 30, hold)
    tuning.assert_selection_windows_only([hold], 30, None)       # sin período final independiente no hay nada reservado


# ───────────────────────────── Optuna: cotas del estudio (objetivo falso, sin entrenar) ─────────────────────────────

def fake_plan() -> BacktestPlan:
    holdout = pd.Timestamp("2026-06-30")
    return BacktestPlan(horizon=30, step=14, cutoffs=[holdout - pd.Timedelta(days=30 + 14 * k) for k in (2, 1, 0)], holdout_cutoff=holdout)


def patch_objective(monkeypatch, fn, seen: list | None = None):
    def fake(panel, features, plan, windows, params, seed, n_jobs, origins=None):
        if seen is not None:
            seen.append({"origins": None if origins is None else [pd.Timestamp(o) for o in origins], "n_jobs": n_jobs})
        return fn(params)

    monkeypatch.setattr(tuning, "lightgbm_backtest_rows", fake)


def rows_with_error(error: float, n: int = 20) -> pd.DataFrame:
    return pd.DataFrame({"actual": [100.0] * n, "yhat": [100.0 + error] * n, "stockout": [False] * n})


@needs_optuna
def test_tuning_is_bounded_by_n_trials(monkeypatch):
    patch_objective(monkeypatch, lambda p: rows_with_error(5.0))
    cfg = tuning.TuningConfig(enabled=True, n_trials=4, timeout_seconds=60, early_stopping_patience=50)
    res = tuning.tune_lightgbm_stage(None, None, fake_plan(), None, cfg, seed=1, n_jobs=8)
    e = res.experiment
    assert e["n_trials_run"] == 4 and e["stopped_by"] == "n_trials" and e["cpu_used"] == cfg.max_cpu == 2, "tope de CPU respetado"
    assert e["applied"] is False and res.params == {} and res.backtest_rows is None, "sin mejora se conservan los hiperparámetros de siempre"
    assert e["trials"][0]["number"] == 0 and e["trials"][0]["params"]["num_leaves"] == 31, "la primera prueba es la configuración por defecto"


@needs_optuna
def test_tuning_stops_early_when_it_stalls(monkeypatch):
    patch_objective(monkeypatch, lambda p: rows_with_error(5.0))
    cfg = tuning.TuningConfig(enabled=True, n_trials=50, timeout_seconds=60, early_stopping_patience=2)
    e = tuning.tune_lightgbm_stage(None, None, fake_plan(), None, cfg, seed=1, n_jobs=1).experiment
    assert e["stopped_by"] == "early_stopping" and e["n_trials_run"] == 3 < 50


@needs_optuna
def test_tuning_stops_at_the_timeout(monkeypatch):
    def slow(params):
        time.sleep(0.4)
        return rows_with_error(5.0)

    patch_objective(monkeypatch, slow)
    cfg = tuning.TuningConfig(enabled=True, n_trials=200, timeout_seconds=1, early_stopping_patience=100)
    t0 = time.perf_counter()
    e = tuning.tune_lightgbm_stage(None, None, fake_plan(), None, cfg, seed=1, n_jobs=1).experiment
    assert time.perf_counter() - t0 < 10 and e["stopped_by"] == "timeout" and 1 <= e["n_trials_run"] < 200


@needs_optuna
def test_tuning_only_ever_sees_selection_windows_and_applies_an_improvement(monkeypatch):
    seen: list = []
    # el error baja cuanto más cerca de 63 hojas: Optuna debe encontrar algo mejor que las 31 por defecto
    patch_objective(monkeypatch, lambda p: rows_with_error(abs(p["num_leaves"] - 63) / 4 + 1.0), seen)
    plan = fake_plan()
    cfg = tuning.TuningConfig(enabled=True, n_trials=12, timeout_seconds=60, early_stopping_patience=20, seed=7)
    res = tuning.tune_lightgbm_stage(None, None, plan, None, cfg, seed=1, n_jobs=8)
    e = res.experiment
    trial_calls, final_calls = [c for c in seen if c["origins"] is not None], [c for c in seen if c["origins"] is None]
    assert len(trial_calls) == e["n_trials_run"] and len(final_calls) == 1, "el rebacktest con lo ajustado es una sola pasada"
    for call in trial_calls:     # el período final reservado no se usa jamás en el ajuste
        assert plan.holdout_cutoff not in call["origins"] and all(o + pd.Timedelta(days=plan.horizon) <= plan.holdout_cutoff for o in call["origins"])
    assert e["holdout_used"] is False and e["origins_used"] == [c.date().isoformat() for c in plan.cutoffs]
    assert e["holdout_origin"] == plan.holdout_cutoff.date().isoformat() and e["independent_holdout"] is True
    assert e["applied"] is True and e["best_value"] < e["default_value"] and res.params == e["best_params"]
    assert final_calls[0]["n_jobs"] == e["cpu_used"] == cfg.max_cpu == 2 and all(c["n_jobs"] == 2 for c in trial_calls), "el tope de CPU rige también el rebacktest"
    assert res.backtest_rows is not None and not res.backtest_rows.empty
    assert e["seed"] == 7 and e["sampler"] == "TPESampler" and e["objective"].startswith("WAPE")


@needs_optuna
def test_a_trial_that_reaches_the_holdout_aborts_the_study(monkeypatch):
    """Si por un defecto alguna ventana de ajuste invadiera el período final, la guarda lo detiene (no se cuenta como prueba fallida)."""
    plan = fake_plan()
    bad = BacktestPlan(horizon=plan.horizon, step=plan.step, cutoffs=[*plan.cutoffs[:-1], plan.holdout_cutoff - pd.Timedelta(days=5)],
                       holdout_cutoff=plan.holdout_cutoff)
    patch_objective(monkeypatch, lambda p: rows_with_error(1.0))
    with pytest.raises(tuning.HoldoutLeakError):
        tuning.tune_lightgbm_stage(None, None, bad, None, tuning.TuningConfig(enabled=True, n_trials=3), seed=1, n_jobs=1)


@needs_optuna
def test_no_completed_trial_keeps_the_default_hyperparameters(monkeypatch):
    patch_objective(monkeypatch, lambda p: pd.DataFrame())       # sin filas: la prueba se poda
    res = tuning.tune_lightgbm_stage(None, None, fake_plan(), None, tuning.TuningConfig(enabled=True, n_trials=2), seed=1, n_jobs=1)
    assert res.experiment["applied"] is False and res.params == {} and [x["code"] for x in res.limitations] == ["optuna_no_trials"]


# ───────────────────────────── Optuna: pipeline real ─────────────────────────────

@needs_optuna
def test_lightgbm_backtest_rows_reproduce_the_pipeline_backtest(small_data):
    """La rama de LightGBM que usa el ajuste produce las MISMAS filas que `run_backtest` con los hiperparámetros por defecto."""
    rec = effective_config_record("demand", small_data.settings, small_data.sector)
    panel = build_panel(small_data, DayRules.from_config(rec["effective_config"]))
    features = FeatureBuilder(panel.df, small_data.branches, small_data.promotions, (panel.cutoff + pd.Timedelta(days=90)).date(),
                              promotion_revisions=small_data.promotion_revisions, promotions_known_on=small_data.promotions_known_on)
    plan = plan_backtest(panel, 2)
    reference = run_backtest(panel, features, plan, ["lightgbm_global"], 42, 4, True)
    reference = reference[reference["family"] == "lightgbm_global"]       # `run_backtest` sin familias estadísticas pide todas: se comparan las de LightGBM
    mine = tuning.lightgbm_backtest_rows(panel, features, plan, None, {}, 42, 4)
    key = ["window", "unique_id", "ds"]
    cols = sorted(reference.columns)
    assert sorted(mine.columns) == cols
    pd.testing.assert_frame_equal(mine[cols].sort_values(key).reset_index(drop=True), reference[cols].sort_values(key).reset_index(drop=True))
    # y solo con los orígenes pedidos
    only_selection = tuning.lightgbm_backtest_rows(panel, features, plan, None, {}, 42, 4, origins=plan.cutoffs)
    assert not only_selection["is_holdout"].any() and set(only_selection["window"]) == set(range(len(plan.cutoffs)))


@needs_optuna
def test_pipeline_with_optuna_records_the_experiment_and_never_touches_the_holdout(small_data, monkeypatch):
    seen: list = []
    real = tuning.lightgbm_backtest_rows

    def spy(panel, features, plan, windows, params, seed, n_jobs, origins=None):
        seen.append((None if origins is None else [pd.Timestamp(o) for o in origins], n_jobs))
        return real(panel, features, plan, windows, params, seed, n_jobs, origins=origins)

    monkeypatch.setattr(tuning, "lightgbm_backtest_rows", spy)
    r = run_pipeline(small_data, horizon=90, seed=42, n_jobs=4,
                     promotion_record=promotion(optuna_enabled=True, optuna_n_trials=2, optuna_timeout_seconds=240, optuna_max_cpu=1))
    e = r.promotion.tuning
    holdout = r.plan.holdout_cutoff
    assert e["enabled"] is True and e["available"] is True and e["n_trials_run"] <= 2 and e["holdout_used"] is False
    assert e["cpu_used"] == 1 and all(n_jobs == 1 for origins, n_jobs in seen), "tope de CPU (ajuste y rebacktest)"
    assert e["origins_used"] == [c.date().isoformat() for c in r.plan.cutoffs] and holdout not in r.plan.cutoffs
    assert all(holdout not in origins for origins, _ in seen if origins is not None)
    assert r.manifest["promotion"]["tuning"] == {k: v for k, v in e.items() if k != "elapsed_seconds"}, "el manifiesto no lleva tiempos"
    assert r.summary["promotion"]["tuning"] == e
    assert verify(r.manifest) == [] and r.manifest["holdout"]["used_for_hyperparameters"] is False
    assert r.manifest["promotion"]["holdout_used_for_hyperparameters"] is False
    gate = next(d for d in r.promotion.decisions if d.policy == "v25" and d.scope_key == "global").gates["no_leakage"]
    assert gate["status"] == "pass" and gate["evidence"]["tuning"]["holdout_used"] is False
    if e["applied"]:
        assert r.promotion.lgbm_params == e["best_params"]
        if r.lgbm is not None:
            assert all(r.lgbm.params[k] == v for k, v in e["best_params"].items()), "el entrenamiento final usa lo ajustado"
        assert any(origins is None for origins, _ in seen), "el backtest de LightGBM se rehízo con lo ajustado"
    else:
        assert r.promotion.lgbm_params == {} and not np.isnan(r.summary["metrics"]["selected_wape"])
