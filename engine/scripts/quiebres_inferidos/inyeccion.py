"""Prueba con verdad conocida sobre ventas reales: se borran ventas en rachas simuladas (quiebres) antes del período final y se mide
cuánto empeora el pronóstico y cuánto recupera la inferencia. El período final no se toca: se evalúa contra la venta real.

Corridas de `favorita_run.py` (variante `todo`, con y sin el sufijo `+quiebres`, con y sin semilla de quiebres simulados).
Uso: python inyeccion.py <original_sin.pkl> <original_con.pkl> <simulado_sin.pkl> <simulado_con.pkl>"""

import sys

import numpy as np
import pandas as pd


def wape(a, p) -> float:
    a, p = np.asarray(a, float), np.asarray(p, float)
    return float(np.abs(a - p).sum() / np.abs(a).sum()) if np.abs(a).sum() else float("nan")


def served(run: dict) -> pd.DataFrame:
    rows = run.get("served_all", run["served"])
    return rows[["unique_id", "ds", "yhat"]]


def metrics(g: pd.DataFrame, col: str) -> str:
    wk = g.assign(b=(g["step"] - 1) // 7).groupby(["unique_id", "b"])[["actual", col]].sum()
    tot = g.groupby("ds")[["actual", col]].sum()
    bias = float((g[col] - g["actual"]).sum() / g["actual"].sum())
    return f"{wape(g['actual'], g[col]):.4f}  {wape(wk['actual'], wk[col]):.4f}  {wape(tot['actual'], tot[col]):.4f}  {bias:+.3f}"


names = ["original, sin inferencia", "original, con inferencia", "con quiebres simulados, sin inferencia", "con quiebres simulados, con inferencia"]
runs = [pd.read_pickle(p) for p in sys.argv[1:5]]
r = runs[0]["served"][["unique_id", "ds", "step", "actual"]]          # días evaluables de la corrida original: la venta real
for i, run in enumerate(runs):
    r = r.merge(served(run).rename(columns={"yhat": f"m{i}"}), on=["unique_id", "ds"])
inj = runs[3]["injected"].copy()
inj["unique_id"] = "b" + inj["branch_id"].astype(int).astype(str) + "-p" + inj["product_id"].astype(int).astype(str)
hit = set(inj["unique_id"])
origin = r["ds"].min() - pd.Timedelta(days=1)
open_ids = set(inj.loc[inj["ds"] == origin, "unique_id"])
print(f"período final: {r['unique_id'].nunique()} series, {len(r):,} días | series con quiebres simulados: {len(hit)} ({len(open_ids)} sin stock en el origen)")
for label, g in (
    ("todas las series", r),
    ("series con quiebres simulados", r[r["unique_id"].isin(hit)]),
    ("  … sin stock en el origen", r[r["unique_id"].isin(open_ids)]),
    ("series sin quiebres simulados", r[~r["unique_id"].isin(hit)]),
):
    print(f"  {label} ({g['unique_id'].nunique()} series): día  semana  total-día  sesgo")
    for i, name in enumerate(names):
        print(f"      {name:42s} {metrics(g, f'm{i}')}")
# detección de los quiebres simulados
panel = runs[3]["panel"]
flag = panel[panel["stockout"].astype(bool) & (panel["day_status"].astype(str) == "stockout_censored")][["unique_id", "ds"]].assign(flag=True)
base = runs[1]["panel"]
natural = base[base["stockout"].astype(bool) & (base["day_status"].astype(str) == "stockout_censored")][["unique_id", "ds"]].assign(natural=True)
t = inj[["unique_id", "ds"]].assign(real=True).merge(flag, on=["unique_id", "ds"], how="outer").merge(natural, on=["unique_id", "ds"], how="left")
t[["real", "flag", "natural"]] = t[["real", "flag", "natural"]].fillna(False).astype(bool)
new = t[t["flag"] & ~t["natural"]]          # marcas que no estaban en la corrida original: se deben a lo simulado
print(
    f"detección: días simulados {int(t['real'].sum()):,}; marcados {int((t['real'] & t['flag']).sum()):,} "
    f"(cobertura {float((t['real'] & t['flag']).sum() / t['real'].sum()):.0%}); marcas nuevas frente a la corrida original {len(new):,}, "
    f"de ellas sobre días simulados {float(new['real'].mean()):.0%}"
)
runs_len = inj.sort_values(["unique_id", "ds"]).assign(g=lambda x: (x.groupby("unique_id")["ds"].diff().dt.days.ne(1)).cumsum())
size = runs_len.groupby("g")["ds"].transform("size")
got = runs_len.merge(flag, on=["unique_id", "ds"], how="left")["flag"].fillna(False).astype(bool).to_numpy()
for lo, hi in ((4, 7), (8, 14), (15, 21)):
    m = ((size >= lo) & (size <= hi)).to_numpy()
    print(f"   rachas de {lo} a {hi} días: {int(m.sum()):,} días, cobertura {got[m].mean():.0%}")
