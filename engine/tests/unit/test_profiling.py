import numpy as np
import pandas as pd

from predictily_engine.profiling.series import build_panel, classify_demand


def test_demand_classification():
    rng = np.random.default_rng(0)
    smooth = rng.poisson(20, 120).astype(float)
    assert classify_demand(smooth, 120)[0] == "smooth"
    erratic = np.where(rng.random(120) < 0.95, rng.lognormal(2, 1.2, 120), 0)
    assert classify_demand(erratic, 120)[0] == "erratic"
    intermittent = np.zeros(120)
    intermittent[::5] = 3
    assert classify_demand(intermittent, 120)[0] == "intermittent"
    lumpy = np.zeros(120)
    lumpy[::6] = np.resize([1.0, 30.0], len(lumpy[::6]))  # tamaños muy variables
    assert classify_demand(lumpy, 120)[0] == "lumpy"
    assert classify_demand(smooth[:20], 20)[0] == "insufficient"


def test_panel_marks_stockouts_and_imputes(small_data):
    panel = build_panel(small_data)
    df = panel.df
    assert df["stockout"].any(), "los datos sintéticos incluyen quiebres de stock"
    # La demanda imputada nunca es menor que la venta observada.
    assert (df["y"] >= df["units"] - 1e-9).all()
    # Fuera de los días con quiebre, y == ventas.
    assert (df.loc[~df["stockout"], "y"] == df.loc[~df["stockout"], "units"]).all()
    # Serie completa por día, sin huecos.
    for _, s in df.groupby("unique_id"):
        assert s["ds"].diff().dropna().dt.days.eq(1).all()
    prof = panel.profile
    assert {"demand_class", "stockout_days", "censored_days", "is_active"} <= set(prof.columns)
    assert (prof["censored_days"] <= prof["stockout_days"]).all()


# ── F4 (validación 28-oct-2026): el perfil de un corte pasado no usa ventas posteriores ──

def _audit_case(extra_future: bool):
    """Caso de la auditoría: 2 días con venta al 1-abr; luego ventas en mayo, junio y julio."""
    from predictily_engine.pipeline.data import TenantData

    days = ["2026-01-01", "2026-02-01", "2026-05-01", "2026-06-01", "2026-07-01"]
    if extra_future:  # se perturba solo el futuro del corte: muchas más ventas después del 1-abr
        days += [d.date().isoformat() for d in pd.date_range("2026-04-02", "2026-07-30", freq="3D")]
    sales = pd.DataFrame({"branch_id": 1, "product_id": 1, "ds": pd.to_datetime(sorted(set(days))),
                          "units": 10.0, "net_amount": 20.0})
    return TenantData(tenant_id=1, settings={}, cutoff=pd.Timestamp("2026-07-31").date(),
                      branches=pd.DataFrame({"branch_id": [1], "region": ["costa"], "canton": ["guayaquil"]}),
                      products=pd.DataFrame({"product_id": [1], "category_id": [1], "is_active": [True]}),
                      categories=pd.DataFrame(), sales=sales)


def test_profile_at_past_cutoff_ignores_future_sales():
    from dataclasses import replace

    from predictily_engine.backtest.rolling import eligible_ids
    from predictily_engine.profiling.series import profile_at

    historical = pd.Timestamp("2026-04-01")
    data = _audit_case(extra_future=False)
    panel_full = build_panel(data)
    panel_past = build_panel(replace(data, cutoff=historical.date()))
    # El perfil completo sí la ve como serie con demanda (5 días con venta), pero al 1-abr solo
    # había 2 días con venta: insuficiente y NO elegible en ese corte.
    assert panel_full.profile.iloc[0]["demand_class"] != "insufficient"
    rebuilt = profile_at(panel_full.df, historical)
    assert rebuilt.iloc[0]["demand_class"] == "insufficient" and rebuilt.iloc[0]["nonzero_days"] == 2
    assert eligible_ids(panel_full, historical) == [] == eligible_ids(panel_past, historical)
    pd.testing.assert_frame_equal(rebuilt, panel_past.profile)

    # Reconstruir el perfil tras perturbar el futuro no lo cambia.
    perturbed = build_panel(_audit_case(extra_future=True))
    assert perturbed.profile.iloc[0]["nonzero_days"] > panel_full.profile.iloc[0]["nonzero_days"]
    pd.testing.assert_frame_equal(profile_at(perturbed.df, historical), rebuilt)
    assert eligible_ids(perturbed, historical) == []


def test_profile_at_final_cutoff_matches_panel_profile(small_data):
    from predictily_engine.profiling.series import profile_at

    panel = build_panel(small_data)
    active = set(small_data.products.loc[small_data.products["is_active"], "product_id"].astype(int))
    pd.testing.assert_frame_equal(profile_at(panel.df, panel.cutoff, active), panel.profile)
    # Un corte pasado solo contiene series que ya habían vendido y su historia hasta ese corte.
    past = panel.cutoff - pd.Timedelta(days=200)
    prof = profile_at(panel.df, past)
    assert (pd.to_datetime(prof["first_sale_date"]) <= past).all()
    assert (prof["history_days"] <= (past - pd.to_datetime(prof["first_sale_date"])).dt.days + 1).all()
