"""Subcomandos de la CLI de entrenamiento privado por empresa (ADR-V25-19): `python -m predictily_engine.cli <comando>`.

Flujo (docs/ml/PRIVATE_TENANT_TRAINING.md):
    export-local → (en la máquina de entrenamiento) validate-data → build-features → train [--from-export] → backtest/tune → package → sign/verify → publish
    → (en el backend, otra persona) validate → approve → promote.   `rollback` solo imprime la instrucción del backend.

Códigos de salida (tabla completa en `mlops/errors.py`): 0 ok · 1 comprobación negativa · 2 uso inválido · 3 entorno/configuración · 4 sin autorización ·
5 no encontrado · 6 conflicto · 7 error interno. Los mensajes de error van a stderr, sin secretos; el resultado de cada comando es JSON en stdout.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import sys
from collections.abc import Callable
from datetime import date
from pathlib import Path
from typing import Any

from predictily_engine.mlops.errors import (
    EXIT_CHECK_FAILED,
    EXIT_CODES,
    EXIT_CONFIG,
    EXIT_CONFLICT,
    EXIT_INTERNAL,
    EXIT_NOT_FOUND,
    EXIT_OK,
    EXIT_USAGE,
    CliError,
)

log = logging.getLogger("predictily_engine.cli")

EPILOG = ("Códigos de salida: " + " · ".join(f"{code} {name}" for code, name in EXIT_CODES.items()) +
          ". Los errores van a stderr (sin secretos); el resultado, en JSON por stdout. Detalle: docs/ml/PRIVATE_TENANT_TRAINING.md.")


def _int_range(low: int, high: int) -> Callable[[str], int]:
    """Tipo de argparse: entero dentro de un rango documentado (si no, salida 2 con un mensaje en español)."""
    def parse(text: str) -> int:
        try:
            value = int(text)
        except ValueError:
            raise argparse.ArgumentTypeError("debe ser un número entero") from None
        if not low <= value <= high:
            raise argparse.ArgumentTypeError(f"debe estar entre {low} y {high}")
        return value

    parse.__name__ = "entero"
    return parse


ID = _int_range(1, 2**62)
SEED = _int_range(0, 2**31 - 1)


def configured_log_level() -> str:
    return os.environ.get("ENGINE_LOG_LEVEL", "WARNING").strip().upper() or "WARNING"


def check_startup_configuration() -> str | None:
    """Mensaje en español si el ENTORNO es inválido (nivel de registro, tamaño de la caché de artefactos); None si todo bien. Se llama antes de ejecutar cualquier
    comando para que una variable mal escrita sea una salida 3 clara y no un rastro de pila."""
    level = configured_log_level()
    if level not in logging.getLevelNamesMapping():
        shown = level if re.fullmatch(r"[A-Z0-9_-]{1,20}", level) else "<no válido>"
        return f"ENGINE_LOG_LEVEL=«{shown}» no es un nivel de registro válido (use DEBUG, INFO, WARNING, ERROR o CRITICAL)."
    from predictily_engine.mlops.serving import validate_cache_settings

    return validate_cache_settings()


def _emit(payload: Any) -> None:
    print(json.dumps(payload, ensure_ascii=False, indent=2, default=str))


def _translate(exc: BaseException) -> CliError:
    """Errores de dominio y de infraestructura → CliError con el código estable. El mensaje nunca incluye la causa de un driver (puede traer datos de conexión)."""
    from sqlalchemy import exc as sa_exc

    from predictily_engine.pipeline.core import InsufficientDataError
    from predictily_engine.pipeline.errors import ConflictError, InvalidConfigurationError, NotFoundError
    from predictily_engine.platform.object_store import InvalidObjectKey, ObjectConflict, ObjectStoreError, ObjectStoreUnavailable
    from predictily_engine.platform.signing import SigningConfigError

    if isinstance(exc, CliError):
        return exc
    if isinstance(exc, InsufficientDataError):
        return CliError(EXIT_CHECK_FAILED, f"Datos insuficientes o bloqueados por el gate de calidad: {exc}")
    if isinstance(exc, (InvalidConfigurationError, SigningConfigError, ObjectStoreUnavailable)):
        return CliError(EXIT_CONFIG, str(exc))
    if isinstance(exc, NotFoundError):
        return CliError(EXIT_NOT_FOUND, str(exc))
    if isinstance(exc, (ConflictError, ObjectConflict)):
        return CliError(EXIT_CONFLICT, str(exc))
    if isinstance(exc, (sa_exc.OperationalError, sa_exc.InterfaceError)):
        return CliError(EXIT_CONFIG, "No se pudo usar la base de datos (conexión o esquema): revise ENGINE_DATABASE_URL y que esté migrada. Ejecute «diagnose».")
    if isinstance(exc, (ObjectStoreError, InvalidObjectKey)):
        return CliError(EXIT_CONFIG, f"Almacén de objetos: {exc}")
    return CliError(EXIT_INTERNAL, f"Error interno ({type(exc).__name__}). Repita con ENGINE_LOG_LEVEL=DEBUG para ver el detalle en el log.")


def guarded(fn: Callable[[argparse.Namespace], int]) -> Callable[[argparse.Namespace], int]:
    def run(args: argparse.Namespace) -> int:
        try:
            return int(fn(args))
        except CliError as exc:
            print(f"error: {exc.message}", file=sys.stderr)
            return exc.code
        except Exception as exc:  # noqa: BLE001
            log.debug("Fallo de %s", fn.__name__, exc_info=True)
            error = _translate(exc)
            print(f"error: {error.message}", file=sys.stderr)
            return error.code

    run.__name__ = fn.__name__
    run.__doc__ = fn.__doc__
    return run


def _date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value)
    except ValueError as exc:
        raise CliError(EXIT_USAGE, f"Fecha no válida «{value}»: use AAAA-MM-DD.") from exc


# ───────────────────────────── entradas comunes ─────────────────────────────

def _inputs(args: argparse.Namespace, core: str) -> Any:
    from predictily_engine.db.session import get_engine
    from predictily_engine.mlops import exports, training

    training.require_core(core)
    if getattr(args, "from_export", None):
        if not getattr(args, "key_file", None):
            raise CliError(EXIT_USAGE, "--from-export exige --key-file (la clave AES-256 de la exportación).")
        key = exports.load_key_file(Path(args.key_file))
        return exports.load_export(Path(args.from_export), key, expect_tenant_id=args.tenant_id, expect_core=core)
    engine = get_engine()
    if core == "demand":
        return training.prepare_demand_from_db(engine, args.tenant_id, cutoff=_date(getattr(args, "cutoff", None)))
    return training.prepare_customer_from_db(engine, args.tenant_id, as_of=_date(getattr(args, "as_of", None)))


def _horizons(args: argparse.Namespace, core: str | None = None) -> list[int] | None:
    """Horizontes pedidos, validados contra el catálogo del core (un horizonte fuera del catálogo es salida 2, nunca se descarta en silencio)."""
    if not getattr(args, "horizons", None):
        return None
    wanted = sorted(set(args.horizons))
    if core is not None:
        if core == "demand":
            from predictily_engine.demand.horizons import SUPPORTED_HORIZONS
        else:
            from predictily_engine.customer import SUPPORTED_HORIZONS
        invalid = [h for h in wanted if h not in SUPPORTED_HORIZONS]
        if invalid:
            raise CliError(EXIT_USAGE, f"Horizontes no válidos para el core {core}: {invalid}. Admitidos: {list(SUPPORTED_HORIZONS)}.")
    return wanted


def _cores(args: argparse.Namespace) -> list[str]:
    return ["demand", "customer"] if args.core == "all" else [args.core]


# ───────────────────────────── diagnose ─────────────────────────────

@guarded
def cmd_diagnose(args: argparse.Namespace) -> int:
    """Entorno, claves de confianza (identificadores y huellas), versiones, almacén de objetos y base de datos; sin secretos."""
    from predictily_engine.mlops.diagnose import diagnose

    wanted = {x.strip() for x in (args.require or "").split(",") if x.strip()}
    unknown = wanted - {"db", "object-store", "signing"}
    if unknown:
        raise CliError(EXIT_USAGE, "--require admite: db, object-store, signing.")
    report = diagnose(require=wanted)
    _emit(report)
    return EXIT_OK if report["ok"] else EXIT_CHECK_FAILED


# ───────────────────────────── validate-data ─────────────────────────────

def _validate_demand(inputs: Any) -> Any:
    from predictily_engine.profiling.days import DayRules
    from predictily_engine.profiling.series import build_panel
    from predictily_engine.quality.assessment import QualityPolicy, assess_demand

    data = inputs.data
    core_config = inputs.config_record["effective_config"]
    panel = build_panel(data, DayRules.from_config(core_config))
    policy = QualityPolicy.resolve(data.settings, data.sector)
    return assess_demand(data, panel, policy, inputs.quality_signals)


@guarded
def cmd_validate_data(args: argparse.Namespace) -> int:
    """Gates de calidad de datos (W2-C) de una empresa (base de datos o exportación autorizada) por core. Salida 1 si algún gate es BLOCK."""
    from predictily_engine.mlops.training import data_gate_block

    out: dict[str, Any] = {"tenant_id": args.tenant_id, "modules": {}}
    worst = "PASS"
    order = {"PASS": 0, "WARN": 1, "DEGRADE": 2, "BLOCK": 3}
    for core in _cores(args):
        inputs = _inputs(args, core)
        assessment = _validate_demand(inputs) if core == "demand" else inputs.qa
        block = data_gate_block(assessment, core)
        block.update({"source": inputs.source, "disabled_features": list(assessment.disabled_features),
                      "messages": [{"code": r["code"], "severity": r["severity"], "message": r["message"]} for r in assessment.gate.reasons],
                      "not_evaluated": [n["code"] for n in assessment.gate.not_evaluated]})
        out["modules"][core] = block
        if order[assessment.outcome] > order[worst]:
            worst = assessment.outcome
    out["outcome"] = worst
    _emit(out)
    return EXIT_CHECK_FAILED if worst == "BLOCK" else EXIT_OK


# ───────────────────────────── build-features ─────────────────────────────

@guarded
def cmd_build_features(args: argparse.Namespace) -> int:
    """Construye las variables (demanda: panel y esquema; Customer: dataset de cortes) y reporta formas, esquema y huella de datos, sin entrenar."""
    from predictily_engine.mlops import training
    from predictily_engine.mlops.bundle import write_json_atomic

    core = training.require_core(args.core)
    requested_horizons = _horizons(args, core)
    inputs = _inputs(args, core)
    report: dict[str, Any] = {"tenant_id": args.tenant_id, "core": core, "source": inputs.source, "feature_schema_version": training.feature_schema_for(core)}
    if core == "demand":
        from predictily_engine.features.builder import feature_registry
        from predictily_engine.pipeline.core import training_data_hash
        from predictily_engine.profiling.days import DayRules
        from predictily_engine.profiling.series import build_panel

        panel = build_panel(inputs.data, DayRules.from_config(inputs.config_record["effective_config"]))
        assessment = _validate_demand(inputs)
        report |= {"cutoff": panel.cutoff.date().isoformat() if hasattr(panel.cutoff, "date") else str(panel.cutoff), "series": int(len(panel.profile)),
                   "series_active": int(panel.profile["is_active"].sum()) if "is_active" in panel.profile else None, "panel_rows": int(len(panel.df)),
                   "data_fingerprint": training_data_hash(panel), "feature_names": [f["name"] if isinstance(f, dict) else str(f) for f in feature_registry()],
                   "gate": assessment.outcome, "features_disabled_by_gate": list(assessment.disabled_features)}
    else:
        from predictily_engine.customer.dataset import MODEL_FEATURES, build_dataset
        from predictily_engine.customer.events import to_day
        from predictily_engine.customer.features import FeatureBuilder

        data, cfg = inputs.data, inputs.cfg
        last = data.last_complete_day()
        horizons = requested_horizons or list(cfg["horizons"])
        report |= {"gate": inputs.qa.outcome, "as_of": last.isoformat() if last else None, "feature_names": list(MODEL_FEATURES), "n_features": len(MODEL_FEATURES)}
        if data.purchases is None or len(data.purchases) == 0 or last is None or inputs.qa.stops_individual_customer:
            report["dataset"] = None
            report["note"] = ("Sin compras identificadas o gate que impide el nivel comprador (" + inputs.qa.outcome + "): no se construye el dataset de cortes.")
        else:
            fb = FeatureBuilder(data, cfg, max_horizon=max(horizons), last_day=int(to_day(last)))
            first = to_day(data.sales_first) if data.sales_first else None
            cov = data.complete_from()
            bundle = build_dataset(fb, cfg, tuple(horizons), last_complete=int(to_day(last)), first_sale_day=int(first) if first is not None else None,
                                   coverage_from_day=int(to_day(cov)) if cov else None)
            report["dataset"] = bundle.meta
    if args.out:
        write_json_atomic(Path(args.out), report)
    _emit(report)
    return EXIT_OK


# ───────────────────────────── train / backtest / tune ─────────────────────────────

def _progress(args: argparse.Namespace) -> Callable[[str, int], None]:
    def show(stage: str, pct: int) -> None:
        if getattr(args, "verbose", False):
            print(f"[{pct:3d}%] {stage}", file=sys.stderr)

    return show


def _run(args: argparse.Namespace, core: str, *, tuning: dict[str, Any] | None = None) -> tuple[Any, Any]:
    from predictily_engine.mlops import training

    horizons = _horizons(args, core)                 # (antes de leer la base: una opción inválida es salida 2 aunque la base no responda)
    inputs = _inputs(args, core)
    progress = _progress(args)
    if core == "demand":
        return inputs, training.run_demand(inputs, seed=args.seed, horizons=tuple(horizons or ()) or None, max_windows=args.max_windows,
                                           tuning=tuning, progress=progress)
    return inputs, training.run_customer(inputs, seed=args.seed, horizons=horizons, progress=progress)


def _headline(core: str, run: Any) -> dict[str, Any]:
    from predictily_engine.mlops import training

    if core == "demand":
        r = run.result
        return {"quality_status": r.quality_status, "selected_wape_holdout": (r.summary.get("metrics") or {}).get("selected_wape"),
                "baseline_wape_holdout": (r.summary.get("metrics") or {}).get("baseline_wape"), "winners": getattr(r.selection, "winners", None),
                "data_gate": r.quality.outcome if r.quality is not None else None, "metrics_summary": training.demand_metrics_summary(r.evaluations),
                "tuning": (r.manifest.get("promotion") or {}).get("tuning")}
    return {"horizon_status": {str(h): r.status for h, r in run.results.items()}, "individual_stop": run.individual_stop,
            "data_gate": run.inputs.qa.outcome, "metrics_summary": training.customer_metrics_summary(run)}


@guarded
def cmd_train(args: argparse.Namespace) -> int:
    """Entrena (demanda o Customer, CPU) SIN escribir en la base de datos: deja artefactos firmados y un borrador en --out. Nunca registra ni promueve modelos."""
    from predictily_engine.mlops import training
    from predictily_engine.mlops.bundle import save_training

    core = training.require_core(args.core)
    inputs, run = _run(args, core)
    draft = save_training(Path(args.out), run, core)
    _emit({"tenant_id": args.tenant_id, "core": core, "out": str(Path(args.out).resolve()), "signed": draft["signed"], "source": draft["source"],
           "data_fingerprint": draft["data_fingerprint"], "models": [{k: m[k] for k in ("file", "sha256", "size_bytes")} | {"signature": m["signature"]["status"]}
                                                                      for m in draft["models"]], "elapsed_seconds": draft["elapsed_seconds"],
           "headline": _headline(core, run), "notes": draft["notes"],
           "next": "package --dir <out> --sign (si hay clave de firma) → verify → publish. Entrenar no registra nada en el registro de modelos."})
    return EXIT_OK


@guarded
def cmd_backtest(args: argparse.Namespace) -> int:
    """Backtest y holdout (los mismos protocolos del servicio) sin guardar artefactos: imprime selección y métricas por horizonte y segmento."""
    from predictily_engine.mlops import training
    from predictily_engine.mlops.bundle import write_json_atomic

    core = training.require_core(args.core)
    inputs, run = _run(args, core)
    report = {"tenant_id": args.tenant_id, "core": core, "source": inputs.source, "elapsed_seconds": round(run.elapsed_s, 1), **_headline(core, run)}
    if args.out:
        write_json_atomic(Path(args.out), report)
    _emit(report)
    return EXIT_OK


@guarded
def cmd_tune(args: argparse.Namespace) -> int:
    """Ajuste de LightGBM con Optuna ACOTADO (W3-B: solo ventanas de selección, nunca el holdout) y entrenamiento resultante; solo demanda."""
    from predictily_engine.mlops import training
    from predictily_engine.mlops.bundle import save_training

    if args.core != "demand":
        raise CliError(EXIT_USAGE, "tune solo aplica al core demand (el Customer Core no usa Optuna).")
    training.require_optuna()
    tuning = {"optuna_enabled": True, "optuna_n_trials": args.trials, "optuna_timeout_seconds": args.timeout_seconds, "optuna_seed": args.seed if args.seed is not None else 42,
              "optuna_max_cpu": args.max_cpu}
    inputs, run = _run(args, "demand", tuning=tuning)
    result = {"tenant_id": args.tenant_id, "core": "demand", "tuning_requested": tuning, "elapsed_seconds": round(run.elapsed_s, 1), **_headline("demand", run)}
    if args.out:
        draft = save_training(Path(args.out), run, "demand", notes=["Entrenado con ajuste Optuna acotado (ver details.tuning)."])
        result["out"] = str(Path(args.out).resolve())
        result["models"] = [m["file"] for m in draft["models"]]
    _emit(result)
    return EXIT_OK


# ───────────────────────────── package / sign / verify / publish ─────────────────────────────

@guarded
def cmd_sign(args: argparse.Namespace) -> int:
    """Firma con ENGINE_SIGNING_PRIVATE_KEY los artefactos del borrador de --dir (sin deserializarlos; exige que sigan siendo los entrenados)."""
    from predictily_engine.mlops.bundle import load_signing_config, sign_artifacts

    done = sign_artifacts(Path(args.dir), load_signing_config(), force=args.force)
    _emit({"dir": str(Path(args.dir).resolve()), "artifacts": done})
    return EXIT_OK


@guarded
def cmd_package(args: argparse.Namespace) -> int:
    """Empaqueta: verifica que todo artefacto esté firmado y crea package.manifest.json firmado (versiones de librerías, huella de datos, métricas, gate)."""
    from predictily_engine.mlops.bundle import build_package, load_signing_config, sign_artifacts

    config = load_signing_config()
    directory = Path(args.dir)
    if args.sign:
        sign_artifacts(directory, config)
    manifest = build_package(directory, config, force=args.force)
    _emit({"dir": str(directory.resolve()), "bundle_id": manifest["bundle_id"], "tenant_id": manifest["tenant_id"], "core": manifest["core"],
           "key_id": manifest["key_id"], "data_fingerprint": manifest["data_fingerprint"], "libraries": manifest["libraries"],
           "models": [{k: m[k] for k in ("file", "sha256", "size_bytes", "key_id")} for m in manifest["models"]], "data_gate": manifest["data_gate"].get("outcome")})
    return EXIT_OK


@guarded
def cmd_verify(args: argparse.Namespace) -> int:
    """Verifica el paquete y sus artefactos SIN deserializar (firma Ed25519, clave de confianza, SHA-256, tamaño, empresa y core). Salida 1 si algo falla."""
    from predictily_engine.mlops.bundle import verify_directory

    report = verify_directory(Path(args.dir), tenant_id=args.tenant_id, require_package=args.require_package)
    _emit(report.as_dict())
    return EXIT_OK if report.ok else EXIT_CHECK_FAILED


@guarded
def cmd_publish(args: argparse.Namespace) -> int:
    """Sube el paquete firmado al almacén (clave del servidor tenants/<uuid>/models/…) y lo registra como CANDIDATE con published_by/env/at. Nunca production."""
    from predictily_engine.db.session import get_engine
    from predictily_engine.mlops.bundle import publish_package
    from predictily_engine.platform.object_store import build_store

    report = publish_package(get_engine(), build_store(), Path(args.dir), env=args.env, published_by_user_id=args.published_by_user_id,
                             tenant_id=args.tenant_id, dry_run=args.dry_run, confirm_production=args.confirm_production)
    _emit(report.as_dict())
    return EXIT_OK


# ───────────────────────────── registro: compare / rollback / list-models ─────────────────────────────

@guarded
def cmd_compare(args: argparse.Namespace) -> int:
    """Compara dos versiones (registro o bundle:<carpeta>) por horizonte y segmento. Veredicto descriptivo; no recomienda promover. Salida 1 si no son comparables."""
    from predictily_engine.db.session import get_engine
    from predictily_engine.mlops.bundle import write_json_atomic
    from predictily_engine.mlops.compare import compare_summaries, side_from_arg

    needs_db = not (args.a.startswith("bundle:") and args.b.startswith("bundle:"))
    if needs_db:
        with get_engine().connect() as conn:
            a, b = (side_from_arg(conn, args.tenant_id, args.core, x) for x in (args.a, args.b))
    else:
        a, b = (side_from_arg(None, args.tenant_id, args.core, x) for x in (args.a, args.b))
    result = compare_summaries(a, b)
    report = {"tenant_id": args.tenant_id, "core": args.core, **{k: {kk: vv for kk, vv in side.items() if kk != "summary"} for k, side in (("a", a), ("b", b))}, **result}
    if args.out:
        write_json_atomic(Path(args.out), report)
    _emit(report)
    return EXIT_OK if result["comparable"] else EXIT_CHECK_FAILED


@guarded
def cmd_rollback(args: argparse.Namespace) -> int:
    """NO cambia nada: comprueba si la versión indicada puede servirse y deja la instrucción exacta del backend (reversión o promoción, con aprobación)."""
    from sqlalchemy import text

    from predictily_engine.db.session import get_engine
    from predictily_engine.mlops import policy

    with get_engine().connect() as conn:
        row = conn.execute(text("SELECT * FROM model_registry WHERE tenant_id = :t AND core = :c AND version = :v"),
                           {"t": args.tenant_id, "c": args.core, "v": args.to_version}).mappings().first()
        if row is None:
            raise CliError(EXIT_NOT_FOUND, f"La versión «{args.to_version}» no existe en el registro de la empresa {args.tenant_id} (core {args.core}).")
        row = dict(row)
        basis = policy.basis_of_rows(conn, args.tenant_id, [row])[0]
        serving = [dict(r) for r in conn.execute(text(
            "SELECT id, version FROM model_registry WHERE tenant_id = :t AND core = :c AND status = 'production' "
            "AND (CAST(:slot AS text) IS NULL OR hyperparameters->>'slot' = CAST(:slot AS text)) ORDER BY id"),
            {"t": args.tenant_id, "c": args.core, "slot": (row.get("hyperparameters") or {}).get("slot") if args.core == "customer" else None}).mappings().all()]
    base = {"tenant_id": args.tenant_id, "core": args.core, "target": {"id": int(row["id"]), "version": row["version"], "status": row["status"], "serving_basis": basis},
            "currently_in_production": serving, "this_cli_changed_nothing": True,
            "authority": "Solo el backend cambia el estado de un modelo: la reversión y la promoción exigen un modelo aprobado (o legado del pipeline propio) y dejan auditoría."}
    status = row["status"]
    if status in ("rejected", "failed") or basis == policy.NOT_APPROVED:
        _emit(base | {"servable": False, "instruction": None,
                      "reason": "El modelo no está aprobado (base «not_approved»): no se puede servir ni revertir a él.",
                      "path": "En el backend: POST /api/v1/models/{id}/validate (forecast.run), luego /approve y /promote con models.approve, "
                              "por una persona distinta de quien publicó o entrenó."})
        return EXIT_CHECK_FAILED
    if status == "production":
        _emit(base | {"servable": True, "instruction": None, "reason": "La versión ya está en producción: no hay nada que revertir."})
        return EXIT_OK
    if status == "approved":
        instruction = {"method": "POST", "path": f"/api/v1/models/{int(row['id'])}/promote", "ability": "models.approve",
                       "note": "Está aprobado y aún no sirvió: se promueve (repite las compuertas de firma y datos)."}
    elif args.core == "customer":
        instruction = {"method": "POST", "path": "/api/v1/models/customer/rollback", "body": {"model_registry_id": int(row["id"])}, "ability": "forecast.run",
                       "note": "Restaura la versión del mismo slot; un aprobado que nunca sirvió exige además models.approve."}
    elif row.get("forecast_run_id") is not None:
        instruction = {"method": "POST", "path": "/api/v1/models/demand/rollback", "body": {"run_id": int(row["forecast_run_id"])}, "ability": "forecast.run",
                       "note": "La demanda se revierte por corrida: re-apunta la ejecución vigente a la corrida de este modelo."}
    else:
        _emit(base | {"servable": True, "instruction": None,
                      "reason": "La reversión de demanda es por corrida y este modelo no tiene corrida enlazada: apruébelo y promuévalo (POST /api/v1/models/{id}/promote)."})
        return EXIT_OK
    _emit(base | {"servable": True, "instruction": instruction})
    return EXIT_OK


@guarded
def cmd_list_models(args: argparse.Namespace) -> int:
    """Lista las versiones del registro de una empresa con su base de servicio (approved | legacy_pipeline | not_approved) y su publicación."""
    from sqlalchemy import text

    from predictily_engine.db.session import get_engine
    from predictily_engine.mlops import policy

    with get_engine().connect() as conn:
        rows = [dict(r) for r in conn.execute(text(
            "SELECT id, tenant_id, version, core, model_family, role, status, hyperparameters, approved_at, approved_by, published_at, published_by, "
            "published_env, published_object_key, forecast_run_id, core_run_id, training_job_id, created_at FROM model_registry "
            "WHERE tenant_id = :t AND (CAST(:c AS text) IS NULL OR core = CAST(:c AS text)) ORDER BY id DESC LIMIT :n"),
            {"t": args.tenant_id, "c": None if args.core == "all" else args.core, "n": args.limit}).mappings().all()]
        bases = policy.basis_of_rows(conn, args.tenant_id, rows)
    _emit({"tenant_id": args.tenant_id, "models": [
        {"id": int(r["id"]), "version": r["version"], "core": r["core"], "family": r["model_family"], "role": r["role"], "status": r["status"],
         "serving_basis": b, "servable": policy.is_servable(b), "published_env": r["published_env"], "published": r["published_at"] is not None,
         "approved": r["approved_at"] is not None, "slot": (r.get("hyperparameters") or {}).get("slot")} for r, b in zip(rows, bases)]})
    return EXIT_OK


# ───────────────────────────── exportación local autorizada ─────────────────────────────

@guarded
def cmd_export_local(args: argparse.Namespace) -> int:
    """Exportación local AUTORIZADA, minimizada (lista blanca y seudónimos) y cifrada con AES-256-GCM; vence y se limpia con cleanup-exports."""
    from predictily_engine.mlops import exports, training

    exports.validate_authorization(args.authorized_export, args.authorized_by, args.purpose)       # primero: sin autorización no se toca la base
    core = training.require_core(args.core)
    key_path = Path(args.key_file)
    if not key_path.exists():
        if not args.generate_key:
            raise CliError(EXIT_CONFIG, "El archivo de clave no existe: indique uno existente o use --generate-key para crearlo (0600).")
        exports.generate_key_file(key_path)
    key = exports.load_key_file(key_path)
    inputs = _inputs(args, core)
    result = exports.create_export(inputs, core, out_root=Path(args.out), key=key, authorization_ref=args.authorized_export, authorized_by=args.authorized_by,
                                   purpose=args.purpose, ttl_hours=args.ttl_hours)
    m = result.manifest
    _emit({"export_dir": str(result.export_dir.resolve()), "export_id": result.export_id, "tenant_id": m["tenant_id"], "core": core, "expires_at": m["expires_at"],
           "authorization": m["authorization"], "encrypted": True, "key_fingerprint": m["encryption"]["key_fingerprint"],
           "tables": {k: v["rows"] for k, v in m["tables"].items()}, "dropped_columns": m["minimization"]["dropped_columns"],
           "asof_fidelity": m["asof_fidelity"],
           "next": "Use la clave con train --from-export; al terminar o al vencer: cleanup-exports --root <carpeta>."})
    return EXIT_OK


@guarded
def cmd_cleanup_exports(args: argparse.Namespace) -> int:
    """Borra las exportaciones locales vencidas (o todas con --all) de --root, sobrescribiendo antes de borrar; solo toca carpetas con la marca de exportación."""
    from predictily_engine.mlops.exports import cleanup_exports

    report = cleanup_exports(Path(args.root), remove_all=args.all, dry_run=args.dry_run)
    _emit({"root": str(Path(args.root).resolve()), "dry_run": args.dry_run, **report.as_dict()})
    return EXIT_OK


# ───────────────────────────── analizador en español ─────────────────────────────

_TRANSLATIONS = (
    ("the following arguments are required:", "faltan argumentos obligatorios:"),
    ("unrecognized arguments:", "argumentos no reconocidos:"),
    ("invalid choice:", "valor no válido:"),
    ("(choose from", "(use uno de"),
    ("expected one argument", "falta el valor del argumento"),
    ("expected at least one argument", "falta al menos un valor del argumento"),
    ("invalid int value:", "número entero no válido:"),
    ("invalid float value:", "número no válido:"),
    ("not allowed with argument", "no se puede usar junto con"),
    ("ambiguous option:", "opción ambigua:"),
    ("argument ", "argumento "),
)


class EsFormatter(argparse.RawDescriptionHelpFormatter):
    def add_usage(self, usage: str | None, actions: Any, groups: Any, prefix: str | None = None) -> None:
        super().add_usage(usage, actions, groups, prefix if prefix is not None else "uso: ")


class EsParser(argparse.ArgumentParser):
    """ArgumentParser con la ayuda y los errores comunes en español; los errores de uso salen con código 2 (como todo argparse)."""

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        kwargs.setdefault("formatter_class", EsFormatter)
        kwargs["add_help"] = False
        super().__init__(*args, **kwargs)
        self._positionals.title = "argumentos"
        self._optionals.title = "opciones"
        self.add_argument("-h", "--help", action="help", default=argparse.SUPPRESS, help="Muestra esta ayuda y termina")

    def error(self, message: str) -> Any:
        for english, spanish in _TRANSLATIONS:
            message = message.replace(english, spanish)
        self.print_usage(sys.stderr)
        self.exit(EXIT_USAGE, f"{self.prog}: error: {message}\n")


# ───────────────────────────── registro en argparse ─────────────────────────────

def _tenant(p: argparse.ArgumentParser) -> None:
    p.add_argument("--tenant-id", "--tenant", dest="tenant_id", type=ID, required=True, help="Id de la empresa (los datos de otras empresas nunca se leen)")


def _source(p: argparse.ArgumentParser) -> None:
    p.add_argument("--from-export", default=None, help="Carpeta de una exportación autorizada (en vez de leer la base de datos); exige --key-file")
    p.add_argument("--key-file", default=None, help="Archivo con la clave AES-256 de la exportación (permisos 0600)")


def _core(p: argparse.ArgumentParser, *, allow_all: bool = False) -> None:
    p.add_argument("--core", choices=["demand", "customer", *(["all"] if allow_all else [])], required=True, help="Core: demand (demanda) o customer (compras por comprador)")


def _run_options(p: argparse.ArgumentParser, *, customer_asof: bool = True) -> None:
    _tenant(p)
    _core(p)
    _source(p)
    p.add_argument("--seed", type=SEED, default=None, help="Semilla, 0 a 2147483647 (por defecto ENGINE_RANDOM_SEED / la del core)")
    p.add_argument("--horizons", type=_int_range(1, 3650), nargs="+", default=None, help="Horizontes en días del catálogo del core (por defecto todos); uno fuera del catálogo es un error")
    p.add_argument("--max-windows", type=_int_range(1, 6), default=None, help="Demanda: máximo de ventanas de backtest (1 a 6)")
    p.add_argument("--cutoff", default=None, help="Demanda: fecha de corte AAAA-MM-DD (por defecto, la última venta)")
    p.add_argument("--as-of", default=None, help="Customer: fecha de datos AAAA-MM-DD (por defecto, el último día completo)")
    p.add_argument("--verbose", action="store_true", help="Muestra el avance por etapas en stderr")


def register(sub: argparse._SubParsersAction) -> None:  # type: ignore[type-arg]
    def add(name: str, fn: Callable[[argparse.Namespace], int], help_: str, *, description: str | None = None) -> argparse.ArgumentParser:
        p = sub.add_parser(name, help=help_, description=description or fn.__doc__, epilog=EPILOG, formatter_class=EsFormatter)
        p.set_defaults(func=fn)
        return p

    p = add("diagnose", cmd_diagnose, "Diagnostica el entorno (claves de confianza, versiones, almacén, base de datos) sin imprimir secretos")
    p.add_argument("--require", default="", help="Lista separada por comas de lo que debe estar listo, o el diagnóstico falla: db, object-store, signing")

    p = add("validate-data", cmd_validate_data, "Gates de calidad de datos (W2-C) de una empresa: PASS, WARN, DEGRADE o BLOCK por core")
    _tenant(p)
    _core(p, allow_all=True)
    _source(p)
    p.add_argument("--cutoff", default=None, help="Demanda: fecha de corte AAAA-MM-DD")
    p.add_argument("--as-of", default=None, help="Customer: fecha de datos AAAA-MM-DD")

    p = add("build-features", cmd_build_features, "Construye las variables y reporta esquema, formas y huella de datos (no entrena)")
    _run_options(p)
    p.add_argument("--out", default=None, help="Archivo JSON donde guardar el informe")

    p = add("train", cmd_train, "Entrena una empresa (demanda o Customer, CPU) sin escribir en la base; deja artefactos firmados en --out")
    _run_options(p)
    p.add_argument("--out", required=True, help="Directorio nuevo donde dejar artefactos, manifiestos firmados y el borrador (no se sobrescribe)")

    p = add("backtest", cmd_backtest, "Backtest y holdout sin guardar artefactos: selección y métricas por horizonte y segmento")
    _run_options(p)
    p.add_argument("--out", default=None, help="Archivo JSON donde guardar el informe")

    p = add("tune", cmd_tune, "Ajuste de LightGBM con Optuna acotado (solo demanda; requiere Optuna instalado)")
    _run_options(p)
    p.add_argument("--trials", type=_int_range(1, 200), default=20, help="Pruebas máximas de Optuna (1 a 200)")
    p.add_argument("--timeout-seconds", type=_int_range(1, 3600), default=120, help="Tiempo máximo del ajuste en segundos (1 a 3600)")
    p.add_argument("--max-cpu", type=_int_range(1, 16), default=2, help="Hilos máximos de LightGBM durante el ajuste (1 a 16)")
    p.add_argument("--out", default=None, help="Directorio nuevo donde guardar lo entrenado con el ajuste (opcional)")

    p = add("sign", cmd_sign, "Firma los artefactos de un directorio de entrenamiento con ENGINE_SIGNING_PRIVATE_KEY")
    p.add_argument("--dir", required=True, help="Directorio de train")
    p.add_argument("--force", action="store_true", help="Vuelve a firmar aunque ya estén firmados")

    p = add("package", cmd_package, "Empaqueta: verifica que todo esté firmado y crea package.manifest.json firmado")
    p.add_argument("--dir", required=True, help="Directorio de train")
    p.add_argument("--sign", action="store_true", help="Firma antes los artefactos que falten")
    p.add_argument("--force", action="store_true", help="Rehace un paquete existente (con nuevo identificador)")

    p = add("verify", cmd_verify, "Verifica firma, huellas y empresa del paquete y sus artefactos sin deserializarlos")
    p.add_argument("--dir", required=True, help="Directorio de train/paquete")
    p.add_argument("--tenant-id", "--tenant", dest="tenant_id", type=ID, default=None, help="Exige que el paquete sea de esta empresa")
    p.add_argument("--require-package", action="store_true", help="Falla si aún no hay package.manifest.json firmado")

    p = add("publish", cmd_publish, "Publica el paquete firmado al almacén de objetos y lo registra como candidate (nunca production)")
    p.add_argument("--dir", required=True, help="Directorio con el paquete firmado")
    p.add_argument("--env", required=True, choices=["local", "staging", "production"], help="Entorno al que se publica")
    p.add_argument("--published-by-user-id", type=ID, required=True, help="Usuario de la empresa que publica (queda en published_by y en el evento; no podrá aprobar)")
    p.add_argument("--tenant-id", "--tenant", dest="tenant_id", type=ID, default=None, help="Exige que el paquete sea de esta empresa")
    p.add_argument("--dry-run", action="store_true", help="Solo muestra claves y versiones; no escribe en el almacén ni en la base")
    p.add_argument("--confirm-production", action="store_true", help="Confirma la publicación con --env production (sigue siendo solo un candidato)")

    p = add("compare", cmd_compare, "Compara dos versiones (registro o bundle:<carpeta>) por horizonte y segmento")
    _tenant(p)
    _core(p)
    p.add_argument("--a", required=True, help="Versión del registro o bundle:<carpeta>")
    p.add_argument("--b", required=True, help="Versión del registro o bundle:<carpeta>")
    p.add_argument("--out", default=None, help="Archivo JSON donde guardar la comparación")

    p = add("rollback", cmd_rollback, "Comprueba una versión y imprime la instrucción del backend para volver a ella; no cambia nada")
    _tenant(p)
    _core(p)
    p.add_argument("--to-version", required=True, help="Versión del registro a la que se quiere volver")

    p = add("list-models", cmd_list_models, "Lista las versiones del registro con su base de servicio y su publicación")
    _tenant(p)
    p.add_argument("--core", choices=["demand", "customer", "all"], default="all")
    p.add_argument("--limit", type=_int_range(1, 500), default=50, help="Máximo de versiones a listar (1 a 500)")

    p = add("export-local", cmd_export_local, "Exportación local autorizada, minimizada y cifrada de los datos de entrenamiento de una empresa")
    _tenant(p)
    _core(p)
    p.add_argument("--authorized-export", default=None, help="Referencia de la autorización escrita (obligatoria; sin ella no se exporta)")
    p.add_argument("--authorized-by", default=None, help="Persona que autoriza la exportación (obligatoria)")
    p.add_argument("--purpose", default=None, help="Para qué se exporta (obligatorio, 5 a 200 caracteres)")
    p.add_argument("--out", required=True, help="Carpeta raíz de exportaciones (se crea export-<id> dentro)")
    p.add_argument("--key-file", required=True, help="Archivo con la clave AES-256 (0600)")
    p.add_argument("--generate-key", action="store_true", help="Crea --key-file (0600) si no existe")
    p.add_argument("--ttl-hours", type=_int_range(1, 720), default=72, help="Horas de vigencia (1 a 720, por defecto 72)")
    p.add_argument("--cutoff", default=None, help="Demanda: fecha de corte AAAA-MM-DD")
    p.add_argument("--as-of", default=None, help="Customer: fecha de datos AAAA-MM-DD")

    p = add("cleanup-exports", cmd_cleanup_exports, "Borra las exportaciones locales vencidas (o todas) de una carpeta")
    p.add_argument("--root", required=True, help="Carpeta raíz de exportaciones")
    p.add_argument("--all", action="store_true", help="Borra también las vigentes")
    p.add_argument("--dry-run", action="store_true", help="Solo lista lo que borraría")
