"""Lecturas del core de Datos para el motor: calendario con estado de conocimiento y cobertura efectiva de las fuentes.

Mismas reglas que el backend (docs/architecture/v2/P3-datos.md): lo que no se conocía al corte no existe en ese corte,
y fuera de la cobertura declarada/inferida un día sin registros es AUSENTE, no cero. Todas las consultas filtran por empresa.
"""

from __future__ import annotations

from datetime import date, datetime, time, timezone
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

CALENDAR_COLUMNS = ["id", "tenant_id", "scope", "scope_ref", "code", "name", "category", "starts_on", "ends_on", "status", "known_at", "source", "revision"]


def load_calendar_events(conn: Connection, tenant_id: int, start: date, end: date, as_of: date | None = None,
                         include_public: bool = True) -> pd.DataFrame:
    """Eventos que se solapan con [start, end], una fila por evento (la última revisión conocida).

    - `as_of=None`: última revisión de cada evento (visualización).
    - `as_of=fecha`: solo revisiones con `known_at` NO nulo y ≤ fin de ese día. Un evento cuya `known_at` es desconocida
      NO se devuelve (no se puede demostrar que se conocía): su ausencia es explícita, no un cero.
    - Calendario público (tenant_id NULL) incluido si `include_public`; las filas de otras empresas nunca.
    """
    rows = conn.execute(text(
        "SELECT id, tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, source, revision "
        "FROM calendar_events WHERE (tenant_id = :t OR (:pub AND tenant_id IS NULL)) AND ends_on >= :s AND starts_on <= :e "
        "ORDER BY starts_on, code, revision"
    ), {"t": tenant_id, "pub": include_public, "s": start, "e": end}).mappings().all()
    frame = pd.DataFrame(rows, columns=CALENDAR_COLUMNS)
    if frame.empty:
        return frame
    if as_of is not None:
        cutoff = datetime.combine(as_of, time.max, tzinfo=timezone.utc)
        known = frame["known_at"].notna() & (pd.to_datetime(frame["known_at"], utc=True) <= cutoff)
        frame = frame[known]
    frame = frame.assign(_t=frame["tenant_id"].fillna(0), _r=frame["scope_ref"].fillna(""))
    latest = frame.sort_values("revision").groupby(["_t", "scope", "_r", "code", "starts_on"], sort=False).tail(1)
    return latest.drop(columns=["_t", "_r"]).sort_values(["starts_on", "code"]).reset_index(drop=True)


def effective_coverage(conn: Connection, tenant_id: int, entity: str, branch_id: int | None = None) -> dict[str, Any]:
    """Cobertura efectiva de una entidad. Precedencia: declaración del usuario (sin fuente) > mínimo entre fuentes > ninguna."""
    rows = conn.execute(text(
        "SELECT id, data_source_id, branch_id, complete_from, complete_through, basis FROM data_coverage "
        "WHERE tenant_id = :t AND entity = :e"
    ), {"t": tenant_id, "e": entity}).mappings().all()
    declared = [r for r in rows if r["data_source_id"] is None and r["basis"] == "declared" and (r["branch_id"] is None or r["branch_id"] == branch_id)]
    if declared:
        best = sorted(declared, key=lambda r: 0 if r["branch_id"] is None else 1, reverse=True)[0]
        return {"entity": entity, "complete_from": best["complete_from"], "complete_through": best["complete_through"], "rule": "declared_by_user", "basis": "declared"}
    sources = [r for r in rows if r["data_source_id"] is not None and r["complete_through"] is not None and (r["branch_id"] is None or r["branch_id"] == branch_id)]
    if sources:
        froms = [r["complete_from"] for r in sources if r["complete_from"] is not None]
        basis = "declared" if all(r["basis"] == "declared" for r in sources) else "inferred"
        return {"entity": entity, "complete_from": max(froms) if froms else None, "complete_through": min(r["complete_through"] for r in sources),
                "rule": "min_over_sources", "basis": basis}
    return {"entity": entity, "complete_from": None, "complete_through": None, "rule": "none", "basis": None}


def classify_day(coverage: dict[str, Any], day: date, has_records: bool) -> str:
    """`observed` (hay registros), `zero_observed` (sin registros dentro de la cobertura) o `absent` (fuera/sin cobertura)."""
    if has_records:
        return "observed"
    start, through = coverage.get("complete_from"), coverage.get("complete_through")
    if through is not None and day <= through and (start is None or day >= start):
        return "zero_observed"
    return "absent"
