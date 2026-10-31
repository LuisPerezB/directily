"""Predicción diaria del Customer Core (ADR-V2-08): recalcula las variables al día con los modelos en producción.

No entrena. La recencia cambia cada día aunque no haya ventas nuevas. Sale, por comprador × producto × horizonte:
`purchase_probability`, `quantity_if_purchase`, `expected_quantity` (= P × E[cantidad | compra]; el cero de «no compra» está
incluido), `expected_amount` (esperanza × último precio neto de la relación: SUPUESTO, nunca venta confirmada), el
comprometido conocido y banderas de calidad. Más el ranking top-k por comprador y la actividad por comprador.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.commitments import committed_by_relation, committed_lines
from predictily_engine.customer.events import from_day
from predictily_engine.customer.features import BASE_FEATURES, HORIZON_FEATURES, FeatureBuilder
from predictily_engine.customer.outputs import RANKING_SOURCES, compute_activity, top_k_products
from predictily_engine.customer.serving import enforce_nested, enforce_nested_quantity, serve_probability, serve_quantity


@dataclass
class PredictionOutput:
    as_of: date
    horizons: list[int]
    predictions: pd.DataFrame
    rankings: pd.DataFrame
    activity: pd.DataFrame
    committed: dict[int, dict[str, Any]]                  # resumen por horizonte
    committed_relations: dict[int, pd.DataFrame]          # comprometido por relación (incluye sin predicción)
    summary: dict[str, Any] = field(default_factory=dict)
    served: dict[int, dict[str, Any]] = field(default_factory=dict)


def predict_all(fb: FeatureBuilder, artifacts: dict[int, dict[str, Any]], as_of_day: int, cfg: dict[str, Any],
                commitments_state: pd.DataFrame | None = None, products_active: dict[int, bool] | None = None,
                commitment_stats: dict[str, int] | None = None) -> PredictionOutput:
    """artifacts: horizonte → {"prob": artefacto, "qty": artefacto} (los de producción). Solo se predicen estos horizontes."""
    horizons = sorted(artifacts)
    as_of = from_day(as_of_day)
    cf = fb.compute(as_of_day, tuple(horizons))
    base = cf.base
    out = PredictionOutput(as_of=as_of, horizons=horizons, predictions=pd.DataFrame(), rankings=pd.DataFrame(),
                           activity=compute_activity(fb, as_of_day, cfg), committed={}, committed_relations={})
    summary: dict[str, Any] = {"candidates": int(len(base)), "customers_with_candidates": int(base["customer_id"].nunique()) if len(base) else 0}
    if base.empty:
        out.summary = summary | {"rows": 0}
        return out
    keep = np.ones(len(base), dtype=bool)
    if products_active is not None:
        keep = np.array([products_active.get(int(p), True) for p in base["product_id"]], dtype=bool)
        summary["excluded_inactive_products"] = int((~keep).sum())
    base = base.loc[keep].reset_index(drop=True)
    for h in horizons:
        cf.horizon[h] = cf.horizon[h].loc[keep].reset_index(drop=True)
    X_base = base[BASE_FEATURES].to_numpy(dtype=np.float32)
    probs: dict[int, np.ndarray] = {}
    raws: dict[int, np.ndarray] = {}
    qs: dict[int, np.ndarray] = {}
    los: dict[int, np.ndarray] = {}
    his: dict[int, np.ndarray] = {}
    for h in horizons:
        X = np.concatenate([X_base, cf.horizon[h][HORIZON_FEATURES].to_numpy(dtype=np.float32)], axis=1)
        ref_p = cf.horizon[h]["ref_p"].to_numpy(dtype=float)
        ref_q = cf.horizon[h]["ref_q"].to_numpy(dtype=float)
        probs[h], raws[h] = serve_probability(artifacts[h]["prob"], X, ref_p)
        qs[h], los[h], his[h] = serve_quantity(artifacts[h]["qty"], X, ref_q)
    probs_c, coh = enforce_nested(probs)
    qs_c, qcoh = enforce_nested_quantity(probs_c, qs)
    summary["coherence"] = coh
    summary["quantity_coherence"] = qcoh
    summary["rows"] = int(len(base) * len(horizons))

    price = base["last_net_price"].to_numpy(dtype=float)
    currencies = np.array(fb.currencies + [None], dtype=object)
    cur = currencies[base["currency_code"].to_numpy(dtype=int).clip(0, len(fb.currencies))] if fb.currencies else np.full(len(base), None)
    now_unavail = base["avail_now"].to_numpy(dtype=float) == 0
    low_av = base["avail_share_90"].to_numpy(dtype=float) < float(cfg["min_availability_share"])
    thin = (base["n_purchases_all"].to_numpy(dtype=float) <= 1) | (base["gap_n"].to_numpy(dtype=float) < 2)
    frames = []
    for h in horizons:
        art_p, art_q = artifacts[h]["prob"], artifacts[h]["qty"]
        meta = art_p.get("meta", {})
        adjusted = probs_c[h] > probs[h] + 1e-12
        e = probs_c[h] * qs_c[h]
        flags = [[] for _ in range(len(base))]
        for i in np.nonzero(thin)[0]:
            flags[i].append("thin_history")
        for i in np.nonzero(now_unavail)[0]:
            flags[i].append("stock_unavailable_now")
        for i in np.nonzero(low_av & ~now_unavail)[0]:
            flags[i].append("low_availability_90d")
        for i in np.nonzero(~np.isfinite(price))[0]:
            flags[i].append("price_unknown")
        for i in np.nonzero(adjusted)[0]:
            flags[i].append("coherence_adjusted")
        if meta.get("calibration_status") in ("degraded", "uncalibrated"):
            for f in flags:
                f.append(f"calibration_{meta['calibration_status']}")
        df = pd.DataFrame({
            "customer_id": base["customer_id"].to_numpy(), "product_id": base["product_id"].to_numpy(), "horizon_days": h,
            "as_of": as_of, "purchase_probability": probs_c[h], "probability_raw": raws[h],
            "probability_coherence_adjusted": adjusted, "calibration_status": meta.get("calibration_status"),
            "quantity_if_purchase": qs_c[h], "quantity_lo": los[h], "quantity_hi": his[h], "expected_quantity": e,
            "assumed_unit_price": np.where(np.isfinite(price), price, np.nan),
            "expected_amount": np.where(np.isfinite(price), e * price, np.nan), "currency": cur,
            "median_interval_days": base["gap_median"].to_numpy(dtype=float),
            "days_since_last_purchase": base["rel_recency_days"].to_numpy(dtype=float),
            "n_purchases": base["n_purchases_all"].to_numpy(dtype=float),
            "model_family": art_p["family"], "model_role": "primary" if art_p["kind"] == "complex" else "fallback",
            "quality_flags": flags,
            # puntajes de las fuentes alternativas del ranking (no se persisten en customer_predictions)
            "ranking_score_popularity": base["prod_buyers_90"].to_numpy(dtype=float),
            "ranking_score_recency": -base["rel_recency_days"].to_numpy(dtype=float)})
        # comprometido (pedidos/contratos confirmados): no se suma a la esperanza
        c_rel = pd.DataFrame(columns=["customer_id", "product_id", "committed_quantity", "n_commitments"])
        if commitments_state is not None:
            lines, csum = committed_lines(commitments_state, as_of, h, commitment_stats)
            c_rel = committed_by_relation(lines)
            out.committed[h] = csum
        out.committed_relations[h] = c_rel
        if len(c_rel):
            m = df.merge(c_rel.dropna(subset=["customer_id"]).astype({"customer_id": "int64"})[["customer_id", "product_id", "committed_quantity"]],
                         on=["customer_id", "product_id"], how="left")
            df["committed_quantity"] = m["committed_quantity"].fillna(0.0).to_numpy()
        else:
            df["committed_quantity"] = 0.0
        df["total_view_quantity"] = np.maximum(df["expected_quantity"], df["committed_quantity"])
        df["incremental_quantity"] = df["total_view_quantity"] - df["committed_quantity"]
        frames.append(df)
        out.served[h] = {"family": art_p["family"], "role": "primary" if art_p["kind"] == "complex" else "fallback",
                         "quantity_objective": art_q["objective"], "calibration_status": meta.get("calibration_status"),
                         # fuente del ranking decidida en el holdout (cust-ranking-v1); sin decisión registrada: popularidad (la más segura)
                         "ranking_source": meta.get("ranking_source") if meta.get("ranking_source") in RANKING_SOURCES else "popularity",
                         "calibration_method": art_p["calibrator"].method}
    out.predictions = pd.concat(frames, ignore_index=True)
    k = int(cfg["top_k_stored"])
    ranks = []
    for h in horizons:
        source = out.served[h]["ranking_source"]
        score, tie = {"model": ("purchase_probability", "probability_raw"), "popularity": ("ranking_score_popularity", None),
                      "recency": ("ranking_score_recency", None)}[source]
        r = top_k_products(out.predictions[out.predictions["horizon_days"] == h], k, score, tie).copy()
        r["ranking_source"] = source
        r["ranking_score"] = r[score]
        ranks.append(r[["customer_id", "product_id", "horizon_days", "rank", "purchase_probability", "expected_quantity",
                        "expected_amount", "currency", "ranking_source", "ranking_score"]])
    out.rankings = pd.concat(ranks, ignore_index=True) if ranks else pd.DataFrame()
    out.summary = summary
    return out
