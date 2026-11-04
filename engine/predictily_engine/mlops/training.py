"""Entrenamiento, backtest y ajuste de una empresa SIN escribir en la base de datos (CLI de entrenamiento privado, ADR-V25-19).

Las entradas vienen de la base (lectura) o de una exportación autorizada (`mlops.exports`); la salida son objetos en memoria
(`DemandRun` | `CustomerRun`) que `mlops.bundle` convierte en artefactos firmados. Nada de aquí promueve, retira ni registra modelos: el único camino
al registro es `publish` (siempre `candidate`) y la promoción es una decisión separada del backend (ADR-V25-07).

Fidelidad con el pipeline de servicio: la preparación de entradas replica la de `pipeline.forecast_run.execute_forecast_run` (demanda) y
`customer.service._run_train` (Customer) paso a paso —configuración efectiva, cobertura, señales de calidad, gate— y llama a las MISMAS funciones puras
(`run_pipeline`, `build_dataset`, `train_horizon`, `finalize_s05`, `evaluate_window`). Las pruebas de paridad comparan huella de datos, selección, modelos servidos
y cifras del holdout con una corrida del servicio: `tests/integration/test_mlops_integration.py::test_cli_training_matches_the_service_for_the_customer_core`,
`tests/integration/test_mlops_demand_integration.py::test_cli_demand_training_matches_the_service` y, desde una exportación,
`…::test_customer_training_from_an_export_matches_training_from_the_database_up_to_relabelling` y `…::test_training_from_an_authorized_export_reproduces_the_database_training`.
Diferencia declarada: una exportación autorizada no trae el historial de versiones con tiempo de conocimiento (ADR-V25-23), así que lo entrenado
desde ella asume conocido cada documento desde su día de negocio (`asof_fidelity = approximate_no_versions` en el informe).
"""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date
from typing import Any

from sqlalchemy import Engine

from predictily_engine.config import FEATURE_SET_VERSION, get_settings
from predictily_engine.mlops.errors import EXIT_CONFIG, EXIT_NOT_FOUND, CliError

log = logging.getLogger(__name__)
Progress = Callable[[str, int], None]
CORES = ("demand", "customer")


def _quiet(stage: str, pct: int) -> None:
    log.info("%s %s%%", stage, pct)


# ───────────────────────────── entradas ─────────────────────────────

@dataclass
class DemandInputs:
    tenant_id: int
    data: Any                                   # TenantData
    config_record: dict[str, Any]
    promotion_record: dict[str, Any]
    quality_signals: dict[str, Any] | None
    champions: dict[str, str]
    champions_source: str
    source: dict[str, Any]


@dataclass
class CustomerInputs:
    tenant_id: int
    data: Any                                   # CustomerData
    cfg: dict[str, Any]
    cfg_version: str
    provenance: dict[str, str]
    qa: Any                                     # QualityAssessment del Customer Core
    tenant: dict[str, Any]
    source: dict[str, Any]


def _tenant_or_404(conn: Any, tenant_id: int) -> None:
    from predictily_engine.db.repository import TenantRepository

    try:
        TenantRepository(conn, tenant_id).tenant()
    except LookupError as exc:
        raise CliError(EXIT_NOT_FOUND, f"La empresa {tenant_id} no existe o está eliminada.") from exc


def prepare_demand_from_db(engine: Engine, tenant_id: int, *, cutoff: date | None = None) -> DemandInputs:
    """Entradas de demanda leídas de la base, con los mismos pasos que `execute_forecast_run` (solo lectura)."""
    from predictily_engine.db.repository import TenantRepository
    from predictily_engine.demand.coverage import SalesCoverage
    from predictily_engine.pipeline.core import InsufficientDataError
    from predictily_engine.pipeline.data import load_tenant_data
    from predictily_engine.pipeline.errors import InvalidConfigurationError
    from predictily_engine.pipeline.forecast_run import load_champions
    from predictily_engine.platform.config import effective_config_record
    from predictily_engine.quality.service import resolve_policy
    from predictily_engine.quality.signals import collect_signals

    with engine.connect() as conn:
        _tenant_or_404(conn, tenant_id)
        repo = TenantRepository(conn, tenant_id)
        repo.analytic_reads()
        last = repo.last_sale_date()
        if last is None:
            raise InsufficientDataError("La empresa no tiene ventas válidas importadas.")
        if cutoff is None or cutoff > last:
            cutoff = last
        live_run = cutoff >= last
        tenant = repo.tenant()
        try:
            config_record = effective_config_record("demand", tenant.get("settings"), tenant.get("sector"))
            promotion_record = effective_config_record("promotion", tenant.get("settings"), tenant.get("sector"))
        except ValueError as exc:
            raise InvalidConfigurationError(f"Configuración inválida de la empresa: {exc}") from exc
        champions = load_champions(repo, True)
        champions_source = "previous_production_run" if champions else "none"
        quality_policy = resolve_policy(tenant)
        branches = repo.branches()
        active_branches = (set(branches.loc[branches["is_active"].astype(bool), "branch_id"].astype(int))
                           if "is_active" in branches else set(branches["branch_id"].astype(int)))
        coverage = SalesCoverage.resolve(conn, tenant_id, repo.sales_coverage(), active_branches)
        cutoff = coverage.limit_cutoff(cutoff)
        data = coverage.apply(load_tenant_data(repo, cutoff, live=live_run))
        data.coverage_limitations = coverage.limitations()
        signals = collect_signals(conn, tenant_id, cutoff, tenant.get("timezone"), quality_policy.config, knowledge=data.promotions_known_on)
    return DemandInputs(tenant_id, data, config_record, promotion_record, signals, champions, champions_source,
                        {"kind": "database", "cutoff": cutoff.isoformat(), "asof_fidelity": "exact_with_versions"})


def prepare_customer_from_db(engine: Engine, tenant_id: int, *, as_of: date | None = None) -> CustomerInputs:
    """Entradas del Customer Core leídas de la base, con los mismos pasos que `_run_train` (solo lectura)."""
    from predictily_engine.customer.data import load_customer_data
    from predictily_engine.db.customer_repository import CustomerRepository
    from predictily_engine.platform.config import resolve_config
    from predictily_engine.quality.service import customer_gate, resolve_policy

    with engine.connect() as conn:
        _tenant_or_404(conn, tenant_id)
        repo = CustomerRepository(conn, tenant_id)
        repo.base.analytic_reads()
        tenant = repo.tenant()
        cfg, cfg_version, provenance = resolve_config("customer", tenant["settings"], tenant.get("sector"))
        resolve_policy(tenant)
        data = load_customer_data(repo, cfg, through=as_of, cap_to_complete=True, live=True if as_of is None else None)
        qa = customer_gate(conn, tenant, cfg, data)
    return CustomerInputs(tenant_id, data, cfg, cfg_version, provenance, qa, tenant,
                          {"kind": "database", "as_of": as_of.isoformat() if as_of else None, "asof_fidelity": "exact_with_versions"})


# ───────────────────────────── demanda ─────────────────────────────

@dataclass
class DemandRun:
    inputs: DemandInputs
    result: Any                                 # PipelineResult
    seed: int
    horizons: tuple[int, ...]
    elapsed_s: float
    tuning_requested: dict[str, Any] | None = None


def run_demand(inputs: DemandInputs, *, seed: int | None = None, horizons: tuple[int, ...] | None = None, max_windows: int | None = None,
               tuning: dict[str, Any] | None = None, force_families: list[str] | None = None, progress: Progress | None = None) -> DemandRun:
    """Ejecuta el pipeline de demanda (`run_pipeline`) sobre `inputs`. `tuning`: sobrescrituras del bloque `promotion` (Optuna acotado de W3-B)."""
    from predictily_engine.demand.horizons import SUPPORTED_HORIZONS
    from predictily_engine.platform.config import effective_config_record

    settings = get_settings()
    from predictily_engine.pipeline.core import run_pipeline

    horizons = tuple(sorted({int(h) for h in (horizons or SUPPORTED_HORIZONS)}))
    seed = settings.random_seed if seed is None else int(seed)
    core_config = inputs.config_record["effective_config"]
    min_improvement = settings.min_improvement if settings.min_improvement is not None else core_config["min_improvement"]
    windows = max_windows or settings.max_backtest_windows or core_config["max_backtest_windows"]
    promotion_record = inputs.promotion_record
    if tuning:
        base = ((inputs.data.settings or {}).get("cores") or {}).get("promotion") or {}
        promotion_record = effective_config_record("promotion", inputs.data.settings, inputs.data.sector, {**base, **tuning})
    t0 = time.perf_counter()
    result = run_pipeline(
        inputs.data, horizon=max(horizons), seed=seed, n_jobs=settings.n_jobs, min_improvement=min_improvement, max_windows=windows,
        max_series_lgbm=settings.max_series_lgbm, progress=progress or _quiet, force_families=force_families, horizons=horizons,
        config_record=inputs.config_record, quality_signals=inputs.quality_signals, promotion_record=promotion_record,
        champions=inputs.champions, champions_source=inputs.champions_source)
    return DemandRun(inputs, result, seed, horizons, time.perf_counter() - t0, tuning)


# ───────────────────────────── Customer ─────────────────────────────

@dataclass
class CustomerRun:
    inputs: CustomerInputs
    results: dict[int, Any]                     # horizonte → HorizonResult
    bundle: Any
    coherence: dict[str, Any]
    window_eval: dict[str, Any] | None
    finalization: dict[str, Any] | None
    activity_eval: dict[str, Any]
    last_complete: date | None
    seed: int
    horizons: list[int]
    elapsed_s: float
    individual_stop: str | None = None          # código si el gate impidió el nivel comprador


def run_customer(inputs: CustomerInputs, *, seed: int | None = None, horizons: list[int] | None = None,
                 progress: Progress | None = None) -> CustomerRun:
    """Entrenamiento del Customer Core (mismos pasos que `_run_train`, sin persistir): dataset → `train_horizon` por horizonte → coherencia → S05."""
    from predictily_engine.customer import SUPPORTED_HORIZONS
    from predictily_engine.customer.dataset import build_dataset
    from predictily_engine.customer.events import to_day
    from predictily_engine.customer.features import FeatureBuilder
    from predictily_engine.customer.outputs import evaluate_activity
    from predictily_engine.customer.s05 import finalize_s05
    from predictily_engine.customer.s05_persist import attach_window
    from predictily_engine.customer.train import HorizonResult, evaluate_coherence, train_horizon
    from predictily_engine.customer.window import WindowParams, evaluate_window

    progress = progress or _quiet
    cfg, data, qa = inputs.cfg, inputs.data, inputs.qa
    wanted = horizons or list(cfg["horizons"])
    requested = sorted({int(h) for h in wanted if int(h) in SUPPORTED_HORIZONS and int(h) in cfg["horizons"]})
    if not requested:
        raise CliError(2, "Ninguno de los horizontes pedidos es válido para el Customer Core (" + ", ".join(str(h) for h in SUPPORTED_HORIZONS) + ").")
    settings = get_settings()
    seed = int(cfg["seed"]) if seed is None else int(seed)
    t0 = time.perf_counter()
    last_complete = data.last_complete_day()
    results: dict[int, HorizonResult] = {}
    bundle = None
    activity_eval: dict[str, Any] = {}
    coherence: dict[str, Any] = {"evaluated": False}
    window_eval: dict[str, Any] | None = None
    finalization: dict[str, Any] | None = None
    stop: str | None = None
    if data.purchases is None or len(data.purchases) == 0 or last_complete is None:
        stop = "no_identified_buyers"
        for h in requested:
            results[h] = HorizonResult(horizon=h, status="abstained", reason_code=stop, reason="La empresa no tiene compras válidas de compradores identificados.")
    elif qa.stops_individual_customer:
        stop = qa.individual_stop_code
        for h in requested:
            results[h] = HorizonResult(horizon=h, status="abstained", reason_code=stop, reason=qa.individual_stop_message())
    else:
        fb = FeatureBuilder(data, cfg, max_horizon=max(requested), last_day=int(to_day(last_complete)))
        progress("building_dataset", 5)
        first_sale = to_day(data.sales_first) if data.sales_first else None
        cov_from = data.complete_from()
        bundle = build_dataset(fb, cfg, tuple(requested), last_complete=int(to_day(last_complete)),
                               first_sale_day=int(first_sale) if first_sale is not None else None,
                               coverage_from_day=int(to_day(cov_from)) if cov_from else None, progress=progress)
        n_jobs = max(1, int(settings.n_jobs))
        for i, h in enumerate(requested):
            progress(f"training_h{h}", 30 + int(50 * i / len(requested)))
            try:
                results[h] = train_horizon(bundle, h, cfg, seed, n_jobs)
            except Exception as exc:  # un horizonte fallido no impide los demás (igual que el servicio)
                log.exception("Entrenamiento fallido h=%s tenant=%s", h, inputs.tenant_id)
                results[h] = HorizonResult(horizon=h, status="failed", reason=f"{type(exc).__name__}: {exc}"[:500])
        progress("evaluating", 82)
        coherence = evaluate_coherence(bundle, results)
        activity_eval = evaluate_activity(fb, list(bundle.cutoffs), bundle.last_complete, cfg)
        if cfg.get("s05_enabled", True):
            try:
                finalization = finalize_s05(bundle, results, cfg)
            except Exception as exc:  # noqa: BLE001  S05 agrega salidas: su fallo no tumba la probabilidad ni la cantidad
                log.exception("Finalización de pedidos fallida tenant=%s", inputs.tenant_id)
                finalization = {"status": "failed", "reason": f"{type(exc).__name__}: {exc}"[:500]}
            try:
                starts = [int(to_day(r.manifest["blocks"]["holdout"]["cutoff_from"])) for r in results.values()
                          if r.status in ("trained", "reference_only") and (r.manifest.get("blocks") or {}).get("holdout")]
                window_eval = evaluate_window(bundle, WindowParams.from_cfg(cfg), holdout_starts=starts or None)
                attach_window(results, window_eval, WindowParams.from_dict(window_eval.get("params"), cfg))
            except Exception as exc:  # noqa: BLE001
                log.exception("Evaluación de la ventana fallida tenant=%s", inputs.tenant_id)
                window_eval = {"status": "failed", "reason": f"{type(exc).__name__}: {exc}"[:500], "levels": None}
    return CustomerRun(inputs, results, bundle, coherence, window_eval, finalization, activity_eval, last_complete, seed, requested,
                       time.perf_counter() - t0, stop)


# ───────────────────────────── candidatos a registrar ─────────────────────────────

@dataclass
class ModelEntry:
    """Un artefacto candidato: lo que se serializa y los metadatos con que `publish` registrará su fila `candidate`."""

    name: str                                   # nombre del archivo sin extensión (el manifiesto firmado lo liga)
    obj: Any = field(repr=False)
    model_family: str
    role: str
    strategy_level: str
    hyperparameters: dict[str, Any]
    served: bool
    series_count: int = 0
    demand_class: str | None = None
    slot: str | None = None
    purpose: str | None = None
    granularity: str | None = None
    notes: str | None = None


def demand_entries(run: DemandRun) -> list[ModelEntry]:
    """Artefactos de una corrida de demanda: solo LightGBM global se serializa (los estadísticos se reajustan en cada corrida, sin artefacto)."""
    result = run.result
    info = result.families.get("lightgbm_global")
    if result.lgbm is None or not info or info.get("status") == "failed":
        return []
    classes = info.get("demand_classes") or []
    return [ModelEntry(
        name="lightgbm_global", obj=result.lgbm.mlf, model_family="lightgbm_global", role=info.get("role", "primary"),
        strategy_level=str(info["level"]), hyperparameters=dict(result.lgbm.params), served=info.get("status") == "production",
        series_count=int(info["series_count"]), demand_class=classes[0] if len(classes) == 1 else None, slot=None,
        purpose="Pronóstico de demanda diaria por serie (sucursal × producto)", granularity="series_day")]


def customer_entries(run: CustomerRun) -> list[ModelEntry]:
    """Artefactos de una corrida del Customer Core: por horizonte y objetivo, el servido, el retador y la referencia (como `_persist_models`)."""
    from predictily_engine.customer.service import _artifact_meta, _slot

    entries: list[ModelEntry] = []
    for h, r in sorted(run.results.items()):
        if r.status not in ("trained", "reference_only"):
            continue
        for target, label in (("probability", "prob"), ("quantity", "qty")):
            art_served, art_ch, art_ref = r.artifacts[label], r.artifacts.get(f"{label}_challenger"), r.artifacts.get(f"{label}_reference")
            info = r.prob if target == "probability" else r.qty
            fam_key = "family" if target == "probability" else "objective"
            to_store = [(art_served, True)]
            if art_ch is not None and art_ch is not art_served:
                to_store.append((art_ch, False))
            if art_ref is not None and art_ref is not art_served:
                to_store.append((art_ref, False))
            for art, is_served in to_store:
                family = art[fam_key] if fam_key in art else art["objective"]
                art["meta"] = _artifact_meta(r, target, family, is_served)
                hp = {"slot": _slot(h, target), "horizon_days": h, "target": target, "family": family, "tier": r.manifest.get("tier"),
                      "kind": art["kind"], "served": bool(is_served),
                      "calibration_method": art.get("calibrator").method if art.get("calibrator") else None,
                      "gate": info.get("gate"), "neg_rate": art.get("neg_rate"), "scale": art.get("scale"),
                      "holdout": (info["served"]["holdout"] if is_served else (info.get("challenger") or {}).get("holdout")) if info else None,
                      "ranking_source": art["meta"].get("ranking_source") if target == "probability" else None}
                entries.append(ModelEntry(
                    name=f"h{h}_{target}_{family}", obj=art, model_family=f"customer_{target}_{family}"[:40],
                    role="primary" if art["kind"] == "complex" else "fallback", strategy_level="B", hyperparameters=hp, served=bool(is_served),
                    slot=_slot(h, target), series_count=0, purpose="Compra de un producto por comprador" if target == "probability"
                    else "Cantidad esperada si compra", granularity="customer_sku",
                    notes=None if (is_served or art["kind"] == "reference") else "Evaluado y validado; no superó la regla de promoción (%s)." % run.inputs.cfg["promotion_rule_version"]))
    return entries


# ───────────────────────────── resúmenes de métricas (para comparar y registrar) ─────────────────────────────

METRICS_SCHEMA = "predictily-metrics-summary/1"
_DEMAND_FIELDS = ("wape", "bias", "mae", "rmse", "coverage_80", "coverage_95", "n_obs")


def _num(value: Any) -> float | None:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if number == number and number not in (float("inf"), float("-inf")) else None


def demand_metrics_summary(evaluations: list[dict[str, Any]]) -> dict[str, Any]:
    """Resumen compacto del período final reservado (holdout) de una corrida de demanda: lo servido (`selection`) y el baseline por nivel, horizonte y segmento.

    Solo cifras que la fila declara (las nulas por «datos insuficientes» quedan nulas con su estado): ningún dato se inventa.
    """
    out: dict[str, Any] = {"schema": METRICS_SCHEMA, "core": "demand", "scope": "holdout", "selected": {}, "baseline": {}}
    for e in evaluations:
        if e.get("evaluation_type") != "backtest" or (e.get("extra") or {}).get("evaluation_scope") != "holdout":
            continue
        role = "selected" if e.get("model_family") == "selection" else ("baseline" if e.get("is_baseline") else None)
        if role is None:
            continue
        level, key, horizon = e.get("level"), e.get("level_key"), e.get("horizon_days")
        if level not in ("overall", "horizon", "segment", "horizon_total", "total"):
            continue
        slot = ":".join(str(x) for x in (level, key, horizon) if x is not None) or level
        row = {f: _num(e.get(f)) if f != "n_obs" else (int(e["n_obs"]) if e.get("n_obs") is not None else None) for f in _DEMAND_FIELDS}
        status = (e.get("metrics") or {}).get("status") or (e.get("metrics") or {}).get("wape_status")
        if status:
            row["status"] = status
        out[role][slot] = row
    return out


def customer_metrics_summary(run: CustomerRun) -> dict[str, Any]:
    """Resumen por horizonte del holdout del Customer Core (probabilidad, cantidad, esperanza y ranking), el mismo que el servicio guarda en `summary`."""
    from predictily_engine.customer.service import _train_summary

    horizons = _train_summary(run.results, run.bundle, run.coherence, run.activity_eval, 0, 0.0, [])["horizons"]
    compact: dict[str, Any] = {}
    for h, item in horizons.items():
        if item.get("status") not in ("trained", "reference_only"):
            compact[h] = {"status": item.get("status"), "reason_code": item.get("reason_code")}
            continue
        compact[h] = {"status": item["status"], "tier": item.get("tier"), "probability": item.get("probability"), "quantity": item.get("quantity"),
                      "expected_quantity": item.get("expected_quantity"), "ranking_source": item.get("ranking_source")}
    return {"schema": METRICS_SCHEMA, "core": "customer", "scope": "holdout", "horizons": compact}


def data_gate_block(assessment: Any, module: str) -> dict[str, Any]:
    """Resultado del gate de calidad (W2-C) que `publish` declara en `artifact_compat.data_gate` cuando el candidato no tiene corrida enlazada."""
    return {"outcome": assessment.outcome, "module": module, "rules_version": assessment.rules_version, "dataset": assessment.dataset,
            "as_of": assessment.as_of.isoformat() if assessment.as_of else None, "reason_codes": assessment.reason_codes()}


def library_versions_for(core: str) -> dict[str, str]:
    if core == "demand":
        from predictily_engine.pipeline.forecast_run import library_versions
    else:
        from predictily_engine.customer.service import library_versions
    return library_versions()


def feature_schema_for(core: str) -> str:
    if core == "demand":
        return FEATURE_SET_VERSION
    from predictily_engine.customer import FEATURE_SCHEMA_VERSION

    return FEATURE_SCHEMA_VERSION


def require_core(core: str) -> str:
    if core not in CORES:
        raise CliError(2, "--core debe ser demand o customer")
    return core


def optuna_available() -> tuple[bool, str]:
    from predictily_engine.selection.tuning import optuna_status

    return optuna_status()


def require_optuna() -> None:
    ok, message = optuna_available()
    if not ok:
        raise CliError(EXIT_CONFIG, f"El ajuste con Optuna no está disponible: {message}")


__all__ = ["CORES", "CustomerInputs", "CustomerRun", "DemandInputs", "DemandRun", "ModelEntry", "customer_entries",
           "customer_metrics_summary", "data_gate_block", "demand_entries", "demand_metrics_summary", "prepare_customer_from_db",
           "prepare_demand_from_db", "run_customer", "run_demand"]
