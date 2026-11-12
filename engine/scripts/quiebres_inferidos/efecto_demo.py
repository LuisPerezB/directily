"""¿Se acerca más el pronóstico a la demanda REAL con la inferencia de quiebres? Empresas demo (sintéticas): se conoce la demanda latente.

Tres ejecuciones del motor por empresa: con inventario (techo), sin inventario (lo que pasa hoy a una empresa sin esa historia) y sin
inventario con la inferencia encendida. Lo servido en el período final reservado se compara con la demanda real de TODOS sus días
(también los de quiebre, donde la venta no la refleja).
Uso: python efecto_demo.py <slug>..."""

import sys
import warnings
from dataclasses import replace

import numpy as np
import pandas as pd

from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.selection.selector import selected_rows
from predictily_engine.synthetic import generate_company

warnings.filterwarnings("ignore")
DEMAND = {"weekday_profile_models": True, "holiday_adjustment_models": True, "ensemble_models": True}
PROMOTION = {"legacy_selection_metric": "total_day"}


def wape(a, p) -> float:
    a, p = np.asarray(a, float), np.asarray(p, float)
    return float(np.abs(a - p).sum() / np.abs(a).sum()) if np.abs(a).sum() else float("nan")


def main(slug: str) -> None:
    company = generate_company(slug, seed=42)
    base = tenant_data_from_synthetic(company)
    truth = company.true_demand.copy()
    truth["unique_id"] = (
        "b" + truth["branch_code"].map(dict(zip(base.branches["code"], base.branches["branch_id"], strict=True))).astype(str)
        + "-p" + truth["product_sku"].map(dict(zip(base.products["sku"], base.products["product_id"], strict=True))).astype(str)
    )  # fmt: skip
    truth["ds"] = pd.to_datetime(truth["ds"])
    truth["real_stockout"] = truth["on_hand"] <= 0
    no_inventory = replace(base, inventory=base.inventory.iloc[0:0])
    arms = {
        "con inventario (techo)": (base, {}),
        "sin inventario": (no_inventory, {}),
        "sin inventario + inferencia": (no_inventory, {"stockout_inference": True}),
    }
    print(f"\n=== {slug}")
    print(f"    {'':30s} series   día  semana  total-día  sesgo | series con quiebre real reciente: día  semana  sesgo")
    for label, (data, extra) in arms.items():
        cores = {"demand": {**DEMAND, **extra}, "promotion": PROMOTION}
        res = run_pipeline(replace(data, settings={**(data.settings or {}), "cores": cores}), horizon=90, seed=42, n_jobs=4)
        hold = res.backtest[res.backtest["is_holdout"]]
        served = selected_rows(hold, res.panel.profile, res.evaluation_selection or res.selection)
        r = served[["unique_id", "ds", "step", "yhat"]].merge(truth[["unique_id", "ds", "demand", "real_stockout"]], on=["unique_id", "ds"])
        start = r["ds"].min()
        recent = truth[(truth["ds"] < start) & (truth["ds"] >= start - pd.Timedelta(days=180)) & truth["real_stockout"]]
        hit = r["unique_id"].isin(recent.groupby("unique_id").size().loc[lambda s: s >= 7].index)

        def row(g: pd.DataFrame) -> tuple[float, float, float, float]:
            wk = g.assign(b=(g["step"] - 1) // 7).groupby(["unique_id", "b"])[["demand", "yhat"]].sum()
            tot = g.groupby("ds")[["demand", "yhat"]].sum()
            bias = float((g["yhat"] - g["demand"]).sum() / g["demand"].sum())
            return wape(g["demand"], g["yhat"]), wape(wk["demand"], wk["yhat"]), wape(tot["demand"], tot["yhat"]), bias

        a, b = row(r), row(r[hit])
        info = (res.summary.get("day_status") or {}).get("stockout_inference") or {}
        print(
            f"    {label:30s} {r['unique_id'].nunique():6d} {a[0]:.3f}  {a[1]:.3f}  {a[2]:.3f}  {a[3]:+.3f} | "
            f"{r.loc[hit, 'unique_id'].nunique():4d} series: {b[0]:.3f}  {b[1]:.3f}  {b[3]:+.3f}"
            + (f" | días marcados {info.get('series_days')}" if info else ""),
            flush=True,
        )


if __name__ == "__main__":
    for name in sys.argv[1:]:
        main(name)
