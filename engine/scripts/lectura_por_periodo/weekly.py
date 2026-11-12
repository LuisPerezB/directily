"""¿Conviene pronosticar por semana los productos de poca venta? Medición por tramo de venta diaria sobre las empresas demo
(sintéticas, semilla 42), en TODAS las ventanas del backtest (selección + período final).

Compara, por tramo de venta media diaria de la serie al corte de cada ventana:
  · el pronóstico diario del motor (familias fijas) medido día a día y sumado a semanas;
  · un pronóstico hecho directamente sobre la serie SEMANAL (AutoETS sin estación, media de 4 y de 8 semanas) y repartido a días
    con tres repartos: plano (÷7), perfil común del negocio y perfil propio encogido (el `weekday_profile` del motor).
Uso: python weekly.py <archivo.pkl>..."""

import math
import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from predictily_engine.models.statistical import common_weekday_profile, weekday_profile
from statsforecast import StatsForecast
from statsforecast.models import AutoETS, WindowAverage

KEYS = ["unique_id", "window", "ds"]
BANDS = [(0, 1, "< 1"), (1, 5, "1–5"), (5, 10, "5–10"), (10, 50, "10–50"), (50, 200, "50–200"), (200, 1e9, "≥ 200")]
DAILY = ["auto_ets", "lightgbm_global", "ets_lightgbm_mean", "window_average_dow", "weekday_average", "seasonal_naive"]
WEEKS = 12  # semanas completas del horizonte de 90 días


def wape(a, p):
    a, p = np.asarray(a, float), np.asarray(p, float)
    d = np.abs(a).sum()
    return float(np.abs(a - p).sum() / d) if d else np.nan


def poisson_mad(mu):
    """E|X − μ| de una Poisson(μ) = 2·e^{−μ}·μ^{⌊μ⌋+1}/⌊μ⌋!"""
    if mu <= 0:
        return 0.0
    k = math.floor(mu)
    return 2 * math.exp(-mu + (k + 1) * math.log(mu) - math.lgamma(k + 1))


_RNG = np.random.default_rng(7)


def demo_mad(mu, days=1, n=4000):
    """E|X − E X| del generador de la demo (Poisson con ruido gamma de forma 5 por día, independiente entre días), sumando `days` días."""
    if mu <= 0:
        return 0.0
    x = _RNG.poisson(mu * _RNG.gamma(5.0, 1 / 5.0, size=(n, days))).sum(axis=1)
    return float(np.abs(x - mu * days).mean())


def band_of(mu):
    for lo, hi, name in BANDS:
        if lo <= mu < hi:
            return name
    return BANDS[-1][2]


def weekly_forecasts(train, cutoff, ids):
    """Pronóstico semanal directo: semanas de 7 días que terminan en el corte. Devuelve unique_id, week (0 = primera del horizonte), modelo, yhat semanal."""
    t = train[train["unique_id"].isin(ids)][["unique_id", "ds", "y"]].copy()
    t["wk"] = -((cutoff - t["ds"]).dt.days // 7)  # 0 = última semana completa antes del corte
    full = t.groupby(["unique_id", "wk"])["y"].agg(["sum", "size"]).reset_index()
    full = full[full["size"] == 7]
    full["ds"] = cutoff + pd.to_timedelta(full["wk"] * 7, unit="D")
    df = full.rename(columns={"sum": "y"})[["unique_id", "ds", "y"]].sort_values(["unique_id", "ds"])
    sf = StatsForecast(
        models=[
            AutoETS(season_length=1, alias="sem_ets"),
            WindowAverage(window_size=4, alias="sem_media4"),
            WindowAverage(window_size=8, alias="sem_media8"),
        ],
        freq="7D",
        n_jobs=4,
        fallback_model=WindowAverage(window_size=1),
    )
    fc = sf.forecast(h=WEEKS + 1, df=df)
    fc["week"] = ((fc["ds"] - cutoff).dt.days // 7) - 1
    long = fc.melt(id_vars=["unique_id", "week"], value_vars=["sem_ets", "sem_media4", "sem_media8"], var_name="model", value_name="yw")
    long["yw"] = np.clip(long["yw"].astype(float).fillna(0.0), 0, None)
    return long


def run(slug):
    d = pd.read_pickle(slug)
    bt, panel = d["bt"], d["panel"].copy()
    panel["ds"] = pd.to_datetime(panel["ds"])
    hist = panel[["unique_id", "ds", "y"]].copy()
    hist["y"] = hist["y"].astype(float).fillna(0.0)
    rows = []
    for w, g in bt.groupby("window"):
        cutoff = pd.to_datetime(g["ds"]).min() - pd.Timedelta(days=1)
        is_hold = bool(g["is_holdout"].iloc[0])
        train = hist[hist["ds"] <= cutoff]
        truth = g.drop_duplicates(["unique_id", "ds"])[["unique_id", "ds", "step", "actual", "stockout"]].copy()
        truth["ds"] = pd.to_datetime(truth["ds"])
        truth = truth[~truth["stockout"].astype(bool)]
        ids = truth["unique_id"].unique()
        level = train[train["ds"] > cutoff - pd.Timedelta(days=90)].groupby("unique_id")["y"].mean().reindex(ids).fillna(0.0)
        truth["mu"] = truth["unique_id"].map(level)
        truth["band"] = truth["mu"].map(band_of)
        truth["week"] = (truth["step"] - 1) // 7
        truth["dow"] = truth["ds"].dt.dayofweek
        truth = truth[truth["week"] < WEEKS]
        # pronósticos diarios del motor
        dd = g[g["family"].isin(DAILY)][["unique_id", "ds", "family", "yhat"]].copy()
        dd["ds"] = pd.to_datetime(dd["ds"])
        wide = dd.pivot_table(index=["unique_id", "ds"], columns="family", values="yhat", aggfunc="first").reset_index()
        base = truth.merge(wide, on=["unique_id", "ds"], how="left")
        # pronóstico semanal directo y sus tres repartos
        wk = weekly_forecasts(train, cutoff, ids).pivot_table(index=["unique_id", "week"], columns="model", values="yw").reset_index()
        base = base.merge(wk, on=["unique_id", "week"], how="left")
        own = weekday_profile(train[train["unique_id"].isin(ids)]).rename(columns={"factor": "f_own"})
        base = base.merge(own, on=["unique_id", "dow"], how="left")
        common = common_weekday_profile(train[train["unique_id"].isin(ids)])
        base["f_common"] = common[base["dow"].to_numpy()]
        base["f_own"] = base["f_own"].fillna(base["f_common"])
        for m in ("sem_ets", "sem_media4", "sem_media8"):
            base[f"{m}|plano"] = base[m] / 7
            base[f"{m}|comun"] = base[m] / 7 * base["f_common"]
            base[f"{m}|propio"] = base[m] / 7 * base["f_own"]
        base["window"] = w
        base["is_holdout"] = is_hold
        rows.append(base.drop(columns=["sem_ets", "sem_media4", "sem_media8"]))
    all_rows = pd.concat(rows, ignore_index=True)
    models = [c for c in all_rows.columns if c in DAILY or "|" in c]
    out = {
        "slug": slug,
        "ventanas": int(all_rows["window"].nunique()),
        "tramos": {},
        "perfil_comun_ultimo_corte": [round(float(x), 2) for x in common],
    }
    complete = all_rows.dropna(subset=models)
    out["filas"] = len(all_rows)
    out["filas_completas"] = len(complete)
    for scope, frame in (("todas las ventanas", complete), ("período final", complete[complete["is_holdout"]])):
        for band in [b[2] for b in BANDS] + ["TODAS"]:
            f = frame if band == "TODAS" else frame[frame["band"] == band]
            if f.empty or f["actual"].sum() == 0:
                continue
            series = f.drop_duplicates(["unique_id", "window"])
            r = {
                "series_ventana": len(series),
                "series": int(f["unique_id"].nunique()),
                "participacion_unidades": round(float(f["actual"].sum() / frame["actual"].sum()), 3),
                "venta_media_dia": round(float(series["mu"].mean()), 2),
                "dias_en_cero": round(float((f["actual"] == 0).mean()), 3),
                "piso_poisson_dia": round(sum(poisson_mad(m) for m in series["mu"]) / max(series["mu"].sum(), 1e-9), 3),
                "piso_poisson_semana": round(sum(poisson_mad(7 * m) for m in series["mu"]) / max(7 * series["mu"].sum(), 1e-9), 3),
                "piso_demo_dia": round(sum(demo_mad(m) for m in series["mu"]) / max(series["mu"].sum(), 1e-9), 3),
                "piso_demo_semana": round(sum(demo_mad(m, 7) for m in series["mu"]) / max(7 * series["mu"].sum(), 1e-9), 3),
                "modelos": {},
            }
            for m in models:
                wkagg = f.groupby(["unique_id", "window", "week"])[["actual", m]].sum()
                tot = f.groupby(["window", "ds"])[["actual", m]].sum()
                r["modelos"][m] = {
                    "dia": round(wape(f["actual"], f[m]), 4),
                    "semana": round(wape(wkagg["actual"], wkagg[m]), 4),
                    "total_dia": round(wape(tot["actual"], tot[m]), 4),
                    "sesgo": round(float((f[m] - f["actual"]).sum() / f["actual"].sum()), 4),
                }
            out["tramos"].setdefault(scope, {})[band] = r
    return out


if __name__ == "__main__":
    res = [run(s) for s in sys.argv[1:]]
    show = [
        "auto_ets",
        "lightgbm_global",
        "ets_lightgbm_mean",
        "window_average_dow",
        "sem_ets|plano",
        "sem_ets|comun",
        "sem_ets|propio",
        "sem_media8|propio",
    ]
    for r in res:
        print(
            "\n==",
            r["slug"],
            "| ventanas:",
            r["ventanas"],
            "| filas:",
            r["filas"],
            "completas:",
            r["filas_completas"],
            "| perfil común:",
            r["perfil_comun_ultimo_corte"],
        )
        for scope, bands in r["tramos"].items():
            print(" --", scope)
            for band, v in bands.items():
                print(
                    f"  [{band:7s}] series {v['series']:4d}  unidades {v['participacion_unidades']:.1%}  media/día {v['venta_media_dia']:7.2f}  ceros {v['dias_en_cero']:.0%}  piso Poisson día {v['piso_poisson_dia']:.2f} / semana {v['piso_poisson_semana']:.2f}  piso demo día {v['piso_demo_dia']:.2f} / semana {v['piso_demo_semana']:.2f}"
                )
                for m in show:
                    x = v["modelos"].get(m)
                    if x:
                        print(
                            f"      {m:22s} día {x['dia']:.3f}  semana {x['semana']:.3f}  total-día {x['total_dia']:.3f}  sesgo {x['sesgo']:+.3f}"
                        )
