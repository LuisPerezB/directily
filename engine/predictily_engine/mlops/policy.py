"""Política de serving del motor: espejo en Python de `ModelServingPolicy::basis()` del backend (ADR-V25-07; MODEL_REGISTRY §5.6).

UNA función (`classify`) decide si un modelo del registro puede servirse. Replica, en el mismo orden, las reglas del backend
(`backend/app/Services/Models/ModelServingPolicy.php`); `tests/unit/test_serving_policy_mirror.py` ejecuta los MISMOS casos que
`ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed` y que `ServingPolicyHardeningTest`. Si el backend cambia la política,
cambia aquí y en esa prueba, o el motor y el backend dejarían de coincidir en lo que se sirve.

Base de servicio (`basis`), en este orden:
 1. `not_approved` si el estado es `rejected` o `failed` (el estado manda, aunque conserve `approved_*`).
 2. `approved`     si tiene `approved_at` o `approved_by` y el estado es `approved`, `production` o `retired`.
 3. `not_approved` si ENTRÓ POR LA PUBLICACIÓN: cualquier marca `published_*` o un evento de auditoría con `actor_kind = cli`.
 4. `not_approved` si el estado no es `production`, `validated` ni `retired` (un `candidate` jamás es legado servible).
 5. `not_approved` si una PERSONA lo validó (evento `candidate → validated` con `actor_kind = user`).
 6. `legacy_pipeline` si tiene ORIGEN POSITIVO del pipeline propio: corrida o trabajo enlazados (`forecast_run_id`, `core_run_id`,
    `training_job_id`) o el evento de línea base con `evidence.legacy_policy`. Es el comportamiento vigente de la demo, del rollback y de los E2E.
 7. `not_approved` en cualquier otro caso (la definición de legado es positiva y falla cerrada).

Se sirve solo `approved` o `legacy_pipeline`. Un `not_approved` jamás: publicado sin aprobar, candidato, validado por una persona, sin origen,
rechazado o fallido. La aprobación en sí (segregación de funciones, firma, gate de datos) la hace el backend; el motor la RECONOCE.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from typing import Any

from sqlalchemy import Connection, text

APPROVED = "approved"
LEGACY = "legacy_pipeline"
NOT_APPROVED = "not_approved"
LEGACY_MARK = "legacy_approved_by_migration"

STATUS_REJECTED, STATUS_FAILED = "rejected", "failed"
APPROVED_STATUSES = ("approved", "production", "retired")
LEGACY_STATUSES = ("production", "validated", "retired")
PUBLISHED_FIELDS = ("published_at", "published_by", "published_object_key", "published_env")
RUN_LINK_FIELDS = ("forecast_run_id", "core_run_id", "training_job_id")


@dataclass(frozen=True)
class EventFlags:
    """Lo que dicen los eventos de auditoría del modelo (`model_registry_events`)."""

    cli: bool = False                 # algún evento con actor_kind = cli (entró por la publicación)
    human_validated: bool = False     # evento candidate → validated con actor_kind = user
    legacy_mark: bool = False         # evento de línea base con evidence.legacy_policy


NO_FLAGS = EventFlags()

# Misma agregación que `ModelServingPolicy::flagsSql()`.
EVENT_FLAGS_SQL = (
    "coalesce(bool_or(actor_kind = 'cli'), false) AS cli, "
    "coalesce(bool_or(from_status = 'candidate' AND to_status = 'validated' AND actor_kind = 'user'), false) AS human_validated, "
    "coalesce(bool_or(evidence->>'legacy_policy' IS NOT NULL), false) AS legacy_mark"
)


def present(value: Any) -> bool:
    """«No es null» como en el backend (`!== null`). Una lectura con pandas entrega NaN/NaT/NA donde la base tiene NULL: también es ausente."""
    if value is None:
        return False
    try:
        return not bool(value != value)           # NaN y NaT no son iguales a sí mismos
    except TypeError:                              # pd.NA: su comparación es ambigua
        return False


def has_published_marks(model: Mapping[str, Any]) -> bool:
    return any(present(model.get(field)) for field in PUBLISHED_FIELDS)


def has_run_link(model: Mapping[str, Any]) -> bool:
    return any(present(model.get(field)) for field in RUN_LINK_FIELDS)


def classify(model: Mapping[str, Any], flags: EventFlags = NO_FLAGS) -> str:
    """Base de servicio de un modelo: `approved`, `legacy_pipeline` o `not_approved` (espejo de `ModelServingPolicy::classify`)."""
    status = str(model.get("status") or "")
    if status in (STATUS_REJECTED, STATUS_FAILED):
        return NOT_APPROVED
    if (present(model.get("approved_at")) or present(model.get("approved_by"))) and status in APPROVED_STATUSES:
        return APPROVED
    if has_published_marks(model) or flags.cli or status not in LEGACY_STATUSES or flags.human_validated:
        return NOT_APPROVED
    return LEGACY if (has_run_link(model) or flags.legacy_mark) else NOT_APPROVED


def is_servable(basis: str) -> bool:
    return basis != NOT_APPROVED


def fetch_event_flags(conn: Connection, tenant_id: int, model_ids: Iterable[int]) -> dict[int, EventFlags]:
    """Banderas de eventos de varios modelos con UNA consulta, siempre filtrada por empresa (el backend filtra por id; el motor añade la empresa)."""
    ids = sorted({int(i) for i in model_ids})
    if not ids:
        return {}
    rows = conn.execute(text(f"SELECT model_registry_id, {EVENT_FLAGS_SQL} FROM model_registry_events "
                             "WHERE tenant_id = :t AND model_registry_id = ANY(:ids) GROUP BY model_registry_id"),
                        {"t": int(tenant_id), "ids": ids}).mappings().all()
    return {int(r["model_registry_id"]): EventFlags(bool(r["cli"]), bool(r["human_validated"]), bool(r["legacy_mark"])) for r in rows}


def basis_of_rows(conn: Connection, tenant_id: int, rows: Iterable[Mapping[str, Any]]) -> list[str]:
    """Base de servicio de cada fila (misma longitud y orden que `rows`); las filas deben ser de `tenant_id`."""
    rows = list(rows)
    for row in rows:
        owner = row.get("tenant_id")
        if owner is not None and int(owner) != int(tenant_id):
            raise ValueError("fila del registro de otra empresa: el serving no mezcla empresas")
    flags = fetch_event_flags(conn, tenant_id, [r["id"] for r in rows])
    return [classify(r, flags.get(int(r["id"]), NO_FLAGS)) for r in rows]


# ───────────────────────────── quién puede publicar (revisión W4-B · M4) ─────────────────────────────

# Habilidades por rol de empresa que la CLI comprueba: espejo de `App\Enums\TenantRole::abilities()` del backend (`backend/app/Enums/TenantRole.php`).
# Solo se listan las dos que importan aquí: `forecast.run` (validar un candidato publicado, ejecutar pronósticos) y `models.approve` (aprobar, rechazar y promover).
# `tests/unit/test_role_abilities_mirror.py` calcula el mapa del código PHP y lo compara con este.
ROLE_ABILITIES: dict[str, frozenset[str]] = {
    "viewer": frozenset(),
    "analyst": frozenset({"forecast.run"}),
    "admin": frozenset({"forecast.run", "models.approve"}),
    "owner": frozenset({"forecast.run", "models.approve"}),
}
PUBLISH_ABILITY = "forecast.run"


def role_can(role: str | None, ability: str) -> bool:
    """¿El rol de empresa `role` tiene la habilidad `ability`? Un rol desconocido no tiene ninguna (falla cerrado)."""
    return ability in ROLE_ABILITIES.get(str(role), frozenset())
