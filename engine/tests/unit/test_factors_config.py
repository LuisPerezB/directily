"""ADR-V25-25 · el pipeline de factores externos es DESACTIVABLE: bloque `factors` de `platform/config.py`, APAGADO por defecto.

Con el pipeline apagado no entra ningún factor externo: el resolvedor no consulta nada y los marcos del builder no cambian.
(Las pruebas contra la base de datos están en `tests/integration/test_factors_t11_integration.py`.)
"""

from __future__ import annotations

from dataclasses import replace

import pandas as pd
import pytest

from predictily_engine.availability import FactorPipeline, FactorResolution
from predictily_engine.availability.pipeline import (
    active_factor_keys,
    factor_config_errors,
    factor_pipeline_limitations,
    validate_factor_config,
)
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import (
    CORE_DEFAULTS,
    ConfigValidationError,
    effective_config_record,
    resolve_config,
    validate_overrides,
)


class UntouchableRepo:
    """Repositorio que falla ante CUALQUIER acceso: demuestra que un pipeline apagado no consulta la base."""

    def __getattr__(self, name):
        raise AssertionError(f"el pipeline de factores apagado no debe tocar el repositorio ({name})")


def test_the_factors_core_is_off_by_default_and_declares_its_keys():
    cfg, version, provenance = resolve_config("factors", {})
    assert cfg["enabled"] is False
    assert "active_factors" not in cfg, "la activación por factor NO vive aquí: es tenants.settings.factor_activation"
    assert cfg["indicator_precedence"] == {} and cfg["calendar_precedence"] == {} and cfg["definition_precedence"] == {}
    assert set(provenance.values()) == {"core"} and version
    assert "factors" in CORE_DEFAULTS and set(cfg) == set(CORE_DEFAULTS["factors"])
    # Antigüedades máximas por frecuencia: valores iniciales a aprobar en el piloto; deben existir y ser positivos.
    assert all(isinstance(v, int) and v > 0 for v in cfg["staleness_days_by_frequency"].values()) and cfg["default_max_staleness_days"] > 0


def test_a_company_enables_the_pipeline_with_the_usual_precedence_and_the_run_records_it():
    tenant = {"cores": {"factors": {"enabled": True, "indicator_precedence": {"ipc": "tenant"}}},
              "factor_activation": {"ipc": {"active": True, "updated_by": 7}}}
    cfg, version, provenance = resolve_config("factors", tenant)
    assert cfg["enabled"] is True and cfg["indicator_precedence"] == {"ipc": "tenant"}
    assert provenance["enabled"] == "tenant" and provenance["default_max_staleness_days"] == "core"
    off_version = resolve_config("factors", {})[1]
    assert version != off_version, "cambiar el interruptor cambia la huella de la configuración efectiva"
    record = effective_config_record("factors", tenant)
    assert record["core"] == "factors" and record["effective_config"]["enabled"] is True and record["config_version"] == version
    assert FactorPipeline.from_settings(tenant).active_factors == ["ipc"]


def test_a_factor_is_active_only_with_the_global_switch_and_its_own_activation():
    """`factors.enabled` Y `factor_activation.<clave>.active`: ninguna de las dos basta por sí sola."""
    activation = {"factor_activation": {"b_clima": {"active": True}, "a_ipc": {"active": True}, "c_off": {"active": False},
                                        "d_malformado": True, "e_sin_active": {"updated_by": 1}, "f_texto": {"active": "true"}}}
    on = {"cores": {"factors": {"enabled": True}}}
    assert active_factor_keys(activation) == ["a_ipc", "b_clima"], "ordenadas; solo `active` literalmente verdadero"
    assert FactorPipeline.from_settings({**on, **activation}).active_factors == ["a_ipc", "b_clima"]
    assert FactorPipeline.from_settings(activation).active_factors == [], "activación sin el interruptor global: ninguno"
    assert FactorPipeline.from_settings({"cores": {"factors": {"enabled": True}}, "factor_activation": {"x": {"active": False}}}
                                        ).active_factors == [], "interruptor sin activación del factor: ninguno"
    assert FactorPipeline.from_settings({**on, "factor_activation": "roto"}).active_factors == []
    assert active_factor_keys(None) == [] and active_factor_keys({"factor_activation": []}) == []


def test_invalid_factor_settings_are_rejected_not_applied_silently():
    errors = {e["key"]: e["code"] for e in validate_overrides("factors", {"enabled": "si", "default_max_staleness_days": 0,
                                                                          "active_factors": ["ipc"], "clima": True})}
    assert errors == {"enabled": "invalid_type", "default_max_staleness_days": "out_of_range", "active_factors": "unknown_key",
                      "clima": "unknown_key"}
    with pytest.raises(ConfigValidationError):
        resolve_config("factors", {"cores": {"factors": {"enabled": "yes"}}})
    assert validate_factor_config({"indicator_precedence": {"a": "x"}, "staleness_days_by_frequency": {"daily": 0}}) != []
    with pytest.raises(ValueError, match="inválida"):
        FactorPipeline({"enabled": True, "definition_precedence": {"a": "both"}})


def test_the_nested_factor_maps_are_validated_when_the_company_config_is_resolved():
    """Los mapas anidados no los valida el tipo «objeto»: `validate_overrides` los revisa con `factor_config_errors`."""
    bad = {"indicator_precedence": {"ipc": "ambos"}, "calendar_precedence": {"X": 1}, "definition_precedence": {"k": "tenant"},
           "staleness_days_by_frequency": {"daily": 0, "weekly": "21"}}
    by_key = {e["key"]: e for e in validate_overrides("factors", bad)}
    assert set(by_key) == {"indicator_precedence", "calendar_precedence", "staleness_days_by_frequency"}
    assert all(e["code"] == "invalid_value" for e in by_key.values())
    assert [e["key"] for e in factor_config_errors({"calendar_precedence": {"X": "public"}})] == []
    with pytest.raises(ConfigValidationError) as raised:
        resolve_config("factors", {"cores": {"factors": {"indicator_precedence": {"ipc": "ambos"}}}})
    assert raised.value.errors[0]["key"] == "indicator_precedence"
    assert validate_overrides("factors", {"indicator_precedence": {"ipc": "tenant"}, "calendar_precedence": {"X": "public"},
                                          "staleness_days_by_frequency": {"daily": 3}}) == []


def test_a_switched_off_pipeline_resolves_nothing_and_never_queries():
    pipeline = FactorPipeline.from_settings({})
    assert pipeline.enabled is False
    resolution = pipeline.resolve(UntouchableRepo(), ["2026-10-01"], "2026-10-01")
    assert isinstance(resolution, FactorResolution)
    assert (resolution.enabled, resolution.reason, resolution.used, resolution.dropped) == (False, "disabled", [], [])
    assert resolution.feature_columns == [] and resolution.report()["columns"] == []
    # Aunque la empresa tenga factores «activos», sin el interruptor general no entra ninguno.
    configured = FactorPipeline.from_settings({"factor_activation": {"ipc": {"active": True}}})
    assert configured.enabled is False and configured.resolve(UntouchableRepo(), ["2026-10-01"], "2026-10-01").reason == "disabled"
    # Solo `enabled = true` literal lo enciende (no basta un valor «verdadero»).
    assert FactorPipeline({**CORE_DEFAULTS["factors"], "enabled": 1}).enabled is False


def test_an_enabled_pipeline_without_active_factors_resolves_nothing_either():
    for settings in ({"cores": {"factors": {"enabled": True}}},
                     {"cores": {"factors": {"enabled": True}}, "factor_activation": {"ipc": {"active": False}}},
                     {"cores": {"factors": {"enabled": True}}, "factor_activation": {"ipc": "si"}}):
        pipeline = FactorPipeline.from_settings(settings)
        resolution = pipeline.resolve(UntouchableRepo(), ["2026-10-01"], "2026-10-01")
        assert pipeline.enabled and resolution.enabled and resolution.reason == "no_active_factors" and resolution.feature_columns == []


# ───────────────────────── factors.enabled sin cablear: limitación explícita ─────────────────────────

def test_the_limitation_is_recorded_only_when_the_pipeline_is_enabled_but_not_connected():
    assert factor_pipeline_limitations({}) == [] and factor_pipeline_limitations(None) == []
    assert factor_pipeline_limitations({"factor_activation": {"ipc": {"active": True}}}) == []
    enabled = factor_pipeline_limitations({"cores": {"factors": {"enabled": True}}})
    assert [x["code"] for x in enabled] == ["factor_pipeline_not_connected"] and "no está conectado" in enabled[0]["message"]
    broken = factor_pipeline_limitations({"cores": {"factors": {"enabled": True, "indicator_precedence": {"a": "x"}}}})
    assert [x["code"] for x in broken] == ["factors_config_invalid"]


@pytest.fixture(scope="module")
def demand_runs(small_data):
    def run(settings):
        return run_pipeline(replace(small_data, settings={**small_data.settings, **settings}), horizon=30, seed=42, n_jobs=1)

    enabled = {"cores": {"factors": {"enabled": True}}, "factor_activation": {"ipc": {"active": True}}}
    return {"default": run({}), "enabled": run(enabled)}


def test_a_demand_run_with_the_pipeline_enabled_reports_that_it_is_not_connected_and_changes_nothing(demand_runs):
    default, enabled = demand_runs["default"], demand_runs["enabled"]
    assert "factor_pipeline_not_connected" not in {x["code"] for x in default.limitations}
    assert [x for x in enabled.limitations if x["code"] == "factor_pipeline_not_connected"], "limitación explícita"
    columns = ["node_key", "granularity", "horizon_step", "yhat", "lo_95", "hi_95", "case_label", "quality_status"]

    def day(result):
        fv = result.forecast_values
        return fv[fv["granularity"] == "day"][columns].sort_values(["node_key", "horizon_step"]).reset_index(drop=True)

    pd.testing.assert_frame_equal(day(default), day(enabled))      # sin cablear, ningún valor cambia
