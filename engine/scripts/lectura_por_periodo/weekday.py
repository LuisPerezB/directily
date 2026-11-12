"""Picos entre semana: ¿de dónde tomar el reparto de la semana a días? Se conserva el total semanal del pronóstico (combinación) y se
reparte con cuatro perfiles: plano, común de la empresa, de la categoría en la sucursal y propio encogido (el del motor). Error por día.
Uso: python weekday.py <archivo.pkl>"""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from predictily_engine.models.registry import WEEKDAY_PROFILE_DAYS
from predictily_engine.models.statistical import common_weekday_profile, weekday_profile

from curve import BANDS, band_of, wape

M = "ets_lightgbm_mean"
d = pd.read_pickle(sys.argv[1])
bt, panel = d["bt"], d["panel"].copy()
panel["ds"] = pd.to_datetime(panel["ds"])
panel["y"] = panel["y"].astype(float).fillna(0.0)
meta = panel.drop_duplicates("unique_id")[["unique_id", "branch_id", "category_id"]]
rows = []
for w, g in bt[bt["family"] == M].groupby("window"):
    g = g[~g["stockout"].astype(bool)][["unique_id", "ds", "step", "actual", "yhat"]].copy()
    g["ds"] = pd.to_datetime(g["ds"])
    g = g[g["step"] <= 84]
    cutoff = g["ds"].min() - pd.Timedelta(days=1)
    train = panel[(panel["ds"] <= cutoff) & panel["unique_id"].isin(g["unique_id"].unique())][["unique_id", "ds", "y"]]
    g["mu"] = g["unique_id"].map(train[train["ds"] > cutoff - pd.Timedelta(days=90)].groupby("unique_id")["y"].mean()).fillna(0.0)
    g["week"] = (g["step"] - 1) // 7
    g["dow"] = g["ds"].dt.dayofweek
    g = g.merge(meta, on="unique_id", how="left")
    g["wk_total"] = g.groupby(["unique_id", "week"])["yhat"].transform("sum")
    g["wk_days"] = g.groupby(["unique_id", "week"])["yhat"].transform("size")
    common = common_weekday_profile(train)
    g["f_comun"] = common[g["dow"].to_numpy()]
    own = weekday_profile(train).rename(columns={"factor": "f_propio"})
    g = g.merge(own, on=["unique_id", "dow"], how="left")
    rec = train[train["ds"] > cutoff - pd.Timedelta(days=WEEKDAY_PROFILE_DAYS)].merge(meta, on="unique_id")
    rec["dow"] = rec["ds"].dt.dayofweek
    cat = rec.groupby(["branch_id", "category_id", "dow"])["y"].sum().rename("s").reset_index()
    cat["f_categoria"] = cat["s"] / cat.groupby(["branch_id", "category_id"])["s"].transform("mean").replace(0, np.nan)
    g = g.merge(cat[["branch_id", "category_id", "dow", "f_categoria"]], on=["branch_id", "category_id", "dow"], how="left")
    g["f_categoria"] = g["f_categoria"].fillna(g["f_comun"])
    g["f_propio"] = g["f_propio"].fillna(g["f_comun"])
    g["f_plano"] = 1.0
    for k in ("plano", "comun", "categoria", "propio"):
        s = g.groupby(["unique_id", "week"])[f"f_{k}"].transform("sum")  # normaliza con los días evaluables de esa semana
        g[k] = g["wk_total"] * g[f"f_{k}"] / s.replace(0, np.nan)
    g["window"] = w
    rows.append(g)
r = pd.concat(rows, ignore_index=True)
r["band"] = r["mu"].map(band_of)
print(f"== {sys.argv[1]} | perfil común (lun…dom) último corte: {[round(float(x), 2) for x in common]}")
print(
    f"   {'tramo':8s} {'series':>6s} | error por día con reparto: {'modelo':>7s} {'plano':>7s} {'común':>7s} {'categ.':>7s} {'propio':>7s} | total diario: plano / común / categ. / propio"
)
for band in [b[2] for b in BANDS] + ["TODAS"]:
    f = r if band == "TODAS" else r[r["band"] == band]
    if f.empty or f["actual"].sum() == 0:
        continue
    tot = f.groupby(["window", "ds"])[["actual", "plano", "comun", "categoria", "propio"]].sum()
    print(
        f"   {band:8s} {f['unique_id'].nunique():6d} | {'':27s} {wape(f['actual'], f['yhat']):7.3f} "
        + " ".join(f"{wape(f['actual'], f[k]):7.3f}" for k in ("plano", "comun", "categoria", "propio"))
        + " | "
        + " / ".join(f"{wape(tot['actual'], tot[k]):.3f}" for k in ("plano", "comun", "categoria", "propio"))
    )
