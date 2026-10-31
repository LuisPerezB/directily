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
        "exceptional_events_flag": True,
    },
    "customer": {
        "horizons": [7, 30, 90],
        "exclude_generic_buyers": True,
        "generic_buyer_codes": ["9999999999999", "9999999999"],
        "min_history_days": 90,
        # ── P2 · dataset de cortes (docs/architecture/v2/P2-comportamiento.md §2) ──
        "cutoff_step_days": 14,              # separación entre cortes históricos
        "max_cutoffs": 60,                   # tope de cortes (se conservan los más recientes)
        "min_relation_purchases": 1,         # compras previas mínimas para ser candidato
        "candidate_max_recency_days": 365,   # relaciones sin compra hace más de N días no son candidatas
        "max_candidates_per_customer": 300,
        "max_dataset_rows": 1_500_000,
        # ── censura por disponibilidad (la fila completa se excluye; nunca es un negativo) ──
        "min_availability_share": 0.8,       # fracción mínima de días con stock en la sucursal habitual
        "min_availability_known_share": 0.5, # si hay menos días con inventario conocido, la disponibilidad es desconocida
        # ── protocolo temporal (cortes con separación de H días entre bloques) ──
        "block_fractions": {"train": 0.55, "calibration": 0.15, "selection": 0.12, "holdout": 0.18},
        "lean_block_fractions": {"train": 0.6, "calibration": 0.2, "holdout": 0.2},  # sin bloque de selección
        "min_block_cutoffs": 2,
        "min_block_rows": 300,
        "min_train_positives": 100,
        "min_calibration_positives": 30,
        "min_holdout_positives": 30,
        "isotonic_min_positives": 150,       # menos positivos de calibración ⇒ Platt
        "max_train_rows": 400_000,           # por encima se submuestrean negativos (con corrección de prior)
        # ── regla de promoción versionada ──
        "promotion_rule_version": "cust-promotion-v1",
        "promotion_min_rel_brier_improvement": 0.02,
        "promotion_qty_min_rel_mae_improvement": 0.03,
        "promotion_qty_max_abs_bias": 0.10,
        "ece_alert_threshold": 0.05,         # ECE del holdout por encima ⇒ calibración "degradada"
        # ── salidas ──
        "ranking_k": 10,
        "ranking_eval_ks": [1, 3, 5, 10],
        # ── regla de la fuente del ranking servido (cust-ranking-v1; ADR-P2-01 en docs/architecture/v2/P2-comportamiento.md) ──
        "ranking_rule_version": "cust-ranking-v1",
        "ranking_primary_k": 5,              # k de precision@k con el que se elige la fuente del ranking en el holdout
        "ranking_min_relative_margin": 0.05, # una fuente distinta de popularidad debe superarla en ≥ 5 % relativo de precision@k
        "ranking_min_units": 200,            # unidades (corte×comprador con ≥ k candidatos) mínimas; con menos se sirve popularidad
        "interval_level": 0.8,               # intervalo de la cantidad condicionada (conformal)
        "inactivity_min_intervals": 4,
        "inactivity_tolerance_fraction": 0.2, # holgura sobre los percentiles propios: fracción de la mediana de intervalos
        "inactivity_at_risk_quantile": 0.75,
        "inactivity_inactive_quantile": 0.90,
        "inactivity_outcome_days": 90,       # ventana observable para evaluar la definición de inactividad
        "inactivity_naive_days": 60,         # referencia ingenua: «sin compras en N días»
        "frequency_lookback_days": 180,      # ventana de la frecuencia de compra (se comparan 90 y 365)
        "behavior_window_days": 90,
        "stale_data_days": 7,                # ventas sin actualizar más de N días ⇒ resultado degradado
        "retain_prediction_runs": 30,        # ejecuciones predict cuyas filas se conservan
        "top_k_stored": 10,
        "reconciliation_tolerance": 0.10,    # exceso de Σ compradores sobre el agregado que se considera mayor
        "seed": 42,
    },
    "supply": {
        "service_level": 0.95,
        "review_period_days": 7,
        "default_lead_time_days": 7,
        "solver_time_limit_seconds": 30,
        # ── P4 · planificador de reposición (docs/architecture/v2/P4-decisiones.md) ──
        "objective": "min_cost",             # min_cost | max_margin
        "coverage_mode": "soft",             # soft: demanda no atendida permitida y penalizada; hard: debe cubrirse
        "planning_horizon_days": 84,
        "solver_mip_gap": 1e-4,                # «óptimo» = brecha probada ≤ 0,01 %; con una tolerancia mayor y brecha superior = factible con brecha
        "holding_cost_rate_annual": 0.20,    # SUPUESTO: costo anual de mantener inventario como fracción del costo
        "shortage_penalty_multiplier": 2.0,  # SUPUESTO: penalización por unidad no atendida = k × costo puesto en bodega (k > 1)
        "safety_stock_weight": 0.9,          # SUPUESTO: peso del déficit de stock de seguridad frente a una venta perdida (< 1)
        "undated_receipt_policy": "lead_time_end",   # lead_time_end | observed_lead_time | exclude
        "overdue_receipt_policy": "lead_time_end",
        "use_implicit_offers": True,         # productos sin oferta usan su último costo conocido y su plazo
        "fx_max_age_days": 30,
        "diagnose_unmet": True,
        "max_nodes": 3000,
        "batch_integer": True,
        "historical_review_days": 7,
    },
    "cost": {
        "horizons": [30, 90],
        "period_days": 30,                   # frecuencia de agregación de costos (el costo no cambia a diario)
        "min_observations": 6,               # períodos con costo observado para pronosticar
        "holdout_periods": 3,                # últimos períodos reservados para evaluar métodos
        "methods": ["last_value", "mean_3", "drift", "holt_damped"],
        "min_improvement": 0.03,             # mejora relativa mínima del MAE de selección para servir un método distinto de la referencia
        "min_train_periods": 3,
        "null_known_at_policy": "strict",    # strict | assume_valid_from (degrada la calidad)
        "interval_level": 0.8,
        "max_series": 2000,
    },
    "scenario": {
        "horizon_days": 90,
        "period_days": 7,                    # paso de la dinámica de inventario del evaluador
        "max_strategies": 8,
        "price_window_days": 28,             # ventana del precio neto observado
        "default_unit_cost_unknown": None,   # costo desconocido ⇒ margen desconocido (no se rellena)
    },
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


# ── Validación de la configuración (claves desconocidas, tipos y rangos) ──────────────────────────────────
# Restricciones por (core, clave). Cada paquete puede añadir las de sus claves; las claves sin entrada solo
# se validan por tipo (el del valor por defecto). min/max: rango numérico; choices: valores permitidos;
# subset_of: cada elemento de la lista debe pertenecer al conjunto.
CONFIG_CONSTRAINTS: dict[tuple[str, str], dict[str, Any]] = {
    ("demand", "service_level"): {"min": 0.5, "max": 0.999},
    ("supply", "service_level"): {"min": 0.5, "max": 0.999},
    ("demand", "review_period_days"): {"min": 1, "max": 90},
    ("supply", "review_period_days"): {"min": 1, "max": 90},
    ("demand", "default_lead_time_days"): {"min": 1, "max": 365},
    ("supply", "default_lead_time_days"): {"min": 1, "max": 365},
    ("demand", "horizons"): {"subset_of": [7, 14, 30, 60, 90], "non_empty": True},
    ("customer", "horizons"): {"subset_of": [7, 30, 90], "non_empty": True},
    ("cost", "horizons"): {"subset_of": [30, 90], "non_empty": True},
}


class ConfigValidationError(ValueError):
    """La configuración contiene claves desconocidas o valores fuera del esquema. `errors` lista cada problema."""

    def __init__(self, errors: list[dict[str, Any]]):
        self.errors = errors
        super().__init__("; ".join(f"{e['key']}: {e['message']}" for e in errors))


def _type_error(default: Any, value: Any) -> str | None:
    if default is None:
        return None
    if isinstance(default, bool):
        return None if isinstance(value, bool) else "debe ser verdadero o falso"
    if isinstance(default, int):
        return None if isinstance(value, int) and not isinstance(value, bool) else "debe ser un entero"
    if isinstance(default, float):
        return None if isinstance(value, (int, float)) and not isinstance(value, bool) else "debe ser un número"
    if isinstance(default, str):
        return None if isinstance(value, str) else "debe ser texto"
    if isinstance(default, list):
        if not isinstance(value, list):
            return "debe ser una lista"
        if default and any(_type_error(default[0], v) for v in value):
            return "contiene elementos de un tipo distinto al esperado"
        return None
    if isinstance(default, dict):
        return None if isinstance(value, dict) else "debe ser un objeto"
    return None


def validate_overrides(core: str, overrides: dict[str, Any] | None, origin: str = "tenant") -> list[dict[str, Any]]:
    """Valida un conjunto de valores contra el esquema del core. No modifica nada. Devuelve la lista de errores."""
    if core not in CORE_DEFAULTS:
        return [{"key": "core", "code": "unknown_core", "message": f"Core desconocido: {core}"}]
    defaults = CORE_DEFAULTS[core]
    errors: list[dict[str, Any]] = []
    for key, value in (overrides or {}).items():
        if key not in defaults:
            errors.append({"key": key, "code": "unknown_key", "message": f"Clave de configuración desconocida para {core}: {key}"})
            continue
        problem = _type_error(defaults[key], value)
        if problem:
            errors.append({"key": key, "code": "invalid_type", "message": problem})
            continue
        rule = CONFIG_CONSTRAINTS.get((core, key), {})
        if "min" in rule and value < rule["min"] or "max" in rule and value > rule["max"]:
            errors.append({"key": key, "code": "out_of_range", "message": f"debe estar entre {rule.get('min')} y {rule.get('max')}"})
        elif "choices" in rule and value not in rule["choices"]:
            errors.append({"key": key, "code": "invalid_choice", "message": f"debe ser uno de {rule['choices']}"})
        elif "subset_of" in rule and isinstance(value, list):
            outside = [v for v in value if v not in rule["subset_of"]]
            if outside or (rule.get("non_empty") and not value):
                errors.append({"key": key, "code": "invalid_choice", "message": f"los valores permitidos son {rule['subset_of']}"})
    return errors


def resolve_config(core: str, tenant_settings: dict[str, Any] | None, sector: str | None = None,
                   tenant_overrides: dict[str, Any] | None = None) -> tuple[dict[str, Any], str, dict[str, str]]:
    """Configuración efectiva de `core` para una empresa.

    Precedencia (ADR-V2-06): valores del core → perfil sectorial opcional → `tenants.settings` (claves de la etapa 1)
    → `settings.cores.<core>` de la empresa (claves de la etapa 2). Una clave desconocida o un valor fuera del esquema
    se RECHAZA (ConfigValidationError, subclase de ValueError): nunca se aplica en silencio.

    tenant_settings: `tenants.settings`. tenant_overrides: sustituye a `settings.cores.<core>` (validación en seco).
    Devuelve (config, config_version, procedencia por clave: `core` | `profile:<código>@<versión>` | `tenant`).
    """
    if core not in CORE_DEFAULTS:
        raise ValueError(f"Core desconocido: {core}")
    cfg = copy.deepcopy(CORE_DEFAULTS[core])
    source = {k: "core" for k in cfg}
    profile = get_profile(sector)
    if profile is not None:
        profile_values = profile.overrides.get(core, {})
        errors = validate_overrides(core, profile_values, "profile")
        if errors:
            raise ConfigValidationError([{**e, "message": f"el perfil {profile.code} {e['message']}"} for e in errors])
        for k, v in profile_values.items():
            cfg[k] = copy.deepcopy(v)
            source[k] = f"profile:{profile.code}@{profile.version}"
    settings = tenant_settings or {}
    for k in TENANT_SETTING_KEYS.get(core, ()):
        if settings.get(k) is not None:
            cfg[k] = settings[k]
            source[k] = "tenant"
    overrides = tenant_overrides if tenant_overrides is not None else (settings.get("cores", {}) or {}).get(core, {})
    errors = validate_overrides(core, overrides, "tenant")
    if errors:
        raise ConfigValidationError(errors)
    for k, v in (overrides or {}).items():
        cfg[k] = copy.deepcopy(v)
        source[k] = "tenant"
    return cfg, config_hash({"core": core, "config": cfg}), source


def effective_config_record(core: str, tenant_settings: dict[str, Any] | None, sector: str | None = None,
                            tenant_overrides: dict[str, Any] | None = None) -> dict[str, Any]:
    """Registro que cada ejecución guarda en `effective_config`/`config_version` (reproducible).

    Cambiar después los parámetros de la empresa NO modifica ejecuciones ya registradas: la ejecución conserva la
    configuración con la que se calculó y su hash. Incluye la procedencia por clave y el perfil sectorial aplicado.
    """
    cfg, version, provenance = resolve_config(core, tenant_settings, sector, tenant_overrides)
    profile = get_profile(sector)
    return {
        "core": core,
        "config_version": version,
        "effective_config": cfg,
        "provenance": provenance,
        "profile": {"code": profile.code, "version": profile.version} if profile is not None else None,
    }
