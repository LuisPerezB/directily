"""Compara varias corridas del motor sobre los mismos datos (p. ej. sin y con promociones, base y con interruptores).

Uso: python compare.py etiqueta=archivo.pkl [etiqueta=archivo.pkl ...]
Para cada corrida: error de lo servido en el período final, error por familia en todas las ventanas (primeros 28 días), sesgo en días con
y sin promoción y acierto de la regla de lectura por serie (tolerancia 25 %, error encogido hacia el tramo con N0 = 300 unidades)."""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from curve import BANDS, GRID, band_of, buckets, wape

KEYS = ["unique_id", "window", "ds"]
MEMBERS = ["auto_ets", "lightgbm_global"]


def family_rows(d, family):
    """Filas evaluables (paso ≤ 84) de una familia en todas las ventanas, con μ al corte; `mean` = promedio de AutoETS y LightGBM."""
    bt, panel = d["bt"], d["panel"]
    bt = bt[~bt["stockout"].astype(bool) & (bt["step"] <= 84)]
    cols = ["unique_id", "ds", "step", "window", "actual", "yhat", "is_holdout"]
    if family == "mean":
        parts = [bt.loc[bt["family"] == m, cols].set_index(KEYS) for m in MEMBERS]
        g = parts[0].join(parts[1]["yhat"].rename("other"), how="inner")
        g["yhat"] = (g["yhat"] + g["other"]) / 2
        g = g.drop(columns="other").reset_index()
    else:
        g = bt.loc[bt["family"] == family, cols].copy()
    g["ds"] = pd.to_datetime(g["ds"])
    hist = panel[["unique_id", "ds", "y"]].copy()
    hist["ds"] = pd.to_datetime(hist["ds"])
    hist["y"] = hist["y"].astype(float).fillna(0.0)
    out = []
    for _, w in g.groupby("window"):
        cutoff = w["ds"].min() - pd.Timedelta(days=1)
        level = hist[(hist["ds"] <= cutoff) & (hist["ds"] > cutoff - pd.Timedelta(days=90))].groupby("unique_id")["y"].mean()
        out.append(w.assign(mu=w["unique_id"].map(level).fillna(0.0)))
    r = pd.concat(out, ignore_index=True)
    r["band"] = r["mu"].map(band_of)
    return r


def levels(f):
    wk = f.assign(b=(f["step"] - 1) // 7).groupby(["unique_id", "window", "b"])[["actual", "yhat"]].sum()
    mo = f.assign(b=(f["step"] - 1) // 28).groupby(["unique_id", "window", "b"])[["actual", "yhat"]].sum()
    tot = f.groupby(["window", "ds"])[["actual", "yhat"]].sum()
    bias = float((f["yhat"] - f["actual"]).sum() / f["actual"].sum())
    return (
        wape(f["actual"], f["yhat"]),
        wape(wk["actual"], wk["yhat"]),
        wape(mo["actual"], mo["yhat"]),
        wape(tot["actual"], tot["yhat"]),
        bias,
    )


def fmt(v):
    return f"día {v[0]:.3f}  semana {v[1]:.3f}  4 sem. {v[2]:.3f}  total-día {v[3]:.3f}  sesgo {v[4]:+.3f}"


def rule(r, tol=0.25, n0=300):
    sel, hold = r[~r["is_holdout"]], r[r["is_holdout"]]
    mu_h = hold.groupby("unique_id")["mu"].first()
    units = hold.groupby("unique_id")["actual"].sum()

    def per_series(f, T):
        b = buckets(f, T)
        b["ae"] = (b["actual"] - b["yhat"]).abs()
        g = b.groupby("unique_id")[["ae", "actual"]].sum()
        g["mu"] = b.groupby("unique_id")["mu"].mean()
        return g

    rec = pd.Series(0, index=mu_h.index)
    real = {}
    for T in reversed(GRID):
        g = per_series(sel, T)
        g = g[g["mu"] > 0]
        g["q"] = pd.qcut(g["mu"].rank(method="first"), 10, labels=False)
        seg = g.groupby("q")[["ae", "actual"]].sum()
        est = (g["ae"] + n0 * g["q"].map(seg["ae"] / seg["actual"])) / (g["actual"] + n0)
        rec[(est <= tol).reindex(mu_h.index).fillna(False).to_numpy()] = T
        h = per_series(hold, T)
        real[T] = h["ae"] / h["actual"].replace(0, np.nan)
    parts, covered, hit = [], 0.0, 0.0
    for T in GRID + [0]:
        ids = rec.index[rec == T]
        lab = {1: "día", 7: "semana", 28: "4 sem.", 0: "ninguna"}[T]
        u = units.reindex(ids).fillna(0)
        share = float(u.sum() / units.sum())
        if T == 0 or len(ids) == 0:
            parts.append(f"{lab}: {len(ids)} series, {share:.0%} unid.")
            continue
        col = real[T].reindex(ids)
        ok = col <= tol + 0.05
        covered += share
        hit += float(u[ok].sum() / units.sum())
        m = col.notna()
        parts.append(
            f"{lab}: {len(ids)} series, {share:.0%} unid., error {float((col[m] * u[m]).sum() / u[m].sum()):.3f}, cumplen {float(ok.mean()):.0%}"
        )
    return (
        " | ".join(parts) + f" || con lectura fiable {covered:.0%} de las unidades; de ellas cumplió {hit / covered if covered else 0:.0%}"
    )


def main(args):
    for arg in args:
        label, path = arg.split("=", 1)
        d = pd.read_pickle(path)
        m = d["summary"]["metrics"]
        print(f"\n== {label}  ({path})")
        print(
            f"   resumen del motor (período final, lo servido): serie-día {m['selected_wape']:.4f}  total-día {m['total_selected_wape']:.4f}  cobertura 80 % {m['coverage_80']:.3f}"
        )
        print(f"   modelos servidos: {d['summary']['models']}")
        for fam in ("mean", "lightgbm_global", "auto_ets"):
            r = family_rows(d, fam)
            print(f"   {fam:16s} período final (84 d): {fmt(levels(r[r['is_holdout']]))}")
            print(f"   {'':16s} 4 ventanas, 28 d   : {fmt(levels(r[r['step'] <= 28]))}")
        r = family_rows(d, "mean")
        r28 = r[r["step"] <= 28]
        print("   promedio AutoETS+LightGBM por tramo de venta (4 ventanas, primeros 28 días):")
        for band in [b[2] for b in BANDS]:
            f = r28[r28["band"] == band]
            if len(f) and f["actual"].sum() > 0:
                print(f"      {band:8s} series {f['unique_id'].nunique():4d}  {fmt(levels(f))}")
        if "promo" in d and len(d["promo"]):
            meta = d["panel"].drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id"]]
            p = d["promo"].copy()
            p["ds"] = pd.to_datetime(p["ds"])
            for fam in ("mean", "lightgbm_global", "auto_ets"):
                x = family_rows(d, fam)
                x = x[x["step"] <= 28].merge(meta, on="unique_id").merge(p, on=["branch_id", "product_id", "ds"], how="left")
                x["promo"] = x["promo"].notna()
                txt = []
                for flag, f in x.groupby("promo"):
                    bias = float((f["yhat"] - f["actual"]).sum() / f["actual"].sum())
                    txt.append(f"{'con' if flag else 'sin'} promoción: error {wape(f['actual'], f['yhat']):.3f} sesgo {bias:+.3f}")
                print(f"   {fam:16s} días " + "  |  ".join(txt))
        print("   regla de lectura (tol 25 %): " + rule(r))


if __name__ == "__main__":
    main(sys.argv[1:])
