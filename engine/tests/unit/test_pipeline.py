"""Pipeline completo en memoria sobre datos sintéticos (sin base de datos)."""

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.pipeline.core import InsufficientDataError, run_pipeline


def test_outputs_and_levels(pipeline_result):
    fv = pipeline_result.forecast_values
    day = fv[fv["granularity"] == "day"]
    assert set(day["level"]) == {"series", "product", "category", "branch", "branch_category", "total"}
    assert day["horizon_step"].max() == 90
    assert (fv["yhat"] >= 0).all()
    assert (day["lo_80"] <= day["yhat"] + 1e-6).all() and (day["yhat"] <= day["hi_80"] + 1e-6).all()
    assert not fv.duplicated(["node_key", "granularity", "target_date"]).any()
    hz = fv[fv["granularity"] == "horizon"]
    assert set(hz["period_days"]) == {7, 14, 30, 60, 90} and hz["lo_80"].notna().all()
    assert (hz["quality_status"] == "ok").all(), "historia de ~575 días: los cinco horizontes están respaldados"
    assert {"week", "month"} <= set(fv["granularity"])


def test_hierarchy_is_coherent(pipeline_result):
    day = pipeline_result.forecast_values.query("granularity == 'day'")
    by = lambda lvl: day[day["level"] == lvl].groupby("target_date")["yhat"].sum()  # noqa: E731
    total = by("total")
    for lvl in ("series", "product", "branch"):
        np.testing.assert_allclose(by(lvl).loc[total.index], total, rtol=1e-6, atol=1e-3)
    # suma de series de un producto = nodo producto
    series = day[day["level"] == "series"].groupby(["product_id", "target_date"])["yhat"].sum()
    product = day[day["level"] == "product"].set_index(["product_id", "target_date"])["yhat"]
    np.testing.assert_allclose(series.loc[product.index], product, rtol=1e-6, atol=1e-3)
    # revenue también se agrega
    rev_series = day[day["level"] == "series"]["revenue_yhat"].sum()
    assert rev_series == pytest.approx(day[day["level"] == "total"]["revenue_yhat"].sum(), rel=1e-6)


def test_compares_against_baseline(pipeline_result):
    ev = pd.DataFrame(pipeline_result.evaluations)
    # Principal (lo que lee Laravel): período final reservado. Secundaria: ventanas de selección.
    assert set(ev["evaluation_type"]) == {"backtest", "selection_windows"}
    scopes = ev["extra"].map(lambda x: x["evaluation_scope"])
    assert (scopes[ev["evaluation_type"] == "backtest"] == "holdout").all()
    assert (scopes[ev["evaluation_type"] == "selection_windows"] == "selection_windows").all()
    picked = {}
    for etype in ("backtest", "selection_windows"):
        overall = ev[(ev["level"] == "overall") & (ev["evaluation_type"] == etype)]
        base = overall[overall["is_baseline"]]
        sel = overall[overall["model_family"] == "selection"]
        assert len(base) == 1 and base["model_family"].iat[0] == "seasonal_naive"
        assert len(sel) == 1 and bool(sel["is_selected"].iat[0])
        assert sel["coverage_80"].iat[0] is not None
        picked[etype] = (sel.iloc[0], base.iloc[0])
    # En las ventanas que eligieron el modelo la selección no pierde contra el baseline (es su
    # criterio); en el holdout puede perder y no se exige: es la prueba independiente.
    sel_w, base_w = picked["selection_windows"]
    assert sel_w["wape"] <= base_w["wape"] + 1e-9
    sel_h, _ = picked["backtest"]
    assert sel_h["n_windows"] == 1 and sel_h["cutoffs"] == [pipeline_result.plan.holdout_cutoff.date().isoformat()]
    primary = ev[ev["evaluation_type"] == "backtest"]
    assert set(primary[primary["level"] == "horizon"]["level_key"]) == {"h1-7", "h8-14", "h15-30", "h31-60", "h61-90"}
    assert primary[primary["level"] == "series"]["level_key"].str.match(r"^b\d+-p\d+$").all()
    assert primary[primary["level"] == "product"]["level_key"].str.match(r"^p\d+$").all()
    m = pipeline_result.summary["metrics"]
    assert m["evaluation_scope"] == "holdout" and m["independent_holdout"] is True
    assert m["selected_wape"] == pytest.approx(sel_h["wape"])
    assert m["selection_windows"]["selected_wape"] == pytest.approx(sel_w["wape"])


def test_holdout_is_disjoint_from_selection_windows(pipeline_result):
    plan, bt = pipeline_result.plan, pipeline_result.backtest
    assert plan.has_holdout and plan.holdout_cutoff == pd.Timestamp(pipeline_result.cutoff) - pd.Timedelta(
        days=plan.horizon)
    hold, windows = bt[bt["is_holdout"]], bt[~bt["is_holdout"]]
    # Ninguna fecha objetivo de las ventanas de selección cae en el holdout: sus residuos ya son
    # observables en el origen del holdout (calibración sin datos del período evaluado).
    assert windows["ds"].max() <= plan.holdout_cutoff < hold["ds"].min()
    assert hold["ds"].max() == pd.Timestamp(pipeline_result.cutoff)
    assert set(windows["window"]) == set(range(plan.windows)) and set(hold["window"]) == {plan.holdout_window}


def _scaled_after(data, after: pd.Timestamp, seed: int, extra_series: bool = False):
    """Copia de `data` con las ventas posteriores a `after` alteradas (y, opcionalmente, una serie
    nueva con 2 días de venta antes de `after` y venta diaria después)."""
    sales = data.sales.copy()
    rng = np.random.default_rng(seed)
    late = sales["ds"] > after
    factor = rng.uniform(0.2, 3.0, int(late.sum()))
    for col in ("units", "net_amount", "gross_amount", "discount_amount", "cost_amount", "cost_units"):
        if col in sales:
            sales.loc[late, col] = sales.loc[late, col] * factor
    products = data.products
    if extra_series:
        new = products.iloc[[0]].copy()
        pid = int(products["product_id"].max()) + 1
        new["product_id"] = pid
        if "sku" in new:
            new["sku"] = "TEST-F4"
        products = pd.concat([products, new], ignore_index=True)
        days = [after - pd.Timedelta(days=60), after - pd.Timedelta(days=20),
                *pd.date_range(after + pd.Timedelta(days=1), pd.Timestamp(data.cutoff), freq="D")]
        add = pd.DataFrame({"branch_id": int(data.branches["branch_id"].iat[0]), "product_id": pid, "ds": days,
                            "units": 5.0, "net_amount": 50.0})
        for col in ("gross_amount", "discount_amount", "cost_amount", "cost_units"):
            if col in sales:
                add[col] = {"gross_amount": 50.0, "discount_amount": 0.0, "cost_amount": 25.0, "cost_units": 5.0}[col]
        sales = pd.concat([sales, add], ignore_index=True).sort_values(["branch_id", "product_id", "ds"])
    return replace(data, sales=sales.reset_index(drop=True), products=products)


def test_future_after_a_backtest_cutoff_does_not_change_that_window(small_data, pipeline_result):
    """F4: alterar ventas posteriores al corte de la primera ventana (y añadir una serie que al corte
    solo tenía 2 días con venta) no cambia qué series se evalúan en esa ventana ni sus pronósticos."""
    c0 = pipeline_result.plan.cutoffs[0]
    pert = run_pipeline(_scaled_after(small_data, c0, seed=7, extra_series=True), horizon=90, seed=42, n_jobs=4)
    assert pert.plan.cutoffs == pipeline_result.plan.cutoffs
    key = ["unique_id", "ds", "family"]
    w0 = pipeline_result.backtest[pipeline_result.backtest["window"] == 0]
    p0 = pert.backtest[pert.backtest["window"] == 0]
    assert set(w0["unique_id"]) == set(p0["unique_id"])
    new_uid = f"b{int(small_data.branches['branch_id'].iat[0])}-p{int(small_data.products['product_id'].max()) + 1}"
    assert new_uid not in set(p0["unique_id"]), "2 días con venta al corte: no elegible en esa ventana"
    assert new_uid in set(pert.backtest.loc[pert.backtest["is_holdout"], "unique_id"]), "sí lo es más tarde"
    m = w0.merge(p0, on=key, suffixes=("_o", "_p"), validate="one_to_one")
    assert len(m) == len(w0) == len(p0) > 0
    np.testing.assert_allclose(m["yhat_o"], m["yhat_p"], rtol=1e-9, atol=1e-9)
    assert (m["demand_class_o"] == m["demand_class_p"]).all()
    assert not np.allclose(m["actual_o"], m["actual_p"]), "la perturbación sí alcanzó el período evaluado"


def test_holdout_evaluation_is_independent_of_holdout_data(small_data, pipeline_result):
    """F6: alterar las ventas del período final reservado cambia sus métricas, pero no la selección
    usada para evaluarlo, ni sus pronósticos, ni las métricas de las ventanas de selección."""
    origin = pipeline_result.plan.holdout_cutoff
    pert = run_pipeline(_scaled_after(small_data, origin, seed=11), horizon=90, seed=42, n_jobs=4)
    assert pert.evaluation_selection.winners == pipeline_result.evaluation_selection.winners
    assert pert.evaluation_selection.reasons == pipeline_result.evaluation_selection.reasons
    sw = lambda r: [e for e in r.evaluations if e["evaluation_type"] == "selection_windows"]  # noqa: E731
    assert sw(pert) == sw(pipeline_result)
    key = ["unique_id", "ds", "family"]
    ho = pipeline_result.backtest[pipeline_result.backtest["is_holdout"]]
    hp = pert.backtest[pert.backtest["is_holdout"]]
    m = ho.merge(hp, on=key, suffixes=("_o", "_p"), validate="one_to_one")
    assert len(m) == len(ho) == len(hp) > 0
    np.testing.assert_allclose(m["yhat_o"], m["yhat_p"], rtol=1e-9, atol=1e-9)
    before, after = pipeline_result.summary["metrics"], pert.summary["metrics"]
    assert after["selected_wape"] != pytest.approx(before["selected_wape"]), "las métricas del holdout sí cambian"


def test_short_history_degrades_without_faking_a_holdout():
    from predictily_engine.pipeline.data import tenant_data_from_synthetic
    from predictily_engine.synthetic import generate_company

    # 78 días: alcanza para 2 ventanas de 14 días, no para además un período final reservado.
    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=6, start="2026-08-10"))
    res = run_pipeline(data, horizon=30, seed=42, n_jobs=2)
    assert res.plan is not None and not res.plan.has_holdout
    m = res.summary["metrics"]
    assert m["evaluation_scope"] == "selection_windows" and m["independent_holdout"] is False
    assert m["holdout"] is None and m["selection_windows"] is None
    assert any("período final independiente" in w for w in res.warnings)
    assert {e["evaluation_type"] for e in res.evaluations} == {"backtest"}


def test_interval_coverage_is_reasonable(pipeline_result):
    m = pipeline_result.summary["metrics"]
    assert 0.65 <= m["coverage_80"] <= 0.92
    assert 0.85 <= m["coverage_95"] <= 0.99


def test_reproducible_with_same_seed(small_data, pipeline_result):
    again = run_pipeline(small_data, horizon=90, seed=42, n_jobs=4)
    a = pipeline_result.forecast_values.sort_values(["node_key", "granularity", "target_date"]).reset_index(drop=True)
    b = again.forecast_values.sort_values(["node_key", "granularity", "target_date"]).reset_index(drop=True)
    pd.testing.assert_frame_equal(a, b)
    assert again.training_data_hash == pipeline_result.training_data_hash


def test_recommendations_and_censoring(pipeline_result):
    recs = pipeline_result.recommendations
    assert not recs.empty
    # Horizonte de 90 días: plazo + revisión siempre cubiertos → todas las cantidades existen (F2).
    assert not recs["assumptions"].map(lambda a: a["coverage_insufficient"]).any()
    assert (recs["suggested_qty"] >= 0).all()
    assert (recs["reorder_point"] >= recs["demand_lead_time"] - 1e-6).all()
    assert recs["risk_level"].isin(["low", "medium", "high", "critical"]).all()
    # Probabilidad nula solo si la incertidumbre es desconocida (F3); si no, en [0, 1].
    unknown = recs["assumptions"].map(lambda a: a["uncertainty"] == "unknown")
    assert (recs["stockout_probability"].isna() == unknown).all()
    assert (recs.loc[~unknown, "stockout_probability"].between(0, 1)).all()
    # Disponible = existencias − reservado; lo pendiente de recibir no cuenta como disponible hoy (F1).
    np.testing.assert_allclose(recs["available"], recs["on_hand"] - recs["reserved"])
    # Estimación empírica con muestra finita: nunca 0 % ni 100 % exactos (regla de Laplace).
    empirical = recs[recs["assumptions"].map(lambda a: a.get("method") == "empirical_backtest")]
    assert not empirical.empty
    assert ((empirical["stockout_probability"] > 0) & (empirical["stockout_probability"] < 1)).all()
    prof = pipeline_result.panel.profile
    assert prof["stockout_days"].sum() > 0


def test_insufficient_data_raises(small_data):
    import copy

    empty = copy.copy(small_data)
    empty.sales = small_data.sales.iloc[0:0]
    with pytest.raises(InsufficientDataError):
        run_pipeline(empty)
