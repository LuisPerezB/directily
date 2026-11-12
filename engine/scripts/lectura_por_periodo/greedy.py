"""Selección codiciosa de ensamble (Caruana et al. 2004, la que usa AutoGluon-TimeSeries) sobre las ventanas de selección,
medida en el período final reservado. Datos: empresas demo sintéticas (semilla 42). Uso: python greedy.py <archivo.pkl>..."""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

KEYS = ["unique_id", "window", "ds"]
ENS = "ets_lightgbm_mean"
ITER = 50


def wape(a, p):
    d = np.abs(a).sum()
    return float(np.abs(a - p).sum() / d) if d else np.nan


class Block:
    """Matriz (filas = serie-ventana-día, columnas = familias) con el real y el índice del grupo «ventana-día» para el total diario."""

    def __init__(self, df, families):
        wide = df[df["family"].isin(families)].pivot_table(index=KEYS, columns="family", values="yhat", aggfunc="first")
        wide = wide.reindex(columns=families).dropna()
        actual = df.drop_duplicates(KEYS).set_index(KEYS)["actual"].reindex(wide.index)
        self.index = wide.index
        self.P = wide.to_numpy(float)
        self.a = actual.to_numpy(float)
        g = pd.MultiIndex.from_arrays([wide.index.get_level_values("window"), wide.index.get_level_values("ds")])
        self.g, _ = pd.factorize(g)
        self.ng = self.g.max() + 1 if len(self.g) else 0
        self.families = families

    def loss(self, yhat, metric):
        if metric == "serie_dia":
            return wape(self.a, yhat)
        A = np.bincount(self.g, self.a, self.ng)
        Y = np.bincount(self.g, yhat, self.ng)
        return wape(A, Y)


def greedy(block, metric, iters=ITER):
    """Con reposición: en cada paso entra la familia que más baja la pérdida de la media de las elegidas. Pesos = veces elegida / pasos."""
    n = len(block.families)
    counts = np.zeros(n)
    total = np.zeros(len(block.a))
    best_w, best = None, np.inf
    for step in range(1, iters + 1):
        losses = [block.loss((total + block.P[:, j]) / step, metric) for j in range(n)]
        j = int(np.argmin(losses))
        counts[j] += 1
        total += block.P[:, j]
        if losses[j] < best - 1e-12:
            best, best_w = losses[j], counts / step
    return best_w, best


def evaluate(df):
    a, y = df["actual"].to_numpy(float), df["yhat"].to_numpy(float)
    t = df.groupby(["window", "ds"])[["actual", "yhat"]].sum()
    return {
        "serie_dia": round(wape(a, y), 4),
        "total_dia": round(wape(t["actual"].to_numpy(), t["yhat"].to_numpy()), 4),
        "sesgo": round(float((y - a).sum() / a.sum()), 4),
    }


def frame(block, yhat):
    out = block.index.to_frame(index=False)
    out["actual"] = block.a
    out["yhat"] = yhat
    return out


def run(slug):
    d = pd.read_pickle(slug)
    bt = d["bt"]
    bt = bt[~bt["stockout"].astype(bool)].copy()
    sel, hold = bt[~bt["is_holdout"]], bt[bt["is_holdout"]]
    n_hold = hold.drop_duplicates(KEYS).shape[0]
    # familias con cobertura completa en el período final (las que solo existen para una clase quedan fuera del ámbito global)
    cover = hold.groupby("family").size() / n_hold
    cover_sel = sel.groupby("family").size() / sel.drop_duplicates(KEYS).shape[0]
    pool = [f for f in cover.index if f != ENS and cover[f] > 0.999 and cover_sel.get(f, 0) > 0.999]
    S, H = Block(sel, pool), Block(hold, pool)
    out = {
        "slug": slug,
        "ventanas_seleccion": int(sel["window"].nunique()),
        "familias": pool,
        "cobertura_parcial": {f: round(float(cover[f]), 3) for f in cover.index if f not in pool and f != ENS},
        "filas_seleccion": len(S.a),
        "filas_final": len(H.a),
        "resultados": {},
    }
    R = out["resultados"]
    base = frame(H, H.a)[KEYS]
    served = d["served"].merge(base, on=KEYS)
    R["servido (todo encendido)"] = evaluate(served)
    for j, f in enumerate(pool):
        R[f"solo {f}"] = evaluate(frame(H, H.P[:, j]))
    members = [pool.index("auto_ets"), pool.index("lightgbm_global")]
    R["promedio simple ETS+LightGBM"] = evaluate(frame(H, H.P[:, members].mean(axis=1)))
    R["promedio simple de todas"] = evaluate(frame(H, H.P.mean(axis=1)))
    # ---- codiciosa global
    for metric in ("serie_dia", "total_dia"):
        w, loss = greedy(S, metric)
        r = evaluate(frame(H, H.P @ w))
        r["pesos"] = {f: round(float(x), 2) for f, x in zip(pool, w) if x > 0}
        r["perdida_en_seleccion"] = round(loss, 4)
        R[f"codiciosa global ({metric})"] = r
    # ---- codiciosa por clase de demanda (la clase de cada fila en el corte de su ventana)
    cls_s = sel.drop_duplicates(KEYS).set_index(KEYS)["demand_class"].reindex(S.index).to_numpy()
    cls_h = hold.drop_duplicates(KEYS).set_index(KEYS)["demand_class"].reindex(H.index).to_numpy()
    for metric in ("serie_dia", "total_dia"):
        yhat = np.zeros(len(H.a))
        pesos = {}
        wg, _ = greedy(S, metric)
        for c in pd.unique(cls_h):
            ms, mh = cls_s == c, cls_h == c
            if ms.sum() < 200:
                w = wg
            else:
                sub = Block.__new__(Block)
                sub.P, sub.a, sub.families = S.P[ms], S.a[ms], pool
                sub.g, _ = pd.factorize(S.g[ms])
                sub.ng = sub.g.max() + 1
                w, _ = greedy(sub, metric)
            yhat[mh] = H.P[mh] @ w
            pesos[str(c)] = {f: round(float(x), 2) for f, x in zip(pool, w) if x > 0}
        r = evaluate(frame(H, yhat))
        r["pesos"] = pesos
        R[f"codiciosa por clase ({metric})"] = r
    # ---- codiciosa por serie (variante «por ítem»): pesos propios de cada serie con su error serie-día
    uid_s = S.index.get_level_values("unique_id").to_numpy()
    uid_h = H.index.get_level_values("unique_id").to_numpy()
    yhat = np.zeros(len(H.a))
    wg, _ = greedy(S, "serie_dia")
    ganadoras = {}
    for u in pd.unique(uid_h):
        ms, mh = uid_s == u, uid_h == u
        if ms.sum() < 30 or S.a[ms].sum() == 0:
            w = wg
        else:
            sub = Block.__new__(Block)
            sub.P, sub.a, sub.families = S.P[ms], S.a[ms], pool
            sub.g = np.arange(ms.sum())
            sub.ng = ms.sum()
            w, _ = greedy(sub, "serie_dia", iters=20)
        yhat[mh] = H.P[mh] @ w
        top = pool[int(np.argmax(w))]
        ganadoras[top] = ganadoras.get(top, 0) + 1
    r = evaluate(frame(H, yhat))
    r["familia_de_mayor_peso_por_serie"] = ganadoras
    R["codiciosa por serie (serie_dia)"] = r
    # ---- techos: pesos codiciosos ajustados CON el período final (no alcanzable) y oráculo por serie
    for metric in ("serie_dia", "total_dia"):
        w, _ = greedy(H, metric)
        r = evaluate(frame(H, H.P @ w))
        r["pesos"] = {f: round(float(x), 2) for f, x in zip(pool, w) if x > 0}
        R[f"TECHO pesos globales vistos en el final ({metric})"] = r
    err = pd.DataFrame(np.abs(H.P - H.a[:, None]), columns=pool).groupby(uid_h).sum()
    bestf = err.idxmin(axis=1)
    col = np.array([pool.index(bestf[u]) for u in uid_h])
    R["TECHO oráculo por serie"] = evaluate(frame(H, H.P[np.arange(len(H.a)), col]))
    return out


if __name__ == "__main__":
    res = [run(s) for s in sys.argv[1:]]
    for r in res:
        print("\n==", r["slug"], "| ventanas de selección:", r["ventanas_seleccion"], "| filas final:", r["filas_final"])
        print("   familias en el pozo:", r["familias"])
        print("   fuera (cobertura parcial):", r["cobertura_parcial"])
        for k, v in r["resultados"].items():
            extra = v.get("pesos") or v.get("familia_de_mayor_peso_por_serie") or ""
            print(f"   {k:52s} serie-día {v['serie_dia']:.4f}  total-día {v['total_dia']:.4f}  sesgo {v['sesgo']:+.4f}  {extra}")
