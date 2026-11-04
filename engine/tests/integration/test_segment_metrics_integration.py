"""W3-A · integración PostgreSQL de las métricas por horizonte y segmento: ejecución real por la API, persistencia en `model_evaluations`
(level = 'segment'), resumen en `forecast_runs.summary`, consumidores existentes sin cambios y aislamiento por empresa.
Datos FICTICIOS (empresa sintética): se afirma persistencia y forma, no precisión."""

from __future__ import annotations

import json

import pandas as pd
import pytest
from sqlalchemy import text

from conftest import AUTH
from loader import create_run, load_company
from predictily_engine.pipeline.forecast_run import persist_evaluations
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration

HORIZONS = [7, 14, 15, 30, 60, 90]


@pytest.fixture(scope="module")
def world(client, db_engine):
    company = generate_company("ferreteria_andina", seed=42, n_products=10, start="2025-05-01")
    with db_engine.begin() as conn:
        tenant = load_company(conn, company, "Empresa W3-A (prueba ficticia)")
        other = load_company(conn, generate_company("distribuidora_costa", seed=42, n_products=8, start="2025-09-01"),
                             "Empresa W3-A otra (prueba ficticia)")
        run_id, job_id = create_run(conn, tenant)
    r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                    json={"tenant_id": tenant, "training_job_id": job_id, "horizons": HORIZONS})
    assert r.status_code == 200, r.text
    return {"tenant": tenant, "other": other, "run": run_id}


def _rows(engine, tenant, run, where: str = "", params: dict | None = None) -> pd.DataFrame:
    with engine.connect() as conn:
        return pd.read_sql(text(f"SELECT * FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r {where}"), conn,
                           params={"t": tenant, "r": run, **(params or {})})


def test_segment_rows_are_persisted_with_the_contract_of_the_table(db_engine, world):
    seg = _rows(db_engine, world["tenant"], world["run"], "AND level = 'segment'")
    assert len(seg) > 500 and set(seg["evaluation_type"]) == {"backtest", "selection_windows"} and set(seg["core"]) == {"demand"}
    assert set(seg["horizon_days"].astype(int)) == set(HORIZONS)
    assert seg["level_key"].str.match(r"^(demand_class|category|branch|volume_tier|series_age):[A-Za-z0-9_]+$").all()
    sel = seg[(seg["evaluation_type"] == "backtest") & (seg["model_family"] == "selection") & seg["level_key"].str.startswith("branch:")]
    assert len(sel) >= len(HORIZONS) and sel["is_selected"].all()
    row = seg[(seg["evaluation_type"] == "backtest") & (seg["model_family"] == "selection") & (seg["horizon_days"] == 30)
              & (seg["level_key"] == "series_age:mature")].iloc[0]
    m = row["metrics"] if isinstance(row["metrics"], dict) else json.loads(row["metrics"])
    assert m["segment_metrics_version"] == "demand-segment-metrics-v1" and m["status"] == "ok" and m["denominator"]["sum_actual"] > 0
    assert float(row["wape"]) == pytest.approx(m["wape"], abs=1e-6), "la columna y el jsonb dicen lo mismo (la columna redondea a 6 decimales)"
    assert int(row["n_obs"]) == m["sample"]["evaluable_days"] and m["comparison"]["selected_vs_baseline"]["verdict"] in ("better", "similar", "worse")
    extra = row["extra"] if isinstance(row["extra"], dict) else json.loads(row["extra"])
    assert extra["evaluation_scope"] == "holdout" and extra["segment_dimension"] == "series_age" and extra["composite"] is True
    insufficient = seg[seg["wape"].isna() & (seg["evaluation_type"] == "backtest")]
    for _, r in insufficient.head(5).iterrows():
        mm = r["metrics"] if isinstance(r["metrics"], dict) else json.loads(r["metrics"])
        assert mm["status"] == "insufficient_data" or mm["wape_unstable"] is True, "un WAPE nulo siempre tiene su motivo"


def test_the_summary_of_the_run_carries_the_segment_metrics_without_touching_the_existing_keys(db_engine, world):
    with db_engine.connect() as conn:
        summary = conn.execute(text("SELECT summary FROM forecast_runs WHERE id = :r AND tenant_id = :t"),
                               {"r": world["run"], "t": world["tenant"]}).scalar_one()
    summary = summary if isinstance(summary, dict) else json.loads(summary)
    block = summary["segment_metrics"]
    assert block["enabled"] is True and block["scope"] == "holdout" and block["counts"]["cells"] > 30
    for key in ("metrics", "models", "horizon_support", "horizon_totals", "quality_status", "p50", "served", "backtest"):
        assert key in summary, key
    assert len(json.dumps(block)) < 8000 and "segments" not in block, "el resumen es compacto: el detalle está en model_evaluations"
    assert block["detail"]["table"] == "model_evaluations" and block["attention_total"] == sum(block["counts"]["by_reason"].values())
    with db_engine.connect() as conn:
        manifest = conn.execute(text("SELECT evaluation_manifest FROM forecast_runs WHERE id = :r AND tenant_id = :t"),
                                {"r": world["run"], "t": world["tenant"]}).scalar_one()
    manifest = manifest if isinstance(manifest, dict) else json.loads(manifest)
    assert manifest["segment_metrics"]["enabled"] is True and manifest["segment_metrics"]["thresholds"]["wape_min_denominator"] == 30.0
    assert block["definitions_ref"] == "forecast_runs.evaluation_manifest.segment_metrics"
    assert summary["metrics"]["selected_wape"] is not None and summary["metrics"]["evaluation_scope"] == "holdout"


def test_the_existing_consumer_queries_return_exactly_the_same_rows_as_before(db_engine, world):
    """Los consumidores del backend filtran por nivel explícito: las filas `segment` no se mezclan en sus resultados."""
    consumers = [
        ("evaluation_type = 'backtest' AND level IN ('overall', 'horizon', 'horizon_total', 'demand_class', 'branch')", None),
        ("evaluation_type = 'backtest' AND level = 'overall' AND is_selected", 1),
        ("evaluation_type = 'selection_windows' AND level = 'overall'", None),
        ("evaluation_type = 'backtest' AND level = 'horizon' AND is_selected", 5),
    ]
    all_rows = _rows(db_engine, world["tenant"], world["run"])
    assert (all_rows["level"] == "segment").sum() > 0
    for where, expected in consumers:
        got = _rows(db_engine, world["tenant"], world["run"], f"AND {where}")
        assert not (got["level"] == "segment").any()
        if expected is not None:
            assert len(got) == expected
    # lo que ya existía sigue ahí: las 6 filas «horizon_total» del servido
    ht = _rows(db_engine, world["tenant"], world["run"], "AND level = 'horizon_total' AND evaluation_type = 'backtest' AND model_family = 'selection'")
    assert sorted(ht["horizon_days"].astype(int)) == HORIZONS


def test_segment_rows_belong_to_their_company_only(db_engine, world):
    with db_engine.connect() as conn:
        n = conn.execute(text("SELECT count(*) FROM model_evaluations WHERE tenant_id = :t AND level = 'segment'"), {"t": world["other"]}).scalar_one()
        own = conn.execute(text("SELECT count(*) FROM model_evaluations WHERE tenant_id = :t AND level = 'segment' AND forecast_run_id = :r"),
                           {"t": world["tenant"], "r": world["run"]}).scalar_one()
    assert n == 0 and own > 500


def test_persist_evaluations_stores_an_overflowing_value_as_null_instead_of_aborting(db_engine, world):
    row = {"model_family": "seasonal_naive", "evaluation_type": "backtest", "level": "segment", "level_key": "branch:b99", "horizon_days": 7,
           "is_baseline": True, "is_selected": False, "wape": 7_500_000.0, "mae": 3.5, "rmse": float("nan"), "bias": -2_000_000.0,
           "coverage_80": 0.8, "coverage_95": 0.9, "n_obs": 12, "n_windows": 1, "cutoffs": ["2026-01-01"],
           "metrics": {"status": "ok"}, "extra": {"evaluation_scope": "holdout"}}
    with db_engine.begin() as conn:
        assert persist_evaluations(conn, world["tenant"], world["run"], [row], {}) == 1
        assert persist_evaluations(conn, world["tenant"], world["run"], [], {}) == 0
        got = conn.execute(text("SELECT wape, bias, rmse, mae, extra FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r "
                                "AND level_key = 'branch:b99'"), {"t": world["tenant"], "r": world["run"]}).mappings().one()
        conn.execute(text("DELETE FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r AND level_key = 'branch:b99'"),
                     {"t": world["tenant"], "r": world["run"]})
    extra = got["extra"] if isinstance(got["extra"], dict) else json.loads(got["extra"])
    assert got["wape"] is None and got["bias"] is None and got["rmse"] is None and float(got["mae"]) == 3.5
    assert sorted(extra["column_overflow"]) == ["bias", "wape"] and extra["composite"] is False
