"""Persistencia: una fila de `data_quality_reports` (scope `run`) por ejecución del motor.

Columnas del contrato (migración `2026_03_03_000100`): `dataset`, `as_of`, `core_run_id` (Customer, abastecimiento) o `forecast_run_id`
(demanda), `metrics`, `gate_results` y `rules_version`; además `summary`/`issues` en la forma heredada para los consumidores existentes.
La fila es independiente de la de preparación (`scope = forecasting_readiness`), que sigue escribiéndose igual. Idempotente: reintentar
una ejecución reemplaza su fila, no la duplica. Todas las sentencias filtran por `tenant_id`.
"""

from __future__ import annotations

import logging

from sqlalchemy import Connection, Engine, text

from predictily_engine.db.writer import jdump
from predictily_engine.quality.assessment import QualityAssessment

log = logging.getLogger(__name__)
SCOPE_RUN = "run"


def insert_run_quality_report(conn: Connection, tenant_id: int, a: QualityAssessment, *, forecast_run_id: int | None = None,
                              core_run_id: int | None = None) -> int:
    """Escribe (reemplazando la anterior de la misma ejecución) el informe de calidad de la ejecución y devuelve su id."""
    if forecast_run_id is not None:
        conn.execute(text("DELETE FROM data_quality_reports WHERE tenant_id = :t AND scope = :s AND forecast_run_id = :r"),
                     {"t": tenant_id, "s": SCOPE_RUN, "r": forecast_run_id})
    if core_run_id is not None:
        conn.execute(text("DELETE FROM data_quality_reports WHERE tenant_id = :t AND scope = :s AND core_run_id = :r"),
                     {"t": tenant_id, "s": SCOPE_RUN, "r": core_run_id})
    return int(conn.execute(text(
        "INSERT INTO data_quality_reports (tenant_id, forecast_run_id, core_run_id, scope, score, summary, issues, dataset, as_of, "
        "metrics, gate_results, rules_version, created_at) VALUES (:t, :f, :c, :scope, NULL, CAST(:summary AS jsonb), "
        "CAST(:issues AS jsonb), :dataset, :as_of, CAST(:metrics AS jsonb), CAST(:gates AS jsonb), :rules, now()) RETURNING id"
    ), {"t": tenant_id, "f": forecast_run_id, "c": core_run_id, "scope": SCOPE_RUN, "summary": jdump(a.report_summary()),
        "issues": jdump(a.issues()), "dataset": a.dataset, "as_of": a.as_of, "metrics": jdump(a.metrics),
        "gates": jdump(a.gate_results()), "rules": a.rules_version}).scalar_one())


def record_blocked_forecast_run(engine: Engine, tenant_id: int, run_id: int, a: QualityAssessment | None) -> int | None:
    """Un pronóstico detenido por un gate BLOCK: deja el informe de calidad de la ejecución (con sus motivos) y marca el resumen del run
    fallido con `quality_status = insufficient_data` y el gate. Sin evaluación (la causa no fue un gate) no hace nada. Nunca lanza: el
    error original que detuvo la ejecución es el que debe llegar al llamador."""
    if a is None or not a.blocked:
        return None
    try:
        with engine.begin() as conn:
            report_id = insert_run_quality_report(conn, tenant_id, a, forecast_run_id=run_id)
            summary = {"quality_status": "insufficient_data", "quality_gate": a.manifest_block(report_id), "limitations": a.limitation_items(),
                       "limitation_codes": a.reason_codes()}
            conn.execute(text("UPDATE forecast_runs SET summary = CAST(:s AS jsonb), updated_at = now() "
                              "WHERE id = :r AND tenant_id = :t AND status = 'failed'"), {"s": jdump(summary), "r": run_id, "t": tenant_id})
        return report_id
    except Exception:  # noqa: BLE001
        log.exception("No se pudo registrar el informe de calidad del run bloqueado run=%s tenant=%s", run_id, tenant_id)
        return None
