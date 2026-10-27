"""Mide el pipeline de punta a punta con una empresa demo completa (ficticia).

Uso: .venv/bin/python scripts/benchmark_demo.py [ferreteria_andina|distribuidora_costa]
Requiere la BD de pruebas migrada (ENGINE_TEST_DATABASE_URL o predictily_engine_test).
Carga la empresa en una empresa nueva de esa BD, ejecuta un run de producción y otro
histórico (corte −30 días) vía HTTP (TestClient) y luego evaluate-actuals.
"""

from __future__ import annotations

import json
import os
import sys
import time
import warnings
from pathlib import Path

warnings.filterwarnings("ignore")
URL = os.environ.get("ENGINE_TEST_DATABASE_URL",
                     "postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test")
os.environ["ENGINE_DATABASE_URL"] = URL
os.environ.setdefault("ENGINE_API_TOKEN", "bench-token")
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tests"))

import pandas as pd  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402
from sqlalchemy import text  # noqa: E402

from loader import create_run, load_company  # noqa: E402
from predictily_engine.api.main import app  # noqa: E402
from predictily_engine.db.session import reset_engine  # noqa: E402
from predictily_engine.synthetic import generate_company  # noqa: E402


def main(slug: str) -> None:
    engine = reset_engine(URL)
    auth = {"Authorization": f"Bearer {os.environ['ENGINE_API_TOKEN']}"}
    client = TestClient(app)
    t = time.perf_counter()
    company = generate_company(slug, seed=42)
    with engine.begin() as conn:
        tenant = load_company(conn, company, f"{company.spec.name} [benchmark]")
        last = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": tenant}).scalar_one()
        lines = conn.execute(text("SELECT COUNT(*) FROM sale_items WHERE tenant_id = :t"), {"t": tenant}).scalar_one()
    load_s = time.perf_counter() - t
    out = {"slug": slug, "tenant_id": tenant, "sale_items": lines, "load_seconds": round(load_s, 1)}
    for run_type, cutoff in (("production", None), ("historical", (pd.Timestamp(last) - pd.Timedelta(days=30)).date())):
        with engine.begin() as conn:
            run_id, job_id = create_run(conn, tenant, run_type=run_type, cutoff=str(cutoff) if cutoff else None)
        t = time.perf_counter()
        r = client.post(f"/v1/forecast-runs/{run_id}/execute", headers=auth,
                        json={"tenant_id": tenant, "training_job_id": job_id, "horizons": [7, 30, 90]})
        elapsed = time.perf_counter() - t
        body = r.json()
        with engine.connect() as conn:
            summary = conn.execute(text("SELECT summary FROM forecast_runs WHERE id = :r"), {"r": run_id}).scalar_one()
            n_values = conn.execute(text("SELECT COUNT(*) FROM forecast_values WHERE forecast_run_id = :r"),
                                    {"r": run_id}).scalar_one()
        out[run_type] = {"http_status": r.status_code, "seconds": round(elapsed, 1), "series": body.get("series_count"),
                         "metrics": body.get("metrics"), "models": (summary or {}).get("models"),
                         "forecast_values_rows": n_values, "timings": (summary or {}).get("timings_seconds"),
                         "recommendations": (summary or {}).get("recommendations"),
                         "warnings": body.get("warnings")}
    t = time.perf_counter()
    ev = client.post(f"/v1/tenants/{tenant}/evaluate-actuals", headers=auth, json={"lookback_runs": 5}).json()
    out["evaluate_actuals"] = {"seconds": round(time.perf_counter() - t, 1), "realized": ev.get("evaluated_runs"),
                               "drift_detected": ev.get("drift_detected")}
    print(json.dumps(out, indent=2, ensure_ascii=False, default=str))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "ferreteria_andina")
