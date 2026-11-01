"""Perfiles sectoriales incluidos: SOLO sintéticos, para probar los puntos de extensión (instrucctions.md §6.H).

No son entregables de rubro (retail, industria, agro...): demuestran que dos perfiles de negocio distintos usan las
MISMAS interfaces (proveedor de calendario, variable adicional, restricción, transformación) y que una empresa sin
perfil sigue funcionando solo con el core. Un perfil real se agrega con `register_profile` sin tocar el core.
"""

from __future__ import annotations


def register_builtin_profiles() -> None:
    from predictily_engine.extensions import synthetic_process_b, synthetic_retail_a
    from predictily_engine.platform.extensions import register_profile

    register_profile(synthetic_retail_a.PROFILE)
    register_profile(synthetic_process_b.PROFILE)
