"""Modelo de disponibilidad temporal único (ADR-V25-06) y contratos de features (ADR-V25-25).

- `FeatureAvailabilityValidator`: valor visible de un factor en un corte y su estado (AVAILABLE, NOT_YET_AVAILABLE, STALE, INVALID,
  SCENARIO_ONLY) con motivo.
- `contracts`: registro declarativo de cada feature del builder con su clase de disponibilidad, fuente y datos requeridos.
- `FactorPipeline`: resolvedor de factores externos, APAGADO por defecto (bloque `factors` de `platform/config.py`).

Documentación: docs/data/FEATURE_CONTRACTS.md y docs/ml/POINT_IN_TIME_VALIDATION.md.
"""

from predictily_engine.availability.contracts import (
    DATASETS,
    FACTOR_COLUMN_PREFIX,
    FEATURE_CONTRACTS,
    FEATURE_CONTRACTS_VERSION,
    DegradePlan,
    FeatureContract,
    FeatureContractError,
    contract_for,
    contracts_digest,
    degrade_plan,
    disabled_columns,
    features_requiring,
    required_data_map,
    validate_feature_columns,
)
from predictily_engine.availability.model import (
    AvailabilityClass,
    AvailabilityResult,
    AvailabilityStatus,
    FactorSpec,
    ForecastBacktestForbidden,
    Reason,
    ScenarioInAccuracyError,
    assert_accuracy_eligible,
    filter_for_accuracy,
)
from predictily_engine.availability.pipeline import FactorPipeline, FactorResolution
from predictily_engine.availability.validator import FeatureAvailabilityValidator

__all__ = [
    "AvailabilityClass", "AvailabilityResult", "AvailabilityStatus", "DATASETS", "DegradePlan", "FACTOR_COLUMN_PREFIX",
    "FEATURE_CONTRACTS", "FEATURE_CONTRACTS_VERSION", "FactorPipeline", "FactorResolution", "FactorSpec", "FeatureAvailabilityValidator",
    "FeatureContract", "FeatureContractError", "ForecastBacktestForbidden", "Reason", "ScenarioInAccuracyError",
    "assert_accuracy_eligible", "contract_for", "contracts_digest", "degrade_plan", "disabled_columns", "features_requiring",
    "filter_for_accuracy", "required_data_map", "validate_feature_columns",
]
