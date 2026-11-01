"""Puntos de extensión por rubro o empresa (instrucctions.md §6.H, ADR-V2-06).

Un perfil sectorial aporta configuración y proveedores (calendarios, variables, restricciones, transformaciones)
mediante datos y contratos; nunca mediante condiciones `si empresa == X` en los algoritmos del core. Un perfil declara
versión, y cada proveedor sus entradas, salidas, unidades y disponibilidad temporal. Una empresa SIN perfil usa solo
los valores del core y no recibe ningún proveedor.

Interfaces (idénticas para todos los perfiles; el core solo conoce estas firmas):

- calendar:        fn(start: date, end: date, as_of: date | None) -> list[CalendarEventRecord]
- feature:         fn(dates: pd.DatetimeIndex, as_of: date, observations: Mapping[date, float] | None) -> pd.DataFrame
- constraint:      fn(as_of: date) -> list[ConstraintRecord]
- transformation:  fn(as_of: date) -> list[TransformationRule]

Disponibilidad temporal (`temporal_availability`):
- known_at:        cada registro declara cuándo se conocía; con `as_of` no se devuelve nada conocido después.
- deterministic:   depende solo de la fecha (calendario, estacionalidad fija): conocible siempre.
- observed_until_as_of: usa observaciones solo hasta `as_of`; hacia delante usa un valor declarado como supuesto.
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from dataclasses import dataclass, field
from datetime import date, datetime
from typing import Any

import pandas as pd

PROVIDER_KINDS = ("calendar", "feature", "constraint", "transformation", "connector")
TEMPORAL_AVAILABILITY = ("known_at", "deterministic", "observed_until_as_of")
CALENDAR_SCOPES = ("country", "region", "tenant", "branch", "category", "product", "sector")
CALENDAR_CATEGORIES = ("holiday", "local_holiday", "commercial_season", "campaign", "promotion", "sector_event",
                       "agricultural_season", "international_peak", "exceptional")


@dataclass(frozen=True)
class CalendarEventRecord:
    """Evento aportado por una extensión; mismos campos que `calendar_events`."""

    scope: str
    scope_ref: str | None
    code: str
    name: str
    category: str
    starts_on: date
    ends_on: date
    status: str = "confirmed"
    known_at: datetime | None = None  # None = desconocido: no se usa a futuro en un backtest
    source: str = "extension"


@dataclass(frozen=True)
class ConstraintRecord:
    """Restricción aportada como dato (el planificador la consume; el core no conoce su origen)."""

    name: str
    kind: str          # min_batch | batch_multiple | max_per_period | capacity | storage_limit | ...
    value: float
    unit: str
    applies_to: str = "all"
    known_at: datetime | None = None


@dataclass(frozen=True)
class TransformationRule:
    """Transformación genérica insumo → producto (cantidades, rendimiento, tiempo y capacidad aportados)."""

    name: str
    inputs: dict[str, float]
    outputs: dict[str, float]
    unit: str
    lead_days: int = 0
    capacity_per_day: float | None = None
    known_at: datetime | None = None


@dataclass(frozen=True)
class ExtensionProvider:
    """Proveedor aportado por una extensión (calendario, variable, restricción o transformación)."""

    kind: str  # calendar | feature | constraint | transformation | connector
    name: str
    version: str
    inputs: tuple[str, ...] = ()
    outputs: tuple[str, ...] = ()
    units: dict[str, str] = field(default_factory=dict)
    temporal_availability: str = "known_at"  # cómo se determina la disponibilidad al corte
    fn: Callable[..., Any] | None = None

    def describe(self) -> dict[str, Any]:
        return {"kind": self.kind, "name": self.name, "version": self.version, "inputs": list(self.inputs),
                "outputs": list(self.outputs), "units": dict(self.units), "temporal_availability": self.temporal_availability}


@dataclass(frozen=True)
class SectorProfile:
    code: str
    version: str
    description: str
    overrides: dict[str, dict[str, Any]] = field(default_factory=dict)  # core -> {clave: valor}
    providers: tuple[ExtensionProvider, ...] = ()
    synthetic: bool = False  # perfil de prueba: no representa un rubro real ni un entregable sectorial

    def providers_of(self, kind: str) -> list[ExtensionProvider]:
        return [p for p in self.providers if p.kind == kind]

    def describe(self) -> dict[str, Any]:
        return {"code": self.code, "version": self.version, "description": self.description, "synthetic": self.synthetic,
                "overrides": {core: sorted(values) for core, values in self.overrides.items()},
                "providers": [p.describe() for p in self.providers]}


_REGISTRY: dict[str, SectorProfile] = {}
_BUILTIN_LOADED = False


def register_profile(profile: SectorProfile) -> None:
    if profile.code in _REGISTRY and _REGISTRY[profile.code].version != profile.version:
        raise ValueError(f"El perfil {profile.code} ya está registrado con otra versión")
    problems = [p for provider in profile.providers for p in validate_provider(provider)]
    if problems:
        raise ValueError(f"El perfil {profile.code} declara proveedores que incumplen el contrato: {problems}")
    _REGISTRY[profile.code] = profile


def _load_builtin() -> None:
    """Registra los perfiles incluidos (sintéticos de prueba). Idempotente; se invoca al consultar el registro."""
    global _BUILTIN_LOADED
    if _BUILTIN_LOADED:
        return
    _BUILTIN_LOADED = True
    from predictily_engine import extensions  # noqa: F401  (el import registra los perfiles)

    extensions.register_builtin_profiles()


def get_profile(code: str | None) -> SectorProfile | None:
    _load_builtin()
    return _REGISTRY.get(code) if code else None


def registered_profiles() -> list[SectorProfile]:
    _load_builtin()
    return sorted(_REGISTRY.values(), key=lambda p: p.code)


def providers_for(sector: str | None, kind: str | None = None) -> list[ExtensionProvider]:
    """Proveedores que aplican a una empresa: los de su perfil sectorial; una empresa sin perfil no recibe ninguno."""
    profile = get_profile(sector)
    if profile is None:
        return []
    return list(profile.providers if kind is None else profile.providers_of(kind))


# ── Contrato de los proveedores ──────────────────────────────────────────────────────────────────────────

def validate_provider(p: ExtensionProvider) -> list[str]:
    """Problemas de declaración de un proveedor (lista vacía = cumple)."""
    problems: list[str] = []
    if p.kind not in PROVIDER_KINDS:
        problems.append(f"{p.name}: tipo desconocido '{p.kind}'")
    if not p.name:
        problems.append("proveedor sin nombre")
    if not p.version or p.version.count(".") < 1:
        problems.append(f"{p.name}: versión no declarada (use MAYOR.MENOR[.PARCHE])")
    if p.temporal_availability not in TEMPORAL_AVAILABILITY:
        problems.append(f"{p.name}: disponibilidad temporal no válida '{p.temporal_availability}'")
    if p.kind in ("feature", "constraint", "transformation") and not p.outputs:
        problems.append(f"{p.name}: no declara salidas")
    for output in p.outputs:
        if output not in p.units:
            problems.append(f"{p.name}: la salida '{output}' no declara unidad")
    if p.fn is None or not callable(p.fn):
        problems.append(f"{p.name}: sin función ejecutable")
    return problems


def run_provider_contract(p: ExtensionProvider, as_of: date, start: date, end: date,
                          observations: Mapping[date, float] | None = None) -> list[str]:
    """Ejecuta un proveedor y comprueba que su salida respeta lo que declara. Lista de incumplimientos."""
    problems = validate_provider(p)
    if problems or p.fn is None:
        return problems
    try:
        if p.kind == "calendar":
            events = p.fn(start, end, as_of)
            for e in events:
                if not isinstance(e, CalendarEventRecord):
                    problems.append(f"{p.name}: devuelve algo que no es CalendarEventRecord")
                    continue
                if e.scope not in CALENDAR_SCOPES or e.category not in CALENDAR_CATEGORIES:
                    problems.append(f"{p.name}: alcance o categoría fuera del catálogo ({e.scope}/{e.category})")
                if e.ends_on < e.starts_on:
                    problems.append(f"{p.name}: evento '{e.code}' termina antes de empezar")
                if e.known_at is not None and e.known_at.date() > as_of:
                    problems.append(f"{p.name}: evento '{e.code}' conocido el {e.known_at.date()} se devolvió para un corte {as_of}")
                if e.known_at is None and p.temporal_availability == "known_at":
                    problems.append(f"{p.name}: evento '{e.code}' sin known_at no puede declararse disponible al corte")
        elif p.kind == "feature":
            dates = pd.date_range(start, end, freq="D")
            frame = p.fn(dates, as_of, observations)
            if not isinstance(frame, pd.DataFrame):
                problems.append(f"{p.name}: debe devolver un DataFrame")
            else:
                if set(frame.columns) != set(p.outputs):
                    problems.append(f"{p.name}: columnas {sorted(frame.columns)} distintas de las declaradas {sorted(p.outputs)}")
                if not frame.index.equals(dates):
                    problems.append(f"{p.name}: el índice debe coincidir con las fechas pedidas")
                if frame.isna().any().any():
                    problems.append(f"{p.name}: devuelve valores nulos (un valor desconocido debe declararse, no inventarse)")
        elif p.kind == "constraint":
            for c in p.fn(as_of):
                if not isinstance(c, ConstraintRecord):
                    problems.append(f"{p.name}: devuelve algo que no es ConstraintRecord")
                elif not c.unit:
                    problems.append(f"{p.name}: restricción '{c.name}' sin unidad")
                elif c.known_at is not None and c.known_at.date() > as_of:
                    problems.append(f"{p.name}: restricción '{c.name}' conocida después del corte")
        elif p.kind == "transformation":
            for r in p.fn(as_of):
                if not isinstance(r, TransformationRule):
                    problems.append(f"{p.name}: devuelve algo que no es TransformationRule")
                elif not r.inputs or not r.outputs or not r.unit:
                    problems.append(f"{p.name}: regla '{r.name}' sin insumos, productos o unidad")
    except Exception as exc:  # pragma: no cover - el mensaje es el diagnóstico
        problems.append(f"{p.name}: falló al ejecutarse ({exc.__class__.__name__}: {exc})")
    return problems
