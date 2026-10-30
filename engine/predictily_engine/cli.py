"""CLI del motor: python -m predictily_engine.cli <comando>."""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path


def _cmd_generate_demo(args: argparse.Namespace) -> int:
    from predictily_engine.synthetic import generate_demo

    t0 = time.perf_counter()
    counts = generate_demo(Path(args.out), seed=args.seed)
    print(json.dumps({"out": str(Path(args.out).resolve()), "files": counts,
                      "seconds": round(time.perf_counter() - t0, 1)}, ensure_ascii=False, indent=2))
    return 0


def _cmd_run(args: argparse.Namespace) -> int:
    from predictily_engine.pipeline.forecast_run import execute_forecast_run
    from predictily_engine.pipeline.schemas import ExecuteRequest

    req = ExecuteRequest(tenant_id=args.tenant_id, training_job_id=args.training_job_id,
                         horizons=args.horizons, cutoff_date=args.cutoff_date)
    result = execute_forecast_run(args.run_id, req)
    print(json.dumps(result, ensure_ascii=False, indent=2, default=str))
    return 0


def _cmd_readiness(args: argparse.Namespace) -> int:
    from predictily_engine.pipeline.readiness import run_readiness

    print(json.dumps(run_readiness(args.tenant_id, args.training_job_id), ensure_ascii=False, indent=2, default=str))
    return 0


def _cmd_evaluate(args: argparse.Namespace) -> int:
    from predictily_engine.evaluation.actuals import evaluate_actuals

    print(json.dumps(evaluate_actuals(args.tenant_id, args.training_job_id, args.lookback_runs),
                     ensure_ascii=False, indent=2, default=str))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="predictily_engine", description="Motor predictivo de Predictily")
    sub = parser.add_subparsers(dest="command", required=True)

    g = sub.add_parser("generate-demo", help="Genera los datasets ficticios de demostración")
    g.add_argument("--out", default="../data/demo")
    g.add_argument("--seed", type=int, default=42)
    g.set_defaults(func=_cmd_generate_demo)

    r = sub.add_parser("run", help="Ejecuta un forecast_run existente (creado por Laravel)")
    r.add_argument("--run-id", type=int, required=True)
    r.add_argument("--tenant-id", type=int, required=True)
    r.add_argument("--training-job-id", type=int, default=None)
    r.add_argument("--horizons", type=int, nargs="+", default=[7, 14, 30, 60, 90])
    r.add_argument("--cutoff-date", default=None)
    r.set_defaults(func=_cmd_run)

    rd = sub.add_parser("readiness", help="Perfila series y calidad de datos de una empresa")
    rd.add_argument("--tenant-id", type=int, required=True)
    rd.add_argument("--training-job-id", type=int, default=None)
    rd.set_defaults(func=_cmd_readiness)

    e = sub.add_parser("evaluate", help="Compara pronósticos pasados con ventas reales")
    e.add_argument("--tenant-id", type=int, required=True)
    e.add_argument("--training-job-id", type=int, default=None)
    e.add_argument("--lookback-runs", type=int, default=10)
    e.set_defaults(func=_cmd_evaluate)

    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
