"""Perfil SINTÉTICO B: proceso por lotes ficticio. Datos inventados; no representa ninguna empresa ni rubro real."""

from __future__ import annotations

from collections.abc import Mapping
from datetime import date, datetime, timedelta, timezone

import numpy as np
import pandas as pd

from predictily_engine.platform.extensions import (
    CalendarEventRecord,
    ConstraintRecord,
    ExtensionProvider,
    SectorProfile,
    TransformationRule,
)

CODE = "synthetic_process_b"
VERSION = "1.0.0"
UTC = timezone.utc


def calendar(start: date, end: date, as_of: date | None) -> list[CalendarEventRecord]:
    """Paro de mantenimiento programado el 1.º de cada trimestre (3 días), planificado 60 días antes."""
    events = []
    for year in range(start.year, end.year + 1):
        for month in (1, 4, 7, 10):
            starts = date(year, month, 1)
            ends = starts + timedelta(days=2)
            known = datetime.combine(starts - timedelta(days=60), datetime.min.time(), tzinfo=UTC)
            if ends < start or starts > end or (as_of is not None and known.date() > as_of):
                continue
            events.append(CalendarEventRecord(scope="sector", scope_ref=f"sector:{CODE}", code=f"paro_mantenimiento_{year}_{month:02d}",
                                              name="Paro de mantenimiento (sintético)", category="exceptional", starts_on=starts, ends_on=ends,
                                              status="planned", known_at=known, source=CODE))
    return events


def ambient_temperature(dates: pd.DatetimeIndex, as_of: date, observations: Mapping[date, float] | None = None) -> pd.DataFrame:
    """Temperatura ambiente (°C): observada hasta `as_of`; después, la climatología declarada como SUPUESTO (nunca lo realizado)."""
    seasonal = 22.0 + 4.0 * np.sin(2 * np.pi * (dates.dayofyear.to_numpy() - 80) / 365.25)
    values = []
    for d, fallback in zip(dates, seasonal, strict=True):
        day = d.date()
        observed = (observations or {}).get(day)
        values.append(float(observed) if observed is not None and day <= as_of else float(fallback))
    return pd.DataFrame({"ambient_temperature_c": values}, index=dates)


def constraints(as_of: date) -> list[ConstraintRecord]:
    known = datetime(2025, 1, 1, tzinfo=UTC)
    return [
        ConstraintRecord(name="min_batch", kind="min_batch", value=200.0, unit="kg", known_at=known),
        ConstraintRecord(name="batch_multiple", kind="batch_multiple", value=50.0, unit="kg", known_at=known),
        ConstraintRecord(name="line_capacity", kind="capacity", value=800.0, unit="kg/day", known_at=known),
    ]


def transformations(as_of: date) -> list[TransformationRule]:
    return [TransformationRule(name="conversion_basica", inputs={"insumo_x": 1.25}, outputs={"producto_y": 1.0}, unit="kg", lead_days=3,
                               capacity_per_day=800.0, known_at=datetime(2025, 1, 1, tzinfo=UTC))]


PROFILE = SectorProfile(
    code=CODE,
    version=VERSION,
    description="Proceso por lotes ficticio (prueba de los puntos de extensión).",
    synthetic=True,
    overrides={
        "demand": {"horizons": [7, 30, 90]},
        "supply": {"default_lead_time_days": 21, "service_level": 0.90, "review_period_days": 14, "solver_time_limit_seconds": 60},
    },
    providers=(
        ExtensionProvider(kind="calendar", name="process_b_calendar", version="1.0.0", inputs=("start", "end", "as_of"), outputs=("calendar_events",),
                          units={"calendar_events": "event"}, temporal_availability="known_at", fn=calendar),
        ExtensionProvider(kind="feature", name="process_b_ambient_temperature", version="1.0.0", inputs=("dates", "as_of", "observations"),
                          outputs=("ambient_temperature_c",), units={"ambient_temperature_c": "celsius"},
                          temporal_availability="observed_until_as_of", fn=ambient_temperature),
        ExtensionProvider(kind="constraint", name="process_b_batch_rules", version="1.0.0", inputs=("as_of",),
                          outputs=("min_batch", "batch_multiple", "line_capacity"),
                          units={"min_batch": "kg", "batch_multiple": "kg", "line_capacity": "kg/day"}, temporal_availability="known_at", fn=constraints),
        ExtensionProvider(kind="transformation", name="process_b_transformation", version="1.0.0", inputs=("as_of",), outputs=("conversion_basica",),
                          units={"conversion_basica": "kg"}, temporal_availability="known_at", fn=transformations),
    ),
)
