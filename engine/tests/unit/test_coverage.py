"""ADR-V2-05: cobertura declarada de la fuente de ventas. Fuera de la cobertura el dato es ausente, no cero."""

from datetime import date

import pandas as pd
import pytest

from predictily_engine.demand.coverage import SalesCoverage
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic import generate_company


def _rows(*rows):
    return pd.DataFrame(rows, columns=["data_source_id", "branch_id", "complete_from", "complete_through", "basis"])


def test_without_a_declaration_the_old_behavior_is_kept_and_the_limitation_is_reported():
    for empty in (SalesCoverage.from_rows(None), SalesCoverage.from_rows(_rows()),
                  SalesCoverage.from_rows(_rows((None, None, None, None, "declared")))):
        assert empty.declared is False and empty.through is None
        assert empty.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 26) and not empty.cutoff_limited
        assert [x["code"] for x in empty.limitations()] == ["coverage_undeclared"]
        assert empty.as_dict()["sales"]["basis"] == "undeclared"


def test_the_cutoff_never_exceeds_the_lowest_declared_complete_through():
    cov = SalesCoverage.from_rows(_rows((1, None, date(2025, 1, 1), date(2026, 10, 20), "declared"),
                                        (2, None, None, date(2026, 10, 18), "declared")))
    assert cov.declared and cov.through == date(2026, 10, 18)
    assert cov.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 18) and cov.cutoff_limited
    codes = [x["code"] for x in cov.limitations()]
    assert codes == ["cutoff_limited_by_coverage"], "declarada: no es coverage_undeclared"
    # Un corte retroactivo anterior a la cobertura no se toca.
    cov2 = SalesCoverage.from_rows(_rows((1, None, None, date(2026, 10, 20), "declared")))
    assert cov2.limit_cutoff(date(2026, 9, 1)) == date(2026, 9, 1) and not cov2.cutoff_limited and cov2.limitations() == []


def test_a_lagging_branch_limits_the_cutoff_unless_it_is_a_declared_closure():
    rows = _rows((1, None, None, date(2026, 10, 26), "declared"), (1, 7, None, date(2026, 8, 1), "declared"))
    assert SalesCoverage.from_rows(rows).limit_cutoff(date(2026, 10, 26)) == date(2026, 8, 1)
    closed = SalesCoverage.from_rows(rows, active_branches={1, 2})  # la sucursal 7 está cerrada: su atraso no cuenta
    assert closed.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 26)


def test_sales_before_complete_from_are_dropped_so_the_series_starts_at_the_first_complete_sale():
    company = generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01")
    data = tenant_data_from_synthetic(company)
    first = data.sales["ds"].min()
    start = first + pd.Timedelta(days=20)
    cov = SalesCoverage.from_rows(_rows((1, None, start.date(), None, "declared")))
    cov.limit_cutoff(data.cutoff)
    out = cov.apply(data)
    assert out.sales["ds"].min() >= start and cov.dropped_before_from > 0
    panel = build_panel(out)
    assert panel.df["ds"].min() >= start, "antes de la cobertura no se rellena con ceros: la serie empieza después"
    assert "sales_before_complete_from_ignored" in [x["code"] for x in cov.limitations()]
    assert out.coverage["sales"]["complete_from"] == start.date().isoformat()


def test_a_branch_specific_complete_from_only_affects_that_branch():
    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01"))
    cutoff_branch = int(data.sales["branch_id"].min())
    start = data.sales["ds"].min() + pd.Timedelta(days=30)
    cov = SalesCoverage.from_rows(_rows((1, cutoff_branch, start.date(), None, "declared")))
    before_other = int((data.sales["branch_id"] != cutoff_branch).sum())
    out = cov.apply(data)
    assert out.sales.loc[out.sales["branch_id"] == cutoff_branch, "ds"].min() >= start
    assert int((out.sales["branch_id"] != cutoff_branch).sum()) == before_other


def test_days_after_the_cutoff_are_absent_not_zero_in_the_panel():
    company = generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01")
    full = tenant_data_from_synthetic(company)
    through = (full.sales["ds"].max() - pd.Timedelta(days=15)).date()
    limited = tenant_data_from_synthetic(company, cutoff=through)
    panel = build_panel(limited)
    assert panel.cutoff == pd.Timestamp(through) and panel.df["ds"].max() == pd.Timestamp(through)
    assert limited.sales["ds"].max() <= pd.Timestamp(through)
    # El mismo corte pedido sobre datos que sí llegan al final produce el mismo panel: lo posterior no influye.
    other = build_panel(tenant_data_from_synthetic(company, cutoff=through))
    pd.testing.assert_frame_equal(panel.df.reset_index(drop=True), other.df.reset_index(drop=True))


def test_declared_coverage_is_part_of_the_data_snapshot(small_data):
    from predictily_engine.demand.snapshot import build_snapshot

    panel = build_panel(small_data)
    cfg = {"core": "demand", "config": {}, "run": {}}
    kn = pd.Timestamp(small_data.cutoff)
    undeclared = SalesCoverage()
    undeclared.limit_cutoff(small_data.cutoff)
    declared = SalesCoverage.from_rows(_rows((1, None, None, small_data.cutoff, "declared")))
    declared.limit_cutoff(small_data.cutoff)
    s1 = build_snapshot(small_data, panel, cfg, kn, None, undeclared.as_dict())
    s2 = build_snapshot(small_data, panel, cfg, kn, None, declared.as_dict())
    assert s1.components["coverage"] != s2.components["coverage"]
    assert {k for k in s1.components if s1.components[k] != s2.components[k]} == {"coverage"}
    assert s1.hash != s2.hash
