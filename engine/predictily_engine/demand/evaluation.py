"""Filas de `model_evaluations` del core de Demanda.

Con período final reservado (`eval_selection` elegida solo con las ventanas anteriores):
- `backtest` (principal): métricas en el holdout de ese procedimiento; cobertura y cuantiles con intervalos
  calibrados solo con residuos de las ventanas de selección (terminan en o antes del origen del holdout).
- `selection_windows` (secundaria): las ventanas que eligieron el modelo; optimistas por construcción y
  con cobertura "dejando una ventana fuera" (sin métricas de cuantiles: no hay prueba independiente).
Sin holdout independiente: `backtest` con todas las ventanas y la selección de producción
(`extra.evaluation_scope = 'selection_windows'`; el pipeline lo advierte en `warnings`).

Cada fila lleva, además de las columnas clásicas (wape, mae, rmse, bias, coverage), el jsonb `metrics`:
MASE/RMSSE (escala = naive estacional 7 con datos de entrenamiento previos a cada origen), pérdida pinball
de P10/P50/P90 y amplitud media de los intervalos 80/95 (solo con holdout independiente), métricas en
DINERO separadas de las de unidades, tamaños de muestra y exclusiones. Ninguna cifra desconocida se
reemplaza por cero: queda `null`.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.backtest.metrics import pinball_loss, summarize
from predictily_engine.backtest.rolling import BUCKET_DAYS, horizon_bucket
from predictily_engine.demand.horizons import SUPPORTED_HORIZONS
from predictily_engine.intervals.conformal import day_bucket, hit_rates, loo_coverage, score_intervals
from predictily_engine.models.registry import BASELINE_FAMILY
from predictily_engine.selection.selector import Selection, selected_rows

# Contrato con Laravel: `evaluation_type='backtest'` son las métricas PRINCIPALES.
EVALUATION_PRIMARY = "backtest"
EVALUATION_SELECTION_WINDOWS = "selection_windows"
SCOPE_HOLDOUT = "holdout"
SCOPE_SELECTION_WINDOWS = "selection_windows"
METRICS_VERSION = "demand-metrics-v1"
SCALE_DEFINITION = "naive_estacional_7_con_datos_de_entrenamiento_previos_a_cada_origen"
# Mínimo de observaciones evaluadas para publicar P50 (la pinball con muestras pequeñas no decide nada).
P50_MIN_SCORED = 200

# Definiciones legibles (se guardan en cada fila para que `metrics` sea autoexplicativo).
DEFINITIONS = {
    "mase": "media de |error| / MAE del naive estacional (7 d) de entrenamiento; <1 mejora al naive de entrenamiento",
    "rmsse": "raíz de la media de error² / MSE del naive estacional (7 d) de entrenamiento",
    "pinball": "pérdida pinball media; P10 = lo_80, P90 = hi_80 (límites del intervalo 80 %); P50 = mediana conformal",
    "money": "unidades × precio: pronóstico al último precio neto conocido en el origen; real al precio neto observado",
}


def iso_dates(values) -> list[str]:
    return sorted({pd.Timestamp(v).date().isoformat() for v in values})


def _num(v: Any) -> float | None:
    if v is None:
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    return None if (np.isnan(f) or np.isinf(f)) else f


def _int(v: Any) -> int | None:
    f = _num(v)
    return None if f is None else int(f)


def with_keys(rows: pd.DataFrame, cat: pd.Series) -> pd.DataFrame:
    """Filas sin quiebre con sucursal, producto, categoría y tramo de horizonte."""
    ids = rows["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
    out = rows.assign(branch_id=ids[0], product_id=ids[1])
    out = out[~out["stockout"].astype(bool)].copy()
    out["category_id"] = out["product_id"].map(cat)
    out["hbucket"] = horizon_bucket(out["step"])
    return out


def daily_total(rows: pd.DataFrame) -> pd.DataFrame:
    """Suma diaria de todas las series por ventana; los días censurados se anulan en ambos lados."""
    b = rows[["window", "ds", "actual", "yhat", "stockout"]].copy()
    b.loc[b["stockout"].astype(bool), ["actual", "yhat"]] = 0.0
    return b.groupby(["window", "ds"], as_index=False)[["actual", "yhat"]].sum()


def coverage(test_rows: pd.DataFrame, calib_rows: pd.DataFrame | None
             ) -> tuple[tuple, dict[str, tuple], pd.DataFrame | None]:
    """Cobertura 80/95 de `test_rows` (global y por tramo de horizonte), agrupando por clase de ventana.

    Con `calib_rows` (holdout): intervalos calibrados SOLO con esos residuos, ya observables en el origen
    evaluado → prueba prospectiva; devuelve además las filas evaluadas con sus cuantiles (para pinball).
    Sin `calib_rows`: dejando una ventana fuera dentro de `test_rows` (diagnóstico de las ventanas de
    selección; usa ventanas posteriores y solapadas): sin filas evaluadas.
    """
    test = test_rows[~test_rows["stockout"].astype(bool)]
    test = test.assign(group=test["demand_class"], hbucket=horizon_bucket(test["step"]))
    buckets = list(pd.unique(test["hbucket"]))
    if calib_rows is not None:
        calib = calib_rows[~calib_rows["stockout"].astype(bool)]
        scored = score_intervals(calib.assign(group=calib["demand_class"]), test, "group", day_bucket)
        return hit_rates(scored), {b: hit_rates(scored[scored["hbucket"] == b]) for b in buckets}, scored

    def loo(r: pd.DataFrame) -> tuple:
        return loo_coverage(r, "group", day_bucket) if r["window"].nunique() > 1 else (None, None)

    return loo(test), {b: loo(test[test["hbucket"] == b]) for b in buckets}, None


def quantile_metrics(scored: pd.DataFrame | None) -> dict[str, Any] | None:
    """Pinball de P10/P50/P90 y amplitud de los intervalos sobre las filas evaluadas con intervalos
    calibrados fuera de muestra. P10 = lo_80 y P90 = hi_80 (a nivel serie son los cuantiles 10/90 del error;
    el intervalo 80 % es central). P50 = mediana conformal; `pinball_p50_yhat_as_median` es la referencia
    «usar la media como mediana». Si no hay filas evaluadas, None."""
    if scored is None or scored.empty:
        return None
    a = scored["actual"].to_numpy(float)
    lo80, hi80 = scored["lo_80"].to_numpy(float), scored["hi_80"].to_numpy(float)
    lo95, hi95 = scored["lo_95"].to_numpy(float), scored["hi_95"].to_numpy(float)
    p50 = scored["p50"].to_numpy(float) if "p50" in scored else np.full(len(scored), np.nan)
    y = scored["yhat"].to_numpy(float)
    tot = float(a.sum())
    has50 = np.isfinite(p50).any()
    return {
        "pinball_p10": pinball_loss(0.1, a, lo80),
        "pinball_p50": pinball_loss(0.5, a, p50) if has50 else None,
        "pinball_p90": pinball_loss(0.9, a, hi80),
        "pinball_p50_yhat_as_median": pinball_loss(0.5, a, y),
        "interval_width_80": float(np.mean(hi80 - lo80)),
        "interval_width_95": float(np.mean(hi95 - lo95)),
        "interval_width_80_rel": float((hi80 - lo80).sum() / tot) if tot > 0 else None,
        "interval_width_95_rel": float((hi95 - lo95).sum() / tot) if tot > 0 else None,
        "share_actual_le_p10": float(np.mean(a <= lo80 + 1e-9)),
        "share_actual_le_p50": float(np.mean(a <= p50 + 1e-9)) if has50 else None,
        "share_actual_le_p90": float(np.mean(a <= hi80 + 1e-9)),
        "n_scored": int(len(scored)),
    }


def p50_decision(q: dict[str, Any] | None) -> dict[str, Any]:
    """P50 se publica solo si se EVALUÓ con pinball fuera de muestra y mejora a «la media como mediana»."""
    base = {"method": "conformal_median_shift", "level": "series_day",
            "definition": "mediana condicional estimada = pronóstico + mediana del error normalizado × escala",
            "evaluation": "holdout_independiente"}
    if q is None or q.get("pinball_p50") is None:
        return {**base, "published": False, "reason": "no_evaluated",
                "message": "Sin período final independiente no se evalúa la mediana: P50 no se publica.",
                "evaluation": "ninguna"}
    n = int(q.get("n_scored") or 0)
    p50, ref = q["pinball_p50"], q["pinball_p50_yhat_as_median"]
    ev = {"pinball_p50": p50, "pinball_yhat_as_median": ref, "n_scored": n,
          "share_actual_le_p50": q.get("share_actual_le_p50")}
    if n < P50_MIN_SCORED:
        return {**base, **ev, "published": False, "reason": "sample_too_small",
                "message": f"Solo {n} observaciones evaluadas (< {P50_MIN_SCORED}): P50 no se publica."}
    if not (p50 < ref):
        return {**base, **ev, "published": False, "reason": "no_improvement",
                "message": "La mediana conformal no mejora la pinball de usar el pronóstico como mediana: no se publica."}
    return {**base, **ev, "published": True, "reason": "improves_pinball_in_holdout",
            "message": "Mediana evaluada con pinball en el período final reservado: mejora a usar la media como mediana."}


def build_metrics(row: Any, ctx: dict[str, Any], quant: dict[str, Any] | None = None,
                  excluded_stockout: int | None = None) -> dict[str, Any]:
    """Jsonb `model_evaluations.metrics` de una fila resumida por `summarize`."""
    get = (lambda k: row.get(k)) if hasattr(row, "get") else (lambda k: None)
    n_obs = _int(get("n_obs"))
    n_scaled, n_money = _int(get("n_scaled")), _int(get("n_money"))
    exclusions: dict[str, Any] = {
        "scale_undefined": None if n_scaled is None or n_obs is None else n_obs - n_scaled,
        "price_missing": None if n_money is None or n_obs is None else n_obs - n_money,
    }
    if excluded_stockout is not None:
        exclusions["stockout_days"] = int(excluded_stockout)
    metrics: dict[str, Any] = {
        "metrics_version": METRICS_VERSION,
        "mase": _num(get("mase")), "rmsse": _num(get("rmsse")), "scale": SCALE_DEFINITION,
        "pinball_p10": None, "pinball_p50": None, "pinball_p90": None, "pinball_p50_yhat_as_median": None,
        "interval_width_80": None, "interval_width_95": None,
        "money": {"currency": ctx.get("currency"), "wape": _num(get("money_wape")), "mae": _num(get("money_mae")),
                  "bias": _num(get("money_bias")), "actual_total": _num(get("money_actual")),
                  "forecast_total": _num(get("money_forecast")), "n_obs": n_money},
        "sample": {"n_obs": n_obs, "n_series": _int(get("n_series")), "n_windows": len(ctx["cutoffs"]),
                   "exclusions": exclusions},
    }
    if quant:
        metrics.update({k: v for k, v in quant.items() if k != "n_scored"})
        metrics["sample"]["n_scored_intervals"] = quant.get("n_scored")
    return metrics


def metric_row(family: str, eval_level: str, key: str | None, row: pd.Series | dict, ctx: dict[str, Any],
               horizon_days: int | None = None, is_selected: bool = False, cov=(None, None),
               quant: dict[str, Any] | None = None, excluded_stockout: int | None = None) -> dict[str, Any]:
    return {
        "model_family": family, "evaluation_type": ctx["evaluation_type"], "level": eval_level, "level_key": key,
        "horizon_days": horizon_days, "is_baseline": family == BASELINE_FAMILY, "is_selected": is_selected,
        "wape": _num(row.get("wape")), "mae": _num(row.get("mae")), "rmse": _num(row.get("rmse")),
        "bias": _num(row.get("bias")),
        "coverage_80": cov[0], "coverage_95": cov[1], "n_obs": int(row["n_obs"]),
        "n_windows": len(ctx["cutoffs"]), "cutoffs": ctx["cutoffs"],
        "metrics": build_metrics(row, ctx, quant, excluded_stockout),
        "extra": {"evaluation_scope": ctx["scope"], "independent_holdout": ctx["scope"] == SCOPE_HOLDOUT},
    }


def horizon_total_rows(family: str, test: pd.DataFrame, ctx: dict[str, Any], horizons=SUPPORTED_HORIZONS,
                       is_selected: bool = False, plan_horizon: int | None = None) -> list[dict[str, Any]]:
    """Error del TOTAL de H días (no del paso diario) por serie y ventana: `level='horizon_total'`,
    `level_key='H{h}'`. Solo con ventanas completas de H días; los días censurados se anulan en ambos lados.
    Incluye el desglose en dinero. Devuelve filas por H ≤ horizonte del backtest con observaciones."""
    rows: list[dict[str, Any]] = []
    if test.empty:
        return rows
    t = test.copy()
    cens = t["stockout"].astype(bool)
    t.loc[cens, ["actual", "yhat"]] = 0.0
    t["_m_act"] = (t["actual"] * t["price_act"]) if "price_act" in t else np.nan
    t["_m_fc"] = (t["yhat"] * t["price_fc"]) if "price_fc" in t else np.nan
    t["_pm"] = (t["price_act"].isna() | t["price_fc"].isna()).astype(int) if "price_act" in t else 1
    max_h = int(t["step"].max())
    for h in horizons:
        if h > max_h or (plan_horizon is not None and h > plan_horizon):
            continue
        sub = t[t["step"] <= h]
        g = sub.groupby(["unique_id", "window"], as_index=False).agg(
            actual=("actual", "sum"), yhat=("yhat", "sum"), n_days=("step", "size"), m_act=("_m_act", "sum"),
            m_fc=("_m_fc", "sum"), n_pm=("_pm", "sum"), censored=("stockout", "sum"))
        g = g[g["n_days"] == h]  # solo ventanas completas de H días
        if g.empty:
            continue
        s = summarize(g.assign(_g=1), ["_g"]).iloc[0].copy()
        ok = g["n_pm"] == 0
        if ok.any():
            mm = summarize(g[ok].assign(_g=1, actual=g.loc[ok, "m_act"], yhat=g.loc[ok, "m_fc"]), ["_g"]).iloc[0]
            for src, dst in (("wape", "money_wape"), ("mae", "money_mae"), ("bias", "money_bias"),
                             ("sum_actual", "money_actual")):
                s[dst] = mm[src]
            s["money_forecast"] = float(g.loc[ok, "m_fc"].sum())
            s["n_money"] = int(ok.sum())
        else:
            s["n_money"] = 0
        s["n_series"] = int(g["unique_id"].nunique())
        rows.append(metric_row(family, "horizon_total", f"H{h}", s, ctx, horizon_days=h, is_selected=is_selected,
                               excluded_stockout=int(g["censored"].sum())))
    return rows


def scope_rows(test: pd.DataFrame, calib: pd.DataFrame | None, sel: Selection, cat: pd.Series,
               ctx: dict[str, Any], detail: bool) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """Métricas de las ventanas `test` para cada familia y para la selección `sel` (fila compuesta).

    Devuelve (filas, info) con info = {"quantiles": métricas de cuantiles de la selección o None}."""
    rows: list[dict[str, Any]] = []
    info: dict[str, Any] = {"quantiles": None}
    if test.empty:
        return rows, info
    clean = with_keys(test, cat)
    excluded = int(len(test) - len(clean))
    winners_by_class = {c: sel.family_for(c) for c in clean["demand_class"].dropna().unique()}
    for fam, sub in clean.groupby("family"):
        cov = (None, None)
        if fam == BASELINE_FAMILY:
            cov, _, _ = coverage(test[test["family"] == fam],
                                 None if calib is None else calib[calib["family"] == fam])
        excl = int(((test["family"] == fam) & test["stockout"].astype(bool)).sum())
        rows.append(metric_row(fam, "overall", None, summarize(sub, ["family"]).iloc[0], ctx, cov=cov,
                               excluded_stockout=excl))
        for _, r in summarize(sub, ["demand_class"]).iterrows():
            rows.append(metric_row(fam, "demand_class", r["demand_class"], r, ctx,
                                   is_selected=winners_by_class.get(r["demand_class"]) == fam))
        for _, r in summarize(sub, ["hbucket"]).iterrows():
            rows.append(metric_row(fam, "horizon", r["hbucket"], r, ctx, horizon_days=BUCKET_DAYS[r["hbucket"]]))
        if detail:
            for _, r in summarize(sub, ["branch_id"]).iterrows():
                rows.append(metric_row(fam, "branch", f"b{int(r['branch_id'])}", r, ctx))
            for _, r in summarize(sub.dropna(subset=["category_id"]), ["category_id"]).iterrows():
                rows.append(metric_row(fam, "category", f"c{int(r['category_id'])}", r, ctx))

    sel_test = selected_rows(test, None, sel)
    if not sel_test.empty:
        cov, cov_by_bucket, scored = coverage(sel_test, selected_rows(calib, None, sel) if calib is not None else None)
        s = with_keys(sel_test, cat).assign(fam="selection")
        q_all = quantile_metrics(scored)
        info["quantiles"] = q_all
        q_bucket: dict[str, Any] = {}
        q_class: dict[str, Any] = {}
        if scored is not None and not scored.empty:
            q_bucket = {b: quantile_metrics(g) for b, g in scored.groupby("hbucket")}
            q_class = {c: quantile_metrics(g) for c, g in scored.groupby("demand_class")}
        rows.append(metric_row("selection", "overall", None, summarize(s, ["fam"]).iloc[0], ctx, is_selected=True,
                               cov=cov, quant=q_all, excluded_stockout=int(len(sel_test) - len(s))))
        for _, r in summarize(s, ["hbucket"]).iterrows():
            rows.append(metric_row("selection", "horizon", r["hbucket"], r, ctx, horizon_days=BUCKET_DAYS[r["hbucket"]],
                                   is_selected=True, cov=cov_by_bucket.get(r["hbucket"], (None, None)),
                                   quant=q_bucket.get(r["hbucket"])))
        levels = [("demand_class", "demand_class", "{}")]
        if detail:
            levels += [("branch_id", "branch", "b{}"), ("category_id", "category", "c{}"),
                       ("product_id", "product", "p{}")]
        for col, lvl, fmt in levels:
            for _, r in summarize(s.dropna(subset=[col]), [col]).iterrows():
                key = fmt.format(r[col] if col == "demand_class" else int(r[col]))
                rows.append(metric_row("selection", lvl, key, r, ctx, is_selected=True,
                                       quant=q_class.get(key) if lvl == "demand_class" else None))
        if detail:
            for _, r in summarize(s, ["branch_id", "product_id"]).iterrows():
                rows.append(metric_row("selection", "series", f"b{int(r['branch_id'])}-p{int(r['product_id'])}",
                                       r, ctx, is_selected=True))
        # Nivel total (agregado): el error del total es mucho menor que el de cada producto.
        tot = daily_total(sel_test).assign(fam="selection")
        rows.append(metric_row("selection", "total", "total", summarize(tot, ["fam"]).iloc[0], ctx, is_selected=True))
        # Total de H días (7/14/30/60/90) por serie y ventana: el error que importa para planear compras.
        rows += horizon_total_rows("selection", sel_test, ctx, is_selected=True)
    base = test[test["family"] == BASELINE_FAMILY]
    if not base.empty:
        btot = daily_total(base).assign(fam=BASELINE_FAMILY)
        rows.append(metric_row(BASELINE_FAMILY, "total", "total", summarize(btot, ["fam"]).iloc[0], ctx))
        rows += horizon_total_rows(BASELINE_FAMILY, base, ctx)
    return rows, info


def evaluate(bt: pd.DataFrame, selection: Selection, eval_selection: Selection | None, cat: pd.Series,
             currency: str | None = None) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """Filas de `model_evaluations` + info = {"quantiles": cuantiles del holdout o None, "p50": decisión}.

    `metrics.pinball_p50` solo se informa si la mediana se PUBLICA (evaluada y mejor que la media como
    mediana); si no, queda null y la pinball de la mediana candidata va en `pinball_p50_candidate`."""
    if bt.empty:
        return [], {"quantiles": None, "p50": p50_decision(None)}
    if eval_selection is not None:
        in_holdout = bt["is_holdout"].astype(bool)
        hold, windows = bt[in_holdout], bt[~in_holdout]
        primary = {"evaluation_type": EVALUATION_PRIMARY, "scope": SCOPE_HOLDOUT, "cutoffs": iso_dates(hold["cutoff"]),
                   "currency": currency}
        secondary = {"evaluation_type": EVALUATION_SELECTION_WINDOWS, "scope": SCOPE_SELECTION_WINDOWS,
                     "cutoffs": iso_dates(windows["cutoff"]), "currency": currency}
        r1, info = scope_rows(hold, windows, eval_selection, cat, primary, detail=True)
        r2, _ = scope_rows(windows, None, eval_selection, cat, secondary, detail=False)
        rows = r1 + r2
    else:
        ctx = {"evaluation_type": EVALUATION_PRIMARY, "scope": SCOPE_SELECTION_WINDOWS,
               "cutoffs": iso_dates(bt["cutoff"]), "currency": currency}
        rows, info = scope_rows(bt, None, selection, cat, ctx, detail=True)
    decision = p50_decision(info["quantiles"])
    for r in rows:
        m = r["metrics"]
        m["pinball_p50_candidate"] = m.get("pinball_p50")
        if not decision["published"]:
            m["pinball_p50"] = None
    info["p50"] = decision
    return rows, info


def horizon_obs(evaluations: list[dict[str, Any]]) -> dict[int, int]:
    """Observaciones (serie×ventana) del total de H días en la evaluación principal, por H."""
    out: dict[int, int] = {}
    for e in evaluations:
        if (e["evaluation_type"] == EVALUATION_PRIMARY and e["model_family"] == "selection"
                and e["level"] == "horizon_total" and e["horizon_days"] is not None):
            out[int(e["horizon_days"])] = int(e["n_obs"])
    return out
