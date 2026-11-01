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

# ── W2-E · horizons · catálogo ÚNICO de horizontes (ADR-V25-02) ────────────────────────────────────────────
# Fuente única en el motor; el backend lo declara en `predictily.horizons.catalog` (backend/config/predictily.php) y una
# prueba verifica que ambos coinciden (engine/tests/unit/test_horizon_catalog.py y Tests\Unit\Horizons\HorizonCatalogTest).
# El plan comercial decide qué subconjunto puede usar cada empresa; el motor calcula lo que se le pide dentro del catálogo.
#  - demand:   todo el catálogo.
#  - customer: 7/14/15/30/90. 60 queda fuera: ninguna fuente lo pide para compra (V2.2: recompra 7/14/30/90; V2.3: 7/15/30) y
#              cada horizonte añade un modelo por objetivo. Para habilitarlo basta agregarlo aquí y en el backend (misma prueba).
#  - cost:     30/90 (frecuencia mensual del costo; no forma parte del catálogo de demanda/compra).
HORIZONS: dict[str, tuple[int, ...]] = {
    "catalog": (7, 14, 15, 30, 60, 90),
    "demand": (7, 14, 15, 30, 60, 90),
    "customer": (7, 14, 15, 30, 90),
    "cost": (30, 90),
}

# Valores del core. Cada paquete amplía SOLO su sección.
CORE_DEFAULTS: dict[str, dict[str, Any]] = {
    "demand": {
        "horizons": list(HORIZONS["demand"]),
        "service_level": 0.95,
        "review_period_days": 7,
        "default_lead_time_days": 7,
        "min_improvement": 0.03,
        "max_backtest_windows": 3,
        "exceptional_events_flag": True,
        # ── ADR-V25-03 · semántica de días sin transacciones ──
        # Regla INFERIDA de días sin fuente (debe aprobarse en el piloto): un día en que una sucursal no registra NINGUNA línea,
        # pese a que la mediana de sus líneas diarias en los `day_status_infer_lookback_days` días previos es ≥
        # `day_status_infer_min_median_lines`, se marca `missing_source` (base `inferred`): no es un cero observado. Los huecos,
        # coberturas y cierres DECLARADOS se aplican siempre; esta regla solo añade lo no declarado y puede apagarse.
        "day_status_infer_gaps": True,
        "day_status_infer_min_median_lines": 5,
        "day_status_infer_lookback_days": 28,
        # ── W3-A · métricas por horizonte y segmento (docs/ml/FORECAST_VALIDATION.md §3). TODOS son valores INICIALES de diseño, «a aprobar
        # en el piloto» con datos reales; ninguno es universal y ninguno cambia lo servido (solo qué métricas se persisten y cómo se marcan). ──
        # Interruptor: apagado no calcula ni persiste las filas level='segment' (las demás métricas no cambian).
        "segment_metrics_enabled": True,
        # WAPE con denominador (Σ reales en unidades) menor que esto es `wape_unstable`: se reporta la métrica alternativa y la columna
        # `wape` queda nula. 30 unidades = el mismo piso con que el selector agrupa clases con poca evidencia (Σ ventas < 30).
        "wape_min_denominator": 30.0,
        # Un segmento con menos de `segment_min_series` series distintas o menos de `segment_min_obs` series-día evaluables es
        # `insufficient_data` (sin cifras): 3 series = el mínimo con que el selector evalúa una clase por separado; 90 series-día = una
        # serie durante un trimestre (≈ 13 semanas completas de la estacionalidad semanal).
        "segment_min_series": 3,
        "segment_min_obs": 90,
        # Tramo de volumen: el `segment_volume_top_share` superior de las series (por unidades imputadas de los últimos
        # `segment_volume_lookback_days` días hasta el corte de cada ventana) frente al resto; 20 % (regla de Pareto, V2.5 §6).
        "segment_volume_top_share": 0.2,
        "segment_volume_lookback_days": 90,
        # Vida de la serie: «nueva» si al corte de la ventana tenía menos de `segment_new_series_days` días de historia (V2.5 §6).
        "segment_new_series_days": 90,
        # Tope de valores por dimensión de alta cardinalidad (categorías, sucursales): se conservan los de mayor volumen.
        "segment_max_values": 50,
        # Diferencia relativa de error por debajo de la cual baseline y seleccionado se consideran «similares» (= min_improvement).
        "segment_similar_tolerance": 0.03,
        # Coeficiente de variación del WAPE entre ventanas por encima del cual el segmento se marca inestable entre ventanas.
        "segment_stability_cv_warn": 0.5,
        # Tope de celdas «que merecen atención» (peor que el baseline, WAPE indefinido/inestable, sin datos) que el resumen de la ejecución
        # (`forecast_runs.summary.segment_metrics`) lista; el detalle completo está en model_evaluations. 20 celdas ≈ 4 KB: el backend
        # devuelve el resumen entero en cada listado de ejecuciones.
        "segment_summary_max_cells": 20,
    },
    "customer": {
        "horizons": list(HORIZONS["customer"]),
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
        "horizons": list(HORIZONS["cost"]),
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
    # ── W2-D · factors · pipeline de factores externos (ADR-V25-06 y ADR-V25-25) ────────────────────────────────────────────────
    # `enabled` es el INTERRUPTOR GLOBAL del pipeline, APAGADO por defecto y por empresa: con enabled=false no entra ningún factor externo
    # al modelo ni se consulta ninguna tabla de factores (availability/pipeline.py). Se resuelve con la MISMA precedencia que el resto
    # (core → perfil → `settings.cores.factors`). QUÉ factores activa la empresa NO está aquí: la fuente de verdad es
    # `tenants.settings.factor_activation.<clave>.active` (backend, PUT /intelligence/factors/{clave}/activation); un factor cuenta solo
    # con enabled=true Y su active=true. Las antigüedades máximas (STALE) son valores INICIALES de diseño, «a aprobar en el piloto»: un
    # múltiplo del período de la fuente más su rezago habitual de publicación, para que un valor no se propague indefinidamente; la
    # definición del factor (max_staleness_days) tiene prioridad. `*_precedence`: {código de indicador | código de evento de calendario |
    # clave de factor: "tenant" | "public"} para desambiguar uno público y uno privado con el mismo código; sin declararla la ambigüedad
    # falla (nunca se combinan en una serie).
    "factors": {
        "enabled": False,
        "default_max_staleness_days": 90,    # sin antigüedad en la definición ni frecuencia conocida
        "staleness_days_by_frequency": {"daily": 7, "weekly": 21, "monthly": 75, "quarterly": 150, "yearly": 400},
        "indicator_precedence": {},
        "calendar_precedence": {},
        "definition_precedence": {},
    },
    "scenario": {
        "horizon_days": 90,
        "period_days": 7,                    # paso de la dinámica de inventario del evaluador
        "max_strategies": 8,
        "price_window_days": 28,             # ventana del precio neto observado
        "default_unit_cost_unknown": None,   # costo desconocido ⇒ margen desconocido (no se rellena)
    },
    # ── W2-C · quality_gates · umbrales de los gates por módulo (ADR-V25-05 y ADR-V25-24) ───────────────────────────────────────
    # Se resuelven con la MISMA precedencia que el resto (core → perfil sectorial → `settings.cores.quality_gates` de la empresa).
    # TODOS son valores INICIALES de diseño, «a aprobar en el piloto» con datos reales; ninguno es universal. Su justificación,
    # la regla que gobierna cada uno y el efecto (BLOCK | DEGRADE | WARN) están en `quality/thresholds.py` y en
    # docs/data/DATA_QUALITY_ENGINE.md §6. Los valores por defecto no deben bloquear fixtures, demo ni E2E (prueba de aceptación).
    "quality_gates": {
        # ventanas de lectura de las señales que salen de la base (días hacia atrás desde el corte)
        "invalid_lookback_days": 180,        # informes de ingestión considerados para el % inválido
        "lag_lookback_days": 90,             # documentos incrementales considerados para el desfase evento→ingestión
        "id_lookback_days": 90,              # ventana de «alias o códigos nuevos recientes»
        "identity_window_days": 180,         # ventana de la proporción de compradores identificados/genéricos
        # demand
        "demand_min_history_days": 28,       # BLOCK: historia de la empresa (primera venta → corte) menor
        "demand_min_source_coverage_pct": 0.5,   # BLOCK: fracción de sucursal-días con cobertura de fuente menor
        "demand_warn_source_coverage_pct": 0.9,  # WARN: cobertura menor
        "demand_block_invalid_pct": 0.5,     # BLOCK: fracción de filas inválidas (informes de ingestión) mayor
        "demand_warn_invalid_pct": 0.05,     # WARN: fracción de filas inválidas mayor
        "demand_warn_key_issue_pct": 0.02,   # WARN: fracción de líneas con producto/sucursal inexistente o dado de baja mayor
        "demand_min_price_coverage": 0.8,    # DEGRADE: fracción de unidades con precio neto positivo menor ⇒ se deshabilita price_ratio
        "demand_warn_min_cost_coverage": 0.5,    # WARN: fracción de líneas con costo menor
        "demand_warn_min_inventory_coverage": 0.5,  # WARN: fracción de series activas con inventario menor
        "demand_warn_max_stockout_pct": 0.2,     # WARN: fracción de series-día con quiebre mayor
        "demand_warn_max_closed_pct": 0.3,       # WARN: fracción de series-día cerradas mayor
        "demand_warn_max_stale_days": 14,        # WARN: días desde la última venta conocida mayor
        "demand_warn_max_lag_p90_days": 7,       # WARN: p90 del desfase evento→ingestión mayor
        "demand_warn_max_short_series_pct": 0.5,  # WARN: fracción de series activas con < 28 días de historia mayor
        # customer
        "customer_min_history_days": 28,     # BLOCK: historia de compras identificadas menor
        "customer_min_buyers": 5,            # BLOCK: compradores identificados con compras menor
        "customer_min_identified_share": 0.2,    # DEGRADE a agregado: fracción de unidades de compradores identificados menor
        "customer_max_id_change_share": 0.2,     # DEGRADE a agregado: fracción de productos o clientes con alias de cambio mayor
        "customer_max_new_product_code_share": 0.5,  # DEGRADE a agregado: fracción de productos con código nuevo reciente mayor
        "customer_warn_max_new_customer_share": 0.5,  # WARN: fracción de compradores nuevos en la ventana mayor
        "customer_warn_min_repeat_buyers_pct": 0.3,   # WARN: fracción de compradores con ≥ 2 días de compra menor
        "customer_warn_min_availability_pct": 0.5,    # WARN: fracción de productos comprados con inventario conocido menor
        "customer_warn_max_stale_days": 14,      # WARN: días desde la última venta conocida mayor
        "customer_warn_invalid_pct": 0.05,       # WARN: fracción de filas inválidas mayor
        # supply
        "supply_min_inventory_coverage": 0.0,    # BLOCK: fracción de series pronosticadas con inventario menor (0 = desactivado; ver WARN)
        "supply_warn_min_inventory_coverage": 0.5,   # WARN: fracción de series pronosticadas con inventario menor
        "supply_inventory_max_age_days": 30,     # WARN: mediana de antigüedad del último inventario mayor
        "supply_min_lead_time_coverage": 0.5,    # DEGRADE: fracción de series con plazo explícito (producto u oferta) menor
        "supply_warn_min_cost_coverage": 0.5,    # WARN: fracción de productos con costo conocido menor
    },
    # ── W3-B · promotion · política de selección heredada, política de promoción V2.5, shadow mode y Optuna (ADR-V25-21) ────────────
    # Se resuelve con la MISMA precedencia que el resto (core → perfil → `settings.cores.promotion`). TODOS los umbrales son valores
    # INICIALES de diseño, «a aprobar en el piloto» con datos reales; ninguno es universal. Su justificación está en docs/ml/FORECAST_SELECTION.md.
    # `governing_policy`: qué política decide lo SERVIDO. Por defecto `legacy` (no cambia la demo ni los E2E); la V2.5 se calcula siempre en
    # paralelo y se registra («sombra de decisión»). La mejora mínima de la política heredada es `demand.min_improvement` (3 %).
    "promotion": {
        "governing_policy": "legacy",         # legacy | v25
        # ── política heredada (legacy-selection-v1): fracción de ventanas que debe ganar LightGBM (2/3 ⇒ ⌈2n/3⌉; 3 de 4 en producción)
        "legacy_windows_won_fraction": 2 / 3,
        # ── política V2.5 (promotion-v25-1): gates calculados SOLO con las ventanas de selección (el holdout nunca decide) ──
        "v25_min_selection_windows": 2,       # ventanas de selección mínimas para certificar estabilidad y calibración
        "v25_max_consecutive_losing_windows": 1,   # el candidato no puede perder en más de x ventanas consecutivas frente a la referencia
        "v25_min_relative_improvement": 0.03,      # mejora relativa mínima del WAPE frente al champion vigente (o el baseline)
        "v25_operational_min_money_improvement": 0.05,   # alternativa: mejora del WAPE en dinero (unidades × precio) sin empeorar las unidades
        "v25_segment_max_regression": 0.10,        # regresión relativa máxima del WAPE en un segmento crítico frente a la referencia
        "v25_segment_min_actual": 30.0,            # unidades reales mínimas de un segmento para evaluarlo (menos ⇒ insufficient_data)
        "v25_critical_segments": ["demand_class", "volume_tier"],   # dimensiones de segmento protegidas (ver selection/promotion.py)
        "v25_coverage_tolerance": 0.10,            # |cobertura de intervalos − nominal| máxima (80 % y 95 %) para documentar la calibración
        "v25_approval_blocks_serving": False,      # True: sin aprobación humana (W4 · S06) el candidato queda en shadow y NO se sirve
        # ── shadow mode: el challenger (mejor candidato no servido de cada clase) predice sin servirse ──
        "shadow_enabled": True,
        "shadow_max_series": 100,                  # tope de series por alcance (las de mayor volumen reciente)
        "shadow_train_ml_challenger": True,        # si el challenger es LightGBM y no se sirve: entrenar uno SOLO con las series acotadas del shadow (aproximación; costo ∝ tope)
        # ── Optuna (opcional y APAGADO): ajusta LightGBM SOLO dentro de las ventanas de selección ──
        "optuna_enabled": False,
        "optuna_n_trials": 20,
        "optuna_timeout_seconds": 120,
        "optuna_seed": 42,
        "optuna_early_stopping_patience": 8,       # pruebas consecutivas sin mejorar el mejor valor ⇒ se detiene
        "optuna_max_cpu": 2,                       # tope de hilos de LightGBM durante el ajuste
    },
}

# Claves de tenants.settings que mapean a cada core (compatibilidad con la etapa 1).
TENANT_SETTING_KEYS: dict[str, tuple[str, ...]] = {
    "demand": ("service_level", "review_period_days", "default_lead_time_days"),
    "supply": ("service_level", "review_period_days", "default_lead_time_days"),
    "customer": (),
    "cost": (),
    "factors": (),
    "scenario": (),
    "promotion": (),
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
    ("demand", "horizons"): {"subset_of": list(HORIZONS["demand"]), "non_empty": True},
    ("demand", "day_status_infer_min_median_lines"): {"min": 1, "max": 100000},
    ("demand", "day_status_infer_lookback_days"): {"min": 7, "max": 90},
    # W3-A · métricas por segmento
    ("demand", "wape_min_denominator"): {"min": 0.0, "max": 1_000_000_000},
    ("demand", "segment_min_series"): {"min": 1, "max": 100000},
    ("demand", "segment_min_obs"): {"min": 1, "max": 100_000_000},
    ("demand", "segment_volume_top_share"): {"min": 0.01, "max": 0.99},
    ("demand", "segment_volume_lookback_days"): {"min": 7, "max": 365},
    ("demand", "segment_new_series_days"): {"min": 28, "max": 365},
    ("demand", "segment_max_values"): {"min": 1, "max": 1000},
    ("demand", "segment_similar_tolerance"): {"min": 0.0, "max": 1.0},
    ("demand", "segment_stability_cv_warn"): {"min": 0.0, "max": 100.0},
    ("demand", "segment_summary_max_cells"): {"min": 0, "max": 200},
    ("customer", "horizons"): {"subset_of": list(HORIZONS["customer"]), "non_empty": True},
    ("cost", "horizons"): {"subset_of": list(HORIZONS["cost"]), "non_empty": True},
}


# ── W2-C · quality_gates: rangos de los umbrales (fracciones en [0, 1]; días y conteos enteros no negativos) ──────────────────
for _key, _value in CORE_DEFAULTS["quality_gates"].items():
    if _key.endswith(("_pct", "_share", "_coverage")):
        CONFIG_CONSTRAINTS[("quality_gates", _key)] = {"min": 0.0, "max": 1.0}
    elif _key.endswith("_days"):
        CONFIG_CONSTRAINTS[("quality_gates", _key)] = {"min": 0, "max": 3650}
CONFIG_CONSTRAINTS[("quality_gates", "customer_min_buyers")] = {"min": 0, "max": 1_000_000}
del _key, _value


# ── W2-D · factors: rangos (los mapas anidados se validan en availability/pipeline.py, enganchado en `validate_overrides`) ──
CONFIG_CONSTRAINTS[("factors", "default_max_staleness_days")] = {"min": 1, "max": 3650}

# ── W3-B · promotion: rangos validados (la mejora mínima de la política heredada es `demand.min_improvement`) ──
CONFIG_CONSTRAINTS[("demand", "min_improvement")] = {"min": 0.0, "max": 0.5}
CONFIG_CONSTRAINTS.update({
    ("promotion", "governing_policy"): {"choices": ["legacy", "v25"]},
    ("promotion", "legacy_windows_won_fraction"): {"min": 0.5, "max": 1.0},
    ("promotion", "v25_min_selection_windows"): {"min": 1, "max": 20},
    ("promotion", "v25_max_consecutive_losing_windows"): {"min": 0, "max": 10},
    ("promotion", "v25_min_relative_improvement"): {"min": 0.0, "max": 0.5},
    ("promotion", "v25_operational_min_money_improvement"): {"min": 0.0, "max": 0.5},
    ("promotion", "v25_segment_max_regression"): {"min": 0.0, "max": 1.0},
    ("promotion", "v25_segment_min_actual"): {"min": 0.0, "max": 1e9},
    ("promotion", "v25_coverage_tolerance"): {"min": 0.0, "max": 0.5},
    ("promotion", "shadow_max_series"): {"min": 1, "max": 5000},
    ("promotion", "optuna_n_trials"): {"min": 1, "max": 200},
    ("promotion", "optuna_timeout_seconds"): {"min": 1, "max": 3600},
    ("promotion", "optuna_seed"): {"min": 0, "max": 2**31 - 1},
    ("promotion", "optuna_early_stopping_patience"): {"min": 1, "max": 100},
    ("promotion", "optuna_max_cpu"): {"min": 1, "max": 16},
})


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
    if core == "factors":        # W2-D: mapas de precedencia y de antigüedad (el tipo «objeto» no basta)
        from predictily_engine.availability.pipeline import factor_config_errors

        reported = {e["key"] for e in errors}
        errors.extend(factor_config_errors({k: v for k, v in (overrides or {}).items() if k in defaults and k not in reported}))
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
