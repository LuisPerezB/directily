"""Error del pronóstico según el tamaño T del período en que se lee (1 día … 84 días), por tramo de venta, y la ley que lo explica.

Ley:  E[e_T²] = φ·μ·T  +  c²·μ²·T  +  δ²·μ²·T²        (e_T = error acumulado en un período de T días; μ = venta media diaria)
      ruido de conteo   ruido diario     error de nivel
      ⇒ error relativo²  r(T)² = φ/(μT) + c²/T + δ²   y  WAPE(T) ≈ √(2/π) · r(T)

Se ajustan φ, c², δ² con las ventanas de SELECCIÓN de toda la empresa y se comprueba en el período final:
  · que la curva predicha coincide con la medida por tramo;
  · que la regla «período más corto cuyo error predicho ≤ tolerancia» acierta.
Uso: python curve.py <archivo.pkl> [familia]"""

import math
import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

TS = [1, 2, 3, 4, 7, 14, 21, 28, 42, 84]
GRID = [1, 7, 28]  # lecturas que el producto ya ofrece: día, semana, 4 semanas
BANDS = [(0, 1, "< 1"), (1, 5, "1–5"), (5, 10, "5–10"), (10, 50, "10–50"), (50, 200, "50–200"), (200, 1e9, "≥ 200")]
K = math.sqrt(2 / math.pi)


def wape(a, p):
    a, p = np.asarray(a, float), np.asarray(p, float)
    d = np.abs(a).sum()
    return float(np.abs(a - p).sum() / d) if d else np.nan


def band_of(mu):
    for lo, hi, name in BANDS:
        if lo <= mu < hi:
            return name
    return BANDS[-1][2]


def rows_from(path, family):
    d = pd.read_pickle(path)
    bt, panel = d["bt"], d["panel"]
    hist = panel[["unique_id", "ds", "y"]].copy()
    hist["ds"] = pd.to_datetime(hist["ds"])
    hist["y"] = hist["y"].astype(float).fillna(0.0)
    out = []
    for w, g in bt[bt["family"] == family].groupby("window"):
        g = g[~g["stockout"].astype(bool)][["unique_id", "ds", "step", "actual", "yhat", "is_holdout"]].copy()
        g["ds"] = pd.to_datetime(g["ds"])
        cutoff = g["ds"].min() - pd.Timedelta(days=1)
        level = hist[(hist["ds"] <= cutoff) & (hist["ds"] > cutoff - pd.Timedelta(days=90))].groupby("unique_id")["y"].mean()
        g["mu"] = g["unique_id"].map(level).fillna(0.0)
        g["window"] = w
        out.append(g[g["step"] <= 84])
    r = pd.concat(out, ignore_index=True)
    r["band"] = r["mu"].map(band_of)
    return r


def buckets(r, T):
    """Una fila por serie, ventana y período de T días: real, pronóstico, días evaluables y μ."""
    b = (
        r.assign(b=(r["step"] - 1) // T)
        .groupby(["unique_id", "window", "b"], as_index=False)
        .agg(
            actual=("actual", "sum"),
            yhat=("yhat", "sum"),
            n=("actual", "size"),
            mu=("mu", "first"),
            band=("band", "first"),
            is_holdout=("is_holdout", "first"),
        )
    )
    b["T"] = T
    return b


def fit_law(sel_buckets, bins=12):
    """Ajuste robusto con errores ABSOLUTOS (como el WAPE): se agrupan los períodos en celdas (tramo de μ por cuantiles × T), en cada
    celda r̂ = Σ|e| / Σ(μ·n) / √(2/π), y se resuelve r̂² = φ·media(1/(μn)) + c²·media(1/n) + δ² por mínimos cuadrados no negativos,
    ponderando cada celda por sus unidades esperadas."""
    from scipy.optimize import nnls

    f = sel_buckets[sel_buckets["mu"] > 0].copy()
    f["N"] = f["mu"] * f["n"]
    f["ae"] = (f["actual"] - f["yhat"]).abs()
    f["q"] = pd.qcut(f["mu"].rank(method="first"), bins, labels=False)
    X, y, w = [], [], []
    for (_, _), c in f.groupby(["q", "T"]):
        N = c["N"].sum()
        if N <= 0 or len(c) < 20:
            continue
        r = c["ae"].sum() / N / K
        wu = c["N"] / N  # el error de la celda es la media de r ponderada por unidades
        X.append([float((wu / c["N"]).sum()), float((wu / c["n"]).sum()), 1.0])
        y.append(r**2)
        w.append(math.sqrt(N))
    X, y, w = np.array(X), np.array(y), np.array(w)
    coef, _ = nnls(X * w[:, None], y * w)
    return {"phi": float(coef[0]), "c2": float(coef[1]), "delta2": float(coef[2])}


def predicted(law, mu, T):
    mu = np.maximum(np.asarray(mu, float), 1e-9)
    return K * np.sqrt(law["phi"] / (mu * T) + law["c2"] / T + law["delta2"])


def main(path, family):
    r = rows_from(path, family)
    hold = r[r["is_holdout"]]
    B = {T: buckets(r, T) for T in TS}
    law = fit_law(pd.concat([B[T][~B[T]["is_holdout"]] for T in TS]))
    out = {
        "archivo": path,
        "familia": family,
        "series": int(r["unique_id"].nunique()),
        "ventanas": int(r["window"].nunique()),
        "ley": {k: round(v, 4) for k, v in law.items()},
        "curvas": {},
        "regla": {},
    }
    print(f"\n== {path} | familia {family} | series {out['series']} | ventanas {out['ventanas']}")
    print(
        f"   ley ajustada en ventanas de selección: φ = {law['phi']:.2f} (1 = Poisson puro), c = {math.sqrt(law['c2']):.2f} (ruido diario), δ = {math.sqrt(law['delta2']):.3f} (error de nivel)"
    )
    print("   error medido en el período final (y predicho por la ley), según los días T del período de lectura:")
    print("   tramo     series  μ/día | " + " ".join(f"T={T:<3d}      " for T in TS))
    for band in [b[2] for b in BANDS] + ["TODAS"]:
        line, cur = [], {}
        f0 = hold if band == "TODAS" else hold[hold["band"] == band]
        if f0.empty or f0["actual"].sum() == 0:
            continue
        for T in TS:
            b = B[T][B[T]["is_holdout"]]
            b = b if band == "TODAS" else b[b["band"] == band]
            m = wape(b["actual"], b["yhat"])
            w = b["mu"] * b["n"]
            p = float((predicted(law, b["mu"], b["n"].clip(lower=1)) * w).sum() / w.sum()) if w.sum() else np.nan
            cur[T] = {"medido": round(m, 3), "predicho": round(p, 3)}
            line.append(f"{m:.2f} ({p:.2f})")
        s = f0.drop_duplicates("unique_id")
        out["curvas"][band] = {"series": len(s), "mu": round(float(s["mu"].mean()), 2), "T": cur}
        print(f"   {band:8s} {len(s):6d} {s['mu'].mean():7.1f} | " + " ".join(f"{x:11s}" for x in line))
    # ajuste por tramo de WAPE² = A/T + B con lo MEDIDO en todas las ventanas (para ver el piso √B que no baja con más días)
    print("   ajuste WAPE(T)² = A/T + B con todas las ventanas → piso al que tiende el error con períodos largos (√B):")
    for band in [b[2] for b in BANDS] + ["TODAS"]:
        ys = []
        for T in TS:
            b = B[T] if band == "TODAS" else B[T][B[T]["band"] == band]
            ys.append(wape(b["actual"], b["yhat"]) if len(b) else np.nan)
        ys = np.array(ys)
        if np.isnan(ys).any():
            continue
        X = np.column_stack([1 / np.array(TS, float), np.ones(len(TS))])
        (A, Bc), *_ = np.linalg.lstsq(X, ys**2, rcond=None)
        fit = np.sqrt(np.clip(X @ np.array([A, Bc]), 0, None))
        r2 = 1 - ((ys - fit) ** 2).sum() / ((ys - ys.mean()) ** 2).sum()
        out["curvas"][band]["A"] = round(float(A), 4)
        out["curvas"][band]["piso"] = round(float(math.sqrt(max(Bc, 0))), 3)
        out["curvas"][band]["r2"] = round(float(r2), 3)
        print(
            f"      {band:8s} A = {A:.3f}  piso √B = {math.sqrt(max(Bc, 0)):.3f}  R² = {r2:.3f}   (día→semana ÷{ys[0] / ys[4]:.2f}; raíz de 7 = 2.65)"
        )
    # regla automática: período más corto de la rejilla cuyo error PREDICHO ≤ tolerancia, con μ de cada serie al corte del período final
    mu_h = hold.groupby("unique_id")["mu"].first()
    units = hold.groupby("unique_id")["actual"].sum()
    real = {}
    for T in GRID:
        b = B[T][B[T]["is_holdout"]]
        g = b.assign(ae=(b["actual"] - b["yhat"]).abs()).groupby("unique_id")[["ae", "actual"]].sum()
        real[T] = g["ae"] / g["actual"].replace(0, np.nan)
    emp = {}
    for T in GRID:
        b = B[T][~B[T]["is_holdout"]]
        g = b.assign(ae=(b["actual"] - b["yhat"]).abs()).groupby("unique_id")[["ae", "actual"]].sum()
        emp[T] = (g["ae"] / g["actual"].replace(0, np.nan)).reindex(mu_h.index)
    for tol in (0.20, 0.25):
        res = {}
        for name in ("ley", "empirica"):
            rec = pd.Series(0, index=mu_h.index)
            for T in reversed(GRID):
                ok = (predicted(law, mu_h, T) <= tol) if name == "ley" else (emp[T] <= tol).to_numpy()
                rec[ok] = T
            rows = {}
            for T in GRID + [0]:
                ids = rec.index[rec == T]
                if len(ids) == 0:
                    rows[T] = {"series": 0}
                    continue
                u = units.reindex(ids).fillna(0)
                col = real[T if T else 28].reindex(ids)
                rows[T] = {
                    "series": len(ids),
                    "unidades": round(float(u.sum() / units.sum()), 3),
                    "error_final": round(float((col * u).sum() / u[col.notna()].sum()), 3) if u[col.notna()].sum() else None,
                    "cumplen": round(float((col <= tol + 0.05).mean()), 2),
                    "mu_min": round(float(mu_h.reindex(ids).min()), 2),
                    "mu_max": round(float(mu_h.reindex(ids).max()), 2),
                }
            res[name] = rows
        out["regla"][tol] = res
        print(f"   regla con tolerancia {tol:.0%} (decidida sin ver el período final; error y cumplimiento medidos en él):")
        for name in ("ley", "empirica"):
            for T in GRID + [0]:
                v = res[name][T]
                lab = {1: "día", 7: "semana", 28: "4 semanas", 0: "ninguna (punto de reorden)"}[T]
                if v["series"] == 0:
                    print(f"      {name:9s} {lab:28s} series   0")
                    continue
                print(
                    f"      {name:9s} {lab:28s} series {v['series']:4d}  unidades {v['unidades']:6.1%}  error {v['error_final']}  cumplen (tol+5) {v['cumplen']:.0%}  μ/día {v['mu_min']}–{v['mu_max']}"
                )
    # umbrales de venta diaria que la ley implica para cada lectura
    for tol in (0.20, 0.25):
        th = {}
        for T in GRID:
            den = (tol / K) ** 2 - law["delta2"] - law["c2"] / T
            th[T] = round(law["phi"] / (T * den), 2) if den > 0 else None
        out.setdefault("umbrales", {})[tol] = th
        print(
            f"   venta diaria mínima para leer con error ≤ {tol:.0%}: día {th[1]}, semana {th[7]}, 4 semanas {th[28]}  (None = inalcanzable con ese período)"
        )
    return out


if __name__ == "__main__":
    path = sys.argv[1]
    fam = sys.argv[2] if len(sys.argv) > 2 else "ets_lightgbm_mean"
    main(path, fam)
