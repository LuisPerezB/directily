"""Champion/challenger y shadow mode (V2.5 §8; ADR-V25-21; V25-SEL-034..038).

El champion es el modelo SERVIDO de cada clase de demanda. El challenger es el mejor candidato NO servido de esa clase (menor WAPE en las
ventanas de selección; el período final reservado no interviene). Solo hay UN challenger por alcance y un tope de series configurable
(`promotion.shadow_max_series`: las de mayor volumen reciente, de forma determinista): el costo está acotado.

El challenger produce sus predicciones del horizonte, que se guardan en `shadow_forecast_values` SIN servirse (ni entran a `forecast_values`,
recomendaciones, intervalos ni alertas). Sus intervalos 80 % se calibran con los residuos del backtest del propio challenger. Después del corte,
`evaluation/actuals.py` (`evaluate_shadow_prospective`) las compara con lo realizado, con la MISMA censura de días que la evaluación servida
(quiebre de stock, fuente ausente, cierre), y escribe `model_evaluations.evaluation_type = 'shadow_prospective'`.

Se ejecuta al FINAL del pipeline, después de todo lo servido: no puede alterar lo que se publica. Si el challenger es LightGBM y el servido no lo
usa, se entrena un LightGBM SOLO con las series acotadas del shadow (`shadow_train_ml_challenger`): el costo es proporcional al tope y no al tamaño de
la empresa; es una aproximación (menos series de entrenamiento) y queda anotada (`shadow_ml_capped_training`). Ese modelo de sombra NO se persiste
como artefacto (cada ejecución reentrena el servido; promover un challenger lo reentrena y lo firma).
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.availability.contracts import FeatureContractError
from predictily_engine.backtest.metrics import summarize
from predictily_engine.intervals.conformal import ConformalCalibrator, day_bucket
from predictily_engine.models.ml import LightGBMGlobal
from predictily_engine.models.registry import CANDIDATES_BY_CLASS, FAMILIES, STATISTICAL
from predictily_engine.models.statistical import forecast_statistical
from predictily_engine.selection.selector import Selection, row_classes

log = logging.getLogger(__name__)

CLASSES = ("smooth", "erratic", "intermittent", "lumpy")
SHADOW_COLUMNS = ["model_family", "node_key", "level", "branch_id", "product_id", "target_date", "horizon_step", "yhat", "lo_80", "hi_80",
                  "demand_class", "scope_key"]


@dataclass
class Challenger:
    scope_key: str                  # class:<clase>
    demand_class: str
    family: str                     # el challenger
    served_family: str              # el champion (lo servido en el alcance)
    wape: float | None              # WAPE del challenger en las ventanas de selección
    served_wape: float | None
    series: list[str] = field(default_factory=list)
    eligible_series: int = 0

    def as_dict(self) -> dict[str, Any]:
        return {"scope_key": self.scope_key, "challenger": self.family, "champion": self.served_family,
                "selection_wape_challenger": self.wape, "selection_wape_champion": self.served_wape,
                "series_in_shadow": len(self.series), "series_eligible": self.eligible_series}


def choose_challengers(bt: pd.DataFrame, profile: pd.DataFrame, served: Selection, eligible: dict[str, list[str]], scales: pd.Series,
                       max_series: int, failed: set[str] | None = None) -> list[Challenger]:
    """Un challenger por clase de demanda con series elegibles: el mejor candidato no servido (WAPE en ventanas de selección).

    `eligible`: clase → series entrenables y activas; `scales`: escala (volumen) reciente por serie para el tope."""
    if bt.empty:
        return []
    failed = set(failed or ())
    in_holdout = bt["is_holdout"].astype(bool) if "is_holdout" in bt else pd.Series(False, index=bt.index)
    rows = bt[~in_holdout] if (in_holdout.any() and (~in_holdout).any()) else bt     # el período final reservado no decide
    rows = rows.assign(demand_class=row_classes(rows, profile))
    rows = rows[~rows["stockout"].astype(bool)]
    out: list[Challenger] = []
    for cls in CLASSES:
        ids = eligible.get(cls) or []
        sub = rows[rows["demand_class"] == cls]
        if not ids or sub.empty:
            continue
        champion = served.family_for(cls)
        present = set(sub["family"].unique())
        candidates = [f for f in CANDIDATES_BY_CLASS[cls] if f in present and f != champion and f not in failed]
        if not candidates:
            continue
        table = summarize(sub[sub["family"].isin([*candidates, champion])], ["family"]).set_index("family")
        scored = sorted((float(table.loc[f, "wape"]), f) for f in candidates if f in table.index and pd.notna(table.loc[f, "wape"]))
        if not scored:
            continue
        wape, family = scored[0]
        champ_wape = float(table.loc[champion, "wape"]) if champion in table.index and pd.notna(table.loc[champion, "wape"]) else None
        ranked = sorted(ids, key=lambda u: (-float(scales.get(u, 0.0) if pd.notna(scales.get(u, np.nan)) else 0.0), u))
        out.append(Challenger(f"class:{cls}", cls, family, champion, wape, champ_wape, ranked[:max(1, int(max_series))], len(ids)))
    return out


def _calibrate(bt: pd.DataFrame, profile: pd.DataFrame, family: str, cls: str) -> ConformalCalibrator | None:
    """Calibrador conformal del challenger con SUS residuos del backtest en la clase (todas las ventanas: es el procedimiento de producción)."""
    rows = bt[bt["family"] == family]
    rows = rows.assign(demand_class=row_classes(rows, profile))
    rows = rows[(rows["demand_class"] == cls) & ~rows["stockout"].astype(bool)]
    if rows.empty:
        return None
    cal = ConformalCalibrator(rows.assign(group=f"{cls}|{family}"), "group", day_bucket)
    return None if cal.empty else cal


def forecast_challengers(challengers: list[Challenger], *, bt: pd.DataFrame, profile: pd.DataFrame, train: pd.DataFrame,
                         features: Any, lgbm: LightGBMGlobal | None, lgbm_ref: pd.Series | None, full_ids: list[str],
                         cutoff: pd.Timestamp, fc_horizon: int, horizon: int, scales: pd.Series, seed: int, n_jobs: int,
                         train_ml: bool = True, lgbm_params: dict[str, Any] | None = None) -> tuple[pd.DataFrame, list[dict[str, str]]]:
    """Predicciones de los challengers para los pasos 1..`horizon` y sus intervalos 80 %. Devuelve (filas, notas): una nota por challenger
    que no se pudo producir (modelo no entrenable, etc.); nunca se rellena un pronóstico."""
    frames: list[pd.DataFrame] = []
    notes: list[dict[str, str]] = []
    if not challengers:
        return pd.DataFrame(columns=SHADOW_COLUMNS), notes
    by_family: dict[str, list[Challenger]] = {}
    for ch in challengers:
        by_family.setdefault(ch.family, []).append(ch)
    for family, chs in by_family.items():
        ids = sorted({u for ch in chs for u in ch.series})
        if family in STATISTICAL:
            sub = train[train["unique_id"].isin(ids)][["unique_id", "ds", "y"]]
            pred = forecast_statistical(sub, fc_horizon, [family])
        elif family == "lightgbm_global":
            pred = _predict_lgbm(lgbm, lgbm_ref, features, full_ids, ids, cutoff, fc_horizon, seed, n_jobs, train_ml, lgbm_params, notes)
            if pred is None:
                continue
            pred = pred[pred["unique_id"].isin(ids)]
        else:
            notes.append({"code": "shadow_family_unsupported", "message": f"La familia {family} no se puede producir en shadow."})
            continue
        if pred.empty:
            notes.append({"code": "shadow_no_predictions", "message": f"{FAMILIES[family].label}: sin predicciones para el challenger."})
            continue
        for ch in chs:
            part = pred[pred["unique_id"].isin(ch.series)][["unique_id", "ds", "yhat"]].copy()
            part["step"] = (part["ds"] - cutoff).dt.days.astype(int)
            part = part[(part["step"] >= 1) & (part["step"] <= horizon)]
            part["scale"] = part["unique_id"].map(scales).fillna(0.05)
            part["group"] = f"{ch.demand_class}|{family}"
            cal = _calibrate(bt, profile, family, ch.demand_class)
            if cal is not None:
                part = cal.apply(part)
            else:
                part["lo_80"] = part["hi_80"] = np.nan
            ids_ = part["unique_id"].str.extract(r"b(\d+)-p(\d+)").astype(int)
            frames.append(pd.DataFrame({
                "model_family": family, "node_key": "series:" + ids_[0].astype(str) + ":" + ids_[1].astype(str), "level": "series",
                "branch_id": ids_[0].to_numpy(), "product_id": ids_[1].to_numpy(), "target_date": part["ds"].to_numpy(),
                "horizon_step": part["step"].to_numpy(), "yhat": np.clip(part["yhat"].to_numpy(float), 0, None).round(4),
                "lo_80": part["lo_80"].astype(float).round(4).to_numpy(), "hi_80": part["hi_80"].astype(float).round(4).to_numpy(),
                "demand_class": ch.demand_class, "scope_key": ch.scope_key}))
    if not frames:
        return pd.DataFrame(columns=SHADOW_COLUMNS), notes
    out = pd.concat(frames, ignore_index=True)
    return out[SHADOW_COLUMNS], notes


def _predict_lgbm(lgbm: LightGBMGlobal | None, ref: pd.Series | None, features: Any, full_ids: list[str], capped_ids: list[str],
                  cutoff: pd.Timestamp, fc_horizon: int, seed: int, n_jobs: int, train_ml: bool, params: dict[str, Any] | None,
                  notes: list[dict[str, str]]) -> pd.DataFrame | None:
    """Predicciones de LightGBM para el shadow. Si el servido ya lo entrenó, se reutiliza (exacto, sin costo). Si no, y `train_ml`, se entrena un
    LightGBM SOLO con las series acotadas del shadow (`capped_ids`): el costo es proporcional al tope y no al tamaño de la empresa; es una
    APROXIMACIÓN del modelo que se serviría (menos series de entrenamiento) y así queda registrada."""
    try:
        if lgbm is not None and ref is not None:
            return lgbm.predict(fc_horizon, features.future_frame(cutoff, full_ids, fc_horizon, ref))
        if not train_ml or not capped_ids:
            notes.append({"code": "shadow_ml_not_trained", "message": "El challenger es LightGBM y no se entrena en shadow "
                                                                       "(promotion.shadow_train_ml_challenger = false o sin series entrenables)."})
            return None
        tr, ref_price = features.training_frame(cutoff, capped_ids)
        if not LightGBMGlobal.can_train(tr):
            notes.append({"code": "shadow_ml_not_trainable", "message": f"Las {len(capped_ids)} series acotadas del shadow no bastan para entrenar "
                                                                         "LightGBM (mínimo de series o de filas): sin predicciones de sombra de LightGBM."})
            return None
        model = LightGBMGlobal(seed=seed, n_jobs=n_jobs)
        model.params.update(params or {})
        model.fit(tr)
        notes.append({"code": "shadow_ml_capped_training", "message": (
            f"El LightGBM de sombra se entrenó solo con las {len(capped_ids)} series acotadas del shadow (costo proporcional al tope, no a la empresa): "
            "aproxima, con menos datos, al modelo que se serviría si se promoviera.")})
        return model.predict(fc_horizon, features.future_frame(cutoff, capped_ids, fc_horizon, ref_price))
    except FeatureContractError:
        raise
    except Exception as exc:   # el challenger de sombra nunca puede romper la ejecución servida
        log.exception("LightGBM en shadow falló")
        notes.append({"code": "shadow_ml_failed", "message": f"El challenger LightGBM falló en shadow ({type(exc).__name__}); sin predicciones de sombra."})
        return None


def shadow_summary(challengers: list[Challenger], shadow: pd.DataFrame, notes: list[dict[str, str]], enabled: bool,
                   max_series: int) -> dict[str, Any]:
    """Bloque `summary.promotion.shadow`: qué se observa en sombra, con cuántas series y filas."""
    return {"enabled": enabled, "max_series_per_scope": max_series,
            "challengers": [c.as_dict() for c in challengers], "rows": int(len(shadow)),
            "series": int(shadow["node_key"].nunique()) if len(shadow) else 0, "notes": notes,
            "served": False, "evaluation": "prospectiva tras el corte (evaluate-actuals → model_evaluations.evaluation_type = 'shadow_prospective')"}
