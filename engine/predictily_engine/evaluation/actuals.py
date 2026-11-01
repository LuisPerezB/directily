"""Comparación de pronósticos pasados con ventas reales y detección de deterioro (drift).

- Para cada run completado se toman las fechas objetivo ya observadas (≤ última venta) y se
  calcula la venta real por nodo con las mismas reglas del entrenamiento (sin anuladas ni
  devoluciones). Se guarda en forecast_actuals (idempotente por run, nodo y fecha).
- Métricas realizadas por run (nivel serie, sin días con quiebre de stock, fuente ausente ni cierre; y nivel total sin los días
  de fuente ausente o cierre) en model_evaluations con evaluation_type='realized'.
- Drift: si el WAPE realizado de un tramo de horizonte (h1-7 / h8-14 / h15-30 / h31-60 / h61-90), acumulado en
  los runs de producción evaluados, supera max(1.3 × backtest, backtest + 0.10) con al menos 14
  días observados, se crea una alerta forecast_drift (deduplicada).

Semántica de días (ADR-V25-03): un día sin ventas NO es siempre un cero. Los días de una serie que la fuente no cubre (fuera de
`data_coverage`, dentro de un hueco declarado o inferido, o inferidos por la regla de actividad de la sucursal) o en que la sucursal
cerró (`calendar_events.category = 'closure'`) NO son ceros observados: se marcan censurados (`is_censored`), no entran a las métricas
realizadas ni a la detección de deriva, y se cuentan por clase (`exclusions`: `stockout_days`, `missing_source_days`, `closed_days`).
Se clasifican con la MISMA función del entrenamiento (`profiling.days.classify_days`) y con el conocimiento más reciente de las
declaraciones; así una caída de la integración no se lee como un deterioro del pronóstico.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.backtest.metrics import summarize
from predictily_engine.backtest.rolling import BUCKET_DAYS, horizon_bucket
from predictily_engine.data.asof import local_today
from predictily_engine.db.repository import TenantRepository
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import jdump, mark_failed, none_if_nan, upsert_alert
from predictily_engine.demand.coverage import SalesCoverage
from predictily_engine.pipeline.data import load_day_declarations
from predictily_engine.pipeline.errors import NotFoundError
from predictily_engine.platform.config import effective_config_record
from predictily_engine.profiling.days import BASIS_INFERRED, CLOSED, EXCLUDED_STATUSES, MISSING_SOURCE, DayRules, classify_days
from predictily_engine.reconciliation.hierarchy import ALL_LEVELS, aggregate

MIN_DRIFT_DAYS = 14


def drift_threshold(backtest_wape: float) -> float:
    return max(1.3 * backtest_wape, backtest_wape + 0.10)


def _flag_nodes(days: pd.DataFrame, flag: str, categories: pd.Series) -> pd.DataFrame:
    """Marca (`flag` = 1) cada nodo y día que contiene al menos una serie-día de `days` (branch_id, product_id, ds)."""
    s = days[["branch_id", "product_id", "ds"]].copy()
    s["category_id"] = s["product_id"].map(categories)
    s[flag] = 1
    return aggregate(s, [flag], ["ds"], ALL_LEVELS)[["node_key", "ds", flag]]


def node_actuals(actual: pd.DataFrame, stockouts: pd.DataFrame, categories: pd.Series,
                 excluded: pd.DataFrame | None = None) -> pd.DataFrame:
    """Reales por nodo y día. actual: branch_id, product_id, ds, units (solo combinaciones con venta).

    `stockouts`: series-día con quiebre de stock (la venta es cota inferior). `excluded`: series-día de fuente ausente o cierre
    (ADR-V25-03: branch_id, product_id, ds), cuyo «cero» es dato ausente. Un nodo agregado queda marcado si alguna de sus series
    lo está ese día. Devuelve node_key, ds, units, censored (quiebre) y excluded (fuente ausente o cierre)."""
    a = actual.copy()
    a["category_id"] = a["product_id"].map(categories)
    if a.empty:  # ningún documento de venta en el período (p. ej. caída total de la integración): solo hay marcas
        nodes = pd.DataFrame({"node_key": pd.Series(dtype=object), "ds": pd.Series(dtype="datetime64[ns]"), "units": pd.Series(dtype=float)})
    else:
        nodes = aggregate(a, ["units"], ["ds"], ALL_LEVELS)[["node_key", "ds", "units"]]
    for flag, days in (("censored", stockouts), ("excluded", excluded)):
        if days is not None and not days.empty:
            nodes = nodes.merge(_flag_nodes(days, flag, categories), on=["node_key", "ds"], how="outer")
        else:
            nodes[flag] = 0
        nodes[flag] = nodes[flag].fillna(0) > 0
    return nodes[["node_key", "ds", "units", "censored", "excluded"]]


def realized_rows(fv: pd.DataFrame, nodes: pd.DataFrame) -> pd.DataFrame:
    """Une pronóstico diario con reales (0 si no hubo venta en el nodo ese día). `is_excluded`: fuente ausente o cierre (nunca un cero
    observado); `is_censored`: además, quiebre de stock. Ninguno entra a las métricas de la serie."""
    df = fv.merge(nodes, on=["node_key", "ds"], how="left")
    df["actual"] = df["units"].fillna(0.0)
    df["is_excluded"] = df["excluded"].eq(True)
    df["is_censored"] = df["censored"].eq(True) | df["is_excluded"]
    df["abs_error"] = (df["actual"] - df["yhat"]).abs()
    return df.drop(columns=["units", "censored", "excluded"])


@dataclass
class DayContext:
    """Insumos de la clasificación de días (`profiling.days`) con el conocimiento MÁS RECIENTE, para clasificar los días evaluados de
    pronósticos ya publicados: cobertura, huecos, cierres, mapa fuente → sucursales, líneas diarias por sucursal (regla inferida) y las
    reglas de la configuración efectiva de la empresa."""

    coverage: dict[str, Any]
    coverage_gaps: pd.DataFrame
    closures: pd.DataFrame
    source_branches: dict[int, list[int]]
    sales: pd.DataFrame
    returns: pd.DataFrame
    rules: DayRules = field(default_factory=DayRules)

    @classmethod
    def load(cls, repo: TenantRepository, last: date) -> "DayContext":
        tenant = repo.tenant()
        try:
            rules = DayRules.from_config(effective_config_record("demand", tenant.get("settings"), tenant.get("sector"))["effective_config"])
        except ValueError:  # configuración inválida: la ejecución la rechaza; aquí se evalúa con los valores del core
            rules = DayRules()
        gaps, closures, source_branches = load_day_declarations(repo)
        today = local_today(repo.timezone())   # la verdad más reciente: lo conocido hasta hoy (como `daily_actuals`)
        sales = repo.daily_sales(last, today)
        returns = repo.daily_returns(last, today)
        return cls(coverage=SalesCoverage.for_repository(repo).as_dict(), coverage_gaps=gaps, closures=closures,
                   source_branches=source_branches,
                   sales=sales.groupby(["branch_id", "ds"], as_index=False)["lines"].sum() if not sales.empty else sales,
                   returns=returns[["branch_id", "ds"]].drop_duplicates() if not returns.empty else returns, rules=rules)


def excluded_series_days(fv: pd.DataFrame, ctx: DayContext | None, date_from, date_to) -> pd.DataFrame:
    """Series-día pronosticados (nivel serie de `fv`) que la fuente no cubre o en que la sucursal cerró, con su estado y base. Usa
    `classify_days` del entrenamiento, con la historia previa a `date_from` para la regla inferida."""
    cols = ["branch_id", "product_id", "ds", "day_status", "day_basis"]
    series = fv.loc[fv["level"] == "series", ["branch_id", "product_id", "ds"]].drop_duplicates() if ctx is not None else fv.iloc[0:0]
    if series.empty:
        return pd.DataFrame(columns=cols)
    df = series.astype({"branch_id": int, "product_id": int}).assign(units=0.0, stockout=False)
    context_from = pd.Timestamp(date_from) - pd.Timedelta(days=ctx.rules.infer_lookback_days + 1)
    cls = classify_days(df, ctx, pd.Timestamp(date_to), ctx.rules, context_from=context_from)
    out = df.assign(day_status=np.asarray(cls.status.astype(str)), day_basis=np.asarray(cls.basis.astype(object)))
    return out[out["day_status"].isin(EXCLUDED_STATUSES)][cols].reset_index(drop=True)


def exclusion_counts(rows: pd.DataFrame, excluded: pd.DataFrame) -> dict[str, int]:
    """Series-día del pronóstico por clase de exclusión (nunca cuentan como cero observado): quiebre de stock, fuente ausente
    (`missing_source`; `missing_source_inferred_days` es el subconjunto inferido) y cierre (`closed`), y cuántos quedan evaluables."""
    ser = rows[rows["level"] == "series"]
    keys = ["branch_id", "product_id", "ds"]
    tag = ser[keys].astype({"branch_id": int, "product_id": int}).merge(
        excluded[[*keys, "day_status", "day_basis"]].astype({"branch_id": int, "product_id": int}), on=keys, how="left") \
        if len(ser) else pd.DataFrame(columns=[*keys, "day_status", "day_basis"])
    missing, closed = tag["day_status"].eq(MISSING_SOURCE), tag["day_status"].eq(CLOSED)
    inferred = missing & tag["day_basis"].eq(BASIS_INFERRED)
    stockout = int(ser["is_censored"].sum()) - int(missing.sum()) - int(closed.sum())
    return {"series_days": int(len(ser)), "evaluated_series_days": int((~ser["is_censored"]).sum()), "stockout_days": max(stockout, 0),
            "missing_source_days": int(missing.sum()), "missing_source_inferred_days": int(inferred.sum()), "closed_days": int(closed.sum())}


def _stockout_days(repo: TenantRepository, date_from, date_to) -> pd.DataFrame:
    inv = repo.inventory(date_from - timedelta(days=1), date_to)
    if inv.empty:
        return pd.DataFrame(columns=["branch_id", "product_id", "ds"])
    inv["ds"] = pd.to_datetime(inv["ds"])
    oos = inv[inv["on_hand"] <= 0][["branch_id", "product_id", "ds"]]
    nxt = oos.assign(ds=oos["ds"] + pd.Timedelta(days=1))
    out = pd.concat([oos, nxt]).drop_duplicates()
    return out[(out["ds"] >= pd.Timestamp(date_from)) & (out["ds"] <= pd.Timestamp(date_to))]


def evaluate_actuals(tenant_id: int, training_job_id: int | None = None, lookback_runs: int = 10) -> dict[str, Any]:
    engine = get_engine()
    t0 = time.perf_counter()
    try:
        with engine.begin() as conn:
            repo = TenantRepository(conn, tenant_id)
            try:
                repo.tenant()
            except LookupError as exc:
                raise NotFoundError(str(exc)) from exc
            if training_job_id is not None:
                if repo.training_job(training_job_id) is None:
                    raise NotFoundError("training_job inexistente para esta empresa")
                conn.execute(text(
                    "UPDATE training_jobs SET status = 'running', stage = 'evaluating', progress = 10, "
                    "started_at = COALESCE(started_at, now()), updated_at = now() "
                    "WHERE id = :id AND tenant_id = :tenant_id"), {"id": training_job_id, "tenant_id": tenant_id})
            repo.analytic_reads()
            result = _evaluate(conn, repo, lookback_runs)
            if training_job_id is not None:
                conn.execute(text(
                    "UPDATE training_jobs SET status = 'succeeded', stage = NULL, progress = 100, finished_at = now(), "
                    "duration_ms = :dur, result = CAST(:result AS jsonb), updated_at = now() "
                    "WHERE id = :id AND tenant_id = :tenant_id"),
                    {"dur": int((time.perf_counter() - t0) * 1000), "result": jdump(result), "id": training_job_id,
                     "tenant_id": tenant_id})
        return result
    except NotFoundError:
        raise
    except Exception as exc:
        mark_failed(engine, tenant_id, None, training_job_id, f"{type(exc).__name__}: {exc}")
        raise


def _evaluate(conn: Connection, repo: TenantRepository, lookback_runs: int) -> dict[str, Any]:
    tenant_id = repo.tenant_id
    last = repo.last_sale_date()
    runs = repo.completed_runs(lookback_runs)
    categories = repo.products().set_index("product_id")["category_id"]
    evaluated, pooled = [], []
    current = repo.current_run()
    day_ctx = DayContext.load(repo, last) if (last is not None and not runs.empty) else None
    totals: dict[str, int] = {}
    for run in runs.to_dict("records"):
        cutoff = pd.Timestamp(run["cutoff_date"])
        if last is None or pd.Timestamp(last) <= cutoff:
            continue
        date_from = (cutoff + pd.Timedelta(days=1)).date()
        date_to = min(pd.Timestamp(last), cutoff + pd.Timedelta(days=int(run["horizon_days"]))).date()
        fv = repo.forecast_values_for_run(int(run["id"]), date_from, date_to)
        if fv.empty:
            continue
        fv["ds"] = pd.to_datetime(fv["ds"])
        act = repo.daily_actuals(date_from, date_to)
        act["ds"] = pd.to_datetime(act["ds"])
        # ADR-V25-03: fuente ausente y cierres no son ceros observados; se clasifican con la regla del entrenamiento.
        excluded = excluded_series_days(fv, day_ctx, date_from, date_to)
        nodes = node_actuals(act, _stockout_days(repo, date_from, date_to), categories, excluded)
        rows = realized_rows(fv, nodes)
        _upsert_actuals(conn, tenant_id, int(run["id"]), rows)
        series = rows[(rows["level"] == "series") & ~rows["is_censored"]]
        exclusions = exclusion_counts(rows, excluded)
        for key, value in exclusions.items():
            totals[key] = totals.get(key, 0) + value
        metrics = _write_realized(conn, tenant_id, run, rows, series, exclusions)
        evaluated.append({"run_id": int(run["id"]), "version": int(run["version"]), "run_type": run["run_type"],
                          "days_observed": int(rows["ds"].nunique()), "exclusions": exclusions, **metrics})
        if run["run_type"] == "production":
            pooled.append(series.assign(run_id=int(run["id"])))

    drift_detected, drift_details = False, []
    if current is not None and pooled:
        allrows = pd.concat(pooled, ignore_index=True)
        allrows["hbucket"] = horizon_bucket(allrows["horizon_step"])
        bt = pd.DataFrame(conn.execute(text(
            "SELECT level_key, wape::float8 AS wape FROM model_evaluations WHERE tenant_id = :tenant_id "
            "AND forecast_run_id = :run_id AND evaluation_type = 'backtest' AND level = 'horizon' AND is_selected"
        ), {"tenant_id": tenant_id, "run_id": int(current["id"])}).mappings().all())
        bt_map = dict(zip(bt["level_key"], bt["wape"])) if not bt.empty else {}
        for bucket, sub in allrows.groupby("hbucket"):
            days = sub["ds"].nunique()
            if bucket not in bt_map or days < MIN_DRIFT_DAYS or sub["actual"].sum() <= 0:
                continue
            realized = float(sub["abs_error"].sum() / sub["actual"].sum())
            limit = drift_threshold(float(bt_map[bucket]))
            drift_details.append({"bucket": bucket, "realized_wape": realized, "backtest_wape": float(bt_map[bucket]),
                                  "threshold": limit, "days_observed": int(days)})
            if realized > limit:
                drift_detected = True
        if drift_detected:
            worst = max(drift_details, key=lambda d: d["realized_wape"] - d["threshold"])
            upsert_alert(conn, tenant_id, type_="forecast_drift", severity="warning",
                         title="La precisión del pronóstico se deterioró",
                         message=(f"En el tramo {worst['bucket']} el error real (WAPE {worst['realized_wape']:.1%}) "
                                  f"supera el esperado según el backtesting ({worst['backtest_wape']:.1%}). "
                                  "Se recomienda revisar datos recientes y generar un nuevo pronóstico."),
                         payload={"details": drift_details, "forecast_run_id": int(current["id"])},
                         dedup_key="forecast_drift", related_type="forecast_run", related_id=int(current["id"]))
    latest = next((e for e in evaluated if current is not None and e["run_id"] == int(current["id"])),
                  evaluated[0] if evaluated else None)
    return {"evaluated_runs": evaluated, "drift_detected": drift_detected, "drift_details": drift_details,
            "realized_wape": latest["wape"] if latest else None, "exclusions": totals}


def _upsert_actuals(conn: Connection, tenant_id: int, run_id: int, rows: pd.DataFrame) -> None:
    payload = [{
        "tenant_id": tenant_id, "run_id": run_id, "node_key": r.node_key, "level": r.level,
        "branch_id": none_if_nan(r.branch_id), "product_id": none_if_nan(r.product_id),
        "category_id": none_if_nan(r.category_id), "ds": r.ds.date(), "step": int(r.horizon_step),
        "yhat": float(r.yhat), "lo": none_if_nan(r.lo_80), "hi": none_if_nan(r.hi_80), "actual": float(r.actual),
        "err": float(r.abs_error), "cens": bool(r.is_censored),
    } for r in rows.itertuples(index=False)]
    for col in ("branch_id", "product_id", "category_id"):
        for p in payload:
            if p[col] is not None:
                p[col] = int(p[col])
    conn.execute(text(
        """
        INSERT INTO forecast_actuals (tenant_id, forecast_run_id, node_key, level, branch_id, product_id, category_id,
            target_date, horizon_step, yhat, lo_80, hi_80, actual, abs_error, is_censored, evaluated_at)
        VALUES (:tenant_id, :run_id, :node_key, :level, :branch_id, :product_id, :category_id, :ds, :step, :yhat, :lo,
            :hi, :actual, :err, :cens, now())
        ON CONFLICT (forecast_run_id, node_key, target_date) DO UPDATE SET actual = EXCLUDED.actual,
            abs_error = EXCLUDED.abs_error, is_censored = EXCLUDED.is_censored, evaluated_at = now()
        """
    ), payload)


def _write_realized(conn: Connection, tenant_id: int, run: dict, rows: pd.DataFrame, series: pd.DataFrame,
                    exclusions: dict[str, int] | None = None) -> dict:
    run_id = int(run["id"])
    conn.execute(text("DELETE FROM model_evaluations WHERE tenant_id = :tenant_id AND forecast_run_id = :run_id "
                      "AND evaluation_type = 'realized'"), {"tenant_id": tenant_id, "run_id": run_id})
    out_rows = []
    base = {"tenant_id": tenant_id, "run_id": run_id, "family": "selection", "n_windows": 1,
            "cutoffs": jdump([str(run["cutoff_date"])[:10]]), "extra": jdump({"exclusions": exclusions or {}})}

    def add(level, key, df, horizon_days=None, cov=None):
        if df.empty:
            return None
        s = summarize(df.assign(_g=1).rename(columns={"actual": "actual"}), ["_g"]).iloc[0]
        out_rows.append({**base, "level": level, "level_key": key, "horizon_days": horizon_days,
                         "wape": none_if_nan(s["wape"]), "mae": none_if_nan(s["mae"]), "rmse": none_if_nan(s["rmse"]),
                         "bias": none_if_nan(s["bias"]), "coverage_80": cov, "n_obs": int(s["n_obs"])})
        return s

    cov = None
    if not series.empty and series["lo_80"].notna().any():
        c = series[series["lo_80"].notna()]
        cov = float(((c["actual"] >= c["lo_80"] - 1e-9) & (c["actual"] <= c["hi_80"] + 1e-9)).mean())
    overall = add("overall", None, series, cov=cov)
    sb = series.assign(hbucket=horizon_bucket(series["horizon_step"]))
    for bucket, sub in sb.groupby("hbucket"):
        add("horizon", bucket, sub, horizon_days=BUCKET_DAYS[bucket])
    add("total", "total", rows[(rows["level"] == "total") & ~rows["is_excluded"]])  # los días de fuente ausente o cierre no son ceros
    if out_rows:
        conn.execute(text(
            """
            INSERT INTO model_evaluations (tenant_id, forecast_run_id, model_family, evaluation_type, level, level_key,
                horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80, n_obs, n_windows, cutoffs,
                extra, created_at)
            VALUES (:tenant_id, :run_id, :family, 'realized', :level, :level_key, :horizon_days, false, true, :wape,
                :mae, :rmse, :bias, :coverage_80, :n_obs, :n_windows, CAST(:cutoffs AS jsonb), CAST(:extra AS jsonb), now())
            """
        ), out_rows)
    return {"wape": none_if_nan(overall["wape"]) if overall is not None else None,
            "bias": none_if_nan(overall["bias"]) if overall is not None else None,
            "n_obs": int(overall["n_obs"]) if overall is not None else 0, "coverage_80": cov}
