"""Casos de la política de serving (`ModelServingPolicy::basis()` del backend) compartidos por la prueba unitaria del espejo y la de integración con la base.

Son los MISMOS casos que `backend/tests/Feature/Models/ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed` y
`ServingPolicyHardeningTest` (referencias en el campo `backend`). Cada caso: nombre, estado, campos de la fila, eventos de auditoría y base esperada.
Una fila «del pipeline» trae `core_run_id` enlazado, igual que `customerModel()` del backend por defecto.
"""

from __future__ import annotations

from typing import Any

LEGACY, APPROVED, NOT_APPROVED = "legacy_pipeline", "approved", "not_approved"
RUN = {"core_run_id": 7}                      # origen positivo del pipeline propio
PUBLISHER, APPROVER, ANALYST = 11, 12, 13


def ev(to_status: str, kind: str, *, frm: str | None = None, evidence: dict[str, Any] | None = None, actor: int | None = None) -> dict[str, Any]:
    return {"from_status": frm, "to_status": to_status, "actor_kind": kind, "evidence": evidence or {}, "actor_user_id": actor}


LEGACY_MARK = {"legacy_policy": "legacy_approved_by_migration"}

# (nombre, estado, campos extra de la fila, eventos, base esperada, referencia al caso del backend)
CASES: list[tuple[str, str, dict[str, Any], list[dict[str, Any]], str, str]] = [
    # ── ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed ──
    ("production del pipeline con corrida enlazada", "production", RUN, [], LEGACY, "lifecycle: legacy_pipeline por estado production"),
    ("validated del pipeline con corrida enlazada", "validated", RUN, [], LEGACY, "lifecycle: legacy_pipeline por estado validated"),
    ("retired del pipeline con corrida enlazada", "retired", RUN, [], LEGACY, "lifecycle: legacy_pipeline por estado retired"),
    ("validated con solo training_job_id (corrida purgada)", "validated", {"training_job_id": 5}, [], LEGACY, "lifecycle: por el trabajo de entrenamiento enlazado"),
    ("production sin corrida ni marca: sin origen", "production", {}, [], NOT_APPROVED, "lifecycle: sin corrida ni marca no hay origen: falla cerrado"),
    ("production sin corrida pero con la línea base de legado", "production", {}, [ev("production", "system", evidence=LEGACY_MARK)], LEGACY,
     "lifecycle: línea base con la marca de legado"),
    ("candidate del pipeline jamás es legado", "candidate", RUN, [], NOT_APPROVED, "lifecycle: un candidato jamás es legado servible"),
    ("production sin origen", "production", {}, [], NOT_APPROVED, "lifecycle: production sin origen"),
    ("validated sin origen", "validated", {}, [], NOT_APPROVED, "lifecycle: validated sin origen"),
    ("retired sin origen", "retired", {}, [], NOT_APPROVED, "lifecycle: retired sin origen"),
    ("publicado: published_by", "production", {**RUN, "published_by": PUBLISHER}, [], NOT_APPROVED, "lifecycle: publicado sin aprobar (published_by)"),
    ("publicado: published_at", "production", {**RUN, "published_at": "2026-11-01T10:00:00+00:00"}, [], NOT_APPROVED, "lifecycle: publicado (published_at)"),
    ("publicado: published_env", "production", {**RUN, "published_env": "staging"}, [], NOT_APPROVED, "lifecycle: publicado (published_env)"),
    ("publicado: published_object_key", "production", {**RUN, "published_object_key": "tenants/x/models/customer/v1/a.joblib"}, [], NOT_APPROVED,
     "lifecycle: publicado (published_object_key)"),
    ("evento de la CLI sin marcas published_*", "production", RUN, [ev("candidate", "cli", actor=PUBLISHER)], NOT_APPROVED, "lifecycle: evento de la CLI cuenta como publicado"),
    ("validado por una persona", "validated", RUN, [ev("validated", "user", frm="candidate", actor=ANALYST)], NOT_APPROVED, "lifecycle: validado por una PERSONA"),
    ("validado por el sistema", "validated", RUN, [ev("validated", "system", frm="candidate")], LEGACY, "lifecycle: la validación del sistema no cambia nada"),
    ("rejected", "rejected", RUN, [], NOT_APPROVED, "lifecycle: rechazado"),
    ("failed", "failed", RUN, [], NOT_APPROVED, "lifecycle: fallido"),
    ("rejected conservando la huella de aprobación", "rejected", {**RUN, "approved_by": APPROVER, "approved_at": "2026-11-01T10:00:00+00:00"}, [], NOT_APPROVED,
     "lifecycle: el estado manda sobre approved_*"),
    ("approved por approved_by y approved_at (publicado)", "approved", {"approved_by": APPROVER, "approved_at": "2026-11-01T10:00:00+00:00", "published_by": PUBLISHER}, [],
     APPROVED, "lifecycle: aprobado"),
    ("production aprobado y publicado", "production", {"approved_at": "2026-11-01T10:00:00+00:00", "published_at": "2026-11-01T09:00:00+00:00"}, [], APPROVED,
     "lifecycle: aprobado por approved_at aunque el aprobador se haya eliminado"),
    ("aprobado después de una validación humana", "approved", {"approved_at": "2026-11-01T10:00:00+00:00"}, [ev("validated", "user", frm="candidate", actor=ANALYST)],
     APPROVED, "lifecycle: la aprobación gana a la validación humana"),
    ("validated con huella de aprobación (estado sin aprobar)", "validated", {"approved_at": "2026-11-01T10:00:00+00:00", "published_at": "2026-11-01T09:00:00+00:00"}, [],
     NOT_APPROVED, "lifecycle: approved_* sin estado de aprobación no basta"),
    # ── ServingPolicyHardeningTest ──
    ("contrato completo de la CLI: candidate publicado", "candidate", {"published_by": PUBLISHER, "published_env": "staging", "published_at": "2026-11-01T09:00:00+00:00",
                                                                      "published_object_key": "tenants/u/models/customer/v/a.joblib"},
     [ev("candidate", "cli", actor=PUBLISHER)], NOT_APPROVED, "hardening (a): contrato completo exige validar, aprobar y promover"),
    ("la CLI olvidó las marcas pero dejó su evento", "validated", RUN, [ev("candidate", "cli", actor=PUBLISHER)], NOT_APPROVED, "hardening (b): evento cli sin marcas"),
    ("una sola marca: published_env", "retired", {**RUN, "published_env": "staging"}, [], NOT_APPROVED, "hardening (c): published_env"),
    ("una sola marca: published_at", "retired", {**RUN, "published_at": "2026-11-01T09:00:00+00:00"}, [], NOT_APPROVED, "hardening (c): published_at"),
    ("una sola marca: published_by", "retired", {**RUN, "published_by": PUBLISHER}, [], NOT_APPROVED, "hardening (c): published_by"),
    ("publicado sin published_object_key", "validated", {"published_by": PUBLISHER, "published_at": "2026-11-01T09:00:00+00:00", "published_env": "staging"}, [],
     NOT_APPROVED, "hardening (d): sin clave del objeto"),
    ("validado del motor (legado) y luego validado por una persona", "validated", RUN,
     [ev("validated", "system", frm="candidate"), ev("validated", "user", frm="candidate", actor=ANALYST)], NOT_APPROVED,
     "hardening: un evento de validación humana basta"),
    ("huérfano retirado sin corrida ni marca", "retired", {"core_run_id": None}, [], NOT_APPROVED, "hardening: sin origen positivo no es legado"),
    ("huérfano retirado con la marca de línea base", "retired", {"core_run_id": None}, [ev("retired", "system", evidence=LEGACY_MARK)], LEGACY,
     "hardening: con la marca de línea base de la migración sí es legado"),
]
