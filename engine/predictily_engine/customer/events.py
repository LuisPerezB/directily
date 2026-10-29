"""Índices de eventos ordenados por grupo y día (compras de una relación, días de compra de un comprador...).

Todas las consultas son "hasta el día d" (búsqueda binaria sobre una clave compuesta grupo×día), de modo
que una característica calculada en un corte solo puede ver eventos con día ≤ corte. Los días se
representan como enteros (días desde 1970-01-01).
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd

KEY_SPAN = 1 << 17  # días representables por grupo (≈ 358 años): la clave grupo*KEY_SPAN+día es monótona
_EPOCH = np.datetime64("1970-01-01")


def to_day(values) -> np.ndarray:
    """Fechas (date, Timestamp, datetime64, str) → enteros de días desde 1970-01-01."""
    if isinstance(values, (date, pd.Timestamp, np.datetime64, str)):
        return np.int64((np.datetime64(pd.Timestamp(values).date(), "D") - _EPOCH).astype(np.int64))
    arr = pd.to_datetime(pd.Series(values)).to_numpy().astype("datetime64[D]")
    return (arr - _EPOCH).astype(np.int64)


def from_day(day: int) -> date:
    return (_EPOCH + np.timedelta64(int(day), "D")).astype("datetime64[D]").astype(object)


class EventIndex:
    """Eventos (grupo, día) con valores acumulados para sumas por ventana en O(log n)."""

    def __init__(self, groups, days, n_groups: int, **values) -> None:
        groups = np.asarray(groups, dtype=np.int64)
        days = np.asarray(days, dtype=np.int64)
        if len(days) and (days.min() < 0 or days.max() >= KEY_SPAN):
            raise ValueError("Día fuera del rango representable")
        order = np.lexsort((days, groups))
        self.n = len(order)
        self.n_groups = int(n_groups)
        self.group = groups[order]
        self.day = days[order]
        self.keys = self.group * KEY_SPAN + self.day
        self.values = {k: np.asarray(v, dtype=np.float64)[order] for k, v in values.items()}
        self.cum = {k: np.concatenate(([0.0], np.cumsum(v))) for k, v in self.values.items()}
        ids = np.arange(self.n_groups)
        self.start = np.searchsorted(self.group, ids, side="left")
        self.end = np.searchsorted(self.group, ids, side="right")
        gap = np.full(self.n, np.nan)
        if self.n > 1:
            same = self.group[1:] == self.group[:-1]
            gap[1:] = np.where(same, self.day[1:] - self.day[:-1], np.nan)
        self.gap = gap
        valid = ~np.isnan(gap)
        self.cum_gap = np.concatenate(([0.0], np.cumsum(np.where(valid, gap, 0.0))))
        self.cum_gap2 = np.concatenate(([0.0], np.cumsum(np.where(valid, gap, 0.0) ** 2)))
        self.cum_gapn = np.concatenate(([0.0], np.cumsum(valid.astype(np.float64))))

    # ── posiciones ──
    def pos(self, groups, day) -> np.ndarray:
        """Índice del primer evento con día > `day` dentro del grupo (los eventos ≤ día quedan antes)."""
        day = np.clip(np.asarray(day, dtype=np.int64), 0, KEY_SPAN - 1)
        return np.searchsorted(self.keys, np.asarray(groups, dtype=np.int64) * KEY_SPAN + day, side="right")

    def count(self, groups, day_lo, day_hi) -> np.ndarray:
        """Eventos con día en (day_lo, day_hi]."""
        return self.pos(groups, day_hi) - self.pos(groups, day_lo)

    def total(self, name: str, groups, day_lo, day_hi) -> np.ndarray:
        """Suma del valor `name` de los eventos con día en (day_lo, day_hi]."""
        cum = self.cum[name]
        return cum[self.pos(groups, day_hi)] - cum[self.pos(groups, day_lo)]

    def last_gaps(self, hi: np.ndarray, groups, k: int) -> np.ndarray:
        """Últimos k intervalos entre eventos consecutivos hasta la posición `hi` (NaN si no existen)."""
        starts = self.start[np.asarray(groups, dtype=np.int64)]
        idx = hi[:, None] - 1 - np.arange(k)[None, :]
        valid = idx > starts[:, None]  # el primer evento del grupo no tiene intervalo previo
        out = self.gap[np.clip(idx, 0, max(self.n - 1, 0))] if self.n else np.full(idx.shape, np.nan)
        return np.where(valid, out, np.nan)

    def last_values(self, name: str, hi: np.ndarray, groups, k: int) -> np.ndarray:
        """Últimos k valores de `name` (más reciente primero); NaN si no existen."""
        starts = self.start[np.asarray(groups, dtype=np.int64)]
        idx = hi[:, None] - 1 - np.arange(k)[None, :]
        valid = idx >= starts[:, None]
        if not self.n:
            return np.full(idx.shape, np.nan)
        return np.where(valid, self.values[name][np.clip(idx, 0, self.n - 1)], np.nan)
