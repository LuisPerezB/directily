"""Reglas declarativas de los gates (código estable → métrica → umbral → severidad).

Cada regla se CUMPLE cuando `valor <op> umbral`; si no se cumple, el gate registra un motivo con la severidad de la regla. El
resultado de un módulo es la peor severidad de sus motivos (PASS si no hay ninguno). Una métrica no disponible (p. ej. no se pasó la
conexión a la base) NO se da por buena en silencio: la regla se registra en `not_evaluated` con su motivo.

Los umbrales son las claves de `platform.config.CORE_DEFAULTS["quality_gates"]` (precedencia core → perfil → empresa). Cambiar esta
tabla cambia `rules_version`.
"""

from __future__ import annotations

from dataclasses import dataclass

# Versión del código de reglas y de las métricas: forman parte de `rules_version` junto con los umbrales efectivos.
RULES_CODE_VERSION = "dq-gates-2026.11-v2"
METRICS_SCHEMA = "dq-metrics-v1"

# Motivos WARN que el propio gate genera (no están en RULES): una señal necesaria para una regla BLOCK o DEGRADE falló, o las métricas
# no se pudieron calcular. Nunca se da por «aprobado» lo que no se pudo evaluar.
SIGNAL_UNAVAILABLE_CODE = "dq_signal_unavailable"
METRICS_UNAVAILABLE_CODE = "dq_metrics_unavailable"
GENERATED_CODES = (SIGNAL_UNAVAILABLE_CODE, METRICS_UNAVAILABLE_CODE)
# Razones por las que una regla queda sin evaluar: `no_data` (la métrica no tiene valor: sin cargas en la ventana, sin historia suficiente,
# no aplica), `not_collected` (el llamador no recogió las señales de la base: ejecución sin base de datos), `signal_failed` (la lectura de
# la señal falló) y `metrics_error` (las métricas no se pudieron calcular). Solo las dos últimas generan un motivo WARN.
NOT_EVALUATED_REASONS = ("no_data", "not_collected", "signal_failed", "metrics_error")

MODULES = ("demand", "customer", "supply")
PASS, WARN, DEGRADE, BLOCK = "PASS", "WARN", "DEGRADE", "BLOCK"
OUTCOMES = (PASS, WARN, DEGRADE, BLOCK)
OUTCOME_RANK = {o: i for i, o in enumerate(OUTCOMES)}

# Datos requeridos (vocabulario común con el registro de contratos de features de W2-D: `required_data`). La suficiencia de un dato la
# declara una regla DEGRADE con `data_kind`; los datos sin regla (None) no tienen aún criterio de suficiencia y nunca se declaran
# insuficientes por este gate.
DATA_KINDS: dict[str, str] = {
    "sales_history": "Historia de ventas válidas as-of (lags, medias móviles, nivel de la serie).",
    "calendar": "Calendario de feriados, cierres y campañas conocido de antemano.",
    "branches": "Sucursales de la empresa.",
    "branch_region": "Región (costa/sierra) y cantón de cada sucursal.",
    "product_category": "Categoría del producto.",
    "promotions": "Promociones con fecha de disponibilidad (known_at).",
    "price": "Precio neto observado en las líneas de venta.",
    "confirmed_future_price": "Precios futuros confirmados con known_at (product_price_history).",
    "costs": "Costo unitario histórico en las líneas de venta o en el catálogo.",
    "inventory": "Existencias por sucursal y producto (snapshots).",
    "lead_times": "Plazos de entrega explícitos por producto, proveedor u oferta.",
    "identified_buyers": "Compras atribuidas a compradores identificados y no genéricos.",
    "stable_ids": "Identidades de producto y cliente estables (sin cambios de código recientes).",
    "external_indicators": "Indicadores externos con disponibilidad temporal (pipeline de factores).",
}
# Los primeros ocho términos coinciden con el vocabulario cerrado del registro de contratos de features de W2-D
# (`availability.contracts.DATASETS`); el resto (costs, inventory, lead_times, identified_buyers, stable_ids, calendar) es de los gates.


@dataclass(frozen=True)
class Rule:
    code: str
    module: str
    severity: str          # WARN | DEGRADE | BLOCK
    metric: str            # ruta con puntos dentro de las métricas del módulo
    op: str                # ">=" | "<=": la regla se cumple cuando valor op umbral
    threshold: str         # clave de quality_gates
    message: str           # plantilla con {value} y {threshold}
    data_kind: str | None = None   # solo DEGRADE: dato requerido que esta regla declara insuficiente


RULES: tuple[Rule, ...] = (
    # ───────────── demanda ─────────────
    Rule("dq_demand_insufficient_history", "demand", BLOCK, "coverage.calendar_days", ">=", "demand_min_history_days",
         "La historia de la empresa es de {value} días y el mínimo configurado es {threshold}: no hay datos suficientes para pronosticar."),
    Rule("dq_demand_insufficient_coverage", "demand", BLOCK, "coverage.source_coverage_pct", ">=", "demand_min_source_coverage_pct",
         "Solo el {value} de los sucursal-días tiene cobertura de fuente (mínimo {threshold}): la señal de entrenamiento sería casi toda imputación."),
    Rule("dq_demand_invalid_rate_excessive", "demand", BLOCK, "invalid.invalid_pct", "<=", "demand_block_invalid_pct",
         "El {value} de las filas de las cargas recientes se rechazó por inválido (máximo {threshold}): los datos no son representativos."),
    Rule("dq_demand_price_coverage_low", "demand", DEGRADE, "price.coverage_share", ">=", "demand_min_price_coverage",
         "Solo el {value} de las unidades vendidas tiene precio neto (mínimo {threshold}): se deshabilita la variable de precio.", "price"),
    Rule("dq_demand_source_coverage_below_target", "demand", WARN, "coverage.source_coverage_pct", ">=", "demand_warn_source_coverage_pct",
         "El {value} de los sucursal-días tiene cobertura de fuente (objetivo {threshold}): los huecos se imputan y no son ceros."),
    Rule("dq_demand_invalid_rate_high", "demand", WARN, "invalid.invalid_pct", "<=", "demand_warn_invalid_pct",
         "El {value} de las filas de las cargas recientes se rechazó por inválido (máximo esperado {threshold}): revise la cuarentena."),
    Rule("dq_demand_key_integrity_issues", "demand", WARN, "key_integrity.key_issue_pct", "<=", "demand_warn_key_issue_pct",
         "El {value} de las líneas de venta referencia productos o sucursales inexistentes o dados de baja (máximo esperado {threshold})."),
    Rule("dq_demand_cost_coverage_low", "demand", WARN, "cost.coverage_share", ">=", "demand_warn_min_cost_coverage",
         "Solo el {value} de las líneas de venta trae costo (mínimo esperado {threshold}): el margen y la inversión estimada quedan incompletos."),
    Rule("dq_demand_inventory_coverage_low", "demand", WARN, "inventory.coverage_share", ">=", "demand_warn_min_inventory_coverage",
         "Solo el {value} de las series activas tiene inventario (mínimo esperado {threshold}): sin él no se detectan quiebres ni hay recomendaciones."),
    Rule("dq_demand_stockouts_high", "demand", WARN, "closures_stockouts.stockout_pct", "<=", "demand_warn_max_stockout_pct",
         "El {value} de las series-día tuvo quiebre de stock (máximo esperado {threshold}): las ventas subestiman la demanda."),
    Rule("dq_demand_closures_high", "demand", WARN, "closures_stockouts.closed_pct", "<=", "demand_warn_max_closed_pct",
         "El {value} de las series-día corresponde a cierres declarados (máximo esperado {threshold})."),
    Rule("dq_demand_data_stale", "demand", WARN, "freshness.days_since_last_sale", "<=", "demand_warn_max_stale_days",
         "La última venta conocida es de hace {value} días (máximo esperado {threshold}): verifique que la carga de datos esté al día."),
    Rule("dq_demand_ingestion_lag_high", "demand", WARN, "freshness.ingestion_lag.p90_days", "<=", "demand_warn_max_lag_p90_days",
         "El p90 del desfase entre la venta y su ingestión es de {value} días (máximo esperado {threshold}): los últimos días pueden corregirse después."),
    Rule("dq_demand_short_series_majority", "demand", WARN, "series_history.short_series_pct", "<=", "demand_warn_max_short_series_pct",
         "El {value} de las series activas tiene menos de 28 días de historia (máximo esperado {threshold}): usan un respaldo sin evaluación."),
    # ───────────── Customer ─────────────
    Rule("dq_customer_insufficient_history", "customer", BLOCK, "coverage.identified_history_days", ">=", "customer_min_history_days",
         "La historia de compras identificadas es de {value} días y el mínimo configurado es {threshold}."),
    Rule("dq_customer_too_few_buyers", "customer", BLOCK, "customer_history.buyers", ">=", "customer_min_buyers",
         "Hay {value} compradores identificados con compras y el mínimo configurado es {threshold}."),
    Rule("dq_customer_identified_share_low", "customer", DEGRADE, "identity.modeled_share_units", ">=", "customer_min_identified_share",
         "Solo el {value} de las unidades se atribuye a compradores identificados (mínimo {threshold}): "
         "se conserva el pronóstico agregado y se deshabilitan las recomendaciones individuales.", "identified_buyers"),
    Rule("dq_customer_product_ids_unstable", "customer", DEGRADE, "id_stability.product_change_share", "<=", "customer_max_id_change_share",
         "El {value} de los productos con ventas tuvo un cambio de código, fusión o división reciente (máximo {threshold}): "
         "las identidades no son estables y se deshabilitan las recomendaciones individuales.", "stable_ids"),
    Rule("dq_customer_customer_ids_unstable", "customer", DEGRADE, "id_stability.customer_change_share", "<=", "customer_max_id_change_share",
         "El {value} de los compradores tuvo un cambio de código, fusión o división reciente (máximo {threshold}): "
         "las identidades no son estables y se deshabilitan las recomendaciones individuales.", "stable_ids"),
    Rule("dq_customer_new_product_codes_high", "customer", DEGRADE, "id_stability.new_product_code_share", "<=", "customer_max_new_product_code_share",
         "El {value} de los productos con ventas tiene un código nuevo reciente sin alias (máximo {threshold}): "
         "posible reasignación de códigos; se deshabilitan las recomendaciones individuales.", "stable_ids"),
    Rule("dq_customer_new_customers_high", "customer", WARN, "id_stability.new_customer_share", "<=", "customer_warn_max_new_customer_share",
         "El {value} de los compradores identificados es nuevo en la ventana reciente (máximo esperado {threshold})."),
    Rule("dq_customer_repeat_buyers_low", "customer", WARN, "customer_history.repeat_buyers_pct", ">=", "customer_warn_min_repeat_buyers_pct",
         "Solo el {value} de los compradores tiene dos o más días de compra (mínimo esperado {threshold}): poca recompra que aprender."),
    Rule("dq_customer_availability_unknown", "customer", WARN, "availability.products_with_inventory_pct", ">=", "customer_warn_min_availability_pct",
         "Solo el {value} de los productos comprados tiene inventario conocido (mínimo esperado {threshold}): la censura por disponibilidad es parcial."),
    Rule("dq_customer_data_stale", "customer", WARN, "freshness.days_since_last_sale", "<=", "customer_warn_max_stale_days",
         "La última venta conocida es de hace {value} días (máximo esperado {threshold}): la recencia puede estar sobreestimada."),
    Rule("dq_customer_invalid_rate_high", "customer", WARN, "invalid.invalid_pct", "<=", "customer_warn_invalid_pct",
         "El {value} de las filas de las cargas recientes se rechazó por inválido (máximo esperado {threshold})."),
    # ───────────── abastecimiento ─────────────
    Rule("dq_supply_no_inventory", "supply", BLOCK, "inventory.coverage_share", ">=", "supply_min_inventory_coverage",
         "Solo el {value} de las series pronosticadas tiene inventario al corte (mínimo {threshold}): las existencias son desconocidas y no se asumen cero."),
    Rule("dq_supply_inventory_coverage_low", "supply", WARN, "inventory.coverage_share", ">=", "supply_warn_min_inventory_coverage",
         "Solo el {value} de las series pronosticadas tiene inventario al corte (mínimo esperado {threshold}): el resto se excluye del plan con el motivo `no_inventory`."),
    Rule("dq_supply_inventory_stale", "supply", WARN, "inventory.age_days_median", "<=", "supply_inventory_max_age_days",
         "La mediana de antigüedad del último inventario es de {value} días (máximo esperado {threshold}): las existencias de partida pueden no ser vigentes."),
    Rule("dq_supply_lead_times_missing", "supply", DEGRADE, "lead_times.coverage_share", ">=", "supply_min_lead_time_coverage",
         "Solo el {value} de las series tiene un plazo de entrega explícito (mínimo {threshold}): se deshabilitan las ofertas implícitas "
         "y la política de plazo observado, que dependen de plazos supuestos.", "lead_times"),
    Rule("dq_supply_cost_coverage_low", "supply", WARN, "costs.coverage_share", ">=", "supply_warn_min_cost_coverage",
         "Solo el {value} de los productos pronosticados tiene costo conocido (mínimo esperado {threshold}): el costo del plan queda incompleto."),
)

RULES_BY_CODE: dict[str, Rule] = {r.code: r for r in RULES}


def rules_for(module: str) -> tuple[Rule, ...]:
    return tuple(r for r in RULES if r.module == module)


def threshold_keys() -> set[str]:
    return {r.threshold for r in RULES}
