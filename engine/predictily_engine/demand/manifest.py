"""Manifiesto del protocolo de evaluación (`forecast_runs.evaluation_manifest`, E2-06).

Se construye a partir de las filas REALES del backtest (no del plan declarado): fechas de las ventanas, de
las fechas objetivo, de los residuos con que se calibra y de lo que participó en cada decisión. Así el
manifiesto puede verificarse (`verify`) y una violación del protocolo no puede pasar inadvertida.

Protocolo (docs/04 §4): 1) ventanas de selección con datos y perfiles reconstruidos en cada corte;
2) calibración con residuos de esas ventanas, ya observables en el origen del período final; 3) período
final reservado que no interviene en la selección, los hiperparámetros, los umbrales ni la calibración que
se usa para evaluarlo; 4) el modelo de PRODUCCIÓN sí usa toda la evidencia (selección + período final).
"""

from __future__ import annotations

from datetime import date
from typing import Any

import pandas as pd

MANIFEST_VERSION = "demand-protocol-v1"


def _d(v: Any) -> str | None:
    return None if v is None or pd.isna(v) else pd.Timestamp(v).date().isoformat()


def _windows(rows: pd.DataFrame, role: str) -> list[dict[str, Any]]:
    out = []
    for w, g in rows.groupby("window"):
        origin = pd.Timestamp(g["cutoff"].iloc[0])
        out.append({"window": int(w), "role": role, "origin": _d(origin), "train_through": _d(origin),
                    "target_from": _d(g["ds"].min()), "target_to": _d(g["ds"].max()),
                    "target_days": int(g["ds"].nunique()), "series": int(g["unique_id"].nunique())})
    return sorted(out, key=lambda x: x["origin"])


def build_manifest(*, cutoff: date, data_from: date, plan: Any | None, bt: pd.DataFrame, min_improvement: float,
                   config_version: str | None, coverage: dict[str, Any] | None, horizon_support: dict[int, dict],
                   has_eval_selection: bool, p50: dict[str, Any] | None, seed: int,
                   served: dict[str, Any] | None = None) -> dict[str, Any]:
    cutoff_ts = pd.Timestamp(cutoff)
    history_days = int((cutoff_ts - pd.Timestamp(data_from)).days) + 1
    manifest: dict[str, Any] = {
        "manifest_version": MANIFEST_VERSION, "core": "demand", "as_of": _d(cutoff),
        "history": {"from": _d(data_from), "to": _d(cutoff), "days": history_days},
        "seed": seed, "min_improvement": min_improvement, "config_version": config_version,
        "source_coverage": coverage,
    }
    if plan is None or bt.empty:
        manifest.update({
            "protocol": {"backtest": False, "reason": "Historia insuficiente para backtesting (< 70 días)."},
            "training": {"final_fit": {"train_from": _d(data_from), "train_through": _d(cutoff)}},
            "selection": None, "calibration": None, "holdout": {"present": False, "independent": False},
            "label_maturity": None, "overlap": None, "checks": {}, "horizon_support": _hs(horizon_support),
            "p50": p50, "served": served,
            "decisions": {"model_selection": "ninguna (sin backtesting)", "interval_calibration": "sin calibrar"},
        })
        return manifest

    hold = bt[bt["is_holdout"].astype(bool)]
    sel = bt[~bt["is_holdout"].astype(bool)]
    has_holdout = not hold.empty and has_eval_selection
    sel_w, hold_w = _windows(sel, "selection"), _windows(hold, "holdout")
    sel_dates = pd.to_datetime(sel["ds"]).drop_duplicates()
    counts = sel.groupby("window")["ds"].apply(lambda s: set(pd.to_datetime(s)))
    seen: dict[pd.Timestamp, int] = {}
    for dates in counts:
        for d in dates:
            seen[d] = seen.get(d, 0) + 1
    shared = sum(1 for n in seen.values() if n > 1)
    hold_dates = set(pd.to_datetime(hold["ds"])) if not hold.empty else set()
    holdout_shared = len(hold_dates & set(sel_dates))
    holdout_origin = pd.Timestamp(hold["cutoff"].iloc[0]) if not hold.empty else None
    residual_end = pd.Timestamp(sel["ds"].max()) if not sel.empty else None
    evidence_end_sel = holdout_origin if has_holdout else cutoff_ts

    manifest["protocol"] = {
        "backtest": True, "horizon_days": int(plan.horizon), "step_days": int(plan.step),
        "selection_windows": int(plan.windows), "independent_holdout": bool(has_holdout),
        "procedure": "selección y calibración con ventanas anteriores; prueba final en el período reservado; "
                     "producción con toda la evidencia",
    }
    manifest["training"] = {
        "backtest_fits": sel_w + hold_w,
        "final_fit": {"train_from": _d(data_from), "train_through": _d(cutoff),
                      "uses": "selección con todas las ventanas (incluido el período final) para el modelo de producción"},
    }
    manifest["selection"] = {
        "origins": [w["origin"] for w in sel_w], "target_from": _d(sel["ds"].min()), "target_to": _d(sel["ds"].max()),
        "unique_target_dates": int(len(sel_dates)), "shared_target_dates_between_windows": int(shared),
        "criterion": f"menor WAPE por clase de demanda; LightGBM solo si mejora ≥ {min_improvement:.0%} y gana en ≥ 2/3 de las ventanas",
        "profile_rebuilt_per_cutoff": True,
    }
    manifest["calibration"] = {
        "source": "residuos del modelo elegido en las ventanas de selección, por clase de demanda y tramo de horizonte",
        "residual_target_from": _d(sel["ds"].min()), "residual_target_to": _d(residual_end),
        "observable_at": _d(evidence_end_sel), "n_residual_rows": int(len(sel)),
        "residuals_observable_when_calibrating": bool(residual_end is not None and evidence_end_sel is not None
                                                      and residual_end <= evidence_end_sel),
        "production": "residuos de todas las ventanas (selección + período final)",
    }
    manifest["holdout"] = (
        {"present": True, "independent": True, "origin": _d(holdout_origin), "target_from": _d(hold["ds"].min()),
         "target_to": _d(hold["ds"].max()), "horizon_days": int(plan.horizon),
         "n_rows": int(len(hold)), "series": int(hold["unique_id"].nunique()),
         "used_for_model_selection": False, "used_for_hyperparameters": False, "used_for_thresholds": False,
         "used_for_interval_calibration": False}
        if has_holdout else
        {"present": False, "independent": False,
         "reason": "La historia no alcanza para reservar un período final independiente: las métricas provienen de las "
                   "ventanas que eligieron el modelo (optimistas)."})
    manifest["label_maturity"] = {
        "rule": ("Una ventana entra a la selección o a la calibración solo cuando terminó su período objetivo "
                 "(origen + horizonte ≤ fin de la evidencia) y los días con quiebre de stock se excluyen; la etiqueta "
                 "diaria madura el mismo día (sin rezago de captura declarado)."),
        "label_horizon_days": int(plan.horizon),
        "selection_evidence_end": _d(evidence_end_sel),
        "latest_selection_window_target_to": _d(residual_end),
        "holdout_evidence_end": _d(cutoff_ts),
        "censored_days_excluded": "stockout",
        "source_complete_through": (coverage or {}).get("sales", {}).get("complete_through"),
    }
    manifest["overlap"] = {
        "overlapping_origins": bool(shared > 0), "shared_target_dates_between_selection_windows": int(shared),
        "shared_target_dates_with_holdout": int(holdout_shared),
        "note": ("Las ventanas de selección se solapan entre sí: sus observaciones no son independientes y no deben "
                 "usarse para estimar incertidumbre estadística de la métrica; el período final no comparte fechas con ellas."
                 if shared > 0 else "Las ventanas de selección no comparten fechas objetivo."),
    }
    manifest["decisions"] = {
        "model_selection_for_evaluation": {"windows": [w["window"] for w in sel_w]},
        "interval_calibration_for_evaluation": {"windows": [w["window"] for w in sel_w]},
        "production_selection_and_calibration": {"windows": [w["window"] for w in sel_w + hold_w]},
        "holdout_windows_used_for_decisions_of_the_evaluation": [] if has_holdout else [w["window"] for w in hold_w],
    }
    manifest["checks"] = {
        "holdout_disjoint_from_selection": holdout_shared == 0,
        "calibration_residuals_observable_at_holdout_origin": manifest["calibration"]["residuals_observable_when_calibrating"],
        "holdout_not_used_for_decisions": has_holdout,
        "selection_windows_end_before_holdout_origin": bool(
            holdout_origin is not None and residual_end is not None and residual_end <= holdout_origin) if has_holdout else None,
    }
    manifest["horizon_support"] = _hs(horizon_support)
    manifest["p50"] = p50
    manifest["served"] = served
    return manifest


def _hs(support: dict[int, dict]) -> dict[str, Any]:
    return {str(h): v for h, v in support.items()}


def verify(manifest: dict[str, Any]) -> list[str]:
    """Violaciones del protocolo según el propio manifiesto (vacío = protocolo respetado)."""
    problems: list[str] = []
    h = manifest.get("holdout") or {}
    if h.get("independent"):
        checks = manifest.get("checks") or {}
        for key in ("holdout_disjoint_from_selection", "calibration_residuals_observable_at_holdout_origin",
                    "holdout_not_used_for_decisions", "selection_windows_end_before_holdout_origin"):
            if checks.get(key) is not True:
                problems.append(f"check {key} no se cumple")
        if any(h.get(k) for k in ("used_for_model_selection", "used_for_hyperparameters", "used_for_thresholds",
                                  "used_for_interval_calibration")):
            problems.append("el período final participó en una decisión")
    return problems
