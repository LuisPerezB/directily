"""Integración PostgreSQL de la demanda (etapa 2 · P1): horizontes 7/14/30/60/90, huella, configuración,
manifiesto, cobertura de fuentes, eventos extraordinarios, registro de modelos y modelo servido coherente."""

from __future__ import annotations

import json

import pandas as pd
import pytest
from sqlalchemy import text

from conftest import AUTH
from loader import create_run, load_company
from predictily_engine.demand.manifest import verify
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration

HORIZONS = [7, 14, 30, 60, 90]


def _execute(client, run_id, tenant_id, job_id, **extra):
    r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                    json={"tenant_id": tenant_id, "training_job_id": job_id, "horizons": HORIZONS, **extra})
    assert r.status_code == 200, r.text
    return r.json()


def _run(client, engine, tenant_id, **extra):
    with engine.begin() as conn:
        run_id, job_id = create_run(conn, tenant_id)
    body = _execute(client, run_id, tenant_id, job_id, **extra)
    return run_id, body


def _event(conn, tenant_id, code, scope, ref, starts, ends, known_at, category="exceptional"):
    conn.execute(text(
        "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, "
        "known_at, source, revision, created_at, updated_at) VALUES (:t, :scope, :ref, :code, :code, :cat, :s, :e, "
        "'confirmed', :k, 'test', 0, now(), now())"),
        {"t": tenant_id, "scope": scope, "ref": ref, "code": code, "cat": category, "s": starts, "e": ends, "k": known_at})


@pytest.fixture(scope="module")
def world(client, db_engine, tenants):
    """Empresa C (cobertura declarada + eventos), empresa D (sin cobertura) y cuatro ejecuciones."""
    c_co = generate_company("ferreteria_andina", seed=42, n_products=8, start="2025-06-01")
    d_co = generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01")
    with db_engine.begin() as conn:
        c = load_company(conn, c_co, "Empresa C (prueba ficticia, cobertura)")
        d = load_company(conn, d_co, "Empresa D (prueba ficticia, sin cobertura)")
        last_c = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": c}).scalar_one()
        last_d = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": d}).scalar_one()
        through = pd.Timestamp(last_c) - pd.Timedelta(days=20)
        conn.execute(text(
            "INSERT INTO data_coverage (tenant_id, entity, complete_through, basis, created_at, updated_at) "
            "VALUES (:t, 'sales', :through, 'declared', now(), now())"), {"t": c, "through": through.date()})
        # Cobertura MÁS restrictiva de otra empresa: no debe afectar a D (aislamiento).
        conn.execute(text(
            "INSERT INTO data_coverage (tenant_id, entity, complete_through, basis, created_at, updated_at) "
            "VALUES (:t, 'sales', '2025-07-01', 'declared', now(), now())"), {"t": tenants["B"]})
        t = through
        _event(conn, c, "EVENTO_C", "tenant", None, (t + pd.Timedelta(days=10)).date(), (t + pd.Timedelta(days=14)).date(),
               t - pd.Timedelta(days=5))
        _event(conn, c, "SIN_FECHA_C", "tenant", None, (t + pd.Timedelta(days=20)).date(), (t + pd.Timedelta(days=22)).date(),
               None)
        _event(conn, d, "EVENTO_D", "tenant", None, (pd.Timestamp(last_d) + pd.Timedelta(days=5)).date(),
               (pd.Timestamp(last_d) + pd.Timedelta(days=8)).date(), pd.Timestamp(last_d) - pd.Timedelta(days=2))
        conn.execute(text(
            "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, "
            "known_at, source, revision, created_at, updated_at) VALUES (NULL, 'country', 'EC', 'PUBLICO', 'PUBLICO', "
            "'exceptional', :s, :e, 'confirmed', :k, 'test', 0, now(), now())"),
            {"s": (pd.Timestamp(last_d) + pd.Timedelta(days=12)).date(), "e": (pd.Timestamp(last_d) + pd.Timedelta(days=13)).date(),
             "k": pd.Timestamp(last_d) - pd.Timedelta(days=1)})
    out = {"c": c, "d": d, "through": through.date(), "last_c": last_c, "last_d": last_d}
    out["r1"], out["b1"] = _run(client, db_engine, c)
    # Ventas POSTERIORES a la cobertura cambian muchísimo: no pueden influir en un corte limitado por ella.
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE sale_items SET quantity = quantity * 7 WHERE tenant_id = :t AND sale_date > :d"),
                     {"t": c, "d": through.date()})
    out["r2"], out["b2"] = _run(client, db_engine, c)
    out["r3"], out["b3"] = _run(client, db_engine, d)
    return out


def _fv(engine, run_id) -> pd.DataFrame:
    with engine.connect() as conn:
        return pd.read_sql(text("SELECT * FROM forecast_values WHERE forecast_run_id = :r"), conn, params={"r": run_id})


def test_five_horizons_are_written_with_a_status_on_every_row(world, db_engine):
    fv = _fv(db_engine, world["r3"])
    hz = fv[(fv["node_key"] == "total") & (fv["granularity"] == "horizon")].sort_values("period_days")
    assert hz["period_days"].tolist() == HORIZONS
    assert (hz["quality_status"] == "ok").all() and hz[["lo_80", "hi_80", "lo_95", "hi_95"]].notna().all().all()
    assert fv["quality_status"].notna().all() and set(fv["quality_status"]) <= {"ok", "degraded", "insufficient_data"}
    assert fv["p50"].isna().all() or fv.loc[fv["p50"].notna(), "level"].eq("series").all()


def test_run_records_config_snapshot_manifest_and_envelope_fields(world, db_engine):
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT * FROM forecast_runs WHERE id = :r"), {"r": world["r3"]}).mappings().one()
        snap = conn.execute(text("SELECT * FROM data_snapshots WHERE id = :s"), {"s": run["data_snapshot_id"]}).mappings().one()
    assert run["config_version"] and len(run["config_version"]) == 64
    assert run["effective_config"]["config"]["horizons"] == HORIZONS
    assert run["effective_config"]["run"]["seed"] == 42 and run["effective_config"]["run"]["horizons"] == HORIZONS
    assert run["summary"]["config_version"] == run["config_version"]
    assert snap["tenant_id"] == world["d"] and snap["core"] == "demand" and snap["hash"] == run["summary"]["data_snapshot_hash"]
    assert {"sales", "returns", "inventory", "prices", "costs", "promotions", "calendar", "config", "eligibility"} \
        <= set(snap["components"])
    manifest = run["evaluation_manifest"]
    assert manifest["holdout"]["independent"] is True and verify(manifest) == []
    assert manifest["config_version"] == run["config_version"]
    body = world["b3"]
    assert body["quality_status"] == "ok" and set(body["horizon_support"]) == {"7", "14", "30", "60", "90"}


def test_metrics_json_and_horizon_total_rows_are_persisted(world, db_engine):
    with db_engine.connect() as conn:
        rows = conn.execute(text(
            "SELECT level, level_key, horizon_days, metrics, core FROM model_evaluations WHERE forecast_run_id = :r "
            "AND evaluation_type = 'backtest' AND model_family = 'selection'"), {"r": world["r3"]}).mappings().all()
    overall = next(r for r in rows if r["level"] == "overall")
    m = overall["metrics"]
    assert overall["core"] == "demand" and m["mase"] > 0 and m["rmsse"] > 0 and m["money"]["currency"] == "USD"
    assert m["pinball_p10"] is not None and m["interval_width_80"] > 0 and m["sample"]["n_obs"] > 0
    assert {r["horizon_days"] for r in rows if r["level"] == "horizon_total"} == set(HORIZONS)


def test_registry_states_roles_core_and_compat(world, db_engine):
    with db_engine.connect() as conn:
        reg = conn.execute(text("SELECT model_family, status, role, core, validated_at, artifact_compat, artifact_path, "
                                "artifact_sha256 FROM model_registry WHERE forecast_run_id = :r"),
                           {"r": world["r3"]}).mappings().all()
    assert {r["core"] for r in reg} == {"demand"}
    assert {r["status"] for r in reg} <= {"candidate", "validated", "production", "failed"}
    prod = [r for r in reg if r["status"] == "production"]
    assert prod and {r["role"] for r in prod} <= {"primary", "fallback"} and any(r["role"] == "primary" for r in prod)
    assert all(r["validated_at"] is not None for r in prod), "un modelo en producción fue evaluado con el protocolo"
    assert all(r["artifact_compat"]["input_schema"] == "demand-panel-v1" for r in reg)
    lgbm = [r for r in reg if r["model_family"] == "lightgbm_global" and r["status"] == "production"]
    for r in lgbm:
        assert r["artifact_path"] and len(r["artifact_sha256"]) == 64 and r["artifact_compat"]["artifact_kind"] == "mlforecast_joblib"


def test_declared_coverage_limits_the_cutoff_and_what_follows_is_absent_not_zero(world, db_engine):
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT cutoff_date, summary FROM forecast_runs WHERE id = :r"), {"r": world["r1"]}).mappings().one()
    assert run["cutoff_date"] == world["through"] and world["through"] < world["last_c"]
    cov = run["summary"]["coverage"]["sales"]
    assert cov["declared"] and cov["cutoff_limited"] and cov["complete_through"] == world["through"].isoformat()
    codes = run["summary"]["limitation_codes"]
    assert "cutoff_limited_by_coverage" in codes and "coverage_undeclared" not in codes
    fv = _fv(db_engine, world["r1"])
    day = fv[fv["granularity"] == "day"]
    assert pd.to_datetime(day["target_date"]).min().date() == (pd.Timestamp(world["through"]) + pd.Timedelta(days=1)).date()
    with db_engine.connect() as conn:
        used = conn.execute(text("SELECT MAX(sale_date) FROM sale_items WHERE tenant_id = :t AND sale_date <= :d"),
                            {"t": world["c"], "d": world["through"]}).scalar_one()
    assert used <= world["through"]


def test_sales_after_the_declared_coverage_do_not_change_the_result_and_the_run_is_reproducible(world, db_engine):
    a, b = _fv(db_engine, world["r1"]), _fv(db_engine, world["r2"])
    key = ["node_key", "granularity", "target_date"]
    m = a.merge(b, on=key, suffixes=("_1", "_2"), validate="one_to_one")
    assert len(m) == len(a) == len(b)
    for col in ("yhat", "lo_80", "hi_80", "lo_95", "hi_95", "revenue_yhat"):
        pd.testing.assert_series_equal(m[f"{col}_1"].astype(float), m[f"{col}_2"].astype(float), check_names=False)
    assert (m["model_family_1"].fillna("") == m["model_family_2"].fillna("")).all()
    with db_engine.connect() as conn:
        rows = conn.execute(text("SELECT id, data_snapshot_id, config_version FROM forecast_runs WHERE id IN (:a, :b) ORDER BY id"),
                            {"a": world["r1"], "b": world["r2"]}).mappings().all()
    assert rows[0]["data_snapshot_id"] == rows[1]["data_snapshot_id"] and rows[0]["config_version"] == rows[1]["config_version"]
    # La repetición retira los modelos de producción de la corrida anterior (siguen disponibles para revertir).
    with db_engine.connect() as conn:
        old = set(conn.execute(text("SELECT DISTINCT status FROM model_registry WHERE forecast_run_id = :r"),
                                {"r": world["r1"]}).scalars())
        new = set(conn.execute(text("SELECT DISTINCT status FROM model_registry WHERE forecast_run_id = :r"),
                                {"r": world["r2"]}).scalars())
        current = conn.execute(text("SELECT id FROM forecast_runs WHERE tenant_id = :t AND is_current"), {"t": world["c"]}).scalars().all()
    assert "production" not in old and "retired" in old and "production" in new and current == [world["r2"]]


def test_a_company_without_declared_coverage_keeps_old_behavior_and_says_so(world, db_engine):
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT cutoff_date, summary FROM forecast_runs WHERE id = :r"), {"r": world["r3"]}).mappings().one()
    assert run["cutoff_date"] == world["last_d"], "la cobertura restrictiva de otra empresa no se aplicó"
    assert "coverage_undeclared" in run["summary"]["limitation_codes"]
    assert run["summary"]["coverage"]["sales"]["declared"] is False


def test_exceptional_events_are_flagged_only_when_known_and_only_for_the_company(world, db_engine):
    fc = _fv(db_engine, world["r1"])
    flagged = fc[(fc["level"] == "series") & (fc["case_label"] == "exceptional_event")]
    dates = pd.to_datetime(flagged["target_date"]).dt.date
    t = world["through"]
    assert set(dates) == {(pd.Timestamp(t) + pd.Timedelta(days=k)).date() for k in range(10, 15)}, \
        "solo EVENTO_C; SIN_FECHA_C (known_at nulo) no se usa y EVENTO_D es de otra empresa"
    assert (flagged["quality_status"] == "degraded").all()
    with db_engine.connect() as conn:
        s1 = conn.execute(text("SELECT summary FROM forecast_runs WHERE id = :r"), {"r": world["r1"]}).scalar_one()
        s3 = conn.execute(text("SELECT summary FROM forecast_runs WHERE id = :r"), {"r": world["r3"]}).scalar_one()
    used1 = {e["code"] for e in s1["special_cases"]["exceptional_events"]["used"]}
    used3 = {e["code"] for e in s3["special_cases"]["exceptional_events"]["used"]}
    assert used1 == {"EVENTO_C"} and s1["special_cases"]["exceptional_events"]["stats"]["known_at_unknown_ignored"] == 1
    assert used3 == {"EVENTO_D", "PUBLICO"}, "los eventos públicos (sin empresa) aplican a todas; los de otra empresa no"


def test_forced_final_failure_serves_the_fallback_and_persists_coherent_registry_and_metrics(world, client, db_engine):
    import predictily_engine.pipeline.core as core
    from predictily_engine.models.ml import LightGBMGlobal

    final = pd.Timestamp(world["last_d"])
    with db_engine.connect() as conn:
        normal = conn.execute(text("SELECT selection FROM forecast_runs WHERE id = :r"), {"r": world["r3"]}).scalar_one()
    assert any(v.get("model_family") == "lightgbm_global" for k, v in normal.items() if k not in ("fallback",)
               and isinstance(v, dict)), "precondición: LightGBM gana alguna clase en la ejecución normal"

    class FailingFinalFit(LightGBMGlobal):
        def fit(self, train):
            if train["ds"].max() >= final:
                raise RuntimeError("fallo forzado del entrenamiento final")
            return super().fit(train)

    mp = pytest.MonkeyPatch()
    mp.setattr(core, "LightGBMGlobal", FailingFinalFit)
    try:
        run_id, body = _run(client, db_engine, world["d"])
    finally:
        mp.undo()
    with db_engine.connect() as conn:
        run = conn.execute(text("SELECT selection, summary, evaluation_manifest FROM forecast_runs WHERE id = :r"),
                           {"r": run_id}).mappings().one()
        reg = {r["model_family"]: r for r in conn.execute(text(
            "SELECT model_family, status, role, notes, series_count, artifact_path FROM model_registry WHERE forecast_run_id = :r"),
            {"r": run_id}).mappings()}
        fams = set(conn.execute(text("SELECT DISTINCT model_family FROM forecast_values WHERE forecast_run_id = :r "
                                     "AND model_family IS NOT NULL"), {"r": run_id}).scalars())
        previous = set(conn.execute(text("SELECT DISTINCT status FROM model_registry WHERE forecast_run_id = :r"),
                                    {"r": world["r3"]}).scalars())
    assert body["quality_status"] == "degraded"
    assert reg["lightgbm_global"]["status"] == "failed" and "fallo forzado" in reg["lightgbm_global"]["notes"]
    assert reg["lightgbm_global"]["artifact_path"] is None
    fb = run["selection"]["fallback"]["model_family"]
    assert reg[fb]["status"] == "production" and reg[fb]["series_count"] > 0
    assert "lightgbm_global" not in fams
    replaced = {c: v for c, v in run["selection"].items() if isinstance(v, dict) and v.get("fallback_applied")}
    assert replaced and all(v["planned_family"] == "lightgbm_global" and v["model_family"] == fb for v in replaced.values())
    assert run["summary"]["served"]["fallback_used"] is True
    assert run["evaluation_manifest"]["served"]["failed_families"].keys() == {"lightgbm_global"}
    assert "fallback_served" in run["summary"]["limitation_codes"]
    assert previous == {"retired"} or "production" not in previous
    with db_engine.connect() as conn:
        ev = conn.execute(text("SELECT metrics FROM model_evaluations WHERE forecast_run_id = :r AND level = 'overall' "
                               "AND model_family = 'selection' AND evaluation_type = 'backtest'"), {"r": run_id}).scalar_one()
    assert ev["mase"] is not None and json.dumps(ev)


def test_tenant_scoped_reads_never_see_other_companys_coverage_or_events(world, db_engine):
    from predictily_engine.db.repository import TenantRepository
    from predictily_engine.extensions.data_core import load_calendar_events

    with db_engine.connect() as conn:
        repo_d = TenantRepository(conn, world["d"])
        assert repo_d.sales_coverage().empty
        wide = (pd.Timestamp("2025-01-01").date(), pd.Timestamp("2028-01-01").date())
        events = load_calendar_events(conn, world["d"], *wide)
        assert set(events["code"]) == {"EVENTO_D", "PUBLICO"}
        repo_c = TenantRepository(conn, world["c"])
        assert set(load_calendar_events(conn, world["c"], *wide)["code"]) == {"EVENTO_C", "SIN_FECHA_C", "PUBLICO"}
        # Con corte, el lector del core de Datos no devuelve lo que no se podía demostrar conocido (known_at nulo).
        known = load_calendar_events(conn, world["c"], *wide, as_of=pd.Timestamp(world["through"]).date())
        assert set(known["code"]) == {"EVENTO_C", "PUBLICO"} or set(known["code"]) == {"EVENTO_C"}
        assert len(repo_c.sales_coverage()) == 1


def test_invalid_company_configuration_is_rejected_before_running(world, client, db_engine):
    with db_engine.begin() as conn:
        conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"),
                     {"s": json.dumps({"cores": {"demand": {"inventa_algo": 1}}}), "t": world["d"]})
        run_id, job_id = create_run(conn, world["d"])
    try:
        r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                        json={"tenant_id": world["d"], "training_job_id": job_id, "horizons": HORIZONS})
        assert r.status_code == 422 and r.json()["code"] == "invalid_configuration"
        assert "inventa_algo" in r.json()["message"]
        with db_engine.connect() as conn:
            row = conn.execute(text("SELECT status, error_message FROM forecast_runs WHERE id = :r"), {"r": run_id}).one()
        assert row[0] == "failed" and "inventa_algo" in row[1]
    finally:
        with db_engine.begin() as conn:
            conn.execute(text("UPDATE tenants SET settings = '{}'::jsonb WHERE id = :t"), {"t": world["d"]})


def test_coverage_resolution_uses_the_data_core_rules_and_ignores_closed_branches(world, db_engine):
    """`SalesCoverage.resolve` = cobertura efectiva del core de Datos: la declaración del usuario manda; una sucursal con
    cobertura propia más atrasada limita el corte, salvo que esté cerrada (desactivada)."""
    from predictily_engine.db.repository import TenantRepository
    from predictily_engine.demand.coverage import SalesCoverage

    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            branch = conn.execute(text("SELECT MIN(id) FROM branches WHERE tenant_id = :t"), {"t": world["c"]}).scalar_one()
            conn.execute(text("INSERT INTO data_coverage (tenant_id, entity, branch_id, complete_through, basis, created_at, "
                              "updated_at) VALUES (:t, 'sales', :b, '2026-01-15', 'declared', now(), now())"),
                         {"t": world["c"], "b": branch})
            rows = TenantRepository(conn, world["c"]).sales_coverage()
            lagging = SalesCoverage.resolve(conn, world["c"], rows, None)
            assert lagging.limit_cutoff(world["last_c"]) == date_(2026, 1, 15)
            closed = SalesCoverage.resolve(conn, world["c"], rows, active_branches=set())
            assert closed.limit_cutoff(world["last_c"]) == world["through"], "sin la sucursal cerrada vale la cobertura de la empresa"
            assert closed.as_dict()["sales"]["rule"] == "declared_by_user"
            # Otra empresa nunca se ve afectada por estas filas.
            other = SalesCoverage.resolve(conn, world["d"], TenantRepository(conn, world["d"]).sales_coverage(), None)
            assert other.declared is False and other.rule == "none"
        finally:
            tx.rollback()


def date_(y, m, d):
    from datetime import date

    return date(y, m, d)


def test_writer_omits_stage2_columns_when_the_schema_has_not_been_migrated_yet(world, db_engine):
    """Compatibilidad entre paquetes: sin la migración 2026_02_05 el motor escribe igual (sin P50, estado ni etiqueta)."""
    from predictily_engine.db import writer

    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            run_id, _ = create_run(conn, world["d"])
            full = writer.table_columns(conn, "forecast_values")
            assert set(writer.FV_STAGE2_COLUMNS) <= full
            key = (conn.engine.url.render_as_string(hide_password=True), "forecast_values")
            writer._COLUMN_CACHE[key] = frozenset(full - set(writer.FV_STAGE2_COLUMNS))
            fv = pd.DataFrame([{
                "node_key": "total", "level": "total", "branch_id": None, "product_id": None, "category_id": None,
                "granularity": "day", "target_date": pd.Timestamp("2030-01-01"), "period_days": 1, "horizon_step": 1,
                "yhat": 1.0, "lo_80": 0.5, "hi_80": 1.5, "lo_95": 0.2, "hi_95": 2.0, "p50": 0.9, "model_family": None,
                "unit_price_assumed": None, "revenue_yhat": 2.0, "quality_status": "ok", "case_label": "x"}])
            assert writer.copy_forecast_values(conn, world["d"], run_id, fv, {}) == 1
            row = conn.execute(text("SELECT p50, quality_status, case_label FROM forecast_values WHERE forecast_run_id = :r"),
                               {"r": run_id}).one()
            assert tuple(row) == (None, None, None)
        finally:
            writer._COLUMN_CACHE.pop(key, None)
            tx.rollback()
