"""E(cantidad por pedido | ocurre) por comprador × producto y horizonte, con su intervalo (V2.5 §7, §25.2; ADR-V25-11).

Definición (docs/ml/CUSTOMER_REPURCHASE.md §3): en una ventana (corte, corte + H] con al menos un pedido, la cantidad POR PEDIDO es
Σ cantidades / nº de pedidos (pedido = día de compra de la relación). Se estima solo con ventanas con compra, ponderando cada ventana por su
nº de pedidos (el promedio ponderado es la cantidad media de UN pedido). Es distinta de `quantity_if_purchase` (suma de TODAS las compras
de la ventana, condicionada a ≥ 1 compra), que se conserva por compatibilidad.

Candidatos: referencia histórica (media de las últimas 5 compras de la relación) y LightGBM con los mismos objetivos que la cantidad
condicionada (L2, L2 sobre log1p, Tweedie, Gamma y Poisson solo con cantidades enteras). Mismo protocolo que la cantidad: escala y
intervalo conformal multiplicativo del 80 % con el bloque de calibración; elección del objetivo en el bloque de selección (o calibración
en «lean»); el holdout solo aplica la regla versionada (mejora de MAE y sesgo), sin ajustar nada.
"""

from __future__ import annotations

from typing import Any

import numpy as np

from predictily_engine.customer.metrics import interval_coverage, quantity_metrics
from predictily_engine.customer.models import ReferenceQuantity, fit_quantity_candidates, ratio_interval, ratio_scale
from predictily_engine.customer.serving import serve_quantity

MIN_SELECTION_POSITIVES = 20
SELECTION_MAX_ABS_BIAS = 0.15


def reference_per_order(qty_mean5, qty_per_purchase_all) -> np.ndarray:
    """Referencia: media de las últimas 5 compras de la relación; si falta, la media histórica por compra; si falta, la mediana global."""
    a = np.asarray(qty_mean5, dtype=np.float64)
    b = np.asarray(qty_per_purchase_all, dtype=np.float64)
    out = np.where(np.isfinite(a), a, b)
    fill = float(np.nanmedian(out)) if np.isfinite(out).any() else 1.0
    return np.where(np.isfinite(out), out, fill)


def fit_per_order_head(X: np.ndarray, target: np.ndarray, weights: np.ndarray, ref: np.ndarray, rows: dict[str, np.ndarray], tier: str,
                       cfg: dict[str, Any], seed: int, n_jobs: int, horizon: int | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
    """`rows`: filas CON compra de cada bloque (train, calibration, selection, holdout). Devuelve (artefacto servido, información)."""
    cal, hold, tr = rows["calibration"], rows["holdout"], rows.get("train", np.zeros(0, dtype=np.int64))
    level = float(cfg["interval_level"])
    ref_model = ReferenceQuantity()
    ref_pred_cal = ref_model.predict(None, ref[cal]) if len(cal) else np.zeros(0)
    ref_scale = ratio_scale(target[cal], ref_pred_cal) if len(cal) else 1.0
    ref_interval = ratio_interval(target[cal], ref_pred_cal * ref_scale, level) if len(cal) >= 5 else (-0.5, 0.5)
    art_ref = {"objective": "reference", "kind": "reference", "model": ref_model, "scale": ref_scale, "interval_log_q": ref_interval,
               "level": level, "horizon": horizon}
    q_ref, lo_ref, hi_ref = serve_quantity(art_ref, X[hold], ref[hold])
    info: dict[str, Any] = {
        "definition": "Cantidad media por pedido en ventanas con ≥ 1 pedido (Σ cantidades / nº de pedidos), ponderada por nº de pedidos.",
        "reference": {"scale": ref_scale, "holdout": quantity_metrics(target[hold], q_ref), "interval": interval_coverage(target[hold], lo_ref, hi_ref)}}
    served, challenger = art_ref, None
    gate: dict[str, Any] = {"rule_version": cfg["promotion_rule_version"], "passed": False, "checks": {}}
    sel = rows.get("selection") if tier == "full" else cal
    if tier in ("full", "lean") and len(tr) >= 50 and len(cal) >= 10 and sel is not None and len(sel) >= MIN_SELECTION_POSITIVES:
        cands = fit_quantity_candidates(X[tr], target[tr], seed, n_jobs, sample_weight=weights[tr])
        arts: dict[str, dict[str, Any]] = {}
        cand_info: dict[str, Any] = {}
        for obj, mdl in cands.items():
            scale = ratio_scale(target[cal], mdl.predict(X[cal]))
            interval = ratio_interval(target[cal], mdl.predict(X[cal]) * scale, level)
            art = {"objective": obj, "kind": "complex", "model": mdl, "scale": scale, "interval_log_q": interval, "level": level,
                   "horizon": horizon}
            pred_sel = serve_quantity(art, X[sel], ref[sel])[0] if tier == "full" else mdl.predict(X[sel])
            m = quantity_metrics(target[sel], pred_sel)
            cand_info[obj] = {"scale": scale, "selection": {k: m[k] for k in ("n", "mae", "bias", "wape", "rmse")}}
            arts[obj] = art
        info["candidates"] = cand_info
        admissible = [o for o in cand_info if cand_info[o]["selection"]["bias"] is not None
                      and abs(cand_info[o]["selection"]["bias"]) <= SELECTION_MAX_ABS_BIAS]
        pool = admissible or list(cand_info)
        key = (lambda o: cand_info[o]["selection"]["mae"]) if admissible else (lambda o: abs(cand_info[o]["selection"]["bias"] or 9))
        best = min(pool, key=key)
        where = "selección" if tier == "full" else "calibración (sin escalar; nivel lean)"
        info["selection_rule"] = (f"menor MAE en el bloque de {where} entre objetivos con |sesgo| ≤ {SELECTION_MAX_ABS_BIAS:.0%}"
                                  if admissible else "ningún objetivo cumple el sesgo: menor |sesgo|")
        challenger = arts[best]
        q_ch, lo_ch, hi_ch = serve_quantity(challenger, X[hold], ref[hold])
        m_ch, m_ref = quantity_metrics(target[hold], q_ch), info["reference"]["holdout"]
        impr = (m_ref["mae"] - m_ch["mae"]) / m_ref["mae"] if m_ref["mae"] else None
        bias_ch = m_ch["bias"]
        checks = {
            "mae_relative_improvement": impr,
            "mae_improvement_threshold": float(cfg["promotion_qty_min_rel_mae_improvement"]),
            "mae_improvement_ok": bool(impr is not None and impr >= float(cfg["promotion_qty_min_rel_mae_improvement"])),
            "bias_ok": bool(bias_ch is not None and abs(bias_ch) <= max(float(cfg["promotion_qty_max_abs_bias"]), abs(m_ref["bias"] or 0.0))),
        }
        gate["checks"], gate["challenger"] = checks, best
        gate["passed"] = bool(checks["mae_improvement_ok"] and checks["bias_ok"])
        info["challenger"] = {"objective": best, "holdout": m_ch, "interval": interval_coverage(target[hold], lo_ch, hi_ch)}
        if gate["passed"]:
            served = challenger
    else:
        info["candidates"] = None
        info["note"] = "Positivos insuficientes para ajustar y seleccionar el modelo de cantidad por pedido: se sirve la referencia histórica."
    info["gate"] = gate
    q_s, lo_s, hi_s = serve_quantity(served, X[hold], ref[hold])
    info["served"] = {"objective": served["objective"], "role": "primary" if served["kind"] == "complex" else "fallback",
                      "holdout": quantity_metrics(target[hold], q_s), "interval": interval_coverage(target[hold], lo_s, hi_s),
                      "interval_level": level}
    return served, {"info": info, "reference_art": art_ref, "challenger_art": challenger}
