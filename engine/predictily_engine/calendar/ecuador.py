"""Calendario comercial de Ecuador.

- Feriados nacionales con las reglas de traslado de la Ley Orgánica Reformatoria a la
  LOSEP y al Código del Trabajo (2016), vía la librería `holidays` (país EC). Cuando la
  ley traslada un feriado, el día original pasa a ser laborable: aquí solo se conserva
  el día efectivo de descanso.
- Feriados locales relevantes (Fundación de Quito, Fundación de Guayaquil) con el mismo
  criterio de traslado.
- Eventos comerciales: quincenas, fin de mes, décimo tercer y décimo cuarto sueldo (según
  región), Día de la Madre/Padre, Black Friday, Navidad y regreso a clases por región.

Los traslados extraordinarios por decreto ejecutivo ("puentes") no son predecibles y no se
incluyen; pueden añadirse como eventos manuales en el futuro.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, timedelta
from functools import lru_cache

import holidays
import numpy as np
import pandas as pd

_OBSERVED_SUFFIX = "(observado)"


def _transfer(d: date) -> date:
    """Regla general de traslado: mar→lun anterior; mié/jue→vie; sáb→vie; dom→lun."""
    wd = d.weekday()  # 0 = lunes
    if wd == 1:
        return d - timedelta(days=1)
    if wd in (2, 3):
        return d + timedelta(days=4 - wd)
    if wd == 5:
        return d - timedelta(days=1)
    if wd == 6:
        return d + timedelta(days=1)
    return d


@lru_cache(maxsize=64)
def _national_rest_days(year: int) -> dict[date, str]:
    raw = holidays.country_holidays("EC", years=year, observed=True, language="es")
    items = {d: name for d, name in raw.items()}
    observed_names = {
        name.replace(_OBSERVED_SUFFIX, "").strip()
        for name in items.values()
        if _OBSERVED_SUFFIX in name
    }
    result: dict[date, str] = {}
    for d, name in items.items():
        base = name.replace(_OBSERVED_SUFFIX, "").strip()
        is_observed = _OBSERVED_SUFFIX in name
        # Si existe el día observado, el original deja de ser feriado.
        if not is_observed and base in observed_names:
            continue
        result[d] = base
    return result


_LOCAL_HOLIDAYS = {
    # canton (normalizado) -> lista de (mes, día, nombre)
    "quito": [(12, 6, "Fundación de Quito")],
    "guayaquil": [(7, 25, "Fundación de Guayaquil")],
}


def rest_days(year: int, canton: str | None = None) -> dict[date, str]:
    """Días efectivos de descanso obligatorio (nacionales + locales del cantón)."""
    days = dict(_national_rest_days(year))
    if canton:
        for month, day, name in _LOCAL_HOLIDAYS.get(canton.strip().lower(), []):
            days[_transfer(date(year, month, day))] = name
    return days


def _nth_weekday(year: int, month: int, weekday: int, n: int) -> date:
    first = date(year, month, 1)
    offset = (weekday - first.weekday()) % 7
    return first + timedelta(days=offset + 7 * (n - 1))


def _carnival_monday(year: int) -> date | None:
    for d, name in _national_rest_days(year).items():
        if "Carnaval" in name and d.weekday() == 0:
            return d
    return None


@dataclass(frozen=True)
class EcuadorCalendar:
    """Genera variables de calendario conocidas de antemano (sin fuga temporal)."""

    region: str | None = None  # 'costa' | 'sierra' | None
    canton: str | None = None

    FEATURES: tuple[str, ...] = (
        "is_holiday",
        "is_pre_holiday",
        "is_post_holiday",
        "is_payday_window",
        "is_month_end",
        "is_decimo_tercero",
        "is_decimo_cuarto",
        "is_school_season",
        "is_mothers_day_window",
        "is_fathers_day_window",
        "is_black_friday_window",
        "is_christmas_window",
        "is_carnival_window",
    )

    def frame(self, start: date, end: date) -> pd.DataFrame:
        """DataFrame indexado por fecha con todas las variables (0/1)."""
        idx = pd.date_range(start, end, freq="D")
        years = range(start.year - 1, end.year + 2)
        holidays_set: set[date] = set()
        for y in years:
            holidays_set.update(rest_days(y, self.canton).keys())
        days = [d.date() for d in idx]
        hol = np.array([d in holidays_set for d in days])
        pre = np.array([(d + timedelta(days=1)) in holidays_set and d not in holidays_set for d in days])
        post = np.array([(d - timedelta(days=1)) in holidays_set and d not in holidays_set for d in days])
        dom = idx.day.to_numpy()
        dim = idx.days_in_month.to_numpy()
        month = idx.month.to_numpy()
        payday = (dom == 15) | (dom == 16) | (dom == dim) | (dom <= 2)
        month_end = dom >= dim - 2
        decimo3 = (month == 12) & (dom >= 10) & (dom <= 24)
        if self.region == "sierra":
            decimo4 = (month == 8) & (dom <= 15)
            school = ((month == 8) & (dom >= 15)) | ((month == 9) & (dom <= 10))
        elif self.region == "costa":
            decimo4 = (month == 3) & (dom <= 15)
            school = (month == 4) | ((month == 5) & (dom <= 10))
        else:
            decimo4 = np.zeros(len(idx), dtype=bool)
            school = np.zeros(len(idx), dtype=bool)

        def window(fn, before: int, after: int = 0) -> np.ndarray:
            flags = np.zeros(len(idx), dtype=bool)
            for y in years:
                anchor = fn(y)
                if anchor is None:
                    continue
                lo, hi = anchor - timedelta(days=before), anchor + timedelta(days=after)
                flags |= np.array([lo <= d <= hi for d in days])
            return flags

        mothers = window(lambda y: _nth_weekday(y, 5, 6, 2), before=7)
        fathers = window(lambda y: _nth_weekday(y, 6, 6, 3), before=7)
        # Black Friday: día siguiente al 4.º jueves de noviembre, más el fin de semana.
        black_friday = window(lambda y: _nth_weekday(y, 11, 3, 4) + timedelta(days=1), before=0, after=3)
        christmas = (month == 12) & (dom >= 15) & (dom <= 24)
        carnival = window(_carnival_monday, before=3, after=1)

        data = {
            "is_holiday": hol,
            "is_pre_holiday": pre,
            "is_post_holiday": post,
            "is_payday_window": payday,
            "is_month_end": month_end,
            "is_decimo_tercero": decimo3,
            "is_decimo_cuarto": decimo4,
            "is_school_season": school,
            "is_mothers_day_window": mothers,
            "is_fathers_day_window": fathers,
            "is_black_friday_window": black_friday,
            "is_christmas_window": christmas,
            "is_carnival_window": carnival,
        }
        return pd.DataFrame({k: v.astype(np.int8) for k, v in data.items()}, index=idx)
