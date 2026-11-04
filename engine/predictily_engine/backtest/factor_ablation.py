"""Ablation reproducible de factores (W6-E · V2.5 §26 T12 · ADR-V25-16). Herramienta P2: no promueve nada por sí sola.

Para una empresa (datos ficticios o una BASE DE PRUEBAS en solo lectura) corre el backtest rolling-origin as-of del LightGBM SIN factores
(`baseline`) y CON cada factor candidato por separado (`+factor`) y con su combinación, sobre las MISMAS ventanas de selección y con los mismos días
evaluables, y entrega las diferencias de métricas por horizonte y segmento con las definiciones y los denominadores de W3-A (`demand/segments.py`):
WAPE = Σ|error| / Σ reales con su denominador y su estado (`ok | unstable | undefined`), segmentos con pocas observaciones sin cifras, y las mismas
dimensiones (clase de demanda, categoría, sucursal, tramo de volumen, vida de la serie).

Reglas que la hacen confiable (las MISMAS que aplica el pipeline en `backtest/factor_arm.py`):

1. **El período final reservado (holdout) NUNCA se entrena ni se mide aquí.** Solo se entrena y se mide en las ventanas de selección
   (`BacktestPlan.cutoffs`). `holdout_untouched` se CALCULA a partir de los orígenes que realmente se entrenaron (no es una constante).
2. **Entrenado como se sirve (H1).** Las columnas de factor se arman en modo «tal como se sirve» (`availability/served.py`): una fila de entrenamiento recibe
   el valor visible en SU origen y arrastrado como en la predicción, nunca el del propio día; un valor vencido es faltante.
3. **Control placebo (H1 b).** Un factor se promueve si cumple la regla (mejora relativa mínima del WAPE y fracción de ventanas ganadas, con un denominador
   estable) **y** supera, con el mismo margen, al MEJOR de K placebos con semilla (el mismo factor desplazado en el tiempo). La evidencia de los placebos
   se registra.
4. **Comparaciones múltiples (H1 c).** Una combinación solo se promueve si CADA miembro se promovió individualmente y la combinación también pasa la regla
   y sus placebos; si un miembro no pasó, la combinación se informa como `reject` (`member_not_promoted`) y no se promueve aunque su WAPE sea bueno.
5. `promote` = la pasa; `reject` = evidencia suficiente de que no mejora; `inconclusive` = evidencia insuficiente (pocas ventanas, denominador pequeño,
   brazo incompleto, placebos insuficientes) o mejora global con regresión en un segmento crítico. Sin evidencia no se promueve (T12). La decisión es del
   CONJUNTO (`decision_scope = factor_set`); cada celda por horizonte y segmento trae su propio veredicto de métrica (`cell_verdict`).
6. **Solo lectura.** No escribe NINGUNA tabla: ni `factor_evaluations` ni las de producción. Devuelve filas con la forma de `factor_evaluations`
   (`evaluation_rows`) para que un proceso autorizado las registre. Con base de datos: URL analizada con `make_url` (sin `dbname`/`host`/`options` en la
   consulta), nombre con «test» como palabra, `current_database()` comprobado tras conectar y `SET TRANSACTION READ ONLY` en cada transacción
   (`db/safety.py`): jamás una base de producción.

Sin costos de API: no hay fuentes externas conectadas; el costo registrado es cómputo (segundos y entrenamientos).
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from datetime import date
from typing import Any, Mapping

import numpy as np
import pandas as pd

from predictily_engine.availability.pipeline import ACTIVATION_SETTING, FactorPipeline, active_factor_keys
from predictily_engine.backtest.factor_arm import (
    KEPT,
    SELECTED,
    ArmContext,
    FactorArmRule,
    FactorOutcome,
    Run,
    duel,
)
from predictily_engine.backtest.rolling import asof_windows, plan_backtest
from predictily_engine.db import safety
from predictily_engine.demand import segments as seg
from predictily_engine.demand.horizons import SUPPORTED_HORIZONS
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.data import TenantData
from predictily_engine.platform.config import effective_config_record, resolve_config
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.quality.assessment import assess_demand

log = logging.getLogger(__name__)

ABLATION_VERSION = "factor-ablation-v2"
PROMOTE, REJECT, INCONCLUSIVE = "promote", "reject", "inconclusive"
CRITICAL_SEGMENTS = ("demand_class", "volume_tier")        # los mismos que protege la política V2.5 por defecto (promotion.v25_critical_segments)
SEGMENT_MAX_REGRESSION = 0.10                               # regresión relativa máxima del WAPE en un segmento crítico (promotion.v25_segment_max_regression)
INCONCLUSIVE_REASONS = {"insufficient_windows", "denominator_below_minimum", "factor_arm_incomplete", "no_plain_rows", "factor_arm_failed",
                        "not_stable_across_windows", "placebo_unavailable"}
OVERALL = "overall"

NOTES = [
    "Herramienta P2 de solo lectura: no escribe factor_evaluations ni ninguna otra tabla; `evaluation_rows` tiene la forma de factor_evaluations "
    "para que un proceso autorizado las registre.",
    "El período final reservado (holdout) no se entrena ni se mide aquí; `holdout_untouched` se calcula de los orígenes entrenados. La decisión usa solo las "
    "ventanas de selección y es del CONJUNTO de factores (decision_scope = factor_set).",
    "Los factores son series de la EMPRESA (un valor por fecha): no distinguen series. Se entrenan tal como se sirven (valor visible en el origen de cada "
    "bloque y arrastrado; vencido = faltante), no con el valor del propio día.",
    "Control placebo: un factor debe superar al mejor de K placebos con semilla (el mismo factor desplazado en el tiempo); una combinación solo se promueve si "
    "cada miembro se promovió individualmente.",
    "Umbrales de la regla (mejora mínima, ventanas, denominador, K placebos) son valores iniciales de diseño, a aprobar en el piloto; no son universales.",
]


@dataclass
class AblationContext:
    data: TenantData
    panel: Any
    features: FeatureBuilder
    windows: Any
    plan: Any
    core_config: dict[str, Any]
    horizon_end: date
    cat: pd.Series
    seed: int
    n_jobs: int


def build_context(data: TenantData, *, max_windows: int = 3, seed: int = 42, n_jobs: int = 2, horizon_days: int = 90) -> AblationContext:
    """Panel, constructor sin factores, ventanas as-of y plan del backtest, como los arma `run_pipeline` (misma configuración efectiva y mismo
    gate de calidad, que deshabilita las mismas features opcionales)."""
    record = effective_config_record("demand", data.settings, data.sector)
    core_config = record["effective_config"]
    panel = build_panel(data, DayRules.from_config(core_config))
    if panel.profile.empty or not panel.profile["is_active"].any():
        raise ValueError("No hay series activas con ventas para evaluar.")
    quality = assess_demand(data, panel)
    if quality.blocked:
        raise ValueError(f"El gate de calidad de datos bloquea la evaluación: {quality.block_message()}")
    horizon_end = (panel.cutoff + pd.Timedelta(days=horizon_days)).date()
    features = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end, promotion_revisions=data.promotion_revisions,
                              promotions_known_on=data.promotions_known_on, disabled_features=quality.disabled_features)
    windows = asof_windows(data, panel, features, horizon_end, DayRules.from_config(core_config))
    plan = plan_backtest(panel, max_windows)
    if plan is None:
        raise ValueError("Historia insuficiente para un backtest (< 70 días): no hay ablation posible.")
    return AblationContext(data, panel, features, windows, plan, core_config, horizon_end, data.products.set_index("product_id")["category_id"],
                           seed, n_jobs)


def _resolve_all(ctx: AblationContext, keys: list[str]) -> Any:
    """Resolución as-of retroactiva, en modo «tal como se sirve», de TODOS los factores candidatos (se evalúan aunque la empresa no los haya activado: es lo
    que la ablation decide). Cada conjunto evaluado usa `resolution.select(conjunto)`."""
    data = ctx.data
    config, _, _ = resolve_config("factors", data.settings, data.sector)
    pipeline = FactorPipeline({**config, "enabled": True}, {k: {"active": True} for k in keys})
    dates = pd.date_range(pd.Timestamp(ctx.panel.df["ds"].min()).normalize(), pd.Timestamp(ctx.horizon_end).normalize(), freq="D")
    return pipeline.resolve(data.factor_inputs, dates, ctx.panel.cutoff, timezone=data.timezone, retroactive=True, served_block_days=int(ctx.plan.horizon))


def _baseline_rows(ctx: AblationContext, origins: list[Any]) -> pd.DataFrame:
    from predictily_engine.selection.tuning import lightgbm_backtest_rows       # import tardío: tuning importa backtest.rolling

    return lightgbm_backtest_rows(ctx.panel, ctx.features, ctx.plan, ctx.windows, {}, ctx.seed, ctx.n_jobs, origins=origins)


# ───────────────────────────────────────────── métricas por horizonte y segmento (W3-A) ─────────────────────────────────────────────

def _arm_view(d: dict[str, Any]) -> dict[str, Any]:
    """Vista de un brazo en una celda. Un segmento con pocas observaciones (`insufficient_data`) conserva sus tamaños de muestra y SUS MOTIVOS,
    pero NO sus cifras de error (igual que `model_evaluations` en W3-A): no se muestra lo que no se puede sostener."""
    ok = d["status"] == seg.STATUS_OK
    trusted = d["wape"]["status"] == "ok"
    return {"status": d["status"], "n_obs": d["n_ok"], "n_series": d["n_series"], "denominator": d["denominator"],
            "sum_abs_error": d["sum_abs_error"] if ok else None, "wape": d["wape"]["trusted"] if ok else None, "wape_status": d["wape"]["status"],
            "wape_raw": d["wape"]["value"] if ok else None, "mase": d["mase"] if ok else None, "mae": d["mae"] if ok else None,
            "bias": d["bias_raw"] if ok and trusted else None, "excluded": d["excluded"], "insufficient_reasons": d["insufficient_reasons"]}


def _delta(base: dict[str, Any], cand: dict[str, Any], cfg: seg.SegmentConfig) -> dict[str, Any]:
    """Diferencia candidato − baseline (negativa = mejora del error). Mismas reglas que la comparación de W3-A: WAPE si ambos son confiables;
    si no, el error absoluto agregado solo cuando las observaciones son las mismas; si no, no comparable."""
    if base["status"] != seg.STATUS_OK or cand["status"] != seg.STATUS_OK:
        return {"verdict": "not_comparable", "reason": "insufficient_data", "metric": None, "delta": None, "relative_improvement": None}
    if base["wape_status"] == "ok" and cand["wape_status"] == "ok":
        metric, x, y = "wape", cand["wape"], base["wape"]
    elif base["n_obs"] == cand["n_obs"]:
        metric, x, y = "aggregate_absolute_error", cand["sum_abs_error"], base["sum_abs_error"]
    else:
        return {"verdict": "not_comparable", "reason": "wape_unstable_and_different_support", "metric": None, "delta": None,
                "relative_improvement": None}
    verdict, relative = seg._verdict(x, y, cfg.similar_tolerance)
    return {"verdict": verdict, "reason": None, "metric": metric, "delta": x - y, "relative_improvement": relative}


def compare_arms(baseline: pd.DataFrame, candidate: pd.DataFrame, ctx: AblationContext, cfg: seg.SegmentConfig,
                 horizons: tuple[int, ...] = SUPPORTED_HORIZONS) -> list[dict[str, Any]]:
    """Celdas (dimensión, valor, horizonte H) con las métricas diarias de cada brazo y su diferencia. Base de la métrica: el error DIARIO por
    serie de los pasos 1..H de todas las ventanas de selección (definición de W3-A, `demand.segments`)."""
    rows = pd.concat([baseline.assign(family="baseline"), candidate.assign(family="candidate")], ignore_index=True)
    context = seg.build_context(rows, ctx.panel, ctx.windows, cfg)
    annotated = seg.annotate(rows, ctx.cat, context)
    prep = seg._prepare(annotated, horizons)
    prep["seg_overall"] = "all"
    n_bands = len(horizons)
    emit = [(j, h) for j, h in enumerate(horizons) if h <= int(rows["step"].max())]
    dims = [(OVERALL, "seg_overall")] + [(d, seg.SEG_COL[d]) for d in seg.DIMENSIONS if annotated[seg.SEG_COL[d]].notna().any()]
    cells: list[dict[str, Any]] = []
    ok_rows = prep[prep["n_ok"] > 0]
    for dim, col in dims:
        grouped = (prep.dropna(subset=[col]).groupby(["family", col, "window", "band"], observed=True, sort=False)[seg.SUM_COLS].sum()
                   .reset_index().rename(columns={col: "value"}))
        grouped.insert(1, "dim", dim)
        sums = seg._sums_index(seg._cumulate(grouped, ["family", "dim", "value", "window"], n_bands, seg.SUM_COLS), seg.SUM_COLS)
        for j, h in emit:
            n_series = ok_rows[ok_rows["band"] <= j].groupby(["family", col])["unique_id"].nunique()
            values = sorted({k[2] for k in sums if k[1] == dim and k[3] == j})
            for value in values:
                views = {}
                for arm in ("baseline", "candidate"):
                    s = sums.get((arm, dim, value, j))
                    if s is None or s["n_rows"] <= 0:
                        break
                    views[arm] = _arm_view(seg._daily(s, cfg, int(n_series.get((arm, value), 0))))
                if len(views) < 2:
                    continue
                cells.append({"dimension": dim, "value": value, "horizon_days": h, "baseline": views["baseline"], "candidate": views["candidate"],
                              "delta": _delta(views["baseline"], views["candidate"], cfg)})
    return cells


def _segment_regressions(cells: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Segmentos críticos donde el candidato EMPEORA el WAPE más de `SEGMENT_MAX_REGRESSION` (al mayor horizonte evaluado): una mejora global con
    regresión protegida no se promueve."""
    if not cells:
        return []
    top = max(c["horizon_days"] for c in cells)
    out = []
    for c in cells:
        d = c["delta"]
        if c["dimension"] in CRITICAL_SEGMENTS and c["horizon_days"] == top and d["metric"] == "wape" and d["relative_improvement"] is not None \
                and d["relative_improvement"] < -SEGMENT_MAX_REGRESSION:
            out.append({"dimension": c["dimension"], "value": c["value"], "horizon_days": top, "relative_improvement": d["relative_improvement"],
                        "wape_baseline": c["baseline"]["wape"], "wape_candidate": c["candidate"]["wape"], "denominator": c["baseline"]["denominator"]})
    return out


def _decision(verdict: dict[str, Any], regressions: list[dict[str, Any]]) -> tuple[str, str]:
    if verdict["decision"] == SELECTED:
        if regressions:
            return INCONCLUSIVE, "segment_regression"
        return PROMOTE, verdict["reason"]
    if verdict["decision"] == KEPT and verdict["reason"] in INCONCLUSIVE_REASONS:
        return INCONCLUSIVE, verdict["reason"]
    return REJECT, verdict["reason"]


# ───────────────────────────────────────────── ablation ─────────────────────────────────────────────

def run_ablation(data: TenantData, *, candidates: list[str] | None = None, combinations: bool = True, factor_sets: list[list[str]] | None = None,
                 max_windows: int = 3, seed: int = 42, n_jobs: int = 2, horizons: tuple[int, ...] = SUPPORTED_HORIZONS,
                 label: Mapping[str, Any] | None = None) -> dict[str, Any]:
    """Ablation de los factores candidatos de `data.factor_inputs` (cada uno por separado y, con `combinations`, todos juntos) contra el LightGBM sin
    factores. `factor_sets` fija conjuntos explícitos (p. ej. `[["a", "b"]]` evalúa solo esa combinación) y anula `candidates`/`combinations`; sus
    miembros se evalúan individualmente igual (una combinación solo se promueve si cada miembro se promovió). Solo lectura de memoria: no escribe
    nada. Devuelve un diccionario serializable (ver el docstring del módulo)."""
    if data.factor_inputs is None or not data.factor_inputs.keys:
        raise ValueError("No hay factores candidatos cargados: indique las claves de factor o active alguno en la empresa.")
    if factor_sets is not None:
        keys = sorted({k for group in factor_sets for k in group})
    else:
        keys = sorted(candidates or data.factor_inputs.keys)
    unknown = [k for k in keys if k not in data.factor_inputs.keys]
    if unknown:
        raise ValueError(f"Factores sin datos cargados: {unknown}")
    started = time.perf_counter()
    ctx = build_context(data, max_windows=max_windows, seed=seed, n_jobs=n_jobs)
    plan = ctx.plan
    selection_origins = [c for _, c, hold in plan.origins if not hold]
    rule = FactorArmRule.from_config(resolve_config("factors", data.settings, data.sector)[0], ctx.core_config)
    cfg = seg.SegmentConfig.from_config(ctx.core_config)
    used_origins: list[Any] = [pd.Timestamp(o) for o in selection_origins]
    baseline = _baseline_rows(ctx, selection_origins)
    if baseline.empty:
        raise ValueError("El backtest del LightGBM sin factores no produjo filas (pocas series o historia): no hay ablation posible.")
    resolution = _resolve_all(ctx, keys)
    arm_context = ArmContext(data=data, panel=ctx.panel, features=ctx.features, horizon_end=ctx.horizon_end)
    outcome = FactorOutcome(rule=rule, active=keys, resolution=resolution, arm_factory=arm_context.make_arm, served_block_days=int(plan.horizon))
    run = Run(panel=ctx.panel, plan=plan, windows=ctx.windows, plain=baseline, seed=seed, n_jobs=n_jobs, origins=selection_origins, used_origins=used_origins)
    sets: list[list[str]] = ([sorted(dict.fromkeys(group)) for group in factor_sets] if factor_sets is not None
                             else [[k] for k in keys] + ([keys] if combinations and len(keys) > 1 else []))
    singles: dict[str, tuple[dict[str, Any], pd.DataFrame]] = {}

    def single(key: str) -> tuple[dict[str, Any], pd.DataFrame]:
        if key not in singles:
            singles[key] = outcome.evaluate_set(outcome.arm_factory(resolution.select([key])), [key], run)
        return singles[key]

    results = []
    evaluation_rows: list[dict[str, Any]] = []
    for factor_set in sets:
        t0, fits0 = time.perf_counter(), len(run.used_origins)
        used = [k for k in factor_set if k in resolution.used]
        entry: dict[str, Any] = {"factor_set": factor_set, "used": used, "decision_scope": "factor_set",
                                 "dropped": [{k: v for k, v in d.items() if k in ("key", "status", "reason", "fallback")}
                                             for d in resolution.dropped if d["key"] in factor_set],
                                 "scenario_only": [k for k in resolution.scenario_only if k in factor_set], "members": None}
        if not used:
            entry.update({"decision": INCONCLUSIVE, "reason": "not_evaluable", "evidence": None, "cells": [], "segment_regressions": [],
                          "cost": {"compute_seconds": round(time.perf_counter() - t0, 2), "lightgbm_fits": 0, "api_calls": 0}})
            results.append(entry)
            continue
        if len(used) == 1:
            verdict, candidate = single(used[0])
        else:
            member_verdicts = {k: single(k)[0] for k in used}
            entry["members"] = {k: {"decision": _decision(v, [])[0], "reason": v["reason"]} for k, v in member_verdicts.items()}
            arm_c = outcome.arm_factory(resolution.select(used))
            candidate = outcome.train_arm(arm_c, run, selection_origins)
            verdict = duel(baseline, candidate, rule)
            blocked = [k for k, v in member_verdicts.items() if v["decision"] != SELECTED]
            if blocked:       # comparaciones múltiples: la combinación no se promueve si algún miembro no pasó solo (ni se gasta en sus placebos)
                verdict = {"decision": KEPT, "reason": "member_not_promoted", "evidence": {**verdict["evidence"], "blocked_by": blocked}}
            elif verdict["decision"] == SELECTED:
                gate = outcome.placebo_control(arm_c, used, verdict["evidence"], run)
                verdict["evidence"]["placebo"] = gate
                if not gate["passed"]:
                    verdict = {"decision": KEPT, "reason": gate["reason"], "evidence": verdict["evidence"]}
        cells = compare_arms(baseline, candidate, ctx, cfg, horizons) if not candidate.empty and verdict["reason"] != "factor_arm_incomplete" else []
        regressions = _segment_regressions(cells)
        decision, reason = _decision(verdict, regressions)
        if reason == "member_not_promoted":
            member_decisions = {entry["members"][k]["decision"] for k in verdict["evidence"]["blocked_by"]}
            decision = INCONCLUSIVE if member_decisions == {INCONCLUSIVE} else REJECT
        entry.update({"decision": decision, "reason": reason, "evidence": verdict["evidence"], "cells": cells, "segment_regressions": regressions,
                      "cost": {"compute_seconds": round(time.perf_counter() - t0, 2), "lightgbm_fits": len(run.used_origins) - fits0, "api_calls": 0}})
        results.append(entry)
        evaluation_rows.extend(_evaluation_rows(entry, ctx, holdout_untouched=_holdout_untouched(plan, run.used_origins)))
    cutoff = ctx.panel.cutoff.date()
    holdout_untouched = _holdout_untouched(plan, run.used_origins)
    return {
        "version": ABLATION_VERSION, "label": dict(label or {}), "cutoff": cutoff.isoformat(),
        "plan": {"horizon_days": int(plan.horizon), "step_days": int(plan.step), "selection_windows": int(plan.windows),
                 "selection_origins": [pd.Timestamp(c).date().isoformat() for c in selection_origins],
                 "holdout_origin": None if plan.holdout_cutoff is None else pd.Timestamp(plan.holdout_cutoff).date().isoformat(),
                 "trained_origins": sorted({pd.Timestamp(o).date().isoformat() for o in run.used_origins}),
                 "holdout_untouched": holdout_untouched, "independent_holdout_available": plan.has_holdout, "served_block_days": int(plan.horizon)},
        "rule": rule.params(), "rule_version": rule.version,
        "metric_definition": {"source": "demand/segments.py (W3-A)", "basis": seg.HORIZON_BASIS, "text": seg.HORIZON_BASIS_TEXT,
                              "wape": "Σ|error| / Σ reales de los días evaluables; denominador y estado (ok | unstable | undefined) en cada celda",
                              "wape_min_denominator": cfg.wape_min_denominator, "segment_min_series": cfg.min_series, "segment_min_obs": cfg.min_obs,
                              "delta": "candidato − baseline (negativo = mejora del error)"},
        "baseline": {"model": "lightgbm_global sin factores", "rows": int(len(baseline))},
        "candidates": keys, "sets": results, "evaluation_rows": evaluation_rows,
        "factor_stats": {k: v for k, v in resolution.stats.items()},
        "cost": {"compute_seconds": round(time.perf_counter() - started, 2), "lightgbm_fits": len(run.used_origins), "api_calls": 0},
        "writes_database": False, "notes": list(NOTES),
    }


def _holdout_untouched(plan: Any, used_origins: list[Any]) -> bool:
    """El período final reservado no se entrenó: se CALCULA de los orígenes entrenados (no es una constante)."""
    if plan.holdout_cutoff is None:
        return True
    hold = pd.Timestamp(plan.holdout_cutoff)
    return not any(pd.Timestamp(o) >= hold for o in used_origins)


def _evaluation_rows(entry: dict[str, Any], ctx: AblationContext, *, holdout_untouched: bool) -> list[dict[str, Any]]:
    """Filas con la forma de `factor_evaluations` (una por conjunto, horizonte y segmento comparable). NO se insertan: las registra un proceso autorizado.
    `decision` es la del CONJUNTO (`decision_scope = factor_set`); `cell_verdict` es el veredicto de métrica de esa celda (better | similar | worse)."""
    out = []
    cutoff = ctx.panel.cutoff.date().isoformat()
    for c in entry["cells"]:
        if c["delta"]["metric"] is None and c["dimension"] != OVERALL:
            continue
        out.append({
            "factor_set": entry["factor_set"], "family": None, "segment": None if c["dimension"] == OVERALL else f"{c['dimension']}:{c['value']}",
            "horizon_days": c["horizon_days"], "cutoff": cutoff, "baseline_metrics": c["baseline"], "candidate_metrics": c["candidate"],
            "delta": c["delta"], "cell_verdict": c["delta"]["verdict"], "holdout_untouched": bool(holdout_untouched), "decision": entry["decision"],
            "decision_scope": "factor_set", "decision_reason": entry["reason"],
            "cost": entry["cost"], "notes": f"{ABLATION_VERSION}; la decisión es del conjunto ({entry['reason']}), con las ventanas de selección; sin período final"})
    return out


# ───────────────────────────────────────────── base de datos de pruebas (solo lectura) ─────────────────────────────────────────────

def database_name(url: str) -> str:
    """Nombre de la base de la URL (analizada con `make_url`; vacío si no la declara)."""
    from sqlalchemy.engine import make_url

    try:
        return make_url(url).database or ""
    except Exception:       # noqa: BLE001
        return ""


def read_only_engine(url: str):
    """Engine de SOLO LECTURA sobre una base de PRUEBAS (ver `db/safety.py`): URL analizada, nombre con «test» como palabra, sin `dbname`/`host`/`options`
    en la consulta, sesión con `default_transaction_read_only = on` y `SET TRANSACTION READ ONLY` en cada transacción."""
    return safety.read_only_engine(url)


def load_from_database(url: str, tenant_id: int, *, cutoff: date | None = None, factors: list[str] | None = None) -> TenantData:
    """Datos de la empresa para la ablation, leídos con una conexión de solo lectura. `factors`: claves candidatas; sin ellas, las que la empresa
    activó en `tenants.settings.factor_activation` (con o sin el interruptor global)."""
    from sqlalchemy import text

    from predictily_engine.db.repository import TenantRepository
    from predictily_engine.demand.coverage import SalesCoverage
    from predictily_engine.pipeline.data import load_factor_inputs, load_tenant_data

    engine = read_only_engine(url)
    try:
        with engine.connect() as conn:
            safety.assert_connected_to_test_database(conn)               # la base REAL de la conexión, no la URL declarada
            if conn.execute(text("SHOW transaction_read_only")).scalar() != "on":
                raise PermissionError("La conexión no es de solo lectura: se aborta.")
            repo = TenantRepository(conn, tenant_id)
            repo.analytic_reads()
            tenant = repo.tenant()
            last = repo.last_sale_date()
            if last is None:
                raise ValueError("La empresa no tiene ventas válidas importadas.")
            cutoff = min(cutoff, last) if cutoff is not None else last
            branches = repo.branches()
            active = (set(branches.loc[branches["is_active"].astype(bool), "branch_id"].astype(int))
                      if "is_active" in branches else set(branches["branch_id"].astype(int)))
            coverage = SalesCoverage.resolve(conn, tenant_id, repo.sales_coverage(), active)
            cutoff = coverage.limit_cutoff(cutoff)
            data = coverage.apply(load_tenant_data(repo, cutoff, live=False))
            keys = factors or active_factor_keys(tenant.get("settings"))
            if not keys:
                raise ValueError("Indique los factores a evaluar (--factors) o actívelos en la empresa "
                                 f"(tenants.settings.{ACTIVATION_SETTING}.<clave>.active).")
            data.factor_inputs = load_factor_inputs(repo, tenant, keys=list(keys))
    finally:
        engine.dispose()
    return data


# ───────────────────────────────────────────── presentación ─────────────────────────────────────────────

def render_markdown(report: dict[str, Any]) -> str:
    """Resumen legible del informe: decisión por conjunto y WAPE por horizonte (global)."""
    lines = [f"# Ablation de factores ({report['version']})", "",
             f"Corte {report['cutoff']} · {report['plan']['selection_windows']} ventanas de selección · horizonte de backtest "
             f"{report['plan']['horizon_days']} d · período final reservado: **intacto**.", "",
             "| Conjunto de factores | Decisión | Motivo | WAPE sin factores | WAPE con factores | Mejora relativa | Ventanas ganadas |", "|---|---|---|---|---|---|---|"]
    for s in report["sets"]:
        ev = s.get("evidence") or {}
        won = f"{ev.get('windows_better')}/{ev.get('windows_total')}" if ev.get("windows_total") is not None else "—"
        lines.append(f"| {', '.join(s['factor_set'])} | {s['decision']} | {s['reason']} | {_num(ev.get('wape_plain'))} | {_num(ev.get('wape_factor'))} | "
                     f"{_num(ev.get('relative_improvement'))} | {won} |")
    lines += ["", "## Diferencia de WAPE por horizonte (global; candidato − baseline)", "",
              "| Conjunto | H (días) | WAPE baseline | WAPE candidato | Δ | Denominador (Σ reales) | Estado WAPE |", "|---|---|---|---|---|---|---|"]
    for s in report["sets"]:
        for c in s["cells"]:
            if c["dimension"] != OVERALL:
                continue
            lines.append(f"| {', '.join(s['factor_set'])} | {c['horizon_days']} | {_num(c['baseline']['wape_raw'])} | {_num(c['candidate']['wape_raw'])} | "
                         f"{_num(c['delta']['delta'], sign=True)} | {c['baseline']['denominator']:.0f} | {c['baseline']['wape_status']} |")
    lines += ["", *[f"- {n}" for n in report["notes"]]]
    return "\n".join(lines) + "\n"


def _num(value: Any, sign: bool = False) -> str:
    if value is None or (isinstance(value, float) and not np.isfinite(value)):
        return "—"
    return f"{value:+.3f}" if sign else f"{value:.3f}"


__all__ = ["ABLATION_VERSION", "INCONCLUSIVE", "PROMOTE", "REJECT", "AblationContext", "build_context", "compare_arms", "database_name",
           "load_from_database", "read_only_engine", "render_markdown", "run_ablation"]
