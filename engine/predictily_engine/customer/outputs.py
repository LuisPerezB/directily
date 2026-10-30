"""Salidas del comprador: frecuencia de compra, riesgo de inactividad, cambio de comportamiento, ranking y monto.

Definiciones (docs/architecture/v2/P2-comportamiento.md §6):
- Frecuencia: días de compra por 30 días (cualquier producto) en la ventana de `frequency_lookback_days`.
- Inactividad (NO es «abandono»): ausencia de compra respecto al patrón propio. Con ≥ `inactivity_min_intervals`
  intervalos propios, se compara la brecha actual con los percentiles 75 y 90 de sus intervalos: `at_risk` si
  la supera el P75; `inactive_by_pattern` si supera el P90 (ambos con una holgura del 20 % de la mediana de sus
  intervalos para no marcar a quien compra a intervalos casi constantes). El riesgo es el percentil empírico de la brecha
  actual dentro de sus propios intervalos. Sin historial suficiente: `insufficient_history` (no se inventa).
- Cambio de comportamiento: compara los días de compra de los últimos W días con los W previos (binomial exacta,
  p < 0,05 y razón fuera de [0,67; 1,5]).
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
from scipy.stats import binomtest
from sklearn.metrics import roc_auc_score

from predictily_engine.customer.events import from_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer.metrics import quantity_metrics

GAP_WINDOW = 24


def _row_quantile(mat: np.ndarray, q: float) -> np.ndarray:
    m = (~np.isnan(mat)).sum(axis=1)
    srt = np.sort(mat, axis=1)
    rows = np.arange(mat.shape[0])
    pos = q * np.maximum(m - 1, 0)
    lo = np.floor(pos).astype(int)
    hi = np.ceil(pos).astype(int)
    frac = pos - lo
    cols = mat.shape[1] - 1
    val = srt[rows, np.clip(lo, 0, cols)] * (1 - frac) + srt[rows, np.clip(hi, 0, cols)] * frac
    return np.where(m > 0, val, np.nan)


def compute_activity(fb: FeatureBuilder, c: int, cfg: dict[str, Any]) -> pd.DataFrame:
    """Frecuencia, inactividad y cambio de comportamiento de cada comprador con ≥ 1 compra hasta el corte `c`."""
    if fb.empty:
        return pd.DataFrame()
    cs = fb.cstore
    groups = np.arange(fb.n_cust)
    chi = cs.pos(groups, c)
    n = chi - cs.start
    G = groups[n >= 1]
    hi = chi[n >= 1]
    st = cs.start[G]
    last_day = cs.day[hi - 1]
    first_day = cs.day[st]
    since = (c - last_day).astype(float)
    gaps = cs.last_gaps(hi, G, GAP_WINDOW)
    m = (~np.isnan(gaps)).sum(axis=1)
    q75 = _row_quantile(gaps, float(cfg["inactivity_at_risk_quantile"]))
    q90 = _row_quantile(gaps, float(cfg["inactivity_inactive_quantile"]))
    with np.errstate(invalid="ignore"):
        le = np.nansum(gaps <= since[:, None], axis=1)
    min_int = int(cfg["inactivity_min_intervals"])
    enough = m >= min_int
    risk = np.where(enough, (le + 0.5) / (m + 1.0), np.nan)
    status = np.full(len(G), "insufficient_history", dtype=object)
    status[enough] = "active"
    slack = float(cfg["inactivity_tolerance_fraction"]) * np.nan_to_num(_row_quantile(gaps, 0.5), nan=0.0)
    status[enough & (since > q75 + slack)] = "at_risk"
    status[enough & (since > q90 + slack)] = "inactive_by_pattern"
    mean_gap = np.where(m > 0, np.nansum(gaps, axis=1) / np.maximum(m, 1), np.nan)
    srt = np.sort(gaps, axis=1)
    rows = np.arange(len(G))
    median_gap = np.where(m > 0, (srt[rows, np.clip((m - 1) // 2, 0, GAP_WINDOW - 1)] + srt[rows, np.clip(m // 2, 0, GAP_WINDOW - 1)]) / 2, np.nan)

    w = int(cfg["frequency_lookback_days"])
    n_w = (hi - cs.pos(G, c - w)).astype(float)
    exposure = np.minimum(float(w), (c - first_day) + 1.0)
    freq30 = 30.0 * n_w / exposure

    bw = int(cfg["behavior_window_days"])
    n_rec = (hi - cs.pos(G, c - bw)).astype(float)
    n_pri = (cs.pos(G, c - bw) - cs.pos(G, c - 2 * bw)).astype(float)
    tenure_ok = (c - first_day) >= 2 * bw
    ratio = (n_rec + 0.5) / (n_pri + 0.5)
    change = np.full(len(G), "insufficient_history", dtype=object)
    pval = np.full(len(G), np.nan)
    tot = n_rec + n_pri
    cand = tenure_ok & (tot >= 4)
    cache: dict[tuple[int, int], float] = {}
    for i in np.nonzero(cand)[0]:
        key = (int(n_rec[i]), int(tot[i]))
        if key not in cache:
            cache[key] = float(binomtest(key[0], key[1], 0.5).pvalue)
        pval[i] = cache[key]
    sig = cand & (pval < 0.05)
    change[cand] = "stable"
    change[sig & (ratio >= 1.5)] = "increase"
    change[sig & (ratio <= 1 / 1.5)] = "decrease"
    single_currency = len(fb.currencies) <= 1
    if single_currency:
        sp_rec = cs.total("net", G, c - bw, c)
        sp_pri = cs.total("net", G, c - 2 * bw, c - bw)
        spend_ratio = np.where(sp_pri > 0, sp_rec / np.where(sp_pri > 0, sp_pri, 1.0), np.nan)
    else:
        sp_rec = sp_pri = spend_ratio = np.full(len(G), np.nan)
    return pd.DataFrame({
        "customer_id": fb.cust_ids[G], "last_purchase_date": [from_day(d) for d in last_day],
        "days_since_last_purchase": since, "n_purchase_days": (hi - st).astype(int), "n_purchase_days_window": n_w.astype(int),
        "purchase_frequency_per_30d": freq30, "median_interval_days": median_gap, "mean_interval_days": mean_gap,
        "interval_p75_days": q75, "interval_p90_days": q90, "n_intervals": m.astype(int), "inactivity_risk": risk,
        "inactivity_status": status, "behavior_change": change, "frequency_ratio": ratio, "behavior_p_value": pval,
        "spend_recent": sp_rec, "spend_prior": sp_pri, "spend_ratio": spend_ratio,
    })


def evaluate_activity(fb: FeatureBuilder, cutoffs: list[int], last_complete: int, cfg: dict[str, Any]) -> dict[str, Any]:
    """Evalúa inactividad y frecuencia con resultados observables posteriores al corte.

    Inactividad: resultado = «ninguna compra de ningún producto en (c, c+D]» (D = inactivity_outcome_days).
    Frecuencia: resultado = días de compra en (c, c+30]. Nada se ajusta con estos resultados (umbrales de config).
    """
    d_out = int(cfg["inactivity_outcome_days"])
    naive = int(cfg["inactivity_naive_days"])
    rows_i: list[pd.DataFrame] = []
    rows_f: list[pd.DataFrame] = []
    for c in cutoffs:
        if fb.empty:
            break
        act = compute_activity(fb, c, cfg)
        if act.empty:
            continue
        codes = pd.Series(np.arange(fb.n_cust), index=fb.cust_ids).loc[act["customer_id"].to_numpy()].to_numpy()
        if c + d_out <= last_complete:
            out = (fb.cstore.count(codes, c, c + d_out) == 0).astype(float)
            rows_i.append(act.assign(outcome=out, cutoff=c))
        if c + 30 <= last_complete:
            n30 = fb.cstore.count(codes, c, c + 30).astype(float)
            ten = (c - fb.cstore.day[fb.cstore.start[codes]])
            keep = ten >= 365
            sub = act.loc[keep]
            if len(sub):
                cs = fb.cstore
                cod = codes[keep]
                hi = cs.pos(cod, c)
                f = {}
                for w in (90, 180, 365):
                    f[w] = 30.0 * (hi - cs.pos(cod, c - w)) / w
                f["all"] = 30.0 * (hi - cs.start[cod]) / np.maximum(c - cs.day[cs.start[cod]] + 1.0, 1.0)
                rows_f.append(pd.DataFrame({"actual": n30[keep], **{f"f_{k}": v for k, v in f.items()}, "cutoff": c}))
    out: dict[str, Any] = {"inactivity": None, "frequency": None}
    if rows_i:
        d = pd.concat(rows_i, ignore_index=True)
        base = float(d["outcome"].mean())
        by_status = {s: {"n": int(len(g)), "no_purchase_rate": float(g["outcome"].mean())} for s, g in d.groupby("inactivity_status")}
        flagged = d["inactivity_status"] == "inactive_by_pattern"
        naive_flag = d["days_since_last_purchase"] > naive

        def pr(flag: pd.Series) -> dict[str, Any]:
            tp = float(d.loc[flag, "outcome"].sum())
            return {"n_flagged": int(flag.sum()), "precision": float(tp / flag.sum()) if flag.sum() else None,
                    "recall": float(tp / d["outcome"].sum()) if d["outcome"].sum() else None}
        have = d["inactivity_risk"].notna() & (d["outcome"].nunique() > 1)
        auc_risk = float(roc_auc_score(d.loc[have, "outcome"], d.loc[have, "inactivity_risk"])) if have.any() and d.loc[have, "outcome"].nunique() > 1 else None
        auc_naive = float(roc_auc_score(d.loc[have, "outcome"], d.loc[have, "days_since_last_purchase"])) if auc_risk is not None else None
        out["inactivity"] = {
            "definition": f"Resultado observable: ninguna compra de ningún producto en los {d_out} días posteriores al corte (no es abandono definitivo).",
            "n_customer_cutoffs": int(len(d)), "n_cutoffs": int(d["cutoff"].nunique()), "base_no_purchase_rate": base,
            "by_status": by_status, "flag_inactive_by_pattern": pr(flagged),
            "reference_flag_no_purchase_days": {"days": naive, **pr(naive_flag)},
            "auc_own_pattern_risk": auc_risk, "auc_days_since_last_purchase": auc_naive,
            "note": "Ventanas solapadas entre cortes: filas no independientes."}
    if rows_f:
        f = pd.concat(rows_f, ignore_index=True)
        out["frequency"] = {
            "definition": "Días de compra (cualquier producto) por 30 días; real = días de compra en los 30 días posteriores al corte.",
            "n_customer_cutoffs": int(len(f)),
            "by_lookback_days": {str(k): quantity_metrics(f["actual"], f[f"f_{k}"]) for k in (90, 180, 365, "all")},
            "used_lookback_days": int(cfg["frequency_lookback_days"]),
            "population": "compradores con ≥ 365 días de historia al corte"}
    return out


def top_k_products(pred: pd.DataFrame, k: int) -> pd.DataFrame:
    """Los k productos más probables por comprador (horizonte ya filtrado); desempata por esperanza de cantidad."""
    if pred.empty:
        return pred.assign(rank=pd.Series(dtype=int))
    d = pred.sort_values(["customer_id", "purchase_probability", "expected_quantity", "product_id"],
                         ascending=[True, False, False, True], kind="mergesort")
    d["rank"] = d.groupby("customer_id", sort=False).cumcount() + 1
    return d[d["rank"] <= k]
