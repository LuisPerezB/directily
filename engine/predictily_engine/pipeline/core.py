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
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket, identity_bucket, loo_coverage
from predictily_engine.inventory.recommendations import LeadTimeErrorModel, build_recommendations
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
    features = FeatureBuilder(panel.df, data.branches, data.promotions, (cutoff + pd.Timedelta(days=horizon)).date())
    plan = plan_backtest(panel, max_windows)
    full_ids = eligible_ids(panel, cutoff)
    use_lgbm = 5 <= len(full_ids) <= max_series_lgbm
    if len(full_ids) > max_series_lgbm:
        warnings.append(f"{len(full_ids)} series superan el límite de LightGBM ({max_series_lgbm}); "
                        "se usan modelos estadísticos.")
    families = list(STATISTICAL) + (["lightgbm_global"] if use_lgbm else [])
    if force_families:
        families = [f for f in families if f in force_families] or families

    # ── Backtesting rolling-origin ──
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
    if bt.empty:
        selection = Selection({"pooled": "window_average"}, "seasonal_naive",
                              {"pooled": "Sin backtest posible: promedio móvil."}, pd.DataFrame(), pd.DataFrame())
    else:
        selection = select_models(bt, prof, min_improvement)

    # ── Entrenamiento final y predicción ──
    t2 = time.perf_counter()
    progress("training", 62)
    needed = {selection.family_for(c) for c in active["demand_class"].unique()} | {selection.fallback, BASELINE_FAMILY}
    train = panel.df[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(full_ids)]
    preds = []
    stat_needed = [f for f in needed if f in STATISTICAL]
    if full_ids:
        preds.append(forecast_statistical(train[["unique_id", "ds", "y"]], horizon, stat_needed))
    lgbm: LightGBMGlobal | None = None
    if "lightgbm_global" in needed and full_ids:
        try:
            tr, ref = features.training_frame(cutoff, full_ids)
            lgbm = LightGBMGlobal(seed=seed, n_jobs=n_jobs).fit(tr)
            progress("predicting", 72)
            preds.append(lgbm.predict(horizon, features.future_frame(cutoff, full_ids, horizon, ref)))
        except Exception as exc:  # respaldo: el pipeline sigue con el modelo de respaldo
            log.exception("LightGBM final falló")
            warnings.append(f"LightGBM falló en el entrenamiento final ({type(exc).__name__}); "
                            f"se usó el modelo de respaldo ({selection.fallback}).")
            lgbm = None
    short_ids = active.loc[~active["unique_id"].isin(full_ids), "unique_id"].tolist()
    if short_ids:
        short_train = panel.df[(panel.df["ds"] <= cutoff) & panel.df["unique_id"].isin(short_ids)]
        preds.append(window_average_short(short_train, cutoff, horizon).assign(family="__short__"))
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
    sel_bt = selected_rows(bt, prof, selection) if not bt.empty else pd.DataFrame()
    if not sel_bt.empty:
        cal_rows = sel_bt[~sel_bt["stockout"]].assign(group=lambda d: d["unique_id"].map(cls_map))
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

    # ── Evaluaciones ──
    evaluations = _evaluations(bt, sel_bt, agg_bt, prof, selection, plan, cat)

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
        recs = build_recommendations(series_fc, data.latest_inventory, data.lead_times, data.suppliers,
                                     data.open_purchase_orders, unit_costs.dropna(), error_model, prof, scales,
                                     data.effective_settings, cutoff)
        if not recs.empty:
            missing_cost = recs["unit_cost"].isna()
            recs.loc[missing_cost, "unit_cost"] = recs.loc[missing_cost, "product_id"].map(prod_cost)
            recs["estimated_investment"] = np.where(recs["unit_cost"].notna(),
                                                    recs["suggested_qty"] * recs["unit_cost"].fillna(0), np.nan)
        no_inv = len(active) - (0 if recs.empty else len(recs))
        if no_inv > 0:
            warnings.append(f"{no_inv} series sin inventario registrado: sin recomendación de abastecimiento.")

    families_info = _families_info(selection, series_fc, bt, active)
    summary = _summary(series_fc, forecast_values, selection, evaluations, plan, readiness, recs, horizon,
                       active, lgbm, warnings)
    timings["total"] = time.perf_counter() - t0
    summary["timings_seconds"] = {k: round(v, 1) for k, v in timings.items()}
    return PipelineResult(
        cutoff=cutoff.date(), data_from=panel.df["ds"].min().date(), data_to=cutoff.date(), panel=panel,
        readiness=readiness, selection=selection, plan=plan, evaluations=evaluations, families=families_info,
        forecast_values=forecast_values, recommendations=recs, summary=summary, warnings=warnings,
        training_data_hash=training_data_hash(panel), lgbm=lgbm, timings=timings,
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


def _metric_row(family: str, eval_level: str, key: str | None, row: pd.Series | dict, plan: BacktestPlan | None,
                horizon_days: int | None = None, is_selected: bool = False, cov=(None, None)) -> dict[str, Any]:
    g = (lambda k: None if pd.isna(row.get(k)) else float(row.get(k))) if isinstance(row, dict) else \
        (lambda k: None if pd.isna(row[k]) else float(row[k]))
    return {
        "model_family": family, "evaluation_type": "backtest", "level": eval_level, "level_key": key,
        "horizon_days": horizon_days, "is_baseline": family == BASELINE_FAMILY, "is_selected": is_selected,
        "wape": g("wape"), "mae": g("mae"), "rmse": g("rmse"), "bias": g("bias"),
        "coverage_80": cov[0], "coverage_95": cov[1], "n_obs": int(row["n_obs"]),
        "n_windows": plan.windows if plan else 0,
        "cutoffs": [c.date().isoformat() for c in plan.cutoffs] if plan else [],
    }


def _evaluations(bt, sel_bt, agg_bt, prof, selection: Selection, plan, cat) -> list[dict[str, Any]]:
    if bt.empty:
        return []
    rows: list[dict[str, Any]] = []
    cls_map = prof.set_index("unique_id")["demand_class"]
    ids = bt["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    clean = bt.assign(branch_id=ids[0], product_id=ids[1])
    clean = clean[~clean["stockout"]].copy()
    clean["demand_class"] = clean["unique_id"].map(cls_map)
    clean["category_id"] = clean["product_id"].map(cat)
    clean["hbucket"] = horizon_bucket(clean["step"])
    winners_by_class = {c: selection.family_for(c) for c in clean["demand_class"].dropna().unique()}

    def cov_for(rows_df):
        r = rows_df[~rows_df["stockout"]].assign(group=rows_df["unique_id"].map(cls_map))
        return loo_coverage(r, "group", day_bucket) if r["window"].nunique() > 1 else (None, None)

    for fam, sub in clean.groupby("family"):
        cov = cov_for(bt[bt["family"] == fam]) if fam == BASELINE_FAMILY else (None, None)
        rows.append(_metric_row(fam, "overall", None, summarize(sub, ["family"]).iloc[0], plan, cov=cov))
        for _, r in summarize(sub, ["demand_class"]).iterrows():
            rows.append(_metric_row(fam, "demand_class", r["demand_class"], r, plan,
                                    is_selected=winners_by_class.get(r["demand_class"]) == fam))
        for _, r in summarize(sub, ["hbucket"]).iterrows():
            hd = {b[0]: b[3] for b in HORIZON_BUCKETS}[r["hbucket"]]
            rows.append(_metric_row(fam, "horizon", r["hbucket"], r, plan, horizon_days=hd))
        for _, r in summarize(sub, ["branch_id"]).iterrows():
            rows.append(_metric_row(fam, "branch", f"b{int(r['branch_id'])}", r, plan))
        for _, r in summarize(sub.dropna(subset=["category_id"]), ["category_id"]).iterrows():
            rows.append(_metric_row(fam, "category", f"c{int(r['category_id'])}", r, plan))
    if not sel_bt.empty:
        ids = sel_bt["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
        s = sel_bt.assign(branch_id=ids[0], product_id=ids[1])
        cov = cov_for(sel_bt)
        s = s[~s["stockout"]].copy()
        s["category_id"] = s["product_id"].map(cat)
        s["demand_class"] = s["unique_id"].map(cls_map)
        s["hbucket"] = horizon_bucket(s["step"])
        s["fam"] = "selection"
        rows.append(_metric_row("selection", "overall", None, summarize(s, ["fam"]).iloc[0], plan,
                                is_selected=True, cov=cov))
        sel_with_group = sel_bt[~sel_bt["stockout"]].assign(group=sel_bt["unique_id"].map(cls_map),
                                                             hbucket=horizon_bucket(sel_bt["step"]))
        for _, r in summarize(s, ["hbucket"]).iterrows():
            hd = {b[0]: b[3] for b in HORIZON_BUCKETS}[r["hbucket"]]
            sub_cov = sel_with_group[sel_with_group["hbucket"] == r["hbucket"]]
            c = loo_coverage(sub_cov, "group", day_bucket) if sub_cov["window"].nunique() > 1 else (None, None)
            rows.append(_metric_row("selection", "horizon", r["hbucket"], r, plan, horizon_days=hd,
                                    is_selected=True, cov=c))
        for col, lvl, fmt in (("demand_class", "demand_class", "{}"), ("branch_id", "branch", "b{}"),
                              ("category_id", "category", "c{}"), ("product_id", "product", "p{}")):
            src = s.dropna(subset=[col])
            for _, r in summarize(src, [col]).iterrows():
                key = fmt.format(r[col] if col == "demand_class" else int(r[col]))
                rows.append(_metric_row("selection", lvl, key, r, plan, is_selected=True))
        for _, r in summarize(s, ["branch_id", "product_id"]).iterrows():
            rows.append(_metric_row("selection", "series", f"b{int(r['branch_id'])}-p{int(r['product_id'])}", r,
                                    plan, is_selected=True))
    # Nivel total (agregado): el error del total es mucho menor que el de cada producto.
    if not agg_bt.empty:
        tot = agg_bt[agg_bt["level"] == "total"].assign(fam="selection")
        rows.append(_metric_row("selection", "total", "total", summarize(tot, ["fam"]).iloc[0], plan,
                                is_selected=True))
        base = bt[bt["family"] == BASELINE_FAMILY].copy()
        base.loc[base["stockout"], ["actual", "yhat"]] = 0.0
        btot = base.groupby(["window", "ds"], as_index=False)[["actual", "yhat"]].sum().assign(fam=BASELINE_FAMILY)
        rows.append(_metric_row(BASELINE_FAMILY, "total", "total", summarize(btot, ["fam"]).iloc[0], plan))
    return rows


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


def _summary(series_fc, fv, selection, evaluations, plan, readiness, recs, horizon, active, lgbm, warnings) -> dict:
    def ev(family, level, key=None):
        for e in evaluations:
            if e["model_family"] == family and e["level"] == level and e["level_key"] == key:
                return e
        return None

    sel = ev("selection", "overall")
    base = ev(BASELINE_FAMILY, "overall")
    sel_tot = ev("selection", "total", "total")
    base_tot = ev(BASELINE_FAMILY, "total", "total")
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
        "metrics": {
            "selected_wape": sel["wape"] if sel else None,
            "baseline_wape": base["wape"] if base else None,
            "selected_bias": sel["bias"] if sel else None,
            "coverage_80": sel["coverage_80"] if sel else None,
            "coverage_95": sel["coverage_95"] if sel else None,
            "total_selected_wape": sel_tot["wape"] if sel_tot else None,
            "total_baseline_wape": base_tot["wape"] if base_tot else None,
            "metric_note": "WAPE diario por serie (sucursal×producto) en backtesting rolling-origin; "
                           "los días con quiebre de stock se excluyen.",
        },
        "backtest": {"horizon_days": plan.horizon, "windows": plan.windows, "step_days": plan.step,
                     "cutoffs": [c.date().isoformat() for c in plan.cutoffs]} if plan else None,
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
