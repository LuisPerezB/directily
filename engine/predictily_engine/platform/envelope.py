"""Envoltura común de resultados (ADR-V2-03, docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md).

Todo resultado de un core de la etapa 2 se devuelve dentro de esta envoltura. `status` es el estado
técnico del trabajo; `quality_status` es la aptitud estadística del resultado. Un valor desconocido
conserva ese estado (None), nunca se reemplaza por cero.
"""

from __future__ import annotations

from datetime import date
from typing import Any, Literal

from pydantic import BaseModel, Field

ResultType = Literal["forecast", "conditional_simulation", "causal_estimate", "optimized_plan", "evaluation"]
QualityStatus = Literal["ok", "degraded", "insufficient_data", "not_configured", "insufficient_evidence"]
UncertaintySource = Literal["empirical_holdout", "conformal", "model", "unknown", "none"]
Core = Literal["demand", "customer", "supply", "cost", "scenario"]


class ResultEnvelope(BaseModel):
    tenant_id: int
    run_id: int | None = None
    core: Core
    as_of: date | None = None
    horizon_start: date | None = None
    horizon_end: date | None = None
    target_definition: dict[str, Any] | None = None
    entity_keys: list[str] | None = Field(default=None, description="Claves que identifican cada fila de datos, p. ej. ['customer_id','product_id']")
    unit: str | None = None
    currency: str | None = None
    result_type: ResultType
    model_version: dict[str, Any] | str | None = None
    data_snapshot_id: int | None = None
    config_version: str | None = None
    assumptions: list[str] = Field(default_factory=list)
    limitations: list[str] = Field(default_factory=list)
    quality_status: QualityStatus
    uncertainty_source: UncertaintySource = "unknown"
    data: Any = None


def envelope(**kwargs: Any) -> dict[str, Any]:
    """Construye y valida una envoltura; devuelve dict serializable (fechas ISO)."""
    return ResultEnvelope(**kwargs).model_dump(mode="json")
