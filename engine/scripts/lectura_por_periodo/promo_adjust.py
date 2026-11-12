"""Dos pruebas fuera del motor sobre una corrida con promociones (datos reales):

A. Ajuste de promociones para AutoETS (hoy solo LightGBM las ve): con la historia al corte se estima el alza de cada serie en días de
   promoción (encogida hacia la de su categoría), se descuenta del nivel de AutoETS la parte que ya trae por promociones recientes y
   se multiplica el pronóstico en los días del horizonte con promoción conocida. Se mide AutoETS ajustado y su promedio con LightGBM.
B. Productos que venden en pocos días de la semana: series cuya venta de las últimas 8 semanas se concentra en 1–2 días.
Uso: python promo_adjust.py <archivo.pkl> [días de anticipación con que se conoce la promoción; por defecto, siempre conocida]"""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from compare import family_rows, fmt, levels
from curve import wape

path = sys.argv[1]
lead = int(sys.argv[2]) if len(sys.argv) > 2 else None
d = pd.read_pickle(path)
panel = d["panel"].copy()
panel["ds"] = pd.to_datetime(panel["ds"])
meta = panel.drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id", "category_id"]]
flags = d["promo"].merge(meta, on=["branch_id", "product_id"])[["unique_id", "ds", "category_id"]].copy()
flags["ds"] = pd.to_datetime(flags["ds"])
flags = flags.sort_values(["unique_id", "ds"])
flags["run"] = flags.groupby("unique_id")["ds"].diff().dt.days.ne(1).cumsum()
flags["run_start"] = flags.groupby("run")["ds"].transform("min")
hist = (
    panel[["unique_id", "ds", "y", "category_id"]]
    .dropna(subset=["y"])
    .merge(flags[["unique_id", "ds"]].assign(promo=True), on=["unique_id", "ds"], how="left")
)
hist["promo"] = hist["promo"].fillna(False).astype(bool)

ets, lgb, mean0 = family_rows(d, "auto_ets"), family_rows(d, "lightgbm_global"), family_rows(d, "mean")
K_SHRINK, MIN_DAYS = 14, 5
out = []
for _, w in ets.groupby("window"):
    cutoff = w["ds"].min() - pd.Timedelta(days=1)
    tr = hist[(hist["ds"] <= cutoff) & (hist["ds"] > cutoff - pd.Timedelta(days=180))]
    g = tr.groupby(["unique_id", "promo"])["y"].agg(["mean", "size"]).unstack()
    cat = tr.groupby(["category_id", "promo"])["y"].mean().unstack()
    pooled = np.log((cat.get(True) / cat.get(False)).replace([np.inf, 0], np.nan)).fillna(0.0).clip(0, np.log(3))
    s = pd.DataFrame({"m1": g[("mean", True)], "m0": g[("mean", False)], "n1": g[("size", True)].fillna(0)})
    s["cat"] = meta.set_index("unique_id")["category_id"].reindex(s.index)
    own = np.log((s["m1"] / s["m0"]).where((s["n1"] >= MIN_DAYS) & (s["m0"] > 0) & (s["m1"] > 0)))
    prior = s["cat"].map(pooled).fillna(0.0)
    logu = ((s["n1"] * own.fillna(0.0) + K_SHRINK * prior) / (s["n1"] + K_SHRINK)).where(own.notna(), prior)
    u = np.exp(logu.clip(np.log(0.8), np.log(4)))
    recent = tr[tr["ds"] > cutoff - pd.Timedelta(days=28)].groupby("unique_id")["promo"].mean()
    known = flags[flags["ds"] > cutoff]
    if lead is not None:
        known = known[known["run_start"] <= cutoff + pd.Timedelta(days=lead)]
    x = w.merge(known[["unique_id", "ds"]].assign(promo=True), on=["unique_id", "ds"], how="left")
    x["promo"] = x["promo"].fillna(False).astype(bool)
    uu = x["unique_id"].map(u).fillna(1.0)
    p = x["unique_id"].map(recent).fillna(0.0)
    x["yhat"] = x["yhat"] / (1 + p * (uu - 1)) * np.where(x["promo"], uu, 1.0)
    out.append(x)
adj = pd.concat(out, ignore_index=True)
keys = ["unique_id", "window", "ds"]
mean1 = adj.merge(lgb[keys + ["yhat"]].rename(columns={"yhat": "lgb"}), on=keys)
mean1["yhat"] = (mean1["yhat"] + mean1["lgb"]) / 2
print(f"== {path} | promociones conocidas: {'siempre' if lead is None else f'{lead} días antes'}")
print(f"   alza estimada en días de promoción (mediana por serie): ×{float(u.median()):.2f}; percentil 90: ×{float(u.quantile(0.9)):.2f}")
for name, r in (
    ("AutoETS", ets),
    ("AutoETS ajustado", adj),
    ("LightGBM", lgb),
    ("promedio actual", mean0),
    ("promedio con AutoETS ajustado", mean1),
):
    print(f"   {name:30s} 4 ventanas, 28 d: {fmt(levels(r[r['step'] <= 28]))}")
    print(f"   {'':30s} período final   : {fmt(levels(r[r['is_holdout']]))}")
fl = flags[["unique_id", "ds"]].assign(promo=True)
for name, r in (("AutoETS", ets), ("AutoETS ajustado", adj), ("promedio actual", mean0), ("promedio con AutoETS ajustado", mean1)):
    x = r[r["step"] <= 28].drop(columns="promo", errors="ignore").merge(fl, on=["unique_id", "ds"], how="left")
    x["promo"] = x["promo"].fillna(False).astype(bool)
    txt = [
        f"{'con' if k else 'sin'} promoción: error {wape(f['actual'], f['yhat']):.3f} sesgo {float((f['yhat'] - f['actual']).sum() / f['actual'].sum()):+.3f}"
        for k, f in x.groupby("promo")
    ]
    print(f"   {name:30s} días " + "  |  ".join(txt))

# B. concentración semanal: parte de la venta de las últimas 8 semanas que cae en los 2 días de más venta
print("(B) productos que venden en pocos días de la semana (4 ventanas, primeros 28 días):")
fams = {
    f: family_rows(d, f)
    for f in ("mean", "lightgbm_global", "auto_ets", "weekday_average", "window_average_dow")
    if f == "mean" or f in set(d["bt"]["family"])
}
conc = []
for w, g in fams["mean"].groupby("window"):
    cutoff = g["ds"].min() - pd.Timedelta(days=1)
    tr = hist[(hist["ds"] <= cutoff) & (hist["ds"] > cutoff - pd.Timedelta(days=56))]
    by = tr.groupby(["unique_id", tr["ds"].dt.dayofweek])["y"].sum().unstack(fill_value=0.0)
    share = np.sort(by.to_numpy(), axis=1)[:, -2:].sum(axis=1) / by.sum(axis=1).replace(0, np.nan)
    conc.append(pd.DataFrame({"unique_id": by.index, "window": w, "top2": share.to_numpy()}))
conc = pd.concat(conc)
for lo, hi, lab in (
    (0, 0.45, "repartida (2 días < 45 %)"),
    (0.45, 0.6, "algo concentrada (45–60 %)"),
    (0.6, 0.8, "concentrada (60–80 %)"),
    (0.8, 1.01, "muy concentrada (≥ 80 %)"),
):
    ids = conc[(conc["top2"] >= lo) & (conc["top2"] < hi)][["unique_id", "window"]]
    line = []
    n_units = 0.0
    for f, r in fams.items():
        x = r[r["step"] <= 28].merge(ids, on=["unique_id", "window"])
        if len(x) and x["actual"].sum() > 0:
            v = levels(x)
            line.append(f"{f[:18]} {v[0]:.2f}/{v[1]:.2f}")
            n_units = x["actual"].sum() / r[r["step"] <= 28]["actual"].sum()
    print(f"   {lab:28s} series-ventana {len(ids):4d}  unidades {n_units:5.1%} | día/semana: " + "  ".join(line))
