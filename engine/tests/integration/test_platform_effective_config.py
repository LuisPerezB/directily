"""Integración: configuración efectiva por empresa (core → perfil → empresa), aislamiento y ejecuciones que conservan su configuración."""

from __future__ import annotations

import json

import pytest
from sqlalchemy import text

from conftest import AUTH
from predictily_engine.platform.config import effective_config_record

pytestmark = pytest.mark.integration


def _tenant(engine, name: str, sector: str, settings: dict) -> int:
    with engine.begin() as conn:
        return conn.execute(text(
            "INSERT INTO tenants (uuid, name, sector, settings, created_at, updated_at) "
            "VALUES (gen_random_uuid(), :n, :s, CAST(:cfg AS jsonb), now(), now()) RETURNING id"
        ), {"n": name, "s": sector, "cfg": json.dumps(settings)}).scalar_one()


@pytest.fixture(scope="module")
def tenants_cfg(db_engine):
    return {
        "retail": _tenant(db_engine, "Empresa con perfil A (ficticia)", "synthetic_retail_a", {"service_level": 0.9, "cores": {"supply": {"default_lead_time_days": 12}}}),
        "process": _tenant(db_engine, "Empresa con perfil B (ficticia)", "synthetic_process_b", {}),
        "plain": _tenant(db_engine, "Empresa sin perfil (ficticia)", "ferreteria", {"cores": {"supply": {"default_lead_time_days": 33}}}),
    }


def _get(client, tenant_id: int, core: str = "supply"):
    return client.get("/v1/platform/effective-config", params={"tenant_id": tenant_id, "core": core}, headers=AUTH)


def _by_key(payload: dict) -> dict:
    return {k["key"]: k for k in payload["keys"]}


def test_effective_config_shows_value_and_provenance_per_key(client, tenants_cfg):
    body = _get(client, tenants_cfg["retail"]).json()
    keys = _by_key(body)
    assert body["precedence"] == ["core", "profile", "tenant"]
    assert body["profile"] == {"code": "synthetic_retail_a", "version": "1.0.0"}
    assert keys["default_lead_time_days"] == {"key": "default_lead_time_days", "value": 12, "source": "tenant"}     # empresa gana al perfil
    assert keys["service_level"]["value"] == 0.9 and keys["service_level"]["source"] == "tenant"                      # etapa 1: tenants.settings
    assert keys["review_period_days"]["source"] == "profile:synthetic_retail_a@1.0.0" and keys["review_period_days"]["value"] == 3
    assert keys["objective"]["source"] == "core"
    assert len(body["config_version"]) == 64


def test_company_without_profile_gets_core_values_and_never_another_tenants_overrides(client, tenants_cfg):
    plain = _get(client, tenants_cfg["plain"]).json()
    assert plain["profile"] is None
    keys = _by_key(plain)
    assert keys["default_lead_time_days"]["value"] == 33 and keys["default_lead_time_days"]["source"] == "tenant"
    assert keys["service_level"]["source"] == "core" and keys["service_level"]["value"] == 0.95
    process = _by_key(_get(client, tenants_cfg["process"]).json())
    assert process["default_lead_time_days"]["value"] == 21 and process["default_lead_time_days"]["source"].startswith("profile:synthetic_process_b")
    # Los otros núcleos de la misma empresa se resuelven por separado.
    assert _get(client, tenants_cfg["plain"], "demand").json()["core"] == "demand"


def test_invalid_stored_or_proposed_configuration_is_rejected(client, db_engine, tenants_cfg):
    bad = _tenant(db_engine, "Empresa con clave inválida (ficticia)", "ferreteria", {"cores": {"supply": {"clave_que_no_existe": 1}}})
    response = _get(client, bad)
    assert response.status_code == 422 and response.json()["code"] == "invalid_config"
    assert response.json()["errors"][0]["code"] == "unknown_key"

    dry = client.post("/v1/platform/validate-config", headers=AUTH, json={"tenant_id": tenants_cfg["plain"], "core": "supply", "overrides": {"service_level": 7, "otra": 1}})
    assert dry.status_code == 422
    assert {e["key"]: e["code"] for e in dry.json()["errors"]} == {"service_level": "out_of_range", "otra": "unknown_key"}
    ok = client.post("/v1/platform/validate-config", headers=AUTH, json={"tenant_id": tenants_cfg["plain"], "core": "supply", "overrides": {"service_level": 0.9}})
    assert ok.status_code == 200 and _by_key(ok.json())["service_level"]["source"] == "tenant"
    # La validación en seco no guardó nada.
    assert _by_key(_get(client, tenants_cfg["plain"]).json())["service_level"]["source"] == "core"

    assert _get(client, tenants_cfg["plain"], "ventas").status_code == 422
    assert _get(client, 987654321).status_code == 404
    assert client.get("/v1/platform/effective-config", params={"tenant_id": tenants_cfg["plain"], "core": "supply"}).status_code == 401


def test_runs_keep_the_effective_config_they_were_computed_with(client, db_engine, tenants_cfg):
    """Cambiar los parámetros de la empresa no sobrescribe resultados históricos: la ejecución guarda su configuración."""
    tid = _tenant(db_engine, "Empresa con ejecuciones (ficticia)", "synthetic_retail_a", {"service_level": 0.95})
    with db_engine.begin() as conn:
        settings = conn.execute(text("SELECT settings FROM tenants WHERE id = :t"), {"t": tid}).scalar_one()
        record = effective_config_record("supply", settings, "synthetic_retail_a")
        run_id = conn.execute(text(
            "INSERT INTO core_runs (tenant_id, core, operation, version, status, config_version, effective_config, created_at, updated_at) "
            "VALUES (:t, 'supply', 'plan', 1, 'completed', :v, CAST(:c AS jsonb), now(), now()) RETURNING id"
        ), {"t": tid, "v": record["config_version"], "c": json.dumps(record["effective_config"])}).scalar_one()
    before = _get(client, tid).json()
    assert before["config_version"] == record["config_version"]

    with db_engine.begin() as conn:  # el usuario cambia su configuración
        conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"), {"s": json.dumps({"service_level": 0.8}), "t": tid})
    after = _get(client, tid).json()
    assert after["config_version"] != before["config_version"] and _by_key(after)["service_level"]["value"] == 0.8

    with db_engine.connect() as conn:
        stored = conn.execute(text("SELECT config_version, effective_config FROM core_runs WHERE id = :r"), {"r": run_id}).mappings().one()
    assert stored["config_version"] == before["config_version"], "la ejecución histórica conserva su versión de configuración"
    assert stored["effective_config"]["service_level"] == 0.95, "y los valores con los que se calculó"


def test_profiles_and_tenant_extensions_endpoints(client, tenants_cfg):
    profiles = client.get("/v1/platform/profiles", headers=AUTH).json()["profiles"]
    assert [p["code"] for p in profiles] == ["synthetic_process_b", "synthetic_retail_a"]
    assert all(p["synthetic"] for p in profiles)
    retail = client.get(f"/v1/platform/tenants/{tenants_cfg['retail']}/extensions", headers=AUTH).json()
    assert retail["profile"]["code"] == "synthetic_retail_a"
    assert {p["kind"] for p in retail["providers"]} == {"calendar", "feature", "constraint"}
    assert all(p["units"] and p["version"] for p in retail["providers"])
    plain = client.get(f"/v1/platform/tenants/{tenants_cfg['plain']}/extensions", headers=AUTH).json()
    assert plain["profile"] is None and plain["providers"] == [] and "core" in plain["note"]
    assert client.get("/v1/platform/profiles").status_code == 401


# ───────────────────────── calendario y cobertura leídos por el motor ─────────────────────────

def _event(conn, tenant_id, code, revision=0, known_at=None, status="confirmed", scope="country", scope_ref="EC", starts="2026-12-24", ends="2026-12-26", name=None):
    conn.execute(text(
        "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, source, revision, created_at, updated_at) "
        "VALUES (:t, :sc, :sr, :c, :n, 'holiday', :s, :e, :st, CAST(:k AS timestamptz), 'prueba', :r, now(), now())"
    ), {"t": tenant_id, "sc": scope, "sr": scope_ref, "c": code, "n": name or code, "s": starts, "e": ends, "st": status, "k": known_at, "r": revision})


def test_engine_calendar_loader_respects_known_at_revisions_and_tenant_isolation(client, db_engine, tenants_cfg):
    from datetime import date

    from predictily_engine.extensions.data_core import load_calendar_events

    a, b = tenants_cfg["retail"], tenants_cfg["plain"]
    with db_engine.begin() as conn:
        _event(conn, a, "navidad", 0, "2026-01-10 00:00:00+00")
        _event(conn, a, "navidad", 1, "2026-11-20 00:00:00+00", status="cancelled")   # cancelada, conocida en noviembre
        _event(conn, a, "sin_fecha", 0, None, starts="2026-12-30", ends="2026-12-30")  # known_at desconocido
        _event(conn, a, "paro", 0, "2026-09-01 00:00:00+00", scope="tenant", scope_ref=None, starts="2026-12-28", ends="2026-12-28")
        _event(conn, b, "evento_de_b", 0, "2026-01-01 00:00:00+00", starts="2026-12-25", ends="2026-12-25")
        _event(conn, None, "publico", 0, "2020-01-01 00:00:00+00", starts="2026-12-25", ends="2026-12-25", scope_ref="EC")
    with db_engine.connect() as conn:
        latest = load_calendar_events(conn, a, date(2026, 12, 1), date(2026, 12, 31))
        assert set(latest["code"]) == {"navidad", "sin_fecha", "paro", "publico"}, "ni una fila de la empresa B"
        assert latest.loc[latest["code"] == "navidad", "status"].item() == "cancelled" and latest.loc[latest["code"] == "navidad", "revision"].item() == 1

        october = load_calendar_events(conn, a, date(2026, 12, 1), date(2026, 12, 31), as_of=date(2026, 10, 1))
        assert set(october["code"]) == {"navidad", "paro", "publico"}, "sin_fecha no se puede usar: su known_at es desconocido"
        assert october.loc[october["code"] == "navidad", "status"].item() == "confirmed", "el 1/10 aún no se conocía la cancelación"
        early = load_calendar_events(conn, a, date(2026, 12, 1), date(2026, 12, 31), as_of=date(2026, 8, 1))
        assert set(early["code"]) == {"navidad", "publico"}

        only_own = load_calendar_events(conn, a, date(2026, 12, 1), date(2026, 12, 31), include_public=False)
        assert "publico" not in set(only_own["code"])
        assert set(load_calendar_events(conn, b, date(2026, 12, 1), date(2026, 12, 31))["code"]) == {"evento_de_b", "publico"}

    response = client.get("/v1/data-core/calendar", params={"tenant_id": a, "start": "2026-12-01", "end": "2026-12-31", "as_of": "2026-10-01"}, headers=AUTH)
    assert response.status_code == 200 and {e["code"] for e in response.json()["events"]} == {"navidad", "paro", "publico"}
    assert client.get("/v1/data-core/calendar", params={"tenant_id": a, "start": "2026-12-31", "end": "2026-12-01"}, headers=AUTH).status_code == 422


def test_engine_effective_coverage_follows_the_backend_rule(client, db_engine, tenants_cfg):
    from datetime import date

    from predictily_engine.extensions.data_core import classify_day, effective_coverage

    tid = _tenant(db_engine, "Empresa con cobertura (ficticia)", "ferreteria", {})
    with db_engine.begin() as conn:
        src = [conn.execute(text("INSERT INTO data_sources (tenant_id, type, connector_key, name, config, status, created_at, updated_at) "
                                 "VALUES (:t, 'connector', 'sql_postgresql', :n, '{}', 'active', now(), now()) RETURNING id"), {"t": tid, "n": f"S{i}"}).scalar_one() for i in (1, 2)]
        for sid, through, basis in ((src[0], "2026-10-20", "inferred"), (src[1], "2026-10-10", "inferred")):
            conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, data_source_id, complete_from, complete_through, basis, created_at, updated_at) "
                              "VALUES (:t, 'sales', :s, '2026-01-01', :th, :b, now(), now())"), {"t": tid, "s": sid, "th": through, "b": basis})
    with db_engine.connect() as conn:
        eff = effective_coverage(conn, tid, "sales")
        assert eff["rule"] == "min_over_sources" and eff["complete_through"] == date(2026, 10, 10), "todas las fuentes deben estar completas"
        assert effective_coverage(conn, tid, "inventory")["rule"] == "none"
    with db_engine.begin() as conn:
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, complete_through, basis, created_at, updated_at) VALUES (:t, 'sales', '2026-10-25', 'declared', now(), now())"), {"t": tid})
    with db_engine.connect() as conn:
        eff = effective_coverage(conn, tid, "sales")
        assert eff["rule"] == "declared_by_user" and eff["complete_through"] == date(2026, 10, 25), "la declaración del usuario manda"
    assert classify_day(eff, date(2026, 10, 3), has_records=False) == "zero_observed"
    assert classify_day(eff, date(2026, 10, 28), has_records=False) == "absent"
    assert classify_day(eff, date(2026, 10, 28), has_records=True) == "observed"
    assert classify_day({"complete_from": None, "complete_through": None}, date(2026, 10, 3), has_records=False) == "absent"
    body = client.get("/v1/data-core/coverage", params={"tenant_id": tid}, headers=AUTH).json()
    assert body["coverage"]["sales"]["complete_through"] == "2026-10-25" and body["coverage"]["inventory"]["rule"] == "none"
    other = client.get("/v1/data-core/coverage", params={"tenant_id": tenants_cfg["plain"], "entity": "sales"}, headers=AUTH).json()
    assert other["coverage"]["sales"]["rule"] == "none", "la cobertura de otra empresa no se filtra"


def test_response_matches_the_contract_fixture_shared_with_the_backend(client, tenants_cfg):
    """El backend (proxy) prueba contra este mismo archivo; si la respuesta del motor cambia de forma, falla aquí."""
    from pathlib import Path

    fixture = json.loads((Path(__file__).resolve().parents[3] / "backend/tests/Feature/DataCore/fixtures/engine-effective-config.json").read_text())
    real = _get(client, tenants_cfg["process"]).json()
    assert set(real) == set(fixture)
    assert real["precedence"] == fixture["precedence"]
    assert all(set(k) == {"key", "value", "source"} for k in real["keys"]) and all(set(k) == {"key", "value", "source"} for k in fixture["keys"])
    assert set(real["profile"]) == set(fixture["profile"]) == {"code", "version"}
