import numpy as np

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
