"""Customer Core S05 · fixtures FUNCIONALES (V2.5 §7 «Caso de prueba funcional (NO pronóstico real)»; T04).

El caso de las cubetas de huevo (3 años; 5–7 cubetas cada 9–15 días; un episodio de ~2 meses con 3–5 cada 6–10 días tras una subida de precio;
vuelta al patrón; corte con la última compra 13 días antes) y un cliente con cambios temporales de precio. Los datos son FICTICIOS con intervalos,
cantidades y precios conocidos por construcción; las pruebas afirman PROPIEDADES (la ventana contiene el intervalo típico y la compra posterior,
la cantidad condicionada vuelve al patrón tras el episodio, no hay mirada al futuro), nunca números mágicos. No validan ninguna empresa.
"""

from __future__ import annotations

from datetime import timedelta

import numpy as np
import pytest

from customer_s05_fixtures import START, data_until, egg_buckets, population, price_changer, train_with_s05
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.explain import describe_factors
from predictily_engine.customer.features import BASE_FEATURES, FeatureBuilder
from predictily_engine.customer.predict import predict_all
from predictily_engine.customer.window import WindowParams, window_from_base
from predictily_engine.platform.config import resolve_config

CFG, _, _ = resolve_config("customer", {}, None)
HORIZONS = (7, 30)


@pytest.fixture(scope="module")
def world():
    return population()


@pytest.fixture(scope="module")
def egg():
    return egg_buckets()


@pytest.fixture(scope="module")
def price():
    return price_changer()


def pipeline(world, cases, case, cutoff_day):
    """Entrena con datos HASTA el corte y predice el corte (el mismo camino que el servicio); devuelve la fila del caso por horizonte."""
    data = data_until(world, cases, cutoff_day)
    last = START + timedelta(days=cutoff_day)
    assert data.purchases["ds"].max() <= np.datetime64(last), "el pipeline solo conoce datos hasta el corte"
    fb = FeatureBuilder(data, CFG, max_horizon=max(HORIZONS), last_day=int(to_day(last)))
    bundle = build_dataset(fb, CFG, HORIZONS, last_complete=int(to_day(last)), first_sale_day=int(to_day(data.sales_first)))
    results, _, window_eval = train_with_s05(bundle, CFG, HORIZONS)
    arts = {h: {"prob": r.artifacts["prob"], "qty": r.artifacts["qty"]} for h, r in results.items()}
    fb_pred = FeatureBuilder(data, CFG, max_horizon=max(HORIZONS), extend_to=int(to_day(last)))
    out = predict_all(fb_pred, arts, int(to_day(last)), CFG)
    pred = out.predictions
    rows = pred[(pred["customer_id"] == case.customer_id) & (pred["product_id"] == case.product_id)].set_index("horizon_days")
    cf = fb_pred.compute(int(to_day(last)), HORIZONS)
    base_row = cf.base[(cf.base["customer_id"] == case.customer_id) & (cf.base["product_id"] == case.product_id)].iloc[0]
    ref_p = {h: float(cf.horizon[h].loc[base_row.name, "ref_p"]) for h in HORIZONS}
    return {"rows": rows, "results": results, "window_eval": window_eval, "base": base_row, "ref_p": ref_p, "data": data, "bundle": bundle}


@pytest.fixture(scope="module")
def egg_text(world, egg, price):
    return pipeline(world, [egg, price], egg, egg.cutoffs["text_case"])


@pytest.fixture(scope="module")
def egg_restored(world, egg, price):
    return pipeline(world, [egg, price], egg, egg.cutoffs["restored"])


@pytest.fixture(scope="module")
def egg_episode(world, egg, price):
    return pipeline(world, [egg, price], egg, egg.cutoffs["episode"])


# ───────────────────────────── cubetas de huevo ─────────────────────────────

def test_egg_fixture_matches_the_functional_case_of_the_spec(egg):
    """La construcción respeta §7: intervalos y cantidades de cada régimen, episodio de ~2 meses y corte con la última compra 13 días antes."""
    days = egg.purchase_days()
    assert days[-1] - days[0] > 3 * 330, "≈ 3 años de historia"
    normal = [(b - a, q) for (a, q, _), (b, _, _) in zip(egg.sales, egg.sales[1:]) if not (egg.episode[0] <= a < egg.episode[1])]
    episode = [(b - a, q) for (a, q, _), (b, _, _) in zip(egg.sales, egg.sales[1:]) if egg.episode[0] <= a < egg.episode[1]]
    assert all(9 <= g <= 15 and 5 <= q <= 7 for g, q in normal if g <= 15) and np.mean([g for g, _ in normal]) == pytest.approx(12, abs=1.5)
    assert all(6 <= g <= 10 and 3 <= q <= 5 for g, q in episode[:-1]) and 7 <= len(episode) <= 12
    assert 55 <= egg.episode[1] - egg.episode[0] <= 70
    c = egg.cutoffs["text_case"]
    last = max(d for d in days if d <= c)
    assert c - last == 13 and 1 <= egg.later_purchase_days(c)[0] - c <= 2, "la última compra fue 13 días antes y la siguiente cae 1–2 días después"


def test_text_case_window_contains_the_typical_interval_and_the_later_purchase(egg, egg_text):
    """La ventana (cuantiles desde el corte) contiene el tiempo restante típico y la compra REAL posterior, que solo entra en la aserción."""
    w = egg_text["rows"].loc[7]
    assert egg_text["window_eval"]["status"] == "validated" and egg_text["window_eval"]["groups"]["gaps:8_plus"]["status"] == "validated", \
        "las cifras solo se publican porque la validación (global y del grupo de la relación) las respalda"
    assert w["window_status"] in ("estimated", "overdue")
    p10, p50, p90 = w["window_p10_days"], w["window_p50_days"], w["window_p90_days"]
    assert 1 <= p10 <= p50 <= p90, "cuantiles ordenados y contados desde el corte (≥ 1 día)"
    typical_remaining = (14 - 13, 15 - 13)          # intervalos típicos 9–15; con 13 días ya transcurridos solo quedan los de 14–15
    assert p10 <= typical_remaining[0] and p90 >= typical_remaining[1]
    actual = egg.later_purchase_days(egg.cutoffs["text_case"])[0] - egg.cutoffs["text_case"]
    assert p10 <= actual <= p90, "la compra posterior (histórico separado) cae dentro de la ventana"
    assert p90 - p10 > 0, "es un rango, nunca un día exacto"
    # la ventana es la misma para todos los horizontes (distribución del tiempo hasta la siguiente compra, desde el corte)
    assert egg_text["rows"].loc[30, ["window_p10_days", "window_p50_days", "window_p90_days"]].tolist() == [p10, p50, p90]


def test_text_case_probability_is_calibrated_or_explicitly_not_estimable_and_better_ordered_than_the_reference(egg, egg_text):
    r = egg_text["results"][7]
    status = r.prob["served"]["calibration_status"]
    assert status in ("calibrated", "degraded", "uncalibrated"), "el estado de calibración siempre se comunica"
    p7 = float(egg_text["rows"].loc[7, "purchase_probability"])
    assert 0.0 < p7 < 1.0
    # la referencia y el servido reconocen que la recompra está vencida (13 > mediana de 12 días) y la siguiente cae a 1–2 días
    assert egg_text["ref_p"][7] >= 0.5 and p7 >= 0.5
    # contra el histórico posterior separado: ocurrió una compra dentro de 7 días; el Brier del servido en esa fila no supera al de la referencia por más de 0,1
    assert egg.later_purchase_days(egg.cutoffs["text_case"])[0] - egg.cutoffs["text_case"] <= 7
    assert (1 - p7) ** 2 <= (1 - egg_text["ref_p"][7]) ** 2 + 0.1
    # y en el holdout poblacional el servido nunca es peor que la referencia (regla de promoción)
    assert r.prob["served"]["holdout"]["brier"] <= r.prob["reference"]["holdout"]["brier"] + 1e-12


def test_probability_depends_on_recency_the_due_purchase_beats_the_just_bought(egg, egg_text, egg_restored):
    """Con intervalos de 9–15 días, a 13 días de la última compra la recompra a 7 días es mucho más probable que a 3 días de ella."""
    p_due = float(egg_text["rows"].loc[7, "purchase_probability"])
    p_just_bought = float(egg_restored["rows"].loc[7, "purchase_probability"])
    assert p_due > p_just_bought


def test_conditional_quantity_returns_to_the_normal_pattern_after_the_episode(egg, egg_text, egg_restored, egg_episode):
    """Cantidad por pedido: en el episodio (3–5) es menor que con el patrón restablecido (5–7), y vuelve a su rango."""
    q_ep = float(egg_episode["rows"].loc[7, "quantity_per_order"])
    q_post = float(egg_restored["rows"].loc[7, "quantity_per_order"])
    q_text = float(egg_text["rows"].loc[7, "quantity_per_order"])
    assert 2.5 <= q_ep <= 5.5, "dentro del episodio la cantidad por pedido es la del episodio (3–5)"
    assert 4.5 <= q_post <= 7.5 and 4.5 <= q_text <= 7.5, "restablecido el patrón, vuelve a 5–7"
    assert q_post > q_ep and q_text > q_ep
    lo, hi = egg_restored["rows"].loc[7, "quantity_per_order_lo"], egg_restored["rows"].loc[7, "quantity_per_order_hi"]
    assert lo < q_post < hi, "la cantidad por pedido trae su intervalo"
    # la referencia histórica (últimas 5 compras) cuenta lo mismo
    assert egg_episode["base"]["qty_mean5"] < 5.0 <= egg_restored["base"]["qty_mean5"] + 0.5


def test_window_adapts_to_the_shorter_intervals_of_the_episode(egg_episode, egg_restored):
    """Mismo tiempo desde la última compra (3 días): con intervalos de 6–10 días la cola superior de la ventana es menor que con 9–15."""
    assert egg_episode["base"]["rel_recency_days"] == egg_restored["base"]["rel_recency_days"] == 3
    assert egg_episode["rows"].loc[7, "window_p90_days"] <= egg_restored["rows"].loc[7, "window_p90_days"]
    assert egg_episode["rows"].loc[7, "window_status"] in ("estimated", "overdue")


def test_number_of_orders_is_a_count_not_the_probability_and_is_coherent(egg_text, egg_restored):
    """E(nº pedidos) ≥ P(≥ 1 pedido) (cota lógica), es mucho menor que 2 en 7 días con intervalos ≥ 9 días y no decrece con el horizonte."""
    for case in (egg_text, egg_restored):
        rows = case["rows"]
        for h in (7, 30):
            assert rows.loc[h, "expected_orders"] >= rows.loc[h, "purchase_probability"] - 1e-9
        assert rows.loc[7, "expected_orders"] <= 1.5
        assert rows.loc[30, "expected_orders"] >= rows.loc[7, "expected_orders"]
        assert rows.loc[30, "expected_orders"] > 1.0, "en 30 días caben varios pedidos: no es una probabilidad"
        assert rows.loc[30, "expected_units"] == pytest.approx(rows.loc[30, "expected_orders"] * rows.loc[30, "quantity_per_order"], rel=1e-9)
        assert rows.loc[7, "expected_units"] == pytest.approx(rows.loc[7, "expected_orders"] * rows.loc[7, "quantity_per_order"], rel=1e-9)


def test_text_case_factors_describe_the_due_purchase_and_the_price_association(egg_text):
    factors = {f["factor"]: f for f in describe_factors(egg_text["base"])}
    assert factors["recency_vs_interval"]["direction"] == "up" and factors["recency_vs_interval"]["value"] > 1.0
    assert "causal" in factors["price_change_last"]["reading"], "la relación con el precio es asociativa y se dice"
    assert egg_text["base"]["rel_recency_days"] == 13 and 10 <= egg_text["base"]["gap_median"] <= 14


def test_posterior_history_is_separated_features_and_window_ignore_it(world, egg, price):
    """Mirada al futuro: con TODO el histórico cargado, las variables y la ventana del corte salen idénticas a las de los datos hasta el corte."""
    c = egg.cutoffs["text_case"]
    truncated = data_until(world, [egg, price], c)
    full = data_until(world, [egg, price], c + 400)          # incluye las compras posteriores del caso (y de los demás)
    fb_t, fb_f = FeatureBuilder(truncated, CFG, max_horizon=30), FeatureBuilder(full, CFG, max_horizon=30)
    day = int(to_day(START + timedelta(days=c)))
    bt, bf = fb_t.compute(day, (7,)).base, fb_f.compute(day, (7,)).base
    assert len(fb_f.data.purchases) > len(fb_t.data.purchases)
    def pick(b):
        return b[(b["customer_id"] == egg.customer_id) & (b["product_id"] == egg.product_id)].iloc[0]
    rt, rf = pick(bt), pick(bf)
    for name in BASE_FEATURES:
        if name.startswith("prod_") or name.startswith("cust_n_products"):
            continue    # agregados de otros compradores: dependen del universo de relaciones del mundo, no del futuro del caso
        a, b = rt[name], rf[name]
        assert (np.isnan(a) and np.isnan(b)) or a == pytest.approx(b), f"{name} cambió al cargar el futuro"
    wt = window_from_base(fb_t, day, bt, WindowParams.from_cfg(CFG))
    wf = window_from_base(fb_f, day, bf, WindowParams.from_cfg(CFG))
    it = np.nonzero(((bt["customer_id"] == egg.customer_id) & (bt["product_id"] == egg.product_id)).to_numpy())[0][0]
    jf = np.nonzero(((bf["customer_id"] == egg.customer_id) & (bf["product_id"] == egg.product_id)).to_numpy())[0][0]
    assert wt.loc[it, ["window_p10_days", "window_p50_days", "window_p90_days"]].tolist() == wf.loc[jf, ["window_p10_days", "window_p50_days", "window_p90_days"]].tolist()


# ───────────────────────────── cliente con cambios temporales de precio ─────────────────────────────

def test_price_changer_features_are_asof_associative_and_quantity_dips_then_recovers(world, egg, price):
    ep0, ep1 = price.episode
    days = price.purchase_days()
    first_ep = next(d for d in days if d >= ep0)
    back = next(d for d in days if d >= ep1)
    def at(c):
        data = data_until(world, [egg, price], c)
        fb = FeatureBuilder(data, CFG, max_horizon=30)
        b = fb.compute(int(to_day(START + timedelta(days=c))), (7,)).base
        return b[(b["customer_id"] == price.customer_id) & (b["product_id"] == price.product_id)].iloc[0]
    before = at(price.cutoffs["before_episode"])
    rise = at(first_ep + 2)
    during = at(price.cutoffs["episode"])
    drop = at(back + 2)
    restored = at(price.cutoffs["restored"])
    assert before["price_change_last"] == pytest.approx(0.0, abs=1e-9) and before["qty_mean5"] >= 9.0
    assert rise["price_change_last"] == pytest.approx(0.6, abs=0.05), "el último precio subió 60 % (asociación)"
    assert during["qty_mean5"] < before["qty_mean5"] - 1.5, "la cantidad por pedido cae durante el episodio de precio alto"
    assert drop["price_change_last"] < -0.3
    assert restored["qty_mean5"] > during["qty_mean5"] + 1.5 and restored["qty_mean5"] >= 8.5, "y vuelve al patrón al normalizarse el precio"
    # sensibilidad a precio asociativa: la lectura nunca afirma causalidad
    assert all("causal" in f["reading"] for f in describe_factors(rise) if f["factor"] == "price_change_last")


def test_price_changer_pipeline_serves_a_per_order_quantity_that_follows_the_pattern(world, egg, price):
    out = pipeline(world, [egg, price], price, price.cutoffs["restored"])
    rows = out["rows"]
    q = float(rows.loc[30, "quantity_per_order"])
    assert 7.0 <= q <= 13.0, "restablecido el precio, la cantidad por pedido vuelve al entorno de 10 unidades"
    assert rows.loc[30, "window_status"] in ("estimated", "overdue")
    assert rows.loc[30, "window_p10_days"] >= 1 and rows.loc[30, "window_p90_days"] <= CFG["window_max_days"]
    # el siguiente pedido real cae (14–16 días de la anterior) dentro de la ventana
    c = price.cutoffs["restored"]
    nxt = price.later_purchase_days(c)[0] - c
    assert rows.loc[30, "window_p10_days"] <= nxt <= rows.loc[30, "window_p90_days"]


def test_cold_relation_has_no_window_and_says_so(world, egg, price):
    """«No estimable»: una relación con una sola compra no tiene intervalos propios; la ventana queda sin cifras con su estado."""
    from customer_s05_fixtures import CaseSeries
    one = CaseSeries(9003, 9003, [(1000, 4.0, 2.0)], (0, 0))
    data = data_until(world, [one], 1020)
    fb = FeatureBuilder(data, CFG, max_horizon=30)
    day = int(to_day(START + timedelta(days=1020)))
    b = fb.compute(day, (7,)).base
    w = window_from_base(fb, day, b, WindowParams.from_cfg(CFG))
    i = int(np.nonzero(((b["customer_id"] == 9003)).to_numpy())[0][0])
    assert w.loc[i, "window_status"] == "insufficient_data"
    assert np.isnan(w.loc[i, ["window_p10_days", "window_p50_days", "window_p90_days"]].astype(float)).all()
