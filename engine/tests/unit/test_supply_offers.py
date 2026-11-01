"""E2-26 · Proveedores: unidades, monedas, gastos de adquisición, vigencia y known_at, cobertura de la comparación,
y una oferta barata que llega tarde no oculta faltantes previos."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest
from _supply_builders import node

from predictily_engine.supply.fx import FxRates
from predictily_engine.supply.offers import (
    comparison_coverage,
    implicit_offers,
    normalize_offers,
    resolve_offer_lead_time,
    standalone_offer_diagnostics,
)
from predictily_engine.supply.units import UnitConversions

AS_OF = date(2026, 10, 26)
PRODUCTS = pd.DataFrame({"product_id": [10], "unit": ["u"], "current_price": [5.0], "current_cost": [2.0]})
LT = pd.DataFrame(columns=["supplier_id", "product_id", "branch_id", "lead_time_days", "lead_time_std_days"])
SUP = pd.DataFrame({"supplier_id": [1, 2], "default_lead_time_days": [9, 15]})
CONV = pd.DataFrame({"product_id": [10, np.nan], "from_unit": ["caja", "kg"], "to_unit": ["u", "g"], "factor": [12.0, 1000.0],
                     "valid_from": [None, None]})
FX = pd.DataFrame({"from_currency": ["EUR"], "to_currency": ["USD"], "rate": [1.2], "valid_on": [date(2026, 10, 20)],
                   "known_at": [None], "source": ["manual"]})


def offer_row(**kw):
    base = dict(offer_id=1, supplier_id=1, product_id=10, branch_id=None, unit_cost=10.0, currency="USD", unit="u",
                min_order_qty=None, order_multiple=None, lead_time_days=5, lead_time_std_days=None,
                capacity_per_period=None, capacity_period_days=7, fixed_order_cost=0.0, acquisition_cost_pct=0.0,
                acquisition_cost_per_unit=0.0, valid_from=None, valid_to=None, known_at=None, is_active=True)
    base.update(kw)
    return base


def normalize(rows, retro=False, fx=FX, conv=CONV):
    return normalize_offers(pd.DataFrame(rows), PRODUCTS, UnitConversions(conv), FxRates(fx, AS_OF, retro), LT, SUP, AS_OF, retro,
                            "USD", 7, 7, [1])


def test_units_and_currency_are_normalized_explicitly_with_acquisition_costs():
    # 10 EUR por caja de 12 u.; +10 % de gastos y 0,50 EUR por caja; EUR→USD 1,2:
    # costo por caja = (10·1,10 + 0,50) = 11,50 EUR; por unidad = 11,50/12 · 1,2 = 1,15 USD. Sin gastos: 10/12·1,2 = 1,00.
    offers, rep = normalize([offer_row(unit_cost=10.0, currency="EUR", unit="caja", acquisition_cost_pct=0.10,
                                       acquisition_cost_per_unit=0.5, min_order_qty=10, order_multiple=2, fixed_order_cost=5.0)])
    o = offers[0]
    assert o.unit_cost == pytest.approx(1.15) and o.base_unit_cost == pytest.approx(1.0)
    assert o.lot == pytest.approx(24.0) and o.moq == pytest.approx(120.0)  # 2 cajas · 12; 10 cajas · 12
    assert o.fixed_cost == pytest.approx(6.0)  # 5 EUR · 1,2
    assert rep[0]["status"] == "comparable"


def test_inverse_conversion_and_missing_conversion():
    conv = pd.DataFrame({"product_id": [np.nan], "from_unit": ["u"], "to_unit": ["docena"], "factor": [1 / 12], "valid_from": [None]})
    uc = UnitConversions(pd.concat([CONV, conv]))
    assert uc.factor(10, "caja", "u", AS_OF) == (12.0, "product")
    assert uc.factor(10, "u", "caja", AS_OF)[0] == pytest.approx(1 / 12) and uc.factor(10, "u", "caja", AS_OF)[1] == "product_inverse"
    assert uc.factor(10, "pallet", "u", AS_OF) == (None, "missing")
    assert uc.factor(10, "U", " u ", AS_OF) == (1.0, "identity")


def test_no_conversion_or_no_fx_means_not_comparable_and_is_declared():
    rows = [offer_row(offer_id=1, unit="pallet"), offer_row(offer_id=2, currency="GBP"), offer_row(offer_id=3),
            offer_row(offer_id=4, is_active=False), offer_row(offer_id=5, valid_to=date(2026, 9, 1))]
    offers, rep = normalize(rows)
    assert [o.offer_id for o in offers] == [3]
    reasons = {e["offer_id"]: e["reasons"] for e in rep}
    assert reasons[1] == ["no_unit_conversion"] and reasons[2] == ["no_fx_rate"] and reasons[4] == ["inactive"] and reasons[5] == ["expired"]
    cov = comparison_coverage(rep, [10])
    assert cov["offers_received"] == 5 and cov["offers_comparable"] == 1 and cov["offers_excluded"] == 4
    assert cov["products_single_source"] == [10] and cov["products_without_comparable_offer"] == []


def test_fx_inverse_stale_and_no_triangulation():
    fx = FxRates(pd.DataFrame({"from_currency": ["USD"], "to_currency": ["EUR"], "rate": [0.8], "valid_on": [date(2026, 8, 1)],
                               "known_at": [None], "source": ["manual"]}), AS_OF)
    rate, meta = fx.rate("EUR", "USD")
    assert rate == pytest.approx(1.25) and meta["inverse"] and meta["stale"]
    assert fx.rate("GBP", "USD")[0] is None  # sin triangular a través de otra moneda
    assert fx.rate("USD", "USD")[0] == 1.0


def test_availability_known_at_and_retroactive_rule():
    rows = [offer_row(offer_id=1, known_at=pd.Timestamp("2026-10-30")),  # se conoció después del corte
            offer_row(offer_id=2, known_at=pd.Timestamp("2026-10-01")),
            offer_row(offer_id=3)]  # known_at desconocido
    cur, rep_c = normalize(rows, retro=False)
    assert sorted(o.offer_id for o in cur) == [2, 3]  # ejecución vigente: known_at NULL se acepta
    retro, rep_r = normalize(rows, retro=True)
    assert [o.offer_id for o in retro] == [2]
    r = {e["offer_id"]: e["reasons"] for e in rep_r}
    assert r[1] == ["after_cutoff"] and r[3] == ["unknown_availability"]
    # tasas de cambio con known_at posterior tampoco existen en el corte
    late_fx = FX.assign(known_at=pd.Timestamp("2026-10-30"))
    assert FxRates(late_fx, AS_OF).rate("EUR", "USD")[0] is None


def test_capacity_is_prorated_to_the_planning_period_and_lead_time_priority():
    o, _ = normalize([offer_row(capacity_per_period=100.0, capacity_period_days=30)])
    assert o[0].capacity == pytest.approx(100.0 * 7 / 30) and o[0].meta["capacity_prorated"]
    lt = pd.DataFrame({"supplier_id": [1, 1, 1], "product_id": [10, 10, np.nan], "branch_id": [3, np.nan, np.nan],
                       "lead_time_days": [4, 6, 8], "lead_time_std_days": [0.5, 1.0, 2.0]})
    assert resolve_offer_lead_time(1, 10, 3, lt, SUP, 7)[2] == "supplier_product_branch"
    assert resolve_offer_lead_time(1, 10, 9, lt, SUP, 7) == (6, 1.0, "supplier_product")
    assert resolve_offer_lead_time(1, 99, 9, lt, SUP, 7) == (8, 2.0, "supplier")
    assert resolve_offer_lead_time(2, 99, 9, LT, SUP, 7) == (15, None, "supplier_default")
    assert resolve_offer_lead_time(None, 99, None, LT, SUP, 7) == (7, None, "tenant_default")


def test_cheap_late_offer_diagnostic_exposes_the_earlier_shortage():
    # Existencias 10, demanda 70 por período. Oferta A: 3 $/u, llega en el período 0; B: 2 $/u, 21 días (período 3).
    # Con B sola, la demanda de los períodos 0-2 (210) menos las 10 u. de stock queda sin atender: 200 u.
    from _supply_builders import offer

    n = node(opening=10.0, demand=[70.0] * 6, penalty=6.0)
    rows = standalone_offer_diagnostics(n, [offer("o1", cost=3.0, lead=1), offer("o2", cost=2.0, lead=21, supplier=2)], 7)
    by = {r["offer_id"]: r for r in rows}
    assert by[1]["unmet_before_arrival_units"] == pytest.approx(0.0)
    assert by[2]["unmet_before_arrival_units"] == pytest.approx(200.0) and by[2]["penalty_before_arrival"] == pytest.approx(1200.0)
    assert by[2]["rank_by_price"] == 1 and by[1]["rank_by_price"] == 2  # la «más barata» es la que oculta el faltante


def test_implicit_offers_only_for_products_with_a_known_cost():
    offers, rep = implicit_offers([10, 11], PRODUCTS, {10: {"unit_cost": 2.0, "supplier_id": 1, "source": "t"}}, LT, SUP, 7, [1], "USD")
    assert [o.product_id for o in offers] == [10] and offers[0].implicit and offers[0].moq == 0 and offers[0].capacity is None
    assert [e["status"] for e in rep] == ["comparable", "excluded"] and rep[1]["reasons"] == ["no_cost_known"]
