"""ADR-V25-23 · la lectura as-of de PostgreSQL frente a la regla literal en zonas con cambio de horario (hallazgo LOW de la revisión).

La base convierte `known_from` / `known_until` a día local con `CAST(x AT TIME ZONE tz AS date)` (ventanas `c_from` / `c_to`); la regla del
ADR-V25-23 habla de instantes: visible si `known_from < M(C+1)` y (`known_until` nulo o `known_until >= M(C+1)`), con `M(C+1)` = medianoche local
del día siguiente al corte. Esta prueba inserta documentos cuyo `known_from` / `known_until` caen justo en la frontera (−1 s, 0, +1 s y ±3 h) alrededor de `M(C+1)` en America/Guayaquil (sin horario de verano), Europe/Madrid (cambio a las 02:00/03:00) y America/Santiago (cambio
A MEDIANOCHE: la medianoche local del cambio de primavera no existe) y comprueba que el resultado de `daily_sales(C)` coincide con la regla
literal calculada con `zoneinfo`, día por día. Todo en una transacción que se revierte; datos ficticios.

Nota sobre las zonas elegidas: PostgreSQL y Python (`zoneinfo`) llevan cada uno su copia de la base de zonas horarias; en zonas cuyas reglas
cambiaron recientemente las copias pueden diferir (la sonda de la revisión lo observó con America/Havana tras el 1-nov-2026). Estas tres zonas
tienen reglas estables.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

import numpy as np
import pytest
from asof_helpers import add_document, amend_document, make_world

from predictily_engine.db.repository import TenantRepository

pytestmark = pytest.mark.integration

# (zona, primer día de los escenarios): la ventana de 12 días incluye el cambio de horario de 2026
ZONES = [
    ("America/Guayaquil", date(2026, 3, 2)),
    ("Europe/Madrid", date(2026, 10, 20)),     # fin del horario de verano: domingo 25-oct-2026 (03:00 → 02:00)
    ("Europe/Madrid", date(2026, 3, 24)),      # inicio: domingo 29-mar-2026 (02:00 → 03:00)
    ("America/Santiago", date(2026, 9, 1)),    # inicio a medianoche: la hora 00:00 del domingo 6-sep-2026 no existe (24:00 → 01:00)
    ("America/Santiago", date(2026, 3, 31)),   # fin: sábado 4-abr-2026 a las 24:00 (retrocede a las 23:00)
]
# Las columnas del contrato (`version_known_from`, `known_from`, `known_until`) son `timestamptz(0)`: PRECISIÓN DE UN SEGUNDO, así que ±1 s es la
# frontera más fina que la base distingue (los instantes a ±1 ns se comprueban sin base de datos en `test_asof_visibility.py`).
DELTAS = [timedelta(seconds=-1), timedelta(0), timedelta(seconds=1), timedelta(hours=3), timedelta(hours=-3)]


@contextmanager
def rolled_back(db_engine):
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


def next_midnight_utc(c: date, tz: str) -> datetime:
    """`M(C+1)` literal: medianoche local del día siguiente (la inexistente se adelanta, como `zoneinfo` con fold = 0), en UTC."""
    nxt = c + timedelta(days=1)
    return datetime(nxt.year, nxt.month, nxt.day, tzinfo=ZoneInfo(tz)).astimezone(timezone.utc)


@pytest.mark.parametrize(("tz", "base"), ZONES, ids=[f"{z}-{b.isoformat()}" for z, b in ZONES])
def test_the_database_asof_read_matches_the_literal_rule_at_the_boundaries_of_dst_and_non_dst_zones(db_engine, tz, base):
    rng = np.random.default_rng(11)
    horizon = 10
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_products=1, timezone=tz)
        docs: list[tuple[date, float, datetime, datetime | None]] = []     # (día del evento, cantidad, known_from, known_until) en UTC
        for i in range(70):
            sale_day = base + timedelta(days=int(rng.integers(0, horizon - 1)))
            first_seen = base + timedelta(days=int(rng.integers(0, horizon)))
            kf = next_midnight_utc(first_seen, tz) + DELTAS[i % len(DELTAS)]
            qty = float(i + 1)
            number = f"T-{i:03d}"
            add_document(conn, w, number=number, sale_date=sale_day.isoformat(), lines=[(0, qty, 10.0)], mode="incremental", known_from=kf)
            ku = None
            if i % 3 == 0:      # anulado después: la versión inicial deja de ser visible desde `ku` (que también cae en la frontera)
                voided_on = base + timedelta(days=int(rng.integers(0, horizon)))
                ku = next_midnight_utc(voided_on, tz) + DELTAS[(i // 3) % len(DELTAS)]
                if ku <= kf:
                    ku = kf + timedelta(hours=7)
                amend_document(conn, w, number, known_from=ku, status="voided")
            docs.append((sale_day, qty, kf, ku))
        repo = TenantRepository(conn, w.tenant_id)
        repo.analytic_reads()

        checked = 0
        for k in range(horizon + 1):
            c = base + timedelta(days=k)
            boundary = next_midnight_utc(c, tz)
            expected: dict[str, float] = {}
            for sale_day, qty, kf, ku in docs:
                if sale_day <= c and kf < boundary and (ku is None or ku >= boundary):
                    expected[sale_day.isoformat()] = expected.get(sale_day.isoformat(), 0.0) + qty
            cells = repo.daily_sales(c)
            got = {r.ds.date().isoformat(): float(r.units) for r in cells.itertuples()} if len(cells) else {}
            assert got == expected, f"{tz}, corte {c}: la lectura de la base difiere de la regla literal"
            checked += 1
        assert checked == horizon + 1
        # las fronteras ejercitadas incluyen el cambio de horario de la zona (el día del cambio dura ≠ 24 h)
        spans = {(next_midnight_utc(base + timedelta(days=k + 1), tz) - next_midnight_utc(base + timedelta(days=k), tz)) for k in range(horizon)}
        if tz != "America/Guayaquil":
            assert len(spans) > 1, "la ventana de pruebas contiene un cambio de horario (un día con duración distinta de 24 h)"
