"""Pipeline de pronóstico puro (sin acceso a base de datos).

Entrada: TenantData de UNA empresa. Salida: PipelineResult con todo lo necesario para
persistir (valores por nodo, evaluaciones, selección, recomendaciones, resumen).
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

from predictily_engine.backtest.metrics import summarize
from predictily_engine.backtest.rolling import (
    HORIZON_BUCKETS,
    BacktestPlan,
    eligible_ids,
    horizon_bucket,
    plan_backtest,
    run_backtest,
    series_scale,
)
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.features.builder import PRICE_ASSUMPTION, FeatureBuilder, last_known_price
from predictily_engine.intervals.conformal import (
    ConformalCalibrator,
    day_bucket,
    hit_rates,
    identity_bucket,
    loo_coverage,
    score_intervals,
)
from predictily_engine.inventory.recommendations import (
    MAX_INTERNAL_HORIZON,
    LeadTimeErrorModel,
    build_recommendations,
    required_coverage_days,
)
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import BASELINE_FAMILY, FAMILIES, STATISTICAL
from predictily_engine.models.statistical import forecast_statistical, window_average_short
from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.readiness import compute_readiness
from predictily_engine.profiling.series import Panel, build_panel
from predictily_engine.reconciliation.hierarchy import AGG_LEVELS, aggregate, to_periods
from predictily_engine.selection.selector import Selection, select_models, selected_rows

log = logging.getLogger(__name__)

ProgressFn = Callable[[str, int], None]


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
    selection: Selection
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
    horizons: tuple[int, ...] = (7, 30, 90),
) -> PipelineResult:
    progress = progress or _noop
    timings: dict[str, float] = {}
    warnings: list[str] = []
    t0 = time.perf_counter()
    np.random.seed(seed)

    progress("profiling", 10)
    panel = build_panel(data)
    readiness = compute_readiness(data, panel)
    if panel.profile.empty or not panel.profile["is_active"].any():
        raise InsufficientDataError("No hay series activas con ventas para pronosticar.",
                                    {"series_total": int(len(panel.profile))})
    cutoff = panel.cutoff
    prof = panel.profile
    active = prof[prof["is_active"]]
    cls_map = prof.set_index("unique_id")["demand_class"]
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
    if "lightgbm_global" in needed and full_ids:
        try:
            tr, ref = features.training_frame(cutoff, full_ids)
            lgbm = LightGBMGlobal(seed=seed, n_jobs=n_jobs).fit(tr)
            progress("predicting", 72)
            preds.append(lgbm.predict(fc_horizon, features.future_frame(cutoff, full_ids, fc_horizon, ref)))
        except Exception as exc:  # respaldo: el pipeline sigue con el modelo de respaldo
            log.exception("LightGBM final falló")
            warnings.append(f"LightGBM falló en el entrenamiento final ({type(exc).__name__}); "
                            f"se usó el modelo de respaldo ({selection.fallback}).")
            lgbm = None
    short_ids = active.loc[~active["unique_id"].isin(full_ids), "unique_id"].tolist()
    if short_ids:
        short_train = panel.df[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(short_ids)]
        preds.append(window_average_short(short_train, cutoff, fc_horizon).assign(family="__short__"))
        warnings.append(f"{len(short_ids)} series con menos de 28 días de historia usan el promedio de su historia.")
    allp = pd.concat(preds, ignore_index=True) if preds else pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    timings["training"] = time.perf_counter() - t2

    progress("predicting", 76)
    wide = allp.pivot_table(index=["unique_id", "ds"], columns="family", values="yhat").reset_index()
    wide["demand_class"] = wide["unique_id"].map(cls_map)
    chosen = wide["demand_class"].map(selection.family_for)
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
    fam_used = np.where(fam_used == "__short__", "window_average", fam_used)
    series_fc = wide[["unique_id", "ds", "demand_class"]].copy()
    series_fc["yhat"] = np.clip(np.nan_to_num(yhat, nan=0.0), 0, None)
    series_fc["model_family"] = fam_used
    ids = series_fc["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    series_fc["branch_id"], series_fc["product_id"] = ids[0], ids[1]
    cat = data.products.set_index("product_id")["category_id"]
    series_fc["category_id"] = series_fc["product_id"].map(cat)
    series_fc["step"] = (series_fc["ds"] - cutoff).dt.days.astype(int)

    # ── Intervalos (series) ──
    scales = series_scale(panel.df, cutoff)
    series_fc["scale"] = series_fc["unique_id"].map(scales).fillna(0.05)
    # Producción: residuos del modelo elegido en TODAS las ventanas, agrupados por la clase que
    # cada serie tenía en el corte de su ventana (la clase con la que se hizo ese pronóstico).
    sel_bt = selected_rows(bt, prof, selection) if not bt.empty else pd.DataFrame()
    if not sel_bt.empty:
        cal_rows = sel_bt[~sel_bt["stockout"]].assign(group=lambda d: d["demand_class"])
        calibrator = ConformalCalibrator(cal_rows, "group", day_bucket)
    else:
        calibrator = ConformalCalibrator(pd.DataFrame(columns=["group", "step", "actual", "yhat", "scale"]))
    series_fc = calibrator.apply(series_fc.assign(group=series_fc["demand_class"]))

    # ── Ingresos con supuesto de precio ──
    price = last_known_price(panel.df, cutoff)
    fallback_price = data.products.set_index("product_id")["current_price"]
    series_fc["unit_price_assumed"] = series_fc["unique_id"].map(price)
    series_fc["unit_price_assumed"] = series_fc["unit_price_assumed"].fillna(series_fc["product_id"].map(fallback_price))
    series_fc["revenue_yhat"] = series_fc["yhat"] * series_fc["unit_price_assumed"]

    # Las recomendaciones usan el pronóstico interno completo (F2); se persiste solo el horizonte pedido.
    rec_fc = series_fc
    series_fc = series_fc[series_fc["step"] <= horizon].reset_index(drop=True)

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
    product_node = agg_fc["level"] == "product"
    agg_fc["unit_price_assumed"] = np.where(product_node & (agg_fc["yhat"] > 0),
                                            agg_fc["revenue_yhat"] / agg_fc["yhat"].where(agg_fc["yhat"] > 0, 1), np.nan)
    agg_fc["model_family"] = None

    values = [
        _frame_day(series_fc.assign(level="series",
                                    node_key="series:" + series_fc["branch_id"].astype(str) + ":"
                                             + series_fc["product_id"].astype(str))),
        _frame_day(agg_fc),
    ]
    for gran, cal in (("week", agg_cal_week), ("month", agg_cal_month)):
        per = to_periods(agg_fc[["node_key", "level", "branch_id", "product_id", "category_id", "ds", "yhat",
                                 "revenue_yhat"]], gran, ["yhat", "revenue_yhat"])
        per["scale"] = per["node_key"].map(final_scale).fillna(0.05) * per["period_days"]
        per = per.rename(columns={"horizon_step": "step"})
        if cal is not None and not cal.empty:
            per = cal.apply(per.assign(group=per["level"]))
        else:
            for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
                per[c] = np.nan
        per["granularity"] = gran
        per["unit_price_assumed"] = np.nan
        per["model_family"] = None
        values.append(per)
    values.append(_horizon_rows(agg_fc, agg_bt, final_scale, cutoff, [h for h in horizons if h <= horizon]))
    forecast_values = pd.concat([_finalize(v) for v in values], ignore_index=True)
    timings["reconciling"] = time.perf_counter() - t3

    # ── Evaluaciones: holdout (principal) + ventanas de selección (secundaria, optimista) ──
    evaluations = _evaluations(bt, selection, eval_selection, cat)

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

    families_info = _families_info(selection, series_fc, bt, active)
    summary = _summary(series_fc, forecast_values, selection, evaluations, plan, readiness, recs, horizon,
                       active, lgbm, warnings, eval_selection)
    timings["total"] = time.perf_counter() - t0
    summary["timings_seconds"] = {k: round(v, 1) for k, v in timings.items()}
    return PipelineResult(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), data_to=cutoff.date(), panel=panel,
        readiness=readiness, selection=selection, plan=plan, evaluations=evaluations, families=families_info,
        forecast_values=forecast_values, recommendations=recs, summary=summary, warnings=warnings,
        training_data_hash=training_data_hash(panel), lgbm=lgbm, timings=timings,
        backtest=bt, evaluation_selection=eval_selection,
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
                  horizons: list[int]) -> pd.DataFrame:
    """Totales del horizonte H por nodo agregado, con intervalo del TOTAL del horizonte.

    granularity='horizon', period_days=horizon_step=H, target_date=corte+H (último día del
    horizonte; así H=7/30/90 no colisionan en la clave única). El intervalo es conformal
    simétrico sobre el error de la suma de H días en cada ventana de backtest, normalizado por
    escala×H y agrupado por nivel (o todos los niveles si hay pocos puntos).
    """
    keys = ["node_key", "level", "branch_id", "product_id", "category_id"]
    fut = []
    for H in horizons:
        g = agg_fc[agg_fc["step"] <= H].groupby(keys, dropna=False, as_index=False)[["yhat", "revenue_yhat"]].sum()
        g["ds"] = cutoff + pd.Timedelta(days=H)
        g["period_days"] = H
        g["step"] = H
        g["scale"] = g["node_key"].map(final_scale).fillna(0.05) * H
        fut.append(g)
    out = pd.concat(fut, ignore_index=True) if fut else pd.DataFrame(columns=[*keys, "yhat"])
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
    out["granularity"] = "horizon"
    out["unit_price_assumed"] = np.nan
    out["model_family"] = None
    return out


def _frame_day(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    out["granularity"] = "day"
    out["period_days"] = 1
    return out


def _finalize(df: pd.DataFrame) -> pd.DataFrame:
    cols = ["node_key", "level", "branch_id", "product_id", "category_id", "granularity", "ds", "period_days",
            "step", "yhat", "lo_80", "hi_80", "lo_95", "hi_95", "model_family", "unit_price_assumed", "revenue_yhat"]
    out = df.reindex(columns=cols).rename(columns={"ds": "target_date", "step": "horizon_step"})
    for c in ("yhat", "lo_80", "hi_80", "lo_95", "hi_95", "unit_price_assumed", "revenue_yhat"):
        out[c] = out[c].astype(float).round(4)
    return out


# Contrato con Laravel: `evaluation_type='backtest'` son las métricas PRINCIPALES (las del período
# final reservado cuando existe). Las ventanas que eligieron el modelo se guardan aparte como
# diagnóstico secundario. `extra.evaluation_scope` indica de dónde viene cada fila.
EVALUATION_PRIMARY = "backtest"
EVALUATION_SELECTION_WINDOWS = "selection_windows"
SCOPE_HOLDOUT = "holdout"
SCOPE_SELECTION_WINDOWS = "selection_windows"
_BUCKET_DAYS = {b[0]: b[3] for b in HORIZON_BUCKETS}


def _iso_dates(values) -> list[str]:
    return sorted({pd.Timestamp(v).date().isoformat() for v in values})


def _metric_row(family: str, eval_level: str, key: str | None, row: pd.Series | dict, ctx: dict[str, Any],
                horizon_days: int | None = None, is_selected: bool = False, cov=(None, None)) -> dict[str, Any]:
    g = (lambda k: None if pd.isna(row.get(k)) else float(row.get(k))) if isinstance(row, dict) else \
        (lambda k: None if pd.isna(row[k]) else float(row[k]))
    return {
        "model_family": family, "evaluation_type": ctx["evaluation_type"], "level": eval_level, "level_key": key,
        "horizon_days": horizon_days, "is_baseline": family == BASELINE_FAMILY, "is_selected": is_selected,
        "wape": g("wape"), "mae": g("mae"), "rmse": g("rmse"), "bias": g("bias"),
        "coverage_80": cov[0], "coverage_95": cov[1], "n_obs": int(row["n_obs"]),
        "n_windows": len(ctx["cutoffs"]), "cutoffs": ctx["cutoffs"],
        "extra": {"evaluation_scope": ctx["scope"], "independent_holdout": ctx["scope"] == SCOPE_HOLDOUT},
    }


def _with_keys(rows: pd.DataFrame, cat: pd.Series) -> pd.DataFrame:
    """Filas sin quiebre con sucursal, producto, categoría y tramo de horizonte."""
    ids = rows["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    out = rows.assign(branch_id=ids[0], product_id=ids[1])
    out = out[~out["stockout"].astype(bool)].copy()
    out["category_id"] = out["product_id"].map(cat)
    out["hbucket"] = horizon_bucket(out["step"])
    return out


def _daily_total(rows: pd.DataFrame) -> pd.DataFrame:
    """Suma diaria de todas las series por ventana; los días censurados se anulan en ambos lados."""
    b = rows[["window", "ds", "actual", "yhat", "stockout"]].copy()
    b.loc[b["stockout"].astype(bool), ["actual", "yhat"]] = 0.0
    return b.groupby(["window", "ds"], as_index=False)[["actual", "yhat"]].sum()


def _coverage(test_rows: pd.DataFrame, calib_rows: pd.DataFrame | None) -> tuple[tuple, dict[str, tuple]]:
    """Cobertura 80/95 de `test_rows` (global y por tramo de horizonte), agrupando por clase de ventana.

    Con `calib_rows` (holdout): intervalos calibrados SOLO con esos residuos, ya observables en el
    origen evaluado → prueba prospectiva. Sin `calib_rows`: dejando una ventana fuera dentro de
    `test_rows` (diagnóstico de las ventanas de selección; usa ventanas posteriores y solapadas).
    """
    test = test_rows[~test_rows["stockout"].astype(bool)]
    test = test.assign(group=test["demand_class"], hbucket=horizon_bucket(test["step"]))
    buckets = list(pd.unique(test["hbucket"]))
    if calib_rows is not None:
        calib = calib_rows[~calib_rows["stockout"].astype(bool)]
        scored = score_intervals(calib.assign(group=calib["demand_class"]), test, "group", day_bucket)
        return hit_rates(scored), {b: hit_rates(scored[scored["hbucket"] == b]) for b in buckets}

    def loo(r: pd.DataFrame) -> tuple:
        return loo_coverage(r, "group", day_bucket) if r["window"].nunique() > 1 else (None, None)

    return loo(test), {b: loo(test[test["hbucket"] == b]) for b in buckets}


def _scope_rows(test: pd.DataFrame, calib: pd.DataFrame | None, sel: Selection, cat: pd.Series,
                ctx: dict[str, Any], detail: bool) -> list[dict[str, Any]]:
    """Métricas de las ventanas `test` para cada familia y para la selección `sel` (fila compuesta)."""
    rows: list[dict[str, Any]] = []
    if test.empty:
        return rows
    clean = _with_keys(test, cat)
    winners_by_class = {c: sel.family_for(c) for c in clean["demand_class"].dropna().unique()}
    for fam, sub in clean.groupby("family"):
        cov = (None, None)
        if fam == BASELINE_FAMILY:
            cov, _ = _coverage(test[test["family"] == fam], None if calib is None else calib[calib["family"] == fam])
        rows.append(_metric_row(fam, "overall", None, summarize(sub, ["family"]).iloc[0], ctx, cov=cov))
        for _, r in summarize(sub, ["demand_class"]).iterrows():
            rows.append(_metric_row(fam, "demand_class", r["demand_class"], r, ctx,
                                    is_selected=winners_by_class.get(r["demand_class"]) == fam))
        for _, r in summarize(sub, ["hbucket"]).iterrows():
            rows.append(_metric_row(fam, "horizon", r["hbucket"], r, ctx, horizon_days=_BUCKET_DAYS[r["hbucket"]]))
        if detail:
            for _, r in summarize(sub, ["branch_id"]).iterrows():
                rows.append(_metric_row(fam, "branch", f"b{int(r['branch_id'])}", r, ctx))
            for _, r in summarize(sub.dropna(subset=["category_id"]), ["category_id"]).iterrows():
                rows.append(_metric_row(fam, "category", f"c{int(r['category_id'])}", r, ctx))

    sel_test = selected_rows(test, None, sel)
    if not sel_test.empty:
        cov, cov_by_bucket = _coverage(sel_test, selected_rows(calib, None, sel) if calib is not None else None)
        s = _with_keys(sel_test, cat).assign(fam="selection")
        rows.append(_metric_row("selection", "overall", None, summarize(s, ["fam"]).iloc[0], ctx,
                                is_selected=True, cov=cov))
        for _, r in summarize(s, ["hbucket"]).iterrows():
            rows.append(_metric_row("selection", "horizon", r["hbucket"], r, ctx,
                                    horizon_days=_BUCKET_DAYS[r["hbucket"]], is_selected=True,
                                    cov=cov_by_bucket.get(r["hbucket"], (None, None))))
        levels = [("demand_class", "demand_class", "{}")]
        if detail:
            levels += [("branch_id", "branch", "b{}"), ("category_id", "category", "c{}"),
                       ("product_id", "product", "p{}")]
        for col, lvl, fmt in levels:
            for _, r in summarize(s.dropna(subset=[col]), [col]).iterrows():
                key = fmt.format(r[col] if col == "demand_class" else int(r[col]))
                rows.append(_metric_row("selection", lvl, key, r, ctx, is_selected=True))
        if detail:
            for _, r in summarize(s, ["branch_id", "product_id"]).iterrows():
                rows.append(_metric_row("selection", "series", f"b{int(r['branch_id'])}-p{int(r['product_id'])}",
                                        r, ctx, is_selected=True))
        # Nivel total (agregado): el error del total es mucho menor que el de cada producto.
        tot = _daily_total(sel_test).assign(fam="selection")
        rows.append(_metric_row("selection", "total", "total", summarize(tot, ["fam"]).iloc[0], ctx,
                                is_selected=True))
    base = test[test["family"] == BASELINE_FAMILY]
    if not base.empty:
        btot = _daily_total(base).assign(fam=BASELINE_FAMILY)
        rows.append(_metric_row(BASELINE_FAMILY, "total", "total", summarize(btot, ["fam"]).iloc[0], ctx))
    return rows


def _evaluations(bt: pd.DataFrame, selection: Selection, eval_selection: Selection | None,
                 cat: pd.Series) -> list[dict[str, Any]]:
    """Filas de `model_evaluations`.

    Con período final reservado (`eval_selection` elegida solo con las ventanas anteriores):
    - `backtest` (principal): métricas en el holdout de ese procedimiento; cobertura con intervalos
      calibrados solo con residuos de las ventanas de selección (terminan en o antes del origen).
    - `selection_windows` (secundaria): las ventanas que eligieron el modelo; optimistas por
      construcción y con cobertura "dejando una ventana fuera".
    Sin holdout independiente: `backtest` con todas las ventanas y la selección de producción
    (`extra.evaluation_scope = 'selection_windows'`; el pipeline lo advierte en `warnings`).
    """
    if bt.empty:
        return []
    if eval_selection is not None:
        in_holdout = bt["is_holdout"].astype(bool)
        hold, windows = bt[in_holdout], bt[~in_holdout]
        primary = {"evaluation_type": EVALUATION_PRIMARY, "scope": SCOPE_HOLDOUT, "cutoffs": _iso_dates(hold["cutoff"])}
        secondary = {"evaluation_type": EVALUATION_SELECTION_WINDOWS, "scope": SCOPE_SELECTION_WINDOWS,
                     "cutoffs": _iso_dates(windows["cutoff"])}
        return (_scope_rows(hold, windows, eval_selection, cat, primary, detail=True)
                + _scope_rows(windows, None, eval_selection, cat, secondary, detail=False))
    ctx = {"evaluation_type": EVALUATION_PRIMARY, "scope": SCOPE_SELECTION_WINDOWS, "cutoffs": _iso_dates(bt["cutoff"])}
    return _scope_rows(bt, None, selection, cat, ctx, detail=True)


def _families_info(selection: Selection, series_fc: pd.DataFrame, bt: pd.DataFrame, active: pd.DataFrame) -> dict:
    counts = series_fc.drop_duplicates("unique_id")["model_family"].value_counts().to_dict()
    evaluated = set(bt["family"].unique()) if not bt.empty else set()
    used = set(counts) | {selection.fallback}
    info = {}
    for fam in sorted(evaluated | used):
        if fam not in FAMILIES:
            continue
        classes = sorted({c for c, f in selection.winners.items() if f == fam and c != "pooled"})
        if fam == "window_average" and (active["demand_class"] == "insufficient").any():
            classes.append("insufficient")
        if counts.get(fam):
            status = "production"
        elif fam == selection.fallback:
            status = "fallback"
        else:
            status = "rejected"
        info[fam] = {"status": status, "series_count": int(counts.get(fam, 0)), "demand_classes": classes,
                     "level": FAMILIES[fam].level, "hyperparameters": FAMILIES[fam].hyperparameters,
                     "evaluated": fam in evaluated}
    if selection.fallback in info and info[selection.fallback]["status"] == "production":
        info[selection.fallback]["is_fallback"] = True
    return info


def _metrics_block(evaluations: list[dict[str, Any]], evaluation_type: str) -> dict[str, Any] | None:
    def ev(family, level, key=None):
        for e in evaluations:
            if (e["evaluation_type"] == evaluation_type and e["model_family"] == family and e["level"] == level
                    and e["level_key"] == key):
                return e
        return None

    sel, base = ev("selection", "overall"), ev(BASELINE_FAMILY, "overall")
    if sel is None and base is None:
        return None
    sel_tot, base_tot = ev("selection", "total", "total"), ev(BASELINE_FAMILY, "total", "total")
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
    }


def _summary(series_fc, fv, selection, evaluations, plan, readiness, recs, horizon, active, lgbm, warnings,
             eval_selection: Selection | None = None) -> dict:
    primary = _metrics_block(evaluations, EVALUATION_PRIMARY) or {}
    scope = primary.get("scope")
    windows = _metrics_block(evaluations, EVALUATION_SELECTION_WINDOWS) if scope == SCOPE_HOLDOUT else None
    keys = ("selected_wape", "baseline_wape", "selected_bias", "coverage_80", "coverage_95", "total_selected_wape",
            "total_baseline_wape")
    metrics: dict[str, Any] = {k: primary.get(k) for k in keys}
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
            **{k: windows[k] for k in keys}, "cutoffs": windows["cutoffs"],
            "coverage_method": "leave_one_window_out", "optimistic": True,
            "note": "Ventanas que eligieron el modelo (comparten fechas objetivo entre sí): no es una prueba "
                    "independiente; cobertura dejando una ventana fuera."},
        "metric_note": note,
    })
    total = fv[(fv["level"] == "total") & (fv["granularity"] == "day")].sort_values("horizon_step")
    horizon_totals = {}
    for h in (7, 30, 90):
        if h <= horizon:
            sub = total[total["horizon_step"] <= h]
            horizon_totals[str(h)] = {"units": round(float(sub["yhat"].sum()), 2),
                                      "revenue": round(float(sub["revenue_yhat"].sum()), 2)}
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
