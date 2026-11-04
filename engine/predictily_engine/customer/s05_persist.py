"""Persistencia de S05 (W3-C): filas de `model_evaluations`, resumen y manifiesto del entrenamiento y ventana en los artefactos.

Funciones puras (sin base de datos): `service.py` las llama al persistir la corrida. Filas de `model_evaluations`:
- `level = 'segment'`, `level_key = '<dimensión>:<valor>'`, `horizon_days = H`: calibración por segmento (frecuencia, valor, antigüedad,
  historia/cold start) con n, tasa observada, media predicha, Brier/log loss/ECE del servido y de la referencia, estado y curva con n por tramo.
- `level = 'overall'`: `h{H}:expected_orders`, `h{H}:quantity_per_order`, `h{H}:expected_units`, `h{H}:ranking_s05`, `h{H}:stability`,
  `h{H}:segmenter` y `window` (sin horizonte; `evaluation_type = 'out_of_sample'`).
Las claves vigentes (`h7:probability`, `h7:expected_quantity`, …) no se tocan.
"""

from __future__ import annotations

from typing import Any

from predictily_engine.customer import S05_VERSION
from predictily_engine.customer.events import from_day
from predictily_engine.customer.train import HorizonResult


def _cols(m: dict[str, Any] | None, coverage: float | None = None) -> dict[str, Any]:
    m = m or {}
    return {"mae": m.get("mae"), "rmse": m.get("rmse"), "bias": m.get("bias"), "wape": m.get("wape"), "n_obs": int(m.get("n") or 0),
            "coverage_80": coverage}


def _ok(r: HorizonResult) -> bool:
    return r.status in ("trained", "reference_only") and bool(r.s05) and r.s05.get("status") != "failed" and "orders" in r.s05


def s05_eval_rows(results: dict[int, HorizonResult], window_eval: dict[str, Any] | None) -> list[dict[str, Any]]:
    """Filas (sin ids de BD) listas para `add(**row)` en `_persist_evaluations`."""
    rows: list[dict[str, Any]] = []

    def add(family: str, key: str, h: int | None, metrics: dict[str, Any], baseline: bool, selected: bool, cols: dict[str, Any] | None = None,
            extra: dict[str, Any] | None = None, level: str = "overall", etype: str = "holdout") -> None:
        rows.append({"family": family, "etype": etype, "key": key, "h": h, "metrics": metrics, "baseline": baseline, "selected": selected,
                     "cols": cols or {}, "extra": extra or {}, "level": level})

    for h, r in sorted(results.items()):
        if not _ok(r):
            continue
        s = r.s05
        o, po, eu = s["orders"], s["per_order"], s["expected_units"]
        add("customer_orders_reference", f"h{h}:expected_orders", h, o["reference"]["holdout"], True, o["served"]["family"] == "reference",
            _cols(o["reference"]["holdout"]), {"definition": o["definition"], "scale": o["reference"]["scale"], "alpha": o["reference"]["alpha"],
                                               "form": o["form"], "rate_only": o["rate_only"]["holdout"]})
        add("customer_orders_served", f"h{h}:expected_orders", h, o["served"]["holdout"], False, True, _cols(o["served"]["holdout"]),
            {"family": o["served"]["family"], "role": o["served"]["role"], "alpha": o["served"]["alpha"], "gate": o["gate"],
             "challenger": o.get("challenger"), "holdout_pre_nesting": o["served"]["holdout_pre_nesting"],
             "conditional_holdout": o["served"]["conditional_holdout"], "nesting": o["served"].get("nesting"),
             "bias_within_bound": o["served"]["bias_within_bound"], "bias_decomposition": o["served"].get("bias_decomposition"),
             "equal_to_probability_share": o["served"]["equal_to_probability_share"], "note": o["served"]["note"]})
        add("customer_per_order_reference", f"h{h}:quantity_per_order", h, po["reference"]["holdout"], True, po["served"]["objective"] == "reference",
            _cols(po["reference"]["holdout"], po["reference"]["interval"].get("coverage")), {"definition": po["definition"], "scale": po["reference"]["scale"],
                                                                                            "interval": po["reference"]["interval"]})
        add("customer_per_order_served", f"h{h}:quantity_per_order", h, po["served"]["holdout"], False, True,
            _cols(po["served"]["holdout"], po["served"]["interval"].get("coverage")),
            {"objective": po["served"]["objective"], "role": po["served"]["role"], "gate": po["gate"], "interval": po["served"]["interval"],
             "interval_level": po["served"]["interval_level"], "challenger": po.get("challenger")})
        add("customer_units_reference", f"h{h}:expected_units", h, eu["reference_orders_times_per_order"], True, False,
            _cols(eu["reference_orders_times_per_order"]), {"definition": eu["definition"]})
        add("customer_units_served", f"h{h}:expected_units", h, eu["s05_orders_times_per_order"], False, True, _cols(eu["s05_orders_times_per_order"]),
            {"definition": eu["definition"], "independence_assumption": eu["independence_assumption"],
             "expected_quantity_p_times_q": eu["expected_quantity_served"], "expected_quantity_reference": eu["expected_quantity_reference"]})
        for seg in s["segments"]["rows"]:
            served = seg.get("served") or {}
            add("customer_segment_calibration", seg["segment"], h, seg, False, True,
                {"n_obs": seg["n"], "bias": None, "mae": None, "rmse": None, "wape": None},
                {"dimension": seg["dimension"], "status": seg["status"], "mean_ratio": seg.get("mean_ratio"),
                 "brier": served.get("brier"), "brier_reference": (seg.get("reference") or {}).get("brier"), "ece": served.get("ece")},
                level="segment")
        add("customer_ranking_s05", f"h{h}:ranking_s05", h, s["ranking"], False, True,
            {"n_obs": s["ranking"]["sources"]["served"]["utility"]["n_rows"]}, {"primary_k": s["ranking"]["primary_k"]})
        add("customer_stability", f"h{h}:stability", h, {"stability": s["stability"], "drift": s["drift"]}, False, True,
            {"n_obs": s["stability"]["n_cutoffs"]}, {})
        add("customer_segmenter", f"h{h}:segmenter", h, {"segmenter": s["segments"]["segmenter"], "segment_recalibration": s["segment_recalibration"],
                                                         "cold_start": s["cold_start"]}, False, True, {"n_obs": 0}, {})
    if window_eval:
        iv = window_eval.get("interval") or {}
        err = (window_eval.get("median") or {}).get("error_days") or {}
        add("customer_window", "window", None, window_eval, False, True,
            {"n_obs": int(iv.get("n") or 0), "coverage_80": iv.get("coverage"), "mae": err.get("mae"), "bias": err.get("bias"), "wape": None, "rmse": None},
            {"units": "días (mae y bias del P50 contra la primera compra observada); coverage_80 = cobertura de [P10, P90]",
             "status": window_eval.get("status")}, etype="out_of_sample")
    return rows


def window_artifact_payload(window_eval: dict[str, Any], params: Any) -> dict[str, Any]:
    """Lo que viaja en el artefacto firmado: parámetros, niveles recalibrados y el resultado de su validación (lo evaluado es lo servido)."""
    iv = window_eval.get("interval") or {}
    groups = {k: {"status": g.get("status"), "coverage": g.get("coverage"), "n_relations": g.get("n_relations"), "n_customers": g.get("n_customers"),
                  "reason": g.get("reason")} for k, g in (window_eval.get("groups") or {}).items()}
    return {"version": S05_VERSION, "params": window_eval.get("params") or params.as_dict(), "levels": window_eval.get("levels"),
            "validation_status": window_eval.get("status"),
            "validation": {"coverage": iv.get("coverage"), "n": iv.get("n"), "n_relations": iv.get("n_relations"), "n_customers": iv.get("n_customers"),
                           "nominal": iv.get("nominal"), "tolerance": window_eval.get("coverage_tolerance"), "follow_up_days": window_eval.get("follow_up_days"),
                           "not_assessable_share": (window_eval.get("rows") or {}).get("not_assessable_share"), "groups": groups,
                           "reason": window_eval.get("reason")}}


def attach_window(results: dict[int, HorizonResult], window_eval: dict[str, Any] | None, params: Any) -> None:
    """Guarda en cada artefacto de cantidad la ventana (el mismo objeto `s05` lo comparten servido, retador y referencia)."""
    if window_eval is None:
        return
    payload = window_artifact_payload(window_eval, params)
    for r in results.values():
        ext = ((r.artifacts or {}).get("qty") or {}).get("s05")
        if ext is not None:
            ext["window"] = payload


def window_summary(window_eval: dict[str, Any] | None) -> dict[str, Any] | None:
    if not window_eval:
        return None
    iv = window_eval.get("interval") or {}
    med = window_eval.get("median") or {}
    lv = window_eval.get("levels") or {}
    groups = {k: {"status": g.get("status"), "coverage": g.get("coverage"), "n_relations": g.get("n_relations")} for k, g in (window_eval.get("groups") or {}).items()}
    return {"status": window_eval.get("status"), "reason": window_eval.get("reason"), "coverage": iv.get("coverage"), "nominal": 0.8, "n_evaluated": iv.get("n"),
            "n_relations": iv.get("n_relations"), "n_customers": iv.get("n_customers"), "follow_up_days": window_eval.get("follow_up_days"),
            "not_assessable_share": (window_eval.get("rows") or {}).get("not_assessable_share"), "groups": groups, "attempts": window_eval.get("attempts"),
            "mean_width_days": iv.get("mean_width_days"), "median_error_days": (med.get("error_days") or {}).get("mae"),
            "reference_coverage": ((window_eval.get("reference_metrics") or {}).get("interval") or {}).get("coverage"),
            "uncalibrated_coverage": ((window_eval.get("recalibration") or {}).get("uncalibrated_nominal_levels") or {}).get("coverage"),
            "levels_fitted": [k for k, v in lv.items() if v.get("fitted")], "n_cutoffs": window_eval.get("n_cutoffs"),
            "rows_by_status": (window_eval.get("rows") or {}).get("by_status")}


def horizon_summary(r: HorizonResult) -> dict[str, Any]:
    if not r.s05:
        return {"status": "unavailable"}
    if r.s05.get("status") == "failed" or "orders" not in r.s05:
        return {"status": "failed", "reason": r.s05.get("reason")}
    s = r.s05
    o, po, eu, rk = s["orders"], s["per_order"], s["expected_units"], s["ranking"]
    k = rk["primary_k"]
    src = rk["sources"]
    segs = s["segments"]["rows"]
    counts = {st: sum(1 for x in segs if x["status"] == st) for st in ("calibrated", "miscalibrated", "insufficient_data")}
    return {
        "status": "ok",
        "orders": {"served": o["served"]["family"], "role": o["served"]["role"], "bias": o["served"]["holdout"]["bias"],
                   "bias_within_bound": o["served"]["bias_within_bound"], "poisson_deviance": o["served"]["holdout"]["poisson_deviance"],
                   "reference_poisson_deviance": o["reference"]["holdout"]["poisson_deviance"], "alpha": o["served"]["alpha"], "promotion_passed": o["gate"]["passed"],
                   "equal_to_probability_share": o["served"]["equal_to_probability_share"],
                   "nesting": {k: (o["served"].get("nesting") or {}).get(k) for k in ("adjusted_share", "mass_share", "bias_before", "bias_after")}},
        "per_order": {"served": po["served"]["objective"], "role": po["served"]["role"], "mae": po["served"]["holdout"]["mae"],
                      "reference_mae": po["reference"]["holdout"]["mae"], "interval_coverage": po["served"]["interval"].get("coverage"),
                      "promotion_passed": po["gate"]["passed"]},
        "expected_units": {"wape": eu["s05_orders_times_per_order"]["wape"], "bias": eu["s05_orders_times_per_order"]["bias"],
                           "expected_quantity_wape": eu["expected_quantity_served"]["wape"], "expected_quantity_bias": eu["expected_quantity_served"]["bias"],
                           "reference_wape": eu["reference_orders_times_per_order"]["wape"]},
        "ranking": {"primary_k": k, "lift": {n: src[n]["lift"].get(f"lift_at_{k}") for n in src}, "pr_auc": {n: src[n]["pr_auc"] for n in src},
                    "units_capture_ratio": {n: (src[n]["utility"]["units"] or {}).get("capture_ratio") for n in src},
                    "amount_capture_ratio": {n: (src[n]["utility"]["amount_at_cutoff_price"] or {}).get("capture_ratio") for n in src},
                    "utility_units_ranked": src["served"]["utility"].get("n_units_ranked"),
                    "margin": None if rk["margin_limitation"] else "calculado", "margin_limitation": rk["margin_limitation"],
                    "comparison": rk["comparison"]},
        "segments": counts, "cold_start": {"status": s["cold_start"].get("status"), "n": s["cold_start"].get("n"), "mean_ratio": s["cold_start"].get("mean_ratio")},
        "stability": {"n_cutoffs": s["stability"]["n_cutoffs"], "brier_served_std": s["stability"]["brier_served_std"],
                      "share_not_worse_than_reference": s["stability"]["share_cutoffs_served_not_worse_than_reference"]},
        "drift": {"calibration_to_holdout": s["drift"]["calibration_to_holdout"].get("status"),
                  "psi_adjusted": s["drift"]["calibration_to_holdout"].get("psi_adjusted"),
                  "across_cutoffs": s["drift"]["across_holdout_cutoffs"].get("status")}}


def manifest_block(results: dict[int, HorizonResult], window_eval: dict[str, Any] | None) -> dict[str, Any]:
    """Bloque `s05` del manifiesto del protocolo: qué decide cada componente, con qué bloque y qué hace el holdout."""
    horizons: dict[str, Any] = {}
    for h, r in sorted(results.items()):
        if not _ok(r):
            horizons[str(h)] = {"status": (r.s05 or {}).get("status", "unavailable"), "reason": (r.s05 or {}).get("reason")}
            continue
        s = r.s05
        horizons[str(h)] = {
            "status": "ok",
            "orders": {"served": s["orders"]["served"]["family"], "gate": s["orders"]["gate"], "form": s["orders"]["form"],
                       "nesting": s["orders"]["served"].get("nesting")},
            "per_order": {"served": s["per_order"]["served"]["objective"], "gate": s["per_order"]["gate"], "selection_rule": s["per_order"].get("selection_rule")},
            "segmenter": s["segments"]["segmenter"], "segment_recalibration": {k: v for k, v in s["segment_recalibration"].items() if k != "shifts"},
            "drift_reference": s["drift"]["reference_distribution"]}
    block: dict[str, Any] = {
        "version": S05_VERSION,
        "components": {
            "orders": ("Hurdle E(N) = P × E(N | N ≥ 1): conteo condicionado propio (1 + Poisson/LightGBM sobre los pedidos adicionales, o la referencia de tasa) "
                       "ajustado solo con los positivos de entrenamiento; escala y dispersión con los positivos de calibración; anidamiento entre horizontes medido."),
            "per_order": "Cantidad por pedido | ocurre: objetivo elegido en selección (o calibración en «lean»), escala e intervalo conformal en calibración.",
            "window": "Cuantiles del tiempo hasta la siguiente compra: empírica suavizada; niveles recalibrados con cortes de ajuste anteriores a los de evaluación.",
            "segments": "Cortes de frecuencia y valor por terciles del bloque de calibración; antigüedad con cortes fijos de configuración.",
            "ranking_economic": "Utilidad realizada del top-k frente a popularidad y recencia; sin costo conocido al corte no se calcula margen."},
        "holdout_policy": ("El holdout solo EVALÚA lo de S05 y aplica las reglas versionadas de promoción del conteo (sobre el E(N) final servido) y de la cantidad "
                           "por pedido; no ajusta modelos, escalas, dispersión, cortes de segmento ni niveles de la ventana. Los cortes de AJUSTE de la ventana (y su "
                           "seguimiento) terminan antes del primer corte del bloque holdout de todo horizonte; los cortes de EVALUACIÓN de la ventana pueden coincidir en el "
                           "tiempo con un holdout, pero solo se evalúan sobre ellos."),
        "horizons": horizons}
    if window_eval:
        block["window"] = {"status": window_eval.get("status"), "reason": window_eval.get("reason"), "params": window_eval.get("params"),
                           "groups": {k: {"status": g.get("status"), "coverage": g.get("coverage"), "n_relations": g.get("n_relations"), "n_customers": g.get("n_customers")}
                                      for k, g in (window_eval.get("groups") or {}).items()},
                           "not_assessable_share": (window_eval.get("rows") or {}).get("not_assessable_share"), "attempts": window_eval.get("attempts"),
                           "holdout_starts": [from_day(c).isoformat() for c in (window_eval.get("holdout_starts") or [])],
                           "fit_cutoffs": [from_day(c).isoformat() for c in window_eval.get("fit_cutoff_days", [])],
                           "eval_cutoffs": [from_day(c).isoformat() for c in window_eval.get("eval_cutoff_days", [])],
                           "follow_up_days": window_eval.get("follow_up_days"), "recalibration": window_eval.get("recalibration"),
                           "levels": window_eval.get("levels")}
    return block
