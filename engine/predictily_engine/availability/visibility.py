"""Regla de visibilidad ÚNICA para todos los factores (ADR-V25-06), reutilizada de W1 y de supply.

No define una regla propia: compone las dos que ya existen y que son equivalentes por construcción.

1. **Instantes** (`available_at`, `published_at`, `issued_at`, `known_at`…): visible al corte `C` si
   `instante < medianoche LOCAL de (C + 1)` (`data.asof.knowledge_boundary`, ADR-V25-23). Es «visible si es ≤ fin del día local
   del corte» de ADR-V25-06 expresado sin ambigüedad en el último instante del día.
2. **Disponibilidad desconocida** (sin fecha): la regla de `supply.availability.known_by` — una ejecución vigente la acepta
   (información actual de la empresa) y una reconstrucción retroactiva (backtest, corte histórico) la EXCLUYE: la ingestión
   actual de un histórico no demuestra que se conociera antes.

Convenciones de tiempo: los instantes con zona se convierten a UTC; los instantes SIN zona se interpretan como UTC (igual que
`data.asof`, que trabaja con «instantes UTC sin zona»). El día de negocio es el día LOCAL de la empresa (`tenants.timezone`).
"""

from __future__ import annotations

from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.data.asof import knowledge_boundary, local_day, resolve_timezone, to_day
from predictily_engine.supply.availability import known_by, to_date

__all__ = ["boundary", "local_date", "to_date", "to_day", "to_utc_naive", "visible_mask", "resolve_timezone", "known_by"]


def to_utc_naive(values: Any) -> pd.Series:
    """Serie de instantes → `datetime64[ns]` UTC sin zona. Con zona se convierte a UTC; sin zona se asume UTC; lo ilegible es NaT."""
    s = values if isinstance(values, pd.Series) else pd.Series(values)
    out = pd.to_datetime(s.reset_index(drop=True), utc=True, errors="coerce")
    return out.dt.tz_localize(None).astype("datetime64[ns]").set_axis(s.index)


def boundary(cutoff: Any, tz: str | None) -> np.datetime64:
    """`M(C + 1)`: medianoche local del día siguiente al corte, en UTC sin zona. Visible ⇔ instante < esta frontera."""
    return knowledge_boundary(cutoff, tz)


def local_date(instant: Any, tz: str | None) -> date | None:
    """Día LOCAL de un instante (None si no hay instante)."""
    if instant is None or instant is pd.NaT or (isinstance(instant, float) and np.isnan(instant)):
        return None
    ts = pd.Timestamp(instant)
    ts = ts.tz_convert("UTC").tz_localize(None) if ts.tzinfo is not None else ts
    return pd.Timestamp(local_day([np.datetime64(ts, "ns")], tz)[0]).date()


def visible_mask(available: pd.Series, cutoff: Any, tz: str | None, retroactive: bool) -> np.ndarray:
    """Máscara de las filas visibles al corte. `available` son instantes UTC sin zona (NaT = disponibilidad desconocida).

    - con instante: `instante < medianoche local (C + 1)` (regla de W1);
    - sin instante: `known_by(None, C, retroactive)` (regla de supply): visible solo en una ejecución vigente."""
    values = available.to_numpy("datetime64[ns]")
    known = ~np.isnat(values)
    mask = np.zeros(len(values), dtype=bool)
    if known.any():
        mask[known] = values[known] < boundary(cutoff, tz)
    if (~known).any():
        mask[~known] = known_by(None, to_date(cutoff), retroactive)[0]
    return mask
