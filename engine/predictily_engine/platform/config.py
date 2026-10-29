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
        "interval_level": 0.8,               # intervalo de la cantidad condicionada (conformal)
        "inactivity_min_intervals": 4,
        "inactivity_at_risk_quantile": 0.75,
        "inactivity_inactive_quantile": 0.90,
        "inactivity_outcome_days": 90,       # ventana observable para evaluar la definición de inactividad
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
        "solver_mip_gap": 1e-4,
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
        "methods": ["last_value", "mean_3", "drift", "ets_trend"],
        "null_known_at_policy": "strict",    # strict | assume_valid_from (degrada la calidad)
        "interval_level": 0.8,
        "max_series": 2000,
    },
    "scenario": {
        "horizon_days": 90,
        "period_days": 30,
        "min_service_to_flag": 0.9,
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
