"""Métricas de dataset AL CORTE (V2.5 §5, ADR-V25-05).

Funciones puras sobre los datos que cada módulo ya cargó (`TenantData` + `Panel`, `CustomerData`, `PlanInputs`), con lo que se conocía
al corte (lectura as-of de W1: `data.asof`). Lo que no está en memoria (informes de ingestión, alias, desfase de ingestión) llega en
`signals` (`quality.signals.collect_signals`, también as-of). Reglas de oro:

- Los días `missing_source` y `closed` NUNCA cuentan como ceros: la proporción de ceros es `zero_confirmed / (sale + zero_confirmed)`
  sobre días observables (`profiling.days`). Un quiebre (`stockout_censored`) tampoco es un día observable.
- Nada posterior al corte entra: los datos de entrada ya son as-of; las señales de la base filtran por la misma regla de visibilidad.
- Una métrica que no se puede calcular se OMITE (`available: false` con su motivo); el gate no la da por buena.

Todas las secciones son diccionarios JSON (números y textos), listos para `data_quality_reports.metrics`.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.quality.rules import METRICS_SCHEMA

SHORT_SERIES_DAYS = 28   # igual a profiling.series.MIN_HISTORY_DAYS (se duplica para no importar el perfilado aquí)
OBSERVABLE = ("sale", "zero_confirmed")


# ───────────────────────────── utilidades ─────────────────────────────

def _r(x: Any, nd: int = 6) -> float | None:
    if x is None:
        return None
    try:
        f = float(x)
    except (TypeError, ValueError):
        return None
    return None if np.isnan(f) or np.isinf(f) else round(f, nd)


def _iso(x: Any) -> str | None:
    if x is None or x is pd.NaT or (isinstance(x, float) and np.isnan(x)):
        return None
    return pd.Timestamp(x).date().isoformat()


def _ratio(num: Any, den: Any) -> float | None:
    den = float(den)
    return _r(float(num) / den) if den > 0 else None


def distribution(values: Any) -> dict[str, Any]:
    """Resumen de una distribución: n, mínimo, p10, mediana, p90 y máximo (None si no hay valores)."""
    arr = np.asarray(values, dtype=float)
    arr = arr[~np.isnan(arr)]
    if arr.size == 0:
        return {"n": 0, "min": None, "p10": None, "median": None, "p90": None, "max": None}
    return {"n": int(arr.size), "min": _r(arr.min()), "p10": _r(np.percentile(arr, 10)), "median": _r(np.percentile(arr, 50)),
            "p90": _r(np.percentile(arr, 90)), "max": _r(arr.max())}


def reference_date(data: Any, cutoff: date) -> date:
    """Día respecto del que se mide la frescura: HOY en una ejecución vigente (`promotions_known_on`) o el propio corte en una
    reconstrucción histórica (donde nada posterior al corte existe)."""
    known = getattr(data, "promotions_known_on", None)
    return pd.Timestamp(known).date() if known is not None else cutoff


def get_path(metrics: dict[str, Any], path: str) -> Any:
    """Valor en una ruta con puntos (None si falta algún tramo)."""
    cur: Any = metrics
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return None
        cur = cur[part]
    return cur


def _unavailable(reason: str) -> dict[str, Any]:
    return {"available": False, "reason": reason}


def _from_signal(signals: dict[str, Any] | None, name: str) -> dict[str, Any]:
    if not signals or name not in signals:
        return _unavailable("signals_not_collected")
    return dict(signals[name])


# ───────────────────────────── ventas (demanda y abastecimiento) ─────────────────────────────

def sales_metrics(data: Any, panel: Any, signals: dict[str, Any] | None = None) -> dict[str, Any]:
    """Métricas del dataset de ventas de una empresa al corte del panel (`data`: TenantData; `panel`: profiling.series.Panel)."""
    cutoff = pd.Timestamp(panel.cutoff).normalize()
    ref = reference_date(data, cutoff.date())
    df = panel.df
    prof = panel.profile
    out: dict[str, Any] = {"schema": METRICS_SCHEMA, "dataset": "sales", "as_of": _iso(cutoff), "reference_date": _iso(ref)}
    if len(df) and "day_status" in df:
        status = df["day_status"].astype(str)
    elif len(df):  # panel sin clasificación de días: solo se distingue venta de cero
        status = pd.Series(np.where(df["units"].to_numpy(float) > 0, "sale", "zero_confirmed"), index=df.index)
    else:
        status = pd.Series(dtype=str)

    # ── cobertura: primer/último día y % de sucursal-días con cobertura de fuente ──
    if df.empty:
        out["coverage"] = {"first_day": None, "last_day": _iso(cutoff), "calendar_days": 0, "branch_days": 0,
                           "missing_source_branch_days": 0, "source_coverage_pct": None, "missing_source_days": 0,
                           "missing_source_by_basis": {}, "declared_coverage": bool((getattr(data, "coverage", None) or {}).get("sales", {}).get("declared"))}
    else:
        first = pd.Timestamp(df["ds"].min())
        missing_rows = (status == "missing_source").to_numpy()
        flags = pd.DataFrame({"b": df["branch_id"].to_numpy(), "d": df["ds"].to_numpy(), "m": missing_rows}).groupby(["b", "d"], sort=False)["m"].max()
        branch_days = int(len(flags))
        missing_bd = int(flags.sum())
        all_missing_days = int(flags.groupby(level="d").all().sum())
        by_basis: dict[str, int] = {}
        if missing_rows.any() and "day_basis" in df:
            sub = df.loc[missing_rows, ["branch_id", "ds", "day_basis"]].drop_duplicates(["branch_id", "ds"])
            by_basis = {str(k): int(v) for k, v in sub["day_basis"].astype(str).value_counts().items()}
        out["coverage"] = {
            "first_day": _iso(first), "last_day": _iso(cutoff), "calendar_days": int((cutoff - first).days) + 1,
            "branch_days": branch_days, "missing_source_branch_days": missing_bd,
            "source_coverage_pct": _r(1.0 - missing_bd / branch_days) if branch_days else None,
            "missing_source_days": all_missing_days, "missing_source_by_basis": by_basis,
            "declared_coverage": bool((getattr(data, "coverage", None) or {}).get("sales", {}).get("declared")),
        }

    out["invalid"] = _from_signal(signals, "ingestion")
    out["key_integrity"] = _key_integrity(data, signals)

    # ── historia por serie ──
    active = prof[prof["is_active"].astype(bool)] if len(prof) and "is_active" in prof else prof
    short = int((active["history_days"] < SHORT_SERIES_DAYS).sum()) if len(active) else 0
    out["series_history"] = {
        "series_total": int(len(prof)), "series_active": int(len(active)),
        "history_days": distribution(active["history_days"]) if len(active) else distribution([]),
        "active_days": distribution(active["nonzero_days"]) if len(active) else distribution([]),
        "short_series": short, "short_series_pct": _ratio(short, len(active)),
    }

    # ── ceros: SOLO zero_confirmed sobre días observables; missing_source/closed/quiebres no son ceros ──
    by_status = {s: int((status == s).sum()) for s in ("sale", "zero_confirmed", "missing_source", "stockout_censored", "closed")} if len(status) else {}
    observable = int(sum(by_status.get(s, 0) for s in OBSERVABLE))
    not_counted = 0
    if len(df):
        not_counted = int(((df["units"].to_numpy(float) == 0) & ~status.isin(OBSERVABLE).to_numpy()).sum())
    out["zeros"] = {"series_days": int(len(df)), "by_status": by_status, "observable_series_days": observable,
                    "zero_confirmed": by_status.get("zero_confirmed", 0), "zero_share": _ratio(by_status.get("zero_confirmed", 0), observable),
                    "zero_days_not_counted": not_counted}

    # ── variabilidad: CV diario por serie sobre días observables ──
    cv_values = np.array([])
    if len(df):
        obs = df.loc[status.isin(OBSERVABLE).to_numpy(), ["unique_id", "units"]]
        if len(obs):
            g = obs.groupby("unique_id", sort=False)["units"]
            stats = pd.DataFrame({"mean": g.mean(), "std": g.std(ddof=0), "n": g.size()})
            ok = (stats["n"] >= 7) & (stats["mean"] > 0)
            cv_values = (stats.loc[ok, "std"] / stats.loc[ok, "mean"]).to_numpy()
    out["variability"] = {"series_evaluated": int(len(cv_values)), "cv_median": _r(np.median(cv_values)) if len(cv_values) else None,
                          "cv_p90": _r(np.percentile(cv_values, 90)) if len(cv_values) else None}

    # ── estabilidad de IDs y compradores genéricos (señales de la base) ──
    out["id_stability"] = _from_signal(signals, "id_stability")
    out["identity"] = _from_signal(signals, "identity")

    # ── presencia de precio, costo e inventario ──
    out["price"] = _price(data)
    out["cost"] = _cost(data)
    out["inventory"] = _inventory(data, prof, cutoff)

    # ── cierres y quiebres ──
    n = len(df)
    out["closures_stockouts"] = {
        "series_days": int(n), "closed_pct": _ratio(by_status.get("closed", 0), n), "stockout_pct": _ratio(by_status.get("stockout_censored", 0), n),
        "missing_source_pct": _ratio(by_status.get("missing_source", 0), n)}

    # ── frescura ──
    sales = getattr(data, "sales", None)
    last_sale = pd.Timestamp(sales["ds"].max()) if sales is not None and len(sales) else None
    out["freshness"] = {"last_sale_day": _iso(last_sale),
                        "days_since_last_sale": int((pd.Timestamp(ref) - last_sale).days) if last_sale is not None else None,
                        "ingestion_lag": _from_signal(signals, "ingestion_lag")}
    return out


def _key_integrity(data: Any, signals: dict[str, Any] | None) -> dict[str, Any]:
    sales = getattr(data, "sales", None)
    if sales is None or len(sales) == 0:
        return {"lines_total": 0, "lines_unknown_product": 0, "lines_unknown_branch": 0, "lines_with_key_issue": 0, "key_issue_pct": None}
    lines = pd.to_numeric(sales["lines"], errors="coerce").fillna(1.0).to_numpy(float) if "lines" in sales else np.ones(len(sales))
    products, branches = getattr(data, "products", None), getattr(data, "branches", None)
    known_p = set(products["product_id"].astype(int)) if products is not None and len(products) else set()
    known_b = set(branches["branch_id"].astype(int)) if branches is not None and len(branches) else set()
    bad_p = ~sales["product_id"].astype(int).isin(known_p).to_numpy()
    bad_b = ~sales["branch_id"].astype(int).isin(known_b).to_numpy()
    total = float(lines.sum())
    inactive = 0.0
    if products is not None and len(products) and "is_active" in products:
        inactive_ids = set(products.loc[~products["is_active"].astype(bool), "product_id"].astype(int))
        inactive = float(lines[sales["product_id"].astype(int).isin(inactive_ids).to_numpy()].sum())
    issue = float(lines[bad_p | bad_b].sum())
    out: dict[str, Any] = {
        "lines_total": int(total), "lines_unknown_product": int(lines[bad_p].sum()), "lines_unknown_branch": int(lines[bad_b].sum()),
        "lines_with_key_issue": int(issue), "key_issue_pct": _ratio(issue, total), "lines_inactive_product": int(inactive),
        "note": "Producto o sucursal inexistente o dado de baja en el catálogo VIGENTE (el catálogo no está versionado: en una reconstrucción "
                "histórica una baja posterior al corte también se cuenta); un producto inactivo (descontinuado) es historia válida y se cuenta aparte."}
    ident = (signals or {}).get("identity") or {}
    if ident.get("available"):
        out["customers"] = {"lines_identified": ident.get("lines_identified"), "lines_deleted_customer": ident.get("lines_deleted_customer"),
                            "deleted_customer_share_lines": ident.get("deleted_customer_share_lines")}
    else:
        out["customers"] = _unavailable("signals_not_collected")
    return out


def _price(data: Any) -> dict[str, Any]:
    sales = getattr(data, "sales", None)
    products = getattr(data, "products", None)
    out: dict[str, Any] = {"coverage_share": None, "catalog_price_share": None,
                           "note": "catalog_price_share usa el precio de catálogo vigente (no versionado); coverage_share sale de las ventas as-of."}
    if sales is not None and len(sales) and {"units", "net_amount"} <= set(sales.columns):
        pos = sales[sales["units"].to_numpy(float) > 0]
        units = float(pos["units"].sum())
        priced = float(pos.loc[pd.to_numeric(pos["net_amount"], errors="coerce").to_numpy(float) > 0, "units"].sum())
        out["coverage_share"] = _ratio(priced, units)
    if products is not None and len(products) and "current_price" in products:
        act = products[products["is_active"].astype(bool)] if "is_active" in products else products
        price = pd.to_numeric(act["current_price"], errors="coerce")
        out["catalog_price_share"] = _ratio(int((price > 0).sum()), len(act))
    return out


def _cost(data: Any) -> dict[str, Any]:
    stats = getattr(data, "sales_stats", None) or {}
    lines = int(stats.get("lines") or 0)
    return {"coverage_share": _ratio(int(stats.get("lines_with_cost") or 0), lines) if lines > 0 else None, "lines": lines}


def _inventory(data: Any, prof: pd.DataFrame, cutoff: pd.Timestamp) -> dict[str, Any]:
    latest = getattr(data, "latest_inventory", None)
    if latest is None or latest.empty:
        inv = getattr(data, "inventory", None)
        if inv is not None and not inv.empty:
            latest = inv.sort_values("ds").groupby(["branch_id", "product_id"], as_index=False).tail(1)
    if not len(prof):
        return {"coverage_share": None, "age_days_median": None, "age_days_max": None, "series_active": 0}
    active = prof[prof["is_active"].astype(bool)] if "is_active" in prof else prof
    keys = active[["branch_id", "product_id"]].astype(int).drop_duplicates()
    if latest is None or latest.empty or not len(keys):
        return {"coverage_share": 0.0 if len(keys) else None, "age_days_median": None, "age_days_max": None, "series_active": int(len(keys))}
    snap = latest[["branch_id", "product_id", "ds"]].astype({"branch_id": int, "product_id": int}).drop_duplicates(["branch_id", "product_id"])
    merged = keys.merge(snap, on=["branch_id", "product_id"], how="left")
    has = merged["ds"].notna()
    age = (cutoff - pd.to_datetime(merged.loc[has, "ds"])).dt.days.to_numpy(float)
    return {"coverage_share": _ratio(int(has.sum()), len(merged)), "series_active": int(len(merged)),
            "age_days_median": _r(np.median(age)) if len(age) else None, "age_days_max": _r(age.max()) if len(age) else None}


# ───────────────────────────── Customer ─────────────────────────────

def customer_metrics(data: Any, signals: dict[str, Any] | None = None, reference: date | None = None,
                     id_lookback_days: int = 90) -> dict[str, Any]:
    """Métricas del dataset de compras de compradores identificados (`data`: customer.data.CustomerData)."""
    through = pd.Timestamp(data.through).normalize() if data.through is not None else None
    ref = reference if reference is not None else (_live_today(data) if getattr(data, "live", False) else (through.date() if through is not None else None))
    out: dict[str, Any] = {"schema": METRICS_SCHEMA, "dataset": "customers", "as_of": _iso(through), "reference_date": _iso(ref)}
    purchases = data.purchases if data.purchases is not None else pd.DataFrame()
    n = len(purchases)

    first_purchase = pd.Timestamp(purchases["ds"].min()) if n else None
    last_complete = data.last_complete_day() if hasattr(data, "last_complete_day") else None
    end = pd.Timestamp(last_complete) if last_complete is not None else through
    out["coverage"] = {
        "first_sale_day": _iso(data.sales_first), "last_sale_day": _iso(data.sales_last), "last_complete_day": _iso(last_complete),
        "through": _iso(through), "coverage_source": data.coverage_source() if hasattr(data, "coverage_source") else None,
        "company_history_days": int((end - pd.Timestamp(data.sales_first)).days) + 1 if (data.sales_first is not None and end is not None) else None,
        "first_purchase_day": _iso(first_purchase),
        "identified_history_days": int((end - first_purchase).days) + 1 if (first_purchase is not None and end is not None) else 0,
    }
    out["invalid"] = _from_signal(signals, "ingestion")

    # ── compradores identificados vs genéricos/anónimos (ventana del Customer Core) ──
    out["identity"] = _customer_identity(data, signals)

    # ── historia por cliente (compras = días distintos de compra) ──
    if n:
        per_customer = purchases.groupby("customer_id")["ds"].nunique()
        per_relation = purchases.groupby(["customer_id", "product_id"])["ds"].nunique()
        out["customer_history"] = {
            "buyers": int(len(per_customer)), "relations": int(len(per_relation)), "purchase_days": distribution(per_customer.to_numpy()),
            "repeat_buyers_pct": _ratio(int((per_customer >= 2).sum()), len(per_customer)),
            "repeat_relations_pct": _ratio(int((per_relation >= 2).sum()), len(per_relation))}
        first_by_customer = purchases.groupby("customer_id")["ds"].min()
    else:
        out["customer_history"] = {"buyers": 0, "relations": 0, "purchase_days": distribution([]), "repeat_buyers_pct": None, "repeat_relations_pct": None}
        first_by_customer = pd.Series(dtype="datetime64[ns]")

    stability = _from_signal(signals, "id_stability")
    # compradores nuevos en la ventana reciente: se calculan de las compras ya cargadas (no hace falta la base)
    if n and end is not None:
        window_start = end - pd.Timedelta(days=int(id_lookback_days))
        # Con menos de DOS ventanas de historia buena parte de los compradores es «nuevo» solo porque el negocio es joven: no se evalúa.
        history_ok = first_purchase is not None and (end - first_purchase).days + 1 >= 2 * int(id_lookback_days)
        stability["new_customer_share"] = _ratio(int((first_by_customer > window_start).sum()), len(first_by_customer)) if history_ok else None
    out["id_stability"] = stability

    # ── inventario conocido para la censura por disponibilidad ──
    avail = data.availability
    if n and avail is not None and len(avail):
        bought = set(purchases["product_id"].astype(int))
        with_inv = set(avail["product_id"].astype(int))
        out["availability"] = {"products_bought": len(bought), "products_with_inventory_pct": _ratio(len(bought & with_inv), len(bought))}
    else:
        out["availability"] = {"products_bought": int(purchases["product_id"].nunique()) if n else 0, "products_with_inventory_pct": 0.0 if n else None}

    last_sale = pd.Timestamp(data.sales_last) if data.sales_last is not None else None
    out["freshness"] = {"last_sale_day": _iso(last_sale),
                        "days_since_last_sale": int((pd.Timestamp(ref) - last_sale).days) if (last_sale is not None and ref is not None) else None}
    return out


def _live_today(data: Any) -> date | None:
    from predictily_engine.data.asof import local_today

    tz = (getattr(data, "tenant", None) or {}).get("timezone")
    return local_today(tz)


def _customer_identity(data: Any, signals: dict[str, Any] | None = None) -> dict[str, Any]:
    """Proporción de unidades de compradores identificados («modelables»), genéricos, anónimos y dados de baja.

    Con las señales de la base se usa la lectura AS-OF de este paquete (`signals.identity`: la baja de un cliente cuenta solo si ocurrió
    antes de la medianoche local de C+1). Sin ellas se recurre a `data.excluded` (`CustomerRepository.excluded_sales_stats`), que aplica la
    misma visibilidad de ventas pero toma `customers.deleted_at` e `is_generic` en su estado ACTUAL (el Customer Core no los versiona): en
    una reconstrucción histórica una baja posterior al corte también se cuenta. `basis` lo declara."""
    ident = (signals or {}).get("identity") or {}
    if ident.get("available"):
        keep = ("window_days", "lines", "units", "modeled_share_units", "anonymous_share_units", "generic_share_units",
                "deleted_customer_share_units")
        return {"available": True, "basis": "sales_line_versions_as_of", **{k: ident.get(k) for k in keep}}
    exc = data.excluded or {}
    if all(isinstance(exc.get(k), dict) and "units" in exc[k] for k in ("anonymous", "generic", "deleted_customer", "modeled")):
        units = {k: float(exc[k]["units"]) for k in ("anonymous", "generic", "deleted_customer", "modeled")}
        lines = {k: int(exc[k].get("lines", 0)) for k in units}
        total = sum(units.values())
        return {"available": True, "basis": "excluded_sales_stats (baja de cliente en estado actual)", "window_days": exc.get("window_days"),
                "units": units, "lines": lines,
                "modeled_share_units": _ratio(units["modeled"], total), "anonymous_share_units": _ratio(units["anonymous"], total),
                "generic_share_units": _ratio(units["generic"], total), "deleted_customer_share_units": _ratio(units["deleted_customer"], total)}
    return _unavailable("excluded_sales_stats_not_available")


# ───────────────────────────── abastecimiento ─────────────────────────────

def supply_metrics(inputs: Any) -> dict[str, Any]:
    """Métricas de los insumos de un plan de reposición (`inputs`: supply.inputs.PlanInputs), al corte `inputs.as_of`."""
    as_of = pd.Timestamp(inputs.as_of).normalize()
    out: dict[str, Any] = {"schema": METRICS_SCHEMA, "dataset": "supply", "as_of": _iso(as_of), "reference_date": _iso(as_of),
                           "retroactive": bool(inputs.retroactive)}
    fc = inputs.forecast
    keys = fc[["branch_id", "product_id"]].astype(int).drop_duplicates() if fc is not None and len(fc) else pd.DataFrame(columns=["branch_id", "product_id"])
    n = len(keys)
    out["forecast"] = {"series": int(n), "products": int(keys["product_id"].nunique()) if n else 0}

    # inventario: cobertura y antigüedad del último snapshot de cada serie pronosticada
    inv = inputs.inventory
    if n and inv is not None and len(inv):
        cols = [c for c in ("branch_id", "product_id", "ds") if c in inv.columns]
        snap = inv[cols].astype({"branch_id": int, "product_id": int}).drop_duplicates(["branch_id", "product_id"])
        merged = keys.merge(snap, on=["branch_id", "product_id"], how="left", indicator=True)
        has = merged["_merge"] == "both"
        info: dict[str, Any] = {"coverage_share": _ratio(int(has.sum()), n), "series_with_snapshot": int(has.sum())}
        if "ds" in merged:
            age = (as_of - pd.to_datetime(merged.loc[has, "ds"])).dt.days.to_numpy(float)
            info.update(age_days_median=_r(np.median(age)) if len(age) else None, age_days_max=_r(age.max()) if len(age) else None)
        else:
            info.update(age_days_median=None, age_days_max=None, age_note="el snapshot no trae fecha")
        out["inventory"] = info
    else:
        out["inventory"] = {"coverage_share": 0.0 if n else None, "series_with_snapshot": 0, "age_days_median": None, "age_days_max": None}

    out["lead_times"] = _supply_lead_times(inputs, keys)
    out["costs"] = _supply_costs(inputs, keys)
    return out


def _supply_lead_times(inputs: Any, keys: pd.DataFrame) -> dict[str, Any]:
    n = len(keys)
    if not n:
        return {"coverage_share": None, "series_explicit": 0}
    explicit = np.zeros(n, dtype=bool)
    lt = inputs.lead_times
    if lt is not None and len(lt) and "product_id" in lt:
        rows = lt[lt["product_id"].notna()]
        product_any = set(rows.loc[rows["branch_id"].isna(), "product_id"].astype(int)) if "branch_id" in rows else set(rows["product_id"].astype(int))
        pair = set(zip(rows.loc[rows["branch_id"].notna(), "branch_id"].astype(int), rows.loc[rows["branch_id"].notna(), "product_id"].astype(int))) \
            if "branch_id" in rows else set()
        explicit |= np.array([(b, p) in pair or p in product_any for b, p in zip(keys["branch_id"], keys["product_id"])])
    offers = inputs.offers
    if offers is not None and len(offers) and {"product_id", "lead_time_days"} <= set(offers.columns):
        with_lead = offers[offers["lead_time_days"].notna()]
        if "is_active" in with_lead:
            with_lead = with_lead[with_lead["is_active"].astype(bool)]
        offer_products = set(with_lead["product_id"].astype(int))
        explicit |= keys["product_id"].astype(int).isin(offer_products).to_numpy()
    return {"coverage_share": _ratio(int(explicit.sum()), n), "series_explicit": int(explicit.sum()), "series_default": int(n - explicit.sum()),
            "note": "Plazo explícito = fila de supplier_lead_times del producto (o producto×sucursal) u oferta con plazo; lo demás usa el plazo por defecto de la empresa."}


def _supply_costs(inputs: Any, keys: pd.DataFrame) -> dict[str, Any]:
    if not len(keys):
        return {"coverage_share": None}
    products = set(keys["product_id"].astype(int))
    known: set[int] = set()
    prods = inputs.products
    if prods is not None and len(prods) and "current_cost" in prods:
        cost = pd.to_numeric(prods["current_cost"], errors="coerce")
        known |= set(prods.loc[cost > 0, "product_id"].astype(int))
    known |= {int(p) for p, v in (inputs.cost_by_product or {}).items() if v}
    offers = inputs.offers
    if offers is not None and len(offers) and {"product_id", "unit_cost"} <= set(offers.columns):
        known |= set(offers.loc[pd.to_numeric(offers["unit_cost"], errors="coerce") > 0, "product_id"].astype(int))
    return {"coverage_share": _ratio(len(products & known), len(products)), "products": len(products), "products_with_cost": len(products & known)}
