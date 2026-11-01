"""Customer Core S05 (W3-C): número de pedidos (hurdle), cantidad por pedido, unidades, ventana, segmentos, ranking económico y drift.

`train_s05` se ejecuta al final de `train.train_horizon` con los bloques, las matrices y las probabilidades servidas del horizonte: AGREGA
métricas y artefactos sin modificar la probabilidad ni la cantidad condicionada servidas. `finalize_s05` se ejecuta UNA VEZ después de entrenar todos los
horizontes: aplica el anidamiento de E(N) entre horizontes sobre las mismas filas del holdout, vuelve a decidir con el E(N) FINAL servido la promoción por
desvianza y el gate de sesgo, y reescribe las métricas que dependen de él (pedidos, unidades, segmentos). `apply_s05` se ejecuta en la predicción diaria
(`predict.predict_all`) con los artefactos de producción y agrega las columnas del contrato de `customer_predictions`.

Todo lo que el entrenamiento ajusta (conteo condicionado, cantidad por pedido, cortes de segmento, escala, dispersión, intervalos, niveles de la ventana) usa los
bloques de entrenamiento, calibración y selección (y cortes de ajuste de la ventana anteriores a todo holdout); el holdout solo evalúa y aplica reglas
versionadas. Las estructuras que viajan en los artefactos firmados (W1-B) están bajo la clave `s05`:
  · artefacto de cantidad  → {"version", "orders", "per_order", "window"}   (el mismo objeto en servido, retador y referencia)
  · artefacto de probabilidad → {"version", "segmenter", "drift_reference"}
Un artefacto entrenado antes de W3-C (o con otra forma de `orders`) no trae `s05` utilizable: sus filas salen con las columnas nuevas nulas y `s05_unavailable`
en las banderas.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer import S05_VERSION
from predictily_engine.customer.drift import drift_across_cutoffs, drift_between, psi_against_reference, reference_distribution
from predictily_engine.customer.metrics import brier_score, quantity_metrics
from predictily_engine.customer.orders import (
    ORDERS_FORM,
    bias_decomposition,
    count_metrics,
    enforce_nested_orders,
    fit_orders_head,
    hurdle_orders,
    reference_orders,
    serve_conditional_orders,
)
from predictily_engine.customer.per_order import fit_per_order_head, reference_per_order
from predictily_engine.customer.ranking_eval import ranking_extension
from predictily_engine.customer.segments import Segmenter, evaluate_segment_recalibration, segment_calibration
from predictily_engine.customer.serving import serve_probability, serve_quantity
from predictily_engine.customer.window import WINDOW_COLUMNS, WindowParams, apply_validation, window_from_base


def _base_col(bundle, name: str, brow: np.ndarray) -> np.ndarray:
    return bundle.base[name].to_numpy(dtype=float)[brow]


def orders_art(ext: dict[str, Any]) -> dict[str, Any] | None:
    """Artefacto de pedidos utilizable: solo la forma hurdle vigente (un artefacto con otra forma no se sirve)."""
    art = (ext or {}).get("orders")
    return art if (art is not None and art.get("form") == ORDERS_FORM) else None


def train_s05(bundle, h: int, cfg: dict[str, Any], seed: int, n_jobs: int, ctx: dict[str, Any]) -> dict[str, Any]:
    """Entrena y evalúa lo de S05 para el horizonte `h`.

    `ctx` (calculado por `train_horizon`): tier, rows (bloque → filas del frame), fr (frame del horizonte), X, y, qty, cut, brow, ref_p, served_prob_art, p_served_hold,
    raw_served_hold, p_ref_hold, e_served_hold, e_ref_hold, units, ks, by_product (desempate).
    Devuelve {"info": JSON-able, "ext_q": ..., "ext_p": ..., "runtime": ...}; `ext_q` / `ext_p` se adjuntan a los artefactos en `train_horizon` y `runtime`
    (arreglos y artefactos que NO se persisten) lo usa `finalize_s05`."""
    tier, rows, fr = ctx["tier"], ctx["rows"], ctx["fr"]
    X, y, qty, cut, brow = ctx["X"], ctx["y"], ctx["qty"], ctx["cut"], ctx["brow"]
    cal, hold = rows["calibration"], rows["holdout"]
    sel = rows.get("selection") if tier == "full" else None
    n_pd = fr["purchase_days"].to_numpy(dtype=float)
    p_hold, p_ref_hold = ctx["p_served_hold"], ctx["p_ref_hold"]
    info: dict[str, Any] = {"version": S05_VERSION, "horizon_days": h, "tier": tier}

    # ── nº esperado de pedidos: hurdle E(N) = P × E(N | N ≥ 1), conteo condicionado propio ajustado solo con los positivos ──
    ref_lam = reference_orders(_base_col(bundle, "rel_n_365", brow), _base_col(bundle, "rel_tenure_days", brow), h)
    served_o, pack_o = fit_orders_head(X, n_pd, y, ref_lam, rows, tier, cfg, seed, n_jobs, p_hold=p_hold, horizon=h)
    info["orders"] = pack_o["info"]

    # ── cantidad por pedido | ocurre ──
    pos = np.nonzero(y == 1)[0]
    rows_pos = {n: np.intersect1d(idx, pos) for n, idx in rows.items()}
    target = np.where(n_pd > 0, qty / np.maximum(n_pd, 1.0), np.nan)
    ref_po = reference_per_order(_base_col(bundle, "qty_mean5", brow), _base_col(bundle, "qty_per_purchase_all", brow))
    served_po, pack_po = fit_per_order_head(X, target, n_pd, ref_po, rows_pos, tier, cfg, seed, n_jobs, horizon=h)
    info["per_order"] = pack_po["info"]

    # ── unidades esperadas = E(pedidos) × E(cantidad por pedido) frente a P × E(cantidad | compra) y a la referencia ──
    e_orders_hold = hurdle_orders(p_hold, serve_conditional_orders(served_o, X[hold], ref_lam[hold]))
    q_po_hold = serve_quantity(served_po, X[hold], ref_po[hold])[0]
    units_s05 = e_orders_hold * q_po_hold
    e_orders_ref = hurdle_orders(p_ref_hold, serve_conditional_orders(pack_o["reference_art"], X[hold], ref_lam[hold]))
    units_ref = e_orders_ref * serve_quantity(pack_po["reference_art"], X[hold], ref_po[hold])[0]
    info["expected_units"] = {
        "definition": ("E(unidades) = E(nº pedidos) × E(cantidad por pedido | ocurre), bajo independencia entre el nº de pedidos y su cantidad "
                       "(supuesto documentado). expected_quantity vigente = P × E(cantidad total de la ventana | compra): se conserva por compatibilidad."),
        "independence_assumption": "N y la cantidad por pedido se tratan como independientes dentro de la relación; no se estima su covarianza.",
        "s05_orders_times_per_order": quantity_metrics(qty[hold], units_s05),
        "reference_orders_times_per_order": quantity_metrics(qty[hold], units_ref),
        "expected_quantity_served": quantity_metrics(qty[hold], ctx["e_served_hold"]),
        "expected_quantity_reference": quantity_metrics(qty[hold], ctx["e_ref_hold"]),
        "n_rows": int(len(hold)), "nesting_applied": False}

    # ── segmentos, calibración por segmento y cold start ──
    base_cal = bundle.base.loc[brow[cal]]
    segmenter = Segmenter.fit(base_cal, cfg)
    base_hold = bundle.base.loc[brow[hold]]
    keys_hold = segmenter.keys(base_hold)
    yh = y[hold]
    seg_rows = segment_calibration(yh, p_hold, p_ref_hold, keys_hold, cfg)
    _segment_orders(seg_rows, keys_hold, n_pd[hold], e_orders_hold, e_orders_ref)
    info["segments"] = {"segmenter": segmenter.as_dict(), "rows": seg_rows, "primary_dimension": "frequency",
                        "min_rows": int(cfg["segment_min_rows"]), "min_positives": int(cfg["segment_min_positives"])}
    cs = [r for r in seg_rows if r["dimension"] == "history" and r["segment"] == "history:cold_start"]
    info["cold_start"] = {"definition": f"relaciones con menos de {int(cfg['cold_start_min_purchases'])} compras al corte (umbral a aprobar en el piloto)",
                          **(cs[0] if cs else {"status": "insufficient_data", "n": 0})}
    p_cal, _ = serve_probability(ctx["served_prob_art"], X[cal], ctx["ref_p"][cal])
    sel_args = (None, None, None)
    if sel is not None and len(sel):
        p_sel, _ = serve_probability(ctx["served_prob_art"], X[sel], ctx["ref_p"][sel])
        sel_args = (p_sel, y[sel], segmenter.primary(bundle.base.loc[brow[sel]]))
    info["segment_recalibration"] = evaluate_segment_recalibration(
        p_cal, y[cal], segmenter.primary(base_cal), *sel_args, p_hold, yh, keys_hold["frequency"], cfg)

    # ── ranking: lift@k, PR-AUC y utilidad económica frente a popularidad y recencia ──
    units = ctx["units"]
    price = base_hold["last_net_price"].to_numpy(dtype=float)
    by_product = ctx["by_product"]
    sources = {"served": (p_hold, ctx["raw_served_hold"]), "reference": (p_ref_hold, ctx["ref_p"][hold]),
               "popularity": (base_hold["prod_buyers_90"].to_numpy(dtype=float), by_product),
               "recency": (-base_hold["rel_recency_days"].to_numpy(dtype=float), by_product)}
    info["ranking"] = ranking_extension(units, yh, qty[hold], price, sources, ctx["ks"], int(cfg["ranking_primary_k"]),
                                        [float(f) for f in cfg["lift_global_fractions"]], cost=None)

    # ── estabilidad entre cortes y drift ──
    cuts = cut[hold]
    per_cut = []
    for c in np.unique(cuts):
        m = cuts == c
        if int(m.sum()) < int(cfg["stability_min_rows"]):
            continue
        bs, br = brier_score(yh[m], p_hold[m]), brier_score(yh[m], p_ref_hold[m])
        per_cut.append({"cutoff": int(c), "n": int(m.sum()), "brier_served": bs, "brier_reference": br, "served_not_worse": bool(bs <= br + 1e-12)})
    b_served = [x["brier_served"] for x in per_cut]
    info["stability"] = {
        "definition": "Brier del modelo servido y de la referencia por corte del holdout (cortes con ≥ stability_min_rows filas).",
        "per_cutoff": per_cut, "n_cutoffs": len(per_cut),
        "brier_served_std": float(np.std(b_served)) if len(b_served) > 1 else None,
        "share_cutoffs_served_not_worse_than_reference": float(np.mean([x["served_not_worse"] for x in per_cut])) if per_cut else None,
        "note": "Cortes con ventanas solapadas: no independientes; sin intervalos de confianza por fila."}
    ref_dist = reference_distribution(p_hold, int(cfg["drift_bins"]))
    info["drift"] = {"calibration_to_holdout": drift_between(p_cal, y[cal], p_hold, yh, cfg),
                     "across_holdout_cutoffs": drift_across_cutoffs(cuts, p_hold, yh, cfg),
                     "reference_distribution": {**ref_dist, "definition": "Probabilidades servidas del holdout: línea base de la vigilancia diaria (no decide ni ajusta nada)."}}

    ext_q = {"version": S05_VERSION, "orders": served_o, "per_order": served_po, "window": None}
    ext_p = {"version": S05_VERSION, "segmenter": segmenter, "drift_reference": ref_dist}
    runtime = {"hold": hold, "n_pd": n_pd[hold], "orders_pack": pack_o, "per_order_pack": pack_po}
    return {"info": info, "ext_q": ext_q, "ext_p": ext_p, "runtime": runtime}


def _segment_orders(seg_rows: list[dict[str, Any]], keys_hold: dict[str, np.ndarray], n_hold: np.ndarray, e_served: np.ndarray, e_ref: np.ndarray) -> None:
    for row in seg_rows:           # nº de pedidos por segmento (cold start incluido): errores del E(N) servido y de la referencia
        if row["status"] == "insufficient_data":
            row["orders"] = None
            continue
        m = keys_hold[row["dimension"]] == row["segment"]
        row["orders"] = {"served": count_metrics(n_hold[m], e_served[m]), "reference": count_metrics(n_hold[m], e_ref[m])}


# ───────────────────────────── finalización entre horizontes ─────────────────────────────

def _usable(r: Any) -> bool:
    return (r.status in ("trained", "reference_only") and bool(r.s05) and r.s05.get("status") != "failed" and "orders" in r.s05
            and bool(getattr(r, "s05_runtime", None)) and bool(r.artifacts))


def _chain(bundle, results: dict[int, Any], horizons: list[int], cond_art_of) -> dict[int, dict[str, np.ndarray]]:
    """E(N) FINAL de las filas del holdout de cada horizonte `b`: para cada horizonte `a ≤ b` en que la fila existe se calcula P_a × E(N | N ≥ 1)_a con los
    modelos de `a`, la probabilidad se anida (máximo acumulado) y E(N) se anida (máximo acumulado), igual que en la predicción diaria. Una fila ausente del
    marco de `a` (etiqueta inmadura o censurada en ese horizonte) no puede predecirse con el modelo de `a` y se salta."""
    base = bundle.base
    n365, tenure = base["rel_n_365"].to_numpy(dtype=float), base["rel_tenure_days"].to_numpy(dtype=float)
    positions = {a: pd.Series(np.arange(len(bundle.per_horizon[a].frame)), index=bundle.per_horizon[a].frame["base_row"].to_numpy()) for a in horizons}
    out: dict[int, dict[str, np.ndarray]] = {}
    for b in horizons:
        hold = results[b].s05_runtime["hold"]
        brow_b = bundle.per_horizon[b].frame["base_row"].to_numpy()[hold]
        m = len(hold)
        run_p, run_e = np.zeros(m), np.zeros(m)
        e_raw = e_pnest = p_raw = np.zeros(m)
        for a in horizons:
            if a > b:
                break
            if a == b:
                idx, pos, brow_a = np.arange(m), hold, brow_b
            else:
                ps = positions[a].reindex(brow_b).to_numpy()
                present = ~np.isnan(ps)
                if not present.any():
                    continue
                idx, pos, brow_a = np.nonzero(present)[0], ps[present].astype(np.int64), brow_b[present]
            fa = bundle.per_horizon[a].frame
            Xa = bundle.matrix(a, pos)
            pa, _ = serve_probability(results[a].artifacts["prob"], Xa, fa["ref_p"].to_numpy(dtype=float)[pos])
            cond = serve_conditional_orders(cond_art_of(a), Xa, reference_orders(n365[brow_a], tenure[brow_a], a))
            run_p[idx] = np.maximum(run_p[idx], pa)
            ea = run_p[idx] * cond
            if a == b:
                p_raw, e_raw, e_pnest = pa, pa * cond, ea
            run_e[idx] = np.maximum(run_e[idx], ea)
        out[b] = {"e_final": run_e, "e_raw": e_raw, "e_pnest": e_pnest, "p_raw": p_raw}
    return out


def finalize_s05(bundle, results: dict[int, Any], cfg: dict[str, Any]) -> dict[str, Any]:
    """Cierra S05 una vez entrenados todos los horizontes (llamar después de `train_horizon` de todos y antes de persistir):
    1. calcula el E(N) FINAL de las filas del holdout (hurdle + anidamiento entre horizontes) con la referencia y con el retador de cada horizonte;
    2. decide con ese E(N) final la promoción (desvianza ≥ `orders_min_rel_deviance_improvement`) y el gate de sesgo (|sesgo| ≤ `orders_max_abs_bias`),
       y degrada a la referencia el horizonte que no los cumpla, repitiendo hasta que el servido real cumpla (el artefacto compartido `s05.orders` se reemplaza);
    3. mide por horizonte la fracción de filas y la masa que corrige el anidamiento (de P y de E) y reescribe pedidos, unidades y segmentos con el E(N) final.
    El holdout solo evalúa y aplica la regla versionada; no ajusta ningún parámetro."""
    ok = sorted(h for h, r in results.items() if _usable(r))
    if not ok:
        return {"status": "skipped", "horizons": []}
    bound, thr = float(cfg["orders_max_abs_bias"]), float(cfg["orders_min_rel_deviance_improvement"])
    packs = {h: results[h].s05_runtime["orders_pack"] for h in ok}
    n_hold = {h: results[h].s05_runtime["n_pd"] for h in ok}
    challenged = [h for h in ok if packs[h]["challenger_art"] is not None]
    chain_ref = _chain(bundle, results, ok, lambda h: packs[h]["reference_art"])
    chain_cx = _chain(bundle, results, ok, lambda h: packs[h]["challenger_art"] or packs[h]["reference_art"]) if challenged else chain_ref
    selected: dict[int, dict[str, Any]] = {}
    final_gate: dict[int, dict[str, Any] | None] = {}
    for h in ok:
        selected[h] = packs[h]["reference_art"]
        final_gate[h] = None
        if h not in challenged:
            continue
        m_ref, m_cx = count_metrics(n_hold[h], chain_ref[h]["e_final"]), count_metrics(n_hold[h], chain_cx[h]["e_final"])
        impr = ((m_ref["poisson_deviance"] - m_cx["poisson_deviance"]) / m_ref["poisson_deviance"]) if m_ref["poisson_deviance"] else None
        checks = {"deviance_relative_improvement": impr, "deviance_improvement_threshold": thr,
                  "deviance_improvement_ok": bool(impr is not None and impr >= thr), "served_bias": m_cx["bias"], "bias_bound": bound,
                  "bias_ok": bool(m_cx["bias"] is not None and abs(m_cx["bias"]) <= bound),
                  "evaluated_on": "E(N) final servido: hurdle P × E(N | N ≥ 1) con P y E(N) anidados entre horizontes sobre las mismas filas del holdout"}
        passed = bool(checks["deviance_improvement_ok"] and checks["bias_ok"])
        final_gate[h] = {"checks": checks, "passed": passed}
        if passed:
            selected[h] = packs[h]["challenger_art"]
    chain_srv = _chain(bundle, results, ok, lambda h: selected[h])
    for _ in range(len(ok)):        # el servido real (cadena mixta) también debe cumplir el sesgo: si no, ese horizonte vuelve a la referencia
        bad = [h for h in ok if selected[h]["kind"] == "complex"
               and not (count_metrics(n_hold[h], chain_srv[h]["e_final"])["bias"] is not None
                        and abs(count_metrics(n_hold[h], chain_srv[h]["e_final"])["bias"]) <= bound)]
        if not bad:
            break
        for h in bad:
            selected[h] = packs[h]["reference_art"]
            final_gate[h] = {**(final_gate[h] or {}), "passed": False, "demoted_by_mixed_chain_bias": True}
        chain_srv = _chain(bundle, results, ok, lambda h: selected[h])
    base = bundle.base
    report: dict[str, Any] = {"status": "ok", "horizons": ok, "rule": "máximo acumulado de P y de E(N) entre horizontes (ventanas anidadas)", "per_horizon": {}}
    for h in ok:
        r = results[h]
        o, eu = r.s05["orders"], r.s05["expected_units"]
        ext_q = r.artifacts["qty"]["s05"]
        ext_q["orders"] = selected[h]
        fr = bundle.per_horizon[h].frame
        hold = r.s05_runtime["hold"]
        brow = fr["base_row"].to_numpy()[hold]
        n = n_hold[h]
        e_fin, e_raw, e_pn, p_raw = chain_srv[h]["e_final"], chain_srv[h]["e_raw"], chain_srv[h]["e_pnest"], chain_srv[h]["p_raw"]
        m_fin, m_raw = count_metrics(n, e_fin), count_metrics(n, e_raw)
        total_raw = float(e_raw.sum())
        nesting = {
            "rows": int(len(n)), "rule": report["rule"],
            "p_nesting": {"adjusted_rows": int((e_pn > e_raw + 1e-12).sum()), "adjusted_share": float((e_pn > e_raw + 1e-12).mean()) if len(n) else 0.0,
                          "mass_share": float((e_pn - e_raw).sum() / total_raw) if total_raw > 0 else 0.0},
            "orders_nesting": {"adjusted_rows": int((e_fin > e_pn + 1e-12).sum()), "adjusted_share": float((e_fin > e_pn + 1e-12).mean()) if len(n) else 0.0,
                               "mass_share": float((e_fin - e_pn).sum() / total_raw) if total_raw > 0 else 0.0},
            "adjusted_share": float((e_fin > e_raw + 1e-12).mean()) if len(n) else 0.0,
            "mass_share": float((e_fin - e_raw).sum() / total_raw) if total_raw > 0 else 0.0,
            "bias_before": m_raw["bias"], "bias_after": m_fin["bias"], "chain_horizons": [a for a in ok if a <= h]}
        hold_pos = hold[r.s05_runtime["n_pd"] > 0]
        cond_pos = serve_conditional_orders(selected[h], bundle.matrix(h, hold_pos), reference_orders(
            _base_col(bundle, "rel_n_365", fr["base_row"].to_numpy()[hold_pos]), _base_col(bundle, "rel_tenure_days", fr["base_row"].to_numpy()[hold_pos]), h))
        n_pos = fr["purchase_days"].to_numpy(dtype=float)[hold_pos]
        o["served"] = {
            "family": selected[h]["family"], "role": "primary" if selected[h]["kind"] == "complex" else "fallback", "alpha": selected[h]["alpha"],
            "holdout": m_fin, "holdout_pre_nesting": m_raw, "conditional_holdout": count_metrics(n_pos, cond_pos),
            "bias_within_bound": bool(m_fin["bias"] is not None and abs(m_fin["bias"]) <= bound),
            "bias_decomposition": bias_decomposition(n, p_raw, m_fin["bias"]),
            "equal_to_probability_share": float((np.abs(e_fin - p_raw) <= 1e-12).mean()) if len(n) else 0.0, "nesting": nesting,
            "note": "holdout = E(N) FINAL servido (hurdle + anidamiento entre horizontes); holdout_pre_nesting = P × E(N | N ≥ 1) sin anidar."}
        o["reference"]["holdout"] = count_metrics(n, chain_ref[h]["e_final"])
        o["gate"] = {**o["gate"], "passed_pre_nesting": o["gate"].get("passed"), "final": final_gate[h],
                     "passed": bool(selected[h]["kind"] == "complex")}
        # unidades con el E(N) final (la referencia de unidades conserva su definición sin anidar)
        Xh = bundle.matrix(h, hold)
        ref_po = reference_per_order(_base_col(bundle, "qty_mean5", brow), _base_col(bundle, "qty_per_purchase_all", brow))
        q_po = serve_quantity(ext_q["per_order"], Xh, ref_po)[0]
        eu["s05_orders_times_per_order"] = quantity_metrics(fr["qty"].to_numpy(dtype=float)[hold], e_fin * q_po)
        eu["nesting_applied"] = True
        ext_p = r.artifacts["prob"]["s05"]
        seg = ext_p["segmenter"]
        keys = seg.keys(base.loc[brow])
        _segment_orders(r.s05["segments"]["rows"], keys, n, e_fin, chain_ref[h]["e_final"])
        cs = [x for x in r.s05["segments"]["rows"] if x["dimension"] == "history" and x["segment"] == "history:cold_start"]
        if cs:
            r.s05["cold_start"].update({k: v for k, v in cs[0].items() if k == "orders"})
        report["per_horizon"][str(h)] = {"family": selected[h]["family"], "role": o["served"]["role"], "gate": final_gate[h],
                                         "bias": m_fin["bias"], "bias_within_bound": o["served"]["bias_within_bound"], "nesting": nesting}
    return report


# ───────────────────────────── predicción diaria ─────────────────────────────

def window_table(fb, as_of_day: int, base_full: pd.DataFrame, artifacts: dict[int, dict[str, Any]], cfg: dict[str, Any]
                 ) -> tuple[pd.DataFrame | None, dict[str, Any]]:
    """Ventana de TODOS los candidatos del corte (el suavizado jerárquico usa la población completa) con los parámetros y niveles guardados en el
    artefacto (lo evaluado es lo servido) y SOLO con las cifras que su validación respalda (`apply_validation`): con validación `insufficient_data` o
    `not_validated`, o con un grupo (cantidad de intervalos propios, estado overdue) sin validar, el estado es `insufficient_data` y los cuantiles son nulos.
    Si ningún artefacto trae `s05.window` (entrenados antes de W3-C o con S05 apagado), no se ofrece ventana. Devuelve (ventana, información)."""
    saved = None
    for h in sorted(artifacts):
        w = (artifacts[h]["qty"].get("s05") or {}).get("window")
        if w:
            saved = w
            break
    if saved is None:
        return None, {}
    params = WindowParams.from_dict(saved.get("params"), cfg)
    win, info = apply_validation(window_from_base(fb, as_of_day, base_full, params, levels=saved.get("levels")), saved)
    val = saved.get("validation") or {}
    info |= {"validation_status": saved.get("validation_status"), "follow_up_days": val.get("follow_up_days"),
             "not_assessable_share": val.get("not_assessable_share"), "reason": val.get("reason")}
    return win, info


def apply_s05(base: pd.DataFrame, X_by_h: dict[int, np.ndarray], probs_c: dict[int, np.ndarray], artifacts: dict[int, dict[str, Any]],
              win: pd.DataFrame | None, cfg: dict[str, Any], win_info: dict[str, Any] | None = None) -> dict[str, Any]:
    """Columnas S05 de cada horizonte para los candidatos de `base` (ya filtrados; `win` alineada con ellos o None si no hay ventana).

    Devuelve {"columns": {h: DataFrame}, "flags": {h: list[list[str]]}, "served": {h: dict}, "summary": dict}."""
    n = len(base)
    horizons = sorted(artifacts)
    cond: dict[int, np.ndarray] = {}
    qpo: dict[int, tuple[np.ndarray, np.ndarray, np.ndarray]] = {}
    for h in horizons:
        ext = artifacts[h]["qty"].get("s05") or {}
        X = X_by_h[h]
        o = orders_art(ext)
        if o is not None:
            cond[h] = serve_conditional_orders(o, X, reference_orders(base["rel_n_365"].to_numpy(dtype=float), base["rel_tenure_days"].to_numpy(dtype=float), h))
        if ext.get("per_order") is not None:
            ref_po = reference_per_order(base["qty_mean5"].to_numpy(dtype=float), base["qty_per_purchase_all"].to_numpy(dtype=float))
            qpo[h] = serve_quantity(ext["per_order"], X, ref_po)
    # hurdle E(N) = P × E(N | N ≥ 1) (coherente por construcción: E(N) > P) y, única corrección que queda, anidamiento entre horizontes
    e_raw = {h: hurdle_orders(probs_c[h], cond[h]) for h in cond}
    nested, nested_stats = enforce_nested_orders(e_raw) if len(e_raw) > 1 else (e_raw, {"rule": "cumulative_max", "horizons": sorted(e_raw)})
    cols: dict[int, pd.DataFrame] = {}
    flags: dict[int, list[list[str]]] = {}
    served: dict[int, dict[str, Any]] = {}
    cold = base["n_purchases_all"].to_numpy(dtype=float) < int(cfg["cold_start_min_purchases"])
    has_window = win is not None
    beyond = win["window_beyond_validation"].to_numpy(dtype=bool) if has_window and "window_beyond_validation" in win else np.zeros(n, dtype=bool)
    for h in horizons:
        ext_p = artifacts[h]["prob"].get("s05") or {}
        ext_q = artifacts[h]["qty"].get("s05") or {}
        seg_er = ext_p.get("segmenter")
        seg = seg_er.primary(base) if seg_er is not None else np.full(n, None, dtype=object)
        units = nested[h] * qpo[h][0] if (h in nested and h in qpo) else np.full(n, np.nan)
        df = pd.DataFrame({
            "expected_orders": nested[h] if h in nested else np.full(n, np.nan),
            "quantity_per_order": qpo[h][0] if h in qpo else np.full(n, np.nan),
            "quantity_per_order_lo": qpo[h][1] if h in qpo else np.full(n, np.nan),
            "quantity_per_order_hi": qpo[h][2] if h in qpo else np.full(n, np.nan),
            "expected_units": units,
            "window_p10_days": win["window_p10_days"].to_numpy() if has_window else np.full(n, np.nan),
            "window_p50_days": win["window_p50_days"].to_numpy() if has_window else np.full(n, np.nan),
            "window_p90_days": win["window_p90_days"].to_numpy() if has_window else np.full(n, np.nan),
            "window_status": win["window_status"].to_numpy(dtype=object) if has_window else np.full(n, None, dtype=object),
            "segment": seg})
        cols[h] = df
        fl: list[list[str]] = [[] for _ in range(n)]
        if not ext_q:
            for f in fl:
                f.append("s05_unavailable")
        for i in np.nonzero(cold)[0]:
            fl[i].append("cold_start")
        if h in nested:
            for i in np.nonzero(nested[h] > e_raw[h] + 1e-12)[0]:
                fl[i].append("orders_nested_adjusted")
        if has_window:       # P90 por encima del seguimiento con que se validó la ventana: su cola no se pudo evaluar
            for i in np.nonzero(beyond)[0]:
                fl[i].append("window_beyond_validation")
        flags[h] = fl
        po, o = ext_q.get("per_order"), orders_art(ext_q)
        served[h] = {
            "orders_family": o["family"] if o else None, "orders_role": ("primary" if o["kind"] == "complex" else "fallback") if o else None,
            "orders_dispersion_alpha": o["alpha"] if o else None, "orders_form": o.get("form") if o else None,
            "orders_nested_corrected_rate": nested_stats.get(f"corrected_rate_h{h}"), "orders_nested_corrected_mass_share": nested_stats.get(f"corrected_mass_share_h{h}"),
            "per_order_objective": po["objective"] if po else None, "per_order_role": ("primary" if po["kind"] == "complex" else "fallback") if po else None,
            "per_order_interval_ratio": [float(np.exp(po["interval_log_q"][0])), float(np.exp(po["interval_log_q"][1]))] if po else None,
            "per_order_interval_level": po.get("level") if po else None,
            "window_validation": (ext_q.get("window") or {}).get("validation_status"), "segmenter_version": getattr(seg_er, "version", None)}
    status_counts = win["window_status"].value_counts().to_dict() if (n and has_window) else {}
    summary: dict[str, Any] = {"orders_nested": nested_stats, "window_status_counts": {k: int(v) for k, v in status_counts.items()},
                               "cold_start_rows": int(cold.sum()), "rows": int(n)}
    if win_info:
        summary["window"] = {"validation_status": win_info.get("validation_status"), "follow_up_days": win_info.get("follow_up_days"),
                             "not_assessable_share": win_info.get("not_assessable_share"), "withheld": win_info.get("withheld"),
                             "beyond_validation_rows": int(beyond.sum()), "reason": win_info.get("reason")}
    # drift de la distribución de probabilidades servida frente a la del holdout del entrenamiento (guardada en el artefacto)
    summary["drift"] = {str(h): psi_against_reference(probs_c[h], (artifacts[h]["prob"].get("s05") or {}).get("drift_reference"), cfg) for h in horizons}
    return {"columns": cols, "flags": flags, "served": served, "summary": summary}


S05_PREDICTION_COLUMNS = ["expected_orders", "quantity_per_order", "expected_units", "window_p10_days", "window_p50_days", "window_p90_days",
                          "window_status", "segment"]
__all__ = ["train_s05", "finalize_s05", "apply_s05", "window_table", "orders_art", "S05_PREDICTION_COLUMNS", "WINDOW_COLUMNS"]
