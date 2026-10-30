"""Customer Core · dataset de cortes, etiquetas y variables (E2-08, E2-09, E2-05 parte customer).

Todo con datos FICTICIOS construidos a mano o con el generador sintético de verdad conocida.
"""

from __future__ import annotations

from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest

from predictily_engine.customer.data import CustomerData
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import BASE_FEATURES, HORIZON_FEATURES, FeatureBuilder
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.platform.config import resolve_config

BASE = date(2025, 1, 1)


def cfg(**over):
    c, _, _ = resolve_config("customer", {}, None, tenant_overrides=over or None)
    return c


def make_data(purchases, returns=(), availability=None, coverage=None, last_offset=None, promotions=None, products=(1, 2)) -> CustomerData:
    rows = [{"customer_id": c, "product_id": p, "branch_id": b, "ds": pd.Timestamp(BASE + timedelta(days=d)), "currency": "USD", "qty": float(q),
             "net_amount": float(q) * 10.0, "gross_amount": float(q) * 10.0, "discount_amount": 0.0} for c, p, d, q, b in
            [(x + (1,) if len(x) == 4 else x) for x in purchases]]
    ret = pd.DataFrame([{"customer_id": c, "product_id": p, "ds": pd.Timestamp(BASE + timedelta(days=d)), "qty": float(q)} for c, p, d, q in returns],
                       columns=["customer_id", "product_id", "ds", "qty"])
    av = availability if availability is not None else pd.DataFrame(columns=["branch_id", "product_id", "ds", "available"])
    sales_last = BASE + timedelta(days=int(last_offset)) if last_offset is not None else max(r["ds"] for r in rows).date()
    return CustomerData(
        purchases=pd.DataFrame(rows), returns=ret, products=pd.DataFrame({"product_id": list(products), "category_id": 1, "unit": "unidad", "is_active": True}),
        branches=pd.DataFrame({"branch_id": [1, 2], "province": ["Pichincha", "Guayas"], "canton": ["Quito", "Guayaquil"]}),
        availability=av, coverage=coverage if coverage is not None else pd.DataFrame(columns=["entity", "branch_id", "complete_from", "complete_through"]),
        promotions=promotions if promotions is not None else pd.DataFrame(), promotion_revisions=pd.DataFrame(),
        sales_first=BASE, sales_last=sales_last, tenant={"currency": "USD"})


def day(offset: int) -> int:
    return int(to_day(BASE)) + offset


# relación (cliente 1, producto 1): compras regulares y, tras el corte, tres compras en 7 días
REGULAR = [(1, 1, d, 1) for d in (0, 20, 40, 60, 80, 100)]
WINDOW = [(1, 1, 105, 2), (1, 1, 108, 3), (1, 1, 110, 1)]


def test_several_purchases_inside_the_window_are_summed_and_returns_stay_apart():
    """E2-08: la cantidad es la SUMA de todas las compras válidas de la ventana; la devolución no la reduce."""
    data = make_data(REGULAR + WINDOW + [(2, 1, 30, 1), (2, 1, 70, 1)], returns=[(1, 1, 107, 1)], last_offset=140)
    fb = FeatureBuilder(data, cfg())
    c = day(104)
    cf = fb.compute(c)
    lab = fb.labels(cf.base, c, 7, last_complete=day(140))
    i = int(np.nonzero((cf.base["customer_id"] == 1).to_numpy() & (cf.base["product_id"] == 1).to_numpy())[0][0])
    assert lab.loc[i, "status"] == 1
    assert lab.loc[i, "y"] == 1.0
    assert lab.loc[i, "qty"] == 6.0           # 2 + 3 + 1: no solo el siguiente ticket
    assert lab.loc[i, "purchase_days"] == 3
    assert lab.loc[i, "returned_qty"] == 1.0  # devoluciones aparte; no restan de la cantidad comprada
    j = int(np.nonzero((cf.base["customer_id"] == 2).to_numpy())[0][0])
    assert lab.loc[j, "y"] == 0.0 and lab.loc[j, "qty"] == 0.0


def test_immature_labels_are_not_negatives():
    """E2-08: si corte+H supera el último día completo, la etiqueta no existe (ni 0 ni 1)."""
    data = make_data(REGULAR + [(2, 1, 30, 1)], last_offset=108)
    fb = FeatureBuilder(data, cfg())
    c = day(104)
    cf = fb.compute(c)
    lab = fb.labels(cf.base, c, 7, last_complete=day(108))   # 104 + 7 = 111 > 108
    assert (lab["status"] == 0).all()
    assert lab["y"].isna().all() and lab["qty"].isna().all()
    # con H=3 la ventana (104, 107] sí está completa
    lab3 = fb.labels(cf.base, c, 3, last_complete=day(108))
    assert (lab3["status"] == 1).all() and lab3["y"].notna().all()


def test_coverage_not_last_sale_defines_label_maturity():
    cov = pd.DataFrame({"entity": ["sales"], "branch_id": [np.nan], "complete_from": [BASE], "complete_through": [BASE + timedelta(days=100)]})
    data = make_data(REGULAR + WINDOW, coverage=cov, last_offset=140)
    assert data.last_complete_day() == BASE + timedelta(days=100)   # cobertura declarada < última venta
    assert data.coverage_source() == "data_coverage"
    data2 = make_data(REGULAR + WINDOW, last_offset=140)
    assert data2.last_complete_day() == BASE + timedelta(days=140)
    assert data2.coverage_source() == "last_sale_date"


def _availability(unavailable_offsets, product=1, branch=1, days=range(0, 141)):
    rows = [{"branch_id": branch, "product_id": product, "ds": pd.Timestamp(BASE + timedelta(days=d)), "available": d not in unavailable_offsets} for d in days]
    return pd.DataFrame(rows)


def test_censored_windows_are_excluded_never_labeled_as_non_purchase():
    """E2-08: sin stock en la sucursal habitual, la ausencia de compra no es un negativo."""
    stockout = set(range(105, 111))   # 6 de los 7 días de la ventana sin stock
    data = make_data(REGULAR + [(2, 1, 30, 1)], availability=_availability(stockout), last_offset=140)
    fb = FeatureBuilder(data, cfg())
    c = day(104)
    cf = fb.compute(c)
    lab = fb.labels(cf.base, c, 7, last_complete=day(140))
    assert (lab["status"] == 2).all()                    # censuradas
    assert lab["y"].isna().all()                          # no hay etiqueta 0 por falta de stock
    assert not (lab["y"] == 0).any()
    # con stock suficiente la misma ausencia de compra SÍ es un negativo
    data_ok = make_data(REGULAR + [(2, 1, 30, 1)], availability=_availability(set()), last_offset=140)
    fb_ok = FeatureBuilder(data_ok, cfg())
    cf_ok = fb_ok.compute(c)
    lab_ok = fb_ok.labels(cf_ok.base, c, 7, last_complete=day(140))
    assert (lab_ok["status"] == 1).all() and (lab_ok["y"] == 0).all()


def test_unknown_availability_does_not_censor_but_is_counted():
    data = make_data(REGULAR + [(2, 1, 30, 1)], last_offset=140)  # sin snapshots de inventario
    fb = FeatureBuilder(data, cfg())
    bundle = build_dataset(fb, cfg(min_history_days=90, cutoff_step_days=14), (7,), last_complete=day(140), first_sale_day=day(0))
    c = bundle.per_horizon[7].counts
    assert c["censored"] == 0 and c["availability_unknown"] == c["valid"] > 0


def test_candidates_are_relations_with_prior_purchases_only():
    """Productos nunca comprados y compradores sin historial al corte no son candidatos (no se inventa una relación)."""
    data = make_data(REGULAR + [(2, 2, 120, 1), (3, 1, 130, 1)], last_offset=140)
    fb = FeatureBuilder(data, cfg())
    c = day(104)
    rels, _ = fb.candidate_rels(c)
    keys = set(zip(fb.rel_customer[rels], fb.rel_product[rels]))
    assert keys == {(1, 1)}                         # (2,2) y (3,1) aún no habían comprado al corte
    cf = fb.compute(day(125))
    keys2 = set(zip(cf.base["customer_id"], cf.base["product_id"]))
    assert keys2 == {(1, 1), (2, 2)}                # (1,2) nunca comprado: no es candidato; (3,1) todavía no existe
    assert 3 not in set(cf.base["customer_id"])


def test_features_ignore_everything_after_the_cutoff():
    """No-fuga: perturbar compras, devoluciones, inventario y promociones posteriores al corte no cambia las variables del corte."""
    world = generate_world(seed=3, n_customers=50, n_products=6, n_days=300)
    data = world.customer_data()
    fb1 = FeatureBuilder(data, cfg())
    c = int(to_day(world.start)) + 180
    base1 = fb1.compute(c, (7, 30, 90))

    after = pd.Timestamp(world.start) + pd.Timedelta(days=181)
    rng = np.random.default_rng(1)
    extra = pd.DataFrame({
        "customer_id": rng.integers(1, 51, 400), "product_id": rng.integers(1, 7, 400), "branch_id": 1,
        "ds": after + pd.to_timedelta(rng.integers(0, 100, 400), unit="D"), "currency": "USD", "qty": rng.integers(1, 9, 400).astype(float)})
    extra["net_amount"] = extra["qty"] * 3.0
    extra["gross_amount"] = extra["qty"] * 4.0
    extra["discount_amount"] = extra["gross_amount"] - extra["net_amount"]
    # y se modifica el precio/cantidad de compras existentes posteriores al corte
    p2 = pd.concat([data.purchases, extra], ignore_index=True)
    late = p2["ds"] > pd.Timestamp(world.start) + pd.Timedelta(days=180)
    p2.loc[late, "qty"] *= 7
    inv2 = data.availability.copy()
    inv2.loc[inv2["ds"] > pd.Timestamp(world.start) + pd.Timedelta(days=180), "available"] = False
    promo2 = pd.concat([data.promotions, pd.DataFrame({"promotion_id": [999], "discount_pct": [0.5], "starts_on": [after], "ends_on": [after + pd.Timedelta(days=20)],
                                                       "branch_id": [np.nan], "product_id": [1], "category_id": [np.nan],
                                                       "known_at": [after + pd.Timedelta(days=3)], "deleted_at": [pd.NaT]})], ignore_index=True)
    ret2 = pd.concat([data.returns, pd.DataFrame({"customer_id": [1], "product_id": [1], "ds": [after + pd.Timedelta(days=5)], "qty": [50.0]})], ignore_index=True)
    data2 = CustomerData(p2, ret2, data.products, data.branches, inv2, data.coverage, promo2, data.promotion_revisions, data.sales_first,
                         data.sales_last, {}, data.tenant)
    fb2 = FeatureBuilder(data2, cfg())
    base2 = fb2.compute(c, (7, 30, 90))

    def keyed(frame):
        return frame.set_index(["customer_id", "product_id"]).sort_index()

    a, b = keyed(base1.base), keyed(base2.base)
    pd.testing.assert_frame_equal(a[BASE_FEATURES], b.loc[a.index, BASE_FEATURES])
    for h in (7, 30, 90):
        ha = base1.horizon[h].set_index(pd.MultiIndex.from_arrays([base1.base["customer_id"], base1.base["product_id"]])).sort_index()
        hb = base2.horizon[h].set_index(pd.MultiIndex.from_arrays([base2.base["customer_id"], base2.base["product_id"]])).sort_index()
        pd.testing.assert_frame_equal(ha[HORIZON_FEATURES + ["ref_p", "ref_q"]], hb.loc[ha.index, HORIZON_FEATURES + ["ref_p", "ref_q"]])


def test_generic_and_anonymous_buyers_never_enter_the_dataset():
    """E2-09: ni «consumidor final» ni ventas sin comprador se convierten en una persona recurrente."""
    world = generate_world(seed=5, n_customers=60, n_products=6, n_days=240)
    data = world.customer_data()
    generic_ids = set(world.customers.loc[world.customers["is_generic"], "customer_id"])
    assert generic_ids and not (set(data.purchases["customer_id"]) & generic_ids)
    assert data.purchases["customer_id"].notna().all()
    assert data.excluded["anonymous"]["lines"] > 0 and data.excluded["generic"]["lines"] > 0     # se contabilizan, no se usan
    fb = FeatureBuilder(data, cfg())
    bundle = build_dataset(fb, cfg(), (7,))
    assert set(bundle.base["customer_id"]).isdisjoint(generic_ids)


def test_voided_documents_and_credit_notes_are_not_purchases():
    world = generate_world(seed=5, n_customers=60, n_products=6, n_days=240)
    s = world.sales
    assert (s["status"] == "voided").any() and (s["document_type"] == "credit_note").any()
    data = world.customer_data()
    valid = s[(s["status"] == "completed") & (s["document_type"] == "invoice") & s["customer_id"].notna()]
    valid = valid[~valid["customer_id"].isin(world.customers.loc[world.customers["is_generic"], "customer_id"])]
    assert data.purchases["qty"].sum() == pytest.approx(valid["quantity"].sum())
    assert data.returns["qty"].sum() == pytest.approx(s[(s["document_type"] == "credit_note")]["quantity"].sum())
