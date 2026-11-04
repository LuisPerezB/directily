"""ADR-V25-03 · evaluación posterior de pronósticos y detección de deriva: los días de fuente ausente o de cierre NO son ceros observados.

Defecto MEDIUM de la revisión: `evaluation/actuals.py` tomaba `actual = units.fillna(0)` y solo censuraba los quiebres, así que una caída de
la integración (o un cierre) se leía como una demanda cero y producía un WAPE/sesgo realizados falsos y una alerta `forecast_drift` falsa.
Ahora esos días se clasifican con `profiling.days.classify_days` (la misma regla del entrenamiento), se marcan censurados y se cuentan por clase.

Los pronósticos «publicados» se fabrican directamente en `forecast_values` (constantes e iguales a la demanda real, de modo que cualquier error
realizado proviene de contar ceros falsos). Todo dentro de una transacción que se revierte; datos ficticios.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import date, timedelta

import pytest
from asof_helpers import World, add_document, make_world
from sqlalchemy import text

from predictily_engine.db.repository import TenantRepository
from predictily_engine.evaluation.actuals import _evaluate

pytestmark = pytest.mark.integration

D0 = date(2026, 3, 2)
CUTOFF, LAST = 59, 119          # el pronóstico publicado cubre los días 60 a 119 (60 pasos)
YHAT = (4.0, 2.0)               # demanda real (constante) del producto 0 y del producto 1
BUCKETS = ("h1-7", "h8-14", "h15-30", "h31-60", "h61-90")


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


def seed_sales(conn, w: World, *, lines: int = 1, off: dict[int, range] | None = None) -> None:
    """Ventas constantes de cada producto en cada sucursal, `lines` líneas diarias por producto; `off[b]` = días SIN ventas de la sucursal b."""
    off = off or {}
    for d in range(0, LAST + 1):
        for b in range(len(w.branch_ids)):
            if d in off.get(b, ()):
                continue
            add_document(conn, w, number=f"S{b}-{d:04d}", sale_date=iso(d), branch=b,
                         lines=[(p, YHAT[p] / lines, 10.0) for p in (0, 1) for _ in range(lines)])


def publish_forecast(conn, w: World) -> int:
    """Ejecución de producción vigente con corte en el día CUTOFF, pronósticos diarios constantes y su backtest (WAPE 5 % por tramo)."""
    t = w.tenant_id
    run_id = int(conn.execute(text(
        "INSERT INTO forecast_runs (tenant_id, version, run_type, status, triggered_by, horizon_days, cutoff_date, options, is_current, created_at, updated_at) "
        "VALUES (:t, 1, 'production', 'completed', 'user', 90, :c, '{}'::jsonb, true, now(), now()) RETURNING id"), {"t": t, "c": iso(CUTOFF)}).scalar_one())
    conn.execute(text(
        "INSERT INTO model_evaluations (tenant_id, forecast_run_id, model_family, evaluation_type, level, level_key, is_baseline, is_selected, wape, n_obs, "
        "n_windows, created_at) VALUES (:t, :r, 'selection', 'backtest', 'horizon', :k, false, true, 0.05, 100, 3, now())"),
        [{"t": t, "r": run_id, "k": k} for k in BUCKETS])
    rows = []
    for step in range(1, LAST - CUTOFF + 1):
        ds = iso(CUTOFF + step)
        total = 0.0
        for b in w.branch_ids:
            for p_idx, p in enumerate(w.product_ids):
                total += YHAT[p_idx]
                rows.append({"t": t, "r": run_id, "k": f"series:{b}:{p}", "lvl": "series", "b": b, "p": p, "ds": ds, "s": step, "y": YHAT[p_idx]})
        rows.append({"t": t, "r": run_id, "k": "total", "lvl": "total", "b": None, "p": None, "ds": ds, "s": step, "y": total})
    conn.execute(text(
        "INSERT INTO forecast_values (tenant_id, forecast_run_id, node_key, level, branch_id, product_id, granularity, target_date, period_days, "
        "horizon_step, yhat, lo_80, hi_80) VALUES (:t, :r, :k, :lvl, :b, :p, 'day', :ds, 1, :s, :y, :y, :y)"), rows)
    return run_id


def evaluate(conn, w: World) -> dict:
    repo = TenantRepository(conn, w.tenant_id)
    repo.analytic_reads()
    return _evaluate(conn, repo, 10)


def declare_gap(conn, w: World, branch: int, first: int, last: int, basis: str = "declared") -> None:
    conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                      "VALUES (:t, 'sales', :b, :f, :e, 'integration_outage', :basis, now(), now())"),
                 {"t": w.tenant_id, "b": w.branch_ids[branch], "f": iso(first), "e": iso(last), "basis": basis})


def realized(conn, w: World, run_id: int) -> dict:
    row = conn.execute(text("SELECT wape::float8 AS wape, bias::float8 AS bias, n_obs, extra FROM model_evaluations WHERE tenant_id = :t "
                            "AND forecast_run_id = :r AND evaluation_type = 'realized' AND level = 'overall'"), {"t": w.tenant_id, "r": run_id}).mappings().one()
    return dict(row)


def censored_days(conn, w: World, run_id: int, level: str) -> set[str]:
    return {r[0].isoformat() for r in conn.execute(text(
        "SELECT target_date FROM forecast_actuals WHERE tenant_id = :t AND forecast_run_id = :r AND level = :l AND is_censored"),
        {"t": w.tenant_id, "r": run_id, "l": level})}


OUTAGE = range(90, 100)     # 10 días dentro del tramo h31-60 (30 días): quedan 20 evaluables, más que el mínimo de 14 para la deriva


def test_an_undeclared_outage_is_read_as_zero_demand_and_triggers_a_false_drift_alert(db_engine):
    """Línea base de la prueba: sin declarar ni poder inferir la caída (pocas líneas diarias), los ceros cuentan y la deriva salta. Es lo que
    el resto de las pruebas demuestran que ya no ocurre cuando la caída se declara o se infiere."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, off={0: OUTAGE})
        run_id = publish_forecast(conn, w)
        result = evaluate(conn, w)
        assert result["drift_detected"] is True
        assert result["exclusions"]["missing_source_days"] == 0 and result["exclusions"]["closed_days"] == 0
        assert realized(conn, w, run_id)["wape"] > 0.05


def test_a_declared_outage_is_missing_source_not_zero_demand_and_does_not_produce_drift(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, off={0: OUTAGE})
        declare_gap(conn, w, 0, 90, 99)
        run_id = publish_forecast(conn, w)
        result = evaluate(conn, w)
        assert result["drift_detected"] is False and all(d["realized_wape"] < d["threshold"] for d in result["drift_details"])
        run = result["evaluated_runs"][0]
        assert run["exclusions"] == {"series_days": 120, "evaluated_series_days": 100, "stockout_days": 0, "missing_source_days": 20,
                                     "missing_source_inferred_days": 0, "closed_days": 0}
        assert result["exclusions"] == run["exclusions"]
        stored = realized(conn, w, run_id)
        assert stored["wape"] == pytest.approx(0.0, abs=1e-9) and stored["bias"] == pytest.approx(0.0, abs=1e-9) and stored["n_obs"] == 100
        assert stored["extra"]["exclusions"]["missing_source_days"] == 20, "el conteo por clase queda en las métricas realizadas"
        outage = {iso(d) for d in OUTAGE}
        assert censored_days(conn, w, run_id, "series") == outage and censored_days(conn, w, run_id, "total") == outage
        # el nivel total tampoco cuenta esos días como ceros
        total = conn.execute(text("SELECT wape::float8, n_obs FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'realized' "
                                  "AND level = 'total'"), {"r": run_id}).one()
        assert total[0] == pytest.approx(0.0, abs=1e-9) and total[1] == 50
        # la reevaluación es idempotente
        again = evaluate(conn, w)
        assert again["evaluated_runs"][0]["exclusions"] == run["exclusions"] and again["drift_detected"] is False


def test_an_outage_in_a_regular_branch_is_inferred_with_the_training_rule(db_engine):
    """Sucursal con ≥ 5 líneas diarias que de pronto no registra nada: la misma regla inferida del entrenamiento la marca `missing_source`."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, lines=3, off={0: OUTAGE})            # 6 líneas diarias
        run_id = publish_forecast(conn, w)
        result = evaluate(conn, w)
        run = result["evaluated_runs"][0]
        assert run["exclusions"]["missing_source_days"] == 20 and run["exclusions"]["missing_source_inferred_days"] == 20
        assert result["drift_detected"] is False and realized(conn, w, run_id)["wape"] == pytest.approx(0.0, abs=1e-3), "cantidades redondeadas a 4 decimales"


def test_declared_closures_are_closed_days_and_are_not_zero_demand(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=2)
        seed_sales(conn, w, off={0: OUTAGE, 1: OUTAGE})
        run_id = publish_forecast(conn, w)
        assert evaluate(conn, w)["drift_detected"] is True, "sin declarar el cierre, los ceros disparan la deriva"
        conn.execute(text("INSERT INTO calendar_events (tenant_id, scope, code, name, category, starts_on, ends_on, status, known_at, created_at, updated_at) "
                          "VALUES (:t, 'tenant', 'feriado-local', 'feriado', 'closure', :s, :e, 'confirmed', now(), now(), now())"),
                     {"t": w.tenant_id, "s": iso(90), "e": iso(99)})
        result = evaluate(conn, w)
        run = result["evaluated_runs"][0]
        assert run["exclusions"]["closed_days"] == 40 and run["exclusions"]["missing_source_days"] == 0 and result["drift_detected"] is False
        assert realized(conn, w, run_id)["wape"] == pytest.approx(0.0, abs=1e-9)
        assert censored_days(conn, w, run_id, "total") == {iso(d) for d in OUTAGE}


def test_days_outside_the_declared_coverage_of_a_branch_are_missing_source_in_the_evaluation(db_engine):
    """La sucursal 1 deja de integrarse (cobertura completa declarada hasta el día 104) mientras la 2 sigue: sus días posteriores son dato
    ausente, no ceros, y los totales de esos días tampoco se miden (les falta una sucursal)."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=2)
        late = range(105, LAST + 1)
        seed_sales(conn, w, off={0: late})
        run_id = publish_forecast(conn, w)
        assert evaluate(conn, w)["drift_detected"] is True
        conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, branch_id, complete_from, complete_through, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :f, :c, 'declared', now(), now())"), {"t": w.tenant_id, "b": w.branch_ids[0], "f": iso(0), "c": iso(104)})
        result = evaluate(conn, w)
        run = result["evaluated_runs"][0]
        assert run["exclusions"]["missing_source_days"] == 2 * len(late) and result["drift_detected"] is False
        assert realized(conn, w, run_id)["wape"] == pytest.approx(0.0, abs=1e-9)
        assert censored_days(conn, w, run_id, "total") == {iso(d) for d in late}


def test_stockouts_and_excluded_days_are_counted_apart_and_a_real_deterioration_is_still_detected(db_engine):
    """Los quiebres siguen contándose aparte y la exclusión no enmascara un deterioro real: si el pronóstico falla en los días evaluables, la
    alerta de deriva sigue saltando aunque haya una caída declarada."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, off={0: OUTAGE})
        declare_gap(conn, w, 0, 90, 99)
        # quiebre de stock del producto 0 los días 70 y 71: el motor lo censura como siempre
        for d in (70, 71):
            conn.execute(text("INSERT INTO inventory_snapshots (tenant_id, branch_id, product_id, snapshot_date, on_hand, created_at, updated_at) "
                              "VALUES (:t, :b, :p, :d, 0, now(), now())"), {"t": w.tenant_id, "b": w.branch_ids[0], "p": w.product_ids[0], "d": iso(d)})
        publish_forecast(conn, w)
        # deterioro REAL: desde el día 100 se vende el doble de lo pronosticado
        for d in range(100, LAST + 1):
            add_document(conn, w, number=f"X-{d}", sale_date=iso(d), lines=[(0, 8, 10.0), (1, 4, 10.0)])
        result = evaluate(conn, w)
        exclusions = result["evaluated_runs"][0]["exclusions"]
        assert exclusions["missing_source_days"] == 20 and exclusions["stockout_days"] == 3, "días 70, 71 y 72 (el día posterior a un quiebre también empieza sin stock)"
        assert result["drift_detected"] is True, "el deterioro real de los días evaluables no se enmascara"
        buckets = {d["bucket"]: d for d in result["drift_details"]}
        assert buckets["h31-60"]["realized_wape"] > buckets["h31-60"]["threshold"]
