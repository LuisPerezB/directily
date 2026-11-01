"""Perfil SINTÉTICO A: comercio minorista ficticio. Datos inventados; no representa ninguna empresa ni rubro real."""

from __future__ import annotations

from collections.abc import Mapping
from datetime import date, datetime, timezone

import pandas as pd

from predictily_engine.platform.extensions import (
    CalendarEventRecord,
    ConstraintRecord,
    ExtensionProvider,
    SectorProfile,
)

CODE = "synthetic_retail_a"
VERSION = "1.0.0"
UTC = timezone.utc


def calendar(start: date, end: date, as_of: date | None) -> list[CalendarEventRecord]:
    """Liquidación anual de la primera semana de febrero, anunciada el 15 de noviembre del año anterior."""
    events = []
    for year in range(start.year, end.year + 1):
        starts, ends = date(year, 2, 1), date(year, 2, 7)
        known = datetime(year - 1, 11, 15, tzinfo=UTC)
        if ends < start or starts > end or (as_of is not None and known.date() > as_of):
            continue
        events.append(CalendarEventRecord(scope="sector", scope_ref=f"sector:{CODE}", code="liquidacion_febrero",
                                          name="Semana de liquidación (sintético)", category="sector_event",
                                          starts_on=starts, ends_on=ends, status="confirmed", known_at=known, source=CODE))
    return events


def foot_traffic(dates: pd.DatetimeIndex, as_of: date, observations: Mapping[date, float] | None = None) -> pd.DataFrame:
    """Índice de afluencia (base 100) determinista: +25 % en fin de semana y +10 % en diciembre. No usa observaciones."""
    base = pd.Series(100.0, index=dates)
    base = base * (1 + 0.25 * (dates.weekday >= 5)) * (1 + 0.10 * (dates.month == 12))
    return pd.DataFrame({"foot_traffic_index": base.astype(float)}, index=dates)


def constraints(as_of: date) -> list[ConstraintRecord]:
    return [ConstraintRecord(name="max_units_per_display", kind="storage_limit", value=48.0, unit="unit", applies_to="all",
                             known_at=datetime(2025, 1, 1, tzinfo=UTC))]


PROFILE = SectorProfile(
    code=CODE,
    version=VERSION,
    description="Comercio minorista ficticio (prueba de los puntos de extensión).",
    synthetic=True,
    overrides={
        "demand": {"review_period_days": 3, "default_lead_time_days": 5},
        "supply": {"service_level": 0.97, "review_period_days": 3},
    },
    providers=(
        ExtensionProvider(kind="calendar", name="retail_a_calendar", version="1.0.0", inputs=("start", "end", "as_of"), outputs=("calendar_events",),
                          units={"calendar_events": "event"}, temporal_availability="known_at", fn=calendar),
        ExtensionProvider(kind="feature", name="retail_a_foot_traffic", version="1.0.0", inputs=("dates", "as_of"), outputs=("foot_traffic_index",),
                          units={"foot_traffic_index": "index_100"}, temporal_availability="deterministic", fn=foot_traffic),
        ExtensionProvider(kind="constraint", name="retail_a_display_limit", version="1.0.0", inputs=("as_of",), outputs=("max_units_per_display",),
                          units={"max_units_per_display": "unit"}, temporal_availability="known_at", fn=constraints),
    ),
)
