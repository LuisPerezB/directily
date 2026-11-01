"""W2-C · métricas de dataset al corte (ADR-V25-05, V2.5 §5): cada métrica sobre fixtures pequeñas, sin base de datos.

Las métricas usan la clasificación de días de W1 (`profiling.days`): `missing_source` y `closed` NUNCA son ceros. Todo con datos FICTICIOS.
"""

from __future__ import annotations

from dataclasses import replace
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.data import CustomerData
from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.quality import metrics as qm
from predictily_engine.supply.inputs import PlanInputs

START = pd.Timestamp("2026-01-01")


def day(offset: int) -> pd.Timestamp:
    return START + pd.Timedelta(days=offset)


def hand_data(n_days: int = 60, branches: tuple[int, ...] = (1,), products: tuple[int, ...] = (1, 2), units: float = 5.0,
              skip: tuple[tuple[int, int], ...] = (), **over) -> TenantData:
    """Ventas diarias de cada sucursal × producto; `skip`: (sucursal, desfase de día) sin ninguna línea."""
    days = pd.date_range(START, periods=n_days, freq="D")
    skipped = {(b, day(k)) for b, k in skip}
    rows = [{"branch_id": b, "product_id": p, "ds": d, "units": units, "net_amount": units * 10.0, "gross_amount": units * 10.0,
             "discount_amount": 0.0, "cost_amount": units * 4.0, "cost_units": units, "lines": 4}
            for b in branches for p in products for d in days if (b, d) not in skipped]
    data = TenantData(
        tenant_id=1, settings={}, cutoff=days[-1].date(),
        branches=pd.DataFrame({"branch_id": list(branches), "region": "sierra", "canton": "quito"}),
        products=pd.DataFrame({"product_id": list(products), "category_id": 1, "is_active": True, "current_price": 10.0, "current_cost": 4.0}),
        categories=pd.DataFrame(), sales=pd.DataFrame(rows), returns=pd.DataFrame(columns=["branch_id", "product_id", "ds", "units"]),
        sales_stats={"lines": 100, "lines_with_cost": 100})
    return replace(data, **over) if over else data


def metrics_of(data: TenantData, signals: dict | None = None) -> dict:
    return qm.sales_metrics(data, build_panel(data, DayRules(infer_gaps=False)), signals)


def gap(branch: float, first: int, last: int, basis: str = "declared") -> pd.DataFrame:
    return pd.DataFrame([{"data_source_id": np.nan, "branch_id": branch, "gap_from": day(first), "gap_to": day(last), "reason": "integration_outage",
                          "basis": basis}])


# ───────────────────────────── cobertura ─────────────────────────────

def test_coverage_reports_first_last_day_and_the_share_of_branch_days_with_source():
    outage = tuple((1, k) for k in range(40, 45))
    m = metrics_of(hand_data(n_days=60, branches=(1, 2), skip=outage, coverage_gaps=gap(1, 40, 44)))["coverage"]
    assert (m["first_day"], m["last_day"], m["calendar_days"]) == ("2026-01-01", "2026-03-01", 60)
    assert m["branch_days"] == 120 and m["missing_source_branch_days"] == 5
    assert m["source_coverage_pct"] == pytest.approx(1 - 5 / 120, abs=1e-6)
    assert m["missing_source_days"] == 0, "la sucursal 2 sí tuvo fuente esos días: no hay un día sin fuente en TODA la empresa"
    assert m["missing_source_by_basis"] == {"declared": 5} and m["declared_coverage"] is False


def test_a_company_wide_gap_counts_days_with_no_source_in_any_branch():
    outage = tuple((b, k) for b in (1, 2) for k in range(10, 14))
    m = metrics_of(hand_data(n_days=60, branches=(1, 2), skip=outage, coverage_gaps=gap(np.nan, 10, 13, "inferred")))["coverage"]
    assert m["missing_source_days"] == 4 and m["missing_source_branch_days"] == 8
    assert m["missing_source_by_basis"] == {"inferred": 8}


# ───────────────────────────── ceros: missing_source y closed NUNCA son ceros ─────────────────────────────

def test_zero_share_counts_only_confirmed_zeros_on_observable_days():
    """4 días sin venta con fuente (ceros reales), 5 de fuente ausente y 3 de cierre: solo los 4 primeros son ceros."""
    real_zero = tuple((1, k) for k in (5, 6, 7, 8))
    outage = tuple((1, k) for k in range(40, 45))
    closure = tuple((1, k) for k in range(20, 23))
    closures = pd.DataFrame([{"branch_id": np.nan, "starts_on": day(20), "ends_on": day(22), "code": "inventario"}])
    m = metrics_of(hand_data(n_days=60, skip=real_zero + outage + closure, coverage_gaps=gap(1, 40, 44), closures=closures))
    z = m["zeros"]
    assert z["series_days"] == 120 and z["by_status"]["zero_confirmed"] == 8      # 4 días × 2 productos
    assert z["by_status"]["missing_source"] == 10 and z["by_status"]["closed"] == 6
    assert z["observable_series_days"] == 120 - 10 - 6 and z["zero_confirmed"] == 8
    assert z["zero_share"] == pytest.approx(8 / 104, abs=1e-6)
    assert z["zero_days_not_counted"] == 16, "los días de fuente ausente y de cierre sin venta no se cuentan como ceros"
    naive = 8 + 10 + 6
    assert z["zero_share"] < naive / 120, "tratarlos como ceros habría inflado la proporción de ceros"


def test_stockout_days_are_not_observable_zeros_either():
    inv = pd.DataFrame({"branch_id": [1, 1], "product_id": [1, 1], "ds": [day(30), day(31)], "on_hand": [0.0, 0.0]})
    m = metrics_of(hand_data(n_days=60, products=(1,), inventory=inv))
    assert m["zeros"]["by_status"]["stockout_censored"] == 3            # días 30 y 31 sin stock + el día siguiente empieza sin stock
    assert m["closures_stockouts"]["stockout_pct"] == pytest.approx(3 / 60, abs=1e-6)


def test_closed_share_over_series_days():
    closures = pd.DataFrame([{"branch_id": np.nan, "starts_on": day(10), "ends_on": day(19), "code": "vacaciones"}])
    skip = tuple((1, k) for k in range(10, 20))
    m = metrics_of(hand_data(n_days=60, products=(1,), skip=skip, closures=closures))
    assert m["closures_stockouts"]["closed_pct"] == pytest.approx(10 / 60, abs=1e-6)
    assert m["closures_stockouts"]["stockout_pct"] == 0.0


# ───────────────────────────── integridad de claves ─────────────────────────────

def test_key_integrity_counts_lines_with_unknown_products_or_branches():
    data = hand_data(n_days=60)
    extra = pd.DataFrame([
        {"branch_id": 1, "product_id": 99, "ds": day(d), "units": 1.0, "net_amount": 10.0, "gross_amount": 10.0, "discount_amount": 0.0,
         "cost_amount": 4.0, "cost_units": 1.0, "lines": 3} for d in range(0, 10)
    ] + [
        {"branch_id": 7, "product_id": 1, "ds": day(d), "units": 1.0, "net_amount": 10.0, "gross_amount": 10.0, "discount_amount": 0.0,
         "cost_amount": 4.0, "cost_units": 1.0, "lines": 2} for d in range(0, 5)
    ])
    data = replace(data, sales=pd.concat([data.sales, extra], ignore_index=True))
    k = metrics_of(data)["key_integrity"]
    total = 60 * 2 * 4 + 10 * 3 + 5 * 2
    assert k["lines_total"] == total and k["lines_unknown_product"] == 30 and k["lines_unknown_branch"] == 10
    assert k["lines_with_key_issue"] == 40 and k["key_issue_pct"] == pytest.approx(40 / total, abs=1e-6)


def test_an_inactive_product_is_valid_history_and_is_counted_apart():
    data = hand_data(n_days=60)
    data = replace(data, products=data.products.assign(is_active=[True, False]))
    k = metrics_of(data)["key_integrity"]
    assert k["lines_with_key_issue"] == 0 and k["lines_inactive_product"] == 60 * 4


def test_key_integrity_tolerates_sales_frames_without_or_with_missing_line_counts():
    data = hand_data(n_days=30)
    no_lines = replace(data, sales=data.sales.drop(columns="lines"))
    assert metrics_of(no_lines)["key_integrity"]["lines_total"] == 30 * 2           # una fila = una línea
    nan_lines = data.sales.copy()
    nan_lines.loc[nan_lines.index[:10], "lines"] = np.nan
    k = metrics_of(replace(data, sales=nan_lines))["key_integrity"]
    assert k["lines_total"] == 10 + (60 - 10) * 4 and k["lines_with_key_issue"] == 0


# ───────────────────────────── historia, variabilidad ─────────────────────────────

def test_series_history_reports_days_and_active_days_and_short_series():
    data = hand_data(n_days=60, products=(1, 2, 3))
    data = replace(data, sales=data.sales[~((data.sales["product_id"] == 3) & (data.sales["ds"] < day(40)))].reset_index(drop=True))
    h = metrics_of(data)["series_history"]
    assert h["series_total"] == 3 and h["series_active"] == 3
    assert h["history_days"]["min"] == 20 and h["history_days"]["max"] == 60
    assert h["active_days"]["min"] == 20 and h["short_series"] == 1 and h["short_series_pct"] == pytest.approx(1 / 3, abs=1e-6)


def test_variability_is_the_cv_of_daily_units_on_observable_days():
    data = hand_data(n_days=60, products=(1, 2))
    sales = data.sales.copy()
    alt = (sales["product_id"] == 1) & (((sales["ds"] - START).dt.days % 2) == 0)
    sales.loc[(sales["product_id"] == 1), "units"] = 4.0
    sales.loc[alt, "units"] = 2.0          # 2,4,2,4…: media 3, desviación 1 ⇒ CV = 1/3; el producto 2 es constante (CV 0)
    v = metrics_of(replace(data, sales=sales))["variability"]
    assert v["series_evaluated"] == 2
    assert v["cv_median"] == pytest.approx(np.median([1 / 3, 0.0]), abs=1e-5)
    assert v["cv_p90"] == pytest.approx(np.percentile([1 / 3, 0.0], 90), abs=1e-5)


# ───────────────────────────── precio, costo, inventario, frescura ─────────────────────────────

def test_price_cost_and_inventory_presence():
    data = hand_data(n_days=60, products=(1, 2))
    sales = data.sales.copy()
    no_price = (sales["product_id"] == 2) & (sales["ds"] < day(18))        # 18 de 60 días del producto 2 sin precio neto
    sales.loc[no_price, "net_amount"] = 0.0
    latest = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [day(49)], "on_hand": [10.0]})   # solo el producto 1, hace 10 días
    m = metrics_of(replace(data, sales=sales, latest_inventory=latest, sales_stats={"lines": 100, "lines_with_cost": 40}))
    assert m["price"]["coverage_share"] == pytest.approx(1 - 18 / 120, abs=1e-6)
    assert m["price"]["catalog_price_share"] == 1.0
    assert m["cost"]["coverage_share"] == 0.4
    assert m["inventory"] == {"coverage_share": 0.5, "series_active": 2, "age_days_median": 10.0, "age_days_max": 10.0}


def test_inventory_absent_means_zero_coverage_not_unknown():
    assert metrics_of(hand_data(n_days=60))["inventory"]["coverage_share"] == 0.0


def test_freshness_is_measured_against_the_run_reference_day():
    data = hand_data(n_days=60)
    assert metrics_of(data)["freshness"]["days_since_last_sale"] == 0, "reconstrucción histórica: la referencia es el propio corte"
    live = replace(data, promotions_known_on=data.cutoff + timedelta(days=10))
    f = metrics_of(live)["freshness"]
    assert f["days_since_last_sale"] == 10 and f["last_sale_day"] == "2026-03-01"
    assert metrics_of(live)["reference_date"] == "2026-03-11"


def test_database_signals_are_embedded_and_missing_ones_are_marked_unavailable():
    signals = {"ingestion": {"available": True, "invalid_pct": 0.1}, "identity": {"available": True, "modeled_share_units": 0.4},
               "id_stability": {"available": True, "product_change_share": 0.0}, "ingestion_lag": {"available": True, "p90_days": 3.0}}
    m = metrics_of(hand_data(), signals)
    assert m["invalid"]["invalid_pct"] == 0.1 and m["identity"]["modeled_share_units"] == 0.4
    assert m["freshness"]["ingestion_lag"]["p90_days"] == 3.0 and m["id_stability"]["product_change_share"] == 0.0
    bare = metrics_of(hand_data())
    assert bare["invalid"] == {"available": False, "reason": "signals_not_collected"}
    assert bare["id_stability"]["available"] is False and bare["freshness"]["ingestion_lag"]["available"] is False


def test_metrics_are_json_serializable_and_deterministic():
    import json

    a, b = metrics_of(hand_data()), metrics_of(hand_data())
    assert json.dumps(a, sort_keys=True) == json.dumps(b, sort_keys=True)
    assert a["schema"] == "dq-metrics-v1" and a["dataset"] == "sales"


# ───────────────────────────── Customer ─────────────────────────────

def customer_data(n_buyers: int = 6, days: int = 120, excluded: dict | None = None, **over) -> CustomerData:
    """Compradores que compran cada `1 + 4 * (i % 3)` días del producto 1 (los primeros, mucho; los últimos, pocas veces)."""
    start = date(2026, 1, 1)
    rows = []
    for c in range(1, n_buyers + 1):
        step = 1 + 4 * (c % 3)
        offset = 0 if c % 2 else 70        # los compradores pares empiezan tarde (nuevos en la ventana reciente de 90 días)
        for d in range(offset, days, step):
            rows.append({"customer_id": c, "product_id": 1 + (c % 2), "branch_id": 1, "ds": start + timedelta(days=d), "currency": "USD",
                         "qty": 2.0, "net_amount": 20.0, "gross_amount": 20.0, "discount_amount": 0.0})
    purchases = pd.DataFrame(rows)
    through = start + timedelta(days=days - 1)
    exc = excluded if excluded is not None else {
        "anonymous": {"lines": 10, "units": 20.0}, "generic": {"lines": 10, "units": 20.0},
        "deleted_customer": {"lines": 0, "units": 0.0}, "modeled": {"lines": 80, "units": 160.0}, "window_days": 180}
    cd = CustomerData(
        purchases=purchases, returns=pd.DataFrame(columns=["customer_id", "product_id", "ds", "qty"]),
        products=pd.DataFrame({"product_id": [1, 2], "category_id": 1, "unit": "u", "is_active": True}),
        branches=pd.DataFrame({"branch_id": [1], "province": "Pichincha", "canton": "Quito"}),
        availability=pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [pd.Timestamp(start)], "available": [True]}),
        coverage=pd.DataFrame(columns=["entity", "branch_id", "complete_from", "complete_through"]),
        promotions=pd.DataFrame(), promotion_revisions=pd.DataFrame(), sales_first=start, sales_last=through, excluded=exc,
        tenant={"currency": "USD", "timezone": "America/Guayaquil"}, through=through)
    return replace(cd, **over) if over else cd


def test_customer_metrics_history_buyers_repeat_and_identity():
    m = qm.customer_metrics(customer_data(), id_lookback_days=30)
    assert m["dataset"] == "customers" and m["coverage"]["identified_history_days"] == 120 and m["coverage"]["company_history_days"] == 120
    ch = m["customer_history"]
    assert ch["buyers"] == 6 and ch["relations"] == 6 and ch["repeat_buyers_pct"] == 1.0
    assert ch["purchase_days"]["n"] == 6 and ch["purchase_days"]["max"] > ch["purchase_days"]["min"]
    ident = m["identity"]
    assert ident["available"] and ident["modeled_share_units"] == pytest.approx(160 / 200) and ident["generic_share_units"] == pytest.approx(0.1)
    assert ident["anonymous_share_units"] == pytest.approx(0.1) and ident["deleted_customer_share_units"] == 0.0
    # compradores que empiezan en el día 70 de 120: «nuevos» con una ventana de 30 días solo si su primera compra es posterior a 90
    assert m["id_stability"]["new_customer_share"] == 0.0
    assert qm.customer_metrics(customer_data(), id_lookback_days=60)["id_stability"]["new_customer_share"] == pytest.approx(3 / 6)
    assert m["availability"]["products_with_inventory_pct"] == pytest.approx(0.5)      # solo el producto 1 tiene inventario


def test_customer_new_customer_share_is_not_evaluable_without_history_longer_than_the_window():
    short = customer_data(days=60)
    assert qm.customer_metrics(short, id_lookback_days=90)["id_stability"]["new_customer_share"] is None


def test_new_customer_share_needs_at_least_two_windows_of_history():
    """Con menos de 2 × ventana buena parte de los compradores es «nuevo» solo porque el negocio es joven: no se evalúa."""
    assert qm.customer_metrics(customer_data(days=119), id_lookback_days=60)["id_stability"]["new_customer_share"] is None
    assert qm.customer_metrics(customer_data(days=120), id_lookback_days=60)["id_stability"]["new_customer_share"] is not None


def test_customer_identity_prefers_the_as_of_signal_and_declares_its_basis():
    """La baja de un cliente es as-of con la señal de la base; sin ella se usa `excluded_sales_stats` (estado actual de la baja) y se dice."""
    fallback = qm.customer_metrics(customer_data())["identity"]
    assert fallback["basis"].startswith("excluded_sales_stats") and "estado actual" in fallback["basis"]
    signal = {"identity": {"available": True, "window_days": 180, "lines": 10, "units": 100.0, "modeled_share_units": 0.7, "anonymous_share_units": 0.1,
                           "generic_share_units": 0.1, "deleted_customer_share_units": 0.1}}
    ident = qm.customer_metrics(customer_data(), signal)["identity"]
    assert ident["basis"] == "sales_line_versions_as_of" and ident["modeled_share_units"] == 0.7 and ident["deleted_customer_share_units"] == 0.1
    failed = qm.customer_metrics(customer_data(), {"identity": {"available": False, "failed": True, "reason": "query_failed:X"}})["identity"]
    assert failed["basis"].startswith("excluded_sales_stats"), "si la señal falla, la cuenta propia del Customer Core sigue disponible"


def test_customer_identity_unavailable_when_the_exclusion_stats_lack_units():
    m = qm.customer_metrics(customer_data(excluded={"anonymous": {"lines": 4}}))
    assert m["identity"] == {"available": False, "reason": "excluded_sales_stats_not_available"}


def test_customer_freshness_uses_today_for_a_live_run():
    from predictily_engine.data.asof import local_today

    live = qm.customer_metrics(customer_data(live=True))
    assert live["freshness"]["days_since_last_sale"] == (local_today("America/Guayaquil") - date(2026, 4, 30)).days
    assert qm.customer_metrics(customer_data())["freshness"]["days_since_last_sale"] == 0


# ───────────────────────────── abastecimiento ─────────────────────────────

def plan_inputs(**over) -> PlanInputs:
    keys = [(1, 1), (1, 2), (1, 3), (1, 4)]
    fc = pd.DataFrame([{"branch_id": b, "product_id": p, "ds": date(2026, 3, 2), "horizon_step": 1, "yhat": 1.0} for b, p in keys])
    inv = pd.DataFrame({"branch_id": [1, 1, 1], "product_id": [1, 2, 3], "ds": [pd.Timestamp("2026-03-01"), pd.Timestamp("2026-02-01"),
                                                                                 pd.Timestamp("2026-01-20")],
                        "on_hand": [5.0, 5.0, 5.0], "reserved": 0.0, "in_transit": 0.0})
    lt = pd.DataFrame({"supplier_id": [1, 1], "product_id": [1, 2], "branch_id": [np.nan, 1.0], "lead_time_days": [3, 5],
                       "lead_time_std_days": [np.nan, np.nan]})
    offers = pd.DataFrame({"offer_id": [1], "supplier_id": [1], "product_id": [3], "unit_cost": [2.0], "lead_time_days": [4], "is_active": [True]})
    products = pd.DataFrame({"product_id": [1, 2, 3, 4], "unit": "u", "current_price": 3.0, "current_cost": [2.0, 2.0, np.nan, np.nan]})
    base = dict(as_of=date(2026, 3, 1), forecast=fc, inventory=inv, lead_times=lt, offers=offers, products=products,
                cost_by_product={4: {"unit_cost": 1.5}})
    return PlanInputs(**{**base, **over})


def test_supply_metrics_inventory_age_lead_time_and_cost_coverage():
    m = qm.supply_metrics(plan_inputs())
    assert m["dataset"] == "supply" and m["forecast"] == {"series": 4, "products": 4}
    inv = m["inventory"]
    assert inv["coverage_share"] == 0.75 and inv["series_with_snapshot"] == 3
    assert inv["age_days_median"] == 28.0 and inv["age_days_max"] == 40.0       # 0, 28 y 40 días
    lt = m["lead_times"]
    assert lt["series_explicit"] == 3 and lt["coverage_share"] == 0.75           # productos 1 y 2 (supplier_lead_times) y 3 (oferta con plazo)
    assert m["costs"]["coverage_share"] == 1.0                                   # 1 y 2 en catálogo, 3 en oferta, 4 en último costo de compra


def test_supply_without_inventory_or_plazos_has_zero_coverage():
    m = qm.supply_metrics(plan_inputs(inventory=pd.DataFrame(columns=["branch_id", "product_id", "ds", "on_hand"]),
                                      lead_times=pd.DataFrame(columns=["supplier_id", "product_id", "branch_id", "lead_time_days"]),
                                      offers=pd.DataFrame()))
    assert m["inventory"]["coverage_share"] == 0.0 and m["lead_times"]["coverage_share"] == 0.0 and m["lead_times"]["series_default"] == 4


def test_supply_metrics_do_not_invent_numbers_without_a_forecast():
    m = qm.supply_metrics(plan_inputs(forecast=pd.DataFrame(columns=["branch_id", "product_id", "ds", "yhat"])))
    assert m["forecast"]["series"] == 0 and m["inventory"]["coverage_share"] is None and m["lead_times"]["coverage_share"] is None
