"""W2-C · gates por módulo (PASS / WARN / DEGRADE / BLOCK), umbrales por precedencia, mapa de features y efectos reales (sin base de datos).

Cada regla de la tabla (`quality.rules.RULES`) se prueba en su umbral: en el límite se cumple; un paso más allá produce su motivo con la
severidad declarada. Los efectos (ejecución detenida, feature neutralizada, política forzada) se prueban contra el pipeline y los
insumos reales. Todo con datos FICTICIOS.
"""

from __future__ import annotations

import copy
import json
import re
from dataclasses import replace
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from predictily_engine.features.builder import FeatureBuilder, feature_registry
from predictily_engine.models import ml as ml_module
from predictily_engine.pipeline.core import InsufficientDataError, run_pipeline
from predictily_engine.platform.config import CORE_DEFAULTS, ConfigValidationError, resolve_config
from predictily_engine.platform.extensions import SectorProfile, register_profile
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.quality import features as qf
from predictily_engine.quality import rules as qr
from predictily_engine.quality.assessment import QualityPolicy, assess_customer, assess_demand, assess_supply
from predictily_engine.quality.gates import evaluate, fmt_number
from predictily_engine.quality.thresholds import PILOT_MARK, THRESHOLDS
from predictily_engine.supply.inputs import PlanInputs
from test_quality_metrics import customer_data, hand_data, plan_inputs

DEFAULTS = CORE_DEFAULTS["quality_gates"]
DOC = Path(__file__).resolve().parents[3] / "docs" / "data" / "DATA_QUALITY_ENGINE.md"


def nested(path: str, value) -> dict:
    out: dict = {}
    cur = out
    parts = path.split(".")
    for part in parts[:-1]:
        cur = cur.setdefault(part, {})
    cur[parts[-1]] = value
    return out


def deep_update(a: dict, b: dict) -> dict:
    for k, v in b.items():
        if isinstance(v, dict) and isinstance(a.get(k), dict):
            deep_update(a[k], v)
        else:
            a[k] = v
    return a


def beyond(rule: qr.Rule, cfg: dict) -> float:
    thr = cfg[rule.threshold]
    step = 1e-3 * max(1.0, abs(thr))
    return thr - step if rule.op == ">=" else thr + step


# ───────────────────────────── la tabla de reglas, regla por regla ─────────────────────────────

@pytest.mark.parametrize("rule", qr.RULES, ids=lambda r: r.code)
def test_every_rule_passes_at_its_threshold_and_fires_one_step_beyond(rule):
    thr = DEFAULTS[rule.threshold]
    at = evaluate(rule.module, nested(rule.metric, thr), DEFAULTS)
    assert rule.code not in at.codes, "en el umbral exacto la regla se cumple"
    if rule.threshold == "supply_min_inventory_coverage":
        lowest = evaluate(rule.module, nested(rule.metric, 0.0), DEFAULTS)
        assert rule.code not in lowest.codes, "BLOCK de inventario desactivado por defecto (umbral 0): ni con cobertura 0 bloquea"
        return
    over = evaluate(rule.module, nested(rule.metric, beyond(rule, DEFAULTS)), DEFAULTS)
    assert [r["code"] for r in over.reasons if r["code"] == rule.code] == [rule.code]
    assert over.outcome == rule.severity or qr.OUTCOME_RANK[over.outcome] > qr.OUTCOME_RANK[rule.severity]
    reason = next(r for r in over.reasons if r["code"] == rule.code)
    assert reason["severity"] == rule.severity and reason["threshold_key"] == rule.threshold and reason["threshold"] == thr
    assert reason["message"] and "{" not in reason["message"], "el mensaje se formatea completo"


def test_the_rules_table_is_consistent():
    codes = [r.code for r in qr.RULES]
    assert len(codes) == len(set(codes)) and all(c.startswith("dq_") for c in codes)
    assert {r.module for r in qr.RULES} == set(qr.MODULES) and {r.severity for r in qr.RULES} == {"WARN", "DEGRADE", "BLOCK"}
    assert all(r.op in (">=", "<=") for r in qr.RULES)
    assert all(r.data_kind in qr.DATA_KINDS for r in qr.RULES if r.data_kind), "el dato requerido pertenece al vocabulario"
    assert all(r.data_kind is None or r.severity == "DEGRADE" for r in qr.RULES), "solo DEGRADE declara datos insuficientes"
    windows = {k for k, t in THRESHOLDS.items() if t.effect == "WINDOW"}
    assert qr.threshold_keys() | windows == set(DEFAULTS), "toda clave de quality_gates la usa una regla o es una ventana de lectura"


def test_the_outcome_is_the_worst_severity_and_reasons_are_ordered():
    metrics: dict = {}
    for path, value in (("coverage.calendar_days", 5), ("price.coverage_share", 0.1), ("freshness.days_since_last_sale", 90)):
        deep_update(metrics, nested(path, value))
    gate = evaluate("demand", metrics, DEFAULTS)
    assert gate.outcome == "BLOCK"
    assert [r["severity"] for r in gate.reasons] == ["BLOCK", "DEGRADE", "WARN"]
    assert gate.codes == ["dq_demand_insufficient_history", "dq_demand_price_coverage_low", "dq_demand_data_stale"]
    assert gate.disabled_features == ["price_ratio"]


def test_metrics_without_data_are_not_evaluated_and_say_why():
    """Sin valor por falta de datos (sin cargas en la ventana, sin historia suficiente) o porque no se recogieron las señales: sin evaluar,
    con su razón, pero sin motivo de aviso (no hay fallo que advertir)."""
    gate = evaluate("demand", {}, DEFAULTS)
    assert gate.outcome == "PASS" and gate.reasons == []
    assert {n["code"] for n in gate.not_evaluated} == {r.code for r in qr.rules_for("demand")}
    assert all(n["reason"] == "no_data" for n in gate.not_evaluated)
    metrics = {"invalid": {"available": False, "reason": "signals_not_collected"}, "freshness": {"ingestion_lag": {"available": False, "reason": "signals_not_collected"}}}
    reasons = {n["code"]: n["reason"] for n in evaluate("demand", metrics, DEFAULTS).not_evaluated}
    assert reasons["dq_demand_invalid_rate_excessive"] == "not_collected" and reasons["dq_demand_ingestion_lag_high"] == "not_collected"
    assert reasons["dq_demand_insufficient_history"] == "no_data"
    assert evaluate("demand", metrics, DEFAULTS).outcome == "PASS", "no recoger señales (ejecución sin base de datos) es una decisión del llamador, no un fallo"


def test_a_failed_signal_behind_a_block_or_degrade_rule_emits_a_stable_warn_reason():
    """Una señal que FALLÓ no se confunde con «sin datos»: si habría podido bloquear o degradar, el gate avisa con `dq_signal_unavailable`."""
    failed = {"available": False, "failed": True, "reason": "query_failed:OperationalError"}
    metrics = {"invalid": failed, "id_stability": {"available": False, "failed": True, "reason": "alias_tables_missing"}}
    demand = evaluate("demand", metrics, DEFAULTS)
    assert demand.outcome == "WARN" and demand.codes == ["dq_signal_unavailable"]
    reason = demand.reasons[0]
    assert reason["severity"] == "WARN" and reason["rules"] == ["dq_demand_invalid_rate_excessive"], "solo la regla BLOCK; la WARN no cambia el resultado"
    assert "query_failed:OperationalError" in reason["message"]
    failed_rules = {n["code"]: n["reason"] for n in demand.not_evaluated if n["reason"] == "signal_failed"}
    assert set(failed_rules) == {"dq_demand_invalid_rate_excessive", "dq_demand_invalid_rate_high"}
    customer = evaluate("customer", metrics, DEFAULTS)
    assert customer.outcome == "WARN" and customer.codes == ["dq_signal_unavailable"]
    assert set(customer.reasons[0]["rules"]) == {"dq_customer_product_ids_unstable", "dq_customer_customer_ids_unstable",
                                                 "dq_customer_new_product_codes_high"}, "las tres reglas DEGRADE de estabilidad de IDs quedaron sin evaluar"
    # una señal fallida que solo alimenta reglas WARN no genera el aviso (no habría cambiado el resultado más que como WARN)
    only_warn = evaluate("demand", {"freshness": {"ingestion_lag": failed}}, DEFAULTS)
    assert only_warn.outcome == "PASS" and only_warn.codes == []
    # una regla que sí se evalúa con otra señal sigue funcionando junto al aviso
    both = evaluate("demand", {**metrics, "coverage": {"calendar_days": 3}}, DEFAULTS)
    assert both.outcome == "BLOCK" and both.codes == ["dq_demand_insufficient_history", "dq_signal_unavailable"]


def test_a_failed_signal_reaches_the_limitations_in_demand_customer_and_supply():
    failed = {"available": False, "failed": True, "reason": "query_failed:OperationalError"}
    d = demand_assessment(signals={"ingestion": failed, "identity": failed, "id_stability": failed, "ingestion_lag": failed})
    assert d.outcome == "WARN" and "dq_signal_unavailable" in [x["code"] for x in d.limitation_items()]
    assert "dq_signal_unavailable" not in "".join(d.warning_messages()), "viaja como limitación, no se duplica como aviso"
    c = assess_customer(customer_data(), QualityPolicy.resolve({}), {"id_stability": failed})
    assert c.outcome == "WARN" and any("dq_signal_unavailable" in s for s in c.limitation_strings())
    assert not c.stops_individual_customer, "un fallo de lectura no deshabilita por sí solo las recomendaciones individuales"


def test_messages_format_fractions_as_percent_and_days_as_numbers():
    assert fmt_number("demand_min_source_coverage_pct", 0.456) == "45,6 %"
    assert fmt_number("demand_warn_max_stale_days", 14) == "14" and fmt_number("customer_min_buyers", 5.0) == "5"


# ───────────────────────────── gates con datos reales de los módulos ─────────────────────────────

def with_inventory(data):
    """Inventario vigente de todos los productos (sin él, el gate avisa de la cobertura de inventario, como debe)."""
    inv = pd.DataFrame({"branch_id": 1, "product_id": sorted(data.sales["product_id"].unique()), "ds": pd.Timestamp(data.cutoff), "on_hand": 50.0})
    return replace(data, latest_inventory=inv)


def demand_assessment(data=None, policy=None, signals=None):
    data = with_inventory(data or hand_data(n_days=100, products=(1, 2, 3, 4, 5, 6)))
    a = assess_demand(data, build_panel(data, DayRules(infer_gaps=False)), policy, signals)
    assert a.metrics_error is None, "las métricas de las fixtures se calculan sin error (un fallo no se esconde en estas pruebas)"
    return a


def test_healthy_demand_data_passes_every_gate_with_the_default_thresholds():
    a = demand_assessment(signals={"ingestion": {"available": True, "invalid_pct": 0.0}, "ingestion_lag": {"available": True, "p90_days": 0.0}})
    assert a.outcome == "WARN" or a.outcome == "PASS"
    assert not a.blocked and not a.degraded and a.disabled_features == []
    assert a.gate.codes == [] or all(r["severity"] == "WARN" for r in a.gate.reasons)
    assert a.metrics["coverage"]["source_coverage_pct"] == 1.0


def test_a_failure_computing_the_metrics_is_recorded_and_the_outcome_is_never_pass(monkeypatch):
    """Un error inesperado al calcular las métricas no tumba la ejecución, pero tampoco cuenta como «aprobado»: el resultado mínimo es WARN
    (`dq_metrics_unavailable`), queda en limitaciones y todas las reglas figuran sin evaluar por `metrics_error`."""
    from predictily_engine.quality import metrics as qm

    def boom(*_a, **_k):
        raise RuntimeError("columna inesperada")

    monkeypatch.setattr(qm, "sales_metrics", boom)
    data = with_inventory(hand_data(n_days=100, products=(1, 2, 3)))
    a = assess_demand(data, build_panel(data, DayRules(infer_gaps=False)))
    assert a.metrics_error == "RuntimeError: columna inesperada" and not a.blocked and not a.degraded
    assert a.outcome == "WARN" and a.gate.codes == ["dq_metrics_unavailable"] and a.quality_status_floor is None
    assert {n["code"] for n in a.gate.not_evaluated} == {r.code for r in qr.rules_for("demand")}
    assert all(n["reason"] == "metrics_error" for n in a.gate.not_evaluated)
    assert [x["code"] for x in a.limitation_items()] == ["dq_metrics_unavailable"] and "columna inesperada" in a.limitation_items()[0]["message"]
    assert a.warning_messages() == [], "viaja como limitación, no se duplica como aviso"
    assert a.manifest_block()["metrics_error"] == a.metrics_error and a.report_summary()["metrics_error"] == a.metrics_error
    assert a.manifest_block()["outcome"] == "WARN" and a.metrics["error"] == a.metrics_error
    c = assess_customer(customer_data(), QualityPolicy.resolve({}))   # el mismo contrato en los demás módulos (métricas de Customer reales)
    monkeypatch.setattr(qm, "customer_metrics", boom)
    cc = assess_customer(customer_data(), QualityPolicy.resolve({}))
    assert c.metrics_error is None and cc.outcome == "WARN" and any("dq_metrics_unavailable" in x for x in cc.limitation_strings())


def test_demand_blocks_without_the_minimum_history():
    a = demand_assessment(hand_data(n_days=20, products=(1, 2, 3)))
    assert a.blocked and a.gate.codes[0] == "dq_demand_insufficient_history" and a.quality_status_floor == "insufficient_data"
    assert "20 días" in a.block_message() and "28" in a.block_message()
    d = a.block_details()
    assert d["code"] == "dq_gate_block" and d["reason_codes"] == ["dq_demand_insufficient_history"] and d["gate"]["outcome"] == "BLOCK"
    assert len(d["rules_version"]) == 64


def test_demand_blocks_with_insufficient_source_coverage():
    """Más de la mitad de los sucursal-días sin fuente: la señal sería casi toda imputación."""
    outage = tuple((1, k) for k in range(20, 90))
    a = demand_assessment(hand_data(n_days=100, products=(1, 2, 3), skip=outage,
                                    coverage_gaps=pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": pd.Timestamp("2026-01-21"),
                                                                 "gap_to": pd.Timestamp("2026-03-31"), "reason": "integration_outage", "basis": "declared"}])))
    assert a.blocked and "dq_demand_insufficient_coverage" in a.gate.codes
    assert a.metrics["coverage"]["source_coverage_pct"] == pytest.approx(0.3, abs=1e-6)


def test_demand_blocks_when_most_ingested_rows_were_rejected_and_warns_beyond_five_percent():
    sig = lambda pct: {"ingestion": {"available": True, "invalid_pct": pct}}   # noqa: E731
    assert demand_assessment(signals=sig(0.6)).blocked
    mid = demand_assessment(signals=sig(0.07))
    assert mid.outcome == "WARN" and mid.gate.codes == ["dq_demand_invalid_rate_high"] and not mid.blocked
    assert demand_assessment(signals=sig(0.04)).gate.codes == []


def test_demand_degrades_the_price_feature_when_price_coverage_is_low():
    data = hand_data(n_days=100, products=(1, 2, 3, 4, 5, 6))
    sales = data.sales.copy()
    sales.loc[sales["product_id"].isin([1, 2]), "net_amount"] = 0.0        # 2 de 6 productos sin precio neto: 66,7 % < 80 %
    a = demand_assessment(replace(data, sales=sales))
    assert a.degraded and a.disabled_features == ["price_ratio"] and a.gate.codes == ["dq_demand_price_coverage_low"]
    assert a.quality_status_floor == "degraded"
    lim = a.limitation_items()
    assert lim[0]["code"] == "dq_demand_price_coverage_low" and "price_ratio" in lim[0]["message"]
    assert a.gate_results()["demand"]["disabled_features"] == ["price_ratio"]


def test_demand_warns_and_continues_with_notices():
    data = replace(hand_data(n_days=100, products=(1, 2, 3, 4, 5, 6)), promotions_known_on=pd.Timestamp("2026-06-01").date())
    a = demand_assessment(data)
    assert a.outcome == "WARN" and a.gate.codes == ["dq_demand_data_stale"] and a.disabled_features == []
    assert a.warning_messages()[0].startswith("[dq_demand_data_stale]") and a.limitation_items() == []
    assert a.quality_status_floor is None


def test_customer_blocks_without_buyers_or_history():
    policy = QualityPolicy.resolve({})
    few = assess_customer(customer_data(n_buyers=3), policy)
    assert few.blocked and "dq_customer_too_few_buyers" in few.gate.codes and few.stops_individual_customer
    assert few.individual_stop_code == "dq_gate_block" and few.quality_status_floor == "insufficient_data"
    short = assess_customer(customer_data(days=20), policy)
    assert short.blocked and "dq_customer_insufficient_history" in short.gate.codes


def test_customer_degrades_to_aggregate_when_identified_buyers_are_too_few():
    low = {"anonymous": {"lines": 70, "units": 140.0}, "generic": {"lines": 20, "units": 40.0},
           "deleted_customer": {"lines": 0, "units": 0.0}, "modeled": {"lines": 10, "units": 20.0}, "window_days": 180}
    a = assess_customer(customer_data(excluded=low), QualityPolicy.resolve({}))
    assert a.degraded and not a.blocked and a.gate.codes == ["dq_customer_identified_share_low"]
    assert a.disabled_features == list(("customer.activity_risk", "customer.individual_predictions", "customer.individual_rankings"))
    assert a.stops_individual_customer and a.individual_stop_code == "dq_individual_recommendations_disabled"
    assert a.manifest_block()["degraded_to"] == "aggregate" and a.quality_status_floor == "degraded"
    assert any("Calidad de datos [dq_customer_identified_share_low]" in s for s in a.limitation_strings())


def test_customer_degrades_when_ids_are_unstable():
    unstable = {"available": True, "lookback_days": 90, "product_change_share": 0.3, "customer_change_share": 0.0,
                "new_product_code_share": 0.1, "new_code_evaluable": True}
    a = assess_customer(customer_data(), QualityPolicy.resolve({}), {"id_stability": unstable})
    assert a.degraded and a.gate.codes == ["dq_customer_product_ids_unstable"] and "customer.individual_predictions" in a.disabled_features
    new_codes = assess_customer(customer_data(), QualityPolicy.resolve({}),
                                {"id_stability": {**unstable, "product_change_share": 0.0, "new_product_code_share": 0.7}})
    assert new_codes.gate.codes == ["dq_customer_new_product_codes_high"] and new_codes.degraded


def test_customer_data_stale_is_only_a_warning():
    a = assess_customer(customer_data(live=True), QualityPolicy.resolve({}))
    assert "dq_customer_data_stale" in a.gate.codes and not a.blocked and not a.degraded and not a.stops_individual_customer


def test_supply_warns_on_stale_inventory_and_degrades_without_lead_times():
    policy = QualityPolicy.resolve({})
    healthy = assess_supply(plan_inputs(inventory=plan_inputs().inventory.assign(ds=pd.Timestamp("2026-03-01"))), policy)
    assert healthy.outcome in ("PASS", "WARN") and healthy.disabled_features == [] and "dq_supply_inventory_stale" not in healthy.gate.codes
    stale = assess_supply(plan_inputs(inventory=plan_inputs().inventory.assign(ds=pd.Timestamp("2026-01-01"))), policy)
    assert stale.outcome == "WARN" and "dq_supply_inventory_stale" in stale.gate.codes and not stale.degraded
    no_lead = assess_supply(plan_inputs(lead_times=pd.DataFrame(columns=["supplier_id", "product_id", "branch_id", "lead_time_days"]),
                                        offers=pd.DataFrame()), policy)
    assert no_lead.degraded and "dq_supply_lead_times_missing" in no_lead.gate.codes
    assert no_lead.disabled_features == ["supply.implicit_offers", "supply.observed_lead_time_policy"]


def test_supply_block_on_missing_inventory_is_off_by_default_and_can_be_enabled_by_the_tenant():
    empty = plan_inputs(inventory=pd.DataFrame(columns=["branch_id", "product_id", "ds", "on_hand"]))
    default = assess_supply(empty, QualityPolicy.resolve({}))
    assert not default.blocked and "dq_supply_inventory_coverage_low" in default.gate.codes
    tenant = {"cores": {"quality_gates": {"supply_min_inventory_coverage": 0.05}}}
    strict = assess_supply(empty, QualityPolicy.resolve(tenant))
    assert strict.blocked and "dq_supply_no_inventory" in strict.gate.codes and strict.quality_status_floor == "insufficient_data"


# ───────────────────────────── precedencia core → perfil → empresa ─────────────────────────────

def test_thresholds_follow_the_core_profile_tenant_precedence_and_record_their_provenance():
    register_profile(SectorProfile(code="synthetic_quality", version="1.0.0", description="x",
                                   overrides={"quality_gates": {"demand_min_history_days": 40, "demand_warn_max_stale_days": 3}}, synthetic=True))
    try:
        core = QualityPolicy.resolve({})
        assert core.config["demand_min_history_days"] == 28 and core.overridden == []
        profile = QualityPolicy.resolve({}, "synthetic_quality")
        assert profile.config["demand_min_history_days"] == 40 and profile.provenance["demand_min_history_days"].startswith("profile:synthetic_quality")
        tenant = QualityPolicy.resolve({"cores": {"quality_gates": {"demand_min_history_days": 60}}}, "synthetic_quality")
        assert tenant.config["demand_min_history_days"] == 60 and tenant.provenance["demand_min_history_days"] == "tenant"
        assert tenant.config["demand_warn_max_stale_days"] == 3, "la clave que la empresa no toca conserva el valor del perfil"
        assert tenant.overridden == ["demand_min_history_days", "demand_warn_max_stale_days"]
        assert len({core.config_version, profile.config_version, tenant.config_version}) == 3
    finally:
        from predictily_engine.platform import extensions as ext

        ext._REGISTRY.pop("synthetic_quality", None)


def test_a_tenant_override_changes_the_outcome_of_the_same_data_and_the_default_tenant_is_not_affected():
    data = hand_data(n_days=100, products=(1, 2, 3, 4, 5, 6))
    strict = QualityPolicy.resolve({"cores": {"quality_gates": {"demand_min_history_days": 120}}})
    assert demand_assessment(data, strict).blocked, "con un mínimo de 120 días, 100 días de historia bloquean"
    assert not demand_assessment(data, QualityPolicy.resolve({})).blocked, "otra empresa sin override mantiene el umbral del core"
    lenient = QualityPolicy.resolve({"cores": {"quality_gates": {"demand_min_history_days": 5}}})
    short = hand_data(n_days=20, products=(1, 2, 3))
    assert demand_assessment(short).blocked and not demand_assessment(short, lenient).blocked
    assert demand_assessment(data, strict).gate_results()["demand"]["thresholds_overridden"] == ["demand_min_history_days"]


def test_invalid_tenant_overrides_are_rejected_not_applied_silently():
    for overrides in ({"umbral_inventado": 1}, {"demand_warn_invalid_pct": 2}, {"demand_min_history_days": 1.5}, {"customer_min_buyers": "cinco"}):
        with pytest.raises(ConfigValidationError):
            resolve_config("quality_gates", {"cores": {"quality_gates": overrides}})


def test_rules_version_changes_with_the_thresholds_and_is_a_sha256():
    a = QualityPolicy.resolve({})
    b = QualityPolicy.resolve({"cores": {"quality_gates": {"demand_min_history_days": 29}}})
    assert re.fullmatch(r"[0-9a-f]{64}", a.rules_version) and a.rules_version != b.rules_version
    assert a.rules_version == QualityPolicy.resolve({}).rules_version, "determinista"


# ───────────────────────────── umbrales documentados ─────────────────────────────

def test_every_threshold_has_a_written_justification_marked_for_pilot_approval():
    assert set(THRESHOLDS) == set(DEFAULTS), "cada clave de quality_gates tiene su justificación (y viceversa)"
    for key, t in THRESHOLDS.items():
        assert t.module in ("shared", *qr.MODULES) and t.effect in ("BLOCK", "DEGRADE", "WARN", "WINDOW") and len(t.justification) > 60
        assert PILOT_MARK in t.justification and "no es universal" in t.justification, key
    rule_effects = {r.threshold: r.severity for r in qr.RULES}
    assert all(THRESHOLDS[k].effect == sev for k, sev in rule_effects.items()), "el efecto documentado coincide con la severidad de la regla"


def test_the_documentation_lists_every_threshold_with_its_default_value():
    text = DOC.read_text(encoding="utf-8")
    section = text[text.index("## 6."):text.index("## 7.")]
    for key, value in DEFAULTS.items():
        assert f"`{key}`" in section, f"{key} falta en la sección 6"
        shown = f"{value:g}".replace(".", ",") if isinstance(value, float) else str(value)
        row = next(line for line in section.splitlines() if line.startswith(f"| `{key}` |"))   # fila de la tabla de umbrales (6.3)
        assert shown in row or f"{value}" in row, f"{key}: el valor por defecto {value} no aparece en su fila"
    for rule in qr.RULES:
        assert f"`{rule.code}`" in section, f"{rule.code} falta en la sección 6"


def test_the_documentation_states_the_real_number_of_thresholds_and_the_generated_codes():
    text = DOC.read_text(encoding="utf-8")
    section = text[text.index("## 6."):text.index("## 7.")]
    assert f"**{len(DEFAULTS)} umbrales**" in section, "el conteo de umbrales del documento no coincide con platform/config.py"
    assert f"{len(DEFAULTS) - 4} umbrales de gate" in section
    for code in qr.GENERATED_CODES:
        assert f"`{code}`" in section, f"{code} (motivo generado por el gate) falta en la sección 6"


def test_every_test_named_in_the_documentation_exists():
    """La documentación solo cita pruebas que existen (estado IMPLEMENTADO/VERIFICADO honesto)."""
    tests_dir = Path(__file__).resolve().parents[1]
    defined = set()
    for rel in ("unit/test_quality_gates.py", "unit/test_quality_metrics.py", "integration/test_quality_gates_integration.py",
                "integration/test_supply_integration.py"):
        defined |= set(re.findall(r"^def (test_\w+)", (tests_dir / rel).read_text(encoding="utf-8"), flags=re.M))
    text = DOC.read_text(encoding="utf-8")
    cited = set(re.findall(r"`(test_\w+)`", text[text.index("## 5."):]))
    assert cited and cited <= defined, f"pruebas citadas que no existen: {sorted(cited - defined)}"


# ───────────────────────────── mapa feature → datos requeridos ─────────────────────────────

def test_every_feature_of_the_demand_feature_set_has_a_data_requirement():
    rows = feature_registry()
    assert qf.unmapped_registry_features(rows) == [], "una feature del builder sin datos requeridos declarados"
    by_name = {r["name"]: qf.required_data(r["name"], r["kind"]) for r in rows}
    assert by_name["price_ratio"] == ("price",) and by_name["promo_discount"] == ("promotions",)
    assert by_name["lag7"] == ("sales_history",) and by_name["series_level"] == ("sales_history",) and by_name["region_num"] == ("branch_region",)
    assert by_name["branch_code_num"] == ("branches",) and by_name["category_num"] == ("product_category",)
    assert all(kind in qr.DATA_KINDS for kinds in by_name.values() for kind in kinds)


def test_every_degradable_model_feature_exists_in_the_builder_registry():
    names = {r["name"] for r in feature_registry()}
    for f in qf.DEGRADABLE_FEATURES:
        if f.kind == "model_feature":
            assert f.name in names and f.name in qf.NEUTRALIZED_COLUMNS, f"{f.name} no existe en el feature set o no sabe neutralizarse"
        assert f.module in qr.MODULES and set(f.requires) <= set(qr.DATA_KINDS) and f.effect and f.description


def test_every_data_kind_with_a_degrade_rule_disables_at_least_one_feature():
    for rule in qr.RULES:
        if rule.data_kind:
            assert qf.features_disabled_by(rule.module, [rule.data_kind]), f"{rule.code} no deshabilita ninguna feature"


def test_the_w2d_contract_registry_can_be_connected_and_checked():
    registry = {"price_ratio": ["price"], "promo_discount": ["promotions"], "lag7": ["sales_history"]}
    assert qf.check_registry(registry) == []
    problems = qf.check_registry({"price_ratio": ["precios_inventados"], "promo_discount": ["promotions"]})
    assert {p["problem"] for p in problems} == {"unknown_data_kind", "requirement_mismatch"}
    assert qf.check_registry({}) == [{"feature": "price_ratio", "problem": "missing_in_registry", "detail": "feature degradable sin contrato"}]
    assert qf.check_registry({"price_ratio": ["promotions"]})[0]["problem"] == "requirement_mismatch"
    try:
        qf.set_contract_provider(lambda: {"price_ratio": ["price", "costs"]})
        assert qf.required_data("price_ratio") == ("price", "costs"), "los requisitos del registro de W2-D se suman a los propios"
        assert qf.features_disabled_by("demand", ["costs"]) == ["price_ratio"]
    finally:
        qf.set_contract_provider(None)
    assert qf.required_data("price_ratio") == ("price",)


def test_the_real_w2d_registry_is_consistent_with_this_package_when_it_is_present():
    """Si el registro de contratos de W2-D ya está en el árbol: usa el mismo vocabulario, contiene las features degradables de demanda con los
    mismos datos requeridos, y `connect_feature_contracts` lo conecta como proveedor. Si todavía no existe, la prueba se omite."""
    contracts = pytest.importorskip("predictily_engine.availability.contracts")
    registry = contracts.required_data_map()
    assert qf.check_registry(registry) == [], "vocabulario o requisitos distintos entre el registro de W2-D y el mapa de los gates"
    assert {name for name, kinds in registry.items() if kinds} >= {"price_ratio", "promo_discount", "lag7"}
    try:
        assert qf.connect_feature_contracts() is True
        assert qf.required_data("price_ratio") == ("price",) and qf.required_data("promo_discount") == ("promotions",)
        assert qf.features_disabled_by("demand", ["price"]) == ["price_ratio"]
    finally:
        qf.set_contract_provider(None)


# ───────────────────────────── efectos reales ─────────────────────────────

def test_masked_builder_makes_the_degraded_columns_constant_and_delegates_the_rest():
    data = hand_data(n_days=100, products=(1, 2, 3))
    sales = data.sales.copy()
    sales["net_amount"] = sales["net_amount"] * (1 + 0.05 * (sales["ds"].dt.day % 5))      # el precio varía ⇒ price_ratio no es constante
    data = replace(data, sales=sales)
    panel = build_panel(data, DayRules(infer_gaps=False))
    plain = FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + pd.Timedelta(days=30)).date())
    ids = list(panel.profile["unique_id"])
    tr, ref = plain.training_frame(panel.cutoff, ids)
    assert tr["price_ratio"].nunique() > 1
    masked = qf.mask_feature_builder(plain, ["price_ratio"])
    mtr, mref = masked.training_frame(panel.cutoff, ids)
    assert mtr["price_ratio"].nunique() == 1 and mtr["price_ratio"].iloc[0] == 1.0
    pd.testing.assert_series_equal(mref, ref)
    pd.testing.assert_frame_equal(mtr.drop(columns="price_ratio"), tr.drop(columns="price_ratio"))
    future = masked.future_frame(panel.cutoff, ids, 7, mref)
    assert (future["price_ratio"] == 1.0).all() and list(future.columns) == list(plain.future_frame(panel.cutoff, ids, 7, ref).columns)
    assert masked.knowledge_date(panel.cutoff) == plain.knowledge_date(panel.cutoff), "lo demás se delega al constructor original"
    assert qf.mask_feature_builder(plain, ["customer.individual_predictions"]) is plain, "sin columnas que neutralizar devuelve el mismo objeto"


def varied_price_data(n_days: int = 320, zero_price_products: tuple[int, ...] = ()):
    """8 series × 320 días: lo mínimo para que LightGBM se entrene (≥ 5 series y ≥ 1500 filas) en las ventanas del backtest."""
    data = hand_data(n_days=n_days, products=(1, 2, 3, 4, 5, 6, 7, 8))
    sales = data.sales.copy()
    rng = np.random.default_rng(3)
    sales["units"] = rng.integers(2, 9, len(sales)).astype(float)
    sales["net_amount"] = sales["units"] * (10.0 + (sales["ds"].dt.day % 6))
    sales.loc[sales["product_id"].isin(zero_price_products), "net_amount"] = 0.0
    return replace(data, sales=sales)


def versioned_price_data(zero_price_products: tuple[int, ...] = ()):
    """Mismos datos con versiones de documentos: cada tercer día de los últimos 100 se conoció 5 días TARDE ⇒ las ventanas del backtest se
    reconstruyen con lo que se conocía en SU corte (`AsOfWindows`) y deben aplicar la misma política de features deshabilitadas."""
    from predictily_engine.data.asof import SalesVersions

    data = varied_price_data(zero_price_products=zero_price_products)
    s = data.sales.reset_index(drop=True)
    last = s["ds"].max()
    late = ((s["ds"] - s["ds"].min()).dt.days % 3 == 0) & (s["ds"] > last - pd.Timedelta(days=100))
    lines = pd.DataFrame({
        "sale_id": np.arange(len(s)), "line_number": 1, "branch_id": s["branch_id"], "product_id": s["product_id"], "ds": s["ds"],
        "quantity": s["units"], "unit_price": s["net_amount"] / s["units"], "discount_amount": 0.0, "net_amount": s["net_amount"],
        "unit_cost": 4.0, "is_return": False, "document_type": "invoice",
        "known_from": s["ds"] + pd.to_timedelta(np.where(late, 5, 0), unit="D") + pd.Timedelta(hours=17), "known_until": pd.NaT})
    versions = SalesVersions.from_lines(lines, "America/Guayaquil", last)
    assert versions.has_dynamic, "la fixture debe tener documentos tardíos"
    return replace(data, sales_versions=versions, sales=versions.sales_cells(last), sales_knowledge=versions.summary())


def test_a_degrade_gate_omits_the_price_feature_in_training_backtest_windows_and_prediction(monkeypatch):
    """Efecto real, por la vía del builder de W2-D (`FeatureBuilder(disabled_features=...)`): LightGBM NO recibe `price_ratio` (entrenamiento,
    ventanas del backtest —incluidas las reconstruidas con lo conocido en su corte— y predicción) y el resultado lo registra."""
    from predictily_engine.features.builder import FeatureBuilder

    seen = {"fit": [], "predict": [], "builders": {}}
    real_fit, real_predict, real_train = ml_module.LightGBMGlobal.fit, ml_module.LightGBMGlobal.predict, FeatureBuilder.training_frame

    def spy_fit(self, train):
        seen["fit"].append("price_ratio" in train.columns and int(train["price_ratio"].nunique()))
        return real_fit(self, train)

    def spy_predict(self, h, future_exog):
        seen["predict"].append("price_ratio" in future_exog.columns and int(future_exog["price_ratio"].nunique()))
        return real_predict(self, h, future_exog)

    def spy_train(self, cutoff, ids):
        seen["builders"][id(self)] = tuple(self.disabled_features)
        return real_train(self, cutoff, ids)

    monkeypatch.setattr(ml_module.LightGBMGlobal, "fit", spy_fit)
    monkeypatch.setattr(ml_module.LightGBMGlobal, "predict", spy_predict)
    monkeypatch.setattr(FeatureBuilder, "training_frame", spy_train)
    healthy = run_pipeline(versioned_price_data(), horizon=14, seed=1, n_jobs=1, horizons=(7,), force_families=["lightgbm_global"])
    assert healthy.quality.outcome in ("PASS", "WARN") and max(seen["fit"]) > 1, "con precio, la variable informa al modelo"
    assert set(seen["builders"].values()) == {()}
    for key in ("fit", "predict"):
        seen[key].clear()
    seen["builders"].clear()

    degraded = run_pipeline(versioned_price_data(zero_price_products=(1, 2, 3)), horizon=14, seed=1, n_jobs=1, horizons=(7,),
                            force_families=["lightgbm_global"])
    assert degraded.quality.degraded and degraded.quality.disabled_features == ["price_ratio"]
    assert len(seen["fit"]) >= 2 and set(seen["fit"]) == {False}, "price_ratio no llega a NINGÚN entrenamiento (backtest y final)"
    assert seen["predict"] and set(seen["predict"]) == {False}
    assert len(seen["builders"]) > 1 and set(seen["builders"].values()) == {("price_ratio",)}, \
        "el builder final y los de las ventanas as-of reconstruidas aplican la misma política"
    assert degraded.quality_status == "degraded"
    assert "dq_demand_price_coverage_low" in [x["code"] for x in degraded.limitations] and "dq_demand_price_coverage_low" in degraded.summary["limitation_codes"]
    gate = degraded.manifest["data_quality_gate"]
    assert gate["outcome"] == "DEGRADE" and gate["disabled_features"] == ["price_ratio"] and gate["rules_version"] == degraded.quality.rules_version
    assert degraded.summary["quality_gate"]["outcome"] == "DEGRADE" and degraded.summary["quality_status"] == "degraded"


def test_the_fallback_also_masks_the_as_of_window_builders():
    """Respaldo para un builder sin `disabled_features`: `mask_windows` envuelve el constructor de cada ventana as-of reconstruida."""
    class Windows:
        def __init__(self):
            self.make_features = lambda df: type("B", (), {"training_frame": lambda self, c, i: (pd.DataFrame({"price_ratio": [0.5, 2.0]}), None)})()

    windows = Windows()
    qf.mask_windows(windows, ["price_ratio"])
    frame, _ = windows.make_features(None).training_frame(None, [])
    assert list(frame["price_ratio"]) == [1.0, 1.0]
    untouched = Windows()
    original = untouched.make_features
    qf.mask_windows(untouched, ["customer.individual_predictions"])
    assert untouched.make_features is original
    qf.mask_windows(None, ["price_ratio"])      # sin ventanas reconstruidas no hace nada


def test_omitting_the_column_through_the_builder_is_equivalent_to_neutralizing_it():
    """Equivalencia: entrenar sin la columna (`disabled_features`) y con la columna constante (`MaskedFeatureBuilder`, el respaldo) da las
    mismas predicciones: por eso `pipeline/core.py` puede usar la vía del builder sin cambiar el resultado de un gate DEGRADE."""
    data = varied_price_data()
    panel = build_panel(data, DayRules(infer_gaps=False))
    end = (panel.cutoff + pd.Timedelta(days=30)).date()
    ids = list(panel.profile["unique_id"])

    def forecast(builder):
        frame, ref = builder.training_frame(panel.cutoff, ids)
        model = ml_module.LightGBMGlobal(seed=1, n_jobs=1).fit(frame)
        return frame, model.predict(14, builder.future_frame(panel.cutoff, ids, 14, ref))

    omitted_frame, omitted = forecast(FeatureBuilder(panel.df, data.branches, data.promotions, end, disabled_features=["price_ratio"]))
    masked_frame, masked = forecast(qf.mask_feature_builder(FeatureBuilder(panel.df, data.branches, data.promotions, end), ["price_ratio"]))
    full_frame, full = forecast(FeatureBuilder(panel.df, data.branches, data.promotions, end))
    assert "price_ratio" not in omitted_frame.columns and masked_frame["price_ratio"].nunique() == 1 and full_frame["price_ratio"].nunique() > 1
    np.testing.assert_allclose(omitted["yhat"].to_numpy(), masked["yhat"].to_numpy(), rtol=0, atol=1e-12)
    assert not np.allclose(omitted["yhat"].to_numpy(), full["yhat"].to_numpy()), "con la variable el modelo sí cambia: la equivalencia no es trivial"


def test_a_block_gate_stops_the_pipeline_with_a_stable_reason_code_before_training():
    with pytest.raises(InsufficientDataError) as exc:
        run_pipeline(hand_data(n_days=15, products=(1, 2, 3)), horizon=7, seed=1, n_jobs=1, horizons=(7,))
    err = exc.value
    assert err.details["code"] == "dq_gate_block" and err.details["reason_codes"] == ["dq_demand_insufficient_history"]
    assert err.assessment is not None and err.assessment.blocked and "dq_demand_insufficient_history" in str(err)
    json.dumps(err.details)      # lo que sale por HTTP 422 es serializable


def test_a_warn_gate_continues_and_records_the_notice_without_changing_the_quality_status():
    data = replace(varied_price_data(), promotions_known_on=pd.Timestamp("2026-12-31").date())
    res = run_pipeline(data, horizon=14, seed=1, n_jobs=1, horizons=(7,), force_families=["seasonal_naive"])
    assert res.quality.outcome == "WARN" and "dq_demand_data_stale" in res.quality.gate.codes
    assert any(w.startswith("[dq_demand_data_stale]") for w in res.warnings)
    assert res.quality_status != "insufficient_data" and "dq_demand_data_stale" not in [x["code"] for x in res.limitations]
    assert res.manifest["data_quality_gate"]["outcome"] == "WARN"


def test_the_default_thresholds_do_not_block_the_existing_fixtures(small_data):
    """Los valores por defecto no bloquean los datos de las fixtures ni de la demo (empresa sintética de la ferretería andina)."""
    a = assess_demand(small_data, build_panel(small_data, DayRules()))
    assert not a.blocked and not a.degraded and a.outcome in ("PASS", "WARN") and a.disabled_features == []


def test_supply_degradation_forces_the_policies_without_mutating_the_caller_dicts():
    cfg = {**CORE_DEFAULTS["supply"], "undated_receipt_policy": "observed_lead_time", "overdue_receipt_policy": "observed_lead_time"}
    options = {"forecast_run_id": 1, "undated_receipt_policy": "observed_lead_time"}
    inputs = plan_inputs(config=cfg, options=options)
    before = copy.deepcopy((cfg, options))
    changes = qf.apply_supply_degradation(inputs, ["supply.implicit_offers", "supply.observed_lead_time_policy"])
    assert inputs.config["use_implicit_offers"] is False
    assert inputs.config["undated_receipt_policy"] == inputs.config["overdue_receipt_policy"] == "lead_time_end"
    assert inputs.options["undated_receipt_policy"] == "lead_time_end" and inputs.options["forecast_run_id"] == 1
    assert (cfg, options) == before, "los diccionarios de configuración originales no se mutan"
    assert len(changes) == 3 and all(isinstance(c, str) for c in changes)
    assert qf.apply_supply_degradation(plan_inputs(config=dict(CORE_DEFAULTS["supply"])), []) == []


def test_the_effect_on_implicit_offers_is_real_in_the_planner_inputs():
    """Con las ofertas implícitas deshabilitadas, un producto sin oferta explícita se excluye del plan con `no_offer`."""
    from predictily_engine.supply.inputs import build_problem

    fc = pd.DataFrame([{"branch_id": 1, "product_id": 1, "ds": pd.Timestamp("2026-03-01").date() + pd.Timedelta(days=d), "horizon_step": d, "yhat": 5.0}
                       for d in range(1, 61)])
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "on_hand": [10.0], "reserved": 0.0, "in_transit": 0.0})
    base = dict(as_of=pd.Timestamp("2026-03-01").date(), forecast=fc, inventory=inv,
                products=pd.DataFrame({"product_id": [1], "unit": "u", "current_price": 3.0, "current_cost": 2.0}),
                cost_by_product={1: {"unit_cost": 2.0, "currency": "USD", "unit": "u"}}, config=dict(CORE_DEFAULTS["supply"]))
    problem, report = build_problem(PlanInputs(**base))
    assert len(problem.nodes) == 1 and not report["excluded_nodes"], "con la oferta implícita el producto se planifica"
    degraded = PlanInputs(**base)
    qf.apply_supply_degradation(degraded, ["supply.implicit_offers"])
    problem2, report2 = build_problem(degraded)
    assert len(problem2.nodes) == 0 and [e["reason"] for e in report2["excluded_nodes"]] == ["no_offer"]

