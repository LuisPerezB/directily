"""Disponibilidad temporal de la información (`known_at`) — regla única y documentada.

Tres nociones de fecha (instrucctions.md §6.C): fecha efectiva del hecho, fecha en que la fuente lo conocía
(`known_at`) y fecha de ingestión. Reglas:

1. `known_at` presente: el dato existe para un corte `as_of` solo si `known_at` (hora local de la empresa) es
   anterior o igual al fin de ese día.
2. `known_at` NULL (desconocido; regla de P3, docs/architecture/v2/P3-datos.md §11):
   - ejecución vigente (no retroactiva): se acepta, es información actual de la empresa;
   - ejecución retroactiva (corte histórico, backtest, simulación): NO es conocido a futuro de un corte pasado; el
     dato queda EXCLUIDO y se cuenta como exclusión por disponibilidad desconocida. P4 no aporta fechas de respaldo;
     la única vía explícita es la política `assume_valid_from` del pronóstico de costos (degrada la calidad).
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


def known_by(known_at, as_of: date, retroactive: bool) -> tuple[bool, str]:
    """(¿existe al corte?, motivo). Motivos: known_at | current_run | unknown_availability | after_cutoff."""
    k = to_date(known_at)
    if k is not None:
        return (k <= as_of, "known_at" if k <= as_of else "after_cutoff")
    if not retroactive:
        return True, "current_run"
    return False, "unknown_availability"
