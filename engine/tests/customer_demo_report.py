"""Evaluación del Customer Core sobre la demo FICTICIA Ferretería Andina (datos sintéticos de `predictily_engine.synthetic`).

    cd engine && ENGINE_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test_p2 \
        .venv/bin/python tests/customer_demo_report.py --out ../docs/architecture/v2/P2-demo-ferreteria-andina.json

Carga la demo en la BD indicada (usar una BD de PRUEBAS: se vacía `tenants`), entrena y predice, y escribe las métricas del modelo
frente a la referencia por horizonte. Los compradores de la demo se asignan al azar a cada factura (no hay recompra real por
comprador): las métricas son las de un procedimiento sobre datos ficticios, NO una validación comercial.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
os.environ.setdefault("ENGINE_ARTIFACTS_DIR", tempfile.mkdtemp(prefix="predictily-demo-artifacts-"))

from sqlalchemy import text  # noqa: E402

from customer_fixtures import attach_demo_customers, create_core_run  # noqa: E402
from loader import load_company  # noqa: E402
from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run  # noqa: E402
from predictily_engine.db.session import get_engine  # noqa: E402
from predictily_engine.synthetic import generate_company  # noqa: E402


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="p2-demo-ferreteria-andina.json")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--products", type=int, default=None, help="número de productos (por defecto, los de la demo completa)")
    args = ap.parse_args()
    engine = get_engine()
    t0 = time.perf_counter()
    company = generate_company("ferreteria_andina", seed=args.seed, n_products=args.products)
    with engine.begin() as conn:
        conn.execute(text("TRUNCATE tenants RESTART IDENTITY CASCADE"))
        tenant_id = load_company(conn, company, "Ferretería Andina (Demo ficticia · P2)")
        attach_demo_customers(conn, tenant_id, company)
    load_s = time.perf_counter() - t0
    report: dict = {"data": "FICTICIO · demo Ferretería Andina (predictily_engine.synthetic)", "seed": args.seed, "load_seconds": round(load_s, 1),
                    "sales_lines": int(len(company.sales)), "products": int(len(company.products))}
    last = str(company.sales["issued_at"].max())[:10]
    for op in ("train", "predict"):
        with engine.begin() as conn:
            run_id, job_id = create_core_run(conn, tenant_id, op, as_of=last if op == "predict" else None)
        t1 = time.perf_counter()
        out = execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))
        report[op] = {"seconds": round(time.perf_counter() - t1, 1), "status": out["status"], "quality_status": out["quality_status"],
                      "summary": out["summary"]}
        if op == "train":
            with engine.connect() as conn:
                row = conn.execute(text("SELECT evaluation_manifest FROM core_runs WHERE id = :r"), {"r": run_id}).scalar_one()
                report["manifest"] = row
                evals = conn.execute(text(
                    "SELECT level_key, evaluation_type, model_family, is_baseline, is_selected, horizon_days, n_obs, mae, bias, wape, metrics "
                    "FROM model_evaluations WHERE core_run_id = :r ORDER BY id"), {"r": run_id}).mappings().all()
                report["evaluations"] = [dict(e) for e in evals if e["evaluation_type"] == "holdout"]
    outp = Path(args.out)
    outp.parent.mkdir(parents=True, exist_ok=True)
    outp.write_text(json.dumps(report, ensure_ascii=False, indent=1, default=str))
    print(json.dumps({h: {k: v for k, v in s.items() if k in ("status", "tier", "probability", "quantity")}
                      for h, s in report["train"]["summary"]["horizons"].items()}, ensure_ascii=False, indent=1, default=str))
    print(f"informe: {outp.resolve()}  ({time.perf_counter() - t0:.0f} s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
