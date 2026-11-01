"""Configuración por precedencia y puntos de extensión (instrucctions.md §6.H, ADR-V2-06). Sin base de datos."""

from __future__ import annotations

import copy
import inspect
from datetime import date, datetime, timezone

import pandas as pd
import pytest

from predictily_engine.extensions import synthetic_process_b, synthetic_retail_a
from predictily_engine.platform import config as cfgmod
from predictily_engine.platform.config import (
    CORE_DEFAULTS,
    ConfigValidationError,
    effective_config_record,
    resolve_config,
    validate_overrides,
)
from predictily_engine.platform.extensions import (
    CalendarEventRecord,
    ExtensionProvider,
    SectorProfile,
    get_profile,
    providers_for,
    register_profile,
    registered_profiles,
    run_provider_contract,
    validate_provider,
)

PROFILES = ["synthetic_retail_a", "synthetic_process_b"]
AS_OF = date(2026, 10, 1)


# ───────────────────────── precedencia: core → perfil → empresa ─────────────────────────

def test_company_without_profile_uses_only_core_values():
    cfg, version, source = resolve_config("supply", {}, "ferreteria")
    assert cfg == CORE_DEFAULTS["supply"]
    assert set(source.values()) == {"core"}
    assert providers_for("ferreteria") == [] and providers_for(None) == []
    assert get_profile("ferreteria") is None
    assert version == resolve_config("supply", {}, None)[1], "sin perfil, el sector no cambia la configuración"


def test_profile_overrides_core_and_tenant_overrides_profile_with_provenance():
    cfg, _, source = resolve_config("supply", {}, "synthetic_process_b")
    assert cfg["default_lead_time_days"] == 21 and cfg["service_level"] == 0.90
    assert source["default_lead_time_days"] == "profile:synthetic_process_b@1.0.0"
    assert source["objective"] == "core"

    # Clave de la etapa 1 en tenants.settings: gana al perfil.
    cfg, _, source = resolve_config("supply", {"service_level": 0.99}, "synthetic_process_b")
    assert cfg["service_level"] == 0.99 and source["service_level"] == "tenant"
    assert cfg["default_lead_time_days"] == 21 and source["default_lead_time_days"].startswith("profile:")

    # Clave de la etapa 2 (settings.cores.supply): también gana al perfil y al core.
    settings = {"cores": {"supply": {"default_lead_time_days": 9, "objective": "max_margin"}}}
    cfg, _, source = resolve_config("supply", settings, "synthetic_process_b")
    assert cfg["default_lead_time_days"] == 9 and source["default_lead_time_days"] == "tenant"
    assert cfg["objective"] == "max_margin" and source["objective"] == "tenant"


def test_two_profiles_resolve_differently_with_the_same_interfaces():
    a, version_a, _ = resolve_config("supply", {}, "synthetic_retail_a")
    b, version_b, _ = resolve_config("supply", {}, "synthetic_process_b")
    assert a["default_lead_time_days"] != b["default_lead_time_days"]
    assert version_a != version_b, "la configuración efectiva distinta produce otro config_version"
    assert resolve_config("supply", {}, "synthetic_retail_a")[1] == version_a, "estable ante repetición"


def test_unknown_keys_types_and_ranges_are_rejected_not_applied():
    with pytest.raises(ConfigValidationError) as exc:
        resolve_config("demand", {"cores": {"demand": {"clave_inventada": 1}}}, None)
    assert exc.value.errors[0]["code"] == "unknown_key"
    assert isinstance(exc.value, ValueError), "compatibilidad con los llamadores que capturan ValueError"

    errors = {e["key"]: e["code"] for e in validate_overrides("demand", {"service_level": 1.5, "review_period_days": "siete", "horizons": [7, 45], "min_improvement": True})}
    assert errors == {"service_level": "out_of_range", "review_period_days": "invalid_type", "horizons": "invalid_choice", "min_improvement": "invalid_type"}
    assert validate_overrides("demand", {"service_level": 0.9, "horizons": [7, 30]}) == []
    assert [e["code"] for e in validate_overrides("nucleo_inexistente", {})] == ["unknown_core"]
    with pytest.raises(ValueError):
        resolve_config("nucleo_inexistente", {}, None)


def test_a_profile_cannot_smuggle_unknown_or_invalid_values():
    bad = SectorProfile(code="synthetic_bad", version="1.0.0", description="x", overrides={"supply": {"no_existe": 1}})
    register_profile(bad)
    try:
        with pytest.raises(ConfigValidationError, match="synthetic_bad"):
            resolve_config("supply", {}, "synthetic_bad")
    finally:
        from predictily_engine.platform import extensions as ext

        ext._REGISTRY.pop("synthetic_bad", None)


def test_changing_parameters_does_not_rewrite_recorded_runs():
    """Cada ejecución guarda su configuración efectiva: cambiar la de la empresa después no la modifica."""
    before = effective_config_record("supply", {"service_level": 0.95}, "synthetic_retail_a")
    stored = copy.deepcopy(before)  # lo que una ejecución persistió
    after = effective_config_record("supply", {"service_level": 0.80}, "synthetic_retail_a")
    assert after["config_version"] != stored["config_version"]
    assert after["effective_config"]["service_level"] == 0.80
    assert stored["effective_config"]["service_level"] == 0.95 and stored["profile"] == {"code": "synthetic_retail_a", "version": "1.0.0"}
    # El registro es reproducible: los mismos insumos devuelven el mismo hash.
    assert effective_config_record("supply", {"service_level": 0.95}, "synthetic_retail_a")["config_version"] == stored["config_version"]


def test_resolved_config_never_aliases_core_defaults():
    cfg, _, _ = resolve_config("demand", {}, None)
    cfg["horizons"].append(999)
    assert 999 not in CORE_DEFAULTS["demand"]["horizons"]
    assert 999 not in resolve_config("demand", {}, None)[0]["horizons"]


def test_config_hash_is_order_independent():
    assert cfgmod.config_hash({"a": 1, "b": 2}) == cfgmod.config_hash({"b": 2, "a": 1})


# ───────────────────────── proveedores: contrato común ─────────────────────────

@pytest.mark.parametrize("code", PROFILES)
def test_every_provider_of_every_profile_honours_the_contract(code):
    profile = get_profile(code)
    assert profile is not None and profile.synthetic
    assert {p.kind for p in profile.providers} >= {"calendar", "feature", "constraint"}
    for provider in profile.providers:
        assert validate_provider(provider) == [], provider.name
        assert run_provider_contract(provider, AS_OF, date(2026, 9, 1), date(2027, 3, 31)) == [], provider.name


def test_both_profiles_use_the_same_interfaces():
    """El core solo conoce la firma por tipo de proveedor: ambos perfiles la implementan igual."""
    by_kind: dict[str, set[tuple[str, ...]]] = {}
    for code in PROFILES:
        for p in get_profile(code).providers:
            by_kind.setdefault(p.kind, set()).add(tuple(inspect.signature(p.fn).parameters))
    assert by_kind["calendar"] == {("start", "end", "as_of")}
    assert by_kind["feature"] == {("dates", "as_of", "observations")}
    assert by_kind["constraint"] == {("as_of",)}
    assert {p.kind for p in get_profile("synthetic_process_b").providers} - {p.kind for p in get_profile("synthetic_retail_a").providers} == {"transformation"}
    assert [p.code for p in registered_profiles()] == sorted(PROFILES)


def test_calendar_providers_return_only_what_was_known_at_the_cutoff():
    start, end = date(2026, 12, 1), date(2027, 3, 1)
    before_announcement = synthetic_retail_a.calendar(start, end, date(2026, 10, 1))
    after_announcement = synthetic_retail_a.calendar(start, end, date(2026, 12, 1))
    assert before_announcement == [], "la liquidación de febrero 2027 se anunció el 15/11/2026: no se conocía el 1/10"
    assert [e.code for e in after_announcement] == ["liquidacion_febrero"]
    assert after_announcement[0].known_at == datetime(2026, 11, 15, tzinfo=timezone.utc)
    # Paro de mantenimiento (perfil B): planificado 60 días antes.
    assert synthetic_process_b.calendar(date(2027, 4, 1), date(2027, 4, 3), date(2027, 1, 20)) == []
    assert len(synthetic_process_b.calendar(date(2027, 4, 1), date(2027, 4, 3), date(2027, 3, 1))) == 1


def test_feature_provider_ignores_observations_after_the_cutoff():
    dates = pd.date_range("2026-09-20", "2026-10-10", freq="D")
    base = synthetic_process_b.ambient_temperature(dates, AS_OF, None)
    perturbed = synthetic_process_b.ambient_temperature(dates, AS_OF, {date(2026, 10, 5): 99.0, date(2026, 10, 9): -50.0})
    pd.testing.assert_frame_equal(base, perturbed)  # lo realizado después del corte no filtra hacia atrás
    seen = synthetic_process_b.ambient_temperature(dates, AS_OF, {date(2026, 9, 25): 31.5})
    assert seen.loc["2026-09-25", "ambient_temperature_c"] == 31.5, "lo observado hasta el corte sí se usa"
    deterministic = synthetic_retail_a.foot_traffic(dates, AS_OF, {date(2026, 10, 5): 1e6})
    pd.testing.assert_frame_equal(deterministic, synthetic_retail_a.foot_traffic(dates, AS_OF, None))


def test_contract_checker_detects_violations():
    def bad_calendar(start, end, as_of):
        return [CalendarEventRecord(scope="planeta", scope_ref=None, code="x", name="x", category="holiday", starts_on=date(2026, 12, 2),
                                    ends_on=date(2026, 12, 1), known_at=datetime(2027, 1, 1, tzinfo=timezone.utc))]

    def bad_feature(dates, as_of, observations):
        return pd.DataFrame({"otra": [None] * len(dates)}, index=dates)

    problems = run_provider_contract(ExtensionProvider("calendar", "malo", "1.0", outputs=("e",), units={"e": "event"}, fn=bad_calendar), AS_OF, date(2026, 12, 1), date(2026, 12, 31))
    assert any("catálogo" in p for p in problems) and any("termina antes" in p for p in problems) and any("después del corte" in p or "se devolvió" in p for p in problems)
    problems = run_provider_contract(ExtensionProvider("feature", "malo2", "1.0", outputs=("x",), units={"x": "u"}, fn=bad_feature), AS_OF, date(2026, 12, 1), date(2026, 12, 5))
    assert any("columnas" in p for p in problems) and any("nulos" in p for p in problems)
    assert validate_provider(ExtensionProvider("feature", "sin_unidad", "1.0", outputs=("x",), units={}, fn=lambda *a: None))
    assert validate_provider(ExtensionProvider("feature", "sin_version", "", outputs=("x",), units={"x": "u"}, fn=lambda *a: None))
    assert validate_provider(ExtensionProvider("raro", "tipo", "1.0", fn=None))
    with pytest.raises(ValueError, match="incumplen el contrato"):
        register_profile(SectorProfile(code="synthetic_roto", version="1.0.0", description="x",
                                       providers=(ExtensionProvider("feature", "sin_salidas", "1.0", fn=lambda *a: None),)))


def test_registering_the_same_profile_with_another_version_is_refused():
    with pytest.raises(ValueError, match="otra versión"):
        register_profile(SectorProfile(code="synthetic_retail_a", version="9.9.9", description="x"))
    register_profile(synthetic_retail_a.PROFILE)  # el mismo perfil y versión es idempotente
