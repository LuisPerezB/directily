"""Ajuste de hiperparámetros con Optuna: OPCIONAL, ACOTADO y APAGADO por defecto (ADR-V25-21; V25-SEL-039..041).

- Opcional: Optuna no es una dependencia declarada del motor (hoy llega de forma transitiva con mlforecast). Se importa de forma perezosa; si
  no está instalado el modo queda NO DISPONIBLE con un mensaje explícito (`optuna_status`) y la ejecución continúa con los hiperparámetros por
  defecto, dejando la limitación `optuna_unavailable` (nunca se inventa un ajuste).
- Acotado: `n_trials`, `timeout_seconds`, semilla, parada temprana (`early_stopping_patience` pruebas seguidas sin mejorar) y tope de CPU
  (`max_cpu` hilos de LightGBM; Optuna corre en serie). La primera prueba es SIEMPRE la configuración por defecto: lo ajustado nunca es peor que
  lo vigente en las ventanas de selección.
- Sin fuga: el objetivo se mide SOLO en las ventanas de selección (cada una termina en o antes del origen del período final reservado);
  `assert_selection_windows_only` lo verifica antes de cada prueba y levanta `HoldoutLeakError` si algún origen invade el período final. El
  experimento queda registrado en el manifiesto (`promotion.tuning`: orígenes usados, origen del holdout, `holdout_used = false`, pruebas).
- Coherencia del protocolo: si el ajuste mejora (≥ 1 % relativo en el WAPE de las ventanas de selección; menos es ruido), el backtest de LightGBM se REHACE con los hiperparámetros ajustados en todas las ventanas
  (también el período final, que solo evalúa), de modo que la selección, los intervalos y las métricas corresponden al modelo servido; el
  entrenamiento final usa los mismos hiperparámetros.

`lightgbm_backtest_rows` reproduce la rama de LightGBM de `backtest.rolling.run_backtest` con hiperparámetros explícitos (una prueba verifica que
con los valores por defecto produce las mismas filas).
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.availability.contracts import FeatureContractError
from predictily_engine.backtest.rolling import (
    AsOfWindows,
    BacktestPlan,
    eligible_ids,
    seasonal_naive_scales,
    series_scale,
)
from predictily_engine.features.builder import FeatureBuilder, last_known_price
from predictily_engine.models.ml import LightGBMGlobal, lgbm_params
from predictily_engine.profiling.days import censored_days
from predictily_engine.profiling.series import Panel, profile_at

log = logging.getLogger(__name__)

FAMILY = "lightgbm_global"
UNAVAILABLE_CODE = "optuna_unavailable"
MIN_IMPROVEMENT = 1e-4          # mejora absoluta mínima del objetivo para contarla como progreso (parada temprana)
MIN_RELATIVE_GAIN = 0.01        # el ajuste solo se APLICA si mejora ≥ 1 % (relativo) el WAPE de los hiperparámetros por defecto: menos es ruido


@dataclass(frozen=True)
class TuningConfig:
    enabled: bool = False
    n_trials: int = 20
    timeout_seconds: int = 120
    seed: int = 42
    early_stopping_patience: int = 8
    max_cpu: int = 2

    def describe(self) -> dict[str, Any]:
        return {"enabled": self.enabled, "n_trials": self.n_trials, "timeout_seconds": self.timeout_seconds, "seed": self.seed,
                "early_stopping_patience": self.early_stopping_patience, "max_cpu": self.max_cpu}


class TuningUnavailable(RuntimeError):
    """Optuna no está instalado: el modo de ajuste no está disponible."""


class HoldoutLeakError(ValueError):
    """Una ventana de ajuste invade el período final reservado."""


def _import_optuna():
    import optuna

    return optuna


def optuna_status() -> tuple[bool, str]:
    """(disponible, mensaje). El mensaje es explícito en ambos casos; no se agrega Optuna como dependencia obligatoria."""
    try:
        optuna = _import_optuna()
    except ImportError:
        return False, ("Optuna no está instalado: el ajuste de hiperparámetros no está disponible (es opcional y no es una dependencia "
                       "obligatoria del motor). Instálelo explícitamente (pip install optuna) para activar `promotion.optuna_enabled`.")
    return True, f"Optuna {getattr(optuna, '__version__', '?')} disponible."


def assert_selection_windows_only(origins: list[Any], horizon: int, holdout_origin: Any | None) -> None:
    """Cada ventana de ajuste (origen + horizonte) debe terminar en o antes del origen del período final reservado; si no hay período final
    independiente no hay nada reservado. @raises HoldoutLeakError."""
    if holdout_origin is None:
        return
    hold = pd.Timestamp(holdout_origin)
    for origin in origins:
        o = pd.Timestamp(origin)
        if o >= hold or o + pd.Timedelta(days=int(horizon)) > hold:
            raise HoldoutLeakError(
                f"La ventana con origen {o.date()} (+{int(horizon)} días) invade el período final reservado (origen {hold.date()}): "
                "el ajuste de hiperparámetros solo puede usar ventanas de selección.")


def search_space(trial: Any) -> dict[str, Any]:
    """Espacio de búsqueda ACOTADO alrededor de los valores por defecto de `models.ml.lgbm_params`."""
    return {
        "num_leaves": trial.suggest_int("num_leaves", 15, 63),
        "learning_rate": trial.suggest_float("learning_rate", 0.02, 0.15, log=True),
        "n_estimators": trial.suggest_int("n_estimators", 150, 500, step=50),
        "min_child_samples": trial.suggest_int("min_child_samples", 20, 100),
        "subsample": trial.suggest_float("subsample", 0.6, 1.0),
        "colsample_bytree": trial.suggest_float("colsample_bytree", 0.6, 1.0),
        "reg_lambda": trial.suggest_float("reg_lambda", 0.1, 10.0, log=True),
        "tweedie_variance_power": trial.suggest_float("tweedie_variance_power", 1.1, 1.5),
    }


def default_trial_params() -> dict[str, Any]:
    """Los valores por defecto del modelo dentro del espacio de búsqueda (primera prueba)."""
    base = lgbm_params(0, 1)
    return {k: base[k] for k in ("num_leaves", "learning_rate", "n_estimators", "min_child_samples", "subsample", "colsample_bytree",
                                 "reg_lambda", "tweedie_variance_power")}


def lightgbm_backtest_rows(panel: Panel, features: FeatureBuilder, plan: BacktestPlan, windows: AsOfWindows | None,
                           params: dict[str, Any], seed: int, n_jobs: int, origins: list[Any] | None = None) -> pd.DataFrame:
    """Filas del backtest de LightGBM con hiperparámetros explícitos (mismas columnas que `run_backtest`).

    `origins`: restringe a esos orígenes (el ajuste solo pasa los de selección); sin él, todas las ventanas del plan (selección + período final)."""
    keep_origins = None if origins is None else {pd.Timestamp(o) for o in origins}
    results = []
    for k, cutoff, is_holdout in plan.origins:
        if keep_origins is not None and pd.Timestamp(cutoff) not in keep_origins:
            continue
        wp, wf = windows.at(cutoff) if windows is not None else (panel, features)
        prof_k = profile_at(wp.df, cutoff)
        ids = eligible_ids(wp, cutoff, prof_k)
        if not ids:
            continue
        train = wp.df[(wp.df["ds"] <= cutoff) & wp.df["unique_id"].isin(ids)]
        if not LightGBMGlobal.can_train(train):
            continue
        try:
            tr, ref = wf.training_frame(cutoff, ids)
            model = LightGBMGlobal(seed=seed, n_jobs=n_jobs)
            model.params.update(params)
            model.fit(tr)
            fc = model.predict(plan.horizon, wf.future_frame(cutoff, ids, plan.horizon, ref))
        except FeatureContractError:
            raise
        except Exception:
            log.exception("LightGBM (ajuste) falló en la ventana %s", pd.Timestamp(cutoff).date())
            continue
        end = cutoff + pd.Timedelta(days=plan.horizon)
        test = panel.df[(panel.df["ds"] > cutoff) & (panel.df["ds"] <= end) & panel.df["unique_id"].isin(ids)]
        test = test.assign(stockout=censored_days(test).to_numpy())
        keep = ["unique_id", "ds", "units", "stockout", "price"] + (["day_status"] if "day_status" in test else [])
        fc = fc.merge(test[keep], on=["unique_id", "ds"], how="inner").rename(columns={"units": "actual", "price": "price_act"})
        fc["step"] = (fc["ds"] - cutoff).dt.days.astype(int)
        fc["window"] = k
        fc["cutoff"] = cutoff
        fc["scale"] = fc["unique_id"].map(series_scale(wp.df, cutoff, ids))
        fc["demand_class"] = fc["unique_id"].map(prof_k.set_index("unique_id")["demand_class"])
        scales = seasonal_naive_scales(wp.df, cutoff, ids)
        fc["scale_mae"] = fc["unique_id"].map(scales["mae_scale"])
        fc["scale_mse"] = fc["unique_id"].map(scales["mse_scale"])
        fc["price_fc"] = fc["unique_id"].map(last_known_price(wp.df, cutoff, ids))
        fc["is_holdout"] = is_holdout
        results.append(fc)
    if not results:
        return pd.DataFrame()
    out = pd.concat(results, ignore_index=True)
    out["yhat"] = out["yhat"].astype(float)
    out["is_holdout"] = out["is_holdout"].astype(bool)
    return out


def _objective_value(rows: pd.DataFrame) -> float | None:
    """WAPE ponderado por unidades en las filas evaluables (sin quiebre, fuente ausente ni cierre). None si no hay denominador."""
    if rows.empty:
        return None
    ok = rows[~rows["stockout"].astype(bool)]
    total = float(ok["actual"].sum())
    return None if total <= 0 else float((ok["actual"] - ok["yhat"]).abs().sum() / total)


@dataclass
class TuningResult:
    experiment: dict[str, Any] | None = None
    params: dict[str, Any] = field(default_factory=dict)             # hiperparámetros ajustados que SE APLICAN ({} = los de siempre)
    backtest_rows: pd.DataFrame | None = None                         # filas de LightGBM rehechas con `params` (todas las ventanas)
    limitations: list[dict[str, str]] = field(default_factory=list)


def tune_lightgbm_stage(panel: Panel, features: FeatureBuilder, plan: BacktestPlan, windows: AsOfWindows | None, cfg: TuningConfig,
                        seed: int, n_jobs: int) -> TuningResult:
    """Ajusta LightGBM dentro de las ventanas de selección. No lanza por indisponibilidad de Optuna: devuelve la limitación explícita."""
    available, message = optuna_status()
    if not available:
        return TuningResult(experiment={"enabled": True, "available": False, "message": message, "holdout_used": False},
                            limitations=[{"code": UNAVAILABLE_CODE, "message": message}])
    optuna = _import_optuna()
    origins = [c for _, c, hold in plan.origins if not hold]
    holdout_origin = plan.holdout_cutoff
    assert_selection_windows_only(origins, plan.horizon, holdout_origin)
    cpu = max(1, min(int(n_jobs), int(cfg.max_cpu)))
    started = time.perf_counter()
    state = {"best": None, "best_number": -1, "stopped_by": None}

    def objective(trial: Any) -> float:
        params = search_space(trial)
        assert_selection_windows_only(origins, plan.horizon, holdout_origin)      # comprobación en CADA prueba
        value = _objective_value(lightgbm_backtest_rows(panel, features, plan, windows, params, seed, cpu, origins=origins))
        if value is None:
            raise optuna.TrialPruned()
        return value

    def stop_when_stalled(study: Any, trial: Any) -> None:
        if trial.state.name != "COMPLETE":
            return
        if state["best"] is None or trial.value < state["best"] - MIN_IMPROVEMENT:
            state["best"], state["best_number"] = trial.value, trial.number
        elif trial.number - state["best_number"] >= cfg.early_stopping_patience:
            state["stopped_by"] = "early_stopping"
            study.stop()

    optuna.logging.set_verbosity(optuna.logging.WARNING)
    study = optuna.create_study(direction="minimize", sampler=optuna.samplers.TPESampler(seed=cfg.seed))
    study.enqueue_trial(default_trial_params())
    # Sin `catch`: HoldoutLeakError y FeatureContractError (defectos de programación) abortan el ajuste en lugar de contarse como pruebas fallidas.
    study.optimize(objective, n_trials=cfg.n_trials, timeout=cfg.timeout_seconds, n_jobs=1, callbacks=[stop_when_stalled])
    elapsed = time.perf_counter() - started
    done = [t for t in study.trials if t.state.name == "COMPLETE"]
    if state["stopped_by"] is None:
        state["stopped_by"] = "timeout" if elapsed >= cfg.timeout_seconds * 0.98 else "n_trials"
    experiment: dict[str, Any] = {
        "enabled": True, "available": True, "engine": "optuna", "optuna_version": getattr(optuna, "__version__", None),
        "sampler": "TPESampler", "seed": cfg.seed, "family": FAMILY, "n_trials_requested": cfg.n_trials, "n_trials_run": len(study.trials),
        "n_trials_completed": len(done), "timeout_seconds": cfg.timeout_seconds, "early_stopping_patience": cfg.early_stopping_patience,
        "max_cpu": cfg.max_cpu, "cpu_used": cpu, "stopped_by": state["stopped_by"], "elapsed_seconds": round(elapsed, 2),
        "objective": "WAPE ponderado por unidades en las ventanas de selección (días no evaluables excluidos)",
        "origins_used": [pd.Timestamp(o).date().isoformat() for o in origins],
        "holdout_origin": None if holdout_origin is None else pd.Timestamp(holdout_origin).date().isoformat(),
        "independent_holdout": holdout_origin is not None, "holdout_used": False,
        "default_params": default_trial_params(),
        "trials": [{"number": t.number, "state": t.state.name, "value": None if t.value is None else round(float(t.value), 6),
                    "params": {k: (round(v, 6) if isinstance(v, float) else v) for k, v in t.params.items()}} for t in study.trials],
    }
    if not done:
        experiment.update({"applied": False, "best_params": None, "best_value": None, "default_value": None,
                           "message": "Ninguna prueba terminó: se conservan los hiperparámetros por defecto."})
        return TuningResult(experiment=experiment, limitations=[{
            "code": "optuna_no_trials", "message": "El ajuste con Optuna no completó ninguna prueba; se usan los hiperparámetros por defecto."}])
    default_trial = next((t for t in done if t.number == 0), None)
    best = study.best_trial
    default_value = None if default_trial is None else float(default_trial.value)
    experiment.update({"best_params": dict(best.params), "best_value": float(best.value), "default_value": default_value})
    improved = default_value is not None and best.number != 0 and float(best.value) < default_value * (1 - MIN_RELATIVE_GAIN)
    experiment["min_relative_gain"] = MIN_RELATIVE_GAIN
    if not improved:
        experiment.update({"applied": False, "message": f"El ajuste no mejoró en ≥ {MIN_RELATIVE_GAIN:.0%} a los hiperparámetros por defecto "
                                                          "en las ventanas de selección: se conservan los de siempre."})
        return TuningResult(experiment=experiment)
    rows = lightgbm_backtest_rows(panel, features, plan, windows, dict(best.params), seed, cpu)      # el mismo tope de CPU que el ajuste
    experiment.update({"applied": True, "message": "Hiperparámetros ajustados en las ventanas de selección; el backtest de LightGBM se rehízo "
                                                   "con ellos (período final solo evalúa)."})
    return TuningResult(experiment=experiment, params=dict(best.params), backtest_rows=rows)
