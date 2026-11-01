"""Recalcula en memoria las métricas de la demo de demanda (datos FICTICIOS, semilla 42) con el protocolo de la
etapa 2: horizontes 7/14/30/60/90, MASE/RMSSE, pinball, amplitud de intervalos, dinero y P50.

Uso: .venv/bin/python scripts/benchmark_demand_v2.py [ferreteria_andina|distribuidora_costa] [salida.json]
No usa base de datos. Ver docs/architecture/v2/P1-demanda.md §9.
"""

from __future__ import annotations

import json
import sys
import time
import warnings

warnings.filterwarnings("ignore")

from predictily_engine.pipeline.core import run_pipeline  # noqa: E402
from predictily_engine.pipeline.data import tenant_data_from_synthetic  # noqa: E402
from predictily_engine.synthetic import generate_company  # noqa: E402


def main(slug: str, out_path: str | None) -> None:
    t0 = time.perf_counter()
    company = generate_company(slug, seed=42)
    data = tenant_data_from_synthetic(company)
    res = run_pipeline(data, horizon=90, seed=42, n_jobs=2)
    keep = [e for e in res.evaluations if e["evaluation_type"] in ("backtest", "selection_windows")
            and e["model_family"] in ("selection", "seasonal_naive")
            and e["level"] in ("overall", "total", "horizon", "horizon_total", "demand_class")]
    out = {
        "company": slug, "data_kind": "synthetic_generated_seed_42", "cutoff": str(res.cutoff),
        "series": int(len(res.panel.profile)), "seconds": round(time.perf_counter() - t0, 1),
        "metrics": res.summary["metrics"], "models": res.summary["models"],
        "horizon_support": res.summary["horizon_support"], "p50": res.summary["p50"],
        "quality_status": res.summary["quality_status"], "evaluations": keep,
        "manifest": res.manifest, "config_version": res.config_version, "snapshot_hash": res.snapshot.hash,
    }
    text = json.dumps(out, ensure_ascii=False, indent=2, default=str)
    if out_path:
        open(out_path, "w", encoding="utf-8").write(text)
    print(text[:3000])


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "ferreteria_andina", sys.argv[2] if len(sys.argv) > 2 else None)
