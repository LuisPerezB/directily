"""Costo unitario reciente: sin costos informados no aborta la ejecución (defecto previo a W2, visto por W2-C)."""

import math

import pandas as pd

from predictily_engine.pipeline.core import recent_unit_costs


def test_sales_without_any_cost_give_nan_instead_of_zero_division():
    sales = pd.DataFrame({"branch_id": [1, 1, 2], "product_id": [7, 7, 8], "cost_amount": [None, None, None],
                          "cost_units": [None, None, None]}, dtype=object)
    out = recent_unit_costs(sales)
    assert out.isna().all() and len(out) == 2


def test_mixed_costs_divide_only_where_units_have_cost():
    sales = pd.DataFrame({"branch_id": [1, 1, 2], "product_id": [7, 7, 8], "cost_amount": [10.0, 20.0, 0.0],
                          "cost_units": [2.0, 3.0, 0.0]})
    out = recent_unit_costs(sales)
    assert math.isclose(out.loc[(1, 7)], 30.0 / 5.0)
    assert math.isnan(out.loc[(2, 8)])


def test_empty_sales_give_an_empty_series():
    assert recent_unit_costs(pd.DataFrame(columns=["branch_id", "product_id", "cost_amount", "cost_units"])).empty
