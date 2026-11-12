"""Datos reales: (1) curva con horizonte operativo (primeros 28 días), (2) error según la distancia del pronóstico, (3) días con y sin
promoción, (4) regla por serie encogida hacia su tramo de venta. Uso: python extra.py <archivo.pkl>"""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from curve import BANDS, GRID, buckets, rows_from, wape

path = sys.argv[1]
M = "ets_lightgbm_mean"
r = rows_from(path, M)
d = pd.read_pickle(path)
names = [b[2] for b in BANDS] + ["TODAS"]
print(f"== {path}")
print("(1) horizonte operativo: solo los primeros 28 días de cada ventana (4 ventanas). Error leyendo por 1, 7, 14 y 28 días:")
r28 = r[r["step"] <= 28]
for band in names:
    f = r28 if band == "TODAS" else r28[r28["band"] == band]
    if f.empty or f["actual"].sum() == 0:
        continue
    vals = []
    for T in (1, 7, 14, 28):
        b = buckets(f, T)
        vals.append(wape(b["actual"], b["yhat"]))
    print(
        f"   {band:8s} series {f['unique_id'].nunique():4d} | día {vals[0]:.3f}  semana {vals[1]:.3f}  2 sem. {vals[2]:.3f}  4 sem. {vals[3]:.3f}  | día→semana ÷{vals[0] / vals[1]:.2f}"
    )
print("(2) error SEMANAL según qué tan lejos está la semana pronosticada (todas las ventanas):")
b7 = buckets(r, 7)
for lo, hi, lab in ((0, 0, "semana 1"), (1, 3, "semanas 2–4"), (4, 7, "semanas 5–8"), (8, 11, "semanas 9–12")):
    f = b7[(b7["b"] >= lo) & (b7["b"] <= hi)]
    print(
        f"   {lab:13s} error {wape(f['actual'], f['yhat']):.3f}   sesgo {float((f['yhat'] - f['actual']).sum() / f['actual'].sum()):+.3f}"
    )
if "promo" in d and len(d["promo"]):
    meta = d["panel"].drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id"]]
    p = d["promo"].copy()
    p["ds"] = pd.to_datetime(p["ds"])
    x = r.merge(meta, on="unique_id").merge(p, on=["branch_id", "product_id", "ds"], how="left")
    x["promo"] = x["promo"].notna()
    print("(3) días con promoción (el motor NO recibió las promociones en esta prueba):")
    for flag, f in x.groupby("promo"):
        print(
            f"   promoción={flag!s:5s} filas {len(f):7d} ({len(f) / len(x):.1%})  unidades {f['actual'].sum() / x['actual'].sum():.1%}  error diario {wape(f['actual'], f['yhat']):.3f}  sesgo {float((f['yhat'] - f['actual']).sum() / f['actual'].sum()):+.3f}"
        )
    wk = (
        x.assign(b=(x["step"] - 1) // 7)
        .groupby(["unique_id", "window", "b"])
        .agg(actual=("actual", "sum"), yhat=("yhat", "sum"), promo=("promo", "max"))
    )
    for flag, f in wk.groupby("promo"):
        print(
            f"   semana con algún día en promoción={flag!s:5s} error semanal {wape(f['actual'], f['yhat']):.3f}  sesgo {float((f['yhat'] - f['actual']).sum() / f['actual'].sum()):+.3f}"
        )
# (4) regla por serie con error encogido hacia el de su tramo (10 tramos por cuantiles de μ), decidida en ventanas de selección
print("(4) regla automática por serie, error propio encogido hacia el de su tramo de venta (N0 = unidades de confianza):")
sel, hold = r[~r["is_holdout"]], r[r["is_holdout"]]
mu_h = hold.groupby("unique_id")["mu"].first()
units = hold.groupby("unique_id")["actual"].sum()


def per_series(f, T):
    b = buckets(f, T)
    b["ae"] = (b["actual"] - b["yhat"]).abs()
    g = b.groupby("unique_id")[["ae", "actual"]].sum()
    g["mu"] = b.groupby("unique_id")["mu"].mean()
    return g


real = {}
for T in GRID:
    g = per_series(hold, T)
    real[T] = g["ae"] / g["actual"].replace(0, np.nan)
for N0 in (0, 300):
    for tol in (0.20, 0.25, 0.30):
        rec = pd.Series(0, index=mu_h.index)
        for T in reversed(GRID):
            g = per_series(sel, T)
            g = g[g["mu"] > 0]
            g["q"] = pd.qcut(g["mu"].rank(method="first"), 10, labels=False)
            seg = g.groupby("q")[["ae", "actual"]].sum()
            g["r_seg"] = g["q"].map(seg["ae"] / seg["actual"])
            est = (g["ae"] + N0 * g["r_seg"]) / (g["actual"] + N0)
            ok = (est <= tol).reindex(mu_h.index).fillna(False).to_numpy()
            rec[ok] = T
        parts = []
        for T in GRID + [0]:
            ids = rec.index[rec == T]
            lab = {1: "día", 7: "semana", 28: "4 sem.", 0: "ninguna"}[T]
            if len(ids) == 0:
                parts.append(f"{lab}: 0")
                continue
            u = units.reindex(ids).fillna(0)
            col = real[T if T else 28].reindex(ids)
            m = col.notna()
            parts.append(
                f"{lab}: {len(ids)} series, {u.sum() / units.sum():.0%} unid., error {float((col[m] * u[m]).sum() / u[m].sum()):.3f}, cumplen {float((col <= tol + 0.05).mean()):.0%}, μ≥{mu_h.reindex(ids).quantile(0.1):.1f}"
            )
        print(f"   N0={N0:3d} tol {tol:.0%} | " + " | ".join(parts))
