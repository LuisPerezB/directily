"""Disponibilidad temporal de la información (`known_at`) — regla única y documentada.

Tres nociones de fecha (instrucctions.md §6.C): fecha efectiva del hecho, fecha en que la fuente lo conocía
(`known_at`) y fecha de ingestión. Reglas:

1. `known_at` presente: el dato existe para un corte `as_of` solo si `known_at` (hora local de la empresa) es
   anterior o igual al fin de ese día.
2. `known_at` NULL (desconocido):
   - ejecución vigente (no retroactiva): se acepta, es información actual de la empresa;
   - ejecución retroactiva (corte histórico, backtest, simulación): se usa la fecha de respaldo SOLO si el
     llamador aporta una evidencia propia del hecho (p. ej. el costo de una compra real se conoce en la fecha de
     la compra); sin respaldo el dato queda EXCLUIDO y se cuenta como exclusión por disponibilidad desconocida.
La ingestión actual de un histórico no demuestra que algo se conociera antes.
"""

from __future__ import annotations

from datetime import date, datetime

import pandas as pd


def to_date(value) -> date | None:
    if value is None or value is pd.NaT:
        return None
    if isinstance(value, float) and pd.isna(value):
        return None
    if isinstance(value, pd.Timestamp):
        return value.date()
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    try:
        return pd.Timestamp(value).date()
    except (ValueError, TypeError):
        return None


def known_by(known_at, as_of: date, retroactive: bool, fallback: date | None = None) -> tuple[bool, str]:
    """(¿existe al corte?, motivo). Motivos: known_at | current_run | fallback_evidence | unknown_availability | after_cutoff."""
    k = to_date(known_at)
    if k is not None:
        return (k <= as_of, "known_at" if k <= as_of else "after_cutoff")
    if not retroactive:
        return True, "current_run"
    if fallback is not None:
        return (fallback <= as_of, "fallback_evidence" if fallback <= as_of else "after_cutoff")
    return False, "unknown_availability"
