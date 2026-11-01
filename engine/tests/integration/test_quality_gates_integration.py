"""W2-C · integración con PostgreSQL: señales as-of de calidad, informe por ejecución (scope `run`), gates con efectos reales y
sobrescritura por empresa en demanda, Customer y abastecimiento. Mundos FICTICIOS; cada prueba usa empresas propias.

Las señales de la base (informes de ingestión, compradores identificados/genéricos, alias, desfase de ingestión) se prueban con la regla
de visibilidad de W1: nada posterior al corte entra (`known_from < M(C+1)`; informes, alias y documentos creados después tampoco).
"""

from __future__ import annotations

import json
from contextlib import contextmanager
from datetime import date, timedelta

import pytest
from _supply_db import add_branch, add_core_run, add_forecast, add_inventory, add_offer, add_product, add_supplier, new_tenant
from asof_helpers import World, add_document, local, make_world
from customer_fixtures import create_core_run, load_world
from loader import create_run, load_company
from sqlalchemy import text

from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.pipeline.core import InsufficientDataError
from predictily_engine.pipeline.forecast_run import execute_forecast_run
from predictily_engine.pipeline.schemas import ExecuteRequest
from predictily_engine.platform.config import CORE_DEFAULTS
from predictily_engine.quality.assessment import QualityAssessment, QualityPolicy
from predictily_engine.quality.gates import evaluate
from predictily_engine.quality.persistence import insert_run_quality_report
from predictily_engine.quality.signals import collect_signals
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration
D0 = date(2026, 3, 2)
POLICY = CORE_DEFAULTS["quality_gates"]
GENERIC = "9999999999999"
AUTH = {"Authorization": "Bearer test-token"}


def iso(offset: int) -> str:
    return (D0 + timedelta(days=offset)).isoformat()


@contextmanager
def rolled_back(db_engine):
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


def signals_at(conn, w: World, offset: int, knowledge: int | None = None) -> dict:
    return collect_signals(conn, w.tenant_id, D0 + timedelta(days=offset), w.timezone, POLICY, [GENERIC],
                           knowledge=None if knowledge is None else D0 + timedelta(days=knowledge))


def insert_report(conn, w: World, *, scope="import", dataset: str | None = "sales", total=0, error=0, created, job: int | None = None,
                  sync: int | None = None, extra: dict | None = None) -> None:
    conn.execute(text(
        "INSERT INTO data_quality_reports (tenant_id, import_job_id, source_sync_id, scope, score, summary, issues, dataset, created_at) "
        "VALUES (:t, :j, :sy, :s, 80, CAST(:sm AS jsonb), '[]'::jsonb, :d, :c)"),
        {"t": w.tenant_id, "j": job, "sy": sync, "s": scope, "sm": json.dumps({"rows_total": total, "rows_error": error, **(extra or {})}),
         "d": dataset, "c": created})


def insert_job(conn, w: World, entity: str = "sales", status: str = "completed", options: dict | None = None) -> int:
    return int(conn.execute(text(
        "INSERT INTO import_jobs (tenant_id, entity_type, original_filename, stored_path, file_hash, file_size, file_format, status, options, "
        "created_at, updated_at) VALUES (:t, :e, 'ventas.csv', 'tenants/x/imports/ventas.csv', :h, 10, 'csv', :s, CAST(:o AS jsonb), now(), now()) RETURNING id"),
        {"t": w.tenant_id, "e": entity, "h": "a" * 64, "s": status, "o": json.dumps(options) if options is not None else None}).scalar_one())


def insert_sync(conn, w: World, status: str = "completed") -> int:
    source = int(conn.execute(text("INSERT INTO data_sources (tenant_id, type, name, created_at, updated_at) VALUES (:t, 'sql', 'ERP (ficticio)', now(), now()) "
                                   "RETURNING id"), {"t": w.tenant_id}).scalar_one())
    return int(conn.execute(text("INSERT INTO source_syncs (tenant_id, data_source_id, entity, mode, status, created_at, updated_at) "
                                 "VALUES (:t, :d, 'sales', 'incremental', :s, now(), now()) RETURNING id"), {"t": w.tenant_id, "d": source, "s": status}).scalar_one())


# ───────────────────────────── señales de la base, as-of ─────────────────────────────

def test_invalid_pct_reads_only_ingestion_reports_of_the_dataset_inside_the_window_and_known_at_the_cutoff(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        job = insert_job(conn, w, "sales")
        insert_report(conn, w, total=1000, error=50, created=local(iso(60), 12), job=insert_job(conn, w))                  # incluido
        insert_report(conn, w, dataset=None, total=500, error=100, created=local(iso(90), 12), job=job)                   # incluido: el dataset sale de la importación
        insert_report(conn, w, dataset="products", total=400, error=200, created=local(iso(70), 12), job=insert_job(conn, w, "products"))  # otro dataset
        insert_report(conn, w, scope="forecasting_readiness", dataset=None, total=99, error=99, created=local(iso(70), 12))  # no es de ingestión
        insert_report(conn, w, scope="run", total=99, error=99, created=local(iso(70), 12))                               # tampoco: es de una ejecución del motor
        insert_report(conn, w, total=1000, error=1000, created=local(iso(101), 12), job=insert_job(conn, w))             # creado DESPUÉS del corte
        insert_report(conn, w, total=1000, error=1000, created=local(iso(-200), 12), job=insert_job(conn, w))             # fuera de la ventana de 180 días
        at_cutoff = signals_at(conn, w, 100)["ingestion"]
        assert at_cutoff["available"] and at_cutoff["dataset"] == "sales" and at_cutoff["lookback_days"] == 180
        assert (at_cutoff["reports"], at_cutoff["rows_total"], at_cutoff["rows_error"], at_cutoff["invalid_pct"]) == (2, 1500, 150, 0.1)
        later = signals_at(conn, w, 101)["ingestion"]
        assert (later["reports"], later["rows_total"], later["rows_error"]) == (3, 2500, 1150), "al día siguiente el informe ya era conocido"
        assert signals_at(conn, w, 100, knowledge=105)["ingestion"]["reports"] == 3, "conocimiento de una ejecución vigente (hoy > corte)"


def test_invalid_pct_counts_only_loads_that_entered_silver_and_not_reprocesses(db_engine):
    """Validar, rechazar o fallar también escriben informe pero NO escriben datos; los reprocesos repiten filas ya contadas."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        counted = [(1000, 50, insert_job(conn, w), None, None), (500, 100, insert_job(conn, w), insert_sync(conn, w, "completed"), None)]
        for total, error, job, sync, _ in counted:
            insert_report(conn, w, total=total, error=error, created=local(iso(60), 12), job=job, sync=sync)
        insert_report(conn, w, scope="sales_batch", total=300, error=30, created=local(iso(70), 12), extra={"created": 4, "updated": 0, "unchanged": 0})
        # NO cuentan:
        for status in ("validated", "rejected", "failed", "uploaded"):
            insert_report(conn, w, total=10000, error=10000, created=local(iso(61), 12), job=insert_job(conn, w, status=status))
        insert_report(conn, w, total=10000, error=10000, created=local(iso(62), 12), job=insert_job(conn, w), sync=insert_sync(conn, w, "failed"))
        insert_report(conn, w, total=10000, error=10000, created=local(iso(62), 12), job=insert_job(conn, w), sync=insert_sync(conn, w, "needs_review"))
        insert_report(conn, w, total=1000, error=1000, created=local(iso(63), 12), job=insert_job(conn, w, options={"reprocess_of_bronze_object_id": 7}))
        insert_report(conn, w, total=1000, error=1000, created=local(iso(63), 12), job=insert_job(conn, w), extra={"reprocess": True})
        insert_report(conn, w, total=1000, error=1000, created=local(iso(63), 12), job=insert_job(conn, w), extra={"reprocess_of_bronze_object_id": 9})  # marca de W2-A
        insert_report(conn, w, dataset="sales_reprocess", total=1000, error=1000, created=local(iso(63), 12), job=insert_job(conn, w))             # dataset de W2-A
        insert_report(conn, w, scope="sales_batch", total=1000, error=1000, created=local(iso(64), 12), extra={"created": 0, "updated": 0, "unchanged": 0})  # lote que no escribió nada
        insert_report(conn, w, scope="import", total=None, error=None, created=local(iso(64), 12), job=insert_job(conn, w))   # BLOCKER estructural sin rows_total
        ing = signals_at(conn, w, 100)["ingestion"]
        assert (ing["reports"], ing["rows_total"], ing["rows_error"]) == (3, 1800, 180) and ing["invalid_pct"] == 0.1


def test_one_clean_confirmed_load_and_three_failed_attempts_do_not_block_the_forecast(db_engine):
    """Caso del revisor: 1 carga confirmada limpia + 3 intentos con el mapeo equivocado (validado, rechazado, fallido) = 0 % inválido, sin BLOCK."""
    from predictily_engine.quality.gates import evaluate

    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        insert_report(conn, w, total=10000, error=0, created=local(iso(50), 10), job=insert_job(conn, w, status="completed"))
        for status in ("rejected", "failed", "validated"):
            insert_report(conn, w, total=10000, error=10000, created=local(iso(55), 10), job=insert_job(conn, w, status=status))
        ing = signals_at(conn, w, 100)["ingestion"]
        assert (ing["reports"], ing["rows_total"], ing["invalid_pct"]) == (1, 10000, 0.0)
        gate = evaluate("demand", {"invalid": ing, "coverage": {"calendar_days": 100, "source_coverage_pct": 1.0}}, POLICY)
        assert gate.outcome == "PASS" and gate.codes == []


def test_identity_shares_split_identified_generic_anonymous_and_deleted_buyers_as_of_the_cutoff(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_products=1, n_customers=3, generic=True)
        add_document(conn, w, number="D-1", sale_date=iso(80), lines=[(0, 10, 1.0)], customer=0)       # identificado
        add_document(conn, w, number="D-2", sale_date=iso(81), lines=[(0, 5, 1.0)], customer=1)        # identificado (se dará de baja DESPUÉS del corte)
        add_document(conn, w, number="D-3", sale_date=iso(82), lines=[(0, 20, 1.0)], customer=-1)      # comprador genérico
        add_document(conn, w, number="D-4", sale_date=iso(83), lines=[(0, 15, 1.0)], customer=None)    # anónimo
        add_document(conn, w, number="D-5", sale_date=iso(84), lines=[(0, 10, 1.0)], customer=2)       # dado de baja ANTES del corte
        add_document(conn, w, number="D-6", sale_date=iso(-100), lines=[(0, 1000, 1.0)], customer=0)   # fuera de la ventana de 180 días
        add_document(conn, w, number="D-7", sale_date=iso(110), lines=[(0, 500, 1.0)], customer=0)     # posterior al corte
        add_document(conn, w, number="D-8", sale_date=iso(90), lines=[(0, 40, 1.0)], customer=0, mode="incremental", known_from=local(iso(105), 10))
        conn.execute(text("UPDATE customers SET deleted_at = :d WHERE id = :c AND tenant_id = :t"),
                     {"d": local(iso(50), 9), "c": w.customer_ids[2], "t": w.tenant_id})
        conn.execute(text("UPDATE customers SET deleted_at = :d WHERE id = :c AND tenant_id = :t"),
                     {"d": local(iso(120), 9), "c": w.customer_ids[1], "t": w.tenant_id})
        i = signals_at(conn, w, 100)["identity"]
        assert i["available"] and i["window_days"] == 180 and i["lines"] == 5 and i["units"] == 60.0
        assert i["modeled_share_units"] == pytest.approx(15 / 60, abs=1e-6) and i["anonymous_share_units"] == pytest.approx(0.25, abs=1e-6)
        assert i["generic_share_units"] == pytest.approx(20 / 60, abs=1e-6) and i["deleted_customer_share_units"] == pytest.approx(10 / 60, abs=1e-6)
        assert i["lines_identified"] == 4 and i["lines_deleted_customer"] == 1 and i["deleted_customer_share_lines"] == 0.25
        # 30 días después ya existen D-7 y D-8, el comprador 1 está dado de baja y la ventana se corrió: nada se ve «hacia atrás»
        j = signals_at(conn, w, 130)["identity"]
        assert j["units"] == 600.0 and j["modeled_share_units"] == pytest.approx(550 / 600, abs=1e-6)
        assert j["deleted_customer_share_units"] == pytest.approx(15 / 600, abs=1e-6)


def test_id_stability_counts_recent_alias_changes_and_new_codes_only_when_the_history_allows_it(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_products=3, n_customers=3)
        for n, (sale, product, customer) in enumerate([(0, 0, 0), (195, 0, 0), (5, 1, 1), (190, 2, 1)]):
            add_document(conn, w, number=f"S-{n}", sale_date=iso(sale), lines=[(product, 1, 1.0)], customer=customer)

        def alias(table: str, entity_index: int, kind: str, valid_from: int | None, created: int) -> None:
            col = "product_id" if table == "product_aliases" else "customer_id"
            ids = w.product_ids if table == "product_aliases" else w.customer_ids
            conn.execute(text(
                f"INSERT INTO {table} (tenant_id, {col}, source_code, kind, valid_from, created_at, updated_at) "
                "VALUES (:t, :e, :c, :k, :v, :ca, now())"),
                {"t": w.tenant_id, "e": ids[entity_index], "c": f"X-{table}-{entity_index}-{kind}-{created}", "k": kind,
                 "v": None if valid_from is None else D0 + timedelta(days=valid_from), "ca": local(iso(created), 8)})

        alias("product_aliases", 0, "code_change", 150, 150)      # reciente (dentro de los 90 días): cuenta
        alias("product_aliases", 1, "code_change", 10, 10)        # antiguo: no cuenta
        alias("product_aliases", 1, "alias", 190, 190)            # un alias simple no es un cambio de código
        alias("product_aliases", 2, "merge", None, 230)           # creado DESPUÉS del corte (día 199): no es conocido
        alias("customer_aliases", 0, "merge", 160, 160)           # reciente: cuenta
        alias("customer_aliases", 1, "code_change", 230, 170)     # conocido antes del corte pero con vigencia POSTERIOR a él: fuera del intervalo (corte-90, corte]
        s = signals_at(conn, w, 199)["id_stability"]
        assert s["available"] and s["history_days"] == 200 and s["new_code_evaluable"] is True
        assert (s["products_with_sales"], s["products_with_change_alias"]) == (3, 1) and s["product_change_share"] == pytest.approx(1 / 3, abs=1e-6)
        assert (s["customers_with_sales"], s["customers_with_change_alias"]) == (2, 1) and s["customer_change_share"] == 0.5
        assert s["new_product_codes"] == 1 and s["new_product_code_share"] == pytest.approx(1 / 3, abs=1e-6)
        early = signals_at(conn, w, 60)["id_stability"]
        assert early["new_code_evaluable"] is False and early["new_product_code_share"] is None, "con historia menor que la ventana todo código es nuevo"
        # «código nuevo» exige al menos DOS ventanas de historia (2 × 90 = 180 días): 179 no basta, 180 sí
        just_short = signals_at(conn, w, 178)["id_stability"]
        assert just_short["history_days"] == 179 and just_short["new_code_evaluable"] is False and just_short["new_product_code_share"] is None
        exactly = signals_at(conn, w, 179)["id_stability"]
        assert exactly["history_days"] == 180 and exactly["new_code_evaluable"] is True and exactly["new_product_code_share"] is not None


def test_ingestion_lag_distribution_uses_only_incremental_documents_known_at_the_cutoff(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        for n, (received, mode) in enumerate([(30, "incremental"), (32, "incremental"), (40, "incremental"), (30, "backfill"), (140, "incremental")]):
            add_document(conn, w, number=f"L-{n}", sale_date=iso(30), lines=[(0, 1, 1.0)], mode=mode, known_from=local(iso(received), 10))
        lag = signals_at(conn, w, 100)["ingestion_lag"]
        assert lag["available"] and lag["documents"] == 3, "el backfill y el documento recibido después del corte no cuentan"
        assert (lag["p50_days"], lag["max_days"]) == (2.0, 10) and lag["p90_days"] == pytest.approx(8.4)
        assert lag["share_gt_1d"] == pytest.approx(2 / 3, abs=1e-6) and lag["share_gt_7d"] == pytest.approx(1 / 3, abs=1e-6)
        assert signals_at(conn, w, 200)["ingestion_lag"]["documents"] == 0, "fuera de la ventana de 90 días"
        assert signals_at(conn, w, 200)["ingestion_lag"]["p90_days"] is None


def test_signals_at_a_cutoff_do_not_change_when_data_arrives_after_it(db_engine):
    """Nada posterior al corte se lee: se agregan informes, documentos, alias y bajas posteriores y las señales son idénticas."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_products=2, n_customers=3, generic=True)
        add_document(conn, w, number="B-1", sale_date=iso(50), lines=[(0, 7, 1.0)], customer=0, mode="incremental", known_from=local(iso(52), 9))
        add_document(conn, w, number="B-2", sale_date=iso(60), lines=[(1, 3, 1.0)], customer=-1)
        insert_report(conn, w, total=100, error=10, created=local(iso(70), 9), job=insert_job(conn, w))
        before = json.dumps(signals_at(conn, w, 100), sort_keys=True, default=str)
        assert signals_at(conn, w, 100)["ingestion"]["reports"] == 1
        # llega DESPUÉS del corte (día 100): todo lo siguiente es invisible a las señales de ese corte
        add_document(conn, w, number="A-1", sale_date=iso(100), lines=[(0, 99, 1.0)], customer=1, mode="incremental", known_from=local(iso(101), 3))
        add_document(conn, w, number="A-2", sale_date=iso(40), lines=[(0, 99, 1.0)], customer=1, mode="incremental", known_from=local(iso(103), 9))
        add_document(conn, w, number="A-3", sale_date=iso(120), lines=[(1, 99, 1.0)], customer=0)
        insert_report(conn, w, total=1000, error=900, created=local(iso(101), 1), job=insert_job(conn, w))
        conn.execute(text("INSERT INTO product_aliases (tenant_id, product_id, source_code, kind, valid_from, created_at, updated_at) "
                          "VALUES (:t, :p, 'NUEVO', 'code_change', :v, :c, now())"),
                     {"t": w.tenant_id, "p": w.product_ids[0], "v": D0 + timedelta(days=95), "c": local(iso(102), 9)})
        conn.execute(text("UPDATE customers SET deleted_at = :d WHERE id = :c AND tenant_id = :t"),
                     {"d": local(iso(101), 9), "c": w.customer_ids[0], "t": w.tenant_id})
        after = json.dumps(signals_at(conn, w, 100), sort_keys=True, default=str)
        assert before == after


def test_a_failing_signal_is_marked_failed_and_does_not_poison_the_transaction(db_engine):
    """La señal que falla se marca `failed` (no es «sin datos»), el gate avisa con `dq_signal_unavailable` y la transacción sigue siendo útil."""
    from predictily_engine.quality.gates import evaluate

    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        conn.execute(text("ALTER TABLE product_aliases RENAME TO product_aliases_roto"))
        s = signals_at(conn, w, 10)
        assert s["id_stability"]["available"] is False and s["id_stability"]["failed"] is True
        assert s["id_stability"]["reason"] == "alias_tables_missing" or s["id_stability"]["reason"].startswith("query_failed")
        assert s["identity"]["available"] is True and conn.execute(text("SELECT 1")).scalar() == 1, "la transacción sigue utilizable"
        gate = evaluate("customer", {"id_stability": s["id_stability"], "customer_history": {"buyers": 50}, "coverage": {"identified_history_days": 400}}, POLICY)
        assert gate.codes == ["dq_signal_unavailable"] and gate.outcome == "WARN"
        assert {n["reason"] for n in gate.not_evaluated if n["code"].startswith("dq_customer_") and "ids_unstable" in n["code"]} == {"signal_failed"}


# ───────────────────────────── demanda de punta a punta ─────────────────────────────

def short_history_tenant(db_engine, name: str, days: int, settings: dict | None = None) -> World:
    with db_engine.begin() as conn:
        w = make_world(conn, n_branches=1, n_products=3, name=name)
        if settings is not None:
            conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"), {"s": json.dumps(settings), "t": w.tenant_id})
        for d in range(days):
            add_document(conn, w, number=f"H-{d}", sale_date=iso(d), lines=[(0, 3 + d % 3, 10.0), (1, 2, 8.0), (2, 4, 5.0)], unit_cost=4.0)
        for p in w.product_ids:
            conn.execute(text("INSERT INTO inventory_snapshots (tenant_id, branch_id, product_id, snapshot_date, on_hand, reserved, in_transit, created_at, updated_at) "
                              "VALUES (:t, :b, :p, :d, 100, 0, 0, now(), now())"), {"t": w.tenant_id, "b": w.branch_ids[0], "p": p, "d": D0 + timedelta(days=days - 1)})
    return w


def new_run(db_engine, tenant_id: int) -> tuple[int, int]:
    with db_engine.begin() as conn:
        return create_run(conn, tenant_id)


def q(db_engine, sql: str, **params):
    with db_engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


def test_a_block_gate_stops_the_forecast_with_a_stable_code_and_leaves_its_report(db_engine, client):
    w = short_history_tenant(db_engine, "Empresa BLOCK (ficticia)", days=14)
    run_id, job_id = new_run(db_engine, w.tenant_id)
    res = client.post(f"/v1/forecast-runs/{run_id}/execute", json={"tenant_id": w.tenant_id, "training_job_id": job_id, "horizons": [7]}, headers=AUTH)
    assert res.status_code == 422 and res.json()["code"] == "insufficient_data"
    body = res.json()["details"]
    assert body["code"] == "dq_gate_block" and body["reason_codes"] == ["dq_demand_insufficient_history"] and body["gate"]["outcome"] == "BLOCK"
    run = q(db_engine, "SELECT status, error_message, summary FROM forecast_runs WHERE id = :r AND tenant_id = :t", r=run_id, t=w.tenant_id)[0]
    assert run["status"] == "failed" and "dq_demand_insufficient_history" in run["error_message"]
    assert run["summary"]["quality_status"] == "insufficient_data" and run["summary"]["quality_gate"]["outcome"] == "BLOCK"
    assert [x["code"] for x in run["summary"]["limitations"]] == ["dq_demand_insufficient_history"]
    rep = q(db_engine, "SELECT * FROM data_quality_reports WHERE forecast_run_id = :r AND scope = 'run'", r=run_id)
    assert len(rep) == 1 and rep[0]["tenant_id"] == w.tenant_id and rep[0]["dataset"] == "sales" and rep[0]["core_run_id"] is None
    assert rep[0]["gate_results"]["demand"]["outcome"] == "BLOCK" and rep[0]["metrics"]["coverage"]["calendar_days"] == 14
    assert run["summary"]["quality_gate"]["report_id"] == rep[0]["id"] and rep[0]["as_of"] == D0 + timedelta(days=13)
    assert q(db_engine, "SELECT COUNT(*) AS n FROM forecast_values WHERE forecast_run_id = :r", r=run_id)[0]["n"] == 0, "no se pronosticó nada"
    job = q(db_engine, "SELECT status FROM training_jobs WHERE id = :j", j=job_id)[0]
    assert job["status"] == "failed"


def test_the_tenant_override_changes_the_gate_and_the_other_tenant_keeps_the_core_threshold(db_engine):
    strict = short_history_tenant(db_engine, "Empresa por defecto (ficticia)", days=24)
    lenient = short_history_tenant(db_engine, "Empresa con override (ficticia)", days=24, settings={"cores": {"quality_gates": {"demand_min_history_days": 10}}})
    rid_strict, jid_strict = new_run(db_engine, strict.tenant_id)
    with pytest.raises(InsufficientDataError):
        execute_forecast_run(rid_strict, ExecuteRequest(tenant_id=strict.tenant_id, training_job_id=jid_strict, horizons=[7]))
    rid_len, jid_len = new_run(db_engine, lenient.tenant_id)
    out = execute_forecast_run(rid_len, ExecuteRequest(tenant_id=lenient.tenant_id, training_job_id=jid_len, horizons=[7]))
    assert out["status"] == "completed"
    gate = q(db_engine, "SELECT tenant_id, gate_results FROM data_quality_reports WHERE forecast_run_id = :r AND scope = 'run'", r=rid_len)
    assert len(gate) == 1 and gate[0]["tenant_id"] == lenient.tenant_id
    g = gate[0]["gate_results"]["demand"]
    assert g["outcome"] in ("PASS", "WARN") and g["thresholds_overridden"] == ["demand_min_history_days"]
    # aislamiento: el informe de cada ejecución es de su empresa y la otra empresa no ve el de esta
    assert q(db_engine, "SELECT COUNT(*) AS n FROM data_quality_reports WHERE scope = 'run' AND tenant_id = :t", t=strict.tenant_id)[0]["n"] == 1
    assert q(db_engine, "SELECT COUNT(*) AS n FROM data_quality_reports WHERE scope = 'run' AND tenant_id = :t AND forecast_run_id = :r",
             t=strict.tenant_id, r=rid_len)[0]["n"] == 0


def test_an_invalid_tenant_override_fails_the_run_with_a_clear_configuration_error(db_engine):
    from predictily_engine.pipeline.errors import InvalidConfigurationError

    w = short_history_tenant(db_engine, "Empresa override inválido (ficticia)", days=24, settings={"cores": {"quality_gates": {"umbral_inventado": 3}}})
    run_id, job_id = new_run(db_engine, w.tenant_id)
    with pytest.raises(InvalidConfigurationError, match="quality_gates"):
        execute_forecast_run(run_id, ExecuteRequest(tenant_id=w.tenant_id, training_job_id=job_id, horizons=[7]))
    assert q(db_engine, "SELECT status FROM forecast_runs WHERE id = :r", r=run_id)[0]["status"] == "failed"


@pytest.fixture(scope="module")
def synthetic_company(db_engine):
    company = generate_company("ferreteria_andina", seed=42, n_products=4, start="2026-01-01", end="2026-07-31")
    with db_engine.begin() as conn:
        return load_company(conn, company, "W2-C Sintética (ficticia)")


def test_a_normal_forecast_persists_its_report_with_the_right_tenant_and_the_defaults_do_not_block(db_engine, synthetic_company):
    tenant = synthetic_company
    run_id, job_id = new_run(db_engine, tenant)
    out = execute_forecast_run(run_id, ExecuteRequest(tenant_id=tenant, training_job_id=job_id, horizons=[7, 30]))
    assert out["status"] == "completed"
    rows = q(db_engine, "SELECT * FROM data_quality_reports WHERE forecast_run_id = :r AND scope = 'run'", r=run_id)
    assert len(rows) == 1
    r = rows[0]
    assert r["tenant_id"] == tenant and r["dataset"] == "sales" and r["core_run_id"] is None and r["score"] is None
    assert len(r["rules_version"]) == 64 and r["as_of"] is not None
    assert r["gate_results"]["demand"]["outcome"] in ("PASS", "WARN") and r["gate_results"]["demand"]["disabled_features"] == []
    m = r["metrics"]
    assert m["schema"] == "dq-metrics-v1" and m["coverage"]["source_coverage_pct"] > 0.9 and m["price"]["coverage_share"] == 1.0
    assert m["invalid"]["available"] is True and m["invalid"]["reports"] == 0 and m["invalid"]["invalid_pct"] is None
    assert m["id_stability"]["available"] and m["identity"]["available"] and m["freshness"]["ingestion_lag"]["available"]
    run = q(db_engine, "SELECT summary, evaluation_manifest FROM forecast_runs WHERE id = :r", r=run_id)[0]
    assert run["summary"]["quality_gate"]["report_id"] == r["id"] and run["summary"]["run_quality_report_id"] == r["id"]
    assert run["evaluation_manifest"]["data_quality_gate"]["outcome"] == r["gate_results"]["demand"]["outcome"]
    assert run["evaluation_manifest"]["data_quality_gate"]["rules_version"] == r["rules_version"]
    # el informe de preparación de siempre sigue escribiéndose, aparte
    assert q(db_engine, "SELECT COUNT(*) AS n FROM data_quality_reports WHERE forecast_run_id = :r AND scope = 'forecasting_readiness'", r=run_id)[0]["n"] == 1
    # reintentar la persistencia de la misma ejecución reemplaza la fila, no la duplica
    again = QualityAssessment("demand", "sales", r["as_of"], m, evaluate("demand", m, POLICY), QualityPolicy.resolve({}), True)
    with db_engine.begin() as conn:
        insert_run_quality_report(conn, tenant, again, forecast_run_id=run_id)
        insert_run_quality_report(conn, tenant, again, forecast_run_id=run_id)
    assert q(db_engine, "SELECT COUNT(*) AS n FROM data_quality_reports WHERE forecast_run_id = :r AND scope = 'run'", r=run_id)[0]["n"] == 1


# ───────────────────────────── Customer ─────────────────────────────

@pytest.fixture(scope="module")
def customer_tenants(db_engine):
    world = generate_world(seed=5, n_customers=70, n_products=5, n_days=280, mean_interval_range=(15, 120))
    with db_engine.begin() as conn:
        pass_ = load_world(conn, world, "W2-C Customer normal (ficticia)")
        degrade = load_world(conn, world, "W2-C Customer DEGRADE (ficticia)",
                             settings={"cores": {"quality_gates": {"customer_min_identified_share": 0.99}}})
        block = load_world(conn, world, "W2-C Customer BLOCK (ficticia)", settings={"cores": {"quality_gates": {"customer_min_buyers": 100000}}})
    return {"pass": pass_["tenant_id"], "degrade": degrade["tenant_id"], "block": block["tenant_id"], "last": world.start + timedelta(days=world.n_days - 1)}


def run_customer(engine, tenant_id: int, operation: str, **kw) -> dict:
    with engine.begin() as conn:
        run_id, job_id = create_core_run(conn, tenant_id, operation, **kw)
    out = execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))
    out["_job_id"] = job_id
    return out


def test_customer_train_and_predict_persist_their_gate_reports_and_the_defaults_do_not_block(db_engine, customer_tenants):
    t = customer_tenants["pass"]
    train = run_customer(db_engine, t, "train")
    assert train["quality_status"] in ("ok", "degraded")
    row = q(db_engine, "SELECT evaluation_manifest, summary, limitations FROM core_runs WHERE id = :r AND tenant_id = :t", r=train["run_id"], t=t)[0]
    gate = row["evaluation_manifest"]["data_quality_gate"]
    assert gate["module"] == "customer" and gate["outcome"] in ("PASS", "WARN") and gate["disabled_features"] == []
    rep = q(db_engine, "SELECT * FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=train["run_id"])
    assert len(rep) == 1 and rep[0]["tenant_id"] == t and rep[0]["dataset"] == "customers" and rep[0]["forecast_run_id"] is None
    assert row["summary"]["quality_gate"]["report_id"] == rep[0]["id"]
    m = rep[0]["metrics"]
    assert m["dataset"] == "customers" and m["customer_history"]["buyers"] > 5 and m["id_stability"]["available"]
    assert m["identity"]["available"] and m["identity"]["basis"] == "sales_line_versions_as_of", "la proporción de identificados se lee as-of de la base"
    predict = run_customer(db_engine, t, "predict", as_of=(customer_tenants["last"] + timedelta(days=1)).isoformat())
    assert predict["quality_status"] in ("ok", "degraded")
    prow = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=predict["run_id"])[0]
    assert prow["summary"]["quality_gate"]["module"] == "customer" and prow["summary"]["quality_gate"]["report_id"]
    assert q(db_engine, "SELECT COUNT(*) AS n FROM customer_predictions WHERE core_run_id = :r", r=predict["run_id"])[0]["n"] > 0


def test_customer_degrades_to_aggregate_no_models_are_trained_and_no_individual_predictions_are_written(db_engine, customer_tenants):
    t = customer_tenants["degrade"]
    train = run_customer(db_engine, t, "train")
    assert train["quality_status"] == "degraded"
    row = q(db_engine, "SELECT evaluation_manifest, summary, limitations, quality_status FROM core_runs WHERE id = :r", r=train["run_id"])[0]
    assert row["evaluation_manifest"]["data_quality_gate"]["degraded_to"] == "aggregate"
    assert set(row["evaluation_manifest"]["data_quality_gate"]["disabled_features"]) == {"customer.individual_predictions", "customer.individual_rankings",
                                                                                        "customer.activity_risk"}
    assert all(h["status"] == "abstained" and h["reason_code"] == "dq_individual_recommendations_disabled"
               for h in row["evaluation_manifest"]["horizons"].values())
    assert any("dq_customer_identified_share_low" in s for s in row["limitations"])
    assert q(db_engine, "SELECT COUNT(*) AS n FROM model_registry WHERE tenant_id = :t AND core = 'customer'", t=t)[0]["n"] == 0, "no se entrenó nada"
    rep = q(db_engine, "SELECT gate_results FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=train["run_id"])[0]
    assert rep["gate_results"]["customer"]["outcome"] == "DEGRADE" and rep["gate_results"]["customer"]["thresholds_overridden"] == ["customer_min_identified_share"]
    pred = run_customer(db_engine, t, "predict", as_of=(customer_tenants["last"] + timedelta(days=1)).isoformat())
    assert pred["quality_status"] == "degraded"
    prow = q(db_engine, "SELECT summary, limitations FROM core_runs WHERE id = :r", r=pred["run_id"])[0]
    assert prow["summary"]["horizons"] == [] and prow["summary"]["quality_gate"]["outcome"] == "DEGRADE"
    assert all(v["status"] == "insufficient_data" and v["reasons"][0]["reason_code"] == "dq_individual_recommendations_disabled"
               for v in prow["summary"]["horizon_support"].values())
    for table in ("customer_predictions", "customer_product_rankings", "customer_activity"):
        assert q(db_engine, f"SELECT COUNT(*) AS n FROM {table} WHERE core_run_id = :r", r=pred["run_id"])[0]["n"] == 0, table


def test_customer_block_ends_the_run_with_insufficient_data_and_a_stable_reason(db_engine, customer_tenants):
    t = customer_tenants["block"]
    out = run_customer(db_engine, t, "predict", as_of=(customer_tenants["last"] + timedelta(days=1)).isoformat())
    assert out["quality_status"] == "insufficient_data"
    row = q(db_engine, "SELECT summary, limitations FROM core_runs WHERE id = :r", r=out["run_id"])[0]
    assert row["summary"]["quality_gate"]["outcome"] == "BLOCK" and "dq_customer_too_few_buyers" in row["summary"]["quality_gate"]["reasons"][0]["code"]
    assert all(v["reasons"][0]["reason_code"] == "dq_gate_block" for v in row["summary"]["horizon_support"].values())
    assert any("dq_customer_too_few_buyers" in s for s in row["limitations"])
    tr = run_customer(db_engine, t, "train")
    assert tr["quality_status"] == "insufficient_data"
    assert q(db_engine, "SELECT COUNT(*) AS n FROM model_registry WHERE tenant_id = :t AND core = 'customer'", t=t)[0]["n"] == 0


# ───────────────────────────── abastecimiento ─────────────────────────────

CUT = date(2026, 10, 26)


def run_plan(db_engine, client, tenant, options=None):
    with db_engine.begin() as conn:
        rid, jid = add_core_run(conn, tenant, "supply", "plan", options)
    r = client.post(f"/v1/supply/runs/{rid}/execute", json={"tenant_id": tenant, "training_job_id": jid}, headers=AUTH)
    assert r.status_code == 200, r.text
    return rid, jid, r.json()


def supply_tenant(db_engine, name: str, *, settings: dict | None = None, inventory: bool = True, lead: int | None = 3, with_offer: bool = True,
                  extra_products: int = 0) -> tuple[int, int, int]:
    """Producto P1 (con oferta y plazo salvo `with_offer=False`) y `extra_products` productos SIN oferta explícita ni plazo; todos con
    existencias, costo de catálogo y pronóstico."""
    with db_engine.begin() as conn:
        t = new_tenant(conn, name, settings=settings)
        b = add_branch(conn, t)
        s = add_supplier(conn, t, "S")
        products = [add_product(conn, t, f"P{i + 1}", "u", price=5.0, cost=2.0) for i in range(1 + extra_products)]
        for n, p in enumerate(products):
            if inventory:
                add_inventory(conn, t, b, p, CUT, on_hand=10.0)
            if with_offer and n == 0:
                add_offer(conn, t, s, p, 2.0, lead=lead)
        add_forecast(conn, t, CUT, {(b, p): [10.0] * 60 for p in products})
    return t, b, products[0]


def test_supply_plan_persists_its_gate_report_and_a_healthy_plan_is_not_degraded(db_engine, client):
    t, _, _ = supply_tenant(db_engine, "W2-C Supply sano (ficticia)")
    rid, _, body = run_plan(db_engine, client, t)
    rep = q(db_engine, "SELECT * FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=rid)
    assert len(rep) == 1 and rep[0]["tenant_id"] == t and rep[0]["dataset"] == "supply" and rep[0]["forecast_run_id"] is None
    g = rep[0]["gate_results"]["supply"]
    assert g["outcome"] in ("PASS", "WARN") and g["disabled_features"] == [] and rep[0]["metrics"]["lead_times"]["coverage_share"] == 1.0
    run = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=rid)[0]
    assert run["summary"]["quality_gate"]["report_id"] == rep[0]["id"] and body["quality_status"] in ("ok", "degraded")


def test_supply_block_is_off_by_default_and_the_tenant_can_turn_it_on(db_engine, client):
    default_t, _, _ = supply_tenant(db_engine, "W2-C Supply sin inventario (ficticia)", inventory=False)
    rid, _, body = run_plan(db_engine, client, default_t)
    assert body["quality_status"] == "insufficient_data"
    assert q(db_engine, "SELECT COUNT(*) AS n FROM supply_plans WHERE core_run_id = :r", r=rid)[0]["n"] == 1, "por defecto el plan explica cada exclusión"
    gate = q(db_engine, "SELECT gate_results FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=rid)[0]["gate_results"]["supply"]
    assert gate["outcome"] == "WARN" and [x["code"] for x in gate["reasons"]] == ["dq_supply_inventory_coverage_low"]

    strict_t, _, _ = supply_tenant(db_engine, "W2-C Supply BLOCK (ficticia)", inventory=False,
                                   settings={"cores": {"quality_gates": {"supply_min_inventory_coverage": 0.05}}})
    rid2, _, body2 = run_plan(db_engine, client, strict_t)
    assert body2["quality_status"] == "insufficient_data"
    assert q(db_engine, "SELECT COUNT(*) AS n FROM supply_plans WHERE core_run_id = :r", r=rid2)[0]["n"] == 0, "BLOCK: no se arma ningún plan"
    row = q(db_engine, "SELECT summary, limitations, quality_status FROM core_runs WHERE id = :r", r=rid2)[0]
    assert row["summary"]["quality_gate"]["outcome"] == "BLOCK" and "dq_supply_no_inventory" in row["summary"]["reason_codes"]
    assert row["summary"]["solver_status"] == "not_solved"
    assert "dq_supply_no_inventory" in row["limitations"][0]
    rep = q(db_engine, "SELECT tenant_id, gate_results FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=rid2)
    assert len(rep) == 1 and rep[0]["tenant_id"] == strict_t and rep[0]["gate_results"]["supply"]["outcome"] == "BLOCK"


def test_supply_degrade_disables_implicit_offers_and_the_plan_says_so(db_engine, client):
    """Dos de tres productos sin ningún plazo explícito (sin oferta ni supplier_lead_times): el plan no los arma con plazos supuestos."""
    off = {"cores": {"quality_gates": {"supply_min_lead_time_coverage": 0.0}}}      # misma empresa con el gate DEGRADE desactivado: referencia
    ref_t, _, _ = supply_tenant(db_engine, "W2-C Supply referencia (ficticia)", settings=off, extra_products=2)
    rid_ref, _, _ = run_plan(db_engine, client, ref_t)
    ref_details = q(db_engine, "SELECT details FROM supply_plans WHERE core_run_id = :r", r=rid_ref)[0]["details"]["report"]
    assert ref_details["nodes_planned"] == 3 and ref_details["excluded_nodes"] == [], "con las ofertas implícitas los tres productos se planifican"

    t, _, _ = supply_tenant(db_engine, "W2-C Supply sin plazos (ficticia)", extra_products=2)
    rid, _, body = run_plan(db_engine, client, t)
    assert body["quality_status"] == "degraded"
    row = q(db_engine, "SELECT limitations, summary FROM core_runs WHERE id = :r", r=rid)[0]
    assert any("dq_supply_lead_times_missing" in s for s in row["limitations"]) and any("Ofertas implícitas deshabilitadas" in s for s in row["limitations"])
    gate = row["summary"]["quality_gate"]
    assert gate["outcome"] == "DEGRADE" and gate["disabled_features"] == ["supply.implicit_offers", "supply.observed_lead_time_policy"]
    assert gate["applied_changes"] and "no_offer" in gate["applied_changes"][0]
    report = q(db_engine, "SELECT details FROM supply_plans WHERE core_run_id = :r", r=rid)[0]["details"]["report"]
    assert report["nodes_planned"] == 1 and [e["reason"] for e in report["excluded_nodes"]] == ["no_offer", "no_offer"], \
        "los productos sin plazo explícito ya no se planifican con un plazo supuesto"
    rep = q(db_engine, "SELECT metrics, gate_results FROM data_quality_reports WHERE core_run_id = :r AND scope = 'run'", r=rid)[0]
    assert rep["metrics"]["lead_times"]["coverage_share"] == pytest.approx(1 / 3, abs=1e-6)
    assert rep["gate_results"]["supply"]["applied_changes"] == gate["applied_changes"]
    snap = q(db_engine, "SELECT components FROM data_snapshots WHERE tenant_id = :t ORDER BY id DESC LIMIT 1", t=t)[0]["components"]
    assert "quality_gate" in snap, "la huella de datos distingue una ejecución degradada de una normal"


# ───────────────────────────── configuración inválida: el mismo 422 en los tres módulos ─────────────────────────────

BAD_GATES = {"cores": {"quality_gates": {"umbral_inventado": 3}}}


def test_an_invalid_quality_gates_override_is_the_same_422_in_customer_and_supply(db_engine, client):
    with db_engine.begin() as conn:
        customer_tenant = load_world(conn, generate_world(seed=3, n_customers=12, n_products=3, n_days=60), "W2-C Customer config inválida (ficticia)",
                                     settings=BAD_GATES)["tenant_id"]
        supply_tenant_id, _, _ = (new_tenant(conn, "W2-C Supply config inválida (ficticia)", settings=BAD_GATES), 0, 0)
    with db_engine.begin() as conn:
        c_run, c_job = create_core_run(conn, customer_tenant, "train")
        s_run, s_job = add_core_run(conn, supply_tenant_id, "supply", "plan")
    c = client.post(f"/v1/customer/runs/{c_run}/execute", json={"tenant_id": customer_tenant, "training_job_id": c_job}, headers=AUTH)
    s = client.post(f"/v1/supply/runs/{s_run}/execute", json={"tenant_id": supply_tenant_id, "training_job_id": s_job}, headers=AUTH)
    for res in (c, s):
        assert res.status_code == 422 and res.json()["code"] == "invalid_configuration" and "quality_gates" in res.json()["message"], res.text
    # el run queda fallido con el motivo de configuración (no como «error interno del motor») y no se escribió nada
    for run_id in (c_run, s_run):
        row = q(db_engine, "SELECT status, error_message FROM core_runs WHERE id = :r", r=run_id)[0]
        assert row["status"] == "failed" and "quality_gates" in row["error_message"] and not row["error_message"].startswith("Error interno"), row
    assert q(db_engine, "SELECT COUNT(*) AS n FROM data_quality_reports WHERE scope = 'run' AND tenant_id IN (:a, :b)", a=customer_tenant, b=supply_tenant_id)[0]["n"] == 0


def test_supply_without_any_explicit_lead_time_degrades_and_a_company_that_planned_with_implicit_offers_gets_an_empty_plan(db_engine, client):
    """Efecto documentado de `dq_supply_lead_times_missing` en una empresa SIN plazos: las ofertas implícitas se deshabilitan y, si no tiene
    ofertas explícitas, el plan queda sin nodos (`insufficient_data`) con el motivo visible; con el umbral en 0 la empresa recupera el plan."""
    strict_t, _, _ = supply_tenant(db_engine, "W2-C Supply sin ningún plazo (ficticia)", with_offer=False)
    rid, _, body = run_plan(db_engine, client, strict_t)
    assert body["quality_status"] == "insufficient_data"
    row = q(db_engine, "SELECT limitations, summary FROM core_runs WHERE id = :r", r=rid)[0]
    assert row["summary"]["quality_gate"]["outcome"] == "DEGRADE" and "supply.implicit_offers" in row["summary"]["quality_gate"]["disabled_features"]
    assert any("dq_supply_lead_times_missing" in x for x in row["limitations"])
    off = {"cores": {"quality_gates": {"supply_min_lead_time_coverage": 0.0}}}
    lenient_t, _, _ = supply_tenant(db_engine, "W2-C Supply sin plazos, gate desactivado (ficticia)", with_offer=False, settings=off)
    rid2, _, body2 = run_plan(db_engine, client, lenient_t)
    assert body2["quality_status"] != "insufficient_data"
    assert q(db_engine, "SELECT details FROM supply_plans WHERE core_run_id = :r", r=rid2)[0]["details"]["report"]["nodes_planned"] == 1
