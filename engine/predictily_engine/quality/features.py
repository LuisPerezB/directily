"""Mapa declarativo feature → datos requeridos y efecto de DEGRADE (V2.5 §5, ADR-V25-05).

Cuando un gate de un módulo declara INSUFICIENTE un dato requerido (regla DEGRADE con `data_kind`), se deshabilitan las features
opcionales que lo exigen. Aquí viven tres cosas:

1. `DEGRADABLE_FEATURES`: las features, salidas y políticas que el motor SABE deshabilitar, con los datos que requieren y el efecto real
   (columna neutralizada, salida omitida, opción de configuración forzada).
2. `FEATURE_DATA` / `KIND_DATA`: el mapa completo feature → datos requeridos del feature set de demanda (`features.builder.feature_registry`),
   incluidas las features sin criterio de suficiencia todavía. Una prueba recorre ese registro y falla si una feature no tiene mapa.
3. El PUNTO DE INTEGRACIÓN con el registro de contratos de features de W2-D (`set_contract_provider`): W2-D declara para cada feature sus
   `required_data` con el vocabulario de `rules.DATA_KINDS`; el arquitecto conecta su registro con una línea y este módulo (a) suma esos
   requisitos a los propios y (b) verifica la consistencia (`check_registry`). Sin proveedor, el mapa propio manda.

Efecto sobre el modelo: `pipeline/core.py` construye el `FeatureBuilder` de W2-D con `disabled_features=` (el builder OMITE la columna en
el entrenamiento, el backtest —también en las ventanas as-of reconstruidas— y la predicción; una prueba demuestra que el resultado es
equivalente a neutralizarla). `MaskedFeatureBuilder` (columna constante) queda como respaldo para un builder sin esa opción.
"""

from __future__ import annotations

from collections.abc import Callable, Iterable, Mapping
from dataclasses import dataclass
from typing import Any

from predictily_engine.quality.rules import DATA_KINDS


@dataclass(frozen=True)
class DegradableFeature:
    name: str
    module: str            # demand | customer | supply
    kind: str              # model_feature | output | policy
    requires: tuple[str, ...]   # claves de rules.DATA_KINDS
    effect: str            # efecto estable cuando se deshabilita
    description: str


DEGRADABLE_FEATURES: tuple[DegradableFeature, ...] = (
    DegradableFeature(
        "price_ratio", "demand", "model_feature", ("price",), "builder_disabled_features:price_ratio",
        "Precio neto / precio mediano de la serie. Deshabilitada: el builder omite la columna (el modelo no la ve en el entrenamiento, el "
        "backtest ni la predicción); el supuesto de ingresos (último precio neto) no cambia."),
    DegradableFeature(
        "customer.individual_predictions", "customer", "output", ("identified_buyers", "stable_ids"), "skip_individual_predictions",
        "Probabilidad y cantidad por comprador × producto. Deshabilitada: no se entrena ni se predice a nivel comprador; se conserva el "
        "pronóstico agregado del Demand Core (V2.5 §25.2)."),
    DegradableFeature(
        "customer.individual_rankings", "customer", "output", ("identified_buyers", "stable_ids"), "skip_individual_rankings",
        "Ranking de productos por comprador. Deshabilitado junto con las predicciones individuales de las que depende."),
    DegradableFeature(
        "customer.activity_risk", "customer", "output", ("identified_buyers", "stable_ids"), "skip_activity_risk",
        "Riesgo de inactividad y cambio de comportamiento por comprador. Deshabilitado: exige la identidad estable del comprador."),
    DegradableFeature(
        "supply.implicit_offers", "supply", "policy", ("lead_times",), "config:use_implicit_offers=false",
        "Oferta implícita (último costo conocido + plazo por defecto) para productos sin oferta. Deshabilitada: esos productos se excluyen "
        "del plan con el motivo `no_offer` en lugar de planificarse con un plazo supuesto."),
    DegradableFeature(
        "supply.observed_lead_time_policy", "supply", "policy", ("lead_times",), "config:receipt_policy_observed_lead_time→lead_time_end",
        "Política de fecha de recepciones sin fecha según el plazo observado en las órdenes. Deshabilitada: si estaba configurada, se vuelve "
        "a `lead_time_end` (plazo conocido de la oferta)."),
)

DEGRADABLE_BY_NAME: dict[str, DegradableFeature] = {f.name: f for f in DEGRADABLE_FEATURES}

# Mapa completo del feature set de demanda (nombre exacto) y por familia (`kind` del registro del builder). Los términos son los de
# `rules.DATA_KINDS`, que coinciden con los de `availability.contracts.DATASETS` (W2-D) donde se solapan.
FEATURE_DATA: dict[str, tuple[str, ...]] = {
    "promo_discount": ("promotions",),
    "price_ratio": ("price",),
    "series_level": ("sales_history",),
    "branch_code_num": ("branches",),
    "category_num": ("product_category",),
    "region_num": ("branch_region",),
    "external_indicators": ("external_indicators",),
}
KIND_DATA: dict[str, tuple[str, ...]] = {
    "calendar": ("calendar",),
    "lag": ("sales_history",),
    "rolling": ("sales_history",),
    "static": ("branches", "product_category"),
    "geographic": ("branch_region",),
    "commercial": ("promotions", "price"),
    "external": ("external_indicators",),
}

# RESPALDO (builder sin `disabled_features`): valor con que `MaskedFeatureBuilder` vuelve constante cada columna deshabilitada.
NEUTRALIZED_COLUMNS: dict[str, float] = {"price_ratio": 1.0}

ContractProvider = Callable[[], Mapping[str, Iterable[str]]]
_provider: ContractProvider | None = None


def set_contract_provider(provider: ContractProvider | None) -> None:
    """Conecta el registro de contratos de features de W2-D: `provider()` devuelve `{feature: required_data}` con el vocabulario de
    `rules.DATA_KINDS`. Ejemplo (en el arranque del motor, cuando W2-D esté integrado):

        set_contract_provider(lambda: {name: c.required_data for name, c in FEATURE_CONTRACTS.items()})

    `None` lo desconecta (valor inicial)."""
    global _provider
    _provider = provider


def connect_feature_contracts() -> bool:
    """Conecta el registro de contratos de features de W2-D (`availability.contracts.required_data_map`) como proveedor. Devuelve False si
    ese módulo todavía no existe en el árbol. Es el punto de integración que el arquitecto invoca (o llama `set_contract_provider` con otro
    proveedor) cuando W2-D esté integrado."""
    try:
        from predictily_engine.availability.contracts import required_data_map
    except ImportError:
        return False
    set_contract_provider(required_data_map)
    return True


def provider_requirements() -> dict[str, tuple[str, ...]]:
    if _provider is None:
        return {}
    return {str(name): tuple(str(k) for k in kinds) for name, kinds in _provider().items()}


def required_data(feature: str, kind: str | None = None) -> tuple[str, ...]:
    """Datos que requiere una feature: unión de lo que declara el registro de W2-D (si está conectado) y del mapa propio."""
    own: tuple[str, ...] = ()
    if feature in DEGRADABLE_BY_NAME:
        own = DEGRADABLE_BY_NAME[feature].requires
    elif feature in FEATURE_DATA:
        own = FEATURE_DATA[feature]
    elif kind in KIND_DATA:
        own = KIND_DATA[kind or ""]
    extra = provider_requirements().get(feature, ())
    return tuple(dict.fromkeys([*own, *extra]))


def unmapped_registry_features(rows: Iterable[Mapping[str, Any]]) -> list[str]:
    """Filas del registro del builder (`{"name", "kind"}`) sin ningún dato requerido en el mapa."""
    return [str(r["name"]) for r in rows if not required_data(str(r["name"]), r.get("kind"))]


def features_disabled_by(module: str, insufficient: Iterable[str]) -> list[str]:
    """Features degradables del módulo que requieren alguno de los datos `insufficient` (orden estable)."""
    kinds = set(insufficient)
    return sorted(f.name for f in DEGRADABLE_FEATURES if f.module == module and kinds & set(required_data(f.name)))


def check_registry(registry: Mapping[str, Iterable[str]]) -> list[dict[str, str]]:
    """Consistencia entre un registro de contratos (`{feature: required_data}`) y el mapa propio. Problemas posibles:
    `unknown_data_kind` (dato fuera del vocabulario), `missing_in_registry` (feature degradable de demanda sin contrato) y
    `requirement_mismatch` (el registro declara datos distintos a los del mapa propio para una feature degradable)."""
    problems: list[dict[str, str]] = []
    normalized = {str(k): tuple(str(x) for x in v) for k, v in registry.items()}
    for feature, kinds in normalized.items():
        for kind in kinds:
            if kind not in DATA_KINDS:
                problems.append({"feature": feature, "problem": "unknown_data_kind", "detail": kind})
    for f in DEGRADABLE_FEATURES:
        if f.module != "demand":
            continue
        if f.name not in normalized:
            problems.append({"feature": f.name, "problem": "missing_in_registry", "detail": "feature degradable sin contrato"})
        elif set(normalized[f.name]) != set(f.requires):
            problems.append({"feature": f.name, "problem": "requirement_mismatch",
                             "detail": f"registro={sorted(normalized[f.name])} mapa={sorted(f.requires)}"})
    return problems


class MaskedFeatureBuilder:
    """Envuelve un `FeatureBuilder` y neutraliza columnas de modelo (constantes) en `training_frame` y `future_frame`.

    Todo lo demás (`knowledge_date`, `panel_df`, `series`, …) se delega al constructor original, así que se puede usar donde se usaba
    el original: ejecución final, backtest y ventanas as-of."""

    def __init__(self, inner: Any, neutral: Mapping[str, float]) -> None:
        self._inner = inner
        self.neutralized = dict(neutral)

    def _mask(self, frame: Any) -> Any:
        for column, value in self.neutralized.items():
            if column in frame:
                frame[column] = float(value)
        return frame

    def training_frame(self, cutoff: Any, ids: list[str]):
        frame, ref = self._inner.training_frame(cutoff, ids)
        return self._mask(frame), ref

    def future_frame(self, cutoff: Any, ids: list[str], h: int, ref_price: Any):
        return self._mask(self._inner.future_frame(cutoff, ids, h, ref_price))

    def __getattr__(self, name: str) -> Any:
        return getattr(self._inner, name)


def neutral_columns(disabled: Iterable[str]) -> dict[str, float]:
    return {c: NEUTRALIZED_COLUMNS[c] for c in disabled if c in NEUTRALIZED_COLUMNS}


def mask_feature_builder(features: Any, disabled: Iterable[str]) -> Any:
    """`features` con las columnas de las features deshabilitadas neutralizadas (el mismo objeto si no hay nada que neutralizar)."""
    cols = neutral_columns(disabled)
    return MaskedFeatureBuilder(features, cols) if cols else features


def mask_windows(windows: Any, disabled: Iterable[str]) -> None:
    """Aplica la misma neutralización a las variables de cada ventana as-of que se reconstruya (`AsOfWindows.make_features`)."""
    cols = neutral_columns(disabled)
    if windows is None or not cols:
        return
    original = windows.make_features
    windows.make_features = lambda df: MaskedFeatureBuilder(original(df), cols)


def apply_supply_degradation(inputs: Any, disabled: Iterable[str]) -> list[str]:
    """Aplica a `PlanInputs` las políticas de abastecimiento deshabilitadas. Devuelve la lista de cambios realizados (para
    `limitations`); no muta los diccionarios de configuración originales."""
    disabled = set(disabled)
    changes: list[str] = []
    config = dict(inputs.config or {})
    options = dict(inputs.options or {})
    if "supply.implicit_offers" in disabled:
        was = options.get("use_implicit_offers", config.get("use_implicit_offers", True))
        config["use_implicit_offers"] = False
        if "use_implicit_offers" in options:
            options["use_implicit_offers"] = False
        if was:
            changes.append("Ofertas implícitas deshabilitadas: los productos sin oferta se excluyen del plan (`no_offer`).")
    if "supply.observed_lead_time_policy" in disabled:
        for key in ("undated_receipt_policy", "overdue_receipt_policy"):
            current = options.get(key) if options.get(key) is not None else config.get(key)
            if current == "observed_lead_time" or (key == "overdue_receipt_policy" and current is None
                                                   and (options.get("undated_receipt_policy") or config.get("undated_receipt_policy")) == "observed_lead_time"):
                config[key] = "lead_time_end"
                if key in options:
                    options[key] = "lead_time_end"
                changes.append(f"Política `{key}` forzada de `observed_lead_time` a `lead_time_end` (plazo explícito insuficiente).")
    inputs.config, inputs.options = config, options
    return changes
