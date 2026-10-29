"""Configuración efectiva por precedencia (ADR-V2-06): core → perfil sectorial → empresa.

`resolve_config` devuelve la configuración efectiva, su hash (`config_version`) y la procedencia de
cada clave. Las claves de empresa fuera del esquema del core se rechazan (no se aplican en silencio).
"""

from __future__ import annotations

import copy
import hashlib
import json
from typing import Any

from predictily_engine.platform.extensions import get_profile

# Valores del core. Cada paquete amplía SOLO su sección.
CORE_DEFAULTS: dict[str, dict[str, Any]] = {
    "demand": {
        "horizons": [7, 14, 30, 60, 90],
        "service_level": 0.95,
        "review_period_days": 7,
        "default_lead_time_days": 7,
        "min_improvement": 0.03,
        "max_backtest_windows": 3,
    },
    "customer": {
        "horizons": [7, 30, 90],
        "exclude_generic_buyers": True,
        "generic_buyer_codes": ["9999999999999", "9999999999"],
        "min_history_days": 90,
    },
    "supply": {
        "service_level": 0.95,
        "review_period_days": 7,
        "default_lead_time_days": 7,
        "solver_time_limit_seconds": 30,
    },
    "cost": {"horizons": [30, 90]},
    "scenario": {},
}

# Claves de tenants.settings que mapean a cada core (compatibilidad con la etapa 1).
TENANT_SETTING_KEYS: dict[str, tuple[str, ...]] = {
    "demand": ("service_level", "review_period_days", "default_lead_time_days"),
    "supply": ("service_level", "review_period_days", "default_lead_time_days"),
    "customer": (),
    "cost": (),
    "scenario": (),
}


def config_hash(cfg: dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(cfg, sort_keys=True, default=str).encode()).hexdigest()


def resolve_config(core: str, tenant_settings: dict[str, Any] | None, sector: str | None = None,
                   tenant_overrides: dict[str, Any] | None = None) -> tuple[dict[str, Any], str, dict[str, str]]:
    """Configuración efectiva de `core` para una empresa.

    tenant_settings: `tenants.settings` (claves de etapa 1). tenant_overrides: sección
    `settings.cores.<core>` (claves de etapa 2). Devuelve (config, config_version, procedencia).
    """
    if core not in CORE_DEFAULTS:
        raise ValueError(f"Core desconocido: {core}")
    cfg = copy.deepcopy(CORE_DEFAULTS[core])
    source = {k: "core" for k in cfg}
    profile = get_profile(sector)
    if profile is not None:
        for k, v in profile.overrides.get(core, {}).items():
            if k not in cfg:
                raise ValueError(f"El perfil {profile.code} define una clave desconocida para {core}: {k}")
            cfg[k] = copy.deepcopy(v)
            source[k] = f"profile:{profile.code}@{profile.version}"
    settings = tenant_settings or {}
    for k in TENANT_SETTING_KEYS.get(core, ()):
        if settings.get(k) is not None:
            cfg[k] = settings[k]
            source[k] = "tenant"
    overrides = tenant_overrides if tenant_overrides is not None else (settings.get("cores", {}) or {}).get(core, {})
    for k, v in (overrides or {}).items():
        if k not in cfg:
            raise ValueError(f"Clave de configuración desconocida para {core}: {k}")
        cfg[k] = v
        source[k] = "tenant"
    return cfg, config_hash({"core": core, "config": cfg}), source
