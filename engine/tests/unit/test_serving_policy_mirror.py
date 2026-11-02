"""El motor reconoce lo servible EXACTAMENTE como el backend (`ModelServingPolicy::basis()`): mismos casos, una sola función (`mlops.policy.classify`)."""

from __future__ import annotations

import pytest

from mlops_policy_cases import CASES, LEGACY, NOT_APPROVED
from predictily_engine.mlops import policy
from predictily_engine.mlops.policy import EventFlags


def _flags(events: list[dict]) -> EventFlags:
    """Mismas banderas que el SQL agregado de `ModelServingPolicy::flagsSql()`, calculadas en Python para la prueba unitaria."""
    return EventFlags(
        cli=any(e["actor_kind"] == "cli" for e in events),
        human_validated=any(e["from_status"] == "candidate" and e["to_status"] == "validated" and e["actor_kind"] == "user" for e in events),
        legacy_mark=any(e["evidence"].get("legacy_policy") is not None for e in events))


def _row(status: str, extra: dict) -> dict:
    return {"id": 1, "status": status, **extra}


@pytest.mark.parametrize("name,status,extra,events,expected,backend", CASES, ids=[c[0] for c in CASES])
def test_legacy_is_a_positive_definition_that_fails_closed(name, status, extra, events, expected, backend):
    """Los casos de `ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed` y de `ServingPolicyHardeningTest`."""
    assert policy.classify(_row(status, extra), _flags(events)) == expected, backend


def test_only_approved_and_legacy_models_are_servable():
    assert policy.is_servable(policy.APPROVED) and policy.is_servable(policy.LEGACY) and not policy.is_servable(policy.NOT_APPROVED)


def test_the_cli_publication_contract_never_yields_a_servable_model_until_approved():
    """La fila que `publish` escribe (candidate + marcas + evento cli) es `not_approved`; recién aprobada y en producción pasa a `approved`."""
    published = {"published_by": 11, "published_env": "staging", "published_at": "2026-11-01T09:00:00+00:00", "published_object_key": "tenants/u/models/customer/v/a.joblib"}
    cli_events = [{"from_status": None, "to_status": "candidate", "actor_kind": "cli", "evidence": {}}]
    assert policy.classify(_row("candidate", published), _flags(cli_events)) == NOT_APPROVED
    assert policy.classify(_row("production", published), _flags(cli_events)) == NOT_APPROVED      # aunque alguien deje el estado en production
    assert policy.classify(_row("production", {**published, "approved_by": 12, "approved_at": "2026-11-01T10:00:00+00:00"}), _flags(cli_events)) == policy.APPROVED


def test_null_values_coming_from_pandas_count_as_absent():
    """Una lectura con pandas entrega NaN/NaT/NA donde la base tiene NULL: ninguno debe contar como «presente»."""
    import pandas as pd

    nulls = {"approved_at": pd.NaT, "approved_by": float("nan"), "published_at": pd.NaT, "published_by": float("nan"), "published_object_key": None,
             "published_env": None, "forecast_run_id": float("nan"), "core_run_id": float("nan"), "training_job_id": pd.NA}
    assert policy.classify(_row("production", nulls), policy.NO_FLAGS) == NOT_APPROVED                      # sin origen: nada enlazado
    assert policy.classify(_row("production", {**nulls, "core_run_id": 5.0}), policy.NO_FLAGS) == LEGACY    # id entero leído como float
    assert policy.classify(_row("production", {**nulls, "approved_by": 12.0}), policy.NO_FLAGS) == policy.APPROVED
    assert policy.classify(_row("production", {**nulls, "core_run_id": 5.0, "published_by": 11.0}), policy.NO_FLAGS) == NOT_APPROVED


def test_the_event_flags_sql_matches_the_backends_aggregate():
    """El SQL de banderas es el mismo agregado que `ModelServingPolicy::flagsSql()` (cli, validación humana, marca de legado)."""
    sql = policy.EVENT_FLAGS_SQL
    assert "actor_kind = 'cli'" in sql
    assert "from_status = 'candidate' AND to_status = 'validated' AND actor_kind = 'user'" in sql
    assert "evidence->>'legacy_policy' IS NOT NULL" in sql


def test_a_row_of_another_company_is_an_error_not_a_silent_skip():
    with pytest.raises(ValueError):
        policy.basis_of_rows(None, 1, [{"id": 5, "tenant_id": 2, "status": "production"}])      # type: ignore[arg-type]
