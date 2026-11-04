"""Simulación cronológica AS-OF de abastecimiento (V2.5 · W5-D; V25-SUP-038/041/042/045/050): en cada revisión se decide con lo conocido ESE día
(costo, precio, plazo, ventas previas) y las métricas de servicio, exceso y capital se verifican con casos a mano. Sin base de datos."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.asof_inputs import asof_costs, asof_series_tables, review_indices, step_fill
from predictily_engine.supply.simulation import Policy, ReferencePolicy, SimInputs, _metrics, simulate_policy


class NoOrder(Policy):
    name = "no_order"

    def decide(self, ctx):
        return np.zeros(len(ctx.inp.keys))


class Recorder(Policy):
    """Registra lo que ve la política en cada revisión (día, costo, plazo, suma de ventas previas a la ventana) y pide una cantidad fija."""

    name = "recorder"

    def __init__(self, qty=0.0):
        self.qty = qty
        self.seen = []

    def decide(self, ctx):
        inp = ctx.inp
        self.seen.append({"day": ctx.day - inp.start, "cost": float(inp.cost_at(ctx.day)[0]), "lead": int(inp.lead_at(ctx.day)[0]),
                          "pre": float(ctx.hist[: inp.start].sum())})
        return np.full(len(inp.keys), self.qty)


def sim(n_days=14, pre_days=60, demand=1.0, init=10.0, cost=10.0, price=15.0, **extra) -> SimInputs:
    D = pre_days + n_days
    dates = pd.date_range("2026-06-01", periods=D, freq="D")
    mat = np.full((D, 1), demand)
    return SimInputs(dates=dates, demand=mat, sold_hist=mat.copy(), start=pre_days, n_days=n_days, init_inv=np.array([init]), keys=[(1, 10)],
                     unit_cost=np.array([cost]), price=np.array([price]), lead=np.array([2]), lead_std=np.array([0.0]), review_days=7, **extra)


# ── métricas con resultado conocido (SUP-041/042/045) ─────────────────────────────────────────────────────

def test_days_of_inventory_turnover_and_capital_match_hand_calculations():
    inp = sim(n_days=10, cost=10.0, price=15.0)
    sold, unmet = np.ones((10, 1)), np.zeros((10, 1))
    inv_path = np.full((10, 1), 3.0)                                  # 3 u. × 10 $ = 30 $ de inventario medio
    on_order = np.zeros(10)
    on_order[2:5] = 7 * 2.0                                           # una orden de 7 u. a 2 $ en camino durante 3 días = 14 $
    m = _metrics(inp, sold, unmet, inv_path, on_order, 0.0, 1, 7.0)
    assert m["avg_inventory_value"] == pytest.approx(30.0)
    assert m["days_of_inventory"] == pytest.approx(3.0)               # 30 $ / (1 u./día · 10 $)
    assert m["inventory_turnover"] == pytest.approx(365.0 / 3.0, abs=0.01)     # = 365 / días de inventario
    assert m["avg_capital_committed"] == pytest.approx(30.0 + 14.0 * 3 / 10)    # inventario medio + órdenes en camino promediadas en la ventana
    assert m["peak_capital_committed"] == pytest.approx(30.0 + 14.0)
    assert m["stockout_days"] == 0 and m["fill_rate"] == 1.0 and m["expired_units"] == 0.0


def test_service_stockout_days_and_lost_margin_use_the_cost_and_price_known_each_day():
    inp = sim(n_days=4, cost=10.0, price=15.0,
              unit_cost_t=np.array([[10.0], [10.0], [20.0], [20.0]]), price_t=np.array([[15.0], [15.0], [18.0], [18.0]]))
    sold, unmet = np.array([[1.0], [0.0], [0.0], [1.0]]), np.array([[0.0], [1.0], [1.0], [0.0]])
    m = _metrics(inp, sold, unmet, np.full((4, 1), 1.0), np.zeros(4), 0.0, 0, 0.0)
    assert m["stockout_days"] == 2 and m["fill_rate"] == pytest.approx(0.5)
    assert m["lost_margin"] == pytest.approx(1 * (15 - 10) + 1 * 0.0)             # día 2: margen 5; día 3: precio 18 < costo 20 ⇒ margen 0 (no negativo)


# ── tablas as-of: nada posterior a la revisión entra en ella ──────────────────────────────────────────────

def _hist(rows):
    return pd.DataFrame(rows, columns=["cost_id", "product_id", "supplier_id", "unit_cost", "currency", "unit", "valid_from", "known_at", "source", "ingested_at"])


def test_asof_costs_use_known_at_and_valid_from_and_never_the_catalogue_cost_of_today():
    h = _hist([(1, 10, None, 5.0, "USD", "u", date(2026, 1, 1), pd.Timestamp("2026-01-02"), "t", None),
               (2, 10, None, 9.0, "USD", "u", date(2026, 7, 1), pd.Timestamp("2026-07-20"), "t", None),
               (3, 10, None, 99.0, "USD", "u", date(2026, 7, 1), None, "t", None)])          # known_at desconocido: en un corte pasado NO existe
    assert asof_costs(h, date(2026, 7, 10))[10]["unit_cost"] == 5.0                            # el 9,0 se conoció el 20/07
    assert asof_costs(h, date(2026, 7, 20))[10]["unit_cost"] == 9.0
    assert asof_costs(h, date(2025, 12, 31)) == {}
    assert asof_costs(None, date(2026, 7, 10)) == {}


def test_series_tables_decisions_of_a_review_do_not_depend_on_costs_or_lead_times_known_later():
    dates = pd.date_range("2026-04-01", periods=120, freq="D")
    start, n_days = 90, 28                       # ventana del 30/06 al 27/07; revisiones cada 7 días
    h_base = _hist([(1, 10, None, 5.0, "USD", "u", date(2026, 1, 1), pd.Timestamp("2026-01-02"), "t", None)])
    # un costo nuevo que SE CONOCE el día 15 de la ventana (14/07): las revisiones anteriores no lo ven
    known = pd.Timestamp(dates[start + 14]) + pd.Timedelta(hours=9)
    h_late = _hist([(1, 10, None, 5.0, "USD", "u", date(2026, 1, 1), pd.Timestamp("2026-01-02"), "t", None),
                    (2, 10, None, 9.0, "USD", "u", dates[start + 10].date(), known, "t", None)])
    suppliers = pd.DataFrame({"supplier_id": [1], "default_lead_time_days": [4]})

    def lead_times_at(cut):       # el plazo de 20 días rige desde el día 15 (14/07): antes, el de 5
        rows = [{"supplier_id": 1, "product_id": 10, "branch_id": np.nan, "lead_time_days": 5, "lead_time_std_days": 0.0, "valid_from": None}]
        if cut >= dates[start + 15].date():
            rows.append({"supplier_id": 1, "product_id": 10, "branch_id": np.nan, "lead_time_days": 20, "lead_time_std_days": 0.0, "valid_from": pd.Timestamp(dates[start + 15])})
        return pd.DataFrame(rows)

    def tables(hist):
        return asof_series_tables(keys=[(1, 10)], dates=dates, start=start, n_days=n_days, review_days=7, cost_history=hist,
                                  price_at=lambda cut: {10: 7.0}, lead_times_at=lead_times_at, suppliers=suppliers, default_lead=7, fallback_cost={})

    base, late = tables(h_base), tables(h_late)
    # revisiones en k = 0, 7, 14, 21: el costo/plazo nuevos solo aparecen en la revisión 21 (el 14/07 a las 09:00 se conoce el fin del día 14, pero el
    # costo tiene known_at = 14/07 09:00 ⇒ visible desde el corte del 14/07 inclusive)
    assert base["cost_t"][:, 0].tolist() == [5.0] * n_days
    changed_from = int(np.flatnonzero(late["cost_t"][:, 0] != 5.0)[0])
    assert changed_from == 14 and late["cost_t"][changed_from:, 0].tolist() == [9.0] * (n_days - changed_from)
    assert late["cost_t"][:14, 0].tolist() == base["cost_t"][:14, 0].tolist()                       # lo anterior a la revisión 14 es idéntico
    assert base["lead_t"][:15, 0].tolist() == [5] * 15 and base["lead_t"][15:, 0].tolist() != [5] * 13
    assert base["price_t"][:, 0].tolist() == [7.0] * n_days
    # sin costo al inicio de la ventana ⇒ serie excluida (no se usa el costo de hoy)
    none = tables(_hist([(2, 10, None, 9.0, "USD", "u", dates[start + 10].date(), known, "t", None)]))
    assert none["keep"].tolist() == [False] and none["excluded"][0]["reason"] == "sin costo conocido al inicio de la ventana"


def test_step_fill_keeps_the_last_known_value_until_the_next_review():
    out = step_fill({0: np.array([1.0]), 7: np.array([2.0]), 14: np.array([3.0])}, 20)
    assert out[:, 0].tolist() == [1.0] * 7 + [2.0] * 7 + [3.0] * 6
    assert review_indices(14, 7) == [0, 7] and review_indices(8, 7) == [0]      # la última jornada no revisa (no habría tiempo de recibir)


# ── la política decide con lo conocido ese día ────────────────────────────────────────────────────────────

def test_each_review_sees_the_cost_lead_time_and_prior_sales_known_that_day():
    n = 21
    cost_t = np.array([[10.0]] * 7 + [[12.0]] * (n - 7))
    lead_t = np.array([[2]] * 7 + [[9]] * (n - 7))
    D = 60 + n
    final = np.ones((D, 1))
    final[10] += 500.0           # un documento TARDÍO (de un día previo a la ventana) que la fuente entregó recién el día 8 de la ventana: la verdad final lo incluye
    without = np.ones((60, 1))   # lo que se conocía ANTES de que llegara

    def pre_history(d):          # d = índice del día de revisión; el documento se conoce desde el día 8 de la ventana
        return without if d - 60 < 8 else None

    def run(history):
        rec = Recorder()
        inp = sim(n_days=n, unit_cost_t=cost_t, lead_t=lead_t, lead_std_t=np.zeros((n, 1)), price_t=np.full((n, 1), 15.0), pre_history=history)
        inp.sold_hist = final.copy()
        inp.demand = final.copy()
        simulate_policy(inp, rec)
        return {s["day"]: s for s in rec.seen}

    seen = run(pre_history)
    assert seen[0]["cost"] == 10.0 and seen[0]["lead"] == 2 and seen[7]["cost"] == 12.0 and seen[7]["lead"] == 9
    assert seen[0]["pre"] == pytest.approx(60.0)             # la revisión del día 0 NO ve el documento tardío
    assert seen[7]["pre"] == pytest.approx(60.0)             # la del día 7 tampoco: todavía no había llegado
    assert seen[14]["pre"] == pytest.approx(60.0 + 500.0)    # la del día 14 sí
    leaky = run(None)                                        # la conducta anterior (una sola lectura final) filtraba el futuro a las revisiones tempranas
    assert leaky[0]["pre"] == pytest.approx(60.0 + 500.0)


def test_orders_are_valued_and_delayed_with_the_cost_and_lead_time_of_the_day_they_are_placed():
    cost_t = np.array([[10.0]] * 7 + [[30.0]] * 7)
    lead_t = np.array([[1]] * 7 + [[5]] * 7)
    inp = sim(n_days=14, init=0.0, demand=0.0, unit_cost_t=cost_t, lead_t=lead_t, lead_std_t=np.zeros((14, 1)), price_t=np.full((14, 1), 40.0))
    m = simulate_policy(inp, Recorder(qty=4.0))
    assert m["purchase_outlay"] == pytest.approx(4 * 10.0 + 4 * 30.0)          # una orden por revisión (días 0 y 7), cada una a su costo
    static = simulate_policy(sim(n_days=14, init=0.0, demand=0.0), Recorder(qty=4.0))
    assert static["purchase_outlay"] == pytest.approx(80.0)                    # sin as-of rigen los vectores estáticos, como antes


def test_static_inputs_give_the_same_result_with_or_without_the_as_of_machinery():
    a = simulate_policy(sim(n_days=21, demand=2.0, init=10.0), ReferencePolicy())
    b = simulate_policy(sim(n_days=21, demand=2.0, init=10.0, unit_cost_t=np.full((21, 1), 10.0), price_t=np.full((21, 1), 15.0),
                            lead_t=np.full((21, 1), 2), lead_std_t=np.zeros((21, 1))), ReferencePolicy())
    for k in ("fill_rate", "unmet_units", "purchase_outlay", "avg_inventory_value", "holding_cost", "lost_margin", "avg_capital_committed", "ordered_units"):
        assert a[k] == pytest.approx(b[k]), k


def test_new_purchases_expire_at_the_end_of_their_last_sellable_day_and_not_one_day_later():
    # Orden en el día 0 con plazo 2 ⇒ llega el día 2; vida útil 4 ⇒ último día vendible: el día 5 (índice de la ventana). Con 6 días de ventana,
    # sin demanda, las 10 u. se pierden AL CIERRE del día 5 (dentro de la ventana). Un vencimiento un día más tarde caería fuera de ella.
    inp = sim(n_days=6, init=0.0, demand=0.0, shelf_life=np.array([4.0]), init_lots=[[]])
    m = simulate_policy(inp, Recorder(qty=10.0))
    assert m["expired_units"] == pytest.approx(10.0) and m["expired_value"] == pytest.approx(100.0)
    longer = sim(n_days=5, init=0.0, demand=0.0, shelf_life=np.array([4.0]), init_lots=[[]])      # la ventana termina el día 4: aún no vence
    assert simulate_policy(longer, Recorder(qty=10.0))["expired_units"] == 0.0


def test_the_forecast_horizon_covers_the_longest_lead_time_of_the_window_not_the_one_at_its_start():
    class Horizon(Policy):
        name = "horizon"

        def __init__(self):
            self.h = []

        def decide(self, ctx):
            self.h.append(ctx.horizon)
            return np.zeros(len(ctx.inp.keys))

    n = 21
    lead_t = np.array([[5]] * 7 + [[20]] * (n - 7))                      # el plazo sube de 5 a 20 días a mitad de la ventana
    inp = sim(n_days=n, unit_cost_t=np.full((n, 1), 10.0), price_t=np.full((n, 1), 15.0), lead_t=lead_t, lead_std_t=np.zeros((n, 1)))
    pol = Horizon()
    simulate_policy(inp, pol)
    assert all(h >= 20 + 2 * inp.review_days + 7 for h in pol.h), pol.h      # el primer pronóstico ya cubre el plazo de 20 días que regirá más adelante


# ── vencimiento en la simulación (FEFO) ───────────────────────────────────────────────────────────────────

def test_expired_units_of_the_initial_lots_are_lost_when_they_are_not_sold_in_time():
    # 10 u. con último día vendible el tercer día de la ventana (índice start+2); demanda 1/día: venden 3 y pierden 7 al cierre.
    inp = sim(n_days=10, init=10.0, demand=1.0, shelf_life=np.array([0.0]), init_lots=[[(60 + 2, 10.0)]])
    m = simulate_policy(inp, NoOrder())
    assert m["expired_units"] == pytest.approx(7.0) and m["expired_value"] == pytest.approx(70.0)
    assert m["sold_units"] == pytest.approx(3.0) and m["unmet_units"] == pytest.approx(7.0)   # después del 3.er día no queda nada vendible


def test_new_purchases_expire_after_their_shelf_life_and_a_series_without_it_never_does():
    perishable = sim(n_days=14, init=0.0, demand=0.5, shelf_life=np.array([4.0]), init_lots=[[]])
    plain = sim(n_days=14, init=0.0, demand=0.5)
    big, plain_big = simulate_policy(perishable, Recorder(qty=20.0)), simulate_policy(plain, Recorder(qty=20.0))
    assert big["expired_units"] > 0 and plain_big["expired_units"] == 0.0
    # misma compra, mismas ventas posibles: la perecible vende menos (parte vence) y su inventario final es menor
    assert big["sold_units"] <= plain_big["sold_units"] and big["ending_inventory_value"] <= plain_big["ending_inventory_value"]
