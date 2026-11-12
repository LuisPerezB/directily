"""Ejecuta el motor (variante «todo») sobre empresas demo (sintéticas, semilla 42) y guarda el backtest para análisis sin conexión.
Uso: python demo_run.py <dir_salida> <slug>..."""

import sys
import warnings
from dataclasses import replace

warnings.filterwarnings("ignore")
import pandas as pd
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.selection.selector import selected_rows
from predictily_engine.synthetic import generate_company

CORES = {
    "promotion": {"legacy_selection_metric": "total_day"},
    "demand": {"weekday_profile_models": True, "holiday_adjustment_models": True, "ensemble_models": True},
}
out_dir = sys.argv[1]
for slug in sys.argv[2:]:
    data = tenant_data_from_synthetic(generate_company(slug, seed=42))
    data = replace(data, settings={**(data.settings or {}), "cores": CORES})
    res = run_pipeline(data, horizon=90, seed=42, n_jobs=4)
    bt = res.backtest
    hold = bt[bt["is_holdout"] & ~bt["stockout"].astype(bool)]
    sel = selected_rows(hold, res.panel.profile, res.evaluation_selection or res.selection)
    payload = {"bt": bt, "panel": res.panel.df, "profile": res.panel.profile, "served": sel, "summary": res.summary}
    pd.to_pickle(payload, f"{out_dir}/{slug}.pkl")
    print(slug, bt.shape, list(bt.columns), list(res.panel.df.columns), flush=True)
