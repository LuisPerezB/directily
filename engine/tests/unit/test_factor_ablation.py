"""W6-E · ablation de factores (T12): backtest con y sin cada factor, diferencias por horizonte y segmento con los denominadores de W3-A.

Se prueba sobre la empresa FICTICIA de `predictily_engine.synthetic.factors` (campañas anunciadas por anticipado con efecto sembrado + un «clima» de
ruido): la respuesta correcta se conoce. La herramienta es de SOLO LECTURA, jamás toca el período final reservado y reutiliza la regla con que el
pipeline hace competir al modelo con factores.
"""

from __future__ import annotations

import json
import sys
from dataclasses import replace
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest import factor_ablation as ablation
from predictily_engine.backtest.factor_arm import FactorArmRule
from predictily_engine.demand import segments as seg
from predictily_engine.pipeline.data import FactorInputs
from predictily_engine.synthetic.factors import CAMPAIGN_KEY, NOISE_KEY, fictional_factor_demo

SCRIPT = Path(__file__).resolve().parents[2] / "scripts"


@pytest.fixture(scope="module")
def demo():
    return fictional_factor_demo()


@pytest.fixture(scope="module")
def run(demo):
    """Una sola ablation (cada factor por separado y los dos juntos) con un espía de los orígenes que se entrenan."""
    import predictily_engine.selection.tuning as tuning

    monkeypatch = pytest.MonkeyPatch()
    origins: list[pd.Timestamp] = []
    real = tuning.lightgbm_backtest_rows

    def spy(panel, features, plan, windows, params, seed, n_jobs, origins=None):
        spied.extend(pd.Timestamp(o) for o in (origins or []))
        return real(panel, features, plan, windows, params, seed, n_jobs, origins=origins)

    spied = origins
    monkeypatch.setattr(tuning, "lightgbm_backtest_rows", spy)
    data = replace(demo.data, settings={**demo.data.settings, "cores": {"factors": {"enabled": True, "arm_placebo_arms": 2}}})      # 2 placebos: la misma lógica con menos cómputo
    report = ablation.run_ablation(data, seed=42, n_jobs=2, label={"source": "fictional"})
    monkeypatch.undo()
    return report, origins


def by_set(report: dict, *keys: str) -> dict:
    return next(s for s in report["sets"] if s["factor_set"] == sorted(keys))


def test_the_sown_campaign_effect_is_promoted_and_the_noise_factor_is_not(run):
    report, _ = run
    assert [s["factor_set"] for s in report["sets"]] == [[CAMPAIGN_KEY], [NOISE_KEY], sorted([CAMPAIGN_KEY, NOISE_KEY])]
    campaign, noise, both = by_set(report, CAMPAIGN_KEY), by_set(report, NOISE_KEY), by_set(report, CAMPAIGN_KEY, NOISE_KEY)
    assert campaign["decision"] == "promote" and campaign["reason"] == "improvement_and_windows"
    assert campaign["evidence"]["relative_improvement"] > 0.03 and campaign["evidence"]["windows_better"] == campaign["evidence"]["windows_total"] == 3
    placebo = campaign["evidence"]["placebo"]
    assert placebo["passed"] is True and placebo["evaluated"] == 2 and placebo["best_placebo_wape"] > campaign["evidence"]["wape_factor"], "supera al mejor de sus placebos"
    assert noise["decision"] == "reject" and noise["reason"] == "no_sufficient_improvement", "un factor sin efecto sembrado no se promueve"
    assert noise["evidence"]["relative_improvement"] < 0.03 and "placebo" not in noise["evidence"]
    # Comparaciones múltiples: la combinación NO se promueve porque uno de sus miembros no pasó solo, aunque su WAPE sea bueno.
    assert both["decision"] == "reject" and both["reason"] == "member_not_promoted" and both["evidence"]["blocked_by"] == [NOISE_KEY]
    assert both["members"] == {CAMPAIGN_KEY: {"decision": "promote", "reason": "improvement_and_windows"}, NOISE_KEY: {"decision": "reject", "reason": "no_sufficient_improvement"}}
    assert both["used"] == sorted([CAMPAIGN_KEY, NOISE_KEY]) and both["cells"], "aun bloqueada, la combinación informa sus diferencias por horizonte y segmento"
    assert all(s["dropped"] == [] and s["cost"]["api_calls"] == 0 and s["decision_scope"] == "factor_set" for s in report["sets"])
    assert report["cost"]["api_calls"] == 0 and report["cost"]["lightgbm_fits"] == 3 * (1 + 1 + 2 + 1 + 1), \
        "baseline, campaña, sus 2 placebos, ruido y la combinación: 3 ventanas cada uno (el ruido no gasta placebos)"


def test_the_final_holdout_is_never_trained_or_measured_to_decide(run):
    report, origins = run
    plan = report["plan"]
    assert plan["holdout_untouched"] is True and plan["independent_holdout_available"] is True and plan["holdout_origin"] is not None
    assert plan["trained_origins"] == sorted(plan["selection_origins"]), "holdout_untouched se CALCULA de los orígenes realmente entrenados"
    holdout = pd.Timestamp(plan["holdout_origin"])
    assert origins and holdout not in origins and set(origins) == {pd.Timestamp(o) for o in plan["selection_origins"]}
    assert len(plan["selection_origins"]) == 3 and max(pd.Timestamp(o) for o in plan["selection_origins"]) + pd.Timedelta(days=plan["horizon_days"]) <= holdout
    assert all(r["holdout_untouched"] is True for r in report["evaluation_rows"])
    for s in report["sets"]:
        assert s["evidence"]["scope"] == "selection_windows" and s["evidence"]["rule"]["holdout_used_for_duel"] is False


def test_the_holdout_flag_is_computed_from_the_trained_origins_not_a_constant():
    holdout = pd.Timestamp("2026-07-28")
    origins = [holdout - pd.Timedelta(days=d) for d in (90, 62, 34)]

    class Plan:
        holdout_cutoff = holdout

    class NoHoldout:
        holdout_cutoff = None

    assert ablation._holdout_untouched(Plan, origins) is True
    assert ablation._holdout_untouched(Plan, [*origins, holdout]) is False and ablation._holdout_untouched(Plan, [holdout + pd.Timedelta(days=3)]) is False
    assert ablation._holdout_untouched(NoHoldout, [holdout]) is True


def test_the_tool_only_reads_and_says_so(run):
    report, _ = run
    assert report["writes_database"] is False and any("no escribe" in n for n in report["notes"])
    assert report["version"] == "factor-ablation-v2" and report["label"] == {"source": "fictional"}
    json.dumps(report, default=str)


def test_metrics_per_horizon_and_segment_carry_the_w3a_denominators_and_states(run):
    report, _ = run
    definition = report["metric_definition"]
    assert definition["source"].startswith("demand/segments.py") and definition["wape_min_denominator"] == 30.0 and definition["basis"] == seg.HORIZON_BASIS
    campaign = by_set(report, CAMPAIGN_KEY)
    overall = {c["horizon_days"]: c for c in campaign["cells"] if c["dimension"] == "overall"}
    assert sorted(overall) == [7, 14, 15, 30, 60, 90], "los horizontes del catálogo que el backtest alcanza"
    assert {c["dimension"] for c in campaign["cells"]} == {"overall", "demand_class", "category", "branch", "volume_tier", "series_age"}
    for h, c in overall.items():
        for arm in ("baseline", "candidate"):
            m = c[arm]
            assert m["status"] == "ok" and m["wape_status"] == "ok" and m["denominator"] >= definition["wape_min_denominator"]
            assert m["wape"] == pytest.approx(m["sum_abs_error"] / m["denominator"]), "WAPE = Σ|error| / Σ reales (el denominador va en la celda)"
            assert m["n_obs"] > 0 and m["n_series"] > 0 and set(m["excluded"]) >= {"stockout_days", "missing_source_days", "closed_days"}
        assert c["baseline"]["denominator"] == c["candidate"]["denominator"], "los dos brazos se miden sobre los MISMOS días evaluables"
        assert c["delta"]["metric"] == "wape" and c["delta"]["delta"] == pytest.approx(c["candidate"]["wape"] - c["baseline"]["wape"])
    # El horizonte más largo reúne todos los pasos 1..H y debe coincidir con la evidencia de la regla (misma base de cálculo).
    top = overall[90]
    ev = campaign["evidence"]
    assert top["baseline"]["wape"] == pytest.approx(ev["wape_plain"]) and top["candidate"]["wape"] == pytest.approx(ev["wape_factor"])
    assert top["baseline"]["denominator"] == pytest.approx(ev["denominator"])
    # Los segmentos con pocas observaciones no muestran cifras (igual que en model_evaluations).
    insufficient = [c for s in run[0]["sets"] for c in s["cells"] if c["baseline"]["status"] == seg.STATUS_INSUFFICIENT]
    for c in insufficient:
        assert c["baseline"]["wape"] is None and c["delta"]["delta"] is None and c["delta"]["reason"] == "insufficient_data"
        assert c["baseline"]["insufficient_reasons"]


def test_the_rows_have_the_shape_of_factor_evaluations_and_are_not_written(run):
    report, _ = run
    rows = report["evaluation_rows"]
    assert rows and {"factor_set", "segment", "horizon_days", "cutoff", "baseline_metrics", "candidate_metrics", "holdout_untouched", "decision", "cost",
                     "notes"} <= set(rows[0])
    assert {r["decision"] for r in rows} <= {"promote", "reject", "inconclusive"}
    assert {r["decision_scope"] for r in rows} == {"factor_set"} and {r["cell_verdict"] for r in rows} <= {"better", "similar", "worse", "not_comparable"}
    assert all(r["decision_reason"] for r in rows), "la decisión es del CONJUNTO; cada celda trae además su propio veredicto de métrica"
    assert {r["segment"] for r in rows if r["segment"] is None} == {None}, "las filas globales llevan segmento NULL"
    assert any(r["segment"] and r["segment"].startswith("demand_class:") for r in rows)
    assert all(r["cutoff"] == report["cutoff"] and r["cost"]["api_calls"] == 0 for r in rows)


def test_the_ablation_applies_the_same_rule_the_pipeline_uses_to_let_the_factor_model_compete(run):
    report, _ = run
    rule = FactorArmRule(placebo_arms=2)
    assert report["rule"] == rule.params() and report["rule_version"] == rule.version and report["version"] == "factor-ablation-v2"
    for s in report["sets"]:
        ev = s["evidence"]
        assert ev["rule"] == rule.params()
        passes = ev["passes_improvement"] and ev["passes_windows"] and (ev.get("placebo") or {}).get("passed") and "blocked_by" not in ev
        assert (s["decision"] == "promote") == bool(passes)


def test_a_set_with_no_usable_factor_is_not_evaluable_and_costs_no_training(demo):
    last = pd.Timestamp(demo.data.cutoff)
    days = pd.date_range(last - pd.Timedelta(days=100), last, freq="D")
    forecast = pd.DataFrame({"id": range(len(days)), "period_date": days, "value": 1.0, "revision": 0, "published_at": days, "issued_at": pd.NaT})
    definition = {**demo.inputs.definitions[NOISE_KEY], "key": "f.forecast", "availability_class": "THIRD_PARTY_FORECAST", "missing_strategy": "unavailable"}
    inputs = FactorInputs(keys=sorted([*demo.inputs.keys, "f.forecast"]), definitions={**demo.inputs.definitions, "f.forecast": definition},
                          observations={**demo.inputs.observations, "f.forecast": forecast})
    from dataclasses import replace

    report = ablation.run_ablation(replace(demo.data, factor_inputs=inputs), factor_sets=[["f.forecast"]], max_windows=2, seed=42, n_jobs=2)
    [only] = report["sets"]
    assert only["decision"] == "inconclusive" and only["reason"] == "not_evaluable" and only["used"] == [] and only["cells"] == []
    assert [d["reason"] for d in only["dropped"]] == ["no_issuance_history"] and only["cost"]["lightgbm_fits"] == 0
    assert report["evaluation_rows"] == [] and report["cost"]["lightgbm_fits"] == 2, "solo el baseline entrenó"
    with pytest.raises(ValueError, match="sin datos cargados"):
        ablation.run_ablation(replace(demo.data, factor_inputs=inputs), factor_sets=[["f.no_cargado"]])
    with pytest.raises(ValueError, match="No hay factores candidatos"):
        ablation.run_ablation(replace(demo.data, factor_inputs=None))


# ───────────────────────── reglas puras (rápidas) ─────────────────────────

def cell(dim: str, value: str, h: int, improvement: float | None, metric: str = "wape") -> dict:
    return {"dimension": dim, "value": value, "horizon_days": h, "baseline": {"wape": 0.5, "denominator": 100.0}, "candidate": {"wape": 0.5},
            "delta": {"metric": metric if improvement is not None else None, "relative_improvement": improvement}}


def test_a_global_improvement_with_a_regression_in_a_protected_segment_is_inconclusive_not_promoted():
    cells = [cell("overall", "all", 30, 0.2), cell("demand_class", "smooth", 30, -0.30), cell("volume_tier", "rest", 30, 0.1),
             cell("demand_class", "lumpy", 7, -0.5),            # no es el mayor horizonte: no cuenta
             cell("category", "c1", 30, -0.9)]                  # categoría: no es una dimensión protegida por defecto
    regressions = ablation._segment_regressions(cells)
    assert [(r["dimension"], r["value"]) for r in regressions] == [("demand_class", "smooth")]
    selected = {"decision": "factor_arm_selected", "reason": "improvement_and_windows"}
    assert ablation._decision(selected, regressions) == ("inconclusive", "segment_regression")
    assert ablation._decision(selected, []) == ("promote", "improvement_and_windows")
    assert ablation._segment_regressions([]) == []
    kept = lambda reason: {"decision": "plain_arm_kept", "reason": reason}  # noqa: E731
    assert ablation._decision(kept("no_sufficient_improvement"), []) == ("reject", "no_sufficient_improvement")
    for reason in ("insufficient_windows", "denominator_below_minimum", "factor_arm_incomplete", "not_stable_across_windows"):
        assert ablation._decision(kept(reason), [])[0] == "inconclusive", reason


def test_the_database_mode_refuses_anything_that_is_not_a_test_copy_and_opens_read_only(monkeypatch):
    assert ablation.database_name("postgresql+psycopg://u:p@h:5432/predictily_prod?sslmode=x") == "predictily_prod"
    assert ablation.database_name("postgresql+psycopg://u:p@h:5432/predictily?x=/test") == "predictily", "se analiza con make_url, no cortando texto"
    for url in ("postgresql+psycopg://u:p@h:5432/predictily", "postgresql+psycopg://u:p@h:5432/predictily_produccion", "postgresql+psycopg://u:p@h:5432/predictily?x=/test",
                "postgresql+psycopg://u:p@h:5432/latest_prod"):
        with pytest.raises(PermissionError):
            ablation.read_only_engine(url)
        with pytest.raises(PermissionError):
            ablation.load_from_database(url, 1)
    captured = {}
    from predictily_engine.db import safety

    class FakeEngine:
        def __init__(self):
            self.listeners = []

    monkeypatch.setattr(safety, "create_engine", lambda url, **kw: captured.update(url=url, **kw) or FakeEngine())
    monkeypatch.setattr(safety.event, "listens_for", lambda engine, name: (lambda fn: captured.update(listener=(name, fn)) or fn))
    assert isinstance(ablation.read_only_engine("postgresql+psycopg://u:p@h:5432/predictily_engine_test_copia"), FakeEngine)
    assert captured["connect_args"] == {"options": "-c default_transaction_read_only=on"} and captured["listener"][0] == "begin"
    executed = []
    captured["listener"][1](type("C", (), {"exec_driver_sql": lambda self, sql: executed.append(sql)})())
    assert executed == ["SET TRANSACTION READ ONLY"], "cada transacción abre en solo lectura (no solo el valor por defecto de la sesión)"


def test_the_markdown_summary_lists_each_set_with_its_decision(run):
    text = ablation.render_markdown(run[0])
    assert "# Ablation de factores" in text and "período final reservado: **intacto**" in text
    for s in run[0]["sets"]:
        assert ", ".join(s["factor_set"]) in text
    assert "| promote |" in text and "| reject |" in text and "Δ" in text


def test_the_command_line_validates_its_input_and_writes_only_what_it_is_asked(tmp_path, monkeypatch, capsys):
    sys.path.insert(0, str(SCRIPT))
    try:
        import factor_ablation as cli
    finally:
        sys.path.remove(str(SCRIPT))
    monkeypatch.delenv("ENGINE_TEST_DATABASE_URL", raising=False)
    assert cli.main(["--tenant-id", "1"]) == 2 and "ENGINE_TEST_DATABASE_URL" in capsys.readouterr().err
    monkeypatch.setenv("ENGINE_TEST_DATABASE_URL", "postgresql+psycopg://u:p@h:5432/predictily")
    assert cli.main(["--tenant-id", "1"]) == 3 and "no parece de pruebas" in capsys.readouterr().err
    # --fictional con una ablation simulada: el CLI escribe el JSON y el Markdown pedidos y nada más.
    fake = {"version": "factor-ablation-v2", "cutoff": "2026-01-01", "plan": {"selection_windows": 3, "horizon_days": 90}, "sets": [], "notes": ["n"]}
    seen = {}
    monkeypatch.setattr(ablation, "run_ablation", lambda data, **kw: seen.update(kw) or fake)
    out, md = tmp_path / "r.json", tmp_path / "r.md"
    assert cli.main(["--fictional", "--factors", f"{CAMPAIGN_KEY}, {NOISE_KEY}", "--no-combination", "--max-windows", "2", "--out", str(out), "--markdown", str(md)]) == 0
    assert json.loads(out.read_text())["version"] == "factor-ablation-v2" and "# Ablation de factores" in md.read_text()
    assert seen["candidates"] == [CAMPAIGN_KEY, NOISE_KEY] and seen["combinations"] is False and seen["max_windows"] == 2
    assert sorted(p.name for p in tmp_path.iterdir()) == ["r.json", "r.md"]
    assert np.isfinite(len(fake))
