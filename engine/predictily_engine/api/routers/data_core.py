"""Rutas internas del core data_core (etapa 2). Requieren Bearer token (require_token).

- GET /v1/data-core/calendar?tenant_id&start&end[&as_of]   eventos de calendario conocidos al corte (empresa + público)
- GET /v1/data-core/coverage?tenant_id[&entity&branch_id]  cobertura efectiva de las fuentes
"""

from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

from predictily_engine.api.auth import require_token
from predictily_engine.db.session import get_engine
from predictily_engine.extensions.data_core import effective_coverage, load_calendar_events

router = APIRouter(prefix="/v1/data-core", tags=["data_core"], dependencies=[Depends(require_token)])

COVERAGE_ENTITIES = ["sales", "inventory", "purchases", "costs", "promotions", "customers", "commitments", "calendar", "externals"]


@router.get("/calendar")
def calendar(tenant_id: int, start: date, end: date, as_of: date | None = None):
    if end < start:
        return JSONResponse(status_code=422, content={"code": "invalid_range", "message": "end no puede ser anterior a start."})
    with get_engine().connect() as conn:
        frame = load_calendar_events(conn, tenant_id, start, end, as_of)
    records = frame.astype(object).where(frame.notna(), None).to_dict("records")
    for r in records:
        for k in ("starts_on", "ends_on", "known_at"):
            if r.get(k) is not None:
                r[k] = r[k].isoformat()
    return {"tenant_id": tenant_id, "as_of": as_of.isoformat() if as_of else None, "events": records,
            "rule": "latest_revision" if as_of is None else "latest_revision_with_known_at_not_null_and_lte_as_of"}


@router.get("/coverage")
def coverage(tenant_id: int, entity: str | None = None, branch_id: int | None = None):
    entities = [entity] if entity else COVERAGE_ENTITIES
    if entity and entity not in COVERAGE_ENTITIES:
        return JSONResponse(status_code=422, content={"code": "unknown_entity", "message": f"Entidad desconocida: {entity}."})
    with get_engine().connect() as conn:
        data = {e: effective_coverage(conn, tenant_id, e, branch_id) for e in entities}
    for item in data.values():
        for k in ("complete_from", "complete_through"):
            if item[k] is not None:
                item[k] = item[k].isoformat()
    return {"tenant_id": tenant_id, "coverage": data}
