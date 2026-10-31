"""Entrenamiento y evaluación por horizonte del Customer Core (funciones puras; el servicio persiste).

Para cada H ∈ {7, 30, 90}:
  1. cortes maduros → bloques cronológicos entrenamiento | calibración | selección | holdout (separados H días);
  2. candidatos de probabilidad (referencia, logística, LGBMClassifier) ajustados con el bloque de entrenamiento,
     calibrados (isotónica/Platt) con el bloque de calibración y comparados con Brier en el bloque de selección;
  3. cantidad condicionada con objetivos comparados (LGBMRegressor) frente a la referencia histórica;
  4. el holdout evalúa el procedimiento completo y aplica la regla de promoción versionada: el modelo complejo
     solo se sirve si mejora la referencia; si no, se sirve la referencia (rol `fallback`);
  5. con pocos datos: solo referencia calibrada («reference_only», resultado degradado) o abstención.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.calibration import fit_calibrator, prior_correction
from predictily_engine.customer.dataset import MODEL_FEATURES, DatasetBundle
from predictily_engine.customer.events import from_day
from predictily_engine.customer.metrics import (
    block_bootstrap_difference,
    interval_coverage,
    probability_metrics,
    quantity_metrics,
    ranking_metrics,
)
from predictily_engine.customer.models import (
    ReferenceProbability,
    ReferenceQuantity,
    fit_probability_candidates,
    fit_quantity_candidates,
    ratio_interval,
    ratio_scale,
)
from predictily_engine.customer.outputs import choose_ranking_source
from predictily_engine.customer.protocol import FULL_BLOCKS, LEAN_BLOCKS, REFERENCE_BLOCKS, BlockPlan, plan_blocks
from predictily_engine.customer.serving import enforce_nested, serve_probability, serve_quantity

MIN_SELECTION_POSITIVES = 20
SIMPLICITY_ORDER = ["logistic", "lgbm_small", "lgbm_large"]
QTY_SELECTION_MAX_ABS_BIAS = 0.15


@dataclass
class HorizonResult:
    horizon: int
    status: str                       # trained | reference_only | abstained
    reason: str | None = None
    manifest: dict[str, Any] = field(default_factory=dict)
    prob: dict[str, Any] = field(default_factory=dict)
    qty: dict[str, Any] = field(default_factory=dict)
    holdout: dict[str, Any] = field(default_factory=dict)
    artifacts: dict[str, Any] = field(default_factory=dict)   # picklable: prob / qty (servido) y challenger
    duration_s: float = 0.0


def _rows_by_block(fr: pd.DataFrame, plan: BlockPlan) -> dict[str, np.ndarray]:
    cut = fr["cutoff"].to_numpy()
    return {n: np.nonzero(np.isin(cut, plan.cutoffs[n]))[0] for n in plan.names}


def _block_ok(fr: pd.DataFrame, rows: np.ndarray, min_rows: int, min_pos: int) -> bool:
    return len(rows) >= min_rows and int(np.nansum(fr["y"].to_numpy()[rows])) >= min_pos


def _unit_ids(cutoff: np.ndarray, customer: np.ndarray) -> np.ndarray:
    return pd.MultiIndex.from_arrays([cutoff, customer]).factorize()[0]


def train_horizon(bundle: DatasetBundle, h: int, cfg: dict[str, Any], seed: int, n_jobs: int) -> HorizonResult:
    t0 = time.perf_counter()
    hd = bundle.per_horizon[h]
    fr = hd.frame
    res = HorizonResult(horizon=h, status="abstained")
    res.manifest = {"horizon_days": h, "label_counts": dict(hd.counts), "cutoff_step_days_effective": bundle.effective_step,
                    "overlapping_origins": bool(bundle.effective_step < h),
                    "overlap_note": ("Los cortes consecutivos tienen ventanas de resultado solapadas (paso < H): sus filas no "
                                     "son independientes; no se calculan intervalos de confianza por fila.") if bundle.effective_step < h else None,
                    "labels_mature_through": from_day(bundle.last_complete).isoformat(),
                    "censoring": {"min_availability_share": cfg["min_availability_share"],
                                  "treatment": "La fila censurada se excluye por completo (ni negativo ni positivo)."}}
    if fr.empty:
        res.reason = "Datos insuficientes: no hay etiquetas maduras y no censuradas para este horizonte."
        return res
    fractions = cfg["block_fractions"]
    min_cut, min_rows = int(cfg["min_block_cutoffs"]), int(cfg["min_block_rows"])
    cuts = np.unique(fr["cutoff"].to_numpy())
    plan = plan_blocks(cuts, h, fractions, FULL_BLOCKS, min_cut)
    tier = None
    rows: dict[str, np.ndarray] = {}
    if plan is not None:
        rows = _rows_by_block(fr, plan)
        ok = (_block_ok(fr, rows["train"], min_rows, int(cfg["min_train_positives"]))
              and _block_ok(fr, rows["calibration"], min_rows, int(cfg["min_calibration_positives"]))
              and _block_ok(fr, rows["selection"], min_rows, 5)
              and _block_ok(fr, rows["holdout"], min_rows, int(cfg["min_holdout_positives"])))
        tier = "full" if ok else None
    if tier is None:
        # nivel «lean»: sin bloque de selección (un único candidato complejo preconfigurado), solo 2 separaciones de H días
        lean_plan = plan_blocks(cuts, h, cfg["lean_block_fractions"], LEAN_BLOCKS, min_cut)
        if lean_plan is not None:
            r3 = _rows_by_block(fr, lean_plan)
            if (_block_ok(fr, r3["train"], min_rows, int(cfg["min_train_positives"]))
                    and _block_ok(fr, r3["calibration"], min_rows, int(cfg["min_calibration_positives"]))
                    and _block_ok(fr, r3["holdout"], min_rows, int(cfg["min_holdout_positives"]))):
                plan, rows, tier = lean_plan, r3, "lean"
    if tier is None:
        ref_plan = plan_blocks(cuts, h, {"calibration": 0.5, "holdout": 0.5}, REFERENCE_BLOCKS, min_cut)
        if ref_plan is not None:
            r2 = _rows_by_block(fr, ref_plan)
            if (_block_ok(fr, r2["calibration"], min_rows, int(cfg["min_calibration_positives"]))
                    and _block_ok(fr, r2["holdout"], min_rows, int(cfg["min_holdout_positives"]))):
                plan, rows, tier = ref_plan, r2, "reference_only"
    if tier is None:
        res.reason = ("Datos insuficientes para este horizonte: no hay suficientes cortes maduros, filas o positivos para "
                      "calibrar y evaluar ni siquiera la referencia con separación temporal.")
        res.manifest["blocks"] = None
        return res

    res.status = "reference_only" if tier == "reference_only" else "trained"
    res.manifest["tier"] = tier
    res.manifest["blocks"] = plan.manifest(bundle.last_complete)
    res.manifest["block_rows"] = {n: int(len(rows[n])) for n in plan.names}
    res.manifest["block_positives"] = {n: int(np.nansum(fr["y"].to_numpy()[rows[n]])) for n in plan.names}
    res.manifest["holdout_used_for"] = ["evaluación del procedimiento completo", "regla de promoción versionada"]
    res.manifest["holdout_not_used_for"] = ["modelo", "hiperparámetros", "variables", "calibrador", "umbrales"]

    y = fr["y"].to_numpy(dtype=float)
    ref_p = fr["ref_p"].to_numpy(dtype=float)
    ref_q = fr["ref_q"].to_numpy(dtype=float)
    qty = fr["qty"].to_numpy(dtype=float)
    net = fr["net"].to_numpy(dtype=float)
    cut = fr["cutoff"].to_numpy()
    brow = fr["base_row"].to_numpy()
    X = bundle.matrix(h)

    iso_min, cal_min = int(cfg["isotonic_min_positives"]), int(cfg["min_calibration_positives"])
    ref_model = ReferenceProbability()
    cal_rows, hold_rows = rows["calibration"], rows["holdout"]

    # ── probabilidad: referencia calibrada con el bloque de calibración ──
    ref_cal = fit_calibrator(ref_p[cal_rows], y[cal_rows], iso_min, cal_min)
    p_ref_hold = ref_cal.transform(ref_p[hold_rows])
    prob_info: dict[str, Any] = {
        "reference": {"calibration_method": ref_cal.method, "holdout": probability_metrics(y[hold_rows], p_ref_hold),
                      "holdout_uncalibrated": probability_metrics(y[hold_rows], np.clip(ref_p[hold_rows], 1e-4, 1 - 1e-4))}}
    art_ref = {"family": "reference", "kind": "reference", "model": ref_model, "calibrator": ref_cal, "neg_rate": 1.0,
               "feature_names": MODEL_FEATURES, "horizon": h}
    served_prob_art = art_ref
    challenger_art = None
    gate: dict[str, Any] = {"rule_version": cfg["promotion_rule_version"], "passed": False, "checks": {}}

    if tier in ("full", "lean"):
        tr = rows["train"]
        y_tr = y[tr]
        neg_rate = 1.0
        tr_idx = tr
        max_rows = int(cfg["max_train_rows"])
        if len(tr) > max_rows:
            rng = np.random.default_rng(seed)
            pos = tr[y_tr == 1]
            neg = tr[y_tr == 0]
            keep_neg = max(1, max_rows - len(pos))
            neg_rate = min(1.0, keep_neg / max(len(neg), 1))
            tr_idx = np.sort(np.concatenate([pos, rng.choice(neg, size=keep_neg, replace=False)]))
        res.manifest["train_negative_sampling"] = {"rate": float(neg_rate), "rows_used": int(len(tr_idx)), "rows_block": int(len(tr)),
                                                   "correction": "prior" if neg_rate < 1.0 else "none"}
        cands = fit_probability_candidates(X[tr_idx], y[tr_idx], seed, n_jobs, only=None if tier == "full" else ("lgbm_small",))
        sel_rows = rows["selection"] if tier == "full" else None
        cand_info: dict[str, Any] = {}
        fitted: dict[str, tuple[Any, Any]] = {}
        for name, model in cands.items():
            raw_cal = prior_correction(model.predict_raw(X[cal_rows]), neg_rate) if neg_rate < 1.0 else model.predict_raw(X[cal_rows])
            cal = fit_calibrator(raw_cal, y[cal_rows], iso_min, cal_min)
            fitted[name] = (model, cal)
            if sel_rows is None:
                cand_info[name] = {"calibration_method": cal.method, "selection": None}
                continue
            raw_sel = prior_correction(model.predict_raw(X[sel_rows]), neg_rate) if neg_rate < 1.0 else model.predict_raw(X[sel_rows])
            m_sel = probability_metrics(y[sel_rows], cal.transform(raw_sel))
            cand_info[name] = {"calibration_method": cal.method, "selection": {k: m_sel[k] for k in ("n", "n_positive", "brier", "log_loss", "ece", "pr_auc")}}
        if sel_rows is not None:
            sel_ref = probability_metrics(y[sel_rows], ref_cal.transform(ref_p[sel_rows]))
            cand_info["reference"] = {"calibration_method": ref_cal.method, "selection": {k: sel_ref[k] for k in ("n", "n_positive", "brier", "log_loss", "ece", "pr_auc")}}
        prob_info["candidates"] = cand_info
        sel_pos = int(np.nansum(y[sel_rows])) if sel_rows is not None else None
        if sel_rows is not None:
            best = min((n for n in cands), key=lambda n: (round(cand_info[n]["selection"]["brier"], 12), SIMPLICITY_ORDER.index(n) if n in SIMPLICITY_ORDER else 9))
        else:
            best = "lgbm_small"
        model, cal = fitted[best]
        challenger_art = {"family": best, "kind": "complex", "model": model, "calibrator": cal, "neg_rate": float(neg_rate),
                          "feature_names": MODEL_FEATURES, "horizon": h}
        p_ch_hold, raw_ch_hold = serve_probability(challenger_art, X[hold_rows], ref_p[hold_rows])
        m_ch = probability_metrics(y[hold_rows], p_ch_hold)
        m_ref = prob_info["reference"]["holdout"]
        impr = (m_ref["brier"] - m_ch["brier"]) / m_ref["brier"] if m_ref["brier"] else None
        ece_ok = (m_ch["ece"] is not None and m_ref["ece"] is not None
                  and m_ch["ece"] <= max(0.03, m_ref["ece"] + 0.02))
        checks = {
            "selection_positives_sufficient": True if sel_pos is None else sel_pos >= MIN_SELECTION_POSITIVES,
            "brier_relative_improvement": impr,
            "brier_improvement_threshold": float(cfg["promotion_min_rel_brier_improvement"]),
            "brier_improvement_ok": bool(impr is not None and impr >= float(cfg["promotion_min_rel_brier_improvement"])),
            "log_loss_not_worse": bool(m_ch["log_loss"] is not None and m_ref["log_loss"] is not None and m_ch["log_loss"] <= m_ref["log_loss"]),
            "ece_ok": bool(ece_ok),
        }
        gate["checks"] = checks
        gate["challenger"] = best
        gate["passed"] = bool(checks["selection_positives_sufficient"] and checks["brier_improvement_ok"]
                              and checks["log_loss_not_worse"] and checks["ece_ok"])
        prob_info["challenger"] = {"family": best, "calibration_method": cal.method, "holdout": m_ch}
        # incertidumbre de la diferencia con bootstrap por bloques de corte (informativo; no gobierna la promoción)
        loss_ref = (p_ref_hold - y[hold_rows]) ** 2
        loss_ch = (p_ch_hold - y[hold_rows]) ** 2
        prob_info["brier_difference_bootstrap"] = block_bootstrap_difference(cut[hold_rows], loss_ref, loss_ch, seed=seed)
        prob_info["brier_difference_bootstrap"]["note"] = ("positivo = el modelo complejo mejora la referencia; remuestreo por corte "
                                                          "(ventanas solapadas ⇒ filas no independientes)")
        if gate["passed"]:
            served_prob_art = challenger_art
    prob_info["gate"] = gate
    served_name = served_prob_art["family"]
    p_served_hold, raw_served_hold = serve_probability(served_prob_art, X[hold_rows], ref_p[hold_rows])
    served_metrics = probability_metrics(y[hold_rows], p_served_hold)
    ece = served_metrics["ece"]
    prob_info["served"] = {
        "family": served_name, "role": "primary" if served_prob_art["kind"] == "complex" else "fallback",
        "calibration_method": served_prob_art["calibrator"].method, "holdout": served_metrics,
        "calibration_status": ("uncalibrated" if served_prob_art["calibrator"].method == "none" else
                               ("calibrated" if (ece is not None and ece <= float(cfg["ece_alert_threshold"])) else "degraded")),
    }
    res.prob = prob_info

    # ── ranking del holdout (modelo servido vs referencia, popularidad y recencia) ──
    base_hold = bundle.base.loc[brow[hold_rows]]
    units = _unit_ids(cut[hold_rows], base_hold["customer_id"].to_numpy())
    ks = sorted({int(k) for k in cfg["ranking_eval_ks"]} | {int(cfg["ranking_primary_k"])})
    yh = y[hold_rows]
    by_product = -base_hold["product_id"].to_numpy(dtype=float)          # desempate determinista: producto ascendente (igual que en la predicción)
    ranking = {
        "served": ranking_metrics(units, p_served_hold, yh, ks, tiebreak=raw_served_hold),
        "reference": ranking_metrics(units, p_ref_hold, yh, ks, tiebreak=ref_p[hold_rows]),
        "popularity": ranking_metrics(units, base_hold["prod_buyers_90"].to_numpy(dtype=float), yh, ks, tiebreak=by_product),
        "recency": ranking_metrics(units, -base_hold["rel_recency_days"].to_numpy(dtype=float), yh, ks, tiebreak=by_product),
        "definition": ("Candidatos = relaciones comprador×producto con compras previas al corte; unidad = (corte, comprador); "
                       "precision@k sobre unidades con ≥ k candidatos, recall@k sobre unidades con ≥ 1 positivo."),
    }
    if challenger_art is not None and challenger_art is not served_prob_art:   # informativo: el candidato no promovido
        p_ch_rank, _ = serve_probability(challenger_art, X[hold_rows], ref_p[hold_rows])
        ranking["challenger_not_promoted"] = ranking_metrics(units, p_ch_rank, yh, ks)
    # ADR-P2-01 (cust-ranking-v1): fuente del ranking servido = {modelo servido, popularidad, recencia} por precision@k con margen mínimo
    ranking["selection"] = choose_ranking_source(ranking, cfg)
    res.holdout["ranking"] = ranking

    # ── cantidad condicionada a compra ──
    pos_all = np.nonzero(y == 1)[0]
    in_block = {n: np.intersect1d(rows[n], pos_all) for n in plan.names}
    cal_pos, hold_pos = in_block["calibration"], in_block["holdout"]
    qinfo: dict[str, Any] = {"definition": "Suma de TODAS las compras válidas de la ventana (cut, cut+H], condicionada a ≥ 1 compra."}
    q_ref_model = ReferenceQuantity()
    ref_scale = ratio_scale(qty[cal_pos], q_ref_model.predict(None, ref_q[cal_pos])) if len(cal_pos) else 1.0
    ref_interval = ratio_interval(qty[cal_pos], q_ref_model.predict(None, ref_q[cal_pos]) * ref_scale, float(cfg["interval_level"])) if len(cal_pos) >= 5 else (-0.5, 0.5)
    q_art_ref = {"objective": "reference", "kind": "reference", "model": q_ref_model, "scale": ref_scale,
                 "interval_log_q": ref_interval, "level": float(cfg["interval_level"]), "feature_names": MODEL_FEATURES, "horizon": h}
    q_ref_hold, ql, qh = serve_quantity(q_art_ref, X[hold_pos], ref_q[hold_pos])
    qinfo["reference"] = {"scale": ref_scale, "holdout": quantity_metrics(qty[hold_pos], q_ref_hold),
                          "interval": interval_coverage(qty[hold_pos], ql, qh)}
    served_q_art = q_art_ref
    q_gate: dict[str, Any] = {"rule_version": cfg["promotion_rule_version"], "passed": False, "checks": {}}
    q_challenger = None
    if tier in ("full", "lean"):
        tr_pos = in_block["train"]
        # «full»: objetivo elegido en el bloque de selección. «lean»: sin bloque de selección; el objetivo se elige con el
        # MAE SIN escalar del bloque de calibración (la escala es un único parámetro) y el holdout queda intacto.
        sel_pos_rows = in_block["selection"] if tier == "full" else cal_pos
        if len(tr_pos) >= 50 and len(cal_pos) >= 10 and len(sel_pos_rows) >= MIN_SELECTION_POSITIVES:
            qc = fit_quantity_candidates(X[tr_pos], qty[tr_pos], seed, n_jobs)
            q_cands: dict[str, Any] = {}
            scaled: dict[str, dict[str, Any]] = {}
            for obj, mdl in qc.items():
                scale = ratio_scale(qty[cal_pos], mdl.predict(X[cal_pos]))
                interval = ratio_interval(qty[cal_pos], mdl.predict(X[cal_pos]) * scale, float(cfg["interval_level"]))
                art = {"objective": obj, "kind": "complex", "model": mdl, "scale": scale, "interval_log_q": interval,
                       "level": float(cfg["interval_level"]), "feature_names": MODEL_FEATURES, "horizon": h}
                if tier == "full":
                    q_sel, _, _ = serve_quantity(art, X[sel_pos_rows], ref_q[sel_pos_rows])
                else:
                    q_sel = mdl.predict(X[sel_pos_rows])
                m = quantity_metrics(qty[sel_pos_rows], q_sel)
                q_cands[obj] = {"scale": scale, "selection": {k: m[k] for k in ("n", "mae", "bias", "wape", "rmse")}}
                scaled[obj] = art
            qinfo["candidates"] = q_cands
            qinfo["integer_quantities"] = "poisson" in qc
            admissible = [o for o in q_cands if q_cands[o]["selection"]["bias"] is not None
                          and abs(q_cands[o]["selection"]["bias"]) <= QTY_SELECTION_MAX_ABS_BIAS]
            pool = admissible or list(q_cands)
            key = (lambda o: q_cands[o]["selection"]["mae"]) if admissible else (lambda o: abs(q_cands[o]["selection"]["bias"] or 9))
            best_obj = min(pool, key=key)
            where = "selección" if tier == "full" else "calibración (sin escalar; nivel lean)"
            qinfo["selection_rule"] = (f"menor MAE en el bloque de {where} entre objetivos con |sesgo| ≤ {QTY_SELECTION_MAX_ABS_BIAS:.0%}"
                                       if admissible else "ningún objetivo cumple el sesgo: menor |sesgo|")
            q_challenger = scaled[best_obj]
            q_ch_hold, cl, ch = serve_quantity(q_challenger, X[hold_pos], ref_q[hold_pos])
            m_q_ch = quantity_metrics(qty[hold_pos], q_ch_hold)
            m_q_ref = qinfo["reference"]["holdout"]
            impr_q = (m_q_ref["mae"] - m_q_ch["mae"]) / m_q_ref["mae"] if m_q_ref["mae"] else None
            bias_ch = m_q_ch["bias"]
            q_checks = {
                "mae_relative_improvement": impr_q,
                "mae_improvement_threshold": float(cfg["promotion_qty_min_rel_mae_improvement"]),
                "mae_improvement_ok": bool(impr_q is not None and impr_q >= float(cfg["promotion_qty_min_rel_mae_improvement"])),
                "bias_ok": bool(bias_ch is not None and abs(bias_ch) <= max(float(cfg["promotion_qty_max_abs_bias"]),
                                                                           abs(m_q_ref["bias"] or 0.0))),
            }
            q_gate["checks"] = q_checks
            q_gate["challenger"] = best_obj
            q_gate["passed"] = bool(q_checks["mae_improvement_ok"] and q_checks["bias_ok"])
            qinfo["challenger"] = {"objective": best_obj, "holdout": m_q_ch, "interval": interval_coverage(qty[hold_pos], cl, ch)}
            if q_gate["passed"]:
                served_q_art = q_challenger
        else:
            qinfo["candidates"] = None
            qinfo["note"] = "Positivos insuficientes para ajustar y seleccionar el modelo de cantidad: se sirve la referencia histórica."
    qinfo["gate"] = q_gate
    q_served_hold, ql, qh = serve_quantity(served_q_art, X[hold_pos], ref_q[hold_pos])
    qinfo["served"] = {"objective": served_q_art["objective"], "role": "primary" if served_q_art["kind"] == "complex" else "fallback",
                       "holdout": quantity_metrics(qty[hold_pos], q_served_hold),
                       "interval": interval_coverage(qty[hold_pos], ql, qh)}
    res.qty = qinfo

    # ── cantidad esperada incondicional (incluye a quienes no compraron) y monto ──
    q_all_served, _, _ = serve_quantity(served_q_art, X[hold_rows], ref_q[hold_rows])
    q_all_ref, _, _ = serve_quantity(q_art_ref, X[hold_rows], ref_q[hold_rows])
    e_served = p_served_hold * q_all_served
    e_ref = p_ref_hold * q_all_ref
    res.holdout["expected_quantity"] = {
        "definition": "E[cantidad total en (corte, corte+H]] = P(compra) × E[cantidad | compra]; filas = TODAS las del holdout (incluye no compradores).",
        "served": quantity_metrics(qty[hold_rows], e_served), "reference": quantity_metrics(qty[hold_rows], e_ref),
        "n_rows_non_buyers": int((y[hold_rows] == 0).sum())}
    price = bundle.base.loc[brow[hold_rows], "last_net_price"].to_numpy(dtype=float)
    ok_price = np.isfinite(price)
    res.holdout["expected_amount"] = {
        "definition": ("Monto esperado = cantidad esperada × último precio neto de la relación (supuesto explícito; nunca una venta "
                       "confirmada). Real = importe neto de las compras de la ventana."),
        "served": quantity_metrics(net[hold_rows][ok_price], (e_served * price)[ok_price]),
        "reference": quantity_metrics(net[hold_rows][ok_price], (e_ref * price)[ok_price]),
        "price_assumption": "last_net_price_of_relation", "rows_without_price": int((~ok_price).sum())}
    res.artifacts = {"prob": served_prob_art, "qty": served_q_art, "prob_challenger": challenger_art, "qty_challenger": q_challenger,
                     "prob_reference": art_ref, "qty_reference": q_art_ref}
    res.holdout["blocks_rows"] = res.manifest["block_rows"]
    res.duration_s = time.perf_counter() - t0
    res.manifest["promotion"] = {"probability": gate, "quantity": q_gate}
    return res


def evaluate_coherence(bundle: DatasetBundle, results: dict[int, HorizonResult]) -> dict[str, Any]:
    """Efecto de la corrección de coherencia P(H1) ≤ P(H2) para cada par de horizontes consecutivos.

    Para el par (a < b) se usan las filas del holdout de b que existen (válidas) en el dataset de a:
    - «limpias»: además caen en el holdout de a (ningún modelo las vio al ajustar ni calibrar);
    - «todas»: incluyen filas que el modelo de a pudo ver en entrenamiento/calibración (diagnóstico optimista).
    Se informa la tasa de violación P_a > P_b y el Brier/log loss de b antes y después de la corrección.
    """
    served = {h: r for h, r in results.items() if r.status in ("trained", "reference_only") and r.artifacts}
    horizons = sorted(served)
    out: dict[str, Any] = {"rule": "cumulative_max", "horizons": horizons, "pairs": {}}
    if len(horizons) < 2:
        out["evaluated"] = False
        out["note"] = "Un solo horizonte servido: no hay anidamiento que evaluar."
        return out
    frames = {h: bundle.per_horizon[h].frame for h in horizons}
    positions = {h: pd.Series(np.arange(len(frames[h])), index=frames[h]["base_row"].to_numpy()) for h in horizons}
    any_clean = False
    for a, b in zip(horizons[:-1], horizons[1:]):
        fb, fa = frames[b], frames[a]
        hold_b = set(_holdout_cutoffs(served[b]))
        hold_a = set(_holdout_cutoffs(served[a]))
        sub_b = np.nonzero(fb["cutoff"].isin(hold_b).to_numpy())[0]
        keys = fb["base_row"].to_numpy()[sub_b]
        in_a = np.isin(keys, positions[a].index.to_numpy())
        sub_b, keys = sub_b[in_a], keys[in_a]
        pair: dict[str, Any] = {"n_rows_all": int(len(sub_b))}
        if len(sub_b) == 0:
            pair["evaluated"] = False
            out["pairs"][f"{a}_{b}"] = pair
            continue
        pos_a = positions[a].loc[keys].to_numpy()
        p_b, _ = serve_probability(served[b].artifacts["prob"], bundle.matrix(b, sub_b), fb["ref_p"].to_numpy(dtype=float)[sub_b])
        p_a, _ = serve_probability(served[a].artifacts["prob"], bundle.matrix(a, pos_a), fa["ref_p"].to_numpy(dtype=float)[pos_a])
        y_b = fb["y"].to_numpy(dtype=float)[sub_b]
        clean = np.isin(fa["cutoff"].to_numpy()[pos_a], list(hold_a))
        corrected = np.maximum(p_b, p_a)
        for label, mask in (("all", np.ones(len(sub_b), dtype=bool)), ("clean", clean)):
            if mask.sum() == 0:
                pair[label] = None
                continue
            pair[label] = {
                "n_rows": int(mask.sum()), "violation_rate": float((p_a[mask] > p_b[mask] + 1e-12).mean()),
                "mean_correction": float((corrected[mask] - p_b[mask]).mean()),
                "brier_before": float(np.mean((p_b[mask] - y_b[mask]) ** 2)), "brier_after": float(np.mean((corrected[mask] - y_b[mask]) ** 2)),
                "log_loss_before": probability_metrics(y_b[mask], p_b[mask])["log_loss"],
                "log_loss_after": probability_metrics(y_b[mask], corrected[mask])["log_loss"]}
        pair["evaluated"] = True
        pair["clean_rows_available"] = bool(clean.sum() > 0)
        any_clean = any_clean or bool(clean.sum() > 0)
        out["pairs"][f"{a}_{b}"] = pair
    out["evaluated"] = any(p.get("evaluated") for p in out["pairs"].values())
    out["any_pair_with_unseen_rows"] = any_clean
    out["note"] = ("Eventos anidados ((c,c+7] ⊂ (c,c+30] ⊂ (c,c+90]): se aplica el máximo acumulado sobre los horizontes crecientes "
                   "y se registra la tasa de corrección. No se multiplica P7 para obtener P30/P90. «clean» = filas que ningún "
                   "modelo vio; «all» puede ser optimista porque el modelo del horizonte corto pudo entrenarse con esas filas.")
    return out


def _holdout_cutoffs(r: HorizonResult) -> list[int]:
    b = (r.manifest.get("blocks") or {}).get("holdout")
    if not b:
        return []
    from predictily_engine.customer.events import to_day
    lo, hi = int(to_day(b["cutoff_from"])), int(to_day(b["cutoff_to"]))
    return list(range(lo, hi + 1))
