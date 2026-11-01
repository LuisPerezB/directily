"""§6.D: productos nuevos, descontinuados, aperturas/cierres de sucursal y eventos extraordinarios, con etiqueta."""

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.demand import lifecycle as lc
from predictily_engine.pipeline.core import run_pipeline

# ───────────────────────── reglas con perfiles armados a mano (rápidas) ─────────────────────────

CUTOFF = pd.Timestamp("2026-10-26")


def _profile(rows):
    cols = ["unique_id", "branch_id", "product_id", "category_id", "first_sale_date", "last_sale_date", "history_days",
            "nonzero_days", "demand_class", "is_active"]
    return pd.DataFrame(rows, columns=cols)


def _d(days_ago):
    return (CUTOFF - pd.Timedelta(days=days_ago)).date()


EMPTY_PANEL = pd.DataFrame({"unique_id": pd.Series(dtype=str), "ds": pd.Series(dtype="datetime64[ns]"),
                            "y": pd.Series(dtype=float)})
PRODUCTS = pd.DataFrame({"product_id": [1, 2, 3, 4], "is_active": [True, False, False, True]})
BRANCHES = pd.DataFrame({"branch_id": [1, 2], "is_active": [True, False]})


def test_discontinued_products_get_explicit_zero_only_if_they_sold_in_the_last_year():
    prof = _profile([
        ("b1-p2", 1, 2, 1, _d(400), _d(100), 301, 40, "smooth", False),   # catálogo inactivo, vendió hace 100 d
        ("b1-p3", 1, 3, 1, _d(900), _d(500), 401, 40, "smooth", False),   # catálogo inactivo, hace 500 d
        ("b1-p4", 1, 4, 1, _d(900), _d(300), 601, 40, "smooth", False),   # activo en catálogo, > 180 d sin venta
    ])
    plan = lc.plan_lifecycle(prof, EMPTY_PANEL, CUTOFF, PRODUCTS, BRANCHES)
    assert plan.zero_ids == ["b1-p2"] and plan.labels["b1-p2"] == lc.LABEL_DISCONTINUED
    assert plan.abstain == {"b1-p3": lc.LABEL_DISCONTINUED_ABSTAIN, "b1-p4": lc.LABEL_NO_RECENT}, \
        "sin ventas recientes NO se convierte en cero: se abstiene"
    assert plan.summary["discontinued"] == {"explicit_zero": 1, "abstained": 1, "lookback_days": 365}


def test_only_declared_closures_are_zero_and_inactivity_alone_never_closes_a_branch():
    prof = _profile([
        ("b2-p1", 2, 1, 1, _d(400), _d(3), 400, 300, "smooth", True),     # sucursal desactivada (cierre declarado)
        ("b1-p1", 1, 1, 1, _d(400), _d(250), 400, 300, "smooth", False),  # sucursal activa sin ventas hace 250 d
    ])
    plan = lc.plan_lifecycle(prof, EMPTY_PANEL, CUTOFF, PRODUCTS, BRANCHES)
    assert plan.zero_ids == ["b2-p1"] and plan.labels["b2-p1"] == lc.LABEL_BRANCH_CLOSED
    assert plan.abstain == {"b1-p1": lc.LABEL_NO_RECENT}
    assert plan.summary["branch_closed_declared"]["branches"] == [2]


def test_sparse_history_is_labeled_and_uses_the_series_own_average():
    ds = pd.date_range(CUTOFF - pd.Timedelta(days=99), CUTOFF)
    panel = pd.DataFrame({"unique_id": "b1-p1", "ds": ds, "y": [0.0] * 97 + [3.0, 0.0, 0.0]})
    prof = _profile([("b1-p1", 1, 1, 1, _d(99), _d(2), 100, 1, "insufficient", True)])
    plan = lc.plan_lifecycle(prof, panel, CUTOFF, PRODUCTS, pd.DataFrame({"branch_id": [1]}))
    assert plan.labels["b1-p1"] == lc.LABEL_SPARSE
    assert plan.short_family["b1-p1"] == "window_average" and plan.short_levels["b1-p1"] == pytest.approx(0.03)


def test_exceptional_events_must_be_known_at_the_cutoff_to_be_used():
    ev = pd.DataFrame({
        "id": [1, 2, 3, 4, 5, 6], "tenant_id": [None] * 6, "scope": ["country"] * 6, "scope_ref": ["EC"] * 6,
        "code": ["A", "B", "C", "D", "E", "F"], "name": list("abcdef"),
        "category": ["exceptional", "exceptional", "exceptional", "exceptional", "holiday", "exceptional"],
        "starts_on": pd.Timestamp("2026-11-05"), "ends_on": pd.Timestamp("2026-11-07"),
        "status": ["confirmed", "confirmed", "confirmed", "confirmed", "confirmed", "cancelled"],
        "known_at": [CUTOFF - pd.Timedelta(days=3), pd.NaT, CUTOFF + pd.Timedelta(days=1),
                     CUTOFF + pd.Timedelta(hours=23), CUTOFF - pd.Timedelta(days=3), CUTOFF - pd.Timedelta(days=3)],
        "source": "manual", "revision": 0})
    known, stats = lc.known_events(ev, CUTOFF)
    assert list(known["code"]) == ["A", "D"], "A conocido antes; D se conocía durante el día de corte; B (known_at nulo), C (después) y F (cancelado) no"
    assert stats == {"total": 5, "known_at_unknown_ignored": 1, "known_after_cutoff_ignored": 1, "cancelled": 1}


def test_latest_known_revision_of_an_event_wins_and_a_later_cancellation_is_not_used_before_it_is_known():
    base = {"tenant_id": None, "scope": "country", "scope_ref": "EC", "code": "X", "name": "x", "category": "exceptional",
            "starts_on": pd.Timestamp("2026-11-05"), "source": "manual"}
    ev = pd.DataFrame([
        {**base, "id": 1, "ends_on": pd.Timestamp("2026-11-06"), "status": "confirmed", "revision": 0,
         "known_at": CUTOFF - pd.Timedelta(days=10)},
        {**base, "id": 2, "ends_on": pd.Timestamp("2026-11-09"), "status": "confirmed", "revision": 1,
         "known_at": CUTOFF - pd.Timedelta(days=2)},
        {**base, "id": 3, "ends_on": pd.Timestamp("2026-11-09"), "status": "cancelled", "revision": 2,
         "known_at": CUTOFF + pd.Timedelta(days=3)},
    ])
    known, _ = lc.known_events(ev, CUTOFF)
    assert len(known) == 1 and known["ends_on"].iat[0] == pd.Timestamp("2026-11-09") and known["status"].iat[0] == "confirmed"


def test_event_scope_selects_the_right_series_and_only_inside_the_window():
    fc = pd.DataFrame({
        "unique_id": ["b1-p1"] * 3 + ["b2-p1"] * 3 + ["b2-p2"] * 3, "branch_id": [1] * 3 + [2] * 6,
        "product_id": [1] * 6 + [2] * 3, "category_id": [10] * 6 + [20] * 3,
        "ds": list(pd.date_range("2026-11-01", periods=3)) * 3})
    branches = pd.DataFrame({"branch_id": [1, 2], "region": ["sierra", "costa"]})

    def ev(scope, ref):
        return pd.DataFrame([{"scope": scope, "scope_ref": ref, "code": "E", "name": "e",
                              "starts_on": pd.Timestamp("2026-11-02"), "ends_on": pd.Timestamp("2026-11-02"),
                              "known_at": CUTOFF, "source": "manual"}])

    for scope, ref, expected in (("country", "EC", 3), ("branch", "branch:2", 2), ("product", "product:2", 1),
                                 ("category", "category:10", 2), ("region", "costa", 2), ("branch", "branch:9", 0)):
        mask, used = lc.flag_events(fc, ev(scope, ref), branches)
        assert int(mask.sum()) == expected, (scope, ref)
        assert set(fc.loc[mask, "ds"]) <= {pd.Timestamp("2026-11-02")}
        assert bool(used) == (expected > 0)


# ───────────────────────── pipeline completo con todos los casos a la vez ─────────────────────────

def _event(scope, ref, code, start, end, known_at, status="confirmed", category="exceptional"):
    return {"id": hash(code) % 1000, "tenant_id": None, "scope": scope, "scope_ref": ref, "code": code, "name": code,
            "category": category, "starts_on": start, "ends_on": end, "status": status, "known_at": known_at,
            "source": "manual", "revision": 0}


@pytest.fixture(scope="module")
def special(small_data):
    cutoff = pd.Timestamp(small_data.cutoff)
    sales = small_data.sales.copy()
    # Producto 11: deja de vender hace 200 días → sin ventas recientes (activo en el catálogo).
    sales = sales[~((sales["product_id"] == 11) & (sales["ds"] > cutoff - pd.Timedelta(days=200)))]
    # Sucursal 2 abre hace 70 días: se descarta su venta anterior.
    sales = sales[~((sales["branch_id"] == 2) & (sales["ds"] <= cutoff - pd.Timedelta(days=70)))]
    # Dos productos nuevos en la sucursal 1 (categoría 1): 10 y 21 días de historia.
    new = []
    for pid, days in ((13, 10), (14, 21)):
        ds = pd.date_range(cutoff - pd.Timedelta(days=days - 1), cutoff)
        new.append(pd.DataFrame({"branch_id": 1, "product_id": pid, "ds": ds, "units": 4.0, "net_amount": 40.0,
                                 "gross_amount": 40.0, "discount_amount": 0.0, "cost_amount": 20.0,
                                 "cost_units": 4.0, "lines": 1}))
    sales = pd.concat([sales, *new], ignore_index=True).sort_values(["branch_id", "product_id", "ds"])
    products = small_data.products.copy()
    products.loc[products["product_id"] == 12, "is_active"] = False  # descontinuado en el catálogo
    extra = products[products["product_id"] == 1].iloc[[0, 0]].copy()
    extra["product_id"] = [13, 14]
    extra["sku"] = ["NUEVO-13", "NUEVO-14"]
    products = pd.concat([products, extra], ignore_index=True)
    branches = small_data.branches.copy()
    branches["is_active"] = [True, True, False]  # sucursal 3 desactivada = cierre declarado
    day = pd.Timedelta(days=1)
    events = pd.DataFrame([
        _event("country", "EC", "PARO", cutoff + 10 * day, cutoff + 16 * day, cutoff - 5 * day),
        _event("branch", "branch:1", "VIA", cutoff + 30 * day, cutoff + 35 * day, cutoff - day),
        _event("country", "EC", "SIN_FECHA", cutoff + 40 * day, cutoff + 45 * day, pd.NaT),
        _event("country", "EC", "TARDE", cutoff + 50 * day, cutoff + 55 * day, cutoff + 2 * day),
        _event("country", "EC", "FERIADO", cutoff + 20 * day, cutoff + 21 * day, cutoff - 5 * day, category="holiday"),
        _event("country", "EC", "CANCELADO", cutoff + 60 * day, cutoff + 62 * day, cutoff - 5 * day, status="cancelled"),
    ])
    data = replace(small_data, sales=sales.reset_index(drop=True), products=products, branches=branches,
                   exceptional_events=events)
    return run_pipeline(data, horizon=90, seed=42, n_jobs=1), cutoff


def _series(res, uid):
    b, p = uid[1:].split("-p")
    fv = res.forecast_values
    return fv[(fv["node_key"] == f"series:{b}:{p}") & (fv["granularity"] == "day")].sort_values("horizon_step")


def test_discontinued_and_closed_branch_are_explicit_zero_with_label(special):
    res, _ = special
    for uid, label in (("b1-p12", "discontinued_zero"), ("b2-p12", "discontinued_zero"),
                       ("b3-p1", "branch_closed_declared"), ("b3-p12", "branch_closed_declared")):
        rows = _series(res, uid)
        assert len(rows) == 90, uid
        assert (rows["yhat"] == 0).all() and (rows["lo_95"] == 0).all() and (rows["hi_95"] == 0).all()
        assert (rows["case_label"] == label).all() and (rows["model_family"] == "declared_zero").all()
        assert (rows["quality_status"] == "ok").all()
    assert set(res.lifecycle.zero_ids) >= {"b1-p12", "b2-p12", "b3-p1"}
    # Las series de la sucursal cerrada no producen recomendaciones de abastecimiento.
    recs = res.recommendations
    assert not recs["branch_id"].eq(3).any() and not recs["product_id"].eq(12).any()


def test_without_recent_sales_the_engine_abstains_instead_of_forecasting_zero(special):
    res, _ = special
    uid = "b1-p11"
    assert res.lifecycle.abstain.get(uid) == "no_recent_sales"
    assert _series(res, uid).empty, "no hay filas: ni cero ni pronóstico"
    # La sucursal cerrada tiene precedencia: su serie sin ventas recientes es un cero DECLARADO, no una abstención.
    assert "b3-p11" not in res.lifecycle.abstain and (_series(res, "b3-p11")["case_label"] == "branch_closed_declared").all()
    assert res.summary["special_cases"]["no_recent_sales"]["abstained"] == 1
    assert any("abstención" in w for w in res.warnings)


def test_new_products_use_the_category_median_or_their_own_average_with_a_label(special):
    res, cutoff = special
    prof = res.panel.profile
    mature = prof[(prof["history_days"] >= 28) & prof["is_active"] & (prof["demand_class"] != "insufficient")
                  & (prof["category_id"] == 1) & (prof["branch_id"] == 1)]
    last28 = res.panel.df[(res.panel.df["ds"] > cutoff - pd.Timedelta(days=28)) & res.panel.df["unique_id"].isin(mature["unique_id"])]
    expected = float(last28.groupby("unique_id")["y"].mean().median())
    assert len(mature) >= 3
    thin = _series(res, "b1-p13")  # 10 días de historia: poca venta propia → mediana de la categoría
    assert (thin["case_label"] == "new_product_category_median").all()
    assert (thin["model_family"] == "category_benchmark").all()
    assert thin["yhat"].nunique() == 1 and thin["yhat"].iat[0] == pytest.approx(expected, rel=1e-6)
    own = _series(res, "b1-p14")  # 21 días, venta diaria: se usa su propio promedio
    assert (own["case_label"] == "new_product_own_average").all() and (own["model_family"] == "window_average").all()
    assert own["yhat"].iat[0] == pytest.approx(4.0)
    # Sin historia suficiente el resultado nunca se presenta como completo.
    assert (thin["quality_status"] == "insufficient_data").all()
    counts = res.summary["special_cases"]["new_product"]
    assert counts["count"] == 2 and counts["methods"]["category_median_branch"] == 1 and counts["methods"]["own_average"] == 1


def test_branch_opening_is_labeled_and_horizons_beyond_half_the_history_are_not_presented_as_complete(special):
    res, _ = special
    rows = _series(res, "b2-p1")
    assert (rows["case_label"] == "branch_opening").all()
    assert res.summary["special_cases"]["branch_opening"]["branches"] == [2]
    # 70 días de historia: respalda H ≤ 30 (2×30 = 60) pero no 60 ni 90. (Los pasos 10-16 caen en un evento
    # extraordinario de alcance país: calidad degradada, no ok.)
    event = rows["horizon_step"].between(10, 16)
    assert (rows.loc[(rows["horizon_step"] <= 30) & ~event, "quality_status"] == "ok").all()
    assert (rows.loc[event, "quality_status"] == "degraded").all()
    assert (rows.loc[rows["horizon_step"] > 30, "quality_status"] == "insufficient_data").all()
    # La serie con historia completa de la sucursal 1 sigue respaldada en todos los horizontes.
    full = _series(res, "b1-p1")
    assert (full["quality_status"].isin(["ok", "degraded"])).all() and full["case_label"].isna().sum() > 60


def test_exceptional_events_are_flagged_not_forecast_differently_and_only_when_known(special):
    res, cutoff = special
    ex = res.summary["special_cases"]["exceptional_events"]
    assert ex["flag_enabled"] is True
    assert {e["code"] for e in ex["used"]} == {"PARO", "VIA"}
    assert ex["stats"]["known_at_unknown_ignored"] == 1 and ex["stats"]["known_after_cutoff_ignored"] == 1
    assert any("sin `known_at`" in w for w in res.warnings)
    rows = _series(res, "b1-p1")
    steps_paro = list(range(10, 17))  # fechas corte+10 … corte+16 (paso = días desde el corte)
    steps_via = list(range(30, 36))
    flagged = rows[rows["case_label"] == "exceptional_event"]
    assert set(flagged["horizon_step"]) == set(steps_paro) | set(steps_via)
    assert (flagged["quality_status"] == "degraded").all()
    # La sucursal 2 solo ve el evento de alcance país, no el de la sucursal 1 (paso 30: VIA sí marca a la 1, no a la 2).
    other = _series(res, "b2-p1")
    assert rows.loc[rows["horizon_step"] == 30, "quality_status"].iat[0] == "degraded"
    assert other.loc[other["horizon_step"] == 30, "quality_status"].iat[0] == "ok"
    assert (other.loc[other["horizon_step"].isin(steps_paro), "quality_status"] == "degraded").all()
    # Los eventos desconocidos, posteriores al corte, cancelados o de otra categoría no marcan nada.
    for step in (list(range(40, 46)) + list(range(50, 56)) + [20, 21, 60, 61, 62]):
        assert rows.loc[rows["horizon_step"] == step, "case_label"].isna().all()
    assert res.summary["special_cases"]["exceptional_events"]["used"][0]["affected_series"] > 0


def test_values_of_regular_series_are_unaffected_and_the_hierarchy_stays_coherent(special, pipeline_result):
    res, _ = special
    day = res.forecast_values.query("granularity == 'day'")
    total = day[day["level"] == "total"].groupby("target_date")["yhat"].sum()
    series = day[day["level"] == "series"].groupby("target_date")["yhat"].sum()
    np.testing.assert_allclose(series.loc[total.index], total, rtol=1e-6, atol=1e-3)
    assert (res.summary["series_count"]) == day[(day["level"] == "series") & (day["case_label"] != "discontinued_zero")
                                               & (day["model_family"] != "declared_zero")]["node_key"].nunique()
    assert set(res.forecast_values["case_label"].dropna()) >= {
        "discontinued_zero", "branch_closed_declared", "new_product_category_median", "new_product_own_average",
        "branch_opening", "exceptional_event"}
