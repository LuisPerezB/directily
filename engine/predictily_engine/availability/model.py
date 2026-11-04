"""Tipos del modelo de disponibilidad temporal único (ADR-V25-06): clases, estados, motivos y resultado.

Una clase de disponibilidad describe CÓMO se conoce un valor respecto del tiempo; un estado describe QUÉ ocurre con ese
valor en un corte concreto. Los motivos son códigos estables (se persisten y se prueban); el texto en español es solo
presentación.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from enum import Enum
from typing import Any, Mapping

import pandas as pd


class AvailabilityClass(str, Enum):
    """Clase de disponibilidad de un factor o de una feature (V2.5 §24.5)."""

    OBSERVED_HISTORICAL = "OBSERVED_HISTORICAL"          # hecho observado: visible desde su disponibilidad real
    STATIC = "STATIC"                                    # atributo estable (sucursal, categoría)
    KNOWN_IN_ADVANCE = "KNOWN_IN_ADVANCE"                # valores futuros permitidos si se conocían antes del corte
    THIRD_PARTY_FORECAST = "THIRD_PARTY_FORECAST"        # pronóstico de un tercero: vale el emitido (issued_at) ≤ corte
    USER_SCENARIO = "USER_SCENARIO"                      # supuesto del usuario: nunca entra en la medición de precisión
    UNAVAILABLE_FOR_HORIZON = "UNAVAILABLE_FOR_HORIZON"  # no se conoce para el horizonte pedido


class AvailabilityStatus(str, Enum):
    """Estado del valor visible en un corte (ADR-V25-06). Son EXACTAMENTE estos cinco."""

    AVAILABLE = "AVAILABLE"
    NOT_YET_AVAILABLE = "NOT_YET_AVAILABLE"
    STALE = "STALE"
    INVALID = "INVALID"
    SCENARIO_ONLY = "SCENARIO_ONLY"


# Estrategias de ausencia de `factor_definitions.missing_strategy`.
MISSING_STRATEGIES = ("disable_feature", "last_valid_with_age", "unavailable")

# Fuentes de datos del almacenamiento nativo (`factor_definitions.storage`).
STORAGES = ("sales", "product_price_history", "promotions", "calendar_events", "commercial_commitments",
            "inventory_snapshots", "external_indicator_observations", "external_events")


class Reason:
    """Códigos de motivo estables. El texto en español de cada uno está en `MESSAGES`."""

    OK = "ok"
    # AVAILABLE con matices
    # NOT_YET_AVAILABLE
    AFTER_CUTOFF = "after_cutoff"                       # su disponibilidad real es posterior al fin del día local del corte
    ISSUED_AFTER_CUTOFF = "issued_after_cutoff"         # pronóstico de tercero emitido después del corte
    KNOWN_AFTER_CUTOFF = "known_after_cutoff"           # valor previsto que solo se conoció después del corte
    PUBLISHED_AFTER_CUTOFF = "published_after_cutoff"   # evento publicado después del corte
    VERIFIED_AFTER_CUTOFF = "verified_after_cutoff"     # evento verificado después del corte
    NO_OBSERVATIONS = "no_observations"                 # la fuente no ha entregado ningún dato
    NO_VALUE_FOR_DATE = "no_value_for_date"             # ningún valor cubre la fecha pedida
    UNAVAILABLE_FOR_HORIZON = "unavailable_for_horizon"
    # STALE
    STALE = "stale"                                     # la antigüedad supera max_staleness_days
    VALIDITY_EXPIRED = "validity_expired"               # la vigencia (valid_to / effective_to) terminó antes de la fecha de uso
    # INVALID
    MISSING_PUBLISHED_AT = "missing_published_at"       # sin fecha de publicación: no es usable como feature
    MISSING_VALUE = "missing_value"
    MISSING_PERIOD = "missing_period"
    PERIOD_AFTER_AVAILABILITY = "period_after_availability"  # el período observado empieza después de que el dato estuvo disponible
    AVAILABLE_BEFORE_OBSERVED = "available_before_observed"  # disponible antes de medirse
    NO_ISSUANCE_HISTORY = "no_issuance_history"         # pronóstico de tercero sin historial archivado de emisiones
    UNKNOWN_AVAILABILITY = "unknown_availability"       # sin fecha de disponibilidad en una reconstrucción retroactiva
    EVENT_NOT_VERIFIED = "event_not_verified"
    EVENT_REJECTED = "event_rejected"
    VERIFICATION_DATE_UNKNOWN = "verification_date_unknown"
    # SCENARIO_ONLY
    USER_SCENARIO = "user_scenario"


MESSAGES: dict[str, str] = {
    Reason.OK: "Valor visible y vigente en el corte.",
    Reason.AFTER_CUTOFF: "Su disponibilidad real es posterior al fin del día local del corte.",
    Reason.ISSUED_AFTER_CUTOFF: "El pronóstico fue emitido después del corte (aunque se haya registrado antes).",
    Reason.KNOWN_AFTER_CUTOFF: "El valor previsto solo se conoció después del corte.",
    Reason.PUBLISHED_AFTER_CUTOFF: "El documento se publicó después del corte.",
    Reason.VERIFIED_AFTER_CUTOFF: "El evento se verificó después del corte.",
    Reason.NO_OBSERVATIONS: "La fuente no tiene observaciones.",
    Reason.NO_VALUE_FOR_DATE: "Ningún valor conocido cubre la fecha pedida.",
    Reason.UNAVAILABLE_FOR_HORIZON: "El factor no se conoce para el horizonte pedido.",
    Reason.STALE: "El último valor visible supera la antigüedad máxima permitida.",
    Reason.VALIDITY_EXPIRED: "La vigencia del valor terminó antes de la fecha de uso.",
    Reason.MISSING_PUBLISHED_AT: "Sin fecha de publicación: no se puede situar en el tiempo ni usar como feature.",
    Reason.MISSING_VALUE: "La observación no trae valor.",
    Reason.MISSING_PERIOD: "La observación no trae fecha de período.",
    Reason.PERIOD_AFTER_AVAILABILITY: "El período observado empieza después de que el dato estuvo disponible.",
    Reason.AVAILABLE_BEFORE_OBSERVED: "El dato figura como disponible antes de haberse observado.",
    Reason.NO_ISSUANCE_HISTORY: "La fuente no tiene historial archivado de emisiones: el backtest con este factor está prohibido.",
    Reason.UNKNOWN_AVAILABILITY: "Sin fecha de disponibilidad: en una reconstrucción retroactiva no puede demostrarse que se conocía.",
    Reason.EVENT_NOT_VERIFIED: "El evento no está verificado (PROPOSED): no se usa como feature.",
    Reason.EVENT_REJECTED: "El evento fue rechazado.",
    Reason.VERIFICATION_DATE_UNKNOWN: "Evento verificado sin fecha de verificación: no puede situarse en el tiempo.",
    Reason.USER_SCENARIO: "Supuesto del usuario: solo sirve para simulación, nunca para medir precisión.",
}


@dataclass(frozen=True)
class FactorSpec:
    """Lo que el validador necesita saber de un factor (metadatos de `factor_definitions`)."""

    key: str
    availability_class: AvailabilityClass
    max_staleness_days: int | None = None
    missing_strategy: str = "disable_feature"
    family: str | None = None
    storage: str | None = None
    frequency: str | None = None
    is_informative_alert: bool = False

    def __post_init__(self) -> None:
        if not isinstance(self.availability_class, AvailabilityClass):
            object.__setattr__(self, "availability_class", AvailabilityClass(self.availability_class))
        if self.missing_strategy not in MISSING_STRATEGIES:
            raise ValueError(f"missing_strategy inválida: {self.missing_strategy!r} (válidas: {MISSING_STRATEGIES})")
        if self.max_staleness_days is not None and self.max_staleness_days < 0:
            raise ValueError("max_staleness_days no puede ser negativa")

    @classmethod
    def from_definition(cls, row: Mapping[str, Any], default_max_staleness_days: int | None = None,
                        staleness_by_frequency: Mapping[str, int] | None = None) -> "FactorSpec":
        """Desde una fila de `factor_definitions`. La antigüedad máxima: la de la definición; si no, la de su frecuencia;
        si no, la general (el valor se propagaría para siempre si no hubiera ninguna: R-169)."""
        explicit = row.get("max_staleness_days")
        frequency = row.get("frequency")
        if explicit is None or (isinstance(explicit, float) and pd.isna(explicit)):
            limit = (staleness_by_frequency or {}).get(frequency) if frequency else None
            limit = default_max_staleness_days if limit is None else limit
        else:
            limit = int(explicit)
        return cls(
            key=str(row["key"]), availability_class=AvailabilityClass(row["availability_class"]),
            max_staleness_days=None if limit is None else int(limit),
            missing_strategy=str(row.get("missing_strategy") or "disable_feature"),
            family=row.get("family"), storage=row.get("storage"), frequency=frequency,
            is_informative_alert=bool(row.get("is_informative_alert") or False))


# Claves de `AvailabilityResult.detail` que cuentan filas NO visibles al corte: sirven para depurar («¿por qué no veo la revisión 1?»),
# nunca como feature ni como parte de un manifiesto (cambiarían al llegar datos nuevos sin que cambie el corte).
DIAGNOSTIC_DETAIL = frozenset({"hidden_after_cutoff"})


def _plain(value: Any) -> Any:
    """Escalar de numpy → escalar de Python (para serializar a JSON)."""
    return value.item() if hasattr(value, "item") else value


def _iso(value: Any) -> str | None:
    if value is None or value is pd.NaT:
        return None
    return pd.Timestamp(value).isoformat()


@dataclass(frozen=True)
class AvailabilityResult:
    """Valor visible de un factor en un corte, con su estado y su motivo.

    `value` solo viene informado si hay un valor visible (AVAILABLE, o STALE con el último valor válido) o, en un
    escenario, el supuesto. `age_days` es la antigüedad etiquetada del valor respecto de la fecha de uso. `fallback`
    es la estrategia de ausencia del factor cuando el estado no es AVAILABLE (qué hacer sin el valor)."""

    status: AvailabilityStatus
    reason: str
    availability_class: AvailabilityClass
    cutoff: date
    factor_key: str | None = None
    target_date: date | None = None
    value: Any = None
    record_id: Any = None
    revision: int | None = None
    period_date: date | None = None
    observed_at: pd.Timestamp | None = None
    published_at: pd.Timestamp | None = None
    available_at: pd.Timestamp | None = None
    issued_at: pd.Timestamp | None = None
    age_days: int | None = None
    max_staleness_days: int | None = None
    fallback: str | None = None
    flags: tuple[str, ...] = ()
    detail: Mapping[str, Any] = field(default_factory=dict)

    @property
    def message(self) -> str:
        return MESSAGES.get(self.reason, self.reason)

    @property
    def usable_as_feature(self) -> bool:
        """¿Puede el valor entrar a un conjunto de features? AVAILABLE sí (salvo una alerta informativa, que no es
        predictiva); STALE solo si la estrategia de ausencia del factor es `last_valid_with_age` (se usa etiquetado con su
        antigüedad); el resto, nunca."""
        if "informative_alert" in self.flags:
            return False
        if self.status is AvailabilityStatus.AVAILABLE:
            return True
        return (self.status is AvailabilityStatus.STALE and self.fallback == "last_valid_with_age"
                and self.value is not None)

    @property
    def eligible_for_accuracy(self) -> bool:
        """Los escenarios del usuario NUNCA entran en la medición de precisión (ADR-V25-06)."""
        return (self.status is not AvailabilityStatus.SCENARIO_ONLY
                and self.availability_class is not AvailabilityClass.USER_SCENARIO)

    def as_dict(self) -> dict[str, Any]:
        """Forma serializable para manifiestos, límites y pruebas. Omite los diagnósticos que dependen de filas POSTERIORES al corte
        (`DIAGNOSTIC_DETAIL`): un manifiesto de una reconstrucción retroactiva no debe cambiar según lo que llegó después."""
        return {
            "factor_key": self.factor_key, "status": self.status.value, "reason": self.reason, "message": self.message,
            "availability_class": self.availability_class.value, "cutoff": self.cutoff.isoformat(),
            "target_date": None if self.target_date is None else self.target_date.isoformat(),
            "value": self.value, "record_id": _plain(self.record_id), "revision": self.revision,
            "period_date": None if self.period_date is None else self.period_date.isoformat(),
            "observed_at": _iso(self.observed_at), "published_at": _iso(self.published_at),
            "available_at": _iso(self.available_at), "issued_at": _iso(self.issued_at),
            "age_days": self.age_days, "max_staleness_days": self.max_staleness_days, "fallback": self.fallback,
            "flags": list(self.flags), "usable_as_feature": self.usable_as_feature,
            "detail": {k: v for k, v in self.detail.items() if k not in DIAGNOSTIC_DETAIL}}


class ScenarioInAccuracyError(ValueError):
    """Se intentó medir precisión con un escenario del usuario."""


class ForecastBacktestForbidden(ValueError):
    """Backtest con un pronóstico de tercero sin historial archivado de emisiones (ADR-V25-06; V2.5 §24.5)."""


def filter_for_accuracy(results: list[AvailabilityResult]) -> list[AvailabilityResult]:
    """Resultados que pueden entrar a una medición de precisión: los escenarios (SCENARIO_ONLY) quedan fuera.

    Es una GUARDA para el código que mezcle resultados del validador con una medición; hoy ninguna métrica de `evaluation/` consume
    resultados de factores, así que NO está enganchada a ellas. La exclusión que sí está garantizada de forma estructural es otra: un
    escenario (`USER_SCENARIO`) nunca llega a las columnas del modelo (`FactorPipeline` lo deja en `scenario_only`), de modo que ni
    el entrenamiento, ni el backtest, ni la precisión medida sobre ellos pueden usarlo."""
    return [r for r in results if r.eligible_for_accuracy]


def assert_accuracy_eligible(result: AvailabilityResult) -> None:
    """Guarda para el código que mide precisión: falla si el insumo es un escenario del usuario."""
    if not result.eligible_for_accuracy:
        raise ScenarioInAccuracyError(
            f"El factor {result.factor_key!r} es un escenario del usuario (SCENARIO_ONLY): no puede usarse para medir precisión.")
