"""Puntos de extensión por rubro o empresa (instrucctions.md §6.H, ADR-V2-06).

Un perfil sectorial aporta configuración y proveedores (calendarios, variables, restricciones,
transformaciones) mediante datos y contratos; nunca mediante condiciones `si empresa == X` en los
algoritmos del core. Un perfil declara versión, y cada proveedor sus entradas, salidas, unidades y
disponibilidad temporal. Una empresa sin perfil usa solo los valores del core.
"""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Any


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


@dataclass(frozen=True)
class SectorProfile:
    code: str
    version: str
    description: str
    overrides: dict[str, dict[str, Any]] = field(default_factory=dict)  # core -> {clave: valor}
    providers: tuple[ExtensionProvider, ...] = ()

    def providers_of(self, kind: str) -> list[ExtensionProvider]:
        return [p for p in self.providers if p.kind == kind]


_REGISTRY: dict[str, SectorProfile] = {}


def register_profile(profile: SectorProfile) -> None:
    if profile.code in _REGISTRY and _REGISTRY[profile.code].version != profile.version:
        raise ValueError(f"El perfil {profile.code} ya está registrado con otra versión")
    _REGISTRY[profile.code] = profile


def get_profile(code: str | None) -> SectorProfile | None:
    return _REGISTRY.get(code) if code else None


def registered_profiles() -> list[SectorProfile]:
    return sorted(_REGISTRY.values(), key=lambda p: p.code)
