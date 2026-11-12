"""¿De dónde sale el error en una corrida? (4 ventanas, primeros 28 días de cada una.)

1. Mejor familia por tramo de venta (error por día y por semana).
2. Cuánto del error es de NIVEL: se reescala el pronóstico de cada serie y ventana para que acierte su total de 28 días (nivel perfecto,
   inalcanzable) y se mide lo que queda: eso es ruido día a día y reparto dentro del período.
3. Error por categoría, sucursal y clase de demanda.
4. Series que más error absoluto aportan.
Uso: python diagnose.py <archivo.pkl>"""

import sys
import warnings

import pandas as pd

warnings.filterwarnings("ignore")
from compare import family_rows, levels
from curve import BANDS, wape

path = sys.argv[1]
d = pd.read_pickle(path)
fams = ["mean", "lightgbm_global", "auto_ets", "window_average_dow", "weekday_average", "adida_dow", "croston_sba_dow", "seasonal_naive"]
have = set(d["bt"]["family"].unique()) | {"mean"}
rows = {f: family_rows(d, f) for f in fams if f in have}
rows = {f: r[r["step"] <= 28] for f, r in rows.items()}
print(f"== {path}\n(1) error por día / por semana de cada familia, por tramo (la mejor por semana marcada con *):")
print("   tramo     " + " ".join(f"{f[:15]:>15s}" for f in rows))
for band in [b[2] for b in BANDS] + ["TODAS"]:
    vals = {}
    for f, r in rows.items():
        x = r if band == "TODAS" else r[r["band"] == band]
        if len(x) and x["actual"].sum() > 0:
            vals[f] = levels(x)
    if not vals:
        continue
    best = min(vals, key=lambda k: vals[k][1])
    print(
        f"   {band:8s}  " + " ".join(f"{vals[f][0]:.2f}/{vals[f][1]:.2f}{'*' if f == best else ' '}".rjust(15) for f in rows if f in vals)
    )

r = rows["mean"].copy()
tot = r.groupby(["unique_id", "window"])[["actual", "yhat"]].transform("sum")
r["yhat_nivel"] = r["yhat"] * (tot["actual"] / tot["yhat"].where(tot["yhat"] > 0))
r["yhat_nivel"] = r["yhat_nivel"].fillna(0.0)
print("(2) promedio AutoETS+LightGBM: error medido y error con el NIVEL de 28 días perfecto (lo que queda es ruido y reparto):")
for band in [b[2] for b in BANDS] + ["TODAS"]:
    x = r if band == "TODAS" else r[r["band"] == band]
    if not len(x) or x["actual"].sum() == 0:
        continue
    a, b = levels(x), levels(x.assign(yhat=x["yhat_nivel"]))
    g = x.groupby(["unique_id", "window"])[["actual", "yhat"]].sum()
    lvl = wape(g["actual"], g["yhat"])
    print(f"   {band:8s} día {a[0]:.3f} → {b[0]:.3f}   semana {a[1]:.3f} → {b[1]:.3f}   error del total de 28 días por serie {lvl:.3f}")

meta = d["panel"].drop_duplicates("unique_id")[["unique_id", "branch_id", "category_id"]]
prof = d["profile"][["unique_id", "demand_class"]]
x = r.merge(meta, on="unique_id").merge(prof, on="unique_id", how="left")
x["ae"] = (x["actual"] - x["yhat"]).abs()
for key in ("branch_id", "demand_class", "category_id"):
    print(f"(3) por {key}:")
    g = x.groupby(key).apply(
        lambda f: pd.Series(
            {
                "series": f["unique_id"].nunique(),
                "unidades": f["actual"].sum() / x["actual"].sum(),
                "aporte_error": f["ae"].sum() / x["ae"].sum(),
                "dia": levels(f)[0],
                "semana": levels(f)[1],
                "sesgo": levels(f)[4],
            }
        )
    )
    print(g.sort_values("aporte_error", ascending=False).head(10).round(3).to_string())
print("(4) series que más error aportan (28 días × 4 ventanas):")
g = x.groupby("unique_id").agg(
    mu=("mu", "mean"),
    unidades=("actual", "sum"),
    ae=("ae", "sum"),
    yhat=("yhat", "sum"),
    clase=("demand_class", "first"),
    cat=("category_id", "first"),
)
g["aporte"] = g["ae"] / g["ae"].sum()
g["wape"] = g["ae"] / g["unidades"]
g["sesgo"] = (g["yhat"] - g["unidades"]) / g["unidades"]
top = g.sort_values("ae", ascending=False).head(12)
print(top[["mu", "clase", "cat", "aporte", "wape", "sesgo"]].round(3).to_string())
print(
    f"   las 12 primeras suman {top['aporte'].sum():.1%} del error absoluto y {top['unidades'].sum() / g['unidades'].sum():.1%} de las unidades"
)
