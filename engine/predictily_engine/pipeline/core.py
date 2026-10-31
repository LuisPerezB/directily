"""Pipeline de pronóstico puro (sin acceso a base de datos).

Entrada: TenantData de UNA empresa. Salida: PipelineResult con todo lo necesario para
persistir (valores por nodo, evaluaciones, selección, recomendaciones, resumen, huella, manifiesto).

Etapa 2 · P1 (docs/architecture/v2/P1-demanda.md): horizontes 7/14/30/60/90 con abstención explícita,
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

from predictily_engine.backtest.rolling import (
    BacktestPlan,
    eligible_ids,
    plan_backtest,
    run_backtest,
    series_scale,
)
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.demand import evaluation as ev
from predictily_engine.demand import horizons as hz
from predictily_engine.demand.lifecycle import (
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
from predictily_engine.features.builder import PRICE_ASSUMPTION, FeatureBuilder, last_known_price
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket, identity_bucket
from predictily_engine.inventory.recommendations import (
    MAX_INTERNAL_HORIZON,
    LeadTimeErrorModel,
    build_recommendations,
    required_coverage_days,
)
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import BASELINE_FAMILY, FAMILIES, STATISTICAL
from predictily_engine.models.statistical import forecast_statistical
from predictily_engine.pipeline.data import TenantData
from predictily_engine.platform.config import config_hash, effective_config_record
from predictily_engine.platform.snapshot import DataSnapshot
from predictily_engine.profiling.readiness import compute_readiness
from predictily_engine.profiling.series import Panel, build_panel
from predictily_engine.reconciliation.hierarchy import AGG_LEVELS, aggregate, to_periods
from predictily_engine.selection.selector import Selection, select_models, selected_rows

log = logging.getLogger(__name__)

ProgressFn = Callable[[str, int], None]

# Compatibilidad: los nombres públicos de la evaluación viven ahora en `demand.evaluation`.
EVALUATION_PRIMARY = ev.EVALUATION_PRIMARY
EVALUATION_SELECTION_WINDOWS = ev.EVALUATION_SELECTION_WINDOWS
SCOPE_HOLDOUT = ev.SCOPE_HOLDOUT
SCOPE_SELECTION_WINDOWS = ev.SCOPE_SELECTION_WINDOWS


class InsufficientDataError(ValueError):
    def __init__(self, message: str, details: dict | None = None) -> None:
        super().__init__(message)
        self.details = details or {}


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


def _noop(stage: str, pct: int) -> None:  # pragma: no cover
    pass


def training_data_hash(panel: Panel) -> str:
    cols = panel.df[["unique_id", "ds", "y", "stockout"]].sort_values(["unique_id", "ds"])
    hashed = pd.util.hash_pandas_object(cols, index=False).to_numpy()
    return hashlib.sha256(hashed.tobytes()).hexdigest()


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
    effective_config = {
        "core": "demand", "config": core_config, "provenance": record["provenance"], "profile": record["profile"],
        "core_config_version": record["config_version"],
        "run": {"seed": seed, "horizon_days": horizon, "horizons": list(horizons), "min_improvement": min_improvement,
                "max_backtest_windows": max_windows, "max_series_lgbm": max_series_lgbm,
                "force_families": force_families, "feature_set_version": FEATURE_SET_VERSION},
    }
    config_version = config_hash(effective_config)

    progress("profiling", 10)
    panel = build_panel(data)
    readiness = compute_readiness(data, panel)
    if panel.profile.empty or not panel.profile["is_active"].any():
        raise InsufficientDataError("No hay series activas con ventas para pronosticar.",
                                    {"series_total": int(len(panel.profile))})
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
    features = FeatureBuilder(panel.df, data.branches, data.promotions, (cutoff + pd.Timedelta(days=fc_horizon)).date(),
                              promotion_revisions=data.promotion_revisions,
                              promotions_known_on=data.promotions_known_on)
    plan = plan_backtest(panel, max_windows)
    full_ids = eligible_ids(panel, cutoff)
    use_lgbm = 5 <= len(full_ids) <= max_series_lgbm
    if len(full_ids) > max_series_lgbm:
        warnings.append(f"{len(full_ids)} series superan el límite de LightGBM ({max_series_lgbm}); "
                        "se usan modelos estadísticos.")
    families = list(STATISTICAL) + (["lightgbm_global"] if use_lgbm else [])
    if force_families:
        families = [f for f in families if f in force_families] or families

    # ── Backtesting rolling-origin: ventanas de selección + período final reservado (holdout) ──
    # Cada ventana se perfila con datos ≤ su corte (elegibilidad y clase sin fuga, F4).
    t1 = time.perf_counter()
    if plan is not None:
        progress("backtesting", 20)
        bt = run_backtest(panel, features, plan, families, seed, n_jobs, use_lgbm,
                          progress=lambda frac: progress("backtesting", 20 + int(35 * frac)))
    else:
        bt = pd.DataFrame()
        warnings.append("Historia insuficiente para backtesting (< 70 días): se usa el promedio de la historia "
                        "disponible y los intervalos no están calibrados.")
    timings["backtesting"] = time.perf_counter() - t1

    progress("selecting", 58)
    eval_selection: Selection | None = None
    if bt.empty:
        selection = Selection({"pooled": "window_average"}, "seasonal_naive",
                              {"pooled": "Sin backtest posible: promedio móvil."}, pd.DataFrame(), pd.DataFrame())
    else:
        # Producción: se elige con toda la evidencia (ventanas de selección + holdout).
        selection = select_models(bt, prof, min_improvement)
        in_holdout = bt["is_holdout"].astype(bool)
        if in_holdout.any() and (~in_holdout).any():
            # Evaluación final (F6): la selección que se mide en el holdout solo ve ventanas anteriores.
            eval_selection = select_models(bt[~in_holdout], prof, min_improvement)
        else:
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
    if full_ids:
        preds.append(forecast_statistical(train[["unique_id", "ds", "y"]], fc_horizon, stat_needed))
    lgbm: LightGBMGlobal | None = None
    failed: dict[str, str] = {}
    if "lightgbm_global" in needed and full_ids:
        try:
            tr, ref = features.training_frame(cutoff, full_ids)
            lgbm = LightGBMGlobal(seed=seed, n_jobs=n_jobs).fit(tr)
            progress("predicting", 72)
            preds.append(lgbm.predict(fc_horizon, features.future_frame(cutoff, full_ids, fc_horizon, ref)))
        except Exception as exc:  # respaldo: el pipeline sigue con el modelo de respaldo
            log.exception("LightGBM final falló")
            failed["lightgbm_global"] = f"{type(exc).__name__}: {exc}"[:300]
            warnings.append(f"LightGBM falló en el entrenamiento final ({type(exc).__name__}); "
                            f"se usó el modelo de respaldo ({selection.fallback}).")
            lgbm = None
    # E2-19: lo que se sirve (y por tanto métricas, intervalos y etiquetas) es la selección con el respaldo
    # en lugar de cualquier familia que falló; el ganador del backtest queda como `planned`.
    served = selection.served(set(failed))
    eval_served = eval_selection.served(set(failed)) if eval_selection is not None else None
    if failed:
        limitations.append({"code": "fallback_served",
                            "message": (f"Falló el entrenamiento final de {', '.join(FAMILIES[f].label for f in failed)}: "
                                        f"se sirve el respaldo ({FAMILIES[selection.fallback].label}). Las métricas, los "
                                        "intervalos y las etiquetas corresponden al modelo servido.")})
    active_ids = set(active["unique_id"])
    short_ids = [u for u in active["unique_id"] if u not in set(full_ids)]
    if short_ids:
        preds.append(short_forecast(lifecycle, short_ids, cutoff, fc_horizon))
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
    miss = np.isnan(yhat)
    if miss.any() and selection.fallback in wide:
        yhat[miss] = wide.loc[miss, selection.fallback].to_numpy()
        fam_used[miss] = selection.fallback
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
    evaluations, eval_info = ev.evaluate(bt, served, eval_served, cat, data.currency)
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
    agg_fc = aggregate(series_fc, ["yhat", "revenue_yhat"], ["ds", "step"], AGG_LEVELS)
    node_hist = aggregate(panel.df.assign(y_clean=panel.df["y"]), ["y_clean"], ["ds"], AGG_LEVELS)
    agg_cal_day, agg_cal_week, agg_cal_month, agg_bt = None, None, None, pd.DataFrame()
    if not sel_bt.empty:
        b = sel_bt.copy()
        b.loc[b["stockout"], ["actual", "yhat"]] = 0.0  # excluye días censurados de ambos lados
        b = b.assign(branch_id=b["unique_id"].str.extract(r"b(\d+)-")[0].astype(int),
                     product_id=b["unique_id"].str.extract(r"-p(\d+)")[0].astype(int))
        b["category_id"] = b["product_id"].map(cat)
        agg_bt = aggregate(b, ["actual", "yhat"], ["window", "cutoff", "ds", "step"], AGG_LEVELS)
        agg_bt["scale"] = _node_scales_at(node_hist, agg_bt)
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
        cost = recent_sales.groupby(["branch_id", "product_id"])[["cost_amount", "cost_units"]].sum()
        unit_costs = (cost["cost_amount"] / cost["cost_units"]).where(cost["cost_units"] > 0)
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
    assumptions = [
        f"Ingresos = unidades × {PRICE_ASSUMPTION}.",
        "Intervalos 80/95 % conformales con residuos del backtest del modelo servido; P10/P90 = límites del intervalo 80 %.",
        "P50 solo a nivel serie-día y únicamente si se evaluó con pinball en un período final independiente.",
        "La historia de cada serie empieza en su primera venta; los días sin venta dentro de la cobertura son ceros observados.",
    ]
    snapshot = build_snapshot(data, panel, effective_config, features.knowledge_date(cutoff), ex_events, data.coverage)
    families_info = _families_info(served, selection, series_fc, bt, active, failed)
    served_info = {
        "fallback_used": bool(failed), "failed_families": failed,
        "planned_models_by_class": dict(selection.winners), "served_models_by_class": dict(served.winners),
        "fallback_family": selection.fallback,
        "note": ("Se sirve el respaldo porque falló el entrenamiento final del ganador: métricas, intervalos y "
                 "etiquetas corresponden al modelo servido." if failed else "Se sirve el modelo ganador del backtest."),
    }
    manifest = build_manifest(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), plan=plan, bt=bt, min_improvement=min_improvement,
        config_version=config_version, coverage=data.coverage or None, horizon_support=support,
        has_eval_selection=eval_selection is not None, p50=p50_info, seed=seed, served=served_info)
    summary = _summary(series_fc, forecast_values, served, evaluations, plan, readiness, recs, horizon, active, lgbm,
                       warnings, eval_served, support=support, horizons=horizons)
    summary.update({
        "quality_status": quality, "uncertainty_source": "conformal" if calibrator is not None and not calibrator.empty else "unknown",
        "horizon_support": {str(h): v for h, v in support.items()},
        "max_supported_horizon": max((h for h, v in support.items() if v["status"] != hz.STATUS_INSUFFICIENT), default=None),
        "p50": p50_info, "served": served_info,
        "special_cases": {**lifecycle.summary,
                          "exceptional_events": {"flag_enabled": flag_on, "used": ex_used, "stats": ex_stats}},
        "coverage": data.coverage, "limitations": limitations, "limitation_codes": [x["code"] for x in limitations],
        "assumptions": assumptions, "config_version": config_version, "data_snapshot_hash": snapshot.hash,
        "history_days": history_days, "horizon_days": horizon, "horizons_requested": list(horizons),
    })
    timings["total"] = time.perf_counter() - t0
    summary["timings_seconds"] = {k: round(v, 1) for k, v in timings.items()}
    return PipelineResult(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), data_to=cutoff.date(), panel=panel,
        readiness=readiness, selection=served, plan=plan, evaluations=evaluations, families=families_info,
        forecast_values=forecast_values, recommendations=recs, summary=summary, warnings=warnings,
        training_data_hash=training_data_hash(panel), lgbm=lgbm, timings=timings,
        backtest=bt, evaluation_selection=eval_served, planned_selection=selection, failed_families=failed,
        snapshot=snapshot, manifest=manifest, effective_config=effective_config, config_version=config_version,
        horizon_support=support, quality_status=quality, limitations=limitations, assumptions=assumptions,
        lifecycle=lifecycle,
    )


# ───────────────────────────── auxiliares ─────────────────────────────

def _node_scales_at(node_hist: pd.DataFrame, agg_bt: pd.DataFrame) -> pd.Series:
    """Escala de cada nodo al corte de cada ventana (media de 28 días previos)."""
    out = pd.Series(np.nan, index=agg_bt.index)
    for cutoff, idx in agg_bt.groupby("cutoff").groups.items():
        sc = _node_scale_final(node_hist, pd.Timestamp(cutoff))
        out.loc[idx] = agg_bt.loc[idx, "node_key"].map(sc).to_numpy()
    return out.fillna(0.05)


def _node_scale_final(node_hist: pd.DataFrame, cutoff: pd.Timestamp) -> pd.Series:
    h = node_hist[(node_hist["ds"] <= cutoff) & (node_hist["ds"] > cutoff - pd.Timedelta(days=28))]
    return h.groupby("node_key")["y_clean"].mean().clip(lower=0.05)


def _horizon_rows(agg_fc: pd.DataFrame, agg_bt: pd.DataFrame, final_scale: pd.Series, cutoff: pd.Timestamp,
                  horizons: list[int], support: dict[int, dict]) -> pd.DataFrame:
    """Totales del horizonte H por nodo agregado, con intervalo del TOTAL del horizonte.

    granularity='horizon', period_days=horizon_step=H, target_date=corte+H (último día del
    horizonte; así los cinco horizontes no colisionan en la clave única). El intervalo es conformal
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
