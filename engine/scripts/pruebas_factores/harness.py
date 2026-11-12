"""Banco de pruebas de factores: ¿cuánto baja el error del pronóstico al agregar cada grupo de variables?

Un LightGBM global con la misma configuración que el del motor (Tweedie, rezagos 1/7/14/28, medias móviles, día de la semana, mes y día)
se entrena en cada corte SIN factores (base) y CON cada grupo de factores, y pronostica los 28 días siguientes. Validación con origen
móvil: nunca usa datos posteriores al corte para entrenar. Las variables de cada brazo se conocen de antemano (calendario, promociones
planificadas) o se entregan como «conocidas» para medir el techo del beneficio (clima observado, precio futuro); un brazo puede congelar
una variable en su último valor al corte (lo que hace hoy el motor con el precio).
"""

from __future__ import annotations

import warnings
from dataclasses import dataclass, field

import lightgbm as lgb
import numpy as np
import pandas as pd
from mlforecast import MLForecast
from mlforecast.lag_transforms import RollingMean, RollingStd, SeasonalRollingMean

warnings.filterwarnings("ignore")

PARAMS = {
    "objective": "tweedie",
    "tweedie_variance_power": 1.2,
    "n_estimators": 350,
    "learning_rate": 0.05,
    "num_leaves": 31,
    "min_child_samples": 40,
    "subsample": 0.8,
    "subsample_freq": 1,
    "colsample_bytree": 0.8,
    "verbose": -1,
    "n_jobs": 4,
}
H = 28


@dataclass
class Arm:
    label: str
    features: list[str]
    frozen: list[str] = field(default_factory=list)  # variables cuyo valor futuro se congela en el último observado al corte
    seed: int = 42


@dataclass
class Dataset:
    name: str
    df: pd.DataFrame  # denso: unique_id, ds, y, evaluable (bool), columnas estáticas, factores y máscaras
    static: list[str]
    arms: list[Arm]
    masks: dict[str, str]  # nombre legible → columna booleana (días donde el factor actúa)
    cutoffs: list[pd.Timestamp]
    notes: str = ""


def wape(a, p) -> float:
    a, p = np.asarray(a, float), np.asarray(p, float)
    d = np.abs(a).sum()
    return float(np.abs(a - p).sum() / d) if d else float("nan")


def forecast(ds: Dataset, arm: Arm, cutoff: pd.Timestamp) -> pd.DataFrame:
    df = ds.df
    train = df[df["ds"] <= cutoff]
    future = df[(df["ds"] > cutoff) & (df["ds"] <= cutoff + pd.Timedelta(days=H))]
    ids = train.groupby("unique_id")["ds"].agg(["max", "size"])
    ids = ids[(ids["max"] == cutoff) & (ids["size"] >= 60)].index
    full = future.groupby("unique_id").size()
    ids = ids.intersection(full[full == H].index)
    train, future = train[train["unique_id"].isin(ids)], future[future["unique_id"].isin(ids)]
    cols = ["unique_id", "ds", "y", *ds.static, *arm.features]
    mlf = MLForecast(
        models={"yhat": lgb.LGBMRegressor(**PARAMS, random_state=arm.seed)},
        freq="D",
        lags=[1, 7, 14, 28],
        lag_transforms={
            1: [RollingMean(window_size=7), RollingMean(window_size=28), RollingStd(window_size=28)],
            7: [SeasonalRollingMean(season_length=7, window_size=4)],
        },
        date_features=["dayofweek", "month", "day"],
    )
    mlf.fit(train[cols], static_features=ds.static)
    x = None
    if arm.features:
        x = future[["unique_id", "ds", *arm.features]].copy()
        if arm.frozen:
            last = train.sort_values("ds").groupby("unique_id")[arm.frozen].last()
            for c in arm.frozen:
                x[c] = x["unique_id"].map(last[c]).to_numpy()
    pred = mlf.predict(h=H, X_df=x)
    pred["yhat"] = pred["yhat"].clip(lower=0)
    keep = ["unique_id", "ds", "y", "evaluable", *ds.masks.values()]
    out = pred.merge(future[keep], on=["unique_id", "ds"])
    out["cutoff"] = cutoff
    out["step"] = (out["ds"] - cutoff).dt.days
    return out


def metrics(r: pd.DataFrame) -> dict[str, float]:
    r = r[r["evaluable"]]
    wk = r.assign(b=(r["step"] - 1) // 7).groupby(["unique_id", "cutoff", "b"])[["y", "yhat"]].sum()
    tot = r.groupby(["cutoff", "ds"])[["y", "yhat"]].sum()
    return {
        "dia": wape(r["y"], r["yhat"]),
        "semana": wape(wk["y"], wk["yhat"]),
        "total_dia": wape(tot["y"], tot["yhat"]),
        "sesgo": float((r["yhat"] - r["y"]).sum() / r["y"].sum()),
    }


def run(ds: Dataset) -> dict:
    print(
        f"\n=== {ds.name}: {ds.df['unique_id'].nunique()} series, {ds.df['ds'].min().date()} → {ds.df['ds'].max().date()}, {len(ds.cutoffs)} cortes de 28 días",
        flush=True,
    )
    if ds.notes:
        print("    " + ds.notes)
    rows: dict[str, pd.DataFrame] = {}
    for arm in ds.arms:
        rows[arm.label] = pd.concat([forecast(ds, arm, c) for c in ds.cutoffs], ignore_index=True)
        m = metrics(rows[arm.label])
        print(
            f"    {arm.label:42s} día {m['dia']:.4f}  semana {m['semana']:.4f}  total-día {m['total_dia']:.4f}  sesgo {m['sesgo']:+.3f}",
            flush=True,
        )
    base = metrics(rows["base"])
    noise = None
    if "base (otra semilla)" in rows:
        other = metrics(rows["base (otra semilla)"])
        noise = {k: abs(other[k] / base[k] - 1) for k in ("dia", "semana", "total_dia")}
        print(f"    ruido entre semillas: día ±{noise['dia']:.1%}  semana ±{noise['semana']:.1%}  total-día ±{noise['total_dia']:.1%}")
    print("    cambio frente a la base (negativo = mejora):")
    result = {"dataset": ds.name, "base": base, "ruido": noise, "brazos": {}}
    for label, r in rows.items():
        if label.startswith("base"):
            continue
        m = metrics(r)
        delta = {k: m[k] / base[k] - 1 for k in ("dia", "semana", "total_dia")}
        result["brazos"][label] = {"metricas": m, "cambio": delta}
        print(f"      {label:40s} día {delta['dia']:+.1%}  semana {delta['semana']:+.1%}  total-día {delta['total_dia']:+.1%}")
    print("    en los días donde actúa cada factor (error por día y sesgo: base → con el factor):")
    b = rows["base"]
    for name, col in ds.masks.items():
        sub = b[b["evaluable"] & b[col]]
        if sub.empty or sub["y"].sum() == 0:
            continue
        share = sub["y"].sum() / b.loc[b["evaluable"], "y"].sum()
        line = f"      {name:28s} {len(sub) / b['evaluable'].sum():5.1%} de los días, {share:5.1%} de la venta | base {wape(sub['y'], sub['yhat']):.3f} ({float((sub['yhat'] - sub['y']).sum() / sub['y'].sum()):+.1%})"
        for label, r in rows.items():
            if label.startswith("base") or col not in MASK_ARMS.get(label, [col]):
                continue
            s2 = r[r["evaluable"] & r[col]]
            line += f" | {label}: {wape(s2['y'], s2['yhat']):.3f} ({float((s2['yhat'] - s2['y']).sum() / s2['y'].sum()):+.1%})"
        print(line)
    return result


MASK_ARMS: dict[str, list[str]] = {}  # brazo → máscaras que le corresponden (si no figura, se muestran todas)
