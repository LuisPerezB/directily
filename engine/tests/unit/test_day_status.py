"""ADR-V25-03 · semántica de días sin transacciones: un día sin ventas NO es siempre un cero (sin base de datos).

`day_status` ∈ {sale, zero_confirmed, missing_source, stockout_censored, closed}; `missing_source` y `closed` se excluyen de las
métricas y del perfilado de ceros, se imputan como los quiebres (media móvil de 28 días) y NUNCA cuentan como cero observado.
Todo con datos FICTICIOS.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd
import pytest

from predictily_engine.backtest.rolling import BacktestPlan, run_backtest
from predictily_engine.demand.evaluation import exclusion_detail, with_keys
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import TenantData, tenant_data_from_synthetic
from predictily_engine.platform.config import CORE_DEFAULTS, resolve_config, validate_overrides
from predictily_engine.profiling.days import DAY_STATUSES, EXCLUDED_STATUSES, DayRules, censored_days
from predictily_engine.profiling.series import build_panel, profile_at
from predictily_engine.synthetic import generate_company

START = pd.Timestamp("2026-01-01")


def hand_data(n_days: int = 80, branches: tuple[int, ...] = (1,), products: tuple[int, ...] = (1, 2), lines: int = 4,
              skip: tuple[tuple[int, str], ...] = (), units: float = 5.0, returns_on: tuple[tuple[int, str], ...] = (), **over) -> TenantData:
    """Ventas diarias de cada sucursal × producto con `lines` líneas por celda, salvo los días (sucursal, fecha) de `skip`."""
    days = pd.date_range(START, periods=n_days, freq="D")
    skipped = {(b, pd.Timestamp(d)) for b, d in skip}
    rows = [{"branch_id": b, "product_id": p, "ds": d, "units": units, "net_amount": units * 10.0, "gross_amount": units * 10.0,
             "discount_amount": 0.0, "cost_amount": units * 4.0, "cost_units": units, "lines": lines}
            for b in branches for p in products for d in days if (b, d) not in skipped]
    ret = pd.DataFrame([{"branch_id": b, "product_id": products[0], "ds": pd.Timestamp(d), "units": 1.0} for b, d in returns_on],
                       columns=["branch_id", "product_id", "ds", "units"])
    data = TenantData(
        tenant_id=1, settings={}, cutoff=days[-1].date(),
        branches=pd.DataFrame({"branch_id": list(branches), "region": "sierra", "canton": "quito"}),
        products=pd.DataFrame({"product_id": list(products), "category_id": 1, "is_active": True}),
        categories=pd.DataFrame(), sales=pd.DataFrame(rows), returns=ret)
    return replace(data, **over) if over else data


def day(offset: int) -> pd.Timestamp:
    return START + pd.Timedelta(days=offset)


def status_of(panel, branch: int, offset: int, product: int = 1) -> tuple[str, str | None]:
    row = panel.df[(panel.df["branch_id"] == branch) & (panel.df["product_id"] == product) & (panel.df["ds"] == day(offset))]
    assert len(row) == 1, "el día debe existir en la serie (continuidad del panel)"
    r = row.iloc[0]
    return str(r["day_status"]), (None if pd.isna(r["day_basis"]) else str(r["day_basis"]))


def test_without_any_declaration_the_panel_behaves_as_before():
    """Sin cobertura, huecos ni cierres: los ceros siguen siendo ceros observados (limitación coverage_undeclared del core de Datos)."""
    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=4, start="2026-01-01"))
    panel = build_panel(data)
    assert set(panel.df["day_status"].unique()) <= {"sale", "zero_confirmed", "stockout_censored"}
    assert list(panel.df["day_status"].cat.categories) == list(DAY_STATUSES)
    st = panel.df["stockout"]
    assert ((panel.df["day_status"] == "stockout_censored") == st).all()
    assert (panel.df.loc[~st, "y"] == panel.df.loc[~st, "units"]).all(), "sin días excluidos la imputación es solo la de los quiebres"
    zero = panel.df[(panel.df["units"] == 0) & ~st]
    assert len(zero) and (zero["day_status"] == "zero_confirmed").all()
    assert panel.day_summary["series_days"]["missing_source"] == 0 and panel.day_summary["series_days"]["closed"] == 0
    assert (panel.profile["missing_source_days"] == 0).all() and (panel.profile["closed_days"] == 0).all()


def test_a_declared_gap_is_missing_source_never_a_zero_and_is_imputed():
    """Defecto HIGH de la auditoría: seis días sin datos por una caída de la integración eran seis ceros observados."""
    outage = [(1, day(k).strftime("%Y-%m-%d")) for k in range(40, 46)]
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(40), "gap_to": day(45), "reason": "integration_outage",
                         "basis": "declared"}])
    undeclared = build_panel(hand_data(skip=tuple(outage)))
    assert status_of(undeclared, 1, 42) == ("zero_confirmed", None), "sin declarar, el hueco sigue siendo un cero (comportamiento actual)"
    declared = build_panel(hand_data(skip=tuple(outage), coverage_gaps=gap))
    for k in range(40, 46):
        assert status_of(declared, 1, k) == ("missing_source", "declared")
    assert status_of(declared, 1, 39)[0] == "sale" and status_of(declared, 1, 46)[0] == "sale"
    gap_rows = declared.df[(declared.df["ds"] >= day(40)) & (declared.df["ds"] <= day(45))]
    assert (gap_rows["units"] == 0).all() and (gap_rows["y"] == 5.0).all(), "imputados con la media móvil de 28 días de los días limpios"
    assert (undeclared.df.loc[undeclared.df["ds"].between(day(40), day(45)), "y"] == 0).all()
    s = declared.day_summary
    assert s["series_days"]["missing_source"] == 12 and s["excluded_days_imputed"] == 12 and s["excluded_days_not_imputable"] == 0
    assert s["series_days_by_basis"] == {"missing_source:declared": 12}
    assert set(declared.profile["missing_source_days"]) == {6}
    assert censored_days(declared.df)[gap_rows.index].all()


def test_zero_proportion_of_the_profile_ignores_missing_source_and_closed_days():
    """ADI = días / días con venta sobre los últimos 365 días LIMPIOS: los días sin fuente o cerrados no cuentan como ceros."""
    sold = [k for k in range(80) if k % 2 == 0]
    rows = [{"branch_id": 1, "product_id": 1, "ds": day(k), "units": 5.0, "net_amount": 50.0, "lines": 3} for k in sold]
    base = TenantData(tenant_id=1, settings={}, cutoff=day(79).date(), branches=pd.DataFrame({"branch_id": [1], "region": ["sierra"], "canton": ["quito"]}),
                      products=pd.DataFrame({"product_id": [1], "category_id": [1], "is_active": [True]}), categories=pd.DataFrame(),
                      sales=pd.DataFrame(rows))
    plain = build_panel(base).profile.iloc[0]
    assert plain["adi"] == pytest.approx(2.0)
    closures = pd.DataFrame([{"branch_id": 1, "starts_on": day(41), "ends_on": day(41), "code": "inventario"},
                             {"branch_id": 1, "starts_on": day(51), "ends_on": day(51), "code": "inventario-2"}])
    gaps = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(61), "gap_to": day(61), "reason": "unknown", "basis": "declared"}])
    flagged = build_panel(replace(base, closures=closures, coverage_gaps=gaps))
    prof = flagged.profile.iloc[0]
    assert (prof["closed_days"], prof["missing_source_days"]) == (2, 1)
    # los tres días excluidos son impares (ceros): sin ellos quedan 77 días y los mismos 40 con venta
    assert prof["adi"] == pytest.approx(77 / 40)
    assert prof["nonzero_days"] == 40 and prof["history_days"] == 80, "la historia es el rango calendario; solo la proporción de ceros los excluye"


def test_closures_by_scope_and_precedence_between_the_statuses():
    two = hand_data(branches=(1, 2))
    closures = pd.DataFrame([
        {"branch_id": np.nan, "starts_on": day(30), "ends_on": day(31), "code": "feriado-empresa"},     # scope tenant: toda la empresa
        {"branch_id": 2, "starts_on": day(50), "ends_on": day(50), "code": "inventario-s2"},            # scope branch
    ])
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(31), "gap_to": day(31), "reason": "unknown", "basis": "declared"}])
    inventory = pd.DataFrame([{"branch_id": 1, "product_id": 1, "ds": day(k), "on_hand": 0.0, "reserved": 0.0, "in_transit": 0.0, "unit_cost": 1.0}
                              for k in (30, 60)])
    panel = build_panel(replace(two, closures=closures, coverage_gaps=gap, inventory=inventory))
    assert status_of(panel, 1, 30) == ("closed", "declared") and status_of(panel, 2, 30) == ("closed", "declared")
    assert status_of(panel, 2, 50) == ("closed", "declared") and status_of(panel, 1, 50)[0] == "sale", "el cierre de sucursal solo afecta a esa sucursal"
    assert status_of(panel, 1, 31) == ("missing_source", "declared"), "missing_source > closed cuando coinciden"
    assert status_of(panel, 2, 31) == ("closed", "declared")
    assert status_of(panel, 1, 60)[0] == "stockout_censored", "el quiebre solo gana a sale/zero_confirmed"
    # cierre + quiebre el mismo día: closed
    both = build_panel(replace(two, closures=closures, inventory=inventory.iloc[:1]))  # quiebre el día 30, que además es cierre
    assert status_of(both, 1, 30)[0] == "closed"
    # un cierre cancelado no llega aquí (el repositorio lo filtra); uno de otra sucursal inexistente se ignora sin romper
    ghost = build_panel(replace(two, closures=pd.DataFrame([{"branch_id": 99, "starts_on": day(10), "ends_on": day(12), "code": "x"}])))
    assert set(ghost.df["day_status"].unique()) == {"sale"}


def test_days_outside_the_declared_coverage_of_a_branch_are_missing_source():
    """Antes de complete_from o después de complete_through, de la empresa o de la sucursal (también una cerrada)."""
    data = hand_data(branches=(1, 2))
    coverage = {"sales": {"declared": True, "basis": "declared", "company_complete_through": day(79).date().isoformat(),
                          "complete_through": day(60).date().isoformat(), "complete_from": day(10).date().isoformat(),
                          "branch_complete_through": {"2": day(60).date().isoformat()}, "branch_complete_from": {"2": day(20).date().isoformat()}}}
    panel = build_panel(replace(data, coverage=coverage))
    assert status_of(panel, 1, 70)[0] == "sale", "la sucursal 1 hereda la cobertura de la empresa (hasta el día 79)"
    assert status_of(panel, 2, 61) == ("missing_source", "declared") and status_of(panel, 2, 60)[0] == "sale"
    assert status_of(panel, 2, 15) == ("missing_source", "declared") and status_of(panel, 2, 20)[0] == "sale"
    assert status_of(panel, 1, 5) == ("missing_source", "declared") and status_of(panel, 1, 10)[0] == "sale"
    inferred = build_panel(replace(data, coverage={"sales": {**coverage["sales"], "basis": "inferred"}}))
    assert status_of(inferred, 2, 61) == ("missing_source", "inferred"), "una cobertura inferida por un conector conserva su base"


def test_a_source_gap_expands_to_the_branches_that_source_feeds_or_to_all_of_them():
    data = hand_data(branches=(1, 2, 3), skip=tuple((b, day(45).strftime("%Y-%m-%d")) for b in (1, 2, 3)))
    gap = pd.DataFrame([{"data_source_id": 7, "branch_id": np.nan, "gap_from": day(45), "gap_to": day(45), "reason": "integration_outage", "basis": "inferred"}])
    by_source = build_panel(replace(data, coverage_gaps=gap, source_branches={7: [1, 2]}))
    assert status_of(by_source, 1, 45) == ("missing_source", "inferred") and status_of(by_source, 2, 45)[0] == "missing_source"
    assert status_of(by_source, 3, 45)[0] == "zero_confirmed", "la rama 3 la alimenta otra fuente: sin declarar sigue siendo cero"
    assert by_source.day_summary["notes"]["gaps_expanded_by_source"] == 1
    unknown = build_panel(replace(data, coverage_gaps=gap))  # sin mapa de fuente se aplica a toda la empresa (conservador)
    assert all(status_of(unknown, b, 45)[0] == "missing_source" for b in (1, 2, 3))
    assert unknown.day_summary["notes"]["gaps_applied_to_all_branches"] == 1


def test_the_inferred_rule_marks_a_day_without_any_line_in_a_regular_branch():
    """Mediana de líneas diarias de los 28 días previos ≥ 5 y ninguna línea ese día ⇒ missing_source con base inferred."""
    data = hand_data(skip=((1, day(50).strftime("%Y-%m-%d")),), lines=4)       # 2 productos × 4 líneas = 8 líneas diarias en la sucursal
    off = build_panel(data)                                                     # por defecto de build_panel: solo lo declarado
    assert status_of(off, 1, 50) == ("zero_confirmed", None)
    on = build_panel(data, DayRules())
    assert status_of(on, 1, 50) == ("missing_source", "inferred") and status_of(on, 1, 50, product=2) == ("missing_source", "inferred")
    assert status_of(on, 1, 49)[0] == "sale" and status_of(on, 1, 51)[0] == "sale"
    row = on.df[(on.df["product_id"] == 1) & (on.df["ds"] == day(50))].iloc[0]
    assert row["units"] == 0 and row["y"] == 5.0 and row["stockout"] == False  # noqa: E712 - imputado, no es un quiebre de inventario
    assert on.day_summary["branch_days"]["missing_source_engine_inferred"] == 1 and on.day_summary["series_days"]["missing_source"] == 2
    # umbral configurable: la mediana (8 líneas) no alcanza 9; la regla puede apagarse; la ventana es configurable
    assert status_of(build_panel(data, DayRules(infer_min_median_lines=9)), 1, 50)[0] == "zero_confirmed"
    assert status_of(build_panel(data, DayRules(infer_min_median_lines=8)), 1, 50)[0] == "missing_source"
    assert status_of(build_panel(data, DayRules(infer_gaps=False)), 1, 50)[0] == "zero_confirmed"
    assert status_of(build_panel(data, DayRules(infer_lookback_days=7)), 1, 50)[0] == "missing_source"


def test_the_inferred_rule_needs_regular_history_and_ignores_irregular_branches():
    # sucursal que acaba de abrir (menos de la mitad de la ventana con datos): no se infiere nada
    young = hand_data(n_days=80, skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in range(0, 66)) + ((1, day(70).strftime("%Y-%m-%d")),))
    assert status_of(build_panel(young, DayRules()), 1, 70)[0] == "zero_confirmed"
    # sucursal de pocas líneas (mediana 2): un día vacío es plausible
    quiet = hand_data(lines=1, skip=((1, day(50).strftime("%Y-%m-%d")),))
    assert status_of(build_panel(quiet, DayRules()), 1, 50)[0] == "zero_confirmed"
    # una devolución ese día prueba que la fuente estaba viva: no se infiere
    alive = hand_data(skip=((1, day(50).strftime("%Y-%m-%d")),), returns_on=((1, day(50).strftime("%Y-%m-%d")),))
    assert status_of(build_panel(alive, DayRules()), 1, 50)[0] == "zero_confirmed"
    # sin columna `lines` (datos sin conteo de líneas) cada fila de venta cuenta como al menos una línea y la regla no falla
    few = hand_data(skip=((1, day(50).strftime("%Y-%m-%d")),))              # 2 filas diarias: mediana 2 < 5
    few = replace(few, sales=few.sales.drop(columns=["lines"]))
    assert status_of(build_panel(few, DayRules()), 1, 50)[0] == "zero_confirmed"
    many = hand_data(products=(1, 2, 3, 4, 5, 6), skip=((1, day(50).strftime("%Y-%m-%d")),))   # 6 filas diarias
    many = replace(many, sales=many.sales.drop(columns=["lines"]))
    assert status_of(build_panel(many, DayRules()), 1, 50)[0] == "missing_source"


def test_declared_closures_have_priority_over_the_inferred_rule_and_do_not_count_as_a_baseline_gap():
    """Un cierre semanal declarado es `closed` (no `missing_source`), y esos días no empujan la mediana de las siguientes semanas."""
    sundays = [k for k in range(80) if day(k).dayofweek == 6]
    data = hand_data(skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in sundays), lines=3)  # 2 × 3 = 6 líneas diarias
    undeclared = build_panel(data, DayRules())
    assert status_of(undeclared, 1, sundays[1])[0] == "zero_confirmed", "con menos de la mitad de la ventana con datos no se infiere"
    assert all(status_of(undeclared, 1, k)[0] == "zero_confirmed" for k in sundays), \
        "con la condición del mismo día de la semana, el cierre recurrente sin declarar es un cero observado"
    legacy = build_panel(data, DayRules(infer_same_weekday=False))
    assert all(status_of(legacy, 1, k)[0] == "missing_source" for k in sundays if k >= 15), \
        "sin ella (comportamiento anterior) se infería cada semana como fuente ausente"
    closures = pd.DataFrame([{"branch_id": 1, "starts_on": day(k), "ends_on": day(k), "code": f"domingo-{k}"} for k in sundays])
    declared = build_panel(replace(data, closures=closures), DayRules())
    assert all(status_of(declared, 1, k) == ("closed", "declared") for k in sundays)
    assert declared.day_summary["branch_days"]["missing_source_engine_inferred"] == 0
    assert declared.day_summary["series_days"]["closed"] == 2 * len(sundays)


def test_excluded_days_are_imputed_with_the_stockout_rule_and_counted_when_not_imputable():
    # un hueco en los primeros días no tiene 7 días limpios previos: queda con su valor observado y se cuenta aparte
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(3), "gap_to": day(4), "reason": "unknown", "basis": "declared"},
                        {"data_source_id": np.nan, "branch_id": 1, "gap_from": day(40), "gap_to": day(40), "reason": "unknown", "basis": "declared"}])
    sales = hand_data(skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in (3, 4, 40)), units=6.0)
    panel = build_panel(replace(sales, coverage_gaps=gap))
    assert panel.day_summary["excluded_days_not_imputable"] == 4 and panel.day_summary["excluded_days_imputed"] == 2
    early = panel.df[(panel.df["product_id"] == 1) & panel.df["ds"].isin([day(3), day(4)])]
    assert (early["y"] == 0).all(), "sin historia suficiente no se inventa un nivel"
    assert (panel.df.loc[(panel.df["product_id"] == 1) & (panel.df["ds"] == day(40)), "y"] == 6.0).all()
    # los días excluidos no alimentan el promedio con que se imputan los siguientes
    assert (panel.df.loc[(panel.df["product_id"] == 1) & (panel.df["ds"] == day(41)), "y"] == 6.0).all()


def test_lightgbm_weight_is_zero_on_excluded_days():
    outage = tuple((1, day(k).strftime("%Y-%m-%d")) for k in range(40, 44))
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(40), "gap_to": day(43), "reason": "unknown", "basis": "declared"}])
    data = hand_data(skip=outage, coverage_gaps=gap)
    panel = build_panel(data)
    fb = FeatureBuilder(panel.df, data.branches.assign(region="sierra"), pd.DataFrame(), panel.cutoff.date())
    frame, _ = fb.training_frame(panel.cutoff, panel.profile["unique_id"].tolist())
    excluded = panel.df.loc[panel.df["day_status"].isin(EXCLUDED_STATUSES), ["unique_id", "ds"]]
    w = frame.merge(excluded.assign(_x=1), on=["unique_id", "ds"], how="left")
    assert (w.loc[w["_x"] == 1, "weight"] == 0).all() and (w.loc[w["_x"] != 1, "weight"] == 1).all()


def test_backtest_rows_flag_excluded_days_so_metrics_never_score_them():
    """Las filas del backtest marcan como no evaluable (`stockout`) todo día missing_source o closed, con su `day_status`."""
    company = generate_company("ferreteria_andina", seed=42, n_products=5, start="2026-01-01")
    data = tenant_data_from_synthetic(company)
    cutoff = pd.Timestamp(data.cutoff)
    c0 = cutoff - pd.Timedelta(days=60)
    first = c0 + pd.Timedelta(days=5)
    branch = int(data.branches["branch_id"].iat[0])
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": branch, "gap_from": first, "gap_to": first + pd.Timedelta(days=3),
                         "reason": "integration_outage", "basis": "declared"}])
    closures = pd.DataFrame([{"branch_id": np.nan, "starts_on": first + pd.Timedelta(days=10), "ends_on": first + pd.Timedelta(days=10), "code": "cierre"}])
    sales = data.sales[~((data.sales["branch_id"] == branch) & data.sales["ds"].between(first, first + pd.Timedelta(days=3)))]
    flagged = replace(data, sales=sales.reset_index(drop=True), coverage_gaps=gap, closures=closures)
    panel = build_panel(flagged)
    plan = BacktestPlan(horizon=30, step=7, cutoffs=[c0])
    fb = FeatureBuilder(panel.df, flagged.branches, flagged.promotions, panel.cutoff.date())
    bt = run_backtest(panel, fb, plan, ["seasonal_naive", "window_average"], seed=42, n_jobs=1, use_lgbm=False)
    miss = bt[bt["day_status"] == "missing_source"]
    shut = bt[bt["day_status"] == "closed"]
    assert len(miss) and len(shut), "los días evaluados incluyen el hueco y el cierre"
    assert set(miss["ds"]) <= set(pd.date_range(first, first + pd.Timedelta(days=3)))
    assert miss["stockout"].all() and shut["stockout"].all(), "no evaluables"
    assert not bt.loc[~bt["day_status"].isin(["missing_source", "closed", "stockout_censored"]), "stockout"].any()
    cat = flagged.products.set_index("product_id")["category_id"]
    scored = with_keys(bt, cat)
    assert not scored["day_status"].isin(EXCLUDED_STATUSES).any() and not scored["stockout"].any()
    detail = exclusion_detail(bt[bt["family"] == "seasonal_naive"])
    assert detail["missing_source_days"] == int((miss["family"] == "seasonal_naive").sum()) > 0
    assert detail["closed_days"] == int((shut["family"] == "seasonal_naive").sum()) > 0
    assert detail["stockout_days"] == int(((bt["family"] == "seasonal_naive") & (bt["day_status"] == "stockout_censored")).sum())
    # los reales de los días excluidos son 0 por falta de datos: si se contaran, sesgarían el WAPE; al excluirlos no cuentan
    assert (miss["actual"] == 0).all()


def test_the_pipeline_reports_excluded_days_in_limitations_manifest_summary_and_metrics():
    company = generate_company("ferreteria_andina", seed=42, n_products=6, start="2025-07-01")
    data = tenant_data_from_synthetic(company)
    cutoff = pd.Timestamp(data.cutoff)
    branch = int(data.branches["branch_id"].iat[0])
    # hueco declarado dentro del período final reservado (90 días antes del corte) de una sucursal
    start = cutoff - pd.Timedelta(days=40)
    sales = data.sales[~((data.sales["branch_id"] == branch) & data.sales["ds"].between(start, start + pd.Timedelta(days=4)))]
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": branch, "gap_from": start, "gap_to": start + pd.Timedelta(days=4),
                         "reason": "integration_outage", "basis": "declared"}])
    res = run_pipeline(replace(data, sales=sales.reset_index(drop=True), coverage_gaps=gap), horizon=90, seed=42, n_jobs=4)
    codes = {x["code"] for x in res.limitations}
    assert "days_missing_source" in codes and "days_missing_source_inferred" in codes, "el hueco declarado y los cierres inferidos del sintético"
    msg = next(x["message"] for x in res.limitations if x["code"] == "days_missing_source")
    assert "imputaron" in msg and "no son ceros" in msg
    ds = res.panel.day_summary
    assert res.summary["day_status"]["series_days"] == ds["series_days"] and res.manifest["day_status"]["series_days"] == ds["series_days"]
    assert ds["series_days_by_basis"]["missing_source:declared"] > 0 and ds["series_days_by_basis"]["missing_source:inferred"] > 0
    assert res.manifest["knowledge"]["as_of_windows_active"] is False, "sin versiones de documentos no hay ventanas as-of"
    assert res.manifest["knowledge"]["source"]["versions"] is False
    assert "stockout, missing_source, closed" in res.manifest["label_maturity"]["censored_days_excluded"] if res.manifest["label_maturity"] else True
    main = [e for e in res.evaluations if e["evaluation_type"] == "backtest" and e["model_family"] == "selection" and e["level"] == "overall"][0]
    ex = main["metrics"]["sample"]["exclusions"]
    assert {"stockout_days", "missing_source_days", "closed_days"} <= set(ex)
    assert ex["missing_source_days"] > 0
    assert any(i["code"] == "missing_source_days" for i in res.readiness["issues"]) and res.readiness["day_status"] == ds
    # el hash de entrenamiento distingue una clasificación distinta: el mismo hueco sin declarar ni inferir queda como ceros observados
    from predictily_engine.pipeline.core import training_data_hash

    holes = replace(data, sales=sales.reset_index(drop=True))
    assert training_data_hash(build_panel(holes, DayRules(infer_gaps=False))) != training_data_hash(build_panel(holes, DayRules()))
    assert training_data_hash(build_panel(replace(holes, coverage_gaps=gap), DayRules())) == res.training_data_hash


def test_the_inferred_rule_is_configurable_and_active_by_default():
    core = CORE_DEFAULTS["demand"]
    assert core["day_status_infer_gaps"] is True and core["day_status_infer_min_median_lines"] == 5 and core["day_status_infer_lookback_days"] == 28
    rules = DayRules.from_config(resolve_config("demand", {}, None)[0])
    assert rules == DayRules(True, 5.0, 28)
    tuned = DayRules.from_config(resolve_config("demand", {"cores": {"demand": {"day_status_infer_gaps": False, "day_status_infer_min_median_lines": 12}}}, None)[0])
    assert tuned.infer_gaps is False and tuned.infer_min_median_lines == 12.0
    errors = validate_overrides("demand", {"day_status_infer_min_median_lines": 0, "day_status_infer_lookback_days": 3, "day_status_infer_gaps": "si"})
    assert {e["key"] for e in errors} == {"day_status_infer_min_median_lines", "day_status_infer_lookback_days", "day_status_infer_gaps"}


def test_a_weekday_the_branch_never_sells_is_a_zero_not_a_source_gap():
    """Distribuidora por rutas que no vende los domingos: con la condición del mismo día de la semana, el domingo vacío es un cero
    observado (antes se marcaba sin fuente cada semana y se imputaba como un día normal); una caída real un martes sigue inferida."""
    sundays = [k for k in range(84) if day(k).dayofweek == 6]
    tuesday = next(k for k in range(56, 84) if day(k).dayofweek == 1)
    data = hand_data(n_days=84, lines=4, skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in sundays) + ((1, day(tuesday).strftime("%Y-%m-%d")),))
    late_sunday = next(k for k in sundays if k > tuesday)
    on = build_panel(data, DayRules())
    assert status_of(on, 1, late_sunday) == ("zero_confirmed", None)
    assert all(status_of(on, 1, k)[0] == "zero_confirmed" for k in sundays), "ningún domingo es una caída de la fuente"
    assert status_of(on, 1, tuesday) == ("missing_source", "inferred"), "un martes sin líneas sigue siendo un hueco inferido"
    row = on.df[(on.df["product_id"] == 1) & (on.df["ds"] == day(late_sunday))].iloc[0]
    assert row["y"] == 0.0, "el domingo entrena como cero observado, no imputado con un día normal"
    off = build_panel(data, DayRules(infer_same_weekday=False))                 # comportamiento anterior
    assert status_of(off, 1, late_sunday) == ("missing_source", "inferred")
    assert status_of(off, 1, tuesday) == ("missing_source", "inferred")
    # con la mitad de las semanas previas (2 de 4) ya hay evidencia del mismo día: dos martes regulares bastan para inferir el hueco
    early_gap = hand_data(n_days=40, lines=4, skip=((1, day(19).strftime("%Y-%m-%d")),))   # día 19: martes con 2 martes previos
    assert day(19).dayofweek == 1 and status_of(build_panel(early_gap, DayRules()), 1, 19) == ("missing_source", "inferred")


def test_the_same_weekday_condition_is_configurable_and_on_by_default():
    assert CORE_DEFAULTS["demand"]["day_status_infer_same_weekday"] is True
    assert DayRules.from_config(resolve_config("demand", {}, None)[0]).infer_same_weekday is True
    tuned = DayRules.from_config(resolve_config("demand", {"cores": {"demand": {"day_status_infer_same_weekday": False}}}, None)[0])
    assert tuned.infer_same_weekday is False and tuned.as_dict()["infer_same_weekday"] is False
    errors = validate_overrides("demand", {"day_status_infer_same_weekday": "si"})
    assert {e["key"] for e in errors} == {"day_status_infer_same_weekday"}


def test_profile_at_keeps_the_day_columns_for_past_cutoffs():
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(40), "gap_to": day(42), "reason": "unknown", "basis": "declared"}])
    data = hand_data(skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in (40, 41, 42)), coverage_gaps=gap)
    panel = build_panel(data)
    past = profile_at(panel.df, day(41))
    assert set(past["missing_source_days"]) == {2}, "al corte del día 41 solo se habían visto dos días del hueco"
    assert set(profile_at(panel.df, day(79))["missing_source_days"]) == {3}


# ───────────────────────────── huella de datos: entradas y resultado de la clasificación de días ─────────────────────────────

def _day_snapshot(data):
    from predictily_engine.demand.snapshot import build_snapshot

    data = replace(data, products=data.products.assign(current_price=10.0, current_cost=4.0))
    panel = build_panel(data, DayRules())
    cfg = {"core": "demand", "config": {}, "run": {}}
    return build_snapshot(data, panel, cfg, pd.Timestamp(data.cutoff), None, data.coverage or {})


def test_the_data_fingerprint_changes_with_gaps_closures_the_source_map_and_the_rules():
    """Hallazgo MEDIUM de la revisión: la huella solo hasheaba la cobertura, así que declarar un cierre o un hueco (que cambia qué días se
    entrenan y miden) dejaba el mismo hash y `persist_snapshot` reutilizaba la fila. Ahora lo cubren `day_inputs` (entradas) y `day_status`
    (resultado). Todo lo demás de la huella no se mueve."""
    base = hand_data(n_days=100)
    closures = pd.DataFrame([{"branch_id": 1, "starts_on": day(50), "ends_on": day(52), "code": "cierre"}])
    gaps = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(60), "gap_to": day(62), "reason": "unknown", "basis": "declared"}])
    s0 = _day_snapshot(base)
    assert _day_snapshot(base).hash == s0.hash, "determinista"

    def moved(other):
        return {k for k in s0.components if s0.components[k] != other.components[k]}

    with_closure, with_gap = _day_snapshot(replace(base, closures=closures)), _day_snapshot(replace(base, coverage_gaps=gaps))
    assert with_closure.hash != s0.hash and with_gap.hash != s0.hash and with_closure.hash != with_gap.hash
    assert moved(with_closure) == {"day_inputs", "day_status"} and moved(with_gap) == {"day_inputs", "day_status"}
    assert with_closure.row_counts["day_status"] == 6 and with_gap.row_counts["day_status"] == 6, "2 productos × 3 días excluidos"
    # el orden de las filas declaradas no cambia la huella; el contenido sí
    two = pd.concat([closures, closures.assign(starts_on=day(70), ends_on=day(70), code="otro")], ignore_index=True)
    assert _day_snapshot(replace(base, closures=two)).hash == _day_snapshot(replace(base, closures=two.iloc[::-1].reset_index(drop=True))).hash
    assert _day_snapshot(replace(base, closures=two)).hash != with_closure.hash
    # la regla inferida (parámetros `day_status_*` en la configuración efectiva): si cambia lo inferido, cambia el resultado
    quiet = replace(base, sales=base.sales[~((base.sales["branch_id"] == 1) & (base.sales["ds"] == day(50)))])
    panel_on, panel_off = build_panel(quiet, DayRules()), build_panel(quiet, DayRules(infer_gaps=False))
    assert panel_on.day_summary["series_days"]["missing_source"] > 0 and panel_off.day_summary["series_days"]["missing_source"] == 0
    from predictily_engine.demand.snapshot import build_snapshot

    cfg = {"core": "demand", "config": {}, "run": {}}
    quiet = replace(quiet, products=quiet.products.assign(current_price=10.0, current_cost=4.0))
    on, off = (build_snapshot(quiet, p, cfg, pd.Timestamp(quiet.cutoff), None, {}) for p in (panel_on, panel_off))
    assert {k for k in on.components if on.components[k] != off.components[k]} == {"day_status"}
    # el mapa fuente → sucursales: el mismo hueco por fuente expande a otras sucursales
    two_branches = hand_data(n_days=100, branches=(1, 2))
    by_source = pd.DataFrame([{"data_source_id": 7, "branch_id": np.nan, "gap_from": day(60), "gap_to": day(62), "reason": "unknown", "basis": "inferred"}])
    only_one = _day_snapshot(replace(two_branches, coverage_gaps=by_source, source_branches={7: [1]}))
    both = _day_snapshot(replace(two_branches, coverage_gaps=by_source, source_branches={7: [1, 2]}))
    assert only_one.hash != both.hash and moved_between(only_one, both) == {"day_inputs", "day_status"}
    # mismo panel, distinto mapa (una fuente que alimenta una sucursal sin ventas en el panel): la entrada declarada sigue cambiando la huella
    unrelated = _day_snapshot(replace(two_branches, coverage_gaps=by_source, source_branches={7: [1, 99]}))
    assert moved_between(only_one, unrelated) == {"day_inputs"} and only_one.components["day_status"] == unrelated.components["day_status"]


def moved_between(a, b) -> set[str]:
    return {k for k in a.components if a.components[k] != b.components[k]}


# ───────────────────────────── huecos largos: el último nivel se propaga ─────────────────────────────

def test_a_long_declared_gap_carries_the_last_level_instead_of_feeding_zeros_to_the_statistical_models():
    """Hallazgo LOW de la revisión: `rolling(28, min_periods=7)` se agota tras 21 días de hueco; un hueco declarado de 60 días dejaba 38 días con
    y = 0 visibles a las familias estadísticas. Ahora los días de fuente ausente o cierre propagan el último nivel disponible."""
    skip = tuple((1, day(k).strftime("%Y-%m-%d")) for k in range(40, 100))
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(40), "gap_to": day(99), "reason": "integration_outage", "basis": "declared"}])
    data = hand_data(n_days=140, skip=skip, coverage_gaps=gap, units=5.0)
    panel = build_panel(data)
    in_gap = panel.df[panel.df["ds"].between(day(40), day(99))]
    assert len(in_gap) == 120 and (in_gap["units"] == 0).all() and (in_gap["day_status"] == "missing_source").all()
    assert (in_gap["y"] == 5.0).all(), "los 60 días del hueco (también los 38 posteriores a la ventana de 28) llevan el último nivel, no ceros"
    s = panel.day_summary
    assert s["excluded_days_imputed"] == 120 and s["excluded_days_not_imputable"] == 0
    assert s["excluded_days_carried_forward"] == 2 * 38, "2 productos × (60 − 22) días con la ventana de 28 días ya agotada"
    # la proporción de ceros del perfilado y las métricas no cambian: esos días siguen excluidos
    assert set(panel.profile["missing_source_days"]) == {60} and (panel.profile["adi"] == 1.0).all()
    # sin el hueco declarado el comportamiento de siempre: son ceros observados
    undeclared = build_panel(replace(data, coverage_gaps=pd.DataFrame()))
    assert (undeclared.df.loc[undeclared.df["ds"].between(day(40), day(99)), "y"] == 0).all()


def test_the_carried_level_is_the_last_one_before_the_gap_and_stockouts_keep_the_legacy_rule():
    # el nivel cambia antes del hueco: se propaga el último (7 días limpios previos al agotamiento), no el promedio de toda la historia
    rows = []
    for k in range(120):
        if 30 <= k < 100:
            continue
        rows.append({"branch_id": 1, "product_id": 1, "ds": day(k), "units": 2.0 if k < 20 else 8.0, "net_amount": 10.0, "lines": 3})
    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(30), "gap_to": day(99), "reason": "unknown", "basis": "declared"}])
    base = hand_data(n_days=120)
    data = replace(base, sales=pd.DataFrame(rows), coverage_gaps=gap, returns=base.returns.iloc[0:0])
    y = build_panel(data).df.set_index("ds")["y"]
    assert y[day(30)] == pytest.approx((8.0 * 10 + 2.0 * 18) / 28), "el primer día del hueco usa la media móvil de 28 días de siempre"
    assert y[day(51)] > 7.9 and y[day(52):day(99)].eq(8.0).all(), "agotada la ventana, el nivel queda en el último disponible (los 7 días limpios más recientes)"
    # un quiebre largo (> 28 días) conserva la regla de siempre: no es objeto de este cambio y se documenta como limitación
    inv = pd.DataFrame([{"branch_id": 1, "product_id": 1, "ds": day(k), "on_hand": 0.0, "reserved": 0.0, "in_transit": 0.0, "unit_cost": 1.0} for k in range(40, 90)])
    stock = hand_data(n_days=120, inventory=inv, skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in range(40, 90)))
    st = build_panel(stock).df
    one = st[st["product_id"] == 1].set_index("ds")
    assert one.loc[day(40):day(89), "stockout"].all() and (one.loc[day(40):day(89), "day_status"] == "stockout_censored").all()
    assert one.loc[day(50), "y"] == 5.0 and one.loc[day(89), "y"] == 0.0, \
        "quiebre: la ventana se agota y el día queda con su valor observado (0), como en el motor anterior (limitación documentada, §12)"


def test_days_that_cannot_be_imputed_emit_their_own_limitation_code():
    """Un hueco que abre la serie no tiene ningún nivel previo: sus días quedan con el valor observado y la limitación lo dice con su conteo."""
    from predictily_engine.pipeline.core import _day_limitations

    gap = pd.DataFrame([{"data_source_id": np.nan, "branch_id": 1, "gap_from": day(3), "gap_to": day(4), "reason": "unknown", "basis": "declared"}])
    panel = build_panel(replace(hand_data(skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in (3, 4))), coverage_gaps=gap))
    assert panel.day_summary["excluded_days_not_imputable"] == 4
    codes = {x["code"]: x["message"] for x in _day_limitations(panel.day_summary)}
    assert "days_not_imputable" in codes and "4 días-serie excluidos" in codes["days_not_imputable"] and "ningún nivel previo" in codes["days_not_imputable"]
    # sin días sin imputar no se emite
    clean = build_panel(replace(hand_data(skip=((1, day(50).strftime("%Y-%m-%d")),)), coverage_gaps=gap.assign(gap_from=day(50), gap_to=day(50))))
    assert clean.day_summary["excluded_days_not_imputable"] == 0 and "days_not_imputable" not in {x["code"] for x in _day_limitations(clean.day_summary)}
    long_gap = build_panel(hand_data(n_days=140, skip=tuple((1, day(k).strftime("%Y-%m-%d")) for k in range(40, 100)),
                                     coverage_gaps=gap.assign(gap_from=day(40), gap_to=day(99))))
    msg = next(x["message"] for x in _day_limitations(long_gap.day_summary) if x["code"] == "days_missing_source")
    assert "76 de ellos con el último nivel conocido por huecos de más de 21 días" in msg
