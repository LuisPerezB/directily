"""Registro declarativo de CONTRATOS DE FEATURES (ADR-V25-06 · V2.5 §24.5 · docs/data/FEATURE_CONTRACTS.md).

CADA feature que produce `features/builder.py` (y las que MLForecast deriva de ellas) declara aquí su clase de disponibilidad,
su fuente, la regla temporal, qué ocurre con los días posteriores al corte y los DATOS REQUERIDOS. Una feature sin contrato
hace fallar la construcción (`validate_feature_columns` se ejecuta en `FeatureBuilder.training_frame` y `future_frame`).

Los datos requeridos (`required_data`) usan el vocabulario cerrado `DATASETS`. Es el mapa feature → datos que consume el gate de
calidad (W2-C) para `DEGRADE`: si un dataset requerido no está disponible o su calidad es insuficiente, `degrade_plan` indica
qué features opcionales se deshabilitan (`FeatureBuilder(disabled_features=...)`) y cuáles, siendo imprescindibles, obligan a
detener la ejecución.

El contenido del registro se fija con una huella (`contracts_digest`) y una versión (`FEATURE_CONTRACTS_VERSION`): cambiar un
contrato sin subir la versión hace fallar `test_feature_contracts.py`.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from typing import Any, Iterable, Mapping

from predictily_engine.availability.model import AvailabilityClass
from predictily_engine.calendar.ecuador import EcuadorCalendar

FEATURE_CONTRACTS_VERSION = "fc-2026.11-v1"
FACTOR_COLUMN_PREFIX = "factor__"
KEY_COLUMNS = frozenset({"unique_id", "ds", "y"})          # identificadores y objetivo: no son features
PRICE_ASSUMPTION = "último precio neto promedio observado (28 días) hasta el corte"

# Vocabulario cerrado de datos requeridos. Cada elemento es un dataset que el gate de calidad (W2-C) sabe medir.
DATA_SALES = "sales_history"
DATA_PRICE = "price"
DATA_PROMOTIONS = "promotions"
DATA_BRANCHES = "branches"
DATA_BRANCH_REGION = "branch_region"
DATA_CATEGORY = "product_category"
DATA_EXTERNAL = "external_indicators"
DATA_FUTURE_PRICE = "confirmed_future_price"
DATASETS: dict[str, str] = {
    DATA_SALES: "Historia de ventas diaria por serie, tal como se conocía al corte (sales_line_versions; días con estado de cobertura).",
    DATA_PRICE: "Precio neto unitario observado en las ventas de la serie (sale_items.net_amount / quantity).",
    DATA_PROMOTIONS: "Promociones con known_at y revisiones (promotions, promotion_revisions).",
    DATA_BRANCHES: "Sucursales de la empresa (branches).",
    DATA_BRANCH_REGION: "Región (costa/sierra) y cantón de cada sucursal.",
    DATA_CATEGORY: "Categoría del producto (products.category_id).",
    DATA_EXTERNAL: "Observaciones de indicadores externos con fechas de disponibilidad (external_indicator_observations).",
    DATA_FUTURE_PRICE: "Precios futuros confirmados con known_at (product_price_history).",
}

ON_MISSING = ("disable", "neutral", "block")

_AC = AvailabilityClass

_SALES_SOURCE = "sales: vista sales_line_versions (versión vigente al corte); demanda imputada del panel"
_SALES_KNOWN_AT = ("sale_date ≤ corte y known_from < medianoche local de (C+1) (ADR-V25-23); los días sin fuente o cerrados no son "
                   "ceros observados (ADR-V25-03)")
_CALENDAR_KNOWN_AT = ("statutory_rule: la fecha es función de la ley o de la convención comercial, no de una publicación posterior; "
                      "los traslados extraordinarios por decreto no se modelan (entrarían como calendar_events con known_at)")


class FeatureContractError(ValueError):
    """Una feature no tiene contrato, un contrato es inválido o se pidió deshabilitar una feature que no puede omitirse."""


@dataclass(frozen=True)
class FeatureContract:
    """Contrato de disponibilidad temporal de una feature."""

    name: str
    kind: str                                  # calendar | lag | rolling | commercial | static | geographic | external | training_weight
    availability_class: AvailabilityClass      # clase para las fechas ≤ corte
    source: str                                # de dónde sale el dato
    known_at: str                              # cómo se determina cuándo se conocía
    future_mode: str                           # qué pasa en días > corte
    description: str
    required_data: tuple[str, ...] = ()        # datasets imprescindibles (vocabulario DATASETS)
    optional_data: tuple[str, ...] = ()        # datasets que la mejoran pero no son imprescindibles
    produced_as: tuple[str, ...] = ()          # nombres de columna que genera MLForecast a partir de ella
    on_missing: str = "block"                  # sin sus datos requeridos: disable (se omite la columna) | neutral (valor neutro documentado) | block
    enabled_by_default: bool = True
    assumption: str | None = None
    future_availability_class: AvailabilityClass | None = None   # clase del futuro si cambia con una condición
    future_condition: str | None = None
    config: Mapping[str, Any] = field(default_factory=dict)      # parámetros legados de forecast_features.config

    def __post_init__(self) -> None:
        for token in (*self.required_data, *self.optional_data):
            if token not in DATASETS:
                raise FeatureContractError(f"{self.name}: dataset requerido desconocido {token!r}")
        if self.on_missing not in ON_MISSING:
            raise FeatureContractError(f"{self.name}: on_missing inválido {self.on_missing!r} (válidos: {ON_MISSING})")

    @property
    def optional(self) -> bool:
        """¿Puede omitirse la columna (DEGRADE) sin detener la ejecución?"""
        return self.on_missing == "disable"

    @property
    def columns(self) -> tuple[str, ...]:
        """Nombres con los que la feature aparece como columna (el propio nombre y los que genera MLForecast)."""
        return (self.name, *self.produced_as)

    def as_dict(self) -> dict[str, Any]:
        return {
            "name": self.name, "kind": self.kind, "availability_class": self.availability_class.value, "source": self.source,
            "known_at": self.known_at, "future_mode": self.future_mode, "description": self.description,
            "required_data": list(self.required_data), "optional_data": list(self.optional_data),
            "produced_as": list(self.produced_as), "on_missing": self.on_missing, "enabled_by_default": self.enabled_by_default,
            "assumption": self.assumption,
            "future_availability_class": None if self.future_availability_class is None else self.future_availability_class.value,
            "future_condition": self.future_condition, "config": dict(self.config)}


def _build() -> tuple[FeatureContract, ...]:
    rows: list[FeatureContract] = []
    for f in EcuadorCalendar.FEATURES:
        rows.append(FeatureContract(
            f, "calendar", _AC.KNOWN_IN_ADVANCE, "calendar/ecuador.py (feriados con `holidays` + convenciones comerciales)",
            _CALENDAR_KNOWN_AT, "deterministic_from_date", f"Indicador de calendario Ecuador: {f}", optional_data=(DATA_BRANCH_REGION,)))
    for f, text in (("dayofweek", "Día de la semana"), ("month", "Mes del año"), ("day", "Día del mes (quincenas/fin de mes)")):
        rows.append(FeatureContract(f, "calendar", _AC.KNOWN_IN_ADVANCE, "fecha del día (ds), calculada por MLForecast",
                                    "deterministic_from_date: función exacta de la fecha", "deterministic_from_date", text))
    for lag in (1, 7, 14, 28):
        rows.append(FeatureContract(
            f"lag{lag}", "lag", _AC.OBSERVED_HISTORICAL, _SALES_SOURCE, _SALES_KNOWN_AT, "recursive_forecast",
            f"Ventas (demanda imputada) hace {lag} días", required_data=(DATA_SALES,), config={"lag": lag}))
    rolling = (
        ("rolling_mean_lag1_w7", "Media móvil 7 días", {"window": 7}, "rolling_mean_lag1_window_size7"),
        ("rolling_mean_lag1_w28", "Media móvil 28 días", {"window": 28}, "rolling_mean_lag1_window_size28"),
        ("rolling_std_lag1_w28", "Desviación móvil 28 días", {"window": 28}, "rolling_std_lag1_window_size28"),
        ("seasonal_rolling_mean_lag7_s7_w4", "Media del mismo día de semana (4 semanas)", {"season": 7, "window": 4},
         "seasonal_rolling_mean_lag7_season_length7_window_size4"),
    )
    for name, text, config, produced in rolling:
        rows.append(FeatureContract(name, "rolling", _AC.OBSERVED_HISTORICAL, _SALES_SOURCE, _SALES_KNOWN_AT, "recursive_forecast",
                                    text, required_data=(DATA_SALES,), produced_as=(produced,), config=config))
    rows.append(FeatureContract(
        "promo_discount", "commercial", _AC.KNOWN_IN_ADVANCE, "promotions + promotion_revisions",
        "promotions.known_at (si falta, created_at) ≤ día del corte y revisión vigente ese día (recorded_at ≤ corte); sin ninguna de las "
        "dos fechas la promoción no se usa a futuro: su inicio no demuestra que estuviera planificada",
        "known_at_cutoff", "Descuento de promociones; a futuro solo las conocidas al corte (known_at y versión vigente)",
        required_data=(DATA_PROMOTIONS,), on_missing="disable", config={"availability": "as_of_cutoff"},
        assumption="Para fechas ≤ corte la promoción cuenta tal como ocurrió (hecho observado)."))
    rows.append(FeatureContract(
        "price_ratio", "commercial", _AC.OBSERVED_HISTORICAL, "sales (precio neto unitario observado) y, opcional, product_price_history",
        "precio observado: sale_date ≤ corte y known_from < medianoche local de (C+1); precio futuro confirmado: "
        "product_price_history.known_at < medianoche local de (C+1)", "frozen_price",
        "Precio neto / precio mediano de la serie; a futuro se congela en el último precio",
        required_data=(DATA_PRICE,), optional_data=(DATA_FUTURE_PRICE,), on_missing="disable", config={"assumption": PRICE_ASSUMPTION},
        assumption=PRICE_ASSUMPTION, future_availability_class=_AC.KNOWN_IN_ADVANCE,
        future_condition="precio futuro confirmado en product_price_history (valid_from ≤ día, known_at < medianoche local de C+1); "
                         "sin él rige el supuesto frozen_price"))
    rows.append(FeatureContract("branch_code_num", "static", _AC.STATIC, "branches.id", "atributo estable de la sucursal", "constant",
                                "Sucursal", required_data=(DATA_BRANCHES,)))
    rows.append(FeatureContract("category_num", "static", _AC.STATIC, "products.category_id", "atributo del producto (vigente hoy)",
                                "constant", "Categoría del producto", required_data=(DATA_CATEGORY,),
                                on_missing="neutral"))
    rows.append(FeatureContract("region_num", "geographic", _AC.STATIC, "branches.region", "atributo estable de la sucursal", "constant",
                                "Región (Costa/Sierra) de la sucursal", required_data=(DATA_BRANCH_REGION,),
                                on_missing="neutral"))
    rows.append(FeatureContract("series_level", "static", _AC.OBSERVED_HISTORICAL, _SALES_SOURCE,
                                "media del log de la demanda de los datos ≤ corte (se calcula solo con el pasado del corte)",
                                "constant_at_cutoff", "Nivel medio log de la serie hasta el corte", required_data=(DATA_SALES,)))
    rows.append(FeatureContract("weight", "training_weight", _AC.OBSERVED_HISTORICAL,
                                "day_status y quiebre de stock (profiling.days.censored_days)", _SALES_KNOWN_AT, "not_applicable",
                                "Peso de entrenamiento: 0 en días con quiebre, fuente ausente o cierre (no es una feature del modelo)",
                                required_data=(DATA_SALES,)))
    rows.append(FeatureContract(
        "external_indicators", "external", _AC.OBSERVED_HISTORICAL,
        "external_indicator_observations (indicador público o de la empresa; nunca combinados)",
        "COALESCE(available_at, GREATEST(published_at, ingested_at)) < medianoche local de (C+1) (rezago de publicación)",
        "carried_last_visible_with_staleness", "Indicadores macro as-of (desactivado por defecto; Fase 4)",
        required_data=(DATA_EXTERNAL,), on_missing="disable", enabled_by_default=False, produced_as=(f"{FACTOR_COLUMN_PREFIX}*",),
        config={"enabled": False},
        assumption="El valor visible al corte se arrastra a los días del horizonte con su antigüedad; pasada la antigüedad máxima "
                   "queda STALE y, según la estrategia de ausencia del factor, se deshabilita."))
    return tuple(rows)


FEATURE_CONTRACTS: tuple[FeatureContract, ...] = _build()
_BY_NAME: dict[str, FeatureContract] = {c.name: c for c in FEATURE_CONTRACTS}
if len(_BY_NAME) != len(FEATURE_CONTRACTS):
    raise FeatureContractError("Nombres de feature duplicados en el registro de contratos.")


def contracts() -> tuple[FeatureContract, ...]:
    return FEATURE_CONTRACTS


def contract_for(name: str) -> FeatureContract:
    """Contrato de una feature por su nombre o por el nombre de columna que genera MLForecast."""
    found = _column_index().get(name)
    if found is None:
        raise FeatureContractError(f"La feature {name!r} no tiene contrato de disponibilidad (registro {FEATURE_CONTRACTS_VERSION}).")
    return found


def _column_index() -> dict[str, FeatureContract]:
    index: dict[str, FeatureContract] = {}
    for c in FEATURE_CONTRACTS:
        for col in c.columns:
            if not col.endswith("*"):
                index[col] = c
    return index


def validate_feature_columns(columns: Iterable[str], extra: Mapping[str, FeatureContract] | None = None,
                             exempt: Iterable[str] = KEY_COLUMNS) -> None:
    """Falla si alguna columna de un conjunto de features no tiene contrato. `extra`: contratos dinámicos (columnas de factores
    externos, `factor__<clave>`, que construye el pipeline de factores). Las claves y el objetivo (`unique_id`, `ds`, `y`) no son
    features."""
    index = _column_index()
    skip = set(exempt)
    missing = [c for c in columns if c not in skip and c not in index and c not in (extra or {})]
    if missing:
        raise FeatureContractError(
            f"Features sin contrato de disponibilidad: {sorted(missing)}. Cada feature debe declarar su clase, fuente y datos requeridos "
            f"en predictily_engine/availability/contracts.py (registro {FEATURE_CONTRACTS_VERSION}).")


def registry_rows() -> list[dict[str, Any]]:
    """Filas de `forecast_features` (documentación y trazabilidad), derivadas del registro de contratos. Mismos nombres, tipos y
    descripciones que antes; la configuración añade la clase de disponibilidad, la fuente y los datos requeridos."""
    rows = []
    for c in FEATURE_CONTRACTS:
        if c.kind == "training_weight":
            continue
        rows.append({"name": c.name, "kind": c.kind, "description": c.description,
                     "config": {**dict(c.config), "availability_class": c.availability_class.value, "source": c.source,
                                "required_data": list(c.required_data), "future_mode": c.future_mode}})
    return rows


def required_data_map(include_optional: bool = False) -> dict[str, tuple[str, ...]]:
    """Mapa declarativo feature → datos requeridos (lo consume el gate de calidad para DEGRADE)."""
    return {c.name: (*c.required_data, *(c.optional_data if include_optional else ())) for c in FEATURE_CONTRACTS}


def features_requiring(dataset: str) -> list[str]:
    """Features cuyo contrato exige el dataset."""
    if dataset not in DATASETS:
        raise FeatureContractError(f"Dataset desconocido: {dataset!r} (válidos: {sorted(DATASETS)})")
    return [c.name for c in FEATURE_CONTRACTS if dataset in c.required_data]


@dataclass(frozen=True)
class DegradePlan:
    """Efecto de la falta de datos sobre las features: `disable` se omiten (opcionales); `neutral` se conservan con un valor neutro
    documentado (se registra como limitación); `blocked` son imprescindibles y obligan a detener la ejecución."""

    unavailable_data: tuple[str, ...]
    disable: tuple[str, ...]
    blocked: tuple[str, ...]
    neutral: tuple[str, ...] = ()

    @property
    def feasible(self) -> bool:
        return not self.blocked


def degrade_plan(unavailable_data: Iterable[str]) -> DegradePlan:
    """Qué features hay que deshabilitar (opcionales) o no se pueden construir (imprescindibles) sin los datasets dados.

    Las features que no están habilitadas por defecto (p. ej. `external_indicators`) no cuentan: no se construyen de todos modos."""
    missing = tuple(sorted(set(unavailable_data)))
    for token in missing:
        if token not in DATASETS:
            raise FeatureContractError(f"Dataset desconocido: {token!r} (válidos: {sorted(DATASETS)})")
    buckets: dict[str, list[str]] = {"disable": [], "neutral": [], "block": []}
    for c in FEATURE_CONTRACTS:
        if c.enabled_by_default and set(c.required_data) & set(missing):
            buckets[c.on_missing].append(c.name)
    return DegradePlan(missing, tuple(buckets["disable"]), tuple(buckets["block"]), tuple(buckets["neutral"]))


def disabled_columns(names: Iterable[str]) -> frozenset[str]:
    """Columnas que deja de producir el builder al deshabilitar `names`. Falla si una feature es desconocida o no puede omitirse."""
    out: set[str] = set()
    for name in names:
        c = contract_for(name)
        if not c.optional:
            raise FeatureContractError(f"La feature {c.name!r} es imprescindible: no puede deshabilitarse (sus datos requeridos: "
                                       f"{list(c.required_data)}).")
        out.update(col for col in c.columns if not col.endswith("*"))
    return frozenset(out)


def contracts_digest() -> str:
    """Huella SHA-256 del registro (versión y contenido canónico)."""
    payload = json.dumps({"version": FEATURE_CONTRACTS_VERSION, "contracts": [c.as_dict() for c in FEATURE_CONTRACTS]},
                         sort_keys=True, ensure_ascii=False)
    return hashlib.sha256(payload.encode()).hexdigest()


def factor_contract(key: str, availability_class: AvailabilityClass, *, source: str, description: str,
                    required_data: tuple[str, ...] = (DATA_EXTERNAL,), assumption: str | None = None) -> FeatureContract:
    """Contrato dinámico de la columna de un factor externo (`factor__<clave>`), construido por el pipeline de factores desde su
    definición. Es siempre opcional: sin el factor el modelo base queda como estaba."""
    return FeatureContract(
        f"{FACTOR_COLUMN_PREFIX}{key}", "external", availability_class, source,
        "COALESCE(available_at, GREATEST(published_at, ingested_at)) < medianoche local de (C+1), por clase de disponibilidad",
        "carried_last_visible_with_staleness" if availability_class is AvailabilityClass.OBSERVED_HISTORICAL else "by_class",
        description, required_data=required_data, on_missing="disable", enabled_by_default=False, assumption=assumption)
