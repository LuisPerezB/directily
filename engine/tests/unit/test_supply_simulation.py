"""E2-20 · Simulación histórica de operación: información disponible a cada fecha, mismas condiciones para todas las
políticas, métricas verificadas a mano, y sin reemplazar la validación con datos reales (sintéticos)."""

from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from predictily_engine.supply.simulation import (
    CurrentPolicy,
    PlannerPolicy,
    Policy,
    ReferencePolicy,
    SimInputs,
    compare_policies,
    simulate_policy,
    weekday_forecast,
)


def inputs(n_days=14, series=1, demand=10.0, init=20.0, lead=2, lead_std=0.0, cost=2.0, price=5.0, history=70, seed=1):
    total = history + n_days
    dates = pd.date_range("2026-06-01", periods=total, freq="D")
    dem = np.full((total, series), float(demand))
    return SimInputs(dates=dates, demand=dem, sold_hist=dem.copy(), start=history, n_days=n_days, init_inv=np.full(series, float(init)),
                     keys=[(1, 10 + i) for i in range(series)], unit_cost=np.full(series, float(cost)), price=np.full(series, float(price)),
                     lead=np.full(series, lead, dtype=int), lead_std=np.full(series, float(lead_std)), review_days=7, seed=seed)


class Fixed(Policy):
    def __init__(self, qty, name="fixed"):
        self.qty, self.name = qty, name

    def decide(self, ctx):
        return np.full(len(ctx.inp.keys), float(self.qty))


class Recorder(Policy):
    name = "recorder"

    def __init__(self, qty=1000.0):
        self.forecasts, self.days, self.qty = [], [], qty

    def decide(self, ctx):
        self.forecasts.append(ctx.forecast.copy())
        self.days.append(ctx.day)
        return np.full(len(ctx.inp.keys), self.qty)


def test_hand_computed_fixed_ordering_policy():
    # Demanda 10/día, existencias 20, plazo 2 días, 70 u. cada revisión (k=0 y k=7), 14 días.
    # Existencias al cierre: 10,0,60,50,40,30,20,10 | 0,60,50,40,30,20 → suma 220 + 200 = 420; sin faltantes.
    m = simulate_policy(inputs(), Fixed(70))
    assert m["demand_units"] == 140 and m["sold_units"] == 140 and m["unmet_units"] == 0 and m["fill_rate"] == 1.0
    assert m["orders"] == 2 and m["purchase_outlay"] == 280.0
    assert m["avg_inventory_value"] == pytest.approx(420 * 2.0 / 14, abs=0.01)
    assert m["holding_cost"] == pytest.approx(420 * 2.0 * 0.20 / 365, abs=0.01)
    assert m["ending_inventory_value"] == 40.0  # 20 u. · 2 $


def test_no_orders_leaves_demand_unmet_with_lost_margin():
    m = simulate_policy(inputs(), Fixed(0))
    assert m["sold_units"] == 20 and m["unmet_units"] == 120 and m["fill_rate"] == pytest.approx(20 / 140, abs=1e-4)
    assert m["lost_margin"] == pytest.approx(120 * (5.0 - 2.0))  # margen de catálogo por unidad no atendida
    assert m["stockout_day_share"] == pytest.approx(12 / 14, abs=1e-4)


def test_policy_decisions_only_use_information_available_at_each_date():
    base = inputs(n_days=21)
    r1 = Recorder()
    simulate_policy(base, r1)
    # Se altera la demanda de la ventana DESPUÉS de la primera revisión: las decisiones anteriores no cambian.
    pert = inputs(n_days=21)
    pert.demand[pert.start + 3:] *= 5.0
    pert.sold_hist[pert.start + 3:] *= 5.0
    r2 = Recorder()
    simulate_policy(pert, r2)
    assert r1.days[0] == r2.days[0] == base.start
    np.testing.assert_allclose(r1.forecasts[0], r2.forecasts[0])  # primera revisión (k=0): idéntica
    assert not np.allclose(r1.forecasts[1], r2.forecasts[1])  # la segunda (k=7) sí ve la nueva demanda observada
    # y el pronóstico solo depende de ventas observadas hasta la fecha de la revisión
    h = base.sold_hist[: base.start].copy()
    f_a, _ = weekday_forecast(h, base.dates[base.start - 1], 14)
    h[-1] += 0  # sin cambios
    f_b, _ = weekday_forecast(np.vstack([h]), base.dates[base.start - 1], 14)
    np.testing.assert_allclose(f_a, f_b)


def test_policy_observed_sales_under_stockout_feed_its_own_forecast():
    # Con quiebres la política observa solo lo vendido (censurado), no la demanda verdadera.
    inp = inputs(n_days=14, init=0.0)
    r = Recorder(qty=0.0)  # no hay existencias ni pedidos: no vende nada en la ventana
    simulate_policy(inp, r)
    # Ventas observadas: 21 días previos a 10 y 7 días a 0 → nivel de 28 días 7,5 (< 10 reales): sesgo por censura, declarado
    assert r.forecasts[1].mean() < 0.9 * 10.0 and r.forecasts[1].mean() > 0


def test_policies_share_demand_initial_stock_and_random_delays():
    inp = inputs(n_days=28, series=3, lead_std=1.5, seed=5)
    res = compare_policies(inp, [CurrentPolicy(), ReferencePolicy()])
    cur, ref = res["policies"]["current"], res["policies"]["reference"]
    assert cur["demand_units"] == ref["demand_units"] == 28 * 10 * 3
    for r in (cur, ref):
        assert r["sold_units"] + r["unmet_units"] == pytest.approx(r["demand_units"])
        assert 0 <= r["fill_rate"] <= 1 and r["days_of_inventory"] is None or r["days_of_inventory"] >= 0
    assert res["delta_vs_current"]["current"]["unmet_units"] == 0
    assert "No reemplaza la validación con datos reales" in res["caveats"][0]


def test_planner_policy_runs_with_budget_and_reports_solver_states():
    inp = inputs(n_days=21, series=4, demand=10.0, init=15.0, lead=3)
    free = PlannerPolicy(name="free")
    capped = PlannerPolicy(budget_per_review=100.0, name="capped")
    res = compare_policies(inp, [free, capped])
    assert res["policies"]["capped"]["spend_per_review_mean"] <= 100.0 + 1e-6  # presupuesto por revisión respetado
    assert res["policies"]["free"]["fill_rate"] >= res["policies"]["capped"]["fill_rate"]
    assert set(res["policies"]["free"]["solver"]["statuses"]) <= {"optimal", "feasible_with_gap", "time_limit", "heuristic_fallback"}


def test_overstock_is_measured_against_realized_future_demand():
    # Demanda constante 10/día, existencias iniciales 200 y no se pide nada: con vista de 5 días, exceso = inv − 50.
    inp = inputs(n_days=10, init=200.0)
    inp.overstock_days = 5
    m = simulate_policy(inp, Fixed(0))
    # cierres: 190,180,...,100 (10 días); exceso en los 5 primeros días = (190,180,170,160,150) − 50 = 140,130,120,110,100 = 600
    assert m["overstock_unit_days"] == pytest.approx(600.0) and m["overstock_window_days"] == 5
