"""Pipeline de pronóstico puro (sin acceso a base de datos).

Entrada: TenantData de UNA empresa. Salida: PipelineResult con todo lo necesario para
persistir (valores por nodo, evaluaciones, selección, recomendaciones, resumen, huella, manifiesto).

Etapa 2 · P1 (docs/architecture/v2/P1-demanda.md): horizontes 7/14/15/30/60/90 con abstención explícita,
métricas MASE/RMSSE/pinball/dinero, P50 solo si se evalúa, modelo SERVIDO coherente (E2-19), casos
especiales (productos nuevos, descontinuados, aperturas/cierres, eventos extraordinarios), huella de
datos completa, configuración efectiva y manifiesto del protocolo de evaluación.
"""

from __future__ import annotations

import hashlib
import logging
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.backtest.factor_arm import FactorOutcome, factor_config_record, prepare_factor_arm
from predictily_engine.backtest.rolling import (
    AsOfWindows,
    BacktestPlan,
    asof_windows,
    eligible_ids,
    plan_backtest,
    run_backtest,
    series_scale,
)
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.demand import evaluation as ev
from predictily_engine.demand import horizons as hz
from predictily_engine.demand import segments as seg_metrics
from predictily_engine.demand.lifecycle import (
    LABEL_CLOSED_DAY,
    LABEL_EXCEPTIONAL,
    LifecyclePlan,
    flag_events,
    known_events,
    plan_lifecycle,
    short_forecast,
    zero_rows,
)
from predictily_engine.demand.manifest import build_manifest
from predictily_engine.demand.snapshot import build_snapshot
from predictily_engine.availability.contracts import FeatureContractError
from predictily_engine.availability.pipeline import factor_pipeline_limitations
from predictily_engine.features.builder import PRICE_ASSUMPTION, FeatureBuilder, last_known_price
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket, identity_bucket
from predictily_engine.inventory.recommendations import (
    MAX_INTERNAL_HORIZON,
    LeadTimeErrorModel,
    build_recommendations,
    required_coverage_days,
)
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import (
    BASELINE_FAMILY,
    FAMILIES,
    STATISTICAL,
    WEEKDAY_BASES,
    WEEKDAY_SWITCH_FAMILIES,
    WEEKDAY_PROFILE_DAYS,
)
from predictily_engine.models.statistical import common_weekday_profile, forecast_statistical
from predictily_engine.pipeline.data import TenantData
from predictily_engine.platform.config import config_hash, effective_config_record
from predictily_engine.platform.snapshot import DataSnapshot
from predictily_engine.profiling.days import DayRules, closed_future_rows, operating_weekdays_applied
from predictily_engine.profiling.readiness import compute_readiness
from predictily_engine.profiling.series import Panel, build_panel
from predictily_engine.quality.assessment import QualityAssessment, assess_demand
from predictily_engine.reconciliation.hierarchy import AGG_LEVELS, aggregate, to_periods
from predictily_engine.selection import shadow as shadow_stage                                   # W3-B: champion/challenger y shadow mode
from predictily_engine.selection.promotion import W3A_SEGMENT_SOURCE, PromotionConfig, PromotionOutcome, run_selection_stage   # W3-B: política heredada + V2.5
from predictily_engine.selection.selector import Selection, select_models, selected_rows

log = logging.getLogger(__name__)

ProgressFn = Callable[[str, int], None]

# Compatibilidad: los nombres públicos de la evaluación viven ahora en `demand.evaluation`.
EVALUATION_PRIMARY = ev.EVALUATION_PRIMARY
EVALUATION_SELECTION_WINDOWS = ev.EVALUATION_SELECTION_WINDOWS
SCOPE_HOLDOUT = ev.SCOPE_HOLDOUT
SCOPE_SELECTION_WINDOWS = ev.SCOPE_SELECTION_WINDOWS


class InsufficientDataError(ValueError):
    def __init__(self, message: str, details: dict | None = None, assessment: Any = None) -> None:
        super().__init__(message)
        self.details = details or {}
        # W2-C: `QualityAssessment` cuando la causa es un gate BLOCK (el llamador lo persiste en data_quality_reports).
        self.assessment = assessment


@dataclass
class PipelineResult:
    cutoff: date
    data_from: date
    data_to: date
    panel: Panel
    readiness: dict[str, Any]
    selection: Selection  # la SERVIDA (si falló el ganador, el respaldo ocupa su lugar)
    plan: BacktestPlan | None
    evaluations: list[dict[str, Any]]
    families: dict[str, dict[str, Any]]
    forecast_values: pd.DataFrame
    recommendations: pd.DataFrame
    summary: dict[str, Any]
    warnings: list[str]
    training_data_hash: str
    lgbm: LightGBMGlobal | None = None
    timings: dict[str, float] = field(default_factory=dict)
    # Filas del backtest (todas las ventanas; `is_holdout` marca el período final reservado) y
    # selección usada SOLO para evaluar en ese período (elegida con las ventanas anteriores).
    backtest: pd.DataFrame = field(default_factory=pd.DataFrame)
    evaluation_selection: Selection | None = None
    # Etapa 2 · P1
    planned_selection: Selection | None = None  # la elegida por el backtest (antes de aplicar fallos)
    failed_families: dict[str, str] = field(default_factory=dict)  # familia → error del entrenamiento final
    snapshot: DataSnapshot | None = None
    manifest: dict[str, Any] = field(default_factory=dict)
    effective_config: dict[str, Any] = field(default_factory=dict)
    config_version: str | None = None
    horizon_support: dict[int, dict[str, Any]] = field(default_factory=dict)
    quality_status: str = "ok"
    limitations: list[dict[str, str]] = field(default_factory=list)
    assumptions: list[str] = field(default_factory=list)
    lifecycle: LifecyclePlan | None = None
    quality: QualityAssessment | None = None  # W2-C: métricas de dataset y gate de calidad de la ejecución
    # W6-E: pipeline de factores (ADR-V25-16/25). Vacío con el pipeline apagado (por defecto); encendido: qué factores se resolvieron, cómo compitió
    # el modelo con factores con el LightGBM sin ellos y si se sirve (`backtest/factor_arm.py`).
    factors: dict[str, Any] = field(default_factory=dict)
    factor_compat: dict[str, Any] | None = None     # linaje de los factores del modelo SERVIDO (None si no se sirve el modelo con factores): `model_registry.artifact_compat.factors`
    # W3-B: decisiones de ambas políticas por alcance (model_promotion_decisions) y predicciones del challenger (shadow_forecast_values)
    promotion: PromotionOutcome | None = None
    shadow: pd.DataFrame = field(default_factory=lambda: pd.DataFrame(columns=shadow_stage.SHADOW_COLUMNS))


def _noop(stage: str, pct: int) -> None:  # pragma: no cover
    pass


def training_data_hash(panel: Panel) -> str:
    keep = ["unique_id", "ds", "y", "stockout"] + (["day_status"] if "day_status" in panel.df else [])
    cols = panel.df[keep].sort_values(["unique_id", "ds"])
    hashed = pd.util.hash_pandas_object(cols, index=False).to_numpy()
    return hashlib.sha256(hashed.tobytes()).hexdigest()



def recent_unit_costs(recent_sales: pd.DataFrame) -> pd.Series:
    """Costo unitario por (sucursal, producto) = Σ costo / Σ unidades con costo, NaN si ninguna línea trae costo.

    Las sumas se fuerzan a float: sin ningún costo informado las columnas llegan como objetos (Decimal/None) y
    `0 / 0` entre enteros de Python lanzaba ZeroDivisionError y abortaba la ejecución (p. ej. un ERP sin costos)."""
    if recent_sales.empty:
        return pd.Series(dtype=float)
    cost = recent_sales.groupby(["branch_id", "product_id"])[["cost_amount", "cost_units"]].sum().astype(float)
    units = cost["cost_units"]
    return cost["cost_amount"] / units.where(units > 0)

def run_pipeline(
    data: TenantData,
    horizon: int = 90,
    seed: int = 42,
    n_jobs: int = 4,
    min_improvement: float = 0.03,
    max_windows: int = 3,
    max_series_lgbm: int = 5000,
    progress: ProgressFn | None = None,
    force_families: list[str] | None = None,
    horizons: tuple[int, ...] = hz.SUPPORTED_HORIZONS,
    config_record: dict[str, Any] | None = None,
    quality_signals: dict[str, Any] | None = None,
    promotion_record: dict[str, Any] | None = None,
    champions: dict[str, str] | None = None,
    segment_provider: Any = None,
    champions_source: str | None = None,
) -> PipelineResult:
    progress = progress or _noop
    timings: dict[str, float] = {}
    warnings: list[str] = []
    limitations: list[dict[str, str]] = [dict(x) for x in data.coverage_limitations]
    t0 = time.perf_counter()
    np.random.seed(seed)
    horizons = tuple(sorted({int(h) for h in horizons if int(h) <= horizon}))

    # Configuración efectiva (core → perfil sectorial → empresa → opciones de la ejecución), con su huella.
    # `config_record` = `platform.config.effective_config_record` (configuración, procedencia por clave, perfil aplicado
    # y su hash); la ejecución lo conserva: cambiar después la configuración de la empresa no reescribe este registro.
    record = config_record or effective_config_record("demand", data.settings, data.sector)
    core_config = record["effective_config"]
    # W3-B (ADR-V25-21): política heredada parametrizada, política V2.5, shadow y Optuna (bloque `promotion`; por defecto gobierna `legacy`).
    # `champions` = familia servida por alcance en la ejecución vigente anterior (la carga `forecast_run.load_champions`; vacío en corridas históricas).
    promotion_record = promotion_record or effective_config_record("promotion", data.settings, data.sector)
    promo_cfg = PromotionConfig.from_config(promotion_record["effective_config"], min_improvement)
    effective_config = {
        "core": "demand", "config": core_config, "provenance": record["provenance"], "profile": record["profile"],
        "core_config_version": record["config_version"],
        "run": {"seed": seed, "horizon_days": horizon, "horizons": list(horizons), "min_improvement": min_improvement,
                "max_backtest_windows": max_windows, "max_series_lgbm": max_series_lgbm,
                "force_families": force_families, "feature_set_version": FEATURE_SET_VERSION},
        # W3-B: la política de promoción decide lo servido y se registra ahora en la huella de la ejecución (trazabilidad: qué política y con qué parámetros).
        "promotion": {"core_config_version": promotion_record["config_version"], "governing_policy": promo_cfg.governing_policy,
                      "policy_versions": promo_cfg.policy_versions()},
    }
    # W6-E: con el pipeline de factores ENCENDIDO su configuración y la activación de la empresa forman parte de la huella de la ejecución;
    # apagado (por defecto) la huella es exactamente la de siempre.
    factors_record = factor_config_record(data.settings, data.sector)
    if factors_record is not None:
        effective_config["factors"] = factors_record
    config_version = config_hash(effective_config)

    progress("profiling", 10)
    # Semántica de días (ADR-V25-03): fuente ausente, huecos y cierres no son ceros; la regla inferida es configurable.
    day_rules = DayRules.from_config(core_config)
    panel = build_panel(data, day_rules)
    readiness = compute_readiness(data, panel)
    limitations.extend(_day_limitations(panel.day_summary))
    limitations.extend(factor_pipeline_limitations(data.settings, data.sector))   # W2-D: factors.enabled sin cablear ⇒ limitación explícita
    if panel.profile.empty or not panel.profile["is_active"].any():
        raise InsufficientDataError("No hay series activas con ventas para pronosticar.",
                                    {"series_total": int(len(panel.profile))})
    # Gate de calidad de datos (W2-C · ADR-V25-05), ANTES de entrenar: BLOCK detiene la ejecución con un motivo de código estable
    # (insufficient_data); DEGRADE deshabilita features opcionales (quality.features); WARN continúa con avisos. `quality_signals` son
    # las señales de la base as-of (informes de ingestión, alias, desfase); sin ellas esas reglas quedan «no evaluadas», no «aprobadas».
    dq = assess_demand(data, panel, signals=quality_signals)
    if dq.blocked:
        raise InsufficientDataError(dq.block_message(), dq.block_details(), assessment=dq)
    limitations.extend(dq.limitation_items())
    warnings.extend(dq.warning_messages())
    cutoff = panel.cutoff
    prof = panel.profile
    # Casos especiales (nuevos, descontinuados, aperturas/cierres): cada serie con su etiqueta y respaldo.
    lifecycle = plan_lifecycle(prof, panel.df, cutoff, data.products, data.branches)
    active = prof[prof["is_active"] & ~prof["unique_id"].isin(lifecycle.excluded_from_model)]
    if active.empty:
        raise InsufficientDataError("No hay series activas con ventas para pronosticar.",
                                    {"series_total": int(len(prof)), "declared_zero": len(lifecycle.zero_ids),
                                     "abstained": len(lifecycle.abstain)})
    cls_map = prof.set_index("unique_id")["demand_class"]
    cat = data.products.set_index("product_id")["category_id"]
    timings["profiling"] = time.perf_counter() - t0

    progress("building_features", 15)
    # Horizonte interno (F2): las recomendaciones necesitan plazo + revisión aunque el horizonte
    # pedido sea menor (tope MAX_INTERNAL_HORIZON). Solo se persiste hasta `horizon`.
    fc_horizon = max(horizon, min(MAX_INTERNAL_HORIZON, required_coverage_days(
        data.latest_inventory, data.lead_times, data.suppliers, data.effective_settings, active["unique_id"])))
    # Promociones as-of (F5): a futuro de cada corte solo lo conocido ese día (known_at / revisiones).
    horizon_end = (cutoff + pd.Timedelta(days=fc_horizon)).date()
    features = FeatureBuilder(panel.df, data.branches, data.promotions, horizon_end,
                              promotion_revisions=data.promotion_revisions,
                              promotions_known_on=data.promotions_known_on,
                              disabled_features=dq.disabled_features)   # DEGRADE (W2-C): el builder omite las features que el gate deshabilitó
    # Conocimiento as-of (ADR-V25-23): si hubo documentos tardíos, anulaciones o correcciones, cada ventana entrena con lo que
    # se conocía en SU corte (panel, perfil y variables); sus promociones son las conocidas ese día (sin `promotions_known_on`).
    windows = asof_windows(data, panel, features, horizon_end, day_rules)
    if windows is not None and features.disabled_features:   # las ventanas as-of reconstruidas aplican la MISMA política del builder
        windows.make_features = lambda df: FeatureBuilder(df, data.branches, data.promotions, horizon_end,
                                                          promotion_revisions=data.promotion_revisions, **features.options())
    # W6-E · pipeline de factores: apagado (por defecto) ⇒ `factor_outcome` es None y nada de lo que sigue cambia. Encendido: resuelve los factores
    # activos (as-of retroactivo) y arma el brazo con factores, que compite con el LightGBM sin factores solo con las ventanas de selección.
    plan = plan_backtest(panel, max_windows)
    factor_outcome: FactorOutcome | None = prepare_factor_arm(data, panel, features, horizon_end, core_config,
                                                              block_days=plan.horizon if plan is not None else None)
    full_ids = eligible_ids(panel, cutoff)
    use_lgbm = 5 <= len(full_ids) <= max_series_lgbm
    if len(full_ids) > max_series_lgbm:
        warnings.append(f"{len(full_ids)} series superan el límite de LightGBM ({max_series_lgbm}); "
                        "se usan modelos estadísticos.")
    families = list(STATISTICAL) + (["lightgbm_global"] if use_lgbm else [])
    if not core_config.get("weekday_profile_models", False):
        # Variantes con perfil semanal: se activan por empresa (`weekday_profile_models`). Cambian qué modelo gana (en los datos
        # sintéticos dejan sin clase a LightGBM), así que entran por decisión expresa en el piloto, no para todas las empresas.
        families = [f for f in families if f not in WEEKDAY_SWITCH_FAMILIES]
    if force_families:
        families = [f for f in families if f in force_families] or families
    # Ajuste de feriados de los modelos estadísticos (`models/holidays.py`): por empresa, apagado por defecto (cambia qué modelo gana).
    holiday_adjustment = bool(core_config.get("holiday_adjustment_models", False))

    # ── Backtesting rolling-origin: ventanas de selección + período final reservado (holdout) ──
    # Cada ventana se perfila con datos ≤ su corte (elegibilidad y clase sin fuga, F4).
    t1 = time.perf_counter()
    if plan is not None:
        progress("backtesting", 20)
        bt = run_backtest(panel, features, plan, families, seed, n_jobs, use_lgbm,
                          progress=lambda frac: progress("backtesting", 20 + int(35 * frac)), windows=windows,
                          holiday_adjustment=holiday_adjustment)
    else:
        bt = pd.DataFrame()
        warnings.append("Historia insuficiente para backtesting (< 70 días): se usa el promedio de la historia "
                        "disponible y los intervalos no están calibrados.")
    if factor_outcome is not None:
        # El modelo con factores compite con el LightGBM sin factores (ventanas de selección; el período final nunca decide): si gana,
        # sus filas y su constructor ocupan el lugar de los de LightGBM; si no, todo sigue exactamente igual que sin factores.
        bt, features, windows = factor_outcome.compete(panel=panel, features=features, plan=plan, windows=windows, bt=bt, use_lgbm=use_lgbm,
                                                       seed=seed, n_jobs=n_jobs)
        limitations.extend(factor_outcome.limitations)
    timings["backtesting"] = time.perf_counter() - t1

    progress("selecting", 58)
    # W3-A: tramo de volumen y vida de la serie as-of en cada corte, calculados UNA vez y compartidos por el gate de segmentos de la política
    # V2.5 (W3-B) y por las métricas por segmento. Aislado: un fallo registra la limitación `segment_metrics_unavailable` y no aborta la ejecución.
    segment_config = seg_metrics.SegmentConfig.from_config(core_config)
    segment_context, segment_error = (seg_metrics.safe_build_context(bt, panel, windows, segment_config)
                                      if not bt.empty else (None, None))
    eval_selection: Selection | None = None
    promotion: PromotionOutcome | None = None
    if bt.empty:
        selection = Selection({"pooled": "window_average"}, "seasonal_naive",
                              {"pooled": "Sin backtest posible: promedio móvil."}, pd.DataFrame(), pd.DataFrame())
    else:
        # Producción: se elige con toda la evidencia (ventanas de selección + holdout) bajo la política heredada, que gobierna por defecto;
        # la evaluación final (F6) solo ve las ventanas anteriores. La política V2.5 se calcula en paralelo y se registra (W3-B).
        # Segmentos críticos del gate «sin regresión por segmento» = los de las métricas por segmento de W3-A (volumen y vida de la serie as-of
        # en el corte de cada ventana). Sin ellos (métricas apagadas) o con un proveedor explícito rige `default_segments`/el entregado.
        segment_source = "proveedor entregado a run_pipeline" if segment_provider is not None else None
        if segment_provider is None:
            segment_provider = seg_metrics.provider_from_context(segment_context, cat)
            segment_source = W3A_SEGMENT_SOURCE if segment_provider is not None else None
        promotion = run_selection_stage(panel=panel, features=features, plan=plan, windows=windows, bt=bt, profile=prof, cfg=promo_cfg,
                                        seed=seed, n_jobs=n_jobs, use_lgbm=use_lgbm, champions=champions, quality=dq,
                                        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), segment_provider=segment_provider,
                                        segment_source=segment_source, champions_source=champions_source)
        bt, selection, eval_selection = promotion.backtest, promotion.selection, promotion.eval_selection
        limitations.extend(promotion.limitations)
        if factor_outcome is not None:     # W6-E: ¿la política que gobierna la selección posterior usa el período final? (la heredada sí; la V2.5 no)
            factor_outcome.note_downstream(promotion.governing, plan is not None and plan.has_holdout)
        if eval_selection is None:
            warnings.append(
                "La historia no alcanza para reservar un período final independiente: las métricas de precisión "
                "provienen de las mismas ventanas que eligieron el modelo y calibraron los intervalos, por lo que "
                "son optimistas.")

    # ── Entrenamiento final y predicción ──
    t2 = time.perf_counter()
    progress("training", 62)
    needed = {selection.family_for(c) for c in active["demand_class"].unique()} | {selection.fallback, BASELINE_FAMILY}
    train = panel.df[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(full_ids)]
    preds = []
    stat_needed = [f for f in needed if f in STATISTICAL]
    final_holidays = features.holiday_days(cutoff, full_ids) if holiday_adjustment and full_ids else None
    holiday_info: dict[str, Any] = {}
    if full_ids:
        preds.append(forecast_statistical(train[["unique_id", "ds", "y"]], fc_horizon, stat_needed, holidays=final_holidays,
                                          info=holiday_info))
    lgbm: LightGBMGlobal | None = None
    failed: dict[str, str] = {}
    if "lightgbm_global" in needed and full_ids:
        try:
            tr, ref = features.training_frame(cutoff, full_ids)
            lgbm = LightGBMGlobal(seed=seed, n_jobs=n_jobs)
            lgbm.params.update(promotion.lgbm_params if promotion is not None else {})   # W3-B: hiperparámetros ajustados con Optuna ({} por defecto)
            lgbm.fit(tr)
            progress("predicting", 72)
            preds.append(lgbm.predict(fc_horizon, features.future_frame(cutoff, full_ids, fc_horizon, ref)))
        except FeatureContractError:
            raise     # una feature sin contrato es un defecto de programación: no se disfraza de fallo de LightGBM
        except Exception as exc:  # respaldo: el pipeline sigue con el modelo de respaldo
            log.exception("LightGBM final falló")
            failed["lightgbm_global"] = f"{type(exc).__name__}: {exc}"[:300]
            warnings.append(f"LightGBM falló en el entrenamiento final ({type(exc).__name__}); "
                            f"se usó el modelo de respaldo ({selection.fallback}).")
            lgbm = None
    # E2-19: lo que se sirve (y por tanto métricas, intervalos y etiquetas) es la selección con el respaldo
    # en lugar de cualquier familia que falló; el ganador del backtest queda como `planned`.
    served = selection.served(set(failed))
    if factor_outcome is not None:     # W6-E: ¿el modelo con factores es realmente el que se sirve?
        factor_outcome.finalize(set(served.winners.values()) | {served.fallback}, set(failed))
    if promotion is not None and failed:    # W3-B: las filas efectivas del registro de decisiones dicen lo REALMENTE servido, no la familia planeada
        promotion.reconcile_served(failed, selection.fallback)
    eval_served = eval_selection.served(set(failed)) if eval_selection is not None else None
    if failed:
        limitations.append({"code": "fallback_served",
                            "message": (f"Falló el entrenamiento final de {', '.join(FAMILIES[f].label for f in failed)}: "
                                        f"se sirve el respaldo ({FAMILIES[selection.fallback].label}). Las métricas, los "
                                        "intervalos y las etiquetas corresponden al modelo servido.")})
    active_ids = set(active["unique_id"])
    short_ids = [u for u in active["unique_id"] if u not in set(full_ids)]
    # Ritmo semanal del negocio para lo que se sirve PLANO sin haber competido en el backtest (series nuevas o de historia escasa y
    # filas servidas con el respaldo plano): mismo interruptor que las variantes `*_dow`. Sin él, en un negocio que no vende un día
    # de la semana esas series reparten su nivel por igual entre los 7 días y son casi toda la venta pronosticada de ese día.
    # El perfil es el común de las series activas (no hay historia para uno propio ni perfil por sucursal); apagado ⇒ None y nada cambia.
    business_weekday: np.ndarray | None = None
    weekday_applied = {"short_series": 0, "fallback_series": 0}
    if core_config.get("weekday_profile_models", False):
        business_weekday = common_weekday_profile(
            panel.df.loc[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(active_ids), ["unique_id", "ds", "y"]])
    if short_ids:
        preds.append(short_forecast(lifecycle, short_ids, cutoff, fc_horizon, weekday_factors=business_weekday))
        weekday_applied["short_series"] = len(short_ids) if business_weekday is not None else 0
        n_cat = int((lifecycle.short_family.reindex(short_ids) == "category_benchmark").sum())
        warnings.append(f"{len(short_ids)} series con historia escasa o menos de 28 días usan respaldo etiquetado "
                        f"({n_cat} con la mediana de su categoría, el resto con el promedio de su historia).")
    allp = pd.concat(preds, ignore_index=True) if preds else pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    timings["training"] = time.perf_counter() - t2

    progress("predicting", 76)
    wide = allp.pivot_table(index=["unique_id", "ds"], columns="family", values="yhat").reset_index()
    wide["demand_class"] = wide["unique_id"].map(cls_map)
    chosen = wide["demand_class"].map(served.family_for)
    chosen = np.where(wide["unique_id"].isin(short_ids), "__short__", chosen)
    yhat = np.full(len(wide), np.nan)
    fam_used = np.array(chosen, dtype=object)
    for fam in pd.unique(chosen):
        m = chosen == fam
        if fam in wide:
            yhat[m] = wide.loc[m, fam].to_numpy()
    weekday_applied["fallback_series"] = fill_missing_with_fallback(wide, yhat, fam_used, selection.fallback, business_weekday)
    short_family = wide["unique_id"].map(lifecycle.short_family).fillna("window_average").to_numpy(dtype=object)
    fam_used = np.where(fam_used == "__short__", short_family, fam_used)
    series_fc = wide[["unique_id", "ds", "demand_class"]].copy()
    series_fc["yhat"] = np.clip(np.nan_to_num(yhat, nan=0.0), 0, None)
    series_fc["model_family"] = fam_used
    ids = series_fc["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    series_fc["branch_id"], series_fc["product_id"] = ids[0], ids[1]
    series_fc["category_id"] = series_fc["product_id"].map(cat)
    series_fc["step"] = (series_fc["ds"] - cutoff).dt.days.astype(int)
    # Series con cero explícito, abstención o fuera de cobertura no se modelan: se etiquetan aparte.
    series_fc = series_fc[series_fc["unique_id"].isin(active_ids)].reset_index(drop=True)

    # ── Evaluación (del procedimiento tal como se sirve) y respaldo de cada horizonte ──
    progress("evaluating", 78)
    # W3-A: métricas por horizonte y segmento (volumen y vida de la serie con lo conocido en cada corte). Solo agrega filas; no cambia lo servido.
    if segment_error is None:
        segment_context, segment_error = seg_metrics.safe_build_context(bt, panel, windows, segment_config, reuse=segment_context)
    evaluations, eval_info = ev.evaluate(bt, served, eval_served, cat, data.currency, segments=segment_context,
                                         wape_min_denominator=segment_config.wape_min_denominator)
    segment_error = segment_error or eval_info.get("segment_error")
    if segment_error is not None:
        limitations.append({"code": "segment_metrics_unavailable",
                            "message": ("Las métricas por horizonte y segmento no se pudieron calcular en esta ejecución "
                                        f"({segment_error}); el pronóstico, la selección y las demás métricas no cambiaron.")})
    history_days = int((cutoff - panel.df["ds"].min()).days) + 1
    support = hz.horizon_support(horizon, history_days, plan, ev.horizon_obs(evaluations))
    p50_info = eval_info["p50"]
    if not p50_info["published"]:
        limitations.append({"code": "p50_not_published", "message": p50_info["message"]})
    if plan is not None and not plan.has_holdout:
        limitations.append({"code": "no_independent_holdout",
                            "message": "Sin período final independiente: las métricas son optimistas (ventanas que "
                                       "eligieron el modelo)."})
    for h, info in support.items():
        if info["status"] == hz.STATUS_INSUFFICIENT:
            limitations.append({"code": f"horizon_{h}_insufficient_data",
                                "message": f"Horizonte de {h} días sin respaldo: " + " ".join(r["message"] for r in info["reasons"])})

    # ── Intervalos (series) ──
    scales = series_scale(panel.df, cutoff)
    series_fc["scale"] = series_fc["unique_id"].map(scales).fillna(0.05)
    # Producción: residuos del modelo SERVIDO en TODAS las ventanas, agrupados por la clase que cada serie
    # tenía en el corte de su ventana y por familia (si se sirve el respaldo, se calibra con SUS residuos).
    sel_bt = selected_rows(bt, prof, served) if not bt.empty else pd.DataFrame()
    if not sel_bt.empty:
        cal_rows = sel_bt[~sel_bt["stockout"]].assign(
            group=lambda d: d["demand_class"].astype(str) + "|" + d["family"].astype(str))
        calibrator = ConformalCalibrator(cal_rows, "group", day_bucket)
    else:
        calibrator = ConformalCalibrator(pd.DataFrame(columns=["group", "step", "actual", "yhat", "scale"]))
    series_fc = calibrator.apply(series_fc.assign(
        group=series_fc["demand_class"].astype(str) + "|" + series_fc["model_family"].astype(str)))
    if not p50_info["published"]:
        series_fc["p50"] = np.nan  # la mediana solo se publica si se evaluó (nunca la media presentada como mediana)

    # ── Etiquetas por serie (casos especiales) y respaldo por fila (horizonte) ──
    hist_days = series_fc["unique_id"].map(prof.set_index("unique_id")["history_days"]).to_numpy()
    series_fc["quality_status"] = hz.row_status(series_fc["step"].to_numpy(), support, hist_days)
    series_fc["case_label"] = series_fc["unique_id"].map(lifecycle.labels)
    ex_events, ex_stats = known_events(data.exceptional_events, cutoff)
    if data.exceptional_stats:  # el lector de Datos ya dejó fuera lo desconocido o posterior al corte: se informa
        ex_stats = {**ex_stats, **data.exceptional_stats}
    flag_on = bool(core_config.get("exceptional_events_flag", True))
    ex_mask, ex_used = (flag_events(series_fc, ex_events, data.branches) if flag_on
                        else (np.zeros(len(series_fc), dtype=bool), []))
    series_fc.loc[ex_mask & series_fc["case_label"].isna().to_numpy(), "case_label"] = LABEL_EXCEPTIONAL
    series_fc.loc[ex_mask & (series_fc["quality_status"] == hz.STATUS_OK).to_numpy(), "quality_status"] = hz.STATUS_DEGRADED

    # ── Días del horizonte declarados cerrados: cero explícito ──
    # Un cierre del calendario (`closure`) o un día de la semana sin operación (`operating_weekdays`, si la clasificación del panel lo
    # aplicó) no puede tener venta: valor e intervalos a cero en todas las familias y fila etiquetada. Los nodos agregados suman el
    # cero y, si TODAS sus series están cerradas ese día, tampoco tienen intervalo (`open_rows`).
    by_calendar, by_weekday = closed_future_rows(series_fc["branch_id"].to_numpy(), series_fc["ds"].to_numpy(), data.closures,
                                                 operating_weekdays_applied(panel.day_summary))
    closed_fc = by_calendar | by_weekday
    if closed_fc.any():
        series_fc.loc[closed_fc, ["yhat", "lo_80", "hi_80", "lo_95", "hi_95"]] = 0.0
        series_fc.loc[closed_fc & series_fc["p50"].notna().to_numpy(), "p50"] = 0.0
        series_fc.loc[closed_fc, "case_label"] = LABEL_CLOSED_DAY
    series_fc["open_rows"] = (~closed_fc).astype(float)
    in_horizon = (series_fc["step"] <= horizon).to_numpy()
    closed_info = {
        "series_days": int((closed_fc & in_horizon).sum()), "dates": int(series_fc.loc[closed_fc & in_horizon, "ds"].nunique()),
        "by_calendar_closure": int((by_calendar & in_horizon).sum()), "by_operating_weekdays": int((by_weekday & ~by_calendar & in_horizon).sum()),
    }

    # ── Ingresos con supuesto de precio ──
    price = last_known_price(panel.df, cutoff)
    fallback_price = data.products.set_index("product_id")["current_price"]
    series_fc["unit_price_assumed"] = series_fc["unique_id"].map(price)
    series_fc["unit_price_assumed"] = series_fc["unit_price_assumed"].fillna(series_fc["product_id"].map(fallback_price))
    series_fc["revenue_yhat"] = series_fc["yhat"] * series_fc["unit_price_assumed"]

    # Las recomendaciones usan el pronóstico interno completo (F2); se persiste solo el horizonte pedido.
    rec_fc = series_fc
    series_fc = series_fc[series_fc["step"] <= horizon].reset_index(drop=True)

    # Cero explícito declarado (descontinuado o sucursal cerrada): fila de serie etiquetada, no modelada.
    zero_fc = zero_rows(lifecycle.zero_ids, prof, cutoff, horizon, cat)
    if not zero_fc.empty:
        zero_fc["case_label"] = zero_fc["unique_id"].map(lifecycle.labels)
        zero_fc["quality_status"] = hz.STATUS_OK
        if not p50_info["published"]:
            zero_fc["p50"] = np.nan

    # ── Reconciliación jerárquica + intervalos agregados ──
    progress("reconciling", 80)
    t3 = time.perf_counter()
    agg_fc = aggregate(series_fc, ["yhat", "revenue_yhat", "open_rows"], ["ds", "step"], AGG_LEVELS)
    node_hist = aggregate(panel.df.assign(y_clean=panel.df["y"]), ["y_clean"], ["ds"], AGG_LEVELS)
    # Escala de cada nodo en cada ventana: con el conocimiento de ESA ventana (ADR-V25-23), no con la historia final.
    window_hist = {}
    if windows is not None:
        for info in windows.info():
            if info["panel_rebuilt"]:
                origin = pd.Timestamp(info["origin"])
                wp = windows.at(origin)[0]
                window_hist[origin] = aggregate(wp.df.assign(y_clean=wp.df["y"]), ["y_clean"], ["ds"], AGG_LEVELS)
    agg_cal_day, agg_cal_week, agg_cal_month, agg_bt = None, None, None, pd.DataFrame()
    if not sel_bt.empty:
        b = sel_bt.copy()
        b.loc[b["stockout"], ["actual", "yhat"]] = 0.0  # excluye días censurados de ambos lados
        b = b.assign(branch_id=b["unique_id"].str.extract(r"b(\d+)-")[0].astype(int),
                     product_id=b["unique_id"].str.extract(r"-p(\d+)")[0].astype(int))
        b["category_id"] = b["product_id"].map(cat)
        agg_bt = aggregate(b, ["actual", "yhat"], ["window", "cutoff", "ds", "step"], AGG_LEVELS)
        agg_bt["scale"] = _node_scales_at(node_hist, agg_bt, window_hist)
        agg_cal_day = ConformalCalibrator(agg_bt.assign(group=agg_bt["level"]), "group", day_bucket, "absolute")
        for gran in ("week", "month"):
            per = to_periods(agg_bt.drop(columns=["cutoff", "scale"]), gran, ["actual", "yhat"], ["window"])
            per = per.merge(agg_bt.drop_duplicates(["node_key", "window"])[["node_key", "window", "scale"]],
                            on=["node_key", "window"], how="left")
            per["scale"] = per["scale"] * per["period_days"]
            per = per.rename(columns={"horizon_step": "step"}).assign(group=per["level"])
            cal = ConformalCalibrator(per, "group", identity_bucket, "absolute")
            if gran == "week":
                agg_cal_week = cal
            else:
                agg_cal_month = cal
    final_scale = _node_scale_final(node_hist, cutoff)
    agg_fc["scale"] = agg_fc["node_key"].map(final_scale).fillna(0.05)
    if agg_cal_day is not None:
        agg_fc = agg_cal_day.apply(agg_fc.assign(group=agg_fc["level"]))
    else:
        for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
            agg_fc[c] = np.nan
    agg_fc.loc[(agg_fc["open_rows"] <= 0).to_numpy(), ["lo_80", "hi_80", "lo_95", "hi_95"]] = 0.0   # nodo con todas sus series cerradas ese día
    agg_fc["p50"] = np.nan  # la suma de medianas no es la mediana de la suma: solo hay P50 a nivel serie-día
    product_node = agg_fc["level"] == "product"
    agg_fc["unit_price_assumed"] = np.where(product_node & (agg_fc["yhat"] > 0),
                                            agg_fc["revenue_yhat"] / agg_fc["yhat"].where(agg_fc["yhat"] > 0, 1), np.nan)
    agg_fc["model_family"] = None
    agg_fc["quality_status"] = hz.row_status(agg_fc["step"].to_numpy(), support)
    agg_fc["case_label"] = None

    values = [
        _frame_day(series_fc.assign(level="series",
                                    node_key="series:" + series_fc["branch_id"].astype(str) + ":"
                                             + series_fc["product_id"].astype(str))),
        _frame_day(agg_fc),
    ]
    if not zero_fc.empty:
        values.append(_frame_day(zero_fc.assign(level="series",
                                                node_key="series:" + zero_fc["branch_id"].astype(str) + ":"
                                                         + zero_fc["product_id"].astype(str))))
    for gran, cal in (("week", agg_cal_week), ("month", agg_cal_month)):
        per = to_periods(agg_fc[["node_key", "level", "branch_id", "product_id", "category_id", "ds", "yhat",
                                 "revenue_yhat"]], gran, ["yhat", "revenue_yhat"])
        per["scale"] = per["node_key"].map(final_scale).fillna(0.05) * per["period_days"]
        # El período agregado se respalda como el último paso diario que contiene.
        start = per["ds"].where(per["ds"] > cutoff, cutoff + pd.Timedelta(days=1))
        last_step = ((start + pd.to_timedelta(per["period_days"] - 1, unit="D")) - cutoff).dt.days.clip(lower=1).to_numpy()
        per["quality_status"] = hz.row_status(last_step, support)
        per = per.rename(columns={"horizon_step": "step"})
        if cal is not None and not cal.empty:
            per = cal.apply(per.assign(group=per["level"]))
        else:
            for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
                per[c] = np.nan
        per["p50"] = np.nan
        per["granularity"] = gran
        per["unit_price_assumed"] = np.nan
        per["model_family"] = None
        per["case_label"] = None
        values.append(per)
    values.append(_horizon_rows(agg_fc, agg_bt, final_scale, cutoff, list(horizons), support))
    forecast_values = pd.concat([_finalize(v) for v in values], ignore_index=True)
    timings["reconciling"] = time.perf_counter() - t3

    # ── Recomendaciones ──
    progress("recommending", 86)
    recs = pd.DataFrame()
    if data.latest_inventory is None or data.latest_inventory.empty:
        warnings.append("Sin datos de inventario: no se generan recomendaciones de abastecimiento.")
    else:
        recent_sales = data.sales[data.sales["ds"] > pd.Timestamp(cutoff) - pd.Timedelta(days=90)]
        unit_costs = recent_unit_costs(recent_sales)
        prod_cost = data.products.set_index("product_id")["current_cost"]
        error_model = LeadTimeErrorModel(sel_bt if not sel_bt.empty else pd.DataFrame(
            columns=["unique_id", "window", "step", "actual", "yhat", "stockout", "scale"]), cls_map)
        recs = build_recommendations(rec_fc, data.latest_inventory, data.lead_times, data.suppliers,
                                     data.open_purchase_orders, unit_costs.dropna(), error_model, prof, scales,
                                     data.effective_settings, cutoff)
        if not recs.empty:
            missing_cost = recs["unit_cost"].isna()
            recs.loc[missing_cost, "unit_cost"] = recs.loc[missing_cost, "product_id"].map(prod_cost)
            recs["estimated_investment"] = np.where(recs["unit_cost"].notna(),
                                                    recs["suggested_qty"] * recs["unit_cost"].fillna(0), np.nan)
            n_short = int(recs["assumptions"].map(lambda a: bool(a.get("coverage_insufficient"))).sum())
            if n_short:
                warnings.append(f"{n_short} recomendaciones sin cantidad sugerida: plazo + revisión supera el "
                                f"pronóstico interno ({fc_horizon} días).")
            n_unknown = int(recs["assumptions"].map(lambda a: a.get("uncertainty") == "unknown").sum())
            if n_unknown:
                warnings.append(f"{n_unknown} recomendaciones con incertidumbre desconocida: sin probabilidad de "
                                "quiebre y con stock de seguridad provisional.")
        no_inv = len(active) - (0 if recs.empty else len(recs))
        if no_inv > 0:
            warnings.append(f"{no_inv} series sin inventario registrado: sin recomendación de abastecimiento.")

    if lifecycle.abstain:
        warnings.append(f"{len(lifecycle.abstain)} series sin pronóstico (abstención): sin ventas recientes o "
                        "descontinuadas hace más de un año.")
    if len(lifecycle.zero_ids):
        warnings.append(f"{len(lifecycle.zero_ids)} series con cero explícito declarado (descontinuadas o de sucursal cerrada).")
    for key in ("known_at_unknown_ignored", "known_after_cutoff_ignored"):
        if ex_stats.get(key):
            warnings.append(f"{ex_stats[key]} eventos extraordinarios sin `known_at` válido al corte no se usaron.")

    quality = hz.run_quality(support)
    if failed or (quality == hz.STATUS_OK and plan is not None and not plan.has_holdout):
        quality = hz.worst(quality, hz.STATUS_DEGRADED)
    if dq.degraded:   # W2-C: un gate DEGRADE nunca deja el resultado en «ok»
        quality = hz.worst(quality, hz.STATUS_DEGRADED)
    assumptions = [
        f"Ingresos = unidades × {PRICE_ASSUMPTION}.",
        "Intervalos 80/95 % conformales con residuos del backtest del modelo servido; P10/P90 = límites del intervalo 80 %.",
        "P50 solo a nivel serie-día y únicamente si se evaluó con pinball en un período final independiente.",
        "La historia de cada serie empieza en su primera venta; solo los días sin venta con la fuente cubierta, la sucursal abierta y "
        "sin quiebre de stock (`zero_confirmed`) son ceros observados: la fuente ausente (`missing_source`) y los cierres (`closed`) "
        "se excluyen de las métricas y del perfilado de ceros, y se imputan con la media móvil de 28 días para ajustar los modelos.",
        "Cada ventana del backtest entrena con los datos tal como se conocían en su corte; los reales con que se evalúa son la "
        "verdad disponible al corte final (versión vigente de cada documento a esa fecha).",
    ]
    if closed_info["series_days"]:
        assumptions.append(
            f"{closed_info['series_days']} series-día del horizonte ({closed_info['dates']} fechas) están declaradas cerradas "
            f"({closed_info['by_calendar_closure']} por cierres del calendario, {closed_info['by_operating_weekdays']} por días de la "
            "semana sin operación): se pronostican en cero, con intervalos en cero, y se etiquetan `closed_day`.")
    if weekday_applied["short_series"] or weekday_applied["fallback_series"]:
        assumptions.append(
            f"Las series nuevas o de historia escasa ({weekday_applied['short_series']}) y las servidas con el respaldo plano "
            f"({weekday_applied['fallback_series']}) reparten su nivel diario con el ritmo semanal del negocio (unidades por día de "
            f"la semana de las series activas en los últimos {WEEKDAY_PROFILE_DAYS} días): el total de una semana completa no "
            "cambia, solo su reparto entre días. No tienen perfil propio ni por sucursal.")
    if holiday_adjustment:
        hf = holiday_info.get("holiday_factors") or {}
        in_horizon_days = 0 if final_holidays is None or final_holidays.empty else int(final_holidays.loc[
            (final_holidays["ds"] > cutoff) & (final_holidays["ds"] <= cutoff + pd.Timedelta(days=horizon)), "ds"].nunique())
        assumptions.append(
            f"Los modelos estadísticos (salvo las referencias naive) se ajustan por feriados: su entrenamiento se limpia de los feriados "
            f"pasados y los {in_horizon_days} feriados del horizonte se multiplican por un factor por feriado estimado con la venta real "
            f"frente a la esperable del negocio (factor común {float(hf.get('common', 1.0)):.2f}; {len(hf.get('by_name', {}))} feriados con "
            "factor propio). Un solo factor por feriado para todo el negocio; no ajusta la víspera ni el día siguiente.")
    snapshot = build_snapshot(data, panel, effective_config, features.knowledge_date(cutoff), ex_events, data.coverage,
                              factors=factor_outcome.snapshot_block() if factor_outcome is not None else None)
    families_info = _families_info(served, selection, series_fc, bt, active, failed)
    served_info = {
        "fallback_used": bool(failed), "failed_families": failed,
        "planned_models_by_class": dict(selection.winners), "served_models_by_class": dict(served.winners),
        "fallback_family": selection.fallback,
        "note": ("Se sirve el respaldo porque falló el entrenamiento final del ganador: métricas, intervalos y "
                 "etiquetas corresponden al modelo servido." if failed else "Se sirve el modelo ganador del backtest."),
    }
    knowledge = _knowledge(data, windows)
    manifest = build_manifest(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), plan=plan, bt=bt, min_improvement=min_improvement,
        config_version=config_version, coverage=data.coverage or None, horizon_support=support,
        has_eval_selection=eval_selection is not None, p50=p50_info, seed=seed, served=served_info,
        day_status=panel.day_summary, knowledge=knowledge)
    manifest["data_quality_gate"] = dq.manifest_block()   # W2-C: resultado del gate de calidad de datos (ADR-V25-05)
    if factor_outcome is not None:   # W6-E: factores activos, resolución as-of, competencia con el modelo sin factores y decisión
        manifest["factors"] = factor_outcome.manifest_block()
    if promotion is not None:   # W3-B: ambas políticas (parámetros, huellas, ventanas usadas, ajuste Optuna) y cuál gobernó
        manifest["promotion"] = promotion.manifest_block()
        if manifest.get("selection"):      # el criterio se genera de la política (no la frase fija «≥ 2/3»): con 4 ventanas la heredada exige 3
            manifest["selection"]["criterion"] = promotion.selection_criterion()
        manifest["decisions"]["model_selection_production"] = {
            "policy": promotion.governing,
            "windows": (promotion.windows["selection_windows"] + promotion.windows["holdout_windows"]) if promotion.governing == "legacy"
            else promotion.windows["selection_windows"]}
    summary = _summary(series_fc, forecast_values, served, evaluations, plan, readiness, recs, horizon, active, lgbm,
                       warnings, eval_served, support=support, horizons=horizons)
    summary.update({
        "quality_status": quality, "uncertainty_source": "conformal" if calibrator is not None and not calibrator.empty else "unknown",
        "horizon_support": {str(h): v for h, v in support.items()},
        "max_supported_horizon": max((h for h, v in support.items() if v["status"] != hz.STATUS_INSUFFICIENT), default=None),
        "p50": p50_info, "served": served_info,
        "special_cases": {**lifecycle.summary,
                          "exceptional_events": {"flag_enabled": flag_on, "used": ex_used, "stats": ex_stats},
                          "closed_days_in_horizon": closed_info},
        "coverage": data.coverage, "limitations": limitations, "limitation_codes": [x["code"] for x in limitations],
        "assumptions": assumptions, "config_version": config_version, "data_snapshot_hash": snapshot.hash,
        "history_days": history_days, "horizon_days": horizon, "horizons_requested": list(horizons),
        "day_status": panel.day_summary, "knowledge": knowledge, "quality_gate": dq.manifest_block(),
    })
    if factor_outcome is not None:
        summary["factors"] = factor_outcome.report()
    summary["segment_metrics"] = seg_metrics.summary_block(evaluations, segment_config if segment_config.enabled else None,
                                                           error=segment_error)   # W3-A (compacto: el detalle está en model_evaluations)
    manifest["segment_metrics"] = seg_metrics.manifest_block(segment_config if segment_config.enabled else None)   # W3-A: definiciones estáticas, una vez
    # ── W3-B · shadow mode: el challenger predice SIN servirse (al final: no puede alterar lo publicado) ──
    shadow_df = pd.DataFrame(columns=shadow_stage.SHADOW_COLUMNS)
    if promotion is not None:
        t_shadow = time.perf_counter()
        challengers, shadow_notes = [], []
        if promo_cfg.shadow.enabled:
            eligible = {c: sorted(set(full_ids) & set(active.loc[active["demand_class"] == c, "unique_id"])) for c in shadow_stage.CLASSES}
            challengers = shadow_stage.choose_challengers(bt, prof, served, eligible, scales, promo_cfg.shadow.max_series, set(failed))
            shadow_df, shadow_notes = shadow_stage.forecast_challengers(
                challengers, bt=bt, profile=prof, train=train, features=features, lgbm=lgbm, lgbm_ref=ref if lgbm is not None else None,
                full_ids=full_ids, cutoff=cutoff, fc_horizon=fc_horizon, horizon=horizon, scales=scales, seed=seed, n_jobs=n_jobs,
                train_ml=promo_cfg.shadow.train_ml_challenger, lgbm_params=promotion.lgbm_params, holidays=final_holidays)
            limitations.extend(n for n in shadow_notes if n not in limitations)
        promotion.shadow = shadow_stage.shadow_summary(challengers, shadow_df, shadow_notes, promo_cfg.shadow.enabled, promo_cfg.shadow.max_series)
        promotion.shadow_elapsed_seconds = round(time.perf_counter() - t_shadow, 2)      # costo del shadow de esta ejecución (V25-SEL-045; solo en el resumen)
        summary["promotion"] = promotion.summary()
        manifest["promotion"] = promotion.manifest_block()
        summary["limitations"], summary["limitation_codes"] = limitations, [x["code"] for x in limitations]
        timings["shadow"] = time.perf_counter() - t_shadow
    timings["total"] = time.perf_counter() - t0
    summary["timings_seconds"] = {k: round(v, 1) for k, v in timings.items()}
    return PipelineResult(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), data_to=cutoff.date(), panel=panel,
        readiness=readiness, selection=served, plan=plan, evaluations=evaluations, families=families_info,
        forecast_values=forecast_values, recommendations=recs, summary=summary, warnings=warnings,
        training_data_hash=(factor_outcome.fold_training_hash(training_data_hash(panel)) if factor_outcome is not None else training_data_hash(panel)),
        lgbm=lgbm, timings=timings,
        backtest=bt, evaluation_selection=eval_served, planned_selection=selection, failed_families=failed,
        snapshot=snapshot, manifest=manifest, effective_config=effective_config, config_version=config_version,
        horizon_support=support, quality_status=quality, limitations=limitations, assumptions=assumptions,
        lifecycle=lifecycle, quality=dq, promotion=promotion, shadow=shadow_df,
        factors=factor_outcome.report() if factor_outcome is not None else {},
        factor_compat=factor_outcome.registry_compat() if factor_outcome is not None else None,
    )


# ───────────────────────────── auxiliares ─────────────────────────────

def fill_missing_with_fallback(wide: pd.DataFrame, yhat: np.ndarray, fam_used: np.ndarray, fallback: str,
                               business_weekday: np.ndarray | None) -> int:
    """Rellena EN SITIO las filas sin pronóstico de su familia (`yhat` NaN) con el respaldo y las etiqueta con él.

    `wide`: una fila por (unique_id, ds) con una columna por familia. Con `business_weekday` (ritmo semanal del negocio: 7 factores
    de media 1, índice 0 = lunes; solo con `weekday_profile_models`) un respaldo PLANO (`WEEKDAY_BASES`: no ve el día de la semana)
    se reparte con ese ritmo; un respaldo que ya tiene día de la semana (`seasonal_naive`) no se toca.
    Devuelve cuántas series recibieron el respaldo con el ritmo del negocio (0 si no se aplicó)."""
    miss = np.isnan(yhat)
    if not miss.any() or fallback not in wide:
        return 0
    yhat[miss] = wide.loc[miss, fallback].to_numpy()
    fam_used[miss] = fallback
    if business_weekday is None or fallback not in WEEKDAY_BASES:
        return 0
    yhat[miss] = yhat[miss] * np.asarray(business_weekday, dtype=float)[pd.to_datetime(wide.loc[miss, "ds"]).dt.dayofweek.to_numpy()]
    return int(wide.loc[miss, "unique_id"].nunique())


def _day_limitations(day_summary: dict[str, Any]) -> list[dict[str, str]]:
    """Limitaciones de la semántica de días (ADR-V25-03) con sus conteos: nada se excluye o imputa en silencio."""
    out: list[dict[str, str]] = []
    days = day_summary.get("series_days") or {}
    by_basis = day_summary.get("series_days_by_basis") or {}
    total = int(day_summary.get("series_days_total") or 0)
    missing, closed = int(days.get("missing_source", 0)), int(days.get("closed", 0))
    imputed, not_imputable = int(day_summary.get("excluded_days_imputed", 0)), int(day_summary.get("excluded_days_not_imputable", 0))
    carried = int(day_summary.get("excluded_days_carried_forward", 0))
    carry = f", {carried} de ellos con el último nivel conocido por huecos de más de 21 días" if carried else ""
    tail = (f" Para ajustar los modelos se imputaron con la media móvil de 28 días de los días limpios ({imputed} imputados{carry}; "
            f"{not_imputable} sin historia suficiente quedan con su valor observado).")
    if missing:
        out.append({"code": "days_missing_source", "message": (
            f"{missing} días-serie ({missing / max(total, 1):.1%} del panel) se clasificaron `missing_source` (fuera de la cobertura "
            "declarada de la fuente, dentro de un hueco o inferidos por la regla de actividad de la sucursal): no son ceros observados "
            "y se excluyen de las métricas y de la proporción de ceros del perfilado." + tail)})
    rules = day_summary.get("rules") or {}
    engine_inferred = int((day_summary.get("branch_days") or {}).get("missing_source_engine_inferred", 0))
    if engine_inferred:
        out.append({"code": "days_missing_source_inferred", "message": (
            f"{engine_inferred} sucursal-días se marcaron `missing_source` con base inferida por el motor: la sucursal no registró ninguna "
            f"línea pese a una mediana de al menos {rules.get('infer_min_median_lines')} líneas diarias en los "
            f"{rules.get('infer_lookback_days')} días previos ({by_basis.get('missing_source:inferred', 0)} días-serie). La regla es configurable "
            "(`day_status_infer_gaps`) y debe aprobarse en el piloto: un cierre sin declarar en el calendario se confunde con una caída de la fuente.")})
    if (missing or closed) and not_imputable:
        out.append({"code": "days_not_imputable", "message": (
            f"{not_imputable} días-serie excluidos (fuente ausente o cierre) no tienen ningún nivel previo con que imputarlos (menos de 7 "
            "días limpios antes, p. ej. un hueco que abre la serie): quedan con su valor observado en los modelos que exigen series "
            "contiguas, pero siguen fuera de las métricas y de la proporción de ceros del perfilado.")})
    if closed:
        out.append({"code": "days_closed", "message": (
            f"{closed} días-serie corresponden a cierres declarados (cierres del calendario `closure` o días de la semana sin "
            "operación): no son ceros observados y se excluyen de las métricas." + tail)})
    weekdays = (day_summary.get("notes") or {}).get("operating_weekdays") or {}
    if weekdays and not weekdays.get("applied"):
        out.append({"code": "operating_weekdays_not_applied", "message": (
            f"Los días de operación declarados ({_weekday_names(weekdays.get('weekdays') or [])}) NO se aplicaron: en los últimos "
            f"{weekdays.get('guard_days')} días el {float(weekdays.get('recent_units_share_on_closed_weekdays') or 0):.1%} de las unidades se "
            f"vendió en días declarados sin operación (máximo admitido {float(weekdays.get('guard_max_share') or 0):.0%}). Esos días se "
            "tratan como días normales; revise la declaración en la configuración de Demanda (`operating_weekdays`).")})
    return out


WEEKDAY_NAMES = ("lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo")


def _weekday_names(days: list[int]) -> str:
    return ", ".join(WEEKDAY_NAMES[int(d)] for d in days if 0 <= int(d) <= 6)


def _knowledge(data: TenantData, windows: AsOfWindows | None) -> dict[str, Any]:
    """Política de conocimiento (ADR-V25-23) y lo que realmente se usó en cada ventana, para el manifiesto y el resumen."""
    versions = data.sales_versions
    return {
        "policy": {
            "visibility_rule": "una versión es visible al corte C si known_from < medianoche local (C+1) y "
                               "(known_until es nulo o known_until >= medianoche local (C+1))",
            "training": "cada ventana del backtest y el período final entrenan con los datos tal como se conocían en SU corte "
                        "(panel, perfil de demanda, escalas y variables)",
            "evaluation_actuals": "los reales con que se evalúa cada ventana son la verdad disponible al corte final",
            "final_cutoff_knowledge": "el corte final de una ejecución vigente (corte = último dato) conoce hasta la fecha de ejecución "
                                      "(`knowledge_date`: lo recibido después de la medianoche del último día, p. ej. la carga nocturna, sí cuenta); "
                                      "una reconstrucción histórica, solo hasta su propio corte",
            "static_catalog": "inventario, catálogo y calendario no están versionados: se usa su estado vigente",
        },
        "source": versions.summary() if versions is not None else {
            "versions": False, "assumption": "sin historial de versiones: cada documento se asume conocido desde su día de negocio"},
        "as_of_windows_active": windows is not None,
        "windows": windows.info() if windows is not None else [],
    }


def _node_scales_at(node_hist: pd.DataFrame, agg_bt: pd.DataFrame,
                    window_hist: dict[pd.Timestamp, pd.DataFrame] | None = None) -> pd.Series:
    """Escala de cada nodo al corte de cada ventana (media de 28 días previos). `window_hist`: historia por nodo con el
    conocimiento de las ventanas cuyo panel difiere de la verdad final (as-of)."""
    out = pd.Series(np.nan, index=agg_bt.index)
    for cutoff, idx in agg_bt.groupby("cutoff").groups.items():
        sc = _node_scale_final((window_hist or {}).get(pd.Timestamp(cutoff), node_hist), pd.Timestamp(cutoff))
        out.loc[idx] = agg_bt.loc[idx, "node_key"].map(sc).to_numpy()
    return out.fillna(0.05)


def _node_scale_final(node_hist: pd.DataFrame, cutoff: pd.Timestamp) -> pd.Series:
    h = node_hist[(node_hist["ds"] <= cutoff) & (node_hist["ds"] > cutoff - pd.Timedelta(days=28))]
    return h.groupby("node_key")["y_clean"].mean().clip(lower=0.05)


def _horizon_rows(agg_fc: pd.DataFrame, agg_bt: pd.DataFrame, final_scale: pd.Series, cutoff: pd.Timestamp,
                  horizons: list[int], support: dict[int, dict]) -> pd.DataFrame:
    """Totales del horizonte H por nodo agregado, con intervalo del TOTAL del horizonte.

    granularity='horizon', period_days=horizon_step=H, target_date=corte+H (último día del
    horizonte; así los horizontes del catálogo no colisionan en la clave única). El intervalo es conformal
    simétrico sobre el error de la suma de H días en cada ventana de backtest, normalizado por
    escala×H y agrupado por nivel (o todos los niveles si hay pocos puntos). Sin ventana de backtest
    de H días, o con el horizonte sin respaldo (`insufficient_data`), NO hay intervalo (nulo): no se
    extrapola. Cada fila lleva el estado del horizonte (`quality_status`).
    """
    keys = ["node_key", "level", "branch_id", "product_id", "category_id"]
    fut = []
    for H in horizons:
        g = agg_fc[agg_fc["step"] <= H].groupby(keys, dropna=False, as_index=False)[["yhat", "revenue_yhat"]].sum()
        g["ds"] = cutoff + pd.Timedelta(days=H)
        g["period_days"] = H
        g["step"] = H
        g["scale"] = g["node_key"].map(final_scale).fillna(0.05) * H
        g["quality_status"] = support.get(H, {"status": hz.STATUS_INSUFFICIENT})["status"]
        fut.append(g)
    out = pd.concat(fut, ignore_index=True) if fut else pd.DataFrame(columns=[*keys, "yhat", "quality_status"])
    cal_rows = []
    if not agg_bt.empty:
        max_step = int(agg_bt["step"].max())
        for H in horizons:
            if H > max_step:
                continue
            b = agg_bt[agg_bt["step"] <= H].groupby(["node_key", "level", "window"], as_index=False).agg(
                actual=("actual", "sum"), yhat=("yhat", "sum"), scale=("scale", "first"))
            b["scale"] = b["scale"] * H
            b["step"] = H
            cal_rows.append(b)
    if cal_rows:
        cal = ConformalCalibrator(pd.concat(cal_rows, ignore_index=True).assign(group=lambda d: d["level"]),
                                  "group", identity_bucket, "absolute")
        out = cal.apply(out.assign(group=out["level"]))
    else:
        for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
            out[c] = np.nan
    unsupported = (out["quality_status"] == hz.STATUS_INSUFFICIENT).to_numpy()
    for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
        out.loc[unsupported, c] = np.nan
    out["p50"] = np.nan
    out["granularity"] = "horizon"
    out["unit_price_assumed"] = np.nan
    out["model_family"] = None
    out["case_label"] = None
    return out


def _frame_day(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    out["granularity"] = "day"
    out["period_days"] = 1
    return out


FV_FLOAT_COLS = ("yhat", "lo_80", "hi_80", "lo_95", "hi_95", "p50", "unit_price_assumed", "revenue_yhat")


def _finalize(df: pd.DataFrame) -> pd.DataFrame:
    cols = ["node_key", "level", "branch_id", "product_id", "category_id", "granularity", "ds", "period_days",
            "step", "yhat", "lo_80", "hi_80", "lo_95", "hi_95", "p50", "model_family", "unit_price_assumed",
            "revenue_yhat", "quality_status", "case_label"]
    out = df.reindex(columns=cols).rename(columns={"ds": "target_date", "step": "horizon_step"})
    for c in FV_FLOAT_COLS:
        out[c] = out[c].astype(float).round(4)
    return out


def _families_info(served: Selection, planned: Selection, series_fc: pd.DataFrame, bt: pd.DataFrame,
                   active: pd.DataFrame, failed: dict[str, str]) -> dict:
    """Familias del run con su estado de registro (candidate/validated/production/failed) y rol (primary/fallback).

    `served` es lo que realmente se sirve; las familias que fallaron en el entrenamiento final quedan `failed`."""
    counts = series_fc.drop_duplicates("unique_id")["model_family"].value_counts().to_dict()
    evaluated = set(bt["family"].unique()) if not bt.empty else set()
    used = set(counts) | {served.fallback}
    native = set(planned.winners.values())
    info = {}
    for fam in sorted(evaluated | used | set(failed)):
        if fam not in FAMILIES:
            continue
        classes = sorted({c for c, f in served.winners.items() if f == fam and c != "pooled"})
        if fam == "window_average" and (active["demand_class"] == "insufficient").any():
            classes.append("insufficient")
        n = int(counts.get(fam, 0))
        if fam in failed:
            status, role = "failed", "primary"
        elif n:
            status = "production"
            role = "fallback" if (fam == served.fallback and fam not in native) else "primary"
        elif fam == served.fallback:
            status, role = "production", "fallback"  # respaldo en espera: también en producción, con su rol
        elif fam in evaluated:
            status, role = "validated", "primary"  # evaluada con el protocolo y no elegida
        else:
            status, role = "candidate", "primary"
        info[fam] = {"status": status, "role": role, "series_count": n, "demand_classes": classes,
                     "level": FAMILIES[fam].level, "hyperparameters": FAMILIES[fam].hyperparameters,
                     "evaluated": fam in evaluated, "error": failed.get(fam)}
    if served.fallback in info and info[served.fallback]["status"] == "production" and info[served.fallback]["series_count"]:
        info[served.fallback]["is_fallback"] = True
    return info


def _metrics_block(evaluations: list[dict[str, Any]], evaluation_type: str) -> dict[str, Any] | None:
    def row(family, level, key=None):
        for e in evaluations:
            if (e["evaluation_type"] == evaluation_type and e["model_family"] == family and e["level"] == level
                    and e["level_key"] == key):
                return e
        return None

    sel, base = row("selection", "overall"), row(BASELINE_FAMILY, "overall")
    if sel is None and base is None:
        return None
    sel_tot, base_tot = row("selection", "total", "total"), row(BASELINE_FAMILY, "total", "total")
    sm = (sel or {}).get("metrics") or {}
    bm = (base or {}).get("metrics") or {}
    return {
        "selected_wape": sel["wape"] if sel else None,
        "baseline_wape": base["wape"] if base else None,
        "selected_bias": sel["bias"] if sel else None,
        "coverage_80": sel["coverage_80"] if sel else None,
        "coverage_95": sel["coverage_95"] if sel else None,
        "total_selected_wape": sel_tot["wape"] if sel_tot else None,
        "total_baseline_wape": base_tot["wape"] if base_tot else None,
        "n_obs": sel["n_obs"] if sel else None,
        "cutoffs": (sel or base)["cutoffs"],
        "scope": (sel or base)["extra"]["evaluation_scope"],
        "selected_mase": sm.get("mase"), "baseline_mase": bm.get("mase"),
        "selected_rmsse": sm.get("rmsse"), "baseline_rmsse": bm.get("rmsse"),
        "pinball_p10": sm.get("pinball_p10"), "pinball_p50": sm.get("pinball_p50"), "pinball_p90": sm.get("pinball_p90"),
        "interval_width_80": sm.get("interval_width_80"), "interval_width_95": sm.get("interval_width_95"),
        "money": sm.get("money"), "baseline_money": bm.get("money"),
    }


def _summary(series_fc, fv, selection, evaluations, plan, readiness, recs, horizon, active, lgbm, warnings,
             eval_selection: Selection | None = None, support: dict[int, dict] | None = None,
             horizons: tuple[int, ...] = hz.SUPPORTED_HORIZONS) -> dict:
    primary = _metrics_block(evaluations, EVALUATION_PRIMARY) or {}
    scope = primary.get("scope")
    windows = _metrics_block(evaluations, EVALUATION_SELECTION_WINDOWS) if scope == SCOPE_HOLDOUT else None
    keys = ("selected_wape", "baseline_wape", "selected_bias", "coverage_80", "coverage_95", "total_selected_wape",
            "total_baseline_wape")
    extra_keys = ("selected_mase", "baseline_mase", "selected_rmsse", "baseline_rmsse", "pinball_p10", "pinball_p50",
                  "pinball_p90", "interval_width_80", "interval_width_95", "money", "baseline_money")
    metrics: dict[str, Any] = {k: primary.get(k) for k in (*keys, *extra_keys)}
    holdout = None
    if scope == SCOPE_HOLDOUT and plan is not None and plan.holdout_cutoff is not None:
        origin = plan.holdout_cutoff
        holdout = {
            "cutoff": origin.date().isoformat(), "horizon_days": plan.horizon,
            "target_from": (origin + pd.Timedelta(days=1)).date().isoformat(),
            "target_to": (origin + pd.Timedelta(days=plan.horizon)).date().isoformat(),
            "n_obs": primary.get("n_obs"),
            "selection_cutoffs": windows["cutoffs"] if windows else [],
            "calibration_cutoffs": windows["cutoffs"] if windows else [],
            "models_by_class": dict(eval_selection.winners) if eval_selection is not None else {},
        }
        note = (f"WAPE diario por serie (sucursal×producto) en el período final reservado "
                f"({holdout['target_from']} a {holdout['target_to']}): el modelo se eligió y los intervalos se "
                f"calibraron solo con ventanas cuyas fechas objetivo terminan en o antes de {holdout['cutoff']}; "
                "los días con quiebre de stock se excluyen.")
    elif primary:
        note = ("WAPE diario por serie (sucursal×producto) en las mismas ventanas de backtesting que eligieron el "
                "modelo (sin período final independiente: estimación optimista); los días con quiebre de stock se "
                "excluyen.")
    else:
        note = "Sin backtesting: no hay métricas de validación."
    metrics.update({
        "evaluation_scope": scope,
        "independent_holdout": scope == SCOPE_HOLDOUT,
        "holdout": holdout,
        "selection_windows": None if windows is None else {
            **{k: windows[k] for k in (*keys, *extra_keys)}, "cutoffs": windows["cutoffs"],
            "coverage_method": "leave_one_window_out", "optimistic": True,
            "note": "Ventanas que eligieron el modelo (comparten fechas objetivo entre sí): no es una prueba "
                    "independiente; cobertura dejando una ventana fuera."},
        "metric_note": note,
    })
    total = fv[(fv["level"] == "total") & (fv["granularity"] == "day")].sort_values("horizon_step")
    horizon_totals = {}
    for h in hz.SUPPORTED_HORIZONS:
        if h <= horizon:
            sub = total[total["horizon_step"] <= h]
            horizon_totals[str(h)] = {"units": round(float(sub["yhat"].sum()), 2),
                                      "revenue": round(float(sub["revenue_yhat"].sum()), 2),
                                      "status": (support or {}).get(h, {}).get("status"),
                                      "requested": h in horizons}
    risk_counts = recs["risk_level"].value_counts().to_dict() if not recs.empty else {}
    return {
        "series_count": int(series_fc["unique_id"].nunique()),
        "series_by_class": {k: int(v) for k, v in active["demand_class"].value_counts().items()},
        "models": {k: int(v) for k, v in series_fc.drop_duplicates("unique_id")["model_family"].value_counts().items()},
        "selection_reasons": selection.reasons,
        "metrics": metrics,
        "backtest": {"horizon_days": plan.horizon, "windows": plan.windows, "step_days": plan.step,
                     "cutoffs": [c.date().isoformat() for c in plan.cutoffs],
                     "holdout_cutoff": plan.holdout_cutoff.date().isoformat() if plan.has_holdout else None}
        if plan else None,
        "horizon_totals": horizon_totals,
        "price_assumption": PRICE_ASSUMPTION,
        "feature_set_version": FEATURE_SET_VERSION,
        "readiness_score": readiness.get("score"),
        "recommendations": {"count": int(len(recs)), "risk_counts": {k: int(v) for k, v in risk_counts.items()},
                            "purchase_investment": round(float(recs["estimated_investment"].fillna(0).sum()), 2)
                            if not recs.empty else 0.0},
        "feature_importance": lgbm.feature_importance() if lgbm is not None else {},
        "warnings": warnings,
    }
