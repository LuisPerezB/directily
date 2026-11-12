"""Comparación en igualdad de condiciones con benchmarks publicados de M5: las 30 490 series producto × tienda, últimos 28 días.

Mide WAPE (Σ|error| / Σ real) por serie y día, por serie y semana y del total diario para: naive estacional (control: debe reproducir el
valor publicado si la ventana es la misma), LightGBM global con la configuración del motor (con precio, eventos y días de subsidio),
AutoETS con estación semanal y el promedio de ambos (lo que el motor sirve como `ets_lightgbm_mean`).
Uso: python m5_full.py <dir_m5> [días de historia, por defecto 500]"""

import sys
import time
import warnings

import lightgbm as lgb
import numpy as np
import pandas as pd
from mlforecast import MLForecast
from mlforecast.lag_transforms import RollingMean, RollingStd, SeasonalRollingMean
from statsforecast import StatsForecast
from statsforecast.models import AutoETS, SeasonalNaive

from harness import PARAMS, wape

warnings.filterwarnings("ignore")
directory = sys.argv[1]
history = int(sys.argv[2]) if len(sys.argv) > 2 else 500
H = 28
t0 = time.time()
head = pd.read_csv(f"{directory}/sales_train_evaluation.csv", nrows=0).columns
dcols = [c for c in head if c.startswith("d_")][-(history + H) :]
s = pd.read_csv(f"{directory}/sales_train_evaluation.csv", usecols=["item_id", "dept_id", "cat_id", "store_id", "state_id", *dcols])
for c in dcols:
    s[c] = s[c].astype("float32")
long = s.melt(id_vars=["item_id", "dept_id", "cat_id", "store_id", "state_id"], var_name="d", value_name="y")
del s
cal = pd.read_csv(f"{directory}/calendar.csv")
cal["d"] = ["d_" + str(i + 1) for i in range(len(cal))]
cal["ds"] = pd.to_datetime(cal["date"])
for t in ("Cultural", "National", "Religious", "Sporting"):
    cal[f"ev_{t.lower()}"] = ((cal["event_type_1"] == t) | (cal["event_type_2"] == t)).astype("int8")
cal["ev_pre"] = cal[["ev_cultural", "ev_national", "ev_religious", "ev_sporting"]].max(axis=1).shift(-1).fillna(0).astype("int8")
events = ["ev_cultural", "ev_national", "ev_religious", "ev_sporting", "ev_pre"]
long = long.merge(cal[["d", "ds", "wm_yr_wk", *events, "snap_CA", "snap_TX", "snap_WI"]], on="d").drop(columns="d")
long["snap"] = np.select([long["state_id"] == "CA", long["state_id"] == "TX"], [long["snap_CA"], long["snap_TX"]], long["snap_WI"]).astype(
    "int8"
)
long = long.drop(columns=["snap_CA", "snap_TX", "snap_WI"])
prices = pd.read_csv(f"{directory}/sell_prices.csv")
long = long.merge(prices, on=["store_id", "item_id", "wm_yr_wk"], how="left").drop(columns="wm_yr_wk")
del prices
long["unique_id"] = (long["store_id"] + "|" + long["item_id"]).astype("category")
long = long.sort_values(["unique_id", "ds"]).reset_index(drop=True)
long = long[long.groupby("unique_id", observed=True)["sell_price"].transform(lambda x: x.notna().cummax())]
long["sell_price"] = long.groupby("unique_id", observed=True)["sell_price"].ffill()
long["price_rel"] = (long["sell_price"] / long.groupby("unique_id", observed=True)["sell_price"].cummax()).astype("float32")
long["log_price"] = np.log(long["sell_price"]).astype("float32")
for c, src in (("store_num", "store_id"), ("dept_num", "dept_id"), ("cat_num", "cat_id")):
    long[c] = long[src].astype("category").cat.codes.astype("int16")
long = long.drop(columns=["item_id", "dept_id", "cat_id", "store_id", "state_id", "sell_price"])
cutoff = long["ds"].max() - pd.Timedelta(days=H)
train, test = long[long["ds"] <= cutoff], long[long["ds"] > cutoff]
n = train.groupby("unique_id", observed=True).size()
ids = n[n >= 60].index
train, test = train[train["unique_id"].isin(ids)], test[test["unique_id"].isin(ids)]
print(
    f"series {train['unique_id'].nunique()} de {long['unique_id'].nunique()} | entrenamiento {train['ds'].min().date()} → {cutoff.date()} | prueba {test['ds'].min().date()} → {test['ds'].max().date()} | filas {len(train):,} | {time.time() - t0:.0f}s",
    flush=True,
)

static, feats = ["store_num", "dept_num", "cat_num"], ["price_rel", "log_price", "snap", *events]
mlf = MLForecast(
    models={"lightgbm": lgb.LGBMRegressor(**PARAMS, random_state=42)},
    freq="D",
    lags=[1, 7, 14, 28],
    lag_transforms={
        1: [RollingMean(window_size=7), RollingMean(window_size=28), RollingStd(window_size=28)],
        7: [SeasonalRollingMean(season_length=7, window_size=4)],
    },
    date_features=["dayofweek", "month", "day"],
)
mlf.fit(train[["unique_id", "ds", "y", *static, *feats]], static_features=static)
pred = mlf.predict(h=H, X_df=test[["unique_id", "ds", *feats]])
pred["lightgbm"] = pred["lightgbm"].clip(lower=0)
print(f"LightGBM listo | {time.time() - t0:.0f}s", flush=True)
sf = StatsForecast(
    models=[AutoETS(season_length=7, model="ZNZ", alias="auto_ets"), SeasonalNaive(season_length=7, alias="seasonal_naive")],
    freq="D",
    n_jobs=4,
    fallback_model=SeasonalNaive(season_length=7),
)
st = sf.forecast(h=H, df=train[["unique_id", "ds", "y"]])
st["auto_ets"] = st["auto_ets"].clip(lower=0)
print(f"AutoETS listo | {time.time() - t0:.0f}s", flush=True)
r = test[["unique_id", "ds", "y"]].merge(pred, on=["unique_id", "ds"]).merge(st, on=["unique_id", "ds"])
r["promedio"] = (r["lightgbm"] + r["auto_ets"]) / 2
r["week"] = (r["ds"] - cutoff).dt.days.sub(1) // 7
print(f"filas evaluadas {len(r):,} | venta total {r['y'].sum():,.0f} | días en cero {float((r['y'] == 0).mean()):.1%}")
for m in ("seasonal_naive", "auto_ets", "lightgbm", "promedio"):
    wk = r.groupby(["unique_id", "week"], observed=True)[["y", m]].sum()
    tot = r.groupby("ds")[["y", m]].sum()
    print(
        f"   {m:15s} WAPE serie-día {wape(r['y'], r[m]):.4f}  serie-semana {wape(wk['y'], wk[m]):.4f}  total-día {wape(tot['y'], tot[m]):.4f}  sesgo {float((r[m] - r['y']).sum() / r['y'].sum()):+.3f}"
    )
