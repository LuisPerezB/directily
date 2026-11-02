"""Comparación de pronósticos pasados con ventas reales y detección de deterioro (drift).

- Para cada run completado se toman las fechas objetivo ya observadas (≤ última venta) y se
  calcula la venta real por nodo con las mismas reglas del entrenamiento (sin anuladas ni
  devoluciones). Se guarda en forecast_actuals (idempotente por run, nodo y fecha).
- Métricas realizadas por run (nivel serie, sin días con quiebre de stock, fuente ausente ni cierre; y nivel total sin los días
  de fuente ausente o cierre) en model_evaluations con evaluation_type='realized'.
- Drift: si el WAPE realizado de un tramo de horizonte (h1-7 / h8-14 / h15-30 / h31-60 / h61-90), acumulado en
  los runs de producción evaluados, supera max(1.3 × backtest, backtest + 0.10) con al menos 14
  días observados, se crea una alerta forecast_drift (deduplicada).

- W3-B · shadow mode (`evaluate_shadow_prospective`): las predicciones del challenger que NO se sirvieron (`shadow_forecast_values`) se
  evalúan tras el corte contra lo realizado, con la MISMA clasificación y censura de días que la evaluación servida (quiebre de stock, fuente
  ausente, cierre), y se escriben en model_evaluations con evaluation_type = 'shadow_prospective' (WAPE, sesgo, cobertura 80 %, por horizonte y
  comparación con el servido sobre las mismas series y días).

Semántica de días (ADR-V25-03): un día sin ventas NO es siempre un cero. Los días de una serie que la fuente no cubre (fuera de
`data_coverage`, dentro de un hueco declarado o inferido, o inferidos por la regla de actividad de la sucursal) o en que la sucursal
cerró (`calendar_events.category = 'closure'`) NO son ceros observados: se marcan censurados (`is_censored`), no entran a las métricas
realizadas ni a la detección de deriva, y se cuentan por clase (`exclusions`: `stockout_days`, `missing_source_days`, `closed_days`).
Se clasifican con la MISMA función del entrenamiento (`profiling.days.classify_days`) y con el conocimiento más reciente de las
declaraciones; así una caída de la integración no se lee como un deterioro del pronóstico.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.backtest.metrics import summarize, wape_status
from predictily_engine.backtest.rolling import BUCKET_DAYS, horizon_bucket
from predictily_engine.data.asof import local_today
from predictily_engine.db.repository import TenantRepository
from predictily_engine.db.session import get_engine
from predictily_engine.db.writer import jdump, mark_failed, none_if_nan, upsert_alert
from predictily_engine.demand.coverage import SalesCoverage
from predictily_engine.pipeline.data import load_day_declarations
from predictily_engine.models.registry import BASELINE_FAMILY
from predictily_engine.pipeline.errors import NotFoundError
from predictily_engine.platform.config import CORE_DEFAULTS, effective_config_record
from predictily_engine.profiling.days import BASIS_INFERRED, CLOSED, EXCLUDED_STATUSES, MISSING_SOURCE, DayRules, classify_days
from predictily_engine.reconciliation.hierarchy import ALL_LEVELS, aggregate

MIN_DRIFT_DAYS = 14
log = logging.getLogger(__name__)


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
    # W3-B: evaluación prospectiva del challenger, AISLADA con un punto de guardado: si falla, la evaluación de lo servido (ya escrita arriba) y la
    # detección de deriva no se pierden; queda la limitación `shadow_evaluation_failed`.
    try:
        with conn.begin_nested():
            shadow = evaluate_shadow_prospective(conn, repo, runs, day_ctx, categories, last)
    except Exception as exc:
        log.exception("La evaluación prospectiva del shadow falló")
        shadow = {"evaluated": [], "runs_with_shadow": 0, "exclusions": {}, "error": f"{type(exc).__name__}: {exc}"[:300],
                  "limitations": [{"code": "shadow_evaluation_failed",
                                   "message": f"La evaluación prospectiva del shadow falló ({type(exc).__name__}); la evaluación de lo servido no se ve afectada."}]}
    return {"evaluated_runs": evaluated, "drift_detected": drift_detected, "drift_details": drift_details,
            "realized_wape": latest["wape"] if latest else None, "exclusions": totals, "shadow_prospective": shadow}


SHADOW_EVALUATION_TYPE = "shadow_prospective"
_SHADOW_LIMITS = {"wape": 1e6, "bias": 1e6, "mae": 1e10, "rmse": 1e10}     # capacidad de las columnas escalares de model_evaluations


def _shadow_cap(column: str, value: Any) -> Any:
    value = none_if_nan(value)
    limit = _SHADOW_LIMITS.get(column)
    return None if (limit is not None and value is not None and abs(float(value)) >= limit - 1e-6) else value


def evaluate_shadow_prospective(conn: Connection, repo: TenantRepository, runs: pd.DataFrame, day_ctx: DayContext | None,
                                categories: pd.Series, last: date | None) -> dict[str, Any]:
    """W3-B · evaluación PROSPECTIVA del challenger (shadow mode): compara sus predicciones no servidas con lo realizado después del corte.

    La censura es la de la evaluación servida: las series-día que la fuente no cubre o en que la sucursal cerró (`excluded_series_days`, la
    misma función del entrenamiento) y los quiebres de stock quedan fuera de WAPE, sesgo y cobertura y se cuentan por clase (`exclusions`).
    Escribe `model_evaluations` con `evaluation_type = 'shadow_prospective'` por familia: `overall` y `horizon` (tramo de pasos); `extra` /
    `metrics` incluyen el WAPE del modelo SERVIDO sobre las mismas series y días no censurados (comparación justa). Idempotente por ejecución."""
    tenant_id = repo.tenant_id
    result: dict[str, Any] = {"evaluated": [], "runs_with_shadow": 0, "exclusions": {}}
    if last is None or runs is None or runs.empty:
        return result
    # Mismo umbral de estabilidad del WAPE que las métricas por segmento (W3-A): `demand.wape_min_denominator` de la empresa; si su configuración no se
    # puede resolver, el valor por defecto del core (la evaluación del shadow no debe fallar por eso).
    try:
        tenant_row = repo.tenant()
        min_den = float(effective_config_record("demand", tenant_row.get("settings"), tenant_row.get("sector"))["effective_config"].get("wape_min_denominator") or 0.0)
    except Exception:  # noqa: BLE001
        min_den = float(CORE_DEFAULTS["demand"].get("wape_min_denominator") or 0.0)
    totals: dict[str, int] = {}
    for run in runs.to_dict("records"):
        run_id = int(run["id"])
        cutoff = pd.Timestamp(run["cutoff_date"])
        if pd.Timestamp(last) <= cutoff:
            continue
        date_from = (cutoff + pd.Timedelta(days=1)).date()
        date_to = min(pd.Timestamp(last), cutoff + pd.Timedelta(days=int(run["horizon_days"]))).date()
        shadow = pd.DataFrame(conn.execute(text(
            "SELECT model_family, model_registry_id, node_key, level, branch_id, product_id, target_date AS ds, horizon_step, "
            "yhat::float8 AS yhat, lo_80::float8 AS lo_80, hi_80::float8 AS hi_80 FROM shadow_forecast_values "
            "WHERE tenant_id = :t AND forecast_run_id = :r AND target_date BETWEEN :f AND :e"),
            {"t": tenant_id, "r": run_id, "f": date_from, "e": date_to}).mappings().all())
        if shadow.empty:
            continue
        shadow["ds"] = pd.to_datetime(shadow["ds"])
        shadow["category_id"] = shadow["product_id"].map(categories)
        act = repo.daily_actuals(date_from, date_to)
        act["ds"] = pd.to_datetime(act["ds"])
        excluded = excluded_series_days(shadow, day_ctx, date_from, date_to)      # fuente ausente y cierres: nunca son ceros observados
        nodes = node_actuals(act, _stockout_days(repo, date_from, date_to), categories, excluded)
        served = repo.forecast_values_for_run(run_id, date_from, date_to)
        served = served[served["level"] == "series"][["node_key", "ds", "yhat"]].rename(columns={"yhat": "served_yhat"})
        served["ds"] = pd.to_datetime(served["ds"])
        conn.execute(text("DELETE FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r AND evaluation_type = :k"),
                     {"t": tenant_id, "r": run_id, "k": SHADOW_EVALUATION_TYPE})
        out_rows: list[dict[str, Any]] = []
        run_summary: dict[str, Any] = {"run_id": run_id, "families": {}}
        for family, part in shadow.groupby("model_family"):
            rows = realized_rows(part.drop(columns=["model_family", "model_registry_id"]), nodes)
            series = rows[(rows["level"] == "series") & ~rows["is_censored"]]
            exclusions = exclusion_counts(rows, excluded)
            for key, value in exclusions.items():
                totals[key] = totals.get(key, 0) + value
            same = series.merge(served, on=["node_key", "ds"], how="inner")
            served_wape = None
            if len(same) and same["actual"].sum() > 0:
                served_wape = float((same["actual"] - same["served_yhat"]).abs().sum() / same["actual"].sum())
            registry_id = part["model_registry_id"].dropna()
            base = {"tenant_id": tenant_id, "run_id": run_id, "family": str(family), "rid": int(registry_id.iloc[0]) if len(registry_id) else None,
                    "baseline": str(family) == BASELINE_FAMILY, "cutoffs": jdump([str(run["cutoff_date"])[:10]])}

            def add(level: str, key: str | None, df: pd.DataFrame, horizon_days: int | None = None) -> dict[str, Any] | None:
                if df.empty:
                    return None
                s = summarize(df.assign(_g=1), ["_g"]).iloc[0]
                with_band = df[df["lo_80"].notna() & df["hi_80"].notna()]
                cov = None if with_band.empty else float(((with_band["actual"] >= with_band["lo_80"] - 1e-9)
                                                           & (with_band["actual"] <= with_band["hi_80"] + 1e-9)).mean())
                comparison = {"served_wape_same_series_days": served_wape if level == "overall" else None,
                              "relative_improvement_vs_served": (None if level != "overall" or served_wape in (None, 0) or s["wape"] != s["wape"]
                                                                 else float((served_wape - s["wape"]) / served_wape)),
                              "n_served_compared": int(len(same)) if level == "overall" else None}
                den = float(s["sum_actual"])
                w_status = wape_status(den, min_den)
                metrics = {"shadow": True, "served": False, "scope": "prospectivo (tras el corte)", "exclusions": exclusions,
                           "sample": {"n_obs": int(s["n_obs"]), "n_series": int(df["node_key"].nunique()), "sum_actual": den},
                           # Estabilidad del WAPE y período REALMENTE evaluado (W3-D): mismo denominador y estado que las filas de segmento (W3-A).
                           "denominator": {"sum_actual": den, "unit": "unidades", "min_required": min_den},
                           "wape_status": w_status, "wape_unstable": w_status != "ok",
                           "evaluated_period": {"first_date": df["ds"].min().date().isoformat(), "last_date": df["ds"].max().date().isoformat(),
                                                "n_days": int(df["ds"].nunique())},
                           "comparison": comparison, "coverage_80_n": int(len(with_band)),
                           "censorship": "misma clasificación de días que la evaluación servida (quiebre, fuente ausente, cierre)"}
                out_rows.append({**base, "level": level, "level_key": key, "horizon_days": horizon_days,
                                 "wape": _shadow_cap("wape", s["wape"]), "mae": _shadow_cap("mae", s["mae"]), "rmse": _shadow_cap("rmse", s["rmse"]),
                                 "bias": _shadow_cap("bias", s["bias"]), "coverage_80": cov, "n_obs": int(s["n_obs"]),
                                 "extra": jdump({"shadow": True, "evaluation_scope": "prospective", "exclusions": exclusions}),
                                 "metrics": jdump(metrics)})
                return {"wape": none_if_nan(s["wape"]), "bias": none_if_nan(s["bias"]), "coverage_80": cov, "n_obs": int(s["n_obs"]),
                        "served_wape_same_series_days": served_wape}

            overall = add("overall", None, series)
            for bucket, sub in series.assign(hbucket=horizon_bucket(series["horizon_step"])).groupby("hbucket"):
                add("horizon", bucket, sub, horizon_days=BUCKET_DAYS[bucket])
            run_summary["families"][str(family)] = {"exclusions": exclusions, **(overall or {"wape": None, "n_obs": 0})}
        if out_rows:
            conn.execute(text(
                """
                INSERT INTO model_evaluations (tenant_id, model_registry_id, forecast_run_id, model_family, evaluation_type, level, level_key,
                    horizon_days, is_baseline, is_selected, wape, mae, rmse, bias, coverage_80, n_obs, n_windows, cutoffs, extra, core, metrics,
                    created_at)
                VALUES (:tenant_id, :rid, :run_id, :family, 'shadow_prospective', :level, :level_key, :horizon_days, :baseline, false, :wape,
                    :mae, :rmse, :bias, :coverage_80, :n_obs, 1, CAST(:cutoffs AS jsonb), CAST(:extra AS jsonb), 'demand',
                    CAST(:metrics AS jsonb), now())
                """), out_rows)
        result["evaluated"].append(run_summary)
    result["runs_with_shadow"] = len(result["evaluated"])
    result["exclusions"] = totals
    return result


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
