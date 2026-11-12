"""¿Baja el error si al promedio del motor (AutoETS + LightGBM) se le suma un modelo fundacional preentrenado (Chronos-2)?

Análisis; no forma parte del motor ni cambia lo que se sirve. Chronos-2 se usa tal cual se publica (sin entrenarlo con estos datos),
con y sin los mismos factores que recibe LightGBM (promociones, feriados, precio…), en los mismos cortes de origen móvil del banco de
pruebas. Va en tres pasos porque el modelo necesita PyTorch, que no se instala en el entorno del motor:

python fundacional.py preparar <rossmann|favorita|m5> <dir_salida> <argumentos de la base…>   # entorno del motor
python fundacional.py chronos <dir_salida> <dir_pesos_chronos2> [base,todo] [días de historia] [últimos N cortes]   # entorno con torch y chronos-forecasting
python fundacional.py comparar <dir_salida>                                                   # cualquiera de los dos

`m5_full.py <dir_m5> <historia> <dir_salida>` deja los mismos archivos para las 30 490 series de M5.
Punto de Chronos-2: «mediana» = cuantil 0,5 (lo que premian los benchmarks publicados); «media» = promedio de sus 21 cuantiles (lo que
busca el motor: sin sesgo en el total; aproximación, porque no ve más allá del cuantil 0,99).
"""

from __future__ import annotations

import json
import sys
import time
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
KEYS = ["unique_id", "cutoff", "ds"]
TODO = {"rossmann": "+ todo", "favorita": "+ todo menos clima", "m5": "+ todo (precio conocido)"}
CONTEXT = 2048  # días de historia que se le entregan a Chronos-2 como máximo (por defecto)
BATCH = 256


def wape(a, p) -> float:
    a, p = np.asarray(a, float), np.asarray(p, float)
    d = np.abs(a).sum()
    return float(np.abs(a - p).sum() / d) if d else float("nan")


def metrics(r: pd.DataFrame, col: str) -> dict[str, float]:
    r = r[r["evaluable"]]
    wk = r.assign(b=(r["step"] - 1) // 7).groupby(["unique_id", "cutoff", "b"], observed=True)[["y", col]].sum()
    tot = r.groupby(["cutoff", "ds"])[["y", col]].sum()
    return {
        "dia": wape(r["y"], r[col]),
        "semana": wape(wk["y"], wk[col]),
        "total_dia": wape(tot["y"], tot[col]),
        "sesgo": float((r[col] - r["y"]).sum() / r["y"].sum()),
    }


def preparar(name: str, out: str, *args: str) -> None:
    from statsforecast import StatsForecast
    from statsforecast.models import AutoETS, SeasonalNaive

    import datasets
    import harness

    ds = getattr(datasets, name)(*args)
    arms = {a.label: a for a in ds.arms}
    base_arm, todo_arm = arms["base"], arms[TODO[name]]
    parts = []
    for c in ds.cutoffs:
        t0 = time.time()
        b = harness.forecast(ds, base_arm, c).rename(columns={"yhat": "lgbm_base"})
        t = harness.forecast(ds, todo_arm, c).rename(columns={"yhat": "lgbm_todo"})
        r = b[["unique_id", "ds", "cutoff", "step", "y", "evaluable", "lgbm_base"]].merge(
            t[["unique_id", "ds", "lgbm_todo"]], on=["unique_id", "ds"]
        )
        train = ds.df[(ds.df["ds"] <= c) & ds.df["unique_id"].isin(r["unique_id"].unique())][["unique_id", "ds", "y"]]
        sf = StatsForecast(
            models=[AutoETS(season_length=7, model="ZNZ", alias="auto_ets")],
            freq="D",
            n_jobs=4,
            fallback_model=SeasonalNaive(season_length=7),
        )
        st = sf.forecast(h=harness.H, df=train)
        st["auto_ets"] = st["auto_ets"].clip(lower=0)
        parts.append(r.merge(st[["unique_id", "ds", "auto_ets"]], on=["unique_id", "ds"]))
        print(f"   corte {c.date()}: {r['unique_id'].nunique()} series | {time.time() - t0:.0f}s", flush=True)
    pd.concat(parts, ignore_index=True).to_parquet(f"{out}/base.parquet")
    feats = list(dict.fromkeys([*base_arm.features, *todo_arm.features]))
    ds.df[["unique_id", "ds", "y", *feats]].to_parquet(f"{out}/frame.parquet")
    meta = {"dataset": ds.name, "h": harness.H, "features_base": base_arm.features, "features_todo": todo_arm.features, "notas": ds.notes}
    with open(f"{out}/meta.json", "w") as f:
        json.dump(meta, f, ensure_ascii=False, indent=1)


def chronos(out: str, weights: str, variants: str = "base,todo", context: str = str(CONTEXT), last: str = "0") -> None:
    import torch
    from chronos import Chronos2Pipeline

    with open(f"{out}/meta.json") as f:
        meta = json.load(f)
    h = int(meta["h"])
    frame = pd.read_parquet(f"{out}/frame.parquet")
    frame["unique_id"] = frame["unique_id"].astype(str)
    frame = frame.sort_values(["unique_id", "ds"]).reset_index(drop=True)
    base = pd.read_parquet(f"{out}/base.parquet", columns=["unique_id", "cutoff"]).drop_duplicates()
    base = base[base["cutoff"].isin(sorted(base["cutoff"].unique())[-int(last) :])]
    ctx = int(context)
    base["unique_id"] = base["unique_id"].astype(str)
    ids, starts = np.unique(frame["unique_id"].to_numpy(), return_index=True)
    bounds = {i: (int(s), int(e)) for i, s, e in zip(ids, starts, [*starts[1:], len(frame)], strict=True)}
    dates = frame["ds"].to_numpy().astype("datetime64[D]")
    y = frame["y"].to_numpy(np.float32)
    feats_by = {v: meta[f"features_{v}"] for v in variants.split(",")}
    cov = {c: np.nan_to_num(frame[c].to_numpy(np.float32)) for c in {c for fs in feats_by.values() for c in fs}}
    pipe = Chronos2Pipeline.from_pretrained(weights, device_map="cpu")
    levels = np.asarray(pipe.quantiles, float)
    weight = (np.append(levels[1:], 1.0) - np.insert(levels[:-1], 0, 0.0)) / 2
    weight = (weight / weight.sum()).astype(np.float32)
    median = int(np.argmin(np.abs(levels - 0.5)))
    out_rows: dict[str, list[pd.DataFrame]] = {v: [] for v in feats_by}
    seconds = dict.fromkeys(feats_by, 0.0)
    for c, g in base.groupby("cutoff"):
        cut = np.datetime64(pd.Timestamp(c), "D")
        tasks = []
        for i in g["unique_id"]:
            s, e = bounds[i]
            k = int(np.searchsorted(dates[s:e], cut, side="right"))
            if k == 0 or dates[s + k - 1] != cut or e - (s + k) < h:
                continue
            tasks.append((i, s + max(0, k - ctx), s + k))
        for v, feats in feats_by.items():
            t0 = time.time()
            for lo in range(0, len(tasks), 4096):
                chunk = tasks[lo : lo + 4096]
                if feats:
                    inputs = [
                        {
                            "target": y[a:b],
                            "past_covariates": {f: cov[f][a:b] for f in feats},
                            "future_covariates": {f: cov[f][b : b + h] for f in feats},
                        }
                        for _, a, b in chunk
                    ]
                else:
                    inputs = [y[a:b] for _, a, b in chunk]
                with torch.inference_mode():
                    pred = pipe.predict(inputs, prediction_length=h, batch_size=BATCH, context_length=ctx)
                q = np.clip(np.stack([p[0].float().numpy() for p in pred]), 0, None)  # (series, cuantiles, h)
                out_rows[v].append(
                    pd.DataFrame(
                        {
                            "unique_id": np.repeat([i for i, _, _ in chunk], h),
                            "cutoff": pd.Timestamp(c),
                            "ds": np.concatenate([dates[b : b + h] for _, _, b in chunk]).astype("datetime64[ns]"),
                            f"chronos_{v}_mediana": q[:, median, :].ravel(),
                            f"chronos_{v}_media": (q * weight[None, :, None]).sum(axis=1).ravel(),
                        }
                    )
                )
                if len(tasks) > 4096:
                    print(f"      {v}: {lo + len(chunk)} de {len(tasks)} | {time.time() - t0:.0f}s", flush=True)
            seconds[v] += time.time() - t0
            print(
                f"   corte {pd.Timestamp(c).date()} | {v} ({len(feats)} factores): {len(tasks)} series | {time.time() - t0:.0f}s",
                flush=True,
            )
    res = None
    for rows in out_rows.values():
        part = pd.concat(rows, ignore_index=True)
        res = part if res is None else res.merge(part, on=KEYS)
    res.to_parquet(f"{out}/chronos.parquet")
    n = int(base.shape[0])
    info = {
        "pronosticos": n,
        "dias_de_historia": ctx,
        "hilos": torch.get_num_threads(),
        "segundos": seconds,
        "ms_por_serie": {v: round(1000 * s / n, 1) for v, s in seconds.items()},
    }
    with open(f"{out}/chronos_meta.json", "w") as f:
        json.dump(info, f, indent=1)
    print("   " + json.dumps(info))


def comparar(out: str) -> dict:
    with open(f"{out}/meta.json") as f:
        meta = json.load(f)
    base = pd.read_parquet(f"{out}/base.parquet")
    base["unique_id"] = base["unique_id"].astype(str)
    r = base.merge(pd.read_parquet(f"{out}/chronos.parquet"), on=KEYS)
    cand: dict[str, pd.Series] = {"AutoETS": r["auto_ets"]}
    if "lgbm_base" in r:
        cand["LightGBM sin factores"] = r["lgbm_base"]
    cand["LightGBM con factores"] = r["lgbm_todo"]
    for v, name in (("base", "sin factores"), ("todo", "con factores")):
        for p in ("mediana", "media"):
            if f"chronos_{v}_{p}" in r and (v == "todo" or meta["features_base"] != meta["features_todo"]):
                cand[f"Chronos-2 {name} ({p})"] = r[f"chronos_{v}_{p}"]
    current = "Promedio actual del motor: AutoETS + LightGBM"
    cand[current] = (r["auto_ets"] + r["lgbm_todo"]) / 2
    for v, name in (("base", "sin factores"), ("todo", "con factores")):
        if f"chronos_{v}_media" not in r or (v == "base" and meta["features_base"] == meta["features_todo"]):
            continue
        for p in ("mediana", "media"):
            cand[f"AutoETS + LightGBM + Chronos-2 {name} ({p})"] = (r["auto_ets"] + r["lgbm_todo"] + r[f"chronos_{v}_{p}"]) / 3
            cand[f"LightGBM + Chronos-2 {name} ({p})"] = (r["lgbm_todo"] + r[f"chronos_{v}_{p}"]) / 2
    r = r.assign(**{f"c{i}": s.to_numpy() for i, s in enumerate(cand.values())})
    cols = dict(zip(cand, [f"c{i}" for i in range(len(cand))], strict=True))
    res = {k: metrics(r, c) for k, c in cols.items()}
    # referencia: el mejor candidato que el motor ya tiene, por error semanal (sirve el que gana su backtest, no siempre el promedio)
    own = ["AutoETS", "LightGBM con factores", current]
    best_own = min(own, key=lambda k: res[k]["semana"])
    ref = res[best_own]
    n_cut = r["cutoff"].nunique()
    print(f"\n=== {meta['dataset']}: {r['unique_id'].nunique()} series, {n_cut} cortes de {meta['h']} días, {len(r):,} filas")
    print(f"    factores: {', '.join(meta['features_todo']) or 'ninguno'}")
    print(f"    mejor candidato actual del motor (referencia): {best_own}")
    print(f"    {'':56s}    día  semana  total-día  sesgo | frente a la referencia: día, semana, total-día")
    for k, m in res.items():
        d = {x: m[x] / ref[x] - 1 for x in ("dia", "semana", "total_dia")}
        print(
            f"    {k:56s} {m['dia']:.4f}  {m['semana']:.4f}  {m['total_dia']:.4f}  {m['sesgo']:+.3f} | {d['dia']:+.1%}  {d['semana']:+.1%}  {d['total_dia']:+.1%}"
        )
    wins = {}
    if n_cut > 1:
        for k in [x for x in cols if "+ Chronos-2" in x]:
            w = dict.fromkeys(("dia", "semana", "total_dia"), 0)
            for _, g in r.groupby("cutoff"):
                a, b = metrics(g, cols[k]), metrics(g, cols[best_own])
                for x in w:
                    w[x] += a[x] < b[x]
            wins[k] = w
            print(
                f"    cortes en que gana a la referencia — {k}: día {w['dia']}, semana {w['semana']}, total-día {w['total_dia']} de {n_cut}"
            )
    try:
        with open(f"{out}/chronos_meta.json") as f:
            cost = json.load(f)
        print(f"    costo de Chronos-2 en CPU ({cost['hilos']} hilos): {cost['ms_por_serie']} ms por serie y corte")
    except FileNotFoundError:
        cost = None
    result = {"dataset": meta["dataset"], "referencia": best_own, "metricas": res, "cortes_ganados": wins, "costo": cost}
    with open(f"{out}/comparacion.json", "w") as f:
        json.dump(result, f, ensure_ascii=False, indent=1)
    return result


if __name__ == "__main__":
    {"preparar": preparar, "chronos": chronos, "comparar": comparar}[sys.argv[1]](*sys.argv[2:])
