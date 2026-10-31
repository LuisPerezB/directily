"""ADR-V2-05: cobertura declarada de la fuente de ventas. Fuera de la cobertura el dato es ausente, no cero."""

from datetime import date

import pandas as pd

from predictily_engine.demand.coverage import SalesCoverage
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic import generate_company


def _eff(through=None, from_=None, rule="declared_by_user", basis="declared"):
    """Cobertura efectiva tal como la devuelve `extensions.data_core.effective_coverage`."""
    if through is None and from_ is None:
        return {"entity": "sales", "complete_from": None, "complete_through": None, "rule": "none", "basis": None}
    return {"entity": "sales", "complete_from": from_, "complete_through": through, "rule": rule, "basis": basis}


def test_without_a_declaration_the_old_behavior_is_kept_and_the_limitation_is_reported():
    for empty in (SalesCoverage.from_effective(_eff()), SalesCoverage.from_effective(_eff(), {}, pd.DataFrame())):
        assert empty.declared is False and empty.through is None
        assert empty.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 26) and not empty.cutoff_limited
        assert [x["code"] for x in empty.limitations()] == ["coverage_undeclared"]
        assert empty.as_dict()["sales"]["basis"] == "undeclared"


def test_the_cutoff_never_exceeds_the_lowest_effective_complete_through():
    cov = SalesCoverage.from_effective(_eff(date(2026, 10, 20), date(2025, 1, 1)))
    assert cov.declared and cov.through == date(2026, 10, 20)
    assert cov.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 20) and cov.cutoff_limited
    assert [x["code"] for x in cov.limitations()] == ["cutoff_limited_by_coverage"], "declarada: no es coverage_undeclared"
    # Un corte retroactivo anterior a la cobertura no se toca.
    cov2 = SalesCoverage.from_effective(_eff(date(2026, 10, 20)))
    assert cov2.limit_cutoff(date(2026, 9, 1)) == date(2026, 9, 1) and not cov2.cutoff_limited and cov2.limitations() == []
    # Cobertura inferida por un conector: también es cobertura, con su base registrada.
    inferred = SalesCoverage.from_effective(_eff(date(2026, 10, 25), None, "min_over_sources", "inferred"))
    assert inferred.limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 25) and inferred.as_dict()["sales"]["basis"] == "inferred"


def test_a_lagging_branch_limits_the_cutoff():
    cov = SalesCoverage.from_effective(_eff(date(2026, 10, 26)), {7: _eff(date(2026, 8, 1))})
    assert cov.limit_cutoff(date(2026, 10, 26)) == date(2026, 8, 1)
    assert SalesCoverage.from_effective(_eff(date(2026, 10, 26)), {}).limit_cutoff(date(2026, 10, 26)) == date(2026, 10, 26)


def test_sales_before_complete_from_are_dropped_so_the_series_starts_at_the_first_complete_sale():
    company = generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01")
    data = tenant_data_from_synthetic(company)
    start = data.sales["ds"].min() + pd.Timedelta(days=20)
    cov = SalesCoverage.from_effective(_eff(None, start.date()))
    cov.limit_cutoff(data.cutoff)
    out = cov.apply(data)
    assert out.sales["ds"].min() >= start and cov.dropped_before_from > 0
    panel = build_panel(out)
    assert panel.df["ds"].min() >= start, "antes de la cobertura no se rellena con ceros: la serie empieza después"
    assert "sales_before_complete_from_ignored" in [x["code"] for x in cov.limitations()]
    assert out.coverage["sales"]["complete_from"] == start.date().isoformat()


def test_a_branch_specific_complete_from_only_affects_that_branch():
    data = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01"))
    branch = int(data.sales["branch_id"].min())
    start = data.sales["ds"].min() + pd.Timedelta(days=30)
    cov = SalesCoverage.from_effective(_eff(), {branch: _eff(None, start.date())})
    before_other = int((data.sales["branch_id"] != branch).sum())
    out = cov.apply(data)
    assert out.sales.loc[out.sales["branch_id"] == branch, "ds"].min() >= start
    assert int((out.sales["branch_id"] != branch).sum()) == before_other


def test_days_after_the_cutoff_are_absent_not_zero_in_the_panel():
    company = generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01")
    full = tenant_data_from_synthetic(company)
    through = (full.sales["ds"].max() - pd.Timedelta(days=15)).date()
    limited = tenant_data_from_synthetic(company, cutoff=through)
    panel = build_panel(limited)
    assert panel.cutoff == pd.Timestamp(through) and panel.df["ds"].max() == pd.Timestamp(through)
    assert limited.sales["ds"].max() <= pd.Timestamp(through)
    other = build_panel(tenant_data_from_synthetic(company, cutoff=through))
    pd.testing.assert_frame_equal(panel.df.reset_index(drop=True), other.df.reset_index(drop=True))


def test_declared_coverage_is_part_of_the_data_snapshot(small_data):
    from predictily_engine.demand.snapshot import build_snapshot

    panel = build_panel(small_data)
    cfg = {"core": "demand", "config": {}, "run": {}}
    kn = pd.Timestamp(small_data.cutoff)
    undeclared = SalesCoverage.from_effective(_eff())
    undeclared.limit_cutoff(small_data.cutoff)
    declared = SalesCoverage.from_effective(_eff(small_data.cutoff))
    declared.limit_cutoff(small_data.cutoff)
    s1 = build_snapshot(small_data, panel, cfg, kn, None, undeclared.as_dict())
    s2 = build_snapshot(small_data, panel, cfg, kn, None, declared.as_dict())
    assert {k for k in s1.components if s1.components[k] != s2.components[k]} == {"coverage"}
    assert s1.hash != s2.hash


def test_every_zero_day_in_the_panel_is_a_zero_observed_day_for_the_data_core():
    """Coherencia con `classify_day` del core de Datos: dentro de la cobertura efectiva un día sin ventas es cero
    OBSERVADO; nada fuera de ella entra al panel (sería ausente)."""
    from predictily_engine.extensions.data_core import classify_day

    company = generate_company("ferreteria_andina", seed=42, n_products=3, start="2026-06-01")
    data = tenant_data_from_synthetic(company)
    start = (data.sales["ds"].min() + pd.Timedelta(days=10)).date()
    through = (data.sales["ds"].max() - pd.Timedelta(days=12)).date()
    eff = _eff(through, start)
    cov = SalesCoverage.from_effective(eff)
    limited = cov.apply(tenant_data_from_synthetic(company, cutoff=cov.limit_cutoff(data.cutoff)))
    panel = build_panel(limited)
    sold = {(r.unique_id, r.ds) for r in panel.df[panel.df["units"] > 0].itertuples()}
    kinds = {classify_day(eff, r.ds.date(), (r.unique_id, r.ds) in sold) for r in panel.df.itertuples()}
    assert kinds == {"observed", "zero_observed"}, "ningún día del panel es 'absent'"
    assert classify_day(eff, through + pd.Timedelta(days=1), False) == "absent"
    assert classify_day(eff, start - pd.Timedelta(days=1), False) == "absent"
