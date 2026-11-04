"""Cableado del pipeline de factores en el backtest y en la ejecución de demanda (W6-E · ADR-V25-16 y ADR-V25-25).

**Qué hace.** Con el pipeline de factores ENCENDIDO (`factors.enabled`) y al menos un factor activado por la empresa
(`tenants.settings.factor_activation.<clave>.active`), `run_pipeline` resuelve los factores activos (`availability.pipeline.FactorPipeline`) y
arma constructores de features CON las columnas `factor__<clave>` (`FactorArm`). El modelo con factores COMPITE con el LightGBM sin factores,
nunca lo reemplaza por decreto (`FactorOutcome.compete`):

1. **Entrenado como se sirve (revisión adversarial H1).** Las columnas de factor se arman en modo «tal como se sirve» (`availability/served.py`): una
   fila de entrenamiento recibe el valor visible en SU origen y arrastrado como en la predicción (bloques de la longitud del horizonte servido), NUNCA
   el valor del propio día; un valor más viejo que `max_staleness_days` es faltante. Esto elimina la «huella de fecha» con que el ruido podía ganar.
2. **Cada factor por separado, contra su placebo (H1 b/c).** El backtest corre como siempre (LightGBM sin factores). Con las MISMAS ventanas de SELECCIÓN
   se entrena el LightGBM con CADA factor. Un factor pasa si cumple la regla (`FactorArmRule`: mejora relativa mínima del WAPE, fracción de ventanas
   ganadas, ventanas y denominador mínimos) **y** además supera, con el mismo margen, al MEJOR de K placebos con semilla (el mismo factor desplazado en el
   tiempo: misma distribución, mismo rezago de publicación y misma proporción de faltantes, sin relación con el objetivo). Es una selección hacia
   delante: solo los que pasan individualmente entran; la combinación de los que pasaron solo se promueve si TAMBIÉN pasa la regla y sus placebos (si no,
   queda el mejor factor individual que pasó).
3. **El período final reservado (holdout) nunca decide el duelo.** Solo se entrena en él el brazo ganador, DESPUÉS de ganar, y su WAPE frente al del brazo
   sin factores se registra como evidencia SOLO de reporte (con una limitación si es peor). La selección posterior frente a los estadísticos usa la
   política que gobierna la empresa: la heredada usa TODAS las ventanas (selección + período final), la V2.5 solo las de selección; el informe lo dice.
4. Si gana, sus filas ocupan el lugar de las filas `lightgbm_global` del backtest y el resto del pipeline (selección, ajuste, shadow, entrenamiento final)
   trabaja con el constructor ganador; si no, el backtest, la selección y todo lo servido son EXACTAMENTE los de un motor sin factores.

**Garantía exacta** (no «no puede empeorar»): un factor solo llega al modelo servido si en las ventanas de selección mejora al LightGBM sin factores y
supera a sus placebos; eso no garantiza nada sobre el período final ni el futuro (el reporte del período final lo mide y avisa).

**Garantía as-of.** Un valor de factor entra a una feature en el corte `t` solo si era conocido en `t`: el resolvedor usa SIEMPRE la regla
retroactiva (`retroactive=True`) y `FactorResolution.columns_at(t)` recalcula las columnas con lo visible en el corte de CADA ventana. Un valor
desconocido es NaN (faltante, jamás cero). Los pronósticos de terceros sin historial de emisiones quedan fuera (backtest prohibido).

**Alcance honesto.** Los factores son series de la EMPRESA (un valor por fecha, no por producto): no distinguen series. No hay fuentes externas
conectadas (EXTERNAL_SOURCES.md): solo hay factores si la empresa cargó indicadores o campañas.
"""

from __future__ import annotations

import hashlib
import json
import logging
import math
import zlib
from dataclasses import dataclass, field
from datetime import date
from typing import Any, Callable, Mapping

import numpy as np
import pandas as pd

from predictily_engine.availability.contracts import FeatureContractError
from predictily_engine.availability.pipeline import FactorPipeline, FactorResolution
from predictily_engine.backtest.metrics import guarded_wape
from predictily_engine.features.builder import FeatureBuilder

log = logging.getLogger(__name__)

FACTOR_ARM_VERSION = "factor-arm-v2"
ML_FAMILY = "lightgbm_global"
DEFAULT_WAPE_MIN_DENOMINATOR = 30.0          # mismo valor inicial que demand.wape_min_denominator (W3-A)
DEFAULT_PLACEBO_ARMS = 5
DEFAULT_PLACEBO_SEED = 20261103

SELECTED = "factor_arm_selected"             # el modelo con factores pasó la regla en las ventanas de selección
KEPT = "plain_arm_kept"                      # se mantiene el modelo sin factores
NOT_EVALUATED = "not_evaluated"              # no hubo competencia posible (sin factores utilizables, sin backtest, sin LightGBM…)


# ───────────────────────────────────────────── regla de competencia ─────────────────────────────────────────────

@dataclass(frozen=True)
class FactorArmRule:
    """Regla con que el modelo con factores compite con el LightGBM sin factores. Valores del bloque `factors` de `platform/config.py`."""

    min_improvement: float = 0.03
    windows_won_fraction: float = 2 / 3
    min_windows: int = 2
    wape_min_denominator: float = DEFAULT_WAPE_MIN_DENOMINATOR
    placebo_arms: int = DEFAULT_PLACEBO_ARMS         # K placebos con semilla por factor (0 = sin control placebo: NO recomendado)
    placebo_seed: int = DEFAULT_PLACEBO_SEED

    @classmethod
    def from_config(cls, factors_config: Mapping[str, Any], demand_config: Mapping[str, Any] | None = None) -> "FactorArmRule":
        base = cls()
        demand = demand_config or {}
        return cls(
            min_improvement=float(factors_config.get("arm_min_improvement", base.min_improvement)),
            windows_won_fraction=float(factors_config.get("arm_windows_won_fraction", base.windows_won_fraction)),
            min_windows=int(factors_config.get("arm_min_windows", base.min_windows)),
            wape_min_denominator=float(demand.get("wape_min_denominator", base.wape_min_denominator)),
            placebo_arms=int(factors_config.get("arm_placebo_arms", base.placebo_arms)),
            placebo_seed=int(factors_config.get("arm_placebo_seed", base.placebo_seed)))

    def needed_windows(self, n_windows: int) -> int:
        """⌈n × fracción⌉ con el mismo redondeo a 9 decimales que `selector.LegacyPolicy.needed_windows`."""
        return math.ceil(round(n_windows * self.windows_won_fraction, 9))

    def params(self) -> dict[str, Any]:
        return {"min_improvement": self.min_improvement, "windows_won_fraction": self.windows_won_fraction,
                "min_windows": self.min_windows, "wape_min_denominator": self.wape_min_denominator,
                "placebo_arms": self.placebo_arms, "placebo_seed": self.placebo_seed,
                "scope": "selection_windows", "holdout_used_for_duel": False, "rule_version": FACTOR_ARM_VERSION}

    @property
    def version(self) -> str:
        return hashlib.sha256(json.dumps(self.params(), sort_keys=True).encode()).hexdigest()


def _pair_sums(plain: pd.DataFrame, factor: pd.DataFrame) -> tuple[pd.DataFrame, int]:
    """Sumas por ventana (Σ reales, Σ|error| de cada brazo) sobre los días evaluables presentes en los DOS brazos, y cuántas filas se compararon."""
    keys = ["window", "unique_id", "ds"]
    both = plain[keys + ["yhat", "actual", "stockout", "cutoff"]].merge(factor[keys + ["yhat"]], on=keys, how="inner", suffixes=("_plain", "_factor"))
    a = pd.to_numeric(both["actual"], errors="coerce").to_numpy(float)
    p = pd.to_numeric(both["yhat_plain"], errors="coerce").to_numpy(float)
    f = pd.to_numeric(both["yhat_factor"], errors="coerce").to_numpy(float)
    ok = ~both["stockout"].astype(bool).to_numpy() & np.isfinite(a) & np.isfinite(p) & np.isfinite(f)
    work = pd.DataFrame({"window": both["window"].to_numpy(), "cutoff": both["cutoff"].to_numpy(),
                         "a": np.where(ok, a, 0.0), "ae_plain": np.where(ok, np.abs(p - a), 0.0), "ae_factor": np.where(ok, np.abs(f - a), 0.0)})
    per_window = work.groupby("window").agg(cutoff=("cutoff", "first"), a=("a", "sum"), ae_plain=("ae_plain", "sum"), ae_factor=("ae_factor", "sum"))
    return per_window, int(ok.sum())


def duel(plain: pd.DataFrame, factor: pd.DataFrame, rule: FactorArmRule) -> dict[str, Any]:
    """Compara el LightGBM sin factores (`plain`) con el LightGBM con factores (`factor`) en las MISMAS filas de las ventanas de selección.

    Ambos son filas del backtest (`window`, `unique_id`, `ds`, `yhat`, `actual`, `stockout`, `cutoff`). Solo cuentan los días evaluables
    (sin quiebre, fuente ausente ni cierre) presentes en los dos brazos. Devuelve `decision` (`factor_arm_selected` | `plain_arm_kept`), `reason`
    y toda la evidencia numérica (WAPE de cada brazo con su denominador, ventanas ganadas y necesarias). Nunca recibe el período final reservado:
    el llamador solo le pasa ventanas de selección. NO incluye el control de placebos (ver `placebo_gate`)."""
    evidence: dict[str, Any] = {"scope": "selection_windows", "metric": "wape", "rule": rule.params(), "rows_plain": int(len(plain)),
                                "rows_factor": int(len(factor)), "rows_compared": 0, "windows": []}

    def kept(reason: str) -> dict[str, Any]:
        return {"decision": KEPT, "reason": reason, "evidence": evidence}

    if plain.empty:
        return kept("no_plain_rows")
    if factor.empty:
        return kept("factor_arm_incomplete")
    missing = sorted(set(plain["window"]) - set(factor["window"]))
    if missing:
        evidence["windows_missing_in_factor_arm"] = [int(w) for w in missing]
        return kept("factor_arm_incomplete")
    per_window, compared = _pair_sums(plain, factor)
    evidence["rows_compared"] = compared
    rows, used = [], []
    for window, r in per_window.iterrows():
        wp = guarded_wape(float(r["ae_plain"]), float(r["a"]), rule.wape_min_denominator)
        wf = guarded_wape(float(r["ae_factor"]), float(r["a"]), rule.wape_min_denominator)
        stable = wp["status"] == "ok" and wf["status"] == "ok"
        entry = {"window": int(window), "cutoff": pd.Timestamp(r["cutoff"]).date().isoformat(), "denominator": float(r["a"]),
                 "wape_plain": wp["value"], "wape_factor": wf["value"], "used": bool(stable),
                 "factor_better": bool(stable and wf["value"] < wp["value"])}
        rows.append(entry)
        if stable:
            used.append(entry)
    evidence["windows"] = rows
    total_a, total_p, total_f = float(per_window["a"].sum()), float(per_window["ae_plain"].sum()), float(per_window["ae_factor"].sum())
    wape_p = guarded_wape(total_p, total_a, rule.wape_min_denominator)
    wape_f = guarded_wape(total_f, total_a, rule.wape_min_denominator)
    evidence.update({"denominator": total_a, "wape_plain": wape_p["value"], "wape_factor": wape_f["value"], "wape_status": wape_p["status"],
                     "windows_total": len(used), "windows_better": sum(1 for e in used if e["factor_better"]),
                     "windows_needed": rule.needed_windows(len(used)) if used else None, "relative_improvement": None,
                     "passes_improvement": None, "passes_windows": None})
    if wape_p["status"] != "ok" or wape_f["status"] != "ok":
        return kept("denominator_below_minimum")
    if len(used) < rule.min_windows:
        return kept("insufficient_windows")
    relative = (wape_p["value"] - wape_f["value"]) / wape_p["value"] if wape_p["value"] > 0 else None
    # Mejora ESTRICTA además del margen: con un margen de 0 %, empatar no es mejorar.
    passes_improvement = bool(wape_f["value"] < wape_p["value"] and wape_f["value"] <= wape_p["value"] * (1 - rule.min_improvement))
    passes_windows = evidence["windows_better"] >= evidence["windows_needed"]
    evidence.update({"relative_improvement": relative, "passes_improvement": bool(passes_improvement), "passes_windows": bool(passes_windows)})
    if passes_improvement and passes_windows:
        return {"decision": SELECTED, "reason": "improvement_and_windows", "evidence": evidence}
    return kept("no_sufficient_improvement" if not passes_improvement else "not_stable_across_windows")


def placebo_gate(real: Mapping[str, Any], placebos: list[dict[str, Any]], rule: FactorArmRule) -> dict[str, Any]:
    """Control placebo (H1 b): el brazo REAL debe superar al MEJOR de los placebos con el mismo margen y en la misma fracción de ventanas.

    `real`: evidencia del duelo del brazo real (con `wape_factor` y `windows`). `placebos`: un diccionario por placebo con `wape` (su WAPE agregado en las
    mismas ventanas), `windows` (WAPE por ventana, las `used`) y `failed`. Sin placebos suficientes (menos de la mitad de los pedidos) NO se promueve."""
    gate: dict[str, Any] = {"enabled": rule.placebo_arms > 0, "requested": rule.placebo_arms, "arms": [], "passed": None, "reason": None}
    if rule.placebo_arms <= 0:
        return {**gate, "passed": True, "reason": "placebo_disabled", "evaluated": 0}
    valid = [p for p in placebos if not p.get("failed") and p.get("wape") is not None]
    gate["arms"] = [{k: v for k, v in p.items() if k != "windows"} for p in placebos]
    gate["evaluated"] = len(valid)
    if len(valid) < max(1, math.ceil(rule.placebo_arms / 2)):
        return {**gate, "passed": False, "reason": "placebo_unavailable"}
    best = min(valid, key=lambda p: p["wape"])
    real_wape = real.get("wape_factor")
    margin_ok = bool(real_wape is not None and real_wape < best["wape"] and real_wape <= best["wape"] * (1 - rule.min_improvement))
    real_w = {w["window"]: w["wape_factor"] for w in real.get("windows", []) if w.get("used")}
    best_w = {w["window"]: w["wape_factor"] for w in best.get("windows", []) if w.get("used")}
    common = sorted(set(real_w) & set(best_w))
    better = sum(1 for w in common if real_w[w] < best_w[w])
    needed = rule.needed_windows(len(common)) if common else None
    windows_ok = bool(common) and better >= needed
    gate.update({"best_placebo_wape": best["wape"], "best_placebo_seed": best.get("seed"), "real_wape": real_wape,
                 "relative_improvement_vs_best_placebo": (None if not best["wape"] else (best["wape"] - real_wape) / best["wape"]) if real_wape is not None else None,
                 "windows_better_than_best_placebo": better, "windows_compared": len(common), "windows_needed": needed,
                 "passes_margin": margin_ok, "passes_windows": bool(windows_ok)})
    gate["passed"] = bool(margin_ok and windows_ok)
    gate["reason"] = "passes_placebo" if gate["passed"] else "placebo_not_beaten"
    return gate


def holdout_comparison(plain: pd.DataFrame, factor: pd.DataFrame, rule: FactorArmRule) -> dict[str, Any]:
    """WAPE de los dos brazos en el período final reservado (SOLO REPORTE: nunca decide el duelo; ver M2 de la revisión). Mismos días evaluables."""
    out: dict[str, Any] = {"report_only": True, "scope": "holdout", "wape_plain": None, "wape_factor": None, "denominator": None, "factor_better": None,
                           "relative_improvement": None, "rows_compared": 0, "wape_status": None}
    if plain is None or factor is None or plain.empty or factor.empty:
        return out
    per_window, compared = _pair_sums(plain, factor)
    total_a, total_p, total_f = float(per_window["a"].sum()), float(per_window["ae_plain"].sum()), float(per_window["ae_factor"].sum())
    wp = guarded_wape(total_p, total_a, rule.wape_min_denominator)
    wf = guarded_wape(total_f, total_a, rule.wape_min_denominator)
    out.update({"rows_compared": compared, "denominator": total_a, "wape_status": wp["status"], "wape_plain": wp["value"], "wape_factor": wf["value"]})
    if wp["status"] == "ok" and wf["status"] == "ok":
        out["factor_better"] = bool(wf["value"] < wp["value"])
        out["relative_improvement"] = (wp["value"] - wf["value"]) / wp["value"] if wp["value"] > 0 else None
    return out


# ───────────────────────────────────────────── brazo con factores ─────────────────────────────────────────────

@dataclass
class FactorArm:
    """Constructores de features CON factores: el de la verdad final y los de las ventanas cuyo panel as-of se reconstruyó."""

    resolution: FactorResolution
    features: FeatureBuilder
    build: Any                                # Callable[[pd.DataFrame], FeatureBuilder]: constructor para un panel reconstruido
    cache: dict[pd.Timestamp, FeatureBuilder] = field(default_factory=dict)


class FactorWindows:
    """Vista de `AsOfWindows` que entrega, en cada origen, el constructor CON factores (misma interfaz: `at`, `info`, `versions`…).

    El panel es el de siempre (as-of, ADR-V25-23); solo cambia el constructor, que lee de `FactorResolution.columns_at(origen)` los valores
    de los factores tal como se conocían en ese origen. Una ventana cuyo panel coincide con la verdad final reutiliza el constructor de la
    verdad (con factores); una reconstruida arma el suyo."""

    def __init__(self, base: Any, arm: FactorArm) -> None:
        self.base = base
        self.arm = arm

    def at(self, cutoff: pd.Timestamp) -> tuple[Any, FeatureBuilder | None]:
        panel, plain = self.base.at(cutoff)
        if plain is None:
            return panel, None
        if panel is self.base.truth:
            return panel, self.arm.features
        key = pd.Timestamp(cutoff).normalize()
        if key not in self.arm.cache:
            self.arm.cache[key] = self.arm.build(panel.df)
        return panel, self.arm.cache[key]

    def __getattr__(self, name: str) -> Any:        # info(), versions, log, truth…: tal cual la vista base
        return getattr(self.base, name)


@dataclass
class ArmContext:
    """Lo que hace falta para armar un constructor con factores: datos, panel final, constructor sin factores (su política se hereda) y fin del horizonte."""

    data: Any
    panel: Any
    features: FeatureBuilder
    horizon_end: date

    def make_arm(self, resolution: FactorResolution) -> FactorArm:
        options = {**self.features.options(), "factors": resolution}        # hereda features deshabilitadas, precios confirmados y zona horaria

        def build(df: pd.DataFrame, known_on: Any = None) -> FeatureBuilder:
            return FeatureBuilder(df, self.data.branches, self.data.promotions, self.horizon_end, promotion_revisions=self.data.promotion_revisions,
                                  promotions_known_on=known_on, **options)

        truth = build(self.panel.df, self.data.promotions_known_on)         # igual que el constructor de la verdad final (`run_pipeline`)
        return FactorArm(resolution=resolution, features=truth, build=build)


# ───────────────────────────────────────────── resultado del cableado ─────────────────────────────────────────────

def _compact_verdict(v: Mapping[str, Any]) -> dict[str, Any]:
    ev = v.get("evidence") or {}
    placebo = ev.get("placebo") or {}
    return {"decision": v["decision"], "reason": v["reason"], "wape_plain": ev.get("wape_plain"), "wape_factor": ev.get("wape_factor"),
            "relative_improvement": ev.get("relative_improvement"), "windows_better": ev.get("windows_better"), "windows_total": ev.get("windows_total"),
            "placebo": ({k: placebo.get(k) for k in ("enabled", "requested", "evaluated", "passed", "reason", "best_placebo_wape", "real_wape",
                                                     "relative_improvement_vs_best_placebo", "windows_better_than_best_placebo", "windows_compared")}
                        if placebo else None)}


@dataclass
class FactorOutcome:
    """Qué pasó con el pipeline de factores en una ejecución: factores activos, resolución, competencia y decisión. Es lo que se registra en
    `summary.factors` y en el manifiesto. Existe SOLO con el pipeline encendido (apagado ⇒ `prepare_factor_arm` devuelve None)."""

    rule: FactorArmRule
    active: list[str]
    resolution: FactorResolution | None = None
    arm: FactorArm | None = None                 # el brazo GANADOR (solo los factores seleccionados); None mientras no gane ninguno
    arm_factory: Callable[[FactorResolution], FactorArm] | None = None
    decision: str = NOT_EVALUATED
    reason: str | None = None
    evidence: dict[str, Any] | None = None
    limitations: list[dict[str, str]] = field(default_factory=list)
    served: bool = False                      # ¿el modelo con factores es el que realmente se sirve? (lo fija `finalize`)
    holdout_evaluated: bool = False           # el brazo ganador se entrenó en el período final DESPUÉS de decidir (para medir lo que se sirve)
    selected: list[str] = field(default_factory=list)
    per_factor: dict[str, dict[str, Any]] = field(default_factory=dict)
    combination: dict[str, Any] | None = None
    holdout_report: dict[str, Any] | None = None
    holdout_used_downstream: bool = False     # la política que gobierna la selección posterior usa el período final (la heredada sí)
    governing_policy: str | None = None
    served_block_days: int | None = None

    # ── competencia ──
    def compete(self, *, panel: Any, features: FeatureBuilder, plan: Any, windows: Any, bt: pd.DataFrame, use_lgbm: bool, seed: int,
                n_jobs: int) -> tuple[pd.DataFrame, FeatureBuilder, Any]:
        """Hace competir a los factores. Devuelve `(bt, features, windows)`: los de entrada si se mantiene el modelo sin factores; con el brazo
        ganador, las filas `lightgbm_global` del backtest reemplazadas y el constructor/ventanas con factores (que usarán la selección, el ajuste de
        hiperparámetros, el shadow y el entrenamiento final)."""
        if self.resolution is None or not self.resolution.used or self.arm_factory is None:
            return bt, features, windows
        if plan is None or bt is None or bt.empty:
            return self._not_evaluated("no_backtest", bt, features, windows)
        if not use_lgbm:
            return self._not_evaluated("lightgbm_not_used", bt, features, windows)
        selection_origins = [c for _, c, hold in plan.origins if not hold]
        plain = bt[(bt["family"] == ML_FAMILY) & ~bt["is_holdout"].astype(bool)]
        if plain.empty:
            return self._not_evaluated("no_lightgbm_rows", bt, features, windows)
        run = Run(panel=panel, plan=plan, windows=windows, plain=plain, seed=seed, n_jobs=n_jobs, origins=selection_origins)

        # 1) Cada factor por separado, contra su placebo (selección hacia delante).
        arms: dict[str, tuple[FactorArm, pd.DataFrame]] = {}
        verdicts: dict[str, dict[str, Any]] = {}
        for key in self.resolution.used:
            arm_k = self.arm_factory(self.resolution.select([key]))
            verdict, rows = self.evaluate_set(arm_k, [key], run)
            verdicts[key], arms[key] = verdict, (arm_k, rows)
            self.per_factor[key] = _compact_verdict(verdict)
        passing = sorted((k for k, v in verdicts.items() if v["decision"] == SELECTED),
                         key=lambda k: -(verdicts[k]["evidence"].get("relative_improvement") or 0.0))
        if not passing:
            best = max(verdicts, key=lambda k: (verdicts[k]["evidence"].get("relative_improvement") or -1.0))
            return self._decide({"decision": KEPT, "reason": verdicts[best]["reason"], "evidence": verdicts[best]["evidence"]}, bt, features, windows)

        # 2) La combinación de los que pasaron solo se promueve si TAMBIÉN pasa la regla y sus placebos.
        final_keys, final_verdict, (final_arm, final_rows) = [passing[0]], verdicts[passing[0]], arms[passing[0]]
        if len(passing) > 1:
            arm_c = self.arm_factory(self.resolution.select(passing))
            verdict_c, rows_c = self.evaluate_set(arm_c, passing, run)
            self.combination = {"members": list(passing), **_compact_verdict(verdict_c)}
            if verdict_c["decision"] == SELECTED:
                final_keys, final_verdict, final_arm, final_rows = list(passing), verdict_c, arm_c, rows_c
            else:
                self.combination["fallback"] = f"se mantiene el mejor factor individual que pasó: {passing[0]}"
        self.selected = final_keys

        # 3) Ganó con las ventanas de selección: recién ahora se entrena en el período final reservado, para que la evaluación mida lo que se sirve.
        factor_rows = final_rows
        if plan.holdout_cutoff is not None:
            plain_hold = bt[(bt["family"] == ML_FAMILY) & bt["is_holdout"].astype(bool)]
            try:
                hold_rows = self.train_arm(final_arm, run, [plan.holdout_cutoff])
            except FeatureContractError:
                raise
            except Exception as exc:   # noqa: BLE001
                log.exception("El LightGBM con factores falló en el período final reservado")
                self.selected = []
                return self._decide({"decision": KEPT, "reason": "factor_arm_failed_in_holdout_window",
                                     "evidence": {**final_verdict["evidence"], "error": f"{type(exc).__name__}: {exc}"[:300]}}, bt, features, windows)
            if not plain_hold.empty and (hold_rows.empty or set(plain_hold["window"]) - set(hold_rows["window"])):
                self.selected = []
                return self._decide({"decision": KEPT, "reason": "holdout_window_incomplete", "evidence": final_verdict["evidence"]}, bt, features, windows)
            if not hold_rows.empty:
                factor_rows = pd.concat([final_rows, hold_rows], ignore_index=True)
                self.holdout_evaluated = True
                # M2: el período final NO decidió, pero se registra cómo le fue a cada brazo (solo reporte) y se avisa si el brazo con factores es peor.
                self.holdout_report = holdout_comparison(plain_hold, hold_rows, self.rule)
        self.arm = final_arm
        self._decide(final_verdict, bt, features, windows)
        if self.holdout_report and self.holdout_report.get("factor_better") is False:
            self.limitations.append({"code": "factor_model_worse_on_holdout", "message": (
                f"El modelo con factores ({', '.join(self.selected)}) ganó en las ventanas de selección pero en el período final reservado su WAPE "
                f"({self.holdout_report['wape_factor']:.3f}) es peor que el del LightGBM sin factores ({self.holdout_report['wape_plain']:.3f}). "
                "El período final solo es evidencia de reporte: no decidió. Revise si el factor mantiene su efecto.")})
        swapped = pd.concat([bt[bt["family"] != ML_FAMILY], factor_rows], ignore_index=True)
        view = FactorWindows(windows, final_arm) if windows is not None else None
        return swapped, final_arm.features, view

    # ── piezas de la competencia ──
    def train_arm(self, arm: FactorArm, run: "Run", origins: list[Any]) -> pd.DataFrame:
        from predictily_engine.selection.tuning import lightgbm_backtest_rows      # import tardío: tuning importa backtest.rolling

        view = FactorWindows(run.windows, arm) if run.windows is not None else None
        run.used_origins.extend(pd.Timestamp(o) for o in origins)
        return lightgbm_backtest_rows(run.panel, arm.features, run.plan, view, {}, run.seed, run.n_jobs, origins=origins)

    def evaluate_set(self, arm: FactorArm, keys: list[str], run: "Run") -> tuple[dict[str, Any], pd.DataFrame]:
        """Regla + control placebo de UN conjunto de factores (un factor o la combinación)."""
        try:
            rows = self.train_arm(arm, run, run.origins)
        except FeatureContractError:
            raise                      # una feature sin contrato es un defecto de programación, no un fallo del brazo
        except Exception as exc:       # noqa: BLE001  (un fallo del brazo con factores nunca detiene la demanda)
            log.exception("El LightGBM con factores falló en las ventanas de selección")
            return {"decision": KEPT, "reason": "factor_arm_failed", "evidence": {"error": f"{type(exc).__name__}: {exc}"[:300]}}, pd.DataFrame()
        verdict = duel(run.plain, rows, self.rule)
        if verdict["decision"] == SELECTED:
            gate = self.placebo_control(arm, keys, verdict["evidence"], run)
            verdict["evidence"]["placebo"] = gate
            if not gate["passed"]:
                verdict = {"decision": KEPT, "reason": gate["reason"], "evidence": verdict["evidence"]}
        return verdict, rows

    def placebo_control(self, arm: FactorArm, keys: list[str], real: Mapping[str, Any], run: "Run") -> dict[str, Any]:
        """K placebos con semilla del mismo conjunto de factores (observaciones desplazadas en el tiempo) entrenados y medidos exactamente igual."""
        K = self.rule.placebo_arms
        placebos: list[dict[str, Any]] = []
        for k in range(K):
            shifts = {key: _placebo_shift(arm.resolution, key, k, K, self.rule.placebo_seed) for key in keys}
            entry: dict[str, Any] = {"k": k, "seed": self.rule.placebo_seed, "shifts_days": shifts, "failed": False, "wape": None}
            try:
                placebo_arm = self.arm_factory(arm.resolution.shifted(shifts))
                rows = self.train_arm(placebo_arm, run, run.origins)
                v = duel(run.plain, rows, self.rule)
                ev = v["evidence"]
                entry.update({"wape": ev.get("wape_factor"), "relative_improvement_vs_plain": ev.get("relative_improvement"),
                              "decision_vs_plain": v["decision"], "windows": ev.get("windows", [])})
                entry["failed"] = entry["wape"] is None
            except FeatureContractError:
                raise
            except Exception as exc:   # noqa: BLE001
                log.exception("Un placebo del modelo con factores falló")
                entry.update({"failed": True, "error": f"{type(exc).__name__}: {exc}"[:200]})
            placebos.append(entry)
        gate = placebo_gate(real, placebos, self.rule)
        return gate

    def _decide(self, verdict: dict[str, Any], bt: pd.DataFrame, features: FeatureBuilder, windows: Any) -> tuple[pd.DataFrame, FeatureBuilder, Any]:
        self.decision, self.reason, self.evidence = verdict["decision"], verdict["reason"], verdict["evidence"]
        if self.decision != SELECTED:
            self.selected = []
            self.limitations.append({"code": "factor_model_not_selected", "message": self._not_selected_message()})
        return bt, features, windows

    def _not_evaluated(self, reason: str, bt: pd.DataFrame, features: FeatureBuilder, windows: Any) -> tuple[pd.DataFrame, FeatureBuilder, Any]:
        self.decision, self.reason = NOT_EVALUATED, reason
        self.limitations.append({"code": "factors_not_evaluated", "message": (
            f"Hay factores activos y utilizables ({', '.join(self.used)}), pero el modelo con factores no pudo evaluarse en el backtest "
            f"({_REASONS.get(reason, reason)}): no se usa ningún factor.")})
        return bt, features, windows

    def _not_selected_message(self) -> str:
        ev = self.evidence or {}
        figures = ""
        if ev.get("wape_plain") is not None and ev.get("wape_factor") is not None:
            figures = (f" (WAPE en las ventanas de selección: {ev['wape_factor']:.3f} con factores frente a {ev['wape_plain']:.3f} sin ellos; "
                       f"gana en {ev.get('windows_better')}/{ev.get('windows_total')} ventanas)")
        placebo = ev.get("placebo") or {}
        if placebo.get("best_placebo_wape") is not None:
            figures += f"; el mejor placebo obtuvo {placebo['best_placebo_wape']:.3f}"
        return (f"El modelo con factores ({', '.join(self.used)}) no superó al LightGBM sin factores{figures}; motivo: "
                f"{_REASONS.get(self.reason or '', self.reason)}. Se mantiene el modelo sin factores: activar un factor no cambia lo servido "
                "mientras no demuestre mejora sobre el modelo sin factores y sobre sus placebos.")

    # ── registro ──
    @property
    def used(self) -> list[str]:
        """Factores RESUELTOS (candidatos que entraron a la competencia). Los que ganaron están en `selected`."""
        return list(self.resolution.used) if self.resolution is not None else []

    def note_downstream(self, governing_policy: str | None, independent_holdout: bool) -> None:
        """Registra con qué política se hizo la selección posterior frente a los estadísticos y si ésta usa el período final: la heredada elige con TODAS
        las ventanas (selección + período final); la V2.5, solo con las de selección. El duelo del factor nunca lo usa; la selección posterior sí
        puede (y entonces `holdout_used_for_decision` lo dice)."""
        self.governing_policy = governing_policy
        self.holdout_used_downstream = bool(self.decision == SELECTED and independent_holdout and (governing_policy or "legacy") == "legacy")

    def finalize(self, served_families: set[str], failed_families: set[str]) -> None:
        """Fija si el modelo con factores es el que realmente se sirve: ganó la competencia Y alguna clase sirve `lightgbm_global` Y su
        entrenamiento final no falló (si falló, se sirve el respaldo)."""
        self.served = bool(self.decision == SELECTED and ML_FAMILY in served_families and ML_FAMILY not in failed_families)

    def _selected_resolution(self) -> FactorResolution | None:
        return self.arm.resolution if self.arm is not None else None

    def factor_fingerprints(self, keys: list[str] | None = None) -> dict[str, str]:
        """Huella de los valores visibles de CADA factor resuelto (columna al corte final y registro que la respalda): cambia si cambian las
        observaciones visibles al corte; no si llegan datos posteriores."""
        res = self.resolution
        out: dict[str, str] = {}
        if res is None:
            return out
        for key in (keys if keys is not None else res.used):
            column = f"factor__{key}"
            if column not in res.columns:
                continue
            values = pd.util.hash_pandas_object(res.columns[["ds", column]], index=False).to_numpy().tobytes()
            record = res.results[key].as_dict().get("record_id") if key in res.results else None
            out[key] = hashlib.sha256(values + json.dumps({"record": record, "definition": res.definition_version(key)}, sort_keys=True, default=str).encode()).hexdigest()
        return out

    def fingerprint(self) -> str | None:
        """Huella de los factores que usa el modelo servido (los SELECCIONADOS; si ninguno ganó, de los resueltos): dos ejecuciones con los mismos datos de
        factores comparten huella; un cambio en las observaciones visibles al corte la cambia."""
        keys = self.selected or self.used
        prints = self.factor_fingerprints(keys)
        if not prints:
            return None
        return hashlib.sha256(json.dumps(prints, sort_keys=True).encode()).hexdigest()

    def snapshot_block(self) -> dict[str, Any]:
        """Componente `external_indicators` de la huella de datos (H2): por cada factor resuelto, su clave, la versión de su definición, la huella de sus valores
        visibles y su estado (activo, seleccionado). Con el pipeline apagado el componente es `{"enabled": false}` y no pasa por aquí."""
        res = self.resolution
        prints = self.factor_fingerprints()
        return {"enabled": True, "decision": self.decision, "served_block_days": self.served_block_days,
                "factors": [{"key": k, "active": k in self.active, "selected": k in self.selected, "definition_version": res.definition_version(k) if res else None,
                             "fingerprint": prints.get(k)} for k in sorted(self.used)],
                "dropped": [{"key": d["key"], "reason": d["reason"]} for d in (res.dropped if res else [])]}

    def fold_training_hash(self, base: str) -> str:
        """`training_data_hash` con los factores del modelo SERVIDO: solo cuando el modelo con factores se sirve (si no, es el hash de siempre)."""
        if not (self.served and self.selected):
            return base
        return hashlib.sha256((base + "|factors:" + (self.fingerprint() or "")).encode()).hexdigest()

    def registry_compat(self) -> dict[str, Any] | None:
        """Bloque `artifact_compat.factors` del registro de modelos cuando se sirve el modelo con factores (None si no)."""
        if not (self.served and self.selected):
            return None
        res = self._selected_resolution()
        return {"keys": list(self.selected), "columns": [f"factor__{k}" for k in self.selected], "fingerprint": self.fingerprint(),
                "definition_versions": {k: res.definition_version(k) for k in self.selected} if res else {},
                "served_block_days": self.served_block_days, "rule_version": self.rule.version, "retroactive_visibility": True}

    def feature_set_version(self, base: str) -> str:
        """`model_registry.feature_set_version` del modelo servido: la versión del conjunto de features más una marca corta de los factores (≤ 40 caracteres)."""
        compat = self.registry_compat()
        return base if compat is None else f"{base}+factors:{compat['fingerprint'][:12]}"

    def message(self) -> str:
        return _message(self)

    def report(self) -> dict[str, Any]:
        """Bloque compacto de `summary.factors` (serializable)."""
        res = self.resolution
        return {
            "version": FACTOR_ARM_VERSION, "enabled": True, "active_factors": list(self.active), "used_factors": self.used,
            "selected_factors": list(self.selected),
            "dropped_factors": [{k: v for k, v in d.items() if k in ("key", "status", "reason", "fallback")} for d in (res.dropped if res else [])],
            "scenario_only": list(res.scenario_only) if res else [], "decision": self.decision, "reason": self.reason, "message": self.message(),
            "factor_model_served": self.served, "rule": self.rule.params(), "rule_version": self.rule.version,
            "evidence": _compact_evidence(self.evidence), "per_factor": dict(self.per_factor), "combination": self.combination,
            "holdout_used_for_duel": False, "holdout_used_for_decision": self.holdout_used_downstream, "governing_policy": self.governing_policy,
            "holdout_evaluated_after_decision": self.holdout_evaluated, "holdout_comparison": self.holdout_report,
            "retroactive_visibility": True, "served_block_days": self.served_block_days,
            "factor_stats": {k: _compact_stats(v) for k, v in (res.stats.items() if res else [])},
            "data_fingerprint": self.fingerprint(), "definition_versions": {k: res.definition_version(k) for k in self.used} if res else {},
            "competition": ("cada factor por separado contra el LightGBM sin factores y contra sus placebos, solo en las ventanas de selección; la combinación de "
                            "los que pasan solo si también pasa; el ganador compite luego con la política de selección que gobierna la empresa"),
        }

    def manifest_block(self) -> dict[str, Any]:
        """Bloque del manifiesto de evaluación: lo anterior más el detalle por factor (estado en el corte final, motivos) y los contratos de columna."""
        res = self.resolution
        block = self.report()
        block["evidence"] = self.evidence
        if res is not None:
            block["resolution"] = res.report()
            block["feature_contracts"] = [c.as_dict() for c in res.contracts.values()]
        return block


@dataclass
class Run:
    """Contexto de una competencia: panel, plan, ventanas as-of, filas del brazo sin factores y orígenes de selección. `used_origins` registra TODO origen
    en que se entrenó un brazo con factores o un placebo (el período final solo aparece si el brazo ya ganó)."""

    panel: Any
    plan: Any
    windows: Any
    plain: pd.DataFrame
    seed: int
    n_jobs: int
    origins: list[Any]
    used_origins: list[Any] = field(default_factory=list)


def _observation_span(resolution: FactorResolution, key: str) -> int:
    obs = resolution._inputs[key][1] if key in resolution._inputs else None
    if obs is None or not isinstance(obs, pd.DataFrame) or obs.empty or "period_date" not in obs:
        return 0
    period = pd.to_datetime(obs["period_date"], errors="coerce")
    return int((period.max() - period.min()).days) + 1 if period.notna().any() else 0


def _placebo_shift(resolution: FactorResolution, key: str, k: int, K: int, seed: int) -> int:
    """Desplazamiento (días) del placebo `k` de `K` del factor `key`: repartido entre el 15 % y el 85 % del rango de sus observaciones, con una fracción
    aleatoria con semilla dentro de cada franja: los K placebos quedan alineados de forma distinta con el objetivo y nunca casi con el original."""
    span = _observation_span(resolution, key)
    if span < 8:
        return 0
    rng = np.random.default_rng([seed, k, zlib.crc32(key.encode())])
    fraction = 0.15 + 0.70 * ((k + rng.uniform(0.1, 0.9)) / max(K, 1))
    return int(round(fraction * span))


_REASONS = {
    "no_backtest": "no hubo backtest (historia insuficiente)", "lightgbm_not_used": "no se entrena LightGBM en esta ejecución (pocas o demasiadas series)",
    "no_lightgbm_rows": "el backtest no produjo filas de LightGBM", "no_sufficient_improvement": "no mejoró el WAPE en el margen mínimo",
    "not_stable_across_windows": "no ganó en suficientes ventanas de selección", "insufficient_windows": "ventanas de selección comparables insuficientes",
    "denominator_below_minimum": "denominador (suma de reales) por debajo del umbral de estabilidad del WAPE",
    "factor_arm_incomplete": "el modelo con factores no pudo entrenarse en todas las ventanas de selección",
    "factor_arm_failed": "el entrenamiento del modelo con factores falló", "factor_arm_failed_in_holdout_window": "falló en el período final reservado",
    "holdout_window_incomplete": "no se pudo evaluar en el período final reservado", "no_plain_rows": "sin filas del modelo sin factores",
    "improvement_and_windows": "mejora el WAPE en el margen mínimo y en suficientes ventanas",
    "placebo_not_beaten": "no superó al mejor de sus placebos (el mismo factor desplazado en el tiempo) con el margen mínimo",
    "placebo_unavailable": "no se pudieron evaluar suficientes placebos",
    "no_active_factors": "la empresa no activó ningún factor", "inputs_not_loaded": "los datos de los factores activos no se cargaron",
    "no_usable_factors": "ningún factor activo es utilizable en el corte y el horizonte",
}


def _message(outcome: "FactorOutcome") -> str:
    """Qué pasó, en una frase (lo que se lee en `summary.factors.message`)."""
    reason = outcome.reason or ""
    if outcome.decision == SELECTED:
        served = "y se sirve" if outcome.served else "pero la selección posterior no lo sirvió" if outcome.served is False else ""
        return (f"El modelo con {', '.join(outcome.selected)} superó al LightGBM sin factores y a sus placebos en las ventanas de selección {served}.").strip()
    if outcome.decision == KEPT:
        return (f"Se mantiene el modelo sin factores: {_REASONS.get(reason, reason)}. Los factores resueltos ({', '.join(outcome.used) or 'ninguno'}) no cambian lo servido.")
    if reason == "no_active_factors":
        return ("El pipeline de factores está encendido pero la empresa no tiene ningún factor activado "
                f"(factor_activation vacío o desactivado): no entra ningún factor y lo servido es el de un motor sin factores.")
    if reason == "inputs_not_loaded":
        return f"Hay factores activados ({', '.join(outcome.active)}) pero sus datos no se cargaron en esta ejecución: no entra ningún factor."
    if reason == "no_usable_factors":
        dropped = "; ".join(f"{d['key']} ({d['reason']})" for d in (outcome.resolution.dropped if outcome.resolution else []))
        return f"Ningún factor activado es utilizable en el corte y el horizonte ({dropped or 'sin detalle'}): no entra ninguno."
    return f"El modelo con factores no se evaluó: {_REASONS.get(reason, reason) or 'sin motivo registrado'}."


def _compact_evidence(evidence: dict[str, Any] | None) -> dict[str, Any] | None:
    if evidence is None:
        return None
    keys = ("scope", "metric", "wape_plain", "wape_factor", "denominator", "relative_improvement", "windows_total", "windows_better",
            "windows_needed", "passes_improvement", "passes_windows", "rows_compared", "error")
    out = {k: evidence[k] for k in keys if k in evidence}
    placebo = evidence.get("placebo")
    if placebo:
        out["placebo"] = {k: placebo.get(k) for k in ("enabled", "requested", "evaluated", "passed", "reason", "best_placebo_wape", "best_placebo_seed",
                                                      "relative_improvement_vs_best_placebo", "windows_better_than_best_placebo", "windows_compared",
                                                      "windows_needed", "passes_margin", "passes_windows")}
        out["placebo"]["arms"] = [{k: v for k, v in a.items() if k in ("k", "wape", "shifts_days", "failed", "relative_improvement_vs_plain")} for a in placebo.get("arms", [])]
    return out


def _compact_stats(stats: Mapping[str, Any]) -> dict[str, Any]:
    future, training = stats.get("future", {}), stats.get("training", {})
    return {"source_stale_at_origin": stats.get("source_stale_at_origin"), "max_staleness_days": stats.get("max_staleness_days"),
            "future_stale_fraction": future.get("stale_fraction"), "future_missing_fraction": future.get("missing_fraction"), "future_max_age_days": future.get("max_age_days"),
            "training_stale_fraction": training.get("stale_fraction"), "training_missing_fraction": training.get("missing_fraction")}


# ───────────────────────────────────────────── preparación ─────────────────────────────────────────────

def factor_config_record(tenant_settings: Mapping[str, Any] | None, sector: str | None = None) -> dict[str, Any] | None:
    """Registro de la configuración de factores de la empresa para la huella de la ejecución (`effective_config.factors`), o None si el
    pipeline está APAGADO (por defecto) o su configuración es inválida: así la huella de una ejecución sin factores es exactamente la de siempre."""
    from predictily_engine.platform.config import effective_config_record

    try:
        pipeline = FactorPipeline.from_settings(tenant_settings, sector)
        if not pipeline.enabled:
            return None
        record = effective_config_record("factors", dict(tenant_settings or {}), sector)
    except ValueError:
        return None
    return {"core_config_version": record["config_version"], "config": record["effective_config"], "provenance": record["provenance"],
            "active_factors": pipeline.active_factors}


def prepare_factor_arm(data: Any, panel: Any, features: FeatureBuilder, horizon_end: date,
                       demand_config: Mapping[str, Any] | None = None, *, block_days: int | None = None) -> FactorOutcome | None:
    """Resuelve los factores activos de la empresa (modo «tal como se sirve») y deja listo el armado de los constructores con factores. APAGADO
    (`factors.enabled` falso) ⇒ None y no se hace nada más (ni una consulta, ni una columna): paridad exacta con un motor sin factores.

    `data`: `TenantData` (ajustes, sector, zona horaria, `factor_inputs`); `panel`: el panel final; `features`: el constructor sin factores
    (su política de contrato —features deshabilitadas, precios confirmados, zona— se hereda); `horizon_end`: fin del pronóstico interno;
    `block_days`: longitud del horizonte que se sirve en el backtest (el del plan; por defecto, el pronóstico interno completo)."""
    try:
        pipeline = FactorPipeline.from_settings(data.settings, data.sector)
    except ValueError:
        return None                                   # `factor_pipeline_limitations` registra `factors_config_invalid`
    if not pipeline.enabled:
        return None
    rule = FactorArmRule.from_config(pipeline.config, demand_config)
    outcome = FactorOutcome(rule=rule, active=pipeline.active_factors)
    if not outcome.active:
        outcome.reason = "no_active_factors"
        return outcome
    inputs = getattr(data, "factor_inputs", None)
    if inputs is None:
        outcome.reason = "inputs_not_loaded"
        outcome.limitations.append({"code": "factors_inputs_not_loaded", "message": (
            f"El pipeline de factores está encendido y la empresa activó {', '.join(outcome.active)}, pero los datos de esos factores no se "
            "cargaron en esta ejecución: ningún factor entró al modelo.")})
        return outcome
    cutoff = panel.cutoff
    dates = pd.date_range(pd.Timestamp(panel.df["ds"].min()).normalize(), pd.Timestamp(horizon_end).normalize(), freq="D")
    block = int(block_days or max((pd.Timestamp(horizon_end).normalize() - pd.Timestamp(cutoff).normalize()).days, 1))
    outcome.served_block_days = block
    try:
        # Regla retroactiva SIEMPRE (también en el corte final de una ejecución vigente): lo que no puede demostrarse conocido no entra.
        resolution = pipeline.resolve(inputs, dates, cutoff, timezone=getattr(data, "timezone", None), retroactive=True, served_block_days=block)
    except Exception as exc:   # noqa: BLE001  (un fallo inesperado de los factores nunca detiene la demanda)
        log.exception("La resolución de factores falló; la demanda sigue sin factores")
        outcome.reason = "resolution_failed"
        outcome.limitations.append({"code": "factors_resolution_failed", "message": (
            f"La resolución de los factores activados falló ({type(exc).__name__}: {str(exc)[:200]}): ningún factor entró al modelo.")})
        return outcome
    outcome.resolution = resolution
    if resolution.dropped:
        listed = "; ".join(f"{d['key']} ({d['status']}/{d['reason']}, respaldo: {d.get('fallback') or 'sin el factor'})" for d in resolution.dropped)
        outcome.limitations.append({"code": "factors_dropped", "message": (
            f"{len(resolution.dropped)} factor(es) activos quedaron fuera del modelo por no ser utilizables en el corte y en todo el horizonte: "
            f"{listed}. El respaldo es el modelo sin esos factores.")})
    stale = [(k, v) for k, v in resolution.stats.items()
             if (v["future"]["stale_fraction"] or 0) > 0 or v.get("source_stale_at_origin")]
    if stale:
        parts = "; ".join(f"{k}: {(v['future']['stale_fraction'] or 0):.0%} de los días del horizonte vencidos (antigüedad máxima {v['future']['max_age_days']} d, "
                          f"límite {v['max_staleness_days']} d{', fuente vencida en el origen' if v.get('source_stale_at_origin') else ''})" for k, v in stale)
        outcome.limitations.append({"code": "factors_stale", "message": (
            f"El valor arrastrado de algunos factores supera su antigüedad máxima en parte del horizonte: {parts}. Esos días el factor es faltante "
            "(no se usa un valor vencido como si estuviera vigente).")})
    if not resolution.used:
        outcome.reason = "no_usable_factors"
        return outcome
    context = ArmContext(data=data, panel=panel, features=features, horizon_end=horizon_end)
    outcome.arm_factory = context.make_arm
    return outcome


__all__ = ["DEFAULT_PLACEBO_ARMS", "DEFAULT_WAPE_MIN_DENOMINATOR", "FACTOR_ARM_VERSION", "KEPT", "ML_FAMILY", "NOT_EVALUATED", "SELECTED", "ArmContext",
           "FactorArm", "FactorArmRule", "FactorOutcome", "FactorWindows", "Run", "duel", "factor_config_record", "holdout_comparison", "placebo_gate",
           "prepare_factor_arm"]
