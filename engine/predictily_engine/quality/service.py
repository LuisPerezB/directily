"""Puntos de entrada de los gates para los módulos que cargan sus propios datos (Customer y abastecimiento).

Cada uno resuelve la política de la empresa (`quality_gates`: core → perfil → empresa), recoge las señales de la base con el mismo
corte y conocimiento que sus datos y devuelve el `QualityAssessment`. El módulo solo llama a estas funciones al inicio de su ejecución y
actúa sobre el resultado (BLOCK, DEGRADE, WARN). Demanda lo hace en `pipeline.core.run_pipeline` (que no toca la base) con las señales
que le pasa `pipeline.forecast_run`.
"""

from __future__ import annotations

from typing import Any

from sqlalchemy import Connection

from predictily_engine.data.asof import local_today
from predictily_engine.pipeline.errors import InvalidConfigurationError
from predictily_engine.quality.assessment import QualityAssessment, QualityPolicy, assess_customer, assess_supply
from predictily_engine.quality.signals import collect_signals


def resolve_policy(tenant: dict[str, Any]) -> QualityPolicy:
    """Umbrales efectivos de la empresa (core → perfil → empresa). Una configuración `quality_gates` inválida (clave desconocida, tipo o
    rango) se rechaza con `InvalidConfigurationError`, el MISMO error 422 que la configuración de demanda: nunca se aplica en silencio."""
    try:
        return QualityPolicy.resolve(tenant.get("settings"), tenant.get("sector"))
    except ValueError as exc:
        raise InvalidConfigurationError(f"Configuración de quality_gates inválida: {exc}") from exc


def customer_gate(conn: Connection, tenant: dict[str, Any], cfg: dict[str, Any], data: Any) -> QualityAssessment:
    """Gate del Customer Core sobre los datos ya cargados (`CustomerData`); `cfg`: configuración efectiva de `customer`."""
    policy = resolve_policy(tenant)
    signals = None
    if data.through is not None:
        knowledge = local_today(tenant.get("timezone")) if getattr(data, "live", False) else None
        signals = collect_signals(conn, int(tenant["id"]), data.through, tenant.get("timezone"), policy.config,
                                  generic_codes=cfg.get("generic_buyer_codes"), knowledge=knowledge)
    return assess_customer(data, policy, signals)


def supply_gate(tenant: dict[str, Any], inputs: Any) -> QualityAssessment:
    """Gate del plan de abastecimiento sobre sus insumos (`PlanInputs`); no necesita la base."""
    return assess_supply(inputs, resolve_policy(tenant))
