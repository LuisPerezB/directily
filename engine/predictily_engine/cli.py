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


def _cmd_keygen(args: argparse.Namespace) -> int:
    """Genera un par Ed25519 para firmar artefactos (ADR-V25-20). La privada va a un archivo 0600 y NUNCA se imprime."""
    from predictily_engine.platform import signing

    try:
        key_id, private_b64, public_b64 = signing.generate_keypair(args.key_id)
    except ValueError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2
    target = Path(args.private_out)
    try:
        signing.write_private_key_file(target, private_b64, overwrite=args.force)
    except FileExistsError:
        print(f"error: {target} ya existe; no se sobrescribe (use --force: lo firmado con esa clave dejaría de ser verificable)", file=sys.stderr)
        return 2
    except OSError as exc:
        print(f"error: no se pudo escribir la clave privada ({exc.strerror})", file=sys.stderr)
        return 2
    location = target.resolve()
    print("\n".join([
        "Par Ed25519 generado.",
        f"  key_id:           {key_id}",
        f"  clave privada:    {location} (permisos 0600; no la copie al serving ni la versione)",
        f"  clave pública:    {public_b64}",
        f"  huella (sha256):  {signing.public_key_fingerprint(public_b64)}",
        "",
        "Lado de ENTRENAMIENTO (entorno autorizado a firmar):",
        f"  ENGINE_SIGNING_KEY_ID={key_id}",
        f"  ENGINE_SIGNING_PRIVATE_KEY=file:{location}",
        "",
        "Lado de SERVICIO (motor que carga artefactos y backend que revierte modelos):",
        f"  ENGINE_TRUSTED_PUBLIC_KEYS={key_id}:{public_b64}",
    ]))
    return 0


def _cmd_verify_artifact(args: argparse.Namespace) -> int:
    """Verifica un artefacto del registro SIN deserializarlo (firma, clave de confianza, SHA-256, tamaño, empresa)."""
    from predictily_engine.platform.artifacts import verify_stored_artifact

    result = verify_stored_artifact(args.path, tenant_id=args.tenant_id, core=args.core, expected_sha256=args.sha256, run_id=args.run_id)
    status = result.artifact.status if result.artifact else None
    print(json.dumps({"ok": result.ok, "reason": result.reason, "status": status,
                      "key_id": result.artifact.key_id if result.artifact else None}, ensure_ascii=False, indent=2))
    return 0 if result.ok else 1


def main(argv: list[str] | None = None) -> int:
    from predictily_engine.demand.horizons import SUPPORTED_HORIZONS  # catálogo único (ADR-V25-02)

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
    r.add_argument("--horizons", type=int, nargs="+", default=list(SUPPORTED_HORIZONS))
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

    k = sub.add_parser("keygen", help="Genera un par de claves Ed25519 para firmar artefactos de modelos (ADR-V25-20)")
    k.add_argument("--key-id", default=None, help="Identificador de la clave (por defecto k-AAAAMMDD-xxxxxx)")
    k.add_argument("--private-out", required=True, help="Archivo donde se guarda la clave privada (se crea con permisos 0600)")
    k.add_argument("--force", action="store_true", help="Sobrescribe el archivo si existe")
    k.set_defaults(func=_cmd_keygen)

    v = sub.add_parser("verify-artifact", help="Verifica firma y SHA-256 de un artefacto sin deserializarlo")
    v.add_argument("--path", required=True, help="artifact_path del registro (relativo a ENGINE_ARTIFACTS_DIR)")
    v.add_argument("--tenant-id", type=int, required=True)
    v.add_argument("--core", default=None, choices=["demand", "customer"])
    v.add_argument("--sha256", default=None, help="artifact_sha256 del registro")
    v.add_argument("--run-id", type=int, default=None, help="corrida que registró el modelo (forecast_run_id), si se conoce con certeza")
    v.set_defaults(func=_cmd_verify_artifact)

    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
