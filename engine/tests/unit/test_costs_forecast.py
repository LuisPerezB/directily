"""E2-15 · Pronóstico de costos: disponibilidad por known_at, referencia simple, holdout temporal, series separadas
y abstención con poca historia. Se pronostican costos unitarios, nunca cantidades."""

from __future__ import annotations

from datetime import date

import pandas as pd
import pytest

from predictily_engine.costs.forecast import (
    CostSeries,
    add_months,
    build_purchase_series,
    forecast_series,
)

CUT = date(2026, 6, 30)
CFG = {"min_observations": 6, "holdout_periods": 3, "min_train_periods": 3, "min_improvement": 0.03, "interval_level": 0.8,
       "methods": ["last_value", "mean_3", "drift", "holt_damped"]}


def series(values, start=date(2025, 1, 1)):
    return CostSeries(1, None, "purchase_history", "USD", "kg", [add_months(start, k) for k in range(len(values))], list(values), [1] * len(values))


def history(rows):
    return pd.DataFrame(rows, columns=["cost_id", "product_id", "supplier_id", "unit_cost", "currency", "unit", "valid_from", "known_at", "source"])


def test_linear_trend_selects_drift_and_beats_the_reference_in_the_holdout():
    r = forecast_series(series([10 + 0.5 * k for k in range(18)]), CUT, 3, CFG)
    assert r["method"] == "drift" and r["quality_status"] == "ok"
    # último valor = 18,5 (k=17); el siguiente mes por tendencia = 19,0 y la referencia (último valor) = 18,5
    assert r["points"][0]["yhat"] == pytest.approx(19.0) and r["points"][0]["reference"] == pytest.approx(18.5)
    assert r["points"][0]["target_date"] == "2026-07-01"
    m = r["metrics"]
    assert m["drift"]["holdout"]["mae"] < m["last_value"]["holdout"]["mae"]
    # El último valor se queda 0,5 por mes de distancia: errores 0,5 / 1,0 / 1,5 a 1, 2 y 3 meses → MAE medio 1,0
    assert m["last_value"]["holdout"]["mae"] == pytest.approx(1.0) and m["drift"]["holdout"]["mae"] == pytest.approx(0.0, abs=1e-9)


def test_constant_series_serves_the_reference_without_inventing_improvement():
    r = forecast_series(series([5.0] * 14), CUT, 3, CFG)
    assert r["method"] == "last_value" and all(p["yhat"] == 5.0 for p in r["points"])


def test_holdout_does_not_participate_in_the_choice_of_method():
    base = [10 + 0.5 * k for k in range(18)]
    shocked = base[:-3] + [60.0, 5.0, 90.0]  # cambia SOLO los 3 últimos períodos (holdout)
    a = forecast_series(series(base), CUT, 3, CFG)
    b = forecast_series(series(shocked), CUT, 3, CFG)
    sel_a = {m: a["metrics"][m]["selection"] for m in a["metrics"]}
    sel_b = {m: b["metrics"][m]["selection"] for m in b["metrics"]}
    assert sel_a == sel_b  # las métricas de selección no ven el holdout
    assert a["metrics"]["last_value"]["holdout"] != b["metrics"]["last_value"]["holdout"]


def test_insufficient_history_abstains():
    r = forecast_series(series([5.0, 5.2, 5.1]), CUT, 3, CFG)
    assert r["quality_status"] == "insufficient_data" and r["points"] == [] and r["method"] is None


def test_availability_known_at_after_cutoff_is_not_used_even_if_valid_from_is_earlier():
    rows = [(1, 1, None, 10.0, "USD", "kg", date(2026, 5, 10), pd.Timestamp("2026-05-10"), "import"),
            (2, 1, None, 99.0, "USD", "kg", date(2026, 6, 1), pd.Timestamp("2026-07-15"), "import"),  # conocido después del corte
            (3, 1, None, 12.0, "USD", "kg", date(2026, 6, 5), pd.Timestamp("2026-06-05"), "import")]
    s, stats = build_purchase_series(history(rows), CUT, retroactive=False)
    assert stats["after_cutoff"] == 1 and stats["used"] == 2
    assert s[0].values == [10.0, 12.0]  # el 99 no existe al corte


def test_null_known_at_rules_current_vs_retroactive():
    rows = [(1, 1, None, 10.0, "USD", "kg", date(2026, 5, 10), None, "import"),
            (2, 1, None, 11.0, "USD", "kg", date(2026, 5, 20), None, "purchase_order"),  # una compra real se conoce en su fecha
            (3, 1, None, 12.0, "USD", "kg", date(2026, 6, 5), None, "manual")]
    cur, st = build_purchase_series(history(rows), CUT, retroactive=False)
    assert st["used"] == 3 and cur[0].availability["known_at_missing_accepted_current_run"] == 3
    retro, st = build_purchase_series(history(rows), CUT, retroactive=True)
    assert st["used"] == 1 and st["unknown_availability_excluded"] == 2 and retro[0].values == [11.0]
    assumed, st = build_purchase_series(history(rows), CUT, retroactive=True, null_policy="assume_valid_from")
    assert st["used"] == 3 and st["assumed_valid_from"] == 2 and assumed[0].degraded
    assert forecast_series(assumed[0], CUT, 3, {**CFG, "min_observations": 1})["quality_status"] in ("degraded", "insufficient_data")


def test_series_are_separate_by_supplier_currency_and_unit_and_quantities_are_ignored():
    rows = []
    for i in range(8):
        d = add_months(date(2025, 11, 1), i)
        rows += [(i * 4, 1, 1, 10.0 + i, "USD", "kg", d, None, "purchase_order"),
                 (i * 4 + 1, 1, 2, 20.0, "USD", "kg", d, None, "purchase_order"),
                 (i * 4 + 2, 1, 1, 9.0, "EUR", "kg", d, None, "purchase_order"),
                 (i * 4 + 3, 1, 1, 10000.0, "USD", "ton", d, None, "purchase_order")]
    h = history(rows)
    s, _ = build_purchase_series(h, CUT, retroactive=True)
    assert len(s) == 4 and {(x.supplier_id, x.currency, x.unit) for x in s} == {(1, "USD", "kg"), (2, "USD", "kg"), (1, "EUR", "kg"), (1, "USD", "ton")}
    h2 = h.assign(quantity=[1, 5000] * (len(h) // 2))  # columna de cantidades: no entra al pronóstico de precios
    s2, _ = build_purchase_series(h2, CUT, retroactive=True)
    f1 = [forecast_series(x, CUT, 3, CFG)["points"] for x in s]
    f2 = [forecast_series(x, CUT, 3, CFG)["points"] for x in s2]
    assert f1 == f2


def test_interval_is_unknown_without_enough_errors_and_never_zero_width_by_default():
    r = forecast_series(series([10, 10.4, 10.1, 10.6, 10.2, 10.7, 10.3, 10.8]), CUT, 3, CFG)
    assert all(p["lo"] is None and p["hi"] is None for p in r["points"])
    assert any("intervalo queda desconocido" in x for x in r["limitations"])
    long = forecast_series(series([10 + (k % 3) * 0.7 for k in range(30)], start=date(2024, 1, 1)), CUT, 3, CFG)
    assert long["points"][0]["lo"] is not None and long["points"][0]["lo"] <= long["points"][0]["yhat"] <= long["points"][0]["hi"]


def test_missing_months_are_filled_and_declared():
    s = CostSeries(1, None, "purchase_history", "USD", None, [date(2025, 1, 1), date(2025, 2, 1), date(2025, 5, 1), date(2025, 6, 1),
                                                              date(2025, 7, 1), date(2025, 8, 1)], [10, 10, 12, 12, 12, 12], [1] * 6)
    r = forecast_series(s, CUT, 2, {**CFG, "min_observations": 6})
    assert any("sin observación" in x for x in r["limitations"]) and r["quality_status"] == "degraded"


def test_forecast_at_a_cutoff_does_not_change_when_later_information_is_added():
    """E2-05: perturbar costos posteriores al corte (fecha efectiva o conocimiento) no altera el pronóstico de ese corte."""
    base = [(i, 1, 1, 10.0 + 0.5 * i, "USD", "kg", add_months(date(2025, 1, 1), i).replace(day=10), None, "purchase_order") for i in range(18)]
    s0, _ = build_purchase_series(history(base), CUT, retroactive=True)
    f0 = forecast_series(s0[0], CUT, 3, CFG)
    noisy = base + [(100, 1, 1, 500.0, "USD", "kg", date(2026, 8, 1), pd.Timestamp("2026-06-15"), "import"),   # anunciado antes, vigente después del corte
                    (101, 1, 1, 700.0, "USD", "kg", date(2026, 6, 1), pd.Timestamp("2026-09-01"), "import"),  # conocido después
                    (102, 1, 1, 900.0, "USD", "kg", date(2026, 6, 2), None, "import")]                       # sin known_at (retroactivo)
    s1, stats = build_purchase_series(history(noisy), CUT, retroactive=True)
    f1 = forecast_series(s1[0], CUT, 3, CFG)
    assert f1 == f0
    assert stats["future_effective"] == 1 and stats["after_cutoff"] == 1 and stats["unknown_availability_excluded"] == 1
