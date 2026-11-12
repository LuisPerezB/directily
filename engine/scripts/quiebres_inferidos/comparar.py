"""Compara dos corridas del motor sobre los mismos datos: sin y con la inferencia de quiebres (archivos de `favorita_run.py`).

Sin inventario no hay verdad contra la que medir: se compara lo servido en el período final (a) en todos los días, (b) solo en los días
que la inferencia NO marcó como quiebre (si los marcados son quiebres reales, esta es la medida correcta) y (c) en las series con algún
quiebre inferido en su historia de entrenamiento.
Uso: python comparar.py <sin.pkl> <con.pkl>"""

import sys

import numpy as np
import pandas as pd


def wape(a, p) -> float:
    a, p = np.asarray(a, float), np.asarray(p, float)
    return float(np.abs(a - p).sum() / np.abs(a).sum()) if np.abs(a).sum() else float("nan")


def line(label: str, g: pd.DataFrame) -> None:
    out = []
    for col in ("yhat_sin", "yhat_con"):
        wk = g.assign(b=(g["step"] - 1) // 7).groupby(["unique_id", "b"])[["actual", col]].sum()
        tot = g.groupby("ds")[["actual", col]].sum()
        bias = float((g[col] - g["actual"]).sum() / g["actual"].sum())
        out.append(f"{wape(g['actual'], g[col]):.4f}  {wape(wk['actual'], wk[col]):.4f}  {wape(tot['actual'], tot[col]):.4f}  {bias:+.3f}")
    print(f"    {label:52s} {g['unique_id'].nunique():5d} {len(g):7,d} | {out[0]} | {out[1]}")


off, on = pd.read_pickle(sys.argv[1]), pd.read_pickle(sys.argv[2])
info = on["summary"]["day_status"]["stockout_inference"]
print(f"inferencia: {info}")
a = off["served"][["unique_id", "ds", "step", "actual", "yhat"]].rename(columns={"yhat": "yhat_sin"})
b = on["served_all"][["unique_id", "ds", "yhat", "stockout", "day_status"]].rename(columns={"yhat": "yhat_con"})
r = a.merge(b, on=["unique_id", "ds"])
r["flag"] = r["stockout"].astype(bool) & (r["day_status"].astype(str) == "stockout_censored")
panel = on["panel"]
start = r["ds"].min()
hist = panel[(panel["ds"] < start) & panel["stockout"].astype(bool) & (panel["day_status"].astype(str) == "stockout_censored")]
affected = set(hist["unique_id"].unique())
recent = set(hist.loc[hist["ds"] >= start - pd.Timedelta(days=28), "unique_id"].unique())
print(f"período final: {r['unique_id'].nunique()} series, {len(r):,} días; marcados quiebre en él: {int(r['flag'].sum()):,}")
print(f"series con quiebre inferido antes del período final: {len(affected)} ({len(recent)} en sus últimos 28 días)")
print(f"    {'':52s} series    días | sin: día  semana  total-día  sesgo | con: día  semana  total-día  sesgo")
line("todos los días", r)
line("días no marcados como quiebre", r[~r["flag"]])
line("series con quiebre inferido en su historia", r[~r["flag"] & r["unique_id"].isin(affected)])
line("  … con quiebre en los 28 días previos", r[~r["flag"] & r["unique_id"].isin(recent)])
line("series sin quiebre inferido en su historia", r[~r["flag"] & ~r["unique_id"].isin(affected)])
f = r[r["flag"]]
if len(f):
    print(
        f"    en los días marcados: venta {f['actual'].sum():,.0f}; pronóstico sin {f['yhat_sin'].sum():,.0f}, con {f['yhat_con'].sum():,.0f} "
        f"({f['yhat_con'].sum() / r['yhat_con'].sum():.1%} de lo pronosticado)"
    )
