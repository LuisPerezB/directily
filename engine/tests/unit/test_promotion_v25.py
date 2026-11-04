"""W3-B · política de promoción V2.5 (ADR-V25-21): cada gate con un caso que pasa y otro que falla, publicar baseline, champion, registro de
decisiones por política y alcance con `is_effective`, y el período final reservado que nunca decide.

Los datos son sintéticos y generados aquí: números aleatorios COMUNES a todas las familias (el error absoluto de cada una es proporcional a su
desvío), de modo que las comparaciones entre familias son deterministas y cada escenario afirma una propiedad, no un número mágico.
"""

from __future__ import annotations

import json
from dataclasses import replace
from types import SimpleNamespace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.db.writer import jdump
from predictily_engine.models.registry import BASELINE_FAMILY
from predictily_engine.selection import promotion as pr
from predictily_engine.selection.promotion import (
    FAIL,
    NOT_APPLICABLE,
    NOT_EVALUABLE,
    PASS,
    PENDING,
    GateContext,
    PromotionConfig,
    V25Policy,
    champions_from_selection,
    decide_v25_scope,
    select_with_policies,
)

FAMILIES = ["seasonal_naive", "naive", "window_average", "auto_ets", "lightgbm_global"]
# El baseline (seasonal_naive) es mejor que los demás estadísticos; LightGBM es el único candidato que lo supera con holgura.
BASE_SD = {"seasonal_naive": 4.0, "naive": 4.6, "window_average": 4.2, "auto_ets": 4.4, "lightgbm_global": 2.8}


def make_rows(sd=None, windows: int = 3, n_series: int = 8, days: int = 28, seed: int = 1, big_series=(0, 1), holdout_from: int | None = None,
              cls: str = "smooth") -> pd.DataFrame:
    """Filas de backtest: `sd(familia, ventana, serie)` = desvío del error; las series `big_series` venden el doble (tramo de volumen top 20 %)."""
    sd = sd or (lambda fam, w, s: BASE_SD[fam])
    rng = np.random.default_rng(seed)
    parts = []
    for w in range(windows):
        for s in range(n_series):
            actual = (100.0 if s in big_series else 50.0) + rng.poisson(10, days)
            z = rng.normal(0, 1, days)
            for fam in FAMILIES:
                parts.append(pd.DataFrame({
                    "unique_id": f"b1-p{s}", "window": w, "step": np.arange(1, days + 1), "family": fam, "actual": actual.astype(float),
                    "yhat": np.maximum(actual + z * sd(fam, w, s), 0), "stockout": False, "scale": 10.0, "demand_class": cls,
                    "is_holdout": holdout_from is not None and w >= holdout_from}))
    return pd.concat(parts, ignore_index=True)


def ctx(**kw) -> GateContext:
    base = dict(policy=V25Policy(), independent_holdout=True, manifest_problems=[], quality_outcome="PASS", quality_reasons=[],
                signing_available=True, windows_used=[0, 1, 2])
    base.update(kw)
    return GateContext(**base)


def decide(rows: pd.DataFrame, champion: str | None = None, **kw):
    return decide_v25_scope("global", rows, sorted(rows["family"].unique()), champion, ctx(**kw))


def statuses(d) -> dict[str, str]:
    return {n: g["status"] for n, g in d.gates.items()}


# ───────────────────────────── caso base: todo pasa ─────────────────────────────

def test_candidate_that_passes_every_gate_is_promoted_with_approval_pending():
    d = decide(make_rows())
    assert d.decision == "promote" and d.candidate_family == d.served_family == "lightgbm_global"
    assert d.champion_family == BASELINE_FAMILY, "sin champion previo la referencia es el baseline"
    assert statuses(d) == {"no_leakage": PASS, "valid_data": PASS, "verifiable_artifacts": PASS, "temporal_stability": PASS,
                           "segment_regression": PASS, "metrics_documented": PASS, "improvement": PASS, "human_approval": PENDING}
    assert d.gates["human_approval"]["blocking"] is False and d.gates["human_approval"]["evidence"]["owner"] == "W4 · S06"
    assert d.gates["improvement"]["evidence"]["relative_improvement"] == pytest.approx(0.3, abs=0.03)
    assert d.metrics["holdout_used"] is False and d.metrics["windows_used"] == [0, 1, 2]
    # cada gate lleva su evidencia numérica (no es decorativo)
    cov = d.gates["metrics_documented"]["evidence"]
    assert cov["coverage_80"] == pytest.approx(0.8, abs=0.1) and cov["coverage_95"] == pytest.approx(0.95, abs=0.1) and cov["mase"] is None


# ───────────────────────────── cada gate: un caso que pasa y otro que falla ─────────────────────────────

def test_gate_improvement_over_the_reference():
    ok = decide(make_rows())
    marginal = decide(make_rows(lambda f, w, s: 3.9 if f == "lightgbm_global" else BASE_SD[f]))    # 2,5 % mejor que el baseline (< 3 %)
    assert ok.gates["improvement"]["status"] == PASS
    assert marginal.gates["improvement"]["status"] == FAIL and "mínimo 3%" in marginal.gates["improvement"]["message"]
    assert marginal.decision == "shadow" and marginal.served_family == BASELINE_FAMILY, "no se promueve: sigue el baseline y queda en shadow"


def test_improvement_threshold_tolerates_floating_point_noise_at_the_boundary():
    """R-063: un WAPE de 0,194 calculado por suma da 0,19400000000000003 y NO debe fallar el 3 % de 0,2 por 3e-17 (la V2.5 compara con tolerancia)."""
    pol = V25Policy()
    cand, ref = {"wape": 0.19400000000000003, "money_wape": None}, {"wape": 0.2, "money_wape": None}
    assert (ref["wape"] - cand["wape"]) / ref["wape"] < pol.min_relative_improvement, "sin tolerancia, la comparación exacta fallaría"
    assert pr.gate_improvement(cand, ref, pol, "baseline (x)")["status"] == PASS
    assert pr.gate_improvement({"wape": 0.1941, "money_wape": None}, ref, pol, "baseline (x)")["status"] == FAIL, "pero 2,95 % sigue sin alcanzar"


def test_gate_improvement_operational_benefit_in_money():
    pol = V25Policy()
    cand = {"wape": 0.0495, "money_wape": 0.040}
    ref = {"wape": 0.0500, "money_wape": 0.050}
    via_money = pr.gate_improvement(cand, ref, pol, "el baseline")
    assert via_money["status"] == PASS and via_money["evidence"]["passed_via"] == "operational_money"
    worse_units = pr.gate_improvement({"wape": 0.052, "money_wape": 0.040}, ref, pol, "el baseline")
    assert worse_units["status"] == FAIL, "el beneficio en dinero no compensa empeorar las unidades"
    assert pr.gate_improvement({"wape": None, "money_wape": None}, ref, pol, "x")["status"] == NOT_EVALUABLE
    assert pr.gate_improvement({"wape": 0.01, "money_wape": None}, {"wape": 0.0, "money_wape": None}, pol, "x")["status"] == FAIL


def test_gate_temporal_stability_consecutive_losses():
    def pattern(losses):
        # ventana perdida: el candidato empeora al baseline (4,4 > 4,0); ganada: 2,0
        return lambda f, w, s: (4.4 if losses[w] else 2.0) if f == "lightgbm_global" else BASE_SD[f]

    alternating = decide(make_rows(pattern([False, True, False, True]), windows=4), windows_used=[0, 1, 2, 3])
    consecutive = decide(make_rows(pattern([False, True, True, False]), windows=4), windows_used=[0, 1, 2, 3])
    assert alternating.gates["temporal_stability"]["status"] == PASS
    assert alternating.gates["temporal_stability"]["evidence"]["max_consecutive_losses"] == 1
    g = consecutive.gates["temporal_stability"]
    assert g["status"] == FAIL and g["evidence"]["max_consecutive_losses"] == 2 and g["evidence"]["losses"] == [False, True, True, False]
    assert consecutive.decision == "shadow", "falla un gate blando: no se promueve pero queda como challenger"
    relaxed = decide(make_rows(pattern([False, True, True, False]), windows=4), windows_used=[0, 1, 2, 3],
                     policy=replace(V25Policy(), max_consecutive_losing_windows=2))
    assert relaxed.gates["temporal_stability"]["status"] == PASS, "x es configurable"


def test_gate_temporal_stability_not_evaluable_with_one_window():
    d = decide(make_rows(windows=1), windows_used=[0])
    assert d.gates["temporal_stability"]["status"] == NOT_EVALUABLE
    assert d.gates["metrics_documented"]["status"] == NOT_EVALUABLE, "con una ventana no hay cobertura de intervalos medible"
    assert d.decision == "shadow" and d.served_family == BASELINE_FAMILY, "lo no certificable no se promueve"


def test_gate_segment_regression_blocks_a_material_loss_in_a_critical_segment():
    regress_top = lambda f, w, s: (4.8 if s in (0, 1) else 3.0) if f == "lightgbm_global" else BASE_SD[f]      # noqa: E731
    d = decide(make_rows(regress_top))
    g = d.gates["segment_regression"]
    assert d.gates["improvement"]["status"] == PASS, "mejora en total…"
    assert g["status"] == FAIL, "…pero empeora el tramo de volumen top 20 %"
    top = [v for v in g["evidence"]["violations"] if v["segment"] == "top20"]
    assert top and top[0]["dimension"] == "volume_tier" and top[0]["relative_regression"] == pytest.approx(0.2, abs=0.04)
    assert d.decision == "shadow" and d.served_family == BASELINE_FAMILY
    ok = decide(make_rows())
    assert ok.gates["segment_regression"]["status"] == PASS
    assert {e["segment"] for e in ok.gates["segment_regression"]["evidence"]["evaluated"] if e["dimension"] == "volume_tier"} == {"top20", "rest"}
    assert ok.gates["segment_regression"]["evidence"]["provider"] == pr.DEFAULT_SEGMENT_SOURCE, "la evidencia dice quién definió los segmentos"


def test_segments_with_little_volume_are_insufficient_data_not_a_verdict():
    d = decide(make_rows(), policy=replace(V25Policy(), segment_min_actual=1e9))
    g = d.gates["segment_regression"]
    assert g["status"] == NOT_EVALUABLE and g["evidence"]["evaluated"] == [] and len(g["evidence"]["insufficient_data"]) >= 2
    assert d.decision == "shadow"


def test_segment_provider_is_the_integration_point_for_w3a_segments():
    rows = make_rows()
    cat = lambda r: pd.DataFrame({"category": np.where(r["unique_id"].isin(["b1-p6", "b1-p7"]), "pintura", "ferreteria")},   # noqa: E731
                                 index=r.index)
    pol = replace(V25Policy(), critical_segments=("category",))
    ok = decide(rows, policy=pol, segment_provider=cat)
    assert ok.gates["segment_regression"]["status"] == PASS
    assert {e["segment"] for e in ok.gates["segment_regression"]["evidence"]["evaluated"]} == {"ferreteria", "pintura"}
    bad_rows = make_rows(lambda f, w, s: (6.0 if s in (6, 7) else 2.5) if f == "lightgbm_global" else BASE_SD[f])     # empeora solo en «pintura»
    bad = decide(bad_rows, policy=pol, segment_provider=cat)
    violation = bad.gates["segment_regression"]["evidence"]["violations"]
    assert bad.gates["segment_regression"]["status"] == FAIL and violation[0]["segment"] == "pintura"
    # una dimensión que el proveedor no entrega se declara (no se ignora en silencio)
    missing = decide(rows, policy=replace(V25Policy(), critical_segments=("series_life", "volume_tier")))
    assert missing.gates["segment_regression"]["evidence"]["dimensions_unavailable"] == ["series_life"]


def test_gate_metrics_documented_requires_calibrated_intervals():
    def miscalibrated(f, w, s):       # la última ventana es 5 veces más ruidosa: la cobertura dejando una ventana fuera se desvía del nominal
        return BASE_SD[f] * (5 if w == 2 else 1) if f == "lightgbm_global" else BASE_SD[f]

    policy = V25Policy()
    rows = make_rows(miscalibrated, windows=3)
    cand = rows[rows["family"] == "lightgbm_global"]
    bad = pr.gate_metrics_documented(rows, "lightgbm_global", pr.family_stats(cand), policy)
    assert bad["status"] == FAIL and "Cobertura fuera de tolerancia" in bad["message"]
    assert abs(bad["evidence"]["coverage_95"] - 0.95) > policy.coverage_tolerance or abs(bad["evidence"]["coverage_80"] - 0.8) > policy.coverage_tolerance
    good_rows = make_rows()
    good = pr.gate_metrics_documented(good_rows, "lightgbm_global", pr.family_stats(good_rows[good_rows["family"] == "lightgbm_global"]), policy)
    assert good["status"] == PASS and good["evidence"]["tolerance"] == 0.10
    tolerant = pr.gate_metrics_documented(rows, "lightgbm_global", pr.family_stats(cand), replace(policy, coverage_tolerance=0.5))
    assert tolerant["status"] == PASS, "la tolerancia es configurable"
    # sin denominador no hay WAPE que documentar
    zero = cand.assign(actual=0.0)
    assert pr.gate_metrics_documented(zero, "lightgbm_global", pr.family_stats(zero), policy)["status"] == NOT_EVALUABLE


def test_gate_no_leakage_passes_fails_and_is_not_evaluable():
    ok = decide(make_rows())
    assert ok.gates["no_leakage"]["status"] == PASS and ok.gates["no_leakage"]["evidence"]["holdout_rows_used"] == 0
    # caso que falla: se le entregan filas del período final → el gate lo detecta sobre las filas realmente usadas
    leaked = decide(make_rows(windows=4, holdout_from=3), windows_used=[0, 1, 2, 3])
    assert leaked.gates["no_leakage"]["status"] == FAIL and leaked.gates["no_leakage"]["evidence"]["holdout_rows_used"] > 0
    assert leaked.decision == "reject", "la fuga es un gate DURO: el candidato se rechaza"
    # un manifiesto del protocolo con violaciones también bloquea
    broken = decide(make_rows(), manifest_problems=["check holdout_disjoint_from_selection no se cumple"])
    assert broken.gates["no_leakage"]["status"] == FAIL and broken.decision == "reject"
    # sin período final independiente no se puede certificar nada
    nohold = decide(make_rows(), independent_holdout=False)
    assert nohold.gates["no_leakage"]["status"] == NOT_EVALUABLE and nohold.decision == "reject"
    assert nohold.served_family == BASELINE_FAMILY
    tuned_leak = decide(make_rows(), tuning_check={"enabled": True, "holdout_used": True})
    assert tuned_leak.gates["no_leakage"]["status"] == FAIL, "el ajuste de hiperparámetros tampoco puede usar el período final"


def test_gate_valid_data_follows_the_w2c_quality_gate():
    assert decide(make_rows(), quality_outcome="WARN").gates["valid_data"]["status"] == PASS
    assert decide(make_rows(), quality_outcome="DEGRADE").gates["valid_data"]["status"] == PASS
    blocked = decide(make_rows(), quality_outcome="BLOCK", quality_reasons=["dq_x"])
    assert blocked.gates["valid_data"]["status"] == FAIL and blocked.decision == "reject"
    unknown = decide(make_rows(), quality_outcome=None)
    assert unknown.gates["valid_data"]["status"] == NOT_EVALUABLE and unknown.decision == "reject"


def test_gate_verifiable_artifacts():
    signed = decide(make_rows())
    assert signed.gates["verifiable_artifacts"]["status"] == PASS and signed.gates["verifiable_artifacts"]["evidence"]["kind"] == "signed_artifact"
    unsigned = decide(make_rows(), signing_available=False)
    assert unsigned.gates["verifiable_artifacts"]["status"] == FAIL and unsigned.decision == "reject"
    assert unsigned.served_family == BASELINE_FAMILY
    # un candidato estadístico no tiene artefacto: se reajusta de forma determinista
    stat_only = make_rows(lambda f, w, s: 2.8 if f == "auto_ets" else (5.0 if f == "lightgbm_global" else BASE_SD[f]))
    d = decide(stat_only, signing_available=False)
    assert d.candidate_family == "auto_ets" and d.gates["verifiable_artifacts"]["status"] == NOT_APPLICABLE and d.decision == "promote"


# ───────────────────────────── baseline y champion ─────────────────────────────

def test_if_no_model_beats_the_baseline_the_baseline_is_published():
    worse = make_rows(lambda f, w, s: 6.0 if f != "seasonal_naive" else 4.0)
    d = decide(worse)
    assert d.decision == "publish_baseline" and d.candidate_family == d.served_family == BASELINE_FAMILY
    assert "Ningún modelo supera al baseline" in d.reason and d.gates["beats_baseline"]["status"] == FAIL
    # incluso con un champion vigente: si el baseline supera a todos, se publica el baseline
    d2 = decide(worse, champion="auto_ets")
    assert d2.decision == "publish_baseline" and d2.served_family == BASELINE_FAMILY


def test_new_candidate_must_beat_the_current_champion():
    rows = make_rows()
    better_than_champion = decide(rows, champion="auto_ets")             # 2,8 frente a 3,6: +22 %
    assert better_than_champion.decision == "promote" and better_than_champion.champion_family == "auto_ets"
    assert better_than_champion.gates["improvement"]["evidence"]["reference"].startswith("champion vigente")
    close = make_rows(lambda f, w, s: {"lightgbm_global": 3.5, "auto_ets": 3.6}.get(f, BASE_SD[f]))     # 2,8 % mejor que el champion (< 3 %)
    d = decide(close, champion="auto_ets")
    assert d.gates["improvement"]["status"] == FAIL and d.decision == "shadow"
    assert d.served_family == "auto_ets", "el champion sigue sirviéndose y el candidato queda como challenger"


def test_champion_that_is_still_the_best_is_kept_when_it_passes_the_gates():
    d = decide(make_rows(), champion="lightgbm_global")
    assert d.decision == "keep_champion" and d.served_family == "lightgbm_global" and d.candidate_family == "lightgbm_global"
    assert d.gates["improvement"]["evidence"]["reference"].startswith("baseline")
    assert d.gates["human_approval"]["status"] == NOT_APPLICABLE, "la aprobación se pidió cuando se promovió"


def test_champion_that_stops_proving_itself_is_replaced_by_the_baseline():
    unstable = make_rows(lambda f, w, s: ([2.0, 4.4, 4.4][w] if f == "lightgbm_global" else BASE_SD[f]))
    d = decide(unstable, champion="lightgbm_global")
    assert d.gates["temporal_stability"]["status"] == FAIL
    assert d.decision == "publish_baseline" and d.served_family == BASELINE_FAMILY
    assert "ya no cumple los gates" in d.reason


def test_hard_gate_failure_keeps_serving_a_healthy_champion_not_the_rejected_candidate():
    healthy = lambda f, w, s: {"auto_ets": 3.2}.get(f, BASE_SD[f])      # noqa: E731  (el champion supera al baseline: 3,2 frente a 4,0)
    d = decide(make_rows(healthy), champion="auto_ets", signing_available=False)
    assert d.candidate_family == "lightgbm_global" and d.decision == "reject" and d.served_family == "auto_ets"
    assert d.metrics["champion_check"]["demoted"] is False and d.metrics["champion_check"]["wape"] < d.metrics["champion_check"]["baseline_wape"]


def test_a_champion_worse_than_the_baseline_is_not_kept_when_the_challenger_fails_a_gate():
    """Repro de la revisión: el champion LightGBM (WAPE ≈ 2× el baseline) no se sigue sirviendo porque el challenger falle un gate blando."""
    def sd(f, w, s):
        if f == "lightgbm_global":
            return 9.0                                   # champion degradado: peor que el baseline
        if f == "auto_ets":
            return 1.5 * (5 if w == 2 else 1)            # challenger mejor que el baseline, pero con intervalos descalibrados (gate blando)
        return BASE_SD[f]

    rows = make_rows(sd, windows=3)
    d = decide(rows, champion="lightgbm_global")
    assert d.candidate_family == "auto_ets" and d.gates["metrics_documented"]["status"] == FAIL
    assert d.served_family == BASELINE_FAMILY and d.decision == "publish_baseline", "el peor de los tres no se sirve"
    check = d.metrics["champion_check"]
    assert check["demoted"] is True and check["wape"] > 1.5 * check["baseline_wape"] and "improvement" in check["failed_gates"]
    assert "champion vigente LightGBM global" in d.reason and "se publica el baseline" in d.reason and "no se promueve" in d.reason
    # sin champion (el baseline es la referencia) la misma situación deja al candidato en shadow y el baseline servido
    d0 = decide(rows)
    assert d0.decision == "shadow" and d0.served_family == BASELINE_FAMILY and "champion_check" not in d0.metrics
    # un champion SANO sí se sigue sirviendo mientras el challenger no pase
    healthy = make_rows(lambda f, w, s: 3.8 if f == "lightgbm_global" else sd(f, w, s), windows=3)      # champion 5 % mejor que el baseline
    kept = decide(healthy, champion="lightgbm_global")
    assert kept.candidate_family == "auto_ets" and kept.decision == "shadow" and kept.served_family == "lightgbm_global"
    assert kept.metrics["champion_check"]["demoted"] is False


def test_a_candidate_missing_from_a_bad_window_is_not_promoted():
    """Repro de la revisión: backtest/rolling.py omite a LightGBM en una ventana que no puede entrenar; ausentarse no es ganar."""
    def sd(f, w, s):
        return (9.0 if w == 0 else 2.0) if f == "lightgbm_global" else BASE_SD[f]

    rows = make_rows(sd, windows=4)
    kw = dict(windows_used=[0, 1, 2, 3])
    full = decide(rows, **kw)
    assert full.gates["improvement"]["evidence"]["relative_improvement"] == pytest.approx(0.06, abs=0.03)
    missing = rows[~((rows["family"] == "lightgbm_global") & (rows["window"] == 0))]
    d = decide(missing, **kw)
    stab = d.gates["temporal_stability"]
    assert stab["status"] == FAIL and stab["evidence"]["windows_missing"] == [0] and stab["evidence"]["required_windows"] == [0, 1, 2, 3]
    assert d.decision == "shadow" and d.served_family == BASELINE_FAMILY and "ventana(s) de selección [0]" in stab["message"]
    # la mejora se mide sobre las observaciones que AMBOS tienen: la ventana 0 del baseline no se cuenta contra una ausencia
    imp = d.gates["improvement"]["evidence"]
    aligned = missing[missing["family"].isin(["lightgbm_global", "seasonal_naive"]) & (missing["window"] > 0)]
    base_aligned = aligned[aligned["family"] == "seasonal_naive"]
    assert imp["reference_wape"] == pytest.approx(pr._wape(base_aligned["actual"].to_numpy(float), base_aligned["yhat"].to_numpy(float)))
    assert d.metrics["aligned"]["windows_missing"] == [0] and d.metrics["aligned"]["aligned_share_of_reference"] == pytest.approx(0.75)


def test_alignment_keeps_only_observations_both_families_have():
    rows = make_rows(windows=3)
    drop = (rows["family"] == "lightgbm_global") & (rows["unique_id"] == "b1-p0") & (rows["window"] == 1)
    rows = pr.with_keys(rows[~drop])
    pair = pr.align_pair(rows, "lightgbm_global", "seasonal_naive", [0, 1, 2])
    assert pair.n_candidate == pair.n_aligned < pair.n_reference and pair.windows_missing == [] and pair.windows_not_comparable == []
    keys = lambda fam: set(map(tuple, pair.rows[pair.rows["family"] == fam][pr.KEY_COLUMNS].to_numpy()))      # noqa: E731
    assert keys("lightgbm_global") == keys("seasonal_naive"), "mismas observaciones en ambos lados"
    assert pair.cand["n_obs"] == pair.ref["n_obs"] == pair.n_aligned and pair.cand["sum_actual"] == pair.ref["sum_actual"], "mismo denominador"


def test_contenders_are_ranked_on_rows_aligned_with_the_baseline_and_full_coverage_wins():
    """LightGBM falta en la ventana mala y parece el mejor; auto_ets cubre todo y es genuinamente mejor que el baseline: el candidato es el que cubre."""
    def sd(f, w, s):
        return {"lightgbm_global": 9.0 if w == 0 else 1.0, "auto_ets": 3.0}.get(f, BASE_SD[f])

    rows = make_rows(sd, windows=4)
    rows = rows[~((rows["family"] == "lightgbm_global") & (rows["window"] == 0))]
    d = decide(rows, windows_used=[0, 1, 2, 3])
    assert d.candidate_family == "auto_ets" and d.decision == "promote"
    by = {c["family"]: c for c in d.metrics["contenders_vs_baseline"]}
    assert by["lightgbm_global"]["windows_missing"] == [0] and by["auto_ets"]["windows_missing"] == []
    assert by["lightgbm_global"]["wape"] < by["auto_ets"]["wape"], "sobre sus propias filas LightGBM se veía mejor: eso es lo que ya no decide"


def test_non_finite_forecasts_do_not_poison_the_ranking():
    rows = make_rows(windows=3)
    bad = (rows["family"] == "lightgbm_global") & (rows["window"] == 1) & (rows["step"] <= 3)
    rows.loc[bad, "yhat"] = np.nan
    all_nan = rows["family"] == "naive"
    rows.loc[all_nan, "yhat"] = np.inf
    stats = pr.family_stats(rows[rows["family"] == "lightgbm_global"])
    assert np.isfinite(stats["wape"]) and stats["invalid_rows"] == int(bad.sum()) and stats["n_obs"] == int((rows["family"] == "lightgbm_global").sum() - bad.sum())
    assert pr.family_stats(rows[rows["family"] == "naive"])["wape"] is None and pr.family_stats(rows[rows["family"] == "naive"])["invalid_rows"] > 0
    d = decide(rows)
    dropped = d.metrics["invalid_rows_dropped"]
    assert dropped["lightgbm_global"] == int(bad.sum()) and dropped["naive"] == int(all_nan.sum())
    assert "naive" not in {c["family"] for c in d.metrics["contenders_vs_baseline"]}, "una familia sin ningún pronóstico válido no es contendiente"
    assert d.candidate_family == "lightgbm_global" and np.isfinite(d.gates["improvement"]["evidence"]["candidate_wape"])
    assert d.gates["improvement"]["evidence"]["relative_improvement"] == pytest.approx(0.3, abs=0.04)


def test_champion_not_evaluated_in_this_backtest_falls_back_to_the_baseline():
    d = decide(make_rows(), champion="tsb")        # familia ausente del backtest de esta ejecución
    assert d.metrics["champion_source"] == "not_evaluated_now" and d.champion_family == BASELINE_FAMILY and d.decision == "promote"


def test_human_approval_can_block_serving_when_configured():
    pol = replace(V25Policy(), approval_blocks_serving=True)
    d = decide(make_rows(), policy=pol)
    assert d.gates["human_approval"]["blocking"] is True and d.gates["human_approval"]["status"] == PENDING
    assert d.decision == "shadow" and d.served_family == BASELINE_FAMILY and "aprobación humana" in d.reason
    assert pol.version != V25Policy().version


def test_champions_are_read_from_the_previous_served_selection():
    selection = {"smooth": {"model_family": "auto_ets", "model_registry_id": 3, "reason": "x"}, "pooled": {"model_family": "tsb"},
                 "insufficient": {"model_family": "window_average"}, "fallback": {"model_family": "seasonal_naive"},
                 "ghost": {"model_family": "no_existe"}}
    assert champions_from_selection(selection) == {"smooth": "auto_ets", "pooled": "tsb"}
    assert champions_from_selection(None) == {} and champions_from_selection({}) == {}


# ───────────────────────────── versión de la política ─────────────────────────────

def test_policy_version_is_the_sha256_of_the_parameters():
    import hashlib

    p = V25Policy()
    expected = hashlib.sha256(json.dumps({"policy": "v25", **p.params()}, sort_keys=True).encode()).hexdigest()
    assert p.version == expected and len(p.version) == 64
    assert replace(p, min_relative_improvement=0.05).version != p.version
    assert replace(p, critical_segments=("demand_class",)).version != p.version
    cfg = PromotionConfig()
    assert cfg.policy_versions() == {"legacy": cfg.legacy.version, "v25": p.version} and cfg.legacy.version != p.version


# ───────────────────────────── orquestación: ambas políticas, registro y gobierno ─────────────────────────────

def full_backtest(sd=None, windows: int = 4, holdout_window: int | None = 3, seed: int = 3):
    """Backtest con clase `smooth` (6 series) e `intermittent` (2 series ⇒ decisión agregada) y su perfil; la última ventana es el período final."""
    a = make_rows(sd, windows=windows, n_series=6, seed=seed, big_series=(0,), holdout_from=holdout_window, cls="smooth")
    b = make_rows(sd, windows=windows, n_series=2, seed=seed + 1, big_series=(), holdout_from=holdout_window, cls="intermittent")
    b["unique_id"] = b["unique_id"].str.replace("p", "p1", regex=False)
    bt = pd.concat([a, b], ignore_index=True)
    profile = bt.drop_duplicates("unique_id")[["unique_id", "demand_class"]].reset_index(drop=True)
    return bt, profile


QUALITY = SimpleNamespace(outcome="PASS", reason_codes=lambda: [])


def run_policies(bt, profile, governing="legacy", champions=None, **cfg_kw):
    cfg = PromotionConfig(governing_policy=governing, **cfg_kw)
    return select_with_policies(bt, profile, cfg, champions=champions, quality=QUALITY, can_sign=True)


def test_both_policies_are_recorded_per_scope_and_exactly_one_governs():
    bt, profile = full_backtest()
    for governing in ("legacy", "v25"):
        out = run_policies(bt, profile, governing)
        rows = out.rows()
        scopes = {r["scope_key"] for r in rows}
        assert scopes == {"global", "class:smooth", "class:intermittent"}
        assert {(r["policy"], r["scope_key"]) for r in rows} == {(p, s) for p in ("legacy", "v25") for s in scopes}, "una fila por política y alcance"
        for scope in scopes:
            effective = [r for r in rows if r["scope_key"] == scope and r["is_effective"]]
            assert len(effective) == 1 and effective[0]["policy"] == governing
        for r in rows:
            assert r["decision"] in ("promote", "reject", "keep_champion", "publish_baseline", "shadow")
            assert len(r["policy_version"]) == 64 and r["reason"] and r["candidate_family"] and r["gates"]
            assert r["metrics"]["served_family"] and "informational" in r["metrics"]
            assert json.loads(jdump(r["gates"])) is not None and json.loads(jdump(r["metrics"])) is not None, "serializable a jsonb"
        versions = {r["policy"]: r["policy_version"] for r in rows}
        assert versions == out.config.policy_versions()


def test_legacy_governs_by_default_and_v25_is_a_decision_shadow():
    bt, profile = full_backtest()
    out = run_policies(bt, profile)
    assert out.governing == "legacy" and out.selection is out.legacy and out.eval_selection is out.legacy_eval
    summary = out.summary()
    assert summary["policies"]["legacy"]["effective"] is True and summary["policies"]["v25"]["effective"] is False
    assert "sombra de decisión" in summary["policies"]["v25"]["role"]
    assert summary["holdout_used_for_decisions"] is False and summary["approval"]["status"] == PENDING
    manifest = out.manifest_block()
    assert manifest["policy_versions"] == out.config.policy_versions() and manifest["holdout_used_for_model_selection_v25"] is False
    assert manifest["config"]["v25"]["rule_version"] == pr.V25_RULE_VERSION


def test_v25_governs_the_served_selection_when_the_company_asks_for_it():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, "v25")
    v25 = {d.scope_key: d for d in out.decisions if d.policy == "v25"}
    assert out.selection is out.v25 and out.eval_selection is out.v25, "con V2.5 la selección de producción y la de evaluación coinciden"
    assert out.selection.winners["smooth"] == v25["class:smooth"].served_family == "lightgbm_global"
    assert out.selection.winners["pooled"] == v25["global"].served_family
    assert out.selection.winners["intermittent"] == v25["global"].served_family, "clase con pocas series: decisión agregada"
    assert out.selection.as_json({"lightgbm_global": 7})["smooth"]["model_registry_id"] == 7
    assert out.selection.policy_version == out.config.v25.version


def test_classes_with_few_series_use_the_pooled_decision_in_both_policies():
    bt, profile = full_backtest()
    out = run_policies(bt, profile)
    for policy in ("legacy", "v25"):
        by = out.by_scope(policy)
        assert by["class:intermittent"].served_family == by["global"].served_family
        assert "used_pooled" in by["class:intermittent"].gates and by["class:intermittent"].reason.startswith("Pocas series")


def test_the_final_holdout_never_decides_or_tunes():
    """El período final se vuelve catastrófico para LightGBM: la V2.5 (ventanas de selección) no se entera; la heredada de producción sí."""
    good, profile = full_backtest()
    tampered = good.copy()
    hold_lgbm = tampered["is_holdout"] & (tampered["family"] == "lightgbm_global")
    tampered.loc[hold_lgbm, "yhat"] = tampered.loc[hold_lgbm, "actual"] * 8.0
    a, b = run_policies(good, profile, "v25"), run_policies(tampered, profile, "v25")
    rows_a = [r for r in a.rows() if r["policy"] == "v25"]
    rows_b = [r for r in b.rows() if r["policy"] == "v25"]
    assert json.dumps(rows_a, sort_keys=True, default=str) == json.dumps(rows_b, sort_keys=True, default=str), "la V2.5 es idéntica"
    assert a.selection.winners == b.selection.winners and a.selection.winners["smooth"] == "lightgbm_global"
    # la política heredada, en producción, SÍ usa todas las ventanas (regla vigente, documentada): aquí cambia
    assert a.legacy.winners["smooth"] == "lightgbm_global" and b.legacy.winners["smooth"] != "lightgbm_global"
    assert all(r["metrics"]["holdout_used"] is False for r in rows_a if "holdout_used" in r["metrics"])
    assert a.windows["selection_windows"] == [0, 1, 2] and a.windows["holdout_windows"] == [3] and a.windows["independent_holdout"] is True


def test_without_an_independent_holdout_v25_cannot_certify_a_promotion():
    bt, profile = full_backtest(windows=3, holdout_window=None)
    out = run_policies(bt, profile, "v25")
    assert out.eval_selection is None and out.windows["independent_holdout"] is False
    for d in out.decisions:
        if d.policy == "v25" and d.scope_key == "global":
            assert d.gates["no_leakage"]["status"] == NOT_EVALUABLE
            assert d.decision == "reject" and d.served_family == BASELINE_FAMILY
    legacy = run_policies(bt, profile, "legacy")
    assert legacy.selection.winners["smooth"] == "lightgbm_global", "la política heredada no cambia por esto"


def test_champion_of_the_previous_run_is_the_reference_in_the_registry():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, champions={"smooth": "auto_ets", "pooled": "auto_ets"})
    smooth = out.by_scope("v25")["class:smooth"]
    legacy = out.by_scope("legacy")["class:smooth"]
    assert smooth.champion_family == "auto_ets" and legacy.champion_family == "auto_ets"
    assert smooth.decision == "promote" and legacy.decision == "promote"
    again = run_policies(bt, profile, champions={"smooth": "lightgbm_global", "pooled": "lightgbm_global"})
    assert again.by_scope("v25")["class:smooth"].decision == "keep_champion" and again.by_scope("legacy")["class:smooth"].decision == "keep_champion"
    assert out.summary()["champions"] == {"smooth": "auto_ets", "pooled": "auto_ets"}


def test_a_pooled_family_that_is_not_a_candidate_of_the_class_is_not_assigned_to_it():
    """R-071: una clase con pocas series hereda la decisión agregada solo si esa familia es candidata de la clase; si no, el baseline."""
    sd = lambda f, w, s: {"auto_ets": 2.8, "lightgbm_global": 5.0}.get(f, BASE_SD[f])      # noqa: E731  (el mejor es auto_ets: NO es candidato de «lumpy»)
    a = make_rows(sd, windows=4, n_series=6, seed=3, big_series=(0,), holdout_from=3, cls="smooth")
    b = make_rows(sd, windows=4, n_series=2, seed=4, big_series=(), holdout_from=3, cls="lumpy")
    b["unique_id"] = b["unique_id"].str.replace("p", "p1", regex=False)
    bt = pd.concat([a, b], ignore_index=True)
    profile = bt.drop_duplicates("unique_id")[["unique_id", "demand_class"]].reset_index(drop=True)
    out = run_policies(bt, profile, "v25")
    v25 = out.by_scope("v25")
    assert v25["global"].served_family == "auto_ets" and v25["class:smooth"].served_family == "auto_ets"
    lumpy = v25["class:lumpy"]
    assert lumpy.served_family == BASELINE_FAMILY and lumpy.decision == "publish_baseline" and "no es candidata de la clase lumpy" in lumpy.reason
    assert lumpy.gates["used_pooled"]["evidence"]["pooled_family_is_class_candidate"] is False
    assert out.selection.winners["lumpy"] == BASELINE_FAMILY
    # la política heredada conserva su comportamiento (paridad): sí asigna la familia agregada a la clase
    assert out.by_scope("legacy")["class:lumpy"].served_family == out.by_scope("legacy")["global"].served_family


def test_manifest_block_reports_what_happened_not_constants():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, "v25")
    block = out.manifest_block()
    assert block["holdout_used_for_model_selection_v25"] is False and out.v25_holdout_rows == 0
    assert block["holdout_used_for_hyperparameters"] is False
    assert block["legacy_windows_rule"]["pooled"] == {"windows_total": 4, "windows_better": 4, "windows_needed": 3, "windows_won_fraction": 2 / 3}, \
        "la regla exacta de la heredada (con 4 ventanas exige 3), no «≥ 2/3»"
    out.tuning = {"enabled": True, "holdout_used": True}
    assert out.manifest_block()["holdout_used_for_hyperparameters"] is True
    out.v25_holdout_rows = 5
    assert out.manifest_block()["holdout_used_for_model_selection_v25"] is True


def test_a_class_that_only_exists_in_the_final_period_still_has_exactly_one_effective_row_per_scope():
    """La heredada usa todas las ventanas y ve la clase `lumpy`; la V2.5 (solo selección) no: su fila existe igual (sin evidencia) y el alcance
    tiene exactamente una fila efectiva, también con cada política gobernando."""
    good, profile = full_backtest()
    late = make_rows(windows=4, n_series=4, seed=9, big_series=(0,), holdout_from=3, cls="lumpy")
    late = late[late["window"] == 3].copy()                       # solo el período final reservado
    late["unique_id"] = late["unique_id"].str.replace("p", "p2", regex=False)
    bt = pd.concat([good, late], ignore_index=True)
    profile = bt.drop_duplicates("unique_id")[["unique_id", "demand_class"]].reset_index(drop=True)
    for governing in ("legacy", "v25"):
        out = run_policies(bt, profile, governing)
        scopes = {d.scope_key for d in out.decisions if not d.informational}
        assert "class:lumpy" in scopes
        for scope in scopes:
            rows = [d for d in out.decisions if d.scope_key == scope]
            assert sorted(d.policy for d in rows) == ["legacy", "v25"] and [d.policy for d in rows if d.is_effective] == [governing]
        lumpy = out.by_scope("v25")["class:lumpy"]
        assert lumpy.gates["used_pooled"]["evidence"]["series"] == 0 and "solo aparece en el período final" in lumpy.reason
        assert "lumpy" in out.v25.winners, "la selección V2.5 también cubre la clase"
        pr.assert_one_effective_per_scope(out.decisions, governing)


def test_the_effective_row_invariant_is_checked_not_assumed():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, "legacy")
    pr.assert_one_effective_per_scope(out.decisions, "legacy")
    twice = [d for d in out.decisions if d.scope_key == "global" and d.policy == "v25"][0]
    twice.is_effective = True
    with pytest.raises(pr.PromotionInvariantError, match="global.*exactamente una fila efectiva"):
        pr.assert_one_effective_per_scope(out.decisions, "legacy")
    twice.is_effective = False
    zero = [d for d in out.decisions if d.scope_key == "class:smooth" and d.policy == "legacy"][0]
    zero.is_effective = False
    with pytest.raises(pr.PromotionInvariantError, match="class:smooth"):
        pr.assert_one_effective_per_scope(out.decisions, "legacy")
    zero.is_effective = True
    missing = [d for d in out.decisions if not (d.scope_key == "class:smooth" and d.policy == "v25")]
    with pytest.raises(pr.PromotionInvariantError, match="una fila por política"):
        pr.assert_one_effective_per_scope(missing, "legacy")
    horizon = pr.ScopeDecision(policy="legacy", policy_version="x", scope_key="horizon:7", candidate_family="naive", champion_family=None,
                               served_family="naive", decision="promote", reason="x", informational=True, is_effective=True)
    with pytest.raises(pr.PromotionInvariantError, match="informativo"):
        pr.assert_one_effective_per_scope([horizon], "legacy")


def test_reconcile_served_records_the_family_really_served_after_a_final_training_fallback():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, "legacy")
    affected = [d for d in out.decisions if d.is_effective and d.served_family == "lightgbm_global"]
    assert affected, "precondición: la heredada sirve LightGBM en algún alcance"
    untouched = [(d.scope_key, d.served_family) for d in out.decisions if not d.is_effective]
    out.reconcile_served({"lightgbm_global": "RuntimeError: fallo forzado"}, "seasonal_naive")
    for d in affected:
        row = d.as_row()
        assert d.served_family == "seasonal_naive" == row["metrics"]["served_family"]
        assert row["metrics"]["fallback_applied"] is True and row["metrics"]["planned_family"] == "lightgbm_global"
        assert row["metrics"]["final_training_error"].startswith("RuntimeError") and "Falló el entrenamiento final" in row["reason"]
    assert [(d.scope_key, d.served_family) for d in out.decisions if not d.is_effective] == untouched, "las filas que no gobernaron no se tocan"
    summary = out.summary()
    assert {(f["scope_key"], f["planned_family"], f["served_family"]) for f in summary["fallback_applied"]} == \
        {(d.scope_key, "lightgbm_global", "seasonal_naive") for d in affected}
    assert all(summary["policies"]["legacy"]["decisions"][d.scope_key]["served"] == "seasonal_naive" for d in affected)


def test_selection_criterion_text_is_generated_from_the_policy():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, "legacy")
    assert out.selection_criterion() == "menor WAPE por clase de demanda; LightGBM solo si mejora ≥ 3% y gana en al menos ⌈n × 0.6667⌉ ventanas (con 4 ventanas: 3)"
    custom = run_policies(bt, profile, "legacy", legacy=pr.LegacyPolicy(min_improvement=0.05, windows_won_fraction=1.0))
    assert "≥ 5%" in custom.selection_criterion() and "(con 4 ventanas: 4)" in custom.selection_criterion()
    assert run_policies(bt, profile, "v25").selection_criterion().startswith("política de promoción V2.5")


def test_summary_reports_where_the_policies_disagree():
    bt, profile = full_backtest()
    out = run_policies(bt, profile, champions=None)
    agreement = out.summary()["agreement"]
    assert agreement["scopes"] == 3 and 0 <= agreement["same_served_model"] <= 3
    assert set(agreement["different"]) == {s for s, d in out.by_scope("legacy").items()
                                           if d.served_family != out.by_scope("v25")[s].served_family}
