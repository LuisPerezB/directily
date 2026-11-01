"""ADR-V25-23 / ADR-V25-03 · pruebas adversariales T02 contra PostgreSQL (BD con el esquema migrado por Laravel).

Los documentos se insertan DIRECTAMENTE en `sales`, `sale_items`, `sales_document_revisions` y `sale_item_revisions` según la migración
`2026_03_01_000100`. Cada prueba trabaja dentro de una transacción que se revierte, de modo que no deja datos ni afecta a las demás.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest
from asof_helpers import World, add_document, amend_document, local, make_world
from sqlalchemy import event, text

from predictily_engine.backtest.rolling import BacktestPlan, asof_windows, run_backtest
from predictily_engine.db.repository import TenantRepository
from predictily_engine.demand.coverage import SalesCoverage
from predictily_engine.demand.snapshot import build_snapshot
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.data import load_tenant_data
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel

pytestmark = pytest.mark.integration

D0 = date(2026, 3, 2)  # lunes: base de las fechas de los escenarios


# Consulta agregada que usaba el motor antes del contrato temporal (estado ACTUAL del documento): referencia de equivalencia y de costo.
LEGACY_DAILY_SALES_SQL = """
    SELECT si.branch_id, si.product_id, si.sale_date AS ds, SUM(si.quantity)::float8 AS units, SUM(si.net_amount)::float8 AS net_amount,
           SUM(si.quantity * si.unit_price)::float8 AS gross_amount, SUM(si.discount_amount)::float8 AS discount_amount,
           SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.quantity * si.unit_cost END)::float8 AS cost_amount,
           SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.quantity END)::float8 AS cost_units, COUNT(*) AS lines
    FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
    WHERE si.tenant_id = :tenant_id AND s.status = 'completed' AND s.document_type IN ('invoice', 'sales_note', 'other')
      AND si.is_return = false AND si.sale_date <= :cutoff GROUP BY 1, 2, 3 ORDER BY 1, 2, 3"""


def iso(offset: int) -> str:
    return (D0 + timedelta(days=offset)).isoformat()


@contextmanager
def rolled_back(db_engine):
    """Conexión dentro de una transacción que SIEMPRE se revierte."""
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


def repo_of(conn, w: World) -> TenantRepository:
    repo = TenantRepository(conn, w.tenant_id)
    repo.analytic_reads()
    return repo


def units_at(repo: TenantRepository, cutoff: str, branch: int | None = None) -> float:
    cells = repo.daily_sales(date.fromisoformat(cutoff))
    return float(cells["units"].sum()) if len(cells) else 0.0


def test_incremental_document_loaded_late_is_invisible_before_its_ingestion_and_visible_after(db_engine):
    """Documento del día 0 recibido el día 5 (10:00 locales): los cortes de los días 0 a 4 no lo conocen."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        add_document(conn, w, number="001-0001", sale_date=iso(0), lines=[(0, 3, 10.0)])                                   # carga histórica
        add_document(conn, w, number="001-0002", sale_date=iso(0), lines=[(0, 7, 10.0)], mode="incremental",
                     known_from=local(iso(5), 10))
        repo = repo_of(conn, w)
        assert [units_at(repo, iso(d)) for d in (0, 1, 4, 5, 6, 40)] == [3.0, 3.0, 3.0, 10.0, 10.0, 10.0]
        assert repo.last_sale_date(date.fromisoformat(iso(4))) == date.fromisoformat(iso(0))
        assert repo.last_sale_date() == date.fromisoformat(iso(0)), "sin corte: el estado vigente (lo conocido ahora)"


def test_a_later_voiding_is_counted_before_it_and_excluded_after(db_engine):
    """Factura vigente al corte y anulada después: cuenta en los cortes anteriores a la anulación y desaparece desde ella."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        add_document(conn, w, number="001-0001", sale_date=iso(0), lines=[(0, 3, 10.0)])
        add_document(conn, w, number="001-0002", sale_date=iso(1), lines=[(0, 100, 10.0)])
        amend_document(conn, w, "001-0002", known_from=local(iso(6), 12), status="voided")
        repo = repo_of(conn, w)
        assert [units_at(repo, iso(d)) for d in (1, 5, 6, 7, 40)] == [103.0, 103.0, 3.0, 3.0, 3.0]
        # el estado ACTUAL (lo que leía el motor antes) la excluye de todos los cortes: eso era la fuga
        current = conn.execute(text(
            "SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id "
            "WHERE si.tenant_id = :t AND s.status = 'completed' AND si.sale_date <= :d"), {"t": w.tenant_id, "d": iso(5)}).scalar_one()
        assert float(current) == 3.0 != units_at(repo, iso(5))


def test_a_later_correction_changes_the_quantity_only_from_its_knowledge_date(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        add_document(conn, w, number="001-0001", sale_date=iso(2), lines=[(0, 5, 10.0), (1, 2, 10.0)])
        amend_document(conn, w, "001-0001", known_from=local(iso(8), 9), lines=[(0, 9, 10.0)], change_kind="lines_changed")
        repo = repo_of(conn, w)
        assert [units_at(repo, iso(d)) for d in (2, 7, 8, 30)] == [7.0, 7.0, 9.0, 9.0]


def test_backfill_document_is_visible_from_its_business_day(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        add_document(conn, w, number="001-0001", sale_date=iso(10), lines=[(0, 4, 10.0)], mode="backfill")
        repo = repo_of(conn, w)
        assert [units_at(repo, iso(d)) for d in (9, 10, 11)] == [0.0, 4.0, 4.0]
        assert not repo.sales_versions(date.fromisoformat(iso(30))).has_dynamic


def test_documents_with_the_default_column_values_behave_as_before(db_engine, tenants):
    """Fixtures existentes (inserciones directas sin las columnas nuevas): ingestion_mode = backfill y conocimiento = su día.
    La vista as-of coincide con la consulta SQL anterior (estado vigente) en todos los cortes."""
    with db_engine.connect() as conn:
        repo = TenantRepository(conn, tenants["A"])
        last = repo.last_sale_date()
        modes = conn.execute(text("SELECT DISTINCT ingestion_mode FROM sales WHERE tenant_id = :t"), {"t": tenants["A"]}).scalars().all()
        assert modes == ["backfill"]
        for cutoff in (last, last - timedelta(days=200)):
            old = repo._read(LEGACY_DAILY_SALES_SQL, cutoff=cutoff)
            old["ds"] = pd.to_datetime(old["ds"])
            new = repo.daily_sales(cutoff)
            assert list(new.columns) == list(old.columns) and len(new) == len(old) > 0
            pd.testing.assert_frame_equal(new.reset_index(drop=True), old, check_dtype=False, rtol=1e-12, atol=1e-9)
        old_ret = repo._read(
            "SELECT si.branch_id, si.product_id, si.sale_date AS ds, SUM(si.quantity)::float8 AS units FROM sale_items si "
            "JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id WHERE si.tenant_id = :tenant_id AND s.status = 'completed' "
            "AND (si.is_return = true OR s.document_type = 'credit_note') AND si.sale_date <= :cutoff GROUP BY 1, 2, 3 ORDER BY 1, 2, 3",
            cutoff=last)
        old_ret["ds"] = pd.to_datetime(old_ret["ds"])
        new_ret = repo.daily_returns(last).sort_values(["branch_id", "product_id", "ds"]).reset_index(drop=True)
        pd.testing.assert_frame_equal(new_ret, old_ret, check_dtype=False)
        legacy_stats = repo.sales_stats()
        stats = repo.sales_stats(last)
        assert int(legacy_stats["lines"]) == stats["lines"] and int(legacy_stats["lines_with_cost"]) == stats["lines_with_cost"]
        assert pd.Timestamp(legacy_stats["date_from"]).date() == stats["date_from"] and repo.last_sale_date(last) == last
        assert not repo.sales_versions(last).has_dynamic


def test_one_read_of_the_versions_serves_every_cutoff(db_engine):
    """Una sola lectura de `sales_line_versions` hasta el corte final; todas las vistas as-of se derivan en pandas."""
    statements: list[str] = []

    def spy(conn, cursor, statement, parameters, context, executemany):
        if "sales_line_versions" in statement:
            statements.append(statement)

    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        for d in range(0, 20, 3):
            add_document(conn, w, number=f"001-{d:04d}", sale_date=iso(d), lines=[(0, 2 + d, 10.0)], mode="incremental",
                         known_from=local(iso(d + 2), 10))
        repo = repo_of(conn, w)
        event.listen(db_engine, "before_cursor_execute", spy)
        try:
            data = load_tenant_data(repo, date.fromisoformat(iso(30)))
            for d in (3, 8, 15, 30):
                repo.daily_sales(date.fromisoformat(iso(d)))
                repo.daily_returns(date.fromisoformat(iso(d)))
                repo.last_sale_date(date.fromisoformat(iso(d)))
                repo.sales_stats(date.fromisoformat(iso(d)))
                data.sales_versions.sales_cells(iso(d))
        finally:
            event.remove(db_engine, "before_cursor_execute", spy)
        assert len(statements) == 1, "una sola lectura de la vista, aunque se pidan muchos cortes"
        assert data.sales_versions.final == pd.Timestamp(iso(30)) and data.sales_knowledge["lines"] == 7
        assert data.sales_knowledge["dynamic_lines"] == 7, "todas llegaron dos días después de su fecha"
        # después de una lectura hasta el corte final, un corte menor no vuelve a leer, y el objeto respeta SU corte final
        assert repo.sales_versions(date.fromisoformat(iso(10))).final == pd.Timestamp(iso(10))


def test_what_happens_after_the_final_cutoff_cannot_change_a_past_cutoff_or_its_fingerprint(db_engine):
    """Reconstrucción HISTÓRICA: una corrección o carga posterior al corte final no cambia lo ya visto en cortes ≤ final ni la huella del
    componente de ventas. (Una ejecución vigente, en cambio, conoce hasta hoy: ver los tests de `live` más abajo.)"""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        add_document(conn, w, number="001-0001", sale_date=iso(0), lines=[(0, 3, 10.0)])
        add_document(conn, w, number="001-0002", sale_date=iso(5), lines=[(0, 8, 10.0)], mode="incremental", known_from=local(iso(6), 9))
        # hay ventas posteriores al corte: reconstruir el día 20 es una ejecución HISTÓRICA (no el último dato), así que su conocimiento
        # termina en el propio corte
        add_document(conn, w, number="001-0004", sale_date=iso(60), lines=[(0, 1, 10.0)])
        final = date.fromisoformat(iso(20))
        before = load_tenant_data(repo_of(conn, w), final)
        # llegan DESPUÉS del corte final: una anulación, una corrección y un documento de una fecha anterior
        amend_document(conn, w, "001-0001", known_from=local(iso(40), 9), status="voided")
        amend_document(conn, w, "001-0002", known_from=local(iso(41), 9), lines=[(0, 99, 10.0)])
        add_document(conn, w, number="001-0003", sale_date=iso(2), lines=[(0, 50, 10.0)], mode="incremental", known_from=local(iso(42), 9))
        after = load_tenant_data(repo_of(conn, w), final)
        pd.testing.assert_frame_equal(before.sales.reset_index(drop=True), after.sales.reset_index(drop=True))
        assert before.sales.attrs["knowledge_digest"] == after.sales.attrs["knowledge_digest"]
        panel_b, panel_a = build_panel(before), build_panel(after)
        cfg = {"core": "demand", "config": {}, "run": {}}
        kn = pd.Timestamp(final)
        snap_b = build_snapshot(before, panel_b, cfg, kn, None, {})
        snap_a = build_snapshot(after, panel_a, cfg, kn, None, {})
        assert snap_b.hash == snap_a.hash
        # …pero un corte posterior sí las ve (y su huella cambia)
        later = load_tenant_data(repo_of(conn, w), date.fromisoformat(iso(45)))
        assert float(later.sales["units"].sum()) == 99.0 + 50.0
        assert later.sales.attrs["knowledge_digest"] != before.sales.attrs["knowledge_digest"]


def test_a_live_run_knows_the_nightly_load_of_its_last_day_and_a_historical_reconstruction_of_that_cutoff_does_not(db_engine):
    """Con carga incremental, la venta del último día llega la madrugada siguiente (03:00 locales). La ejecución vigente (corte = último
    dato) la conoce —igual que las promociones—; la reconstrucción histórica del mismo corte no, porque entonces no existía. Sin esta
    regla el último día aparecería vacío en el pronóstico vigente."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        for d in range(0, 10):
            add_document(conn, w, number=f"B-{d:02d}", sale_date=iso(d), lines=[(0, 4, 10.0)])
        add_document(conn, w, number="N-09", sale_date=iso(9), lines=[(0, 6, 10.0)], mode="incremental", known_from=local(iso(10), 3))
        last = date.fromisoformat(iso(9))
        day9 = lambda data: float(data.sales.loc[data.sales["ds"] == pd.Timestamp(iso(9)), "units"].sum())  # noqa: E731

        repo = repo_of(conn, w)
        live = load_tenant_data(repo, last)
        today = live.promotions_known_on
        assert today is not None and today > last, "ejecución vigente: corte = último dato"
        assert live.sales_knowledge["knowledge_date"] == today.isoformat() and live.sales_versions.knowledge == pd.Timestamp(today)
        assert day9(live) == 10.0 and live.sales_stats["lines"] == 11 and live.sales_knowledge["late_lines"] == 1
        # la lectura estricta del corte (la que usa cualquier otro consumidor de `daily_sales(cutoff)`) sigue sin conocerla
        assert float(repo.daily_sales(last)["units"].sum()) == 40.0, "as-of estricto: lo recibido el día 10 no se conocía el 9"
        assert float(repo.daily_sales(last, today)["units"].sum()) == 46.0 and float(repo.daily_sales(last, last)["units"].sum()) == 40.0
        assert repo.last_sale_date(last) == last and repo.sales_stats(last)["lines"] == 10 and repo.sales_stats(last, today)["lines"] == 11

        # reconstrucción histórica del mismo corte: ya existen ventas posteriores, así que el día 9 NO es el último dato
        add_document(conn, w, number="B-12", sale_date=iso(12), lines=[(0, 4, 10.0)])
        hist = load_tenant_data(repo_of(conn, w), last)
        assert hist.promotions_known_on is None and hist.sales_knowledge["knowledge_date"] == last.isoformat()
        assert day9(hist) == 4.0 and hist.sales_stats["lines"] == 10 and hist.sales_knowledge["late_lines"] == 0
        assert live.sales.attrs["knowledge_digest"] != hist.sales.attrs["knowledge_digest"], "la huella distingue lo que cada una conoció"
        # el corte siguiente (también histórico) ya la conocía
        assert day9(load_tenant_data(repo_of(conn, w), date.fromisoformat(iso(10)))) == 10.0


def test_a_live_run_applies_a_correction_received_after_its_last_day_but_the_window_keeps_the_version_it_knew(db_engine):
    """Corrección recibida el día 41 (después del último día de datos, 39) a una venta del día 3: el período final y los reales de las
    ventanas usan la corregida; la ventana del día 20 entrena con la versión que regía entonces, y la reconstrucción histórica del corte
    39 (con ventas posteriores) no conoce la corrección."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        for d in range(0, 40):
            add_document(conn, w, number=f"B-{d:02d}", sale_date=iso(d), lines=[(0, 4, 10.0)])
        amend_document(conn, w, "B-03", known_from=local(iso(41), 9), lines=[(0, 100, 10.0)], change_kind="lines_changed")
        last = date.fromisoformat(iso(39))
        units_on = lambda df, d: float(df.loc[df["ds"] == pd.Timestamp(iso(d)), "units"].sum())  # noqa: E731

        live = load_tenant_data(repo_of(conn, w), last)
        assert live.sales_versions.has_dynamic and units_on(live.sales, 3) == 100.0, "el período final conoce la corrección"
        rules = DayRules()
        panel = build_panel(live, rules)
        fb = FeatureBuilder(panel.df, live.branches, live.promotions, panel.cutoff.date(), promotion_revisions=live.promotion_revisions)
        windows = asof_windows(live, panel, fb, panel.cutoff.date(), rules)
        assert windows is not None
        view, _ = windows.at(pd.Timestamp(iso(20)))
        assert view is not panel and units_on(view.df, 3) == 4.0, "la ventana del día 20 entrena con la versión que se conocía ese día"
        assert units_on(panel.df, 3) == 100.0, "los reales con que se evalúan las ventanas son la verdad disponible"
        assert windows.info()[0]["lines_not_yet_known"] == 1 and windows.info()[0]["lines_later_replaced"] == 1
        # un origen anterior a la venta corregida (día 2 < 3) no depende de ella: reutiliza el panel final
        assert windows.at(pd.Timestamp(iso(2)))[0] is panel and windows.info()[0]["knowledge"] == "equals_final_truth"

        # reconstrucción histórica: con ventas posteriores al corte, la corrección (día 41) no existía en el corte 39
        add_document(conn, w, number="B-45", sale_date=iso(45), lines=[(0, 4, 10.0)])
        hist = load_tenant_data(repo_of(conn, w), last)
        assert units_on(hist.sales, 3) == 4.0 and not hist.sales_versions.has_dynamic and hist.promotions_known_on is None


def coverage_limited_world(conn) -> tuple[World, date, date]:
    """Escenario del hallazgo «cobertura limita el corte»: histórico de los días 0 a 9, carga nocturna del día 9 (6 u. recibidas el día 10
    a las 03:00 locales), ventas PARCIALES de hoy (día 10) y cobertura completa declarada hasta el día 9. Devuelve (mundo, último dato,
    corte limitado por la cobertura)."""
    w = make_world(conn)
    for d in range(0, 10):
        add_document(conn, w, number=f"B-{d:02d}", sale_date=iso(d), lines=[(0, 4, 10.0)])
    add_document(conn, w, number="N-09", sale_date=iso(9), lines=[(0, 6, 10.0)], mode="incremental", known_from=local(iso(10), 3))
    add_document(conn, w, number="P-10", sale_date=iso(10), lines=[(0, 1, 10.0)], mode="incremental", known_from=local(iso(10), 15))
    conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, complete_from, complete_through, basis, created_at, updated_at) "
                      "VALUES (:t, 'sales', :f, :c, 'declared', now(), now())"), {"t": w.tenant_id, "f": iso(0), "c": iso(9)})
    repo = repo_of(conn, w)
    last = repo.last_sale_date()
    cutoff = SalesCoverage.resolve(conn, w.tenant_id, repo.sales_coverage(), None).limit_cutoff(last)
    assert (last, cutoff) == (date.fromisoformat(iso(10)), date.fromisoformat(iso(9))), "la cobertura limita el corte por debajo del último dato"
    return w, last, cutoff


def test_a_live_run_whose_cutoff_is_limited_by_coverage_keeps_the_nightly_load_of_its_last_day(db_engine):
    """Hallazgo MEDIUM de la revisión: con `cutoff < último dato` por la cobertura declarada, la ejecución vigente se trataba como histórica y
    perdía la carga nocturna de su último día. El llamador ahora lo indica con `live=True`; sin él se infiere del corte (el comportamiento
    anterior, correcto para quien pide un corte histórico) y `live=False` fuerza la reconstrucción histórica aunque el corte sea el último dato."""
    with rolled_back(db_engine) as conn:
        w, last, cutoff = coverage_limited_world(conn)
        day9 = lambda data: float(data.sales.loc[data.sales["ds"] == pd.Timestamp(iso(9)), "units"].sum())  # noqa: E731
        repo = repo_of(conn, w)

        live = SalesCoverage.resolve(conn, w.tenant_id, repo.sales_coverage(), None).apply(load_tenant_data(repo, cutoff, live=True))
        today = live.promotions_known_on
        assert today is not None and today > last, "vigente: el período final conoce hasta hoy"
        assert day9(live) == 10.0 and live.sales_knowledge["knowledge_date"] == today.isoformat() and live.sales_knowledge["late_lines"] == 1
        assert live.sales_stats["lines"] == 11 and live.sales["ds"].max() == pd.Timestamp(iso(9)), "nada posterior al corte limitado entra"

        inferred = load_tenant_data(repo_of(conn, w), cutoff)          # sin la señal explícita: cutoff < último dato ⇒ histórica
        assert inferred.promotions_known_on is None and day9(inferred) == 4.0 and inferred.sales_knowledge["knowledge_date"] == iso(9)
        forced = load_tenant_data(repo_of(conn, w), last, live=False)  # histórica forzada aunque el corte sea el último dato
        assert forced.promotions_known_on is None and forced.sales_knowledge["knowledge_date"] == iso(10), "conoce solo hasta su corte"
        assert live.sales.attrs["knowledge_digest"] != inferred.sales.attrs["knowledge_digest"], "la huella distingue lo que cada una conoció"
        assert load_tenant_data(repo_of(conn, w), last).promotions_known_on == today, "sin cobertura que limite, `None` infiere vigente (como antes)"


def test_the_forecast_run_passes_live_to_the_loader_so_a_coverage_limited_run_knows_the_nightly_load(db_engine, client):
    """De extremo a extremo (`execute_forecast_run`): una ejecución de producción (sin corte pedido) cuyo corte limita la cobertura declarada
    carga el último día con la carga nocturna; una histórica con corte explícito conoce solo hasta su corte."""
    from conftest import AUTH
    from loader import create_run

    with db_engine.begin() as conn:
        w = make_world(conn, name="Empresa cobertura vigente (ficticia)")
        for d in range(0, 69):
            add_document(conn, w, number=f"B-{d:02d}", sale_date=iso(d), lines=[(0, 4, 10.0), (1, 2, 10.0)])
        add_document(conn, w, number="N-68", sale_date=iso(68), lines=[(0, 6, 10.0)], mode="incremental", known_from=local(iso(69), 3))
        add_document(conn, w, number="P-69", sale_date=iso(69), lines=[(0, 1, 10.0)], mode="incremental", known_from=local(iso(69), 15))
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, complete_from, complete_through, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :f, :c, 'declared', now(), now())"), {"t": w.tenant_id, "f": iso(0), "c": iso(68)})

    def execute(cutoff: str | None) -> dict:
        with db_engine.begin() as conn:
            run_id, job_id = create_run(conn, w.tenant_id, run_type="historical" if cutoff else "production", cutoff=cutoff)
        body = {"tenant_id": w.tenant_id, "training_job_id": job_id, "horizons": [7]}
        if cutoff:
            body["cutoff_date"] = cutoff
        r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH, json=body)
        assert r.status_code == 200, r.text
        with db_engine.connect() as conn:
            return dict(conn.execute(text("SELECT cutoff_date, evaluation_manifest, summary FROM forecast_runs WHERE id = :r"),
                                     {"r": run_id}).mappings().one())

    live = execute(None)
    assert live["cutoff_date"].isoformat() == iso(68) and "cutoff_limited_by_coverage" in live["summary"]["limitation_codes"]
    k = live["evaluation_manifest"]["knowledge"]["source"]
    assert k["final_cutoff"] == iso(68) and date.fromisoformat(k["knowledge_date"]) > date.fromisoformat(iso(69)), "la ejecución vigente conoce hasta hoy"
    assert (k["lines"], k["late_lines"]) == (2 * 69 + 1, 1), "la carga nocturna del día 68 está en lo leído (la venta parcial del día 69 no)"
    hist = execute(iso(60))
    hk = hist["evaluation_manifest"]["knowledge"]["source"]
    assert hist["cutoff_date"].isoformat() == iso(60) and hk["knowledge_date"] == iso(60) == hk["final_cutoff"], "histórica: solo hasta su corte"
    assert (hk["lines"], hk["late_lines"]) == (2 * 61, 0)
    assert hk["digest"] != k["digest"]


def test_the_sales_fingerprint_changes_with_the_knowledge_windows_even_if_the_values_are_equal(db_engine):
    """Dos cortes con las mismas cifras pero distinto conocimiento no comparten huella en el componente de ventas."""
    def snapshot(mode: str):
        with rolled_back(db_engine) as conn:
            w = make_world(conn)
            for d in range(0, 30):
                add_document(conn, w, number=f"001-{d:04d}", sale_date=iso(d), lines=[(0, 5 + d % 3, 10.0)], unit_cost=4.0)
            kwargs = {} if mode == "backfill" else {"mode": "incremental", "known_from": local(iso(20), 9)}
            add_document(conn, w, number="001-9999", sale_date=iso(10), lines=[(0, 40, 10.0)], **kwargs)
            data = load_tenant_data(repo_of(conn, w), date.fromisoformat(iso(29)))
            panel = build_panel(data)
            return data, build_snapshot(data, panel, {"core": "demand", "config": {}, "run": {}}, pd.Timestamp(data.cutoff), None, {})

    data_a, snap_a = snapshot("backfill")
    data_b, snap_b = snapshot("incremental")
    values = ["ds", "units", "net_amount", "gross_amount", "discount_amount", "cost_amount", "cost_units", "lines"]  # los ids de BD difieren
    pd.testing.assert_frame_equal(data_a.sales[values].reset_index(drop=True), data_b.sales[values].reset_index(drop=True))
    changed = {k for k in snap_a.components if snap_a.components[k] != snap_b.components[k]}
    assert {"sales", "costs"} <= changed, "las ventas (y los costos derivados de ellas) llevan la ventana de conocimiento"
    assert snap_a.hash != snap_b.hash
    assert "inventory" not in changed and "config" not in changed and "calendar" not in changed
    assert data_a.sales_knowledge["dynamic_lines"] == 0 and data_b.sales_knowledge["dynamic_lines"] == 1
    assert data_b.sales_knowledge["ingestion_modes"] == {"backfill": 30, "incremental": 1}


def test_versions_are_scoped_by_tenant(db_engine):
    with rolled_back(db_engine) as conn:
        a, b = make_world(conn, name="Empresa A (ficticia)"), make_world(conn, name="Empresa B (ficticia)")
        add_document(conn, a, number="001-0001", sale_date=iso(0), lines=[(0, 3, 10.0)])
        add_document(conn, b, number="001-0001", sale_date=iso(0), lines=[(0, 500, 10.0)], mode="incremental", known_from=local(iso(3), 9))
        repo_a = repo_of(conn, a)
        v = repo_a.sales_versions(date.fromisoformat(iso(30)))
        assert set(v.cells["product_id"]) <= set(a.product_ids) and float(v.cells["units"].sum()) == 3.0
        assert float(repo_of(conn, b).daily_sales(date.fromisoformat(iso(30)))["units"].sum()) == 500.0
        assert units_at(repo_a, iso(30)) == 3.0


# ───────────────────────────── semántica de días desde la base de datos ─────────────────────────────

def seed_daily_sales(conn, w: World, n_days: int = 80, skip: set[tuple[int, int]] = frozenset(), lines_per_day: int = 6) -> None:
    """Ventas diarias (`lines_per_day` líneas por sucursal, repartidas entre los productos 0 y 1), salvo los pares (sucursal, día) de `skip`."""
    for d in range(n_days):
        for b in range(len(w.branch_ids)):
            if (b, d) in skip:
                continue
            add_document(conn, w, number=f"S{b}-{d:04d}", sale_date=iso(d), branch=b,
                         lines=[(k % 2, 3 + (d + k) % 4, 10.0) for k in range(lines_per_day)])


def test_declared_gaps_closures_and_coverage_come_from_the_database_scoped_by_tenant(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=3)
        other = make_world(conn, name="Otra empresa (ficticia)")
        source = int(conn.execute(text(
            "INSERT INTO data_sources (tenant_id, name, type, status, created_at, updated_at) VALUES (:t, 'ERP ficticio', 'sql', 'active', now(), now()) RETURNING id"),
            {"t": w.tenant_id}).scalar_one())
        add_document(conn, w, number="001-0001", sale_date=iso(0), branch=0, lines=[(0, 1, 10.0)])
        add_document(conn, w, number="001-0002", sale_date=iso(0), branch=1, lines=[(0, 1, 10.0)])      # la sucursal 3 no tiene fuente
        conn.execute(text("UPDATE sales SET data_source_id = :s WHERE tenant_id = :t AND branch_id = ANY(:b)"),
                     {"s": source, "t": w.tenant_id, "b": w.branch_ids[:2]})
        for t, branch, gf, gt, basis, src in ((w.tenant_id, w.branch_ids[1], iso(10), iso(12), "declared", None),
                                              (w.tenant_id, None, iso(20), iso(21), "inferred", source),
                                              (other.tenant_id, None, iso(30), iso(31), "declared", None)):
            conn.execute(text(
                "INSERT INTO data_coverage_gaps (tenant_id, entity, data_source_id, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                "VALUES (:t, 'sales', :s, :b, :gf, :gt, 'integration_outage', :basis, now(), now())"),
                {"t": t, "s": src, "b": branch, "gf": gf, "gt": gt, "basis": basis})
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, gap_from, gap_to, created_at, updated_at) "
                          "VALUES (:t, 'inventory', :gf, :gt, now(), now())"), {"t": w.tenant_id, "gf": iso(1), "gt": iso(2)})
        events = [("tenant", None, "cierre-empresa", iso(40), iso(41), "confirmed", 0), ("branch", f"branch:{w.branch_ids[2]}", "inventario", iso(50), iso(50), "confirmed", 0),
                  ("branch", f"branch:{w.branch_ids[0]}", "cancelado", iso(60), iso(60), "cancelled", 0),
                  ("branch", "sin-referencia", "mal-formada", iso(61), iso(61), "confirmed", 0),
                  ("tenant", None, "cierre-empresa", iso(40), iso(42), "confirmed", 1)]            # revisión 1: la última manda
        for scope, ref, code, s, e, st, rev in events:
            conn.execute(text(
                "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, revision, created_at, updated_at) "
                "VALUES (:t, :sc, :ref, :code, :code, 'closure', :s, :e, :st, now(), :rev, now(), now())"),
                {"t": w.tenant_id, "sc": scope, "ref": ref, "code": code, "s": s, "e": e, "st": st, "rev": rev})
        conn.execute(text(
            "INSERT INTO calendar_events (tenant_id, scope, code, name, category, starts_on, ends_on, status, known_at, created_at, updated_at) "
            "VALUES (:t, 'tenant', 'feriado', 'feriado', 'holiday', :s, :s, 'confirmed', now(), now(), now())"), {"t": w.tenant_id, "s": iso(70)})
        repo = repo_of(conn, w)
        gaps = repo.sales_coverage_gaps()
        assert len(gaps) == 2 and set(gaps["basis"]) == {"declared", "inferred"}, "solo la entidad sales y solo la empresa"
        closures = repo.closure_events()
        by = {(None if pd.isna(r.branch_id) else int(r.branch_id), str(r.code), str(r.ends_on)) for r in closures.itertuples()}
        assert by == {(None, "cierre-empresa", iso(42)), (w.branch_ids[2], "inventario", iso(50))}, "última revisión, sin cancelados, sin otras categorías"
        assert closures.attrs["ignored"] == 1, "la referencia de sucursal mal formada se ignora y se cuenta"
        assert repo.branches_by_source([source]) == {source: sorted(w.branch_ids[:2])}
        assert repo_of(conn, other).sales_coverage_gaps()["gap_from"].astype(str).tolist() == [iso(30)]
        assert repo_of(conn, other).closure_events().empty


def test_a_source_outage_is_missing_source_when_declared_and_when_inferred_but_never_a_zero(db_engine):
    """Defecto HIGH: la caída de la integración (días sin ninguna línea) entraba al panel como ceros observados."""
    outage = {(0, d) for d in range(50, 54)}
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        seed_daily_sales(conn, w, n_days=80, skip=outage)
        repo = repo_of(conn, w)
        data = load_tenant_data(repo, date.fromisoformat(iso(79)))
        uid = f"b{w.branch_ids[0]}-p{w.product_ids[0]}"

        def statuses(panel, s=uid, days=range(48, 56)):
            df = panel.df[panel.df["unique_id"] == s].set_index("ds")
            return [str(df.loc[pd.Timestamp(iso(d)), "day_status"]) for d in days]

        # 1) sin declarar y con la regla apagada: el comportamiento anterior (ceros observados) y la limitación de la cobertura
        zeros = build_panel(data, DayRules(infer_gaps=False))
        assert statuses(zeros) == ["sale"] * 2 + ["zero_confirmed"] * 4 + ["sale"] * 2
        # 2) regla inferida (activa por defecto en la configuración): missing_source con base inferred
        inferred = build_panel(data, DayRules())
        assert statuses(inferred) == ["sale"] * 2 + ["missing_source"] * 4 + ["sale"] * 2
        assert set(inferred.df.loc[inferred.df["day_status"] == "missing_source", "day_basis"].astype(str)) == {"inferred"}
        other_branch = f"b{w.branch_ids[1]}-p{w.product_ids[0]}"
        assert statuses(inferred, other_branch) == ["sale"] * 8, "la otra sucursal sí vendió"
        # 3) declarado en data_coverage_gaps: base declared (manda sobre la inferida)
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :gf, :gt, 'integration_outage', 'declared', now(), now())"),
                     {"t": w.tenant_id, "b": w.branch_ids[0], "gf": iso(50), "gt": iso(53)})
        declared = build_panel(load_tenant_data(repo_of(conn, w), date.fromisoformat(iso(79))), DayRules(infer_gaps=False))
        assert statuses(declared) == ["sale"] * 2 + ["missing_source"] * 4 + ["sale"] * 2
        assert set(declared.df.loc[declared.df["day_status"] == "missing_source", "day_basis"].astype(str)) == {"declared"}
        both = build_panel(load_tenant_data(repo_of(conn, w), date.fromisoformat(iso(79))), DayRules())
        assert set(both.df.loc[both.df["day_status"] == "missing_source", "day_basis"].astype(str)) == {"declared"}
        assert both.day_summary["branch_days"]["missing_source_engine_inferred"] == 0
        # ningún día de la caída es un cero observado ni cuenta en la proporción de ceros del perfil
        gap_rows = declared.df[(declared.df["branch_id"] == w.branch_ids[0]) & declared.df["ds"].between(iso(50), iso(53))]
        assert (gap_rows["day_status"] != "zero_confirmed").all() and (gap_rows["y"] > 0).all()
        prof = declared.profile.set_index("unique_id")
        assert prof.loc[uid, "missing_source_days"] == 4 and prof.loc[uid, "adi"] == pytest.approx(1.0)


def test_declared_closures_are_closed_and_data_coverage_limits_the_days_of_each_branch(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=3)
        seed_daily_sales(conn, w, n_days=80, skip={(2, d) for d in range(60, 80)} | {(b, 45) for b in range(3)}, lines_per_day=6)
        for ref, code, s in (("tenant", "cierre-empresa", iso(45)), ("branch", "inventario", iso(30))):
            scope_ref = None if ref == "tenant" else f"branch:{w.branch_ids[1]}"
            conn.execute(text(
                "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, created_at, updated_at) "
                "VALUES (:t, :sc, :ref, :c, :c, 'closure', :s, :s, 'confirmed', now(), now(), now())"),
                {"t": w.tenant_id, "sc": ref, "ref": scope_ref, "c": code, "s": s})
        # la sucursal 3 está cerrada (desactivada) y su cobertura propia llega hasta el día 59; la empresa hasta el día 79
        conn.execute(text("UPDATE branches SET is_active = false WHERE id = :b"), {"b": w.branch_ids[2]})
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, complete_from, complete_through, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :f, :c, 'declared', now(), now())"), {"t": w.tenant_id, "f": iso(0), "c": iso(79)})
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, branch_id, complete_from, complete_through, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :f, :c, 'declared', now(), now())"),
                     {"t": w.tenant_id, "b": w.branch_ids[2], "f": iso(0), "c": iso(59)})
        repo = repo_of(conn, w)
        branches = repo.branches()
        active = set(branches.loc[branches["is_active"].astype(bool), "branch_id"].astype(int))
        coverage = SalesCoverage.resolve(conn, w.tenant_id, repo.sales_coverage(), active)
        assert coverage.limit_cutoff(date.fromisoformat(iso(79))) == date.fromisoformat(iso(79)), "la sucursal cerrada no fija el corte"
        assert coverage.branch_through[w.branch_ids[2]] == date.fromisoformat(iso(59))
        data = coverage.apply(load_tenant_data(repo, date.fromisoformat(iso(79))))
        panel = build_panel(data)

        def st(branch_idx, d, product=0):
            row = panel.df[(panel.df["branch_id"] == w.branch_ids[branch_idx]) & (panel.df["product_id"] == w.product_ids[product])
                           & (panel.df["ds"] == pd.Timestamp(iso(d)))]
            return str(row["day_status"].iloc[0]), (None if pd.isna(row["day_basis"].iloc[0]) else str(row["day_basis"].iloc[0]))

        assert st(0, 45) == ("closed", "declared") and st(1, 45) == ("closed", "declared"), "cierre de la empresa"
        assert st(1, 30) == ("closed", "declared") and st(0, 30)[0] == "sale", "cierre solo de la sucursal 2"
        assert st(2, 59)[0] == "sale" and st(2, 60) == ("missing_source", "declared"), "la cobertura propia de la sucursal cerrada acaba el día 59"
        assert panel.day_summary["series_days"]["closed"] > 0 and data.coverage["sales"]["branch_complete_through"] == {
            str(w.branch_ids[2]): iso(59)}


def test_declaring_a_closure_or_a_gap_changes_the_persisted_fingerprint_and_removing_it_reuses_the_old_row(db_engine):
    """`persist_snapshot` reutiliza la fila por (empresa, core, hash): si declarar un cierre o un hueco no cambiaba la huella, dos ejecuciones
    con paneles distintos compartían `data_snapshot_id`."""
    from predictily_engine.platform.snapshot import persist_snapshot

    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        seed_daily_sales(conn, w, n_days=60, lines_per_day=1)
        cutoff = date.fromisoformat(iso(59))

        def snapshot_id() -> tuple[int, str]:
            data = load_tenant_data(repo_of(conn, w), cutoff)
            snap = build_snapshot(data, build_panel(data, DayRules()), {"core": "demand", "config": {}, "run": {}}, pd.Timestamp(cutoff), None, data.coverage)
            return persist_snapshot(conn, w.tenant_id, snap), snap.hash

        plain_id, plain_hash = snapshot_id()
        conn.execute(text("INSERT INTO calendar_events (tenant_id, scope, code, name, category, starts_on, ends_on, status, known_at, created_at, updated_at) "
                          "VALUES (:t, 'tenant', 'cierre', 'cierre', 'closure', :s, :s, 'confirmed', now(), now(), now())"), {"t": w.tenant_id, "s": iso(30)})
        closure_id, closure_hash = snapshot_id()
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :f, :e, 'integration_outage', 'declared', now(), now())"),
                     {"t": w.tenant_id, "b": w.branch_ids[0], "f": iso(40), "e": iso(41)})
        gap_id, gap_hash = snapshot_id()
        assert len({plain_id, closure_id, gap_id}) == 3 and len({plain_hash, closure_hash, gap_hash}) == 3
        conn.execute(text("DELETE FROM data_coverage_gaps WHERE tenant_id = :t"), {"t": w.tenant_id})
        conn.execute(text("DELETE FROM calendar_events WHERE tenant_id = :t"), {"t": w.tenant_id})
        assert snapshot_id() == (plain_id, plain_hash), "sin las declaraciones vuelve la huella original (misma fila)"


def test_readiness_applies_the_declared_coverage_like_the_forecast_run(db_engine, client):
    """Hallazgo LOW de la revisión: `run_readiness` cargaba hasta la última venta e ignoraba la cobertura declarada, así que su informe y el
    perfil de series describían datos que el pronóstico no usa. Ahora aplica `SalesCoverage` igual que `execute_forecast_run`: el corte no la
    supera, lo anterior a `complete_from` se descarta y los días fuera de ella son `missing_source`."""
    from conftest import AUTH

    with db_engine.begin() as conn:
        w = make_world(conn, name="Empresa readiness cobertura (ficticia)")
        seed_daily_sales(conn, w, n_days=80, lines_per_day=1)
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, complete_from, complete_through, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :f, :c, 'declared', now(), now())"), {"t": w.tenant_id, "f": iso(10), "c": iso(59)})

    r = client.post(f"/v1/tenants/{w.tenant_id}/readiness", headers=AUTH, json={})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["date_from"] == iso(10) and body["date_to"] == iso(59), "el informe usa lo mismo que el pronóstico: [complete_from, complete_through]"
    assert body["history_days"] == 50
    limited = [i for i in body["issues"] if i["code"] == "cutoff_limited_by_coverage"]
    assert len(limited) == 1 and limited[0]["count"] == 20 and iso(59) in limited[0]["message"]
    assert body["coverage"]["sales"]["cutoff_limited"] and body["coverage"]["sales"]["effective_cutoff"] == iso(59)
    with db_engine.connect() as conn:
        last_day = conn.execute(text("SELECT max(last_sale_date) FROM forecasting_series WHERE tenant_id = :t"), {"t": w.tenant_id}).scalar_one()
    assert last_day.isoformat() == iso(59), "el perfil de series persistido llega hasta el corte limitado"
    # una empresa sin cobertura declarada conserva el informe de siempre
    with db_engine.begin() as conn:
        free = make_world(conn, name="Empresa readiness sin cobertura (ficticia)")
        seed_daily_sales(conn, free, n_days=40, lines_per_day=1)
    plain = client.post(f"/v1/tenants/{free.tenant_id}/readiness", headers=AUTH, json={}).json()
    assert plain["date_to"] == iso(39) and not any(i["code"] == "cutoff_limited_by_coverage" for i in plain["issues"]) and "coverage" not in plain


def test_undeclared_coverage_keeps_the_old_zero_semantics_and_the_limitation(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn)
        seed_daily_sales(conn, w, n_days=60, skip={(0, 30)}, lines_per_day=1)       # pocas líneas: nada se infiere
        repo = repo_of(conn, w)
        coverage = SalesCoverage.resolve(conn, w.tenant_id, repo.sales_coverage(), None)
        data = coverage.apply(load_tenant_data(repo, date.fromisoformat(iso(59))))
        assert [x["code"] for x in coverage.limitations()] == ["coverage_undeclared"]
        panel = build_panel(data, DayRules())
        row = panel.df[(panel.df["branch_id"] == w.branch_ids[0]) & (panel.df["ds"] == pd.Timestamp(iso(30)))]
        assert (row["day_status"].astype(str) == "zero_confirmed").all() and (row["y"] == 0).all()


# ───────────────────────────── backtest as-of por ventana ─────────────────────────────

FAMILIES = ["seasonal_naive", "window_average", "auto_ets", "lightgbm_global"]


def existing_world(conn, tenant_id: int) -> World:
    ids = lambda sql: [int(r[0]) for r in conn.execute(text(sql), {"t": tenant_id})]  # noqa: E731
    return World(tenant_id, ids("SELECT id FROM branches WHERE tenant_id = :t ORDER BY id"),
                 ids("SELECT id FROM products WHERE tenant_id = :t ORDER BY id"), [], None)


def backtest(data, plan, *, windows: bool):
    rules = DayRules()
    panel = build_panel(data, rules)
    fb = FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), promotion_revisions=data.promotion_revisions)
    w = asof_windows(data, panel, fb, panel.cutoff.date(), rules) if windows else None
    bt = run_backtest(panel, fb, plan, FAMILIES, seed=42, n_jobs=1, use_lgbm=True, windows=w)
    return bt, w


def window_rows(bt: pd.DataFrame, k: int) -> pd.DataFrame:
    return bt[bt["window"] == k].set_index(["unique_id", "ds", "family"]).sort_index()


def test_a_backtest_window_does_not_change_when_data_known_after_its_cutoff_is_perturbed(db_engine, tenants):
    """Generaliza `test_leakage`: la fuga ya no es solo por fecha de evento sino por FECHA DE CONOCIMIENTO. Un documento cargado
    tarde, una anulación posterior y una corrección posterior (todas con fecha de evento ANTERIOR al corte de la ventana 0) no
    cambian lo que entrena la ventana 0; sí cambian las ventanas que ya las conocían, y los reales de evaluación son la verdad final."""
    with rolled_back(db_engine) as conn:
        w = existing_world(conn, tenants["A"])
        repo = TenantRepository(conn, w.tenant_id)
        repo.analytic_reads()
        cutoff = repo.last_sale_date()
        c0 = pd.Timestamp(cutoff) - pd.Timedelta(days=120)
        c1 = c0 + pd.Timedelta(days=14)
        plan = BacktestPlan(horizon=14, step=14, cutoffs=[c0, c1])
        day = lambda off: (c0 + pd.Timedelta(days=off)).date().isoformat()  # noqa: E731

        # estado base: X y Y existen con las cantidades originales, conocidas desde su día (carga histórica)
        add_document(conn, w, number="T02-X", sale_date=day(-5), lines=[(0, 300, 10.0)])
        add_document(conn, w, number="T02-Y", sale_date=day(-7), lines=[(1, 10, 10.0)])
        base = load_tenant_data(repo_of(conn, w), cutoff)
        bt_base, windows_base = backtest(base, plan, windows=True)
        assert windows_base is None, "una carga histórica sin cambios no necesita ventanas as-of (mismo resultado, sin costo)"

        # perturbación: todo se conoce DESPUÉS del corte c0 de la ventana 0 (y X, Y, L antes del corte c1 de la ventana 1)
        add_document(conn, w, number="T02-L", sale_date=day(-10), lines=[(2, 400, 10.0)], mode="incremental", known_from=local(day(4), 9))
        amend_document(conn, w, "T02-X", known_from=local(day(5), 12), status="voided")
        amend_document(conn, w, "T02-Y", known_from=local(day(6), 9), lines=[(1, 500, 10.0)], change_kind="lines_changed")
        add_document(conn, w, number="T02-E", sale_date=day(5), lines=[(3, 250, 10.0)], mode="incremental", known_from=local(day(20), 9))
        pert = load_tenant_data(repo_of(conn, w), cutoff)
        assert pert.sales_versions.has_dynamic
        bt_pert, windows = backtest(pert, plan, windows=True)
        bt_leak, _ = backtest(pert, plan, windows=False)

        b0, p0, leak0 = window_rows(bt_base, 0), window_rows(bt_pert, 0), window_rows(bt_leak, 0)
        assert len(b0) == len(p0) == len(leak0) > 0 and set(b0.index.get_level_values("family")) == set(FAMILIES)
        # 1) la ventana 0 entrena con lo que se conocía en c0: pronósticos, clases y escalas idénticos al estado base
        np.testing.assert_allclose(p0["yhat"], b0["yhat"], rtol=1e-9, atol=1e-9)
        assert (p0["demand_class"] == b0["demand_class"]).all()
        for col in ("scale", "scale_mae", "scale_mse", "price_fc"):
            np.testing.assert_allclose(p0[col].astype(float), b0[col].astype(float), rtol=1e-9, atol=1e-9, equal_nan=True, err_msg=col)
        # 2) sin conocimiento as-of (el comportamiento anterior) la ventana 0 SÍ cambiaba: la prueba discrimina
        uid = lambda branch_idx, product_idx: f"b{w.branch_ids[branch_idx]}-p{w.product_ids[product_idx]}"  # noqa: E731
        wa = lambda rows, product_idx: rows.xs(uid(0, product_idx), level="unique_id").xs("window_average", level="family")["yhat"]  # noqa: E731
        for p_idx in (0, 1, 2):
            assert not np.allclose(wa(leak0, p_idx), wa(b0, p_idx)), f"fuga en la serie del producto {p_idx} sin conocimiento as-of"
        # 3) los reales de la evaluación son la verdad disponible al corte final: incluyen el documento tardío E
        a_base = b0["actual"].xs(uid(0, 3), level="unique_id").xs("window_average", level="family")
        a_pert = p0["actual"].xs(uid(0, 3), level="unique_id").xs("window_average", level="family")
        assert a_pert.loc[pd.Timestamp(day(5))] == a_base.loc[pd.Timestamp(day(5))] + 250.0
        diff = (p0["actual"] - b0["actual"]).abs()
        assert (diff[diff > 0].index.get_level_values("unique_id") == uid(0, 3)).all()
        # 4) la ventana 1 (c1) ya conoce X anulada, Y corregida y L: sus pronósticos cambian en las series afectadas
        b1, p1 = window_rows(bt_base, 1), window_rows(bt_pert, 1)
        assert wa(p1, 0).iloc[0] < wa(b1, 0).iloc[0], "X (300 u.) anulada antes de c1: baja el promedio"
        assert wa(p1, 1).iloc[0] > wa(b1, 1).iloc[0], "Y corregida de 10 a 500 antes de c1: sube el promedio"
        assert wa(p1, 2).iloc[0] > wa(b1, 2).iloc[0], "L (400 u.) recibida antes de c1: sube el promedio"
        assert np.allclose(wa(p1, 5), wa(b1, 5)), "una serie que nadie tocó no cambia"
        # 5) auditoría: qué conocimiento usó cada ventana
        info = windows.info()
        assert [i["origin"] for i in info] == [c0.date().isoformat(), c1.date().isoformat()]
        # c0: aún no se conocían L y la corrección de Y; se creía vigente la versión original de X y de Y
        assert info[0]["panel_rebuilt"] and (info[0]["lines_not_yet_known"], info[0]["lines_later_replaced"]) == (2, 2)
        # c1: ya se conocían las tres; solo falta E (fecha de evento anterior a c1, conocida después)
        assert info[1]["panel_rebuilt"] and (info[1]["lines_not_yet_known"], info[1]["lines_later_replaced"]) == (1, 0)
        assert pert.sales_knowledge["dynamic_lines"] == 5 and pert.sales_knowledge["superseded_lines"] == 2
        assert pert.sales_knowledge["late_lines"] == 3


def test_the_holdout_window_trains_with_what_was_known_at_its_origin(db_engine, tenants):
    """El período final reservado (holdout) también entrena con lo conocido en SU origen `H`: una anulación y un documento tardío conocidos
    DESPUÉS de `H` (pero antes del corte final), ambos con fecha de evento anterior a `H`, no cambian su pronóstico, clase ni escalas, y
    las filas del holdout son idénticas a las de un estado en el que nunca ocurrieron. Sin conocimiento as-of sí cambiaban."""
    from predictily_engine.backtest.rolling import plan_backtest

    with rolled_back(db_engine) as conn:
        w = existing_world(conn, tenants["A"])
        repo = TenantRepository(conn, w.tenant_id)
        repo.analytic_reads()
        cutoff = repo.last_sale_date()
        plan = plan_backtest(build_panel(load_tenant_data(repo, cutoff), DayRules()), 3)
        assert plan is not None and plan.has_holdout, "la empresa de pruebas tiene historia para reservar un período final"
        h0 = plan.holdout_cutoff
        day = lambda off: (h0 + pd.Timedelta(days=off)).date().isoformat()  # noqa: E731

        # estado de referencia: X (300 u.) conocida desde su día, sin anular; L no existe
        add_document(conn, w, number="T02-HX", sale_date=day(-6), lines=[(0, 300, 10.0)])
        base = load_tenant_data(repo_of(conn, w), cutoff)
        bt_base, windows_base = backtest(base, plan, windows=True)
        assert windows_base is None, "una carga histórica sin cambios no necesita ventanas as-of"

        # perturbación posterior a H y anterior al corte final: X se anula el día H+5 y L (fecha de evento H-9) llega el día H+3
        amend_document(conn, w, "T02-HX", known_from=local(day(5), 12), status="voided")
        add_document(conn, w, number="T02-HL", sale_date=day(-9), lines=[(1, 400, 10.0)], mode="incremental", known_from=local(day(3), 9))
        pert = load_tenant_data(repo_of(conn, w), cutoff)
        assert pert.sales_versions.has_dynamic
        bt_pert, windows = backtest(pert, plan, windows=True)
        bt_leak, _ = backtest(pert, plan, windows=False)

        def holdout(bt):
            rows = bt[bt["is_holdout"]]
            assert set(rows["window"]) == {plan.holdout_window}
            return rows.set_index(["unique_id", "ds", "family"]).sort_index()

        b, p, leak = holdout(bt_base), holdout(bt_pert), holdout(bt_leak)
        assert len(b) == len(p) == len(leak) > 0 and set(b.index.get_level_values("family")) == set(FAMILIES)
        # 1) con conocimiento as-of las filas del holdout son idénticas a las del estado donde nada de eso ocurrió
        np.testing.assert_allclose(p["yhat"], b["yhat"], rtol=1e-9, atol=1e-9)
        np.testing.assert_allclose(p["actual"], b["actual"], rtol=1e-9, atol=1e-9)
        assert (p["demand_class"] == b["demand_class"]).all() and (p["stockout"] == b["stockout"]).all()
        for col in ("scale", "scale_mae", "scale_mse", "price_fc"):
            np.testing.assert_allclose(p[col].astype(float), b[col].astype(float), rtol=1e-9, atol=1e-9, equal_nan=True, err_msg=col)
        # 2) sin conocimiento as-of (el comportamiento anterior) el holdout SÍ cambiaba: la prueba discrimina
        uid = lambda product_idx: f"b{w.branch_ids[0]}-p{w.product_ids[product_idx]}"  # noqa: E731
        wa = lambda rows, product_idx: rows.xs(uid(product_idx), level="unique_id").xs("window_average", level="family")["yhat"]  # noqa: E731
        for product_idx in (0, 1):
            assert not np.allclose(wa(leak, product_idx), wa(b, product_idx)), f"fuga en el holdout de la serie del producto {product_idx}"
        # 3) auditoría: el origen del holdout reconstruyó su panel y registró qué no conocía todavía y qué se reemplazó después
        entry = {i["origin"]: i for i in windows.info()}[h0.date().isoformat()]
        assert entry["panel_rebuilt"] and (entry["lines_not_yet_known"], entry["lines_later_replaced"]) == (1, 1)


def test_windows_that_equal_the_final_truth_reuse_it(db_engine, tenants):
    """Si lo que llegó tarde no toca el pasado de una ventana, esa ventana reutiliza el panel final (mismo resultado, sin reconstruir)."""
    with rolled_back(db_engine) as conn:
        w = existing_world(conn, tenants["A"])
        repo = TenantRepository(conn, w.tenant_id)
        cutoff = repo.last_sale_date()
        c0 = pd.Timestamp(cutoff) - pd.Timedelta(days=120)
        add_document(conn, w, number="T02-LATE", sale_date=(c0 + pd.Timedelta(days=30)).date().isoformat(), lines=[(0, 80, 10.0)],
                     mode="incremental", known_from=local((c0 + pd.Timedelta(days=35)).date().isoformat(), 9))
        data = load_tenant_data(repo_of(conn, w), cutoff)
        rules = DayRules()
        panel = build_panel(data, rules)
        fb = FeatureBuilder(panel.df, data.branches, data.promotions, panel.cutoff.date(), promotion_revisions=data.promotion_revisions)
        windows = asof_windows(data, panel, fb, panel.cutoff.date(), rules)
        assert windows is not None
        early_panel, early_fb = windows.at(c0)                                   # el documento ni siquiera existe todavía
        assert early_panel is panel and early_fb is fb
        late_panel, _ = windows.at(c0 + pd.Timedelta(days=32))                   # existe pero aún no se conocía
        assert late_panel is not panel
        final_panel, _ = windows.at(pd.Timestamp(cutoff))
        assert final_panel is panel
        info = {i["origin"]: i for i in windows.info()}
        assert info[c0.date().isoformat()]["knowledge"] == "equals_final_truth" and not info[c0.date().isoformat()]["panel_rebuilt"]
        assert info[(c0 + pd.Timedelta(days=32)).date().isoformat()]["knowledge"] == "as_of_origin"


def test_the_full_pipeline_records_the_knowledge_it_used_and_window_zero_ignores_what_was_known_later(db_engine, tenants):
    """Equivalente de `test_pipeline.py::test_future_after_a_backtest_cutoff_does_not_change_that_window` pero por FECHA DE CONOCIMIENTO,
    con `run_pipeline` completo sobre los datos de la BD: una anulación posterior y un documento tardío (ambos con fecha de evento anterior
    al corte de la ventana 0, conocidos después) no cambian las series, clases ni pronósticos de esa ventana; el manifiesto y el resumen
    dicen qué conocimiento usó cada ventana."""
    from predictily_engine.backtest.rolling import plan_backtest
    from predictily_engine.pipeline.core import run_pipeline

    with rolled_back(db_engine) as conn:
        w = existing_world(conn, tenants["A"])
        repo = TenantRepository(conn, w.tenant_id)
        repo.analytic_reads()
        cutoff = repo.last_sale_date()
        c0 = plan_backtest(build_panel(load_tenant_data(repo, cutoff)), 3).cutoffs[0]
        day = lambda off: (c0 + pd.Timedelta(days=off)).date().isoformat()  # noqa: E731
        add_document(conn, w, number="T02-X", sale_date=day(-1), lines=[(0, 400, 10.0)])
        base = run_pipeline(load_tenant_data(repo_of(conn, w), cutoff), horizon=90, seed=42, n_jobs=2)
        amend_document(conn, w, "T02-X", known_from=local(day(3), 12), status="voided")
        add_document(conn, w, number="T02-L", sale_date=day(-3), lines=[(1, 300, 10.0)], mode="incremental", known_from=local(day(4), 9))
        pert_data = load_tenant_data(repo_of(conn, w), cutoff)
        pert = run_pipeline(pert_data, horizon=90, seed=42, n_jobs=2)

    assert pert.plan.cutoffs == base.plan.cutoffs and pert.plan.cutoffs[0] == c0
    key = ["unique_id", "ds", "family"]
    b0, p0 = base.backtest[base.backtest["window"] == 0], pert.backtest[pert.backtest["window"] == 0]
    m = b0.merge(p0, on=key, suffixes=("_b", "_p"), validate="one_to_one")
    assert len(m) == len(b0) == len(p0) > 0
    np.testing.assert_allclose(m["yhat_b"], m["yhat_p"], rtol=1e-9, atol=1e-9)
    assert (m["demand_class_b"] == m["demand_class_p"]).all() and np.allclose(m["actual_b"], m["actual_p"])
    later = [k for k in sorted(set(base.backtest["window"])) if k > 0]
    changed = 0
    for k in later:
        mk = base.backtest[base.backtest["window"] == k].merge(pert.backtest[pert.backtest["window"] == k], on=key, suffixes=("_b", "_p"))
        changed += int((np.abs(mk["yhat_b"] - mk["yhat_p"]) > 1e-9).sum())
    assert changed > 0, "las ventanas que ya conocían la anulación y el documento tardío sí cambian"
    # el manifiesto y el resumen registran la política y lo que usó cada ventana
    k = pert.manifest["knowledge"]
    assert k == pert.summary["knowledge"] and k["as_of_windows_active"] is True
    assert "tal como se conocían" in k["policy"]["training"] and "verdad disponible" in k["policy"]["evaluation_actuals"]
    origins = {i["origin"]: i for i in k["windows"]}
    assert origins[c0.date().isoformat()]["panel_rebuilt"] and origins[c0.date().isoformat()]["lines_later_replaced"] >= 1
    assert k["source"]["late_lines"] >= 1 and k["source"]["superseded_lines"] >= 1 and k["source"]["digest"]
    assert base.manifest["knowledge"]["as_of_windows_active"] is False and base.manifest["knowledge"]["windows"] == []
    assert base.snapshot.components["sales"] != pert.snapshot.components["sales"] and pert.summary["data_snapshot_hash"] == pert.snapshot.hash
    # lo que se conocía en c0 no entró al manifiesto de selección de esa ventana: mismas fechas objetivo y mismo conjunto de series
    sel_b, sel_p = base.manifest["selection"], pert.manifest["selection"]
    assert sel_b["origins"] == sel_p["origins"] and sel_b["target_from"] == sel_p["target_from"]


def test_the_asof_read_cost_is_reported(db_engine, tenants, record_property):
    """Costo de la lectura as-of frente a la consulta agregada anterior sobre los datos de la empresa de pruebas. Se reporta (propiedades
    de la prueba y `-s`); el umbral es holgado a propósito para no fallar por ruido de la máquina."""
    import time

    with db_engine.connect() as conn:
        repo = TenantRepository(conn, tenants["A"])
        repo.analytic_reads()
        last = repo.last_sale_date()
        n_lines = int(conn.execute(text("SELECT count(*) FROM sale_items WHERE tenant_id = :t"), {"t": tenants["A"]}).scalar_one())
        t0 = time.perf_counter()
        repo._read(LEGACY_DAILY_SALES_SQL, cutoff=last)
        legacy = time.perf_counter() - t0
        fresh = TenantRepository(conn, tenants["A"])
        fresh.analytic_reads()
        fresh.timezone()
        t0 = time.perf_counter()
        versions = fresh.sales_versions(last)
        read = time.perf_counter() - t0
        t0 = time.perf_counter()
        for back in (0, 40, 80, 120, 160):
            versions.sales_cells(pd.Timestamp(last) - pd.Timedelta(days=back))
        per_view = (time.perf_counter() - t0) / 5
        t0 = time.perf_counter()
        load_tenant_data(TenantRepository(conn, tenants["A"]), last)
        load = time.perf_counter() - t0
    for key, value in (("lines", n_lines), ("legacy_aggregated_query_s", round(legacy, 3)), ("asof_versions_read_s", round(read, 3)),
                       ("asof_extra_view_s", round(per_view, 4)), ("load_tenant_data_s", round(load, 3))):
        record_property(key, value)
    print(f"\n[costo as-of] {n_lines} líneas: consulta agregada anterior {legacy:.3f}s | lectura de versiones {read:.3f}s | "
          f"cada vista as-of adicional {per_view * 1000:.1f} ms | load_tenant_data completo {load:.2f}s")
    assert read < max(10 * legacy, 3.0) and per_view < 0.5
