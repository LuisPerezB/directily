"""Integración PostgreSQL de las mejoras de precisión encendidas por empresa: selección por el total diario (regla v2), familia
«mismo día de la semana», ajuste de feriados de los modelos estadísticos y combinación AutoETS + LightGBM.

Comprueba que una ejecución con los cuatro interruptores activos se persiste completa (registro de modelos, evaluaciones, decisiones de
promoción y valores) y que la configuración queda en la huella de la ejecución. No afirma nada de precisión: los datos son ficticios."""

from __future__ import annotations

import pytest
from sqlalchemy import text

from conftest import AUTH
from loader import create_run, load_company
from predictily_engine.models.registry import ENSEMBLE_FAMILY, FAMILIES, WEEKDAY_AVERAGE
from predictily_engine.selection.selector import LEGACY_RULE_VERSION_V2, LegacyPolicy
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration

SETTINGS = {"cores": {
    "demand": {"weekday_profile_models": True, "holiday_adjustment_models": True, "ensemble_models": True},
    "promotion": {"legacy_selection_metric": "total_day"},
}}


def _rows(engine, sql, **params):
    with engine.connect() as conn:
        return [dict(r) for r in conn.execute(text(sql), params).mappings().all()]


@pytest.fixture(scope="module")
def world(client, db_engine):
    company = generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01")
    with db_engine.begin() as conn:
        tenant = load_company(conn, company, "Empresa P (prueba ficticia, mejoras de precisión)", settings=SETTINGS)
        run_id, job_id = create_run(conn, tenant)
    resp = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                       json={"tenant_id": tenant, "training_job_id": job_id, "horizons": [7, 14, 30, 60, 90]})
    assert resp.status_code == 200, resp.text
    return {"tenant": tenant, "run": run_id, "body": resp.json()}


def test_run_completes_and_records_the_switches_in_its_configuration(world, db_engine):
    run = _rows(db_engine, "SELECT status, summary, effective_config FROM forecast_runs WHERE id = :r AND tenant_id = :t",
                r=world["run"], t=world["tenant"])[0]
    assert run["status"] == "completed"
    config = run["effective_config"]["config"]
    assert config["weekday_profile_models"] is True and config["holiday_adjustment_models"] is True and config["ensemble_models"] is True
    assert any("se ajustan por feriados" in a for a in run["summary"]["assumptions"])
    expected = LegacyPolicy(selection_metric="total_day", min_improvement=config["min_improvement"])
    assert run["effective_config"]["promotion"]["policy_versions"]["legacy"] == expected.version


def test_new_families_are_registered_evaluated_and_only_known_families_are_served(world, db_engine):
    registry = {r["model_family"]: r for r in _rows(
        db_engine, "SELECT model_family, status, strategy_level, artifact_path, artifact_compat, notes FROM model_registry "
                   "WHERE tenant_id = :t AND forecast_run_id = :r", t=world["tenant"], r=world["run"])}
    assert {ENSEMBLE_FAMILY, WEEKDAY_AVERAGE, "lightgbm_global", "auto_ets", "seasonal_naive"} <= set(registry)
    ensemble = registry[ENSEMBLE_FAMILY]
    assert ensemble["strategy_level"] == "B" and ensemble["artifact_path"] is None
    assert ensemble["artifact_compat"]["artifact_kind"] == "ensemble_of_members" and "Combinación" in ensemble["notes"]
    assert registry["lightgbm_global"]["artifact_path"], "el artefacto firmado de LightGBM sigue en su propia fila"
    assert registry[WEEKDAY_AVERAGE]["artifact_compat"]["artifact_kind"] == "statistical_refit"
    evaluated = {r["model_family"] for r in _rows(
        db_engine, "SELECT DISTINCT model_family FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r",
        t=world["tenant"], r=world["run"])}
    assert {ENSEMBLE_FAMILY, WEEKDAY_AVERAGE} <= evaluated
    served = _rows(db_engine, "SELECT model_family, COUNT(*) AS n FROM forecast_values WHERE tenant_id = :t AND forecast_run_id = :r "
                              "AND model_family IS NOT NULL GROUP BY model_family", t=world["tenant"], r=world["run"])
    assert served and all(r["model_family"] in FAMILIES for r in served)


def test_promotion_decisions_use_the_v2_rule_and_record_the_ensemble_gate(world, db_engine):
    decisions = _rows(db_engine, "SELECT scope_key, policy, policy_version, is_effective, gates, metrics, reason FROM model_promotion_decisions "
                                 "WHERE tenant_id = :t AND forecast_run_id = :r AND policy = 'legacy'", t=world["tenant"], r=world["run"])
    assert decisions
    by_scope = {d["scope_key"]: d for d in decisions}
    glob = by_scope["global"]
    assert glob["is_effective"] is True
    evidence = glob["metrics"]["evidence"]
    assert evidence["metric"] == "total_day" and evidence["ensemble"]["family"] == ENSEMBLE_FAMILY
    assert {"min_improvement", "windows_won", "guards", "ensemble"} <= set(glob["gates"])
    assert "wape_total" not in glob["metrics"]["wape_by_family"] and ENSEMBLE_FAMILY in glob["metrics"]["wape_by_family"]
    assert LegacyPolicy(selection_metric="total_day").rule_version == LEGACY_RULE_VERSION_V2
