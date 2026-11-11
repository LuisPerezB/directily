"""Política de promoción V2.5 y registro de decisiones por alcance (ADR-V25-21; V2.5 §8; requisitos V25-SEL-025..042).

Dos políticas se calculan SIEMPRE en paralelo sobre el mismo backtest y se registran por alcance (`global`, `class:<clase>` y, a modo
informativo, `horizon:<H>`):

- `legacy` (selection/selector.py): la regla vigente, parametrizada y verificable. Es la que GOBIERNA lo servido por defecto: así la demo y
  los E2E no cambian (prueba de paridad exacta contra una copia congelada del selector anterior).
- `v25` (este módulo): gates de promoción, con evidencia por gate, calculados SOLO con las ventanas de selección (el período final reservado
  NUNCA decide: se filtra antes de calcular y el gate `no_leakage` lo verifica sobre las filas realmente usadas y sobre el manifiesto del
  protocolo). Se registra como «sombra de decisión» salvo que la empresa fije `promotion.governing_policy = "v25"`.

Gates V2.5 (nombre → qué exige; los tres primeros son DUROS: si fallan el candidato se rechaza sin pasar a shadow):
  no_leakage              Manifiesto del protocolo sin violaciones y período final independiente e intacto (ni filas ni ajuste lo usaron).
  valid_data              El gate de calidad de datos de W2-C no es BLOCK.
  verifiable_artifacts    El artefacto del candidato es verificable (firma Ed25519 disponible) o el modelo es un reajuste determinista.
  temporal_stability      El candidato no pierde frente a la referencia en más de x ventanas consecutivas.
  segment_regression      Ningún segmento crítico empeora más de `segment_max_regression` frente a la referencia.
  metrics_documented      WAPE, MASE/RMSSE y cobertura de intervalos 80/95 medidos; la cobertura dentro de la tolerancia del nominal.
  improvement             Mejora el WAPE del champion vigente (o del baseline) en ≥ x %, o aporta beneficio operacional (WAPE en dinero).
  human_approval          Aprobación humana separada (W4 · S06): queda `pending`; solo bloquea lo servido con `approval_blocks_serving`.
Si ningún modelo supera al baseline en el alcance, se publica el baseline (`publish_baseline`).

Decisiones (una fila de `model_promotion_decisions` por política y alcance; lo SERVIDO por la política está siempre en `metrics.served_family`):
  promote            el candidato pasa todos los gates y reemplaza al champion (o al baseline).
  keep_champion      el champion vigente sigue siendo el mejor y vuelve a cumplir los gates (o un gate duro impide certificar algo distinto).
  publish_baseline   ningún modelo supera al baseline, o el champion ya no cumple los gates blandos frente al baseline.
  shadow             el candidato no se promueve (gate blando fallido o aprobación pendiente) pero queda como challenger observado sin servirse.
  reject             un gate duro (fuga, datos inválidos, artefacto no verificable) impide considerar al candidato.

Segmentos críticos: `SegmentProvider` (abajo) recibe las filas del backtest de un alcance (las del candidato y la referencia) y devuelve un
DataFrame con el MISMO índice y una columna por dimensión de segmento. `pipeline/core.py` lo conecta a `demand.segments.make_provider` (W3-A:
volumen y vida de la serie as-of en el corte de cada ventana, los mismos segmentos que se miden en `model_evaluations`); si las métricas por segmento
están apagadas o no se puede construir, rige `default_segments` (clase de demanda, tramo de volumen top 20 % / resto con las unidades del período
evaluado, y sucursal, solo con columnas del backtest). La evidencia del gate dice cuál se usó (`evidence.provider`).

Todos los umbrales son valores iniciales «a aprobar en el piloto» (platform/config.py, bloque `promotion`); ninguno es universal.
"""

from __future__ import annotations

import hashlib
import json
import logging
import math
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.backtest.metrics import summarize
from predictily_engine.demand.manifest import build_manifest, verify
from predictily_engine.intervals.conformal import day_bucket, loo_coverage
from predictily_engine.models.registry import BASELINE_FAMILY, CANDIDATES_BY_CLASS, FAMILIES
from predictily_engine.platform.config import HORIZONS
from predictily_engine.selection.selector import (
    LEGACY_RULE_VERSION,
    DEFAULT_BIAS_TOLERANCE,
    DEFAULT_SERIES_TOLERANCE,
    METRIC_SERIES_DAY,
    MIN_CLASS_ACTUAL,
    MIN_CLASS_SERIES,
    POOLED,
    LegacyPolicy,
    Selection,
    _decide_full,
    row_classes,
    select_models,
)

log = logging.getLogger(__name__)

POLICY_LEGACY = "legacy"
POLICY_V25 = "v25"
V25_RULE_VERSION = "promotion-v25-1"
DECISIONS = ("promote", "reject", "keep_champion", "publish_baseline", "shadow")
CLASSES = ("smooth", "erratic", "intermittent", "lumpy")

PASS, FAIL, NOT_EVALUABLE, NOT_APPLICABLE, PENDING = "pass", "fail", "not_evaluable", "not_applicable", "pending"
HARD_GATES = ("no_leakage", "valid_data", "verifiable_artifacts")
GATE_ORDER = ("no_leakage", "valid_data", "verifiable_artifacts", "temporal_stability", "segment_regression", "metrics_documented",
              "improvement", "human_approval")
TOP_VOLUME_SHARE = 0.20      # «tramo de volumen»: el 20 % de las series con más unidades reales en las ventanas de selección
EPS = 1e-9                   # tolerancia de comparación de los umbrales de la V2.5 (R-063: un WAPE de 0,194 calculado por suma NO debe fallar el 3 % de 0,2
                             # por 3e-17). La política heredada conserva su comparación exacta por paridad.

SegmentProvider = Callable[[pd.DataFrame], pd.DataFrame]
DEFAULT_SEGMENT_SOURCE = "default_segments (columnas del backtest; el tramo de volumen usa las unidades del propio período evaluado)"
W3A_SEGMENT_SOURCE = "demand.segments.make_provider (W3-A: volumen y vida de la serie as-of en el corte de cada ventana)"


# ─────────────────────────────────── configuración ───────────────────────────────────

@dataclass(frozen=True)
class V25Policy:
    """Parámetros de los gates de la política V2.5 (`promotion.v25_*`), con su huella (`version`)."""

    min_selection_windows: int = 2
    max_consecutive_losing_windows: int = 1
    min_relative_improvement: float = 0.03
    operational_min_money_improvement: float = 0.05
    segment_max_regression: float = 0.10
    segment_min_actual: float = 30.0
    critical_segments: tuple[str, ...] = ("demand_class", "volume_tier")
    coverage_tolerance: float = 0.10
    approval_blocks_serving: bool = False

    def params(self) -> dict[str, Any]:
        return {"min_selection_windows": self.min_selection_windows,
                "max_consecutive_losing_windows": self.max_consecutive_losing_windows,
                "min_relative_improvement": self.min_relative_improvement,
                "operational_min_money_improvement": self.operational_min_money_improvement,
                "segment_max_regression": self.segment_max_regression, "segment_min_actual": self.segment_min_actual,
                "critical_segments": list(self.critical_segments), "coverage_tolerance": self.coverage_tolerance,
                "approval_blocks_serving": self.approval_blocks_serving, "rule_version": V25_RULE_VERSION,
                "gates": list(GATE_ORDER), "hard_gates": list(HARD_GATES), "top_volume_share": TOP_VOLUME_SHARE}

    @property
    def version(self) -> str:
        """SHA-256 de los parámetros de la política (columna `model_promotion_decisions.policy_version`)."""
        return hashlib.sha256(json.dumps({"policy": POLICY_V25, **self.params()}, sort_keys=True).encode()).hexdigest()


@dataclass(frozen=True)
class ShadowConfig:
    enabled: bool = True
    max_series: int = 100
    train_ml_challenger: bool = True


@dataclass(frozen=True)
class PromotionConfig:
    """Configuración efectiva del bloque `promotion` (core → perfil → empresa), ya validada por `platform.config`."""

    governing_policy: str = POLICY_LEGACY
    legacy: LegacyPolicy = field(default_factory=LegacyPolicy)
    v25: V25Policy = field(default_factory=V25Policy)
    shadow: ShadowConfig = field(default_factory=ShadowConfig)
    tuning: Any = None          # selection.tuning.TuningConfig (importado de forma perezosa: evita ciclos y la dependencia de Optuna)

    def __post_init__(self) -> None:
        if self.governing_policy not in (POLICY_LEGACY, POLICY_V25):
            raise ValueError(f"governing_policy debe ser '{POLICY_LEGACY}' o '{POLICY_V25}' (recibido {self.governing_policy!r}).")
        if self.tuning is None:
            from predictily_engine.selection.tuning import TuningConfig

            object.__setattr__(self, "tuning", TuningConfig())

    @classmethod
    def from_config(cls, cfg: dict[str, Any], min_improvement: float = 0.03) -> "PromotionConfig":
        """`cfg` = `effective_config` del core `promotion`; `min_improvement` = el de la ejecución (demand.min_improvement o el del entorno)."""
        from predictily_engine.selection.tuning import TuningConfig

        return cls(
            governing_policy=str(cfg.get("governing_policy", POLICY_LEGACY)),
            legacy=LegacyPolicy(min_improvement=float(min_improvement),
                                windows_won_fraction=float(cfg.get("legacy_windows_won_fraction", 2 / 3)),
                                selection_metric=str(cfg.get("legacy_selection_metric", METRIC_SERIES_DAY)),
                                bias_tolerance=float(cfg.get("legacy_bias_tolerance", DEFAULT_BIAS_TOLERANCE)),
                                series_tolerance=float(cfg.get("legacy_series_tolerance", DEFAULT_SERIES_TOLERANCE))),
            v25=V25Policy(
                min_selection_windows=int(cfg.get("v25_min_selection_windows", 2)),
                max_consecutive_losing_windows=int(cfg.get("v25_max_consecutive_losing_windows", 1)),
                min_relative_improvement=float(cfg.get("v25_min_relative_improvement", 0.03)),
                operational_min_money_improvement=float(cfg.get("v25_operational_min_money_improvement", 0.05)),
                segment_max_regression=float(cfg.get("v25_segment_max_regression", 0.10)),
                segment_min_actual=float(cfg.get("v25_segment_min_actual", 30.0)),
                critical_segments=tuple(cfg.get("v25_critical_segments", ("demand_class", "volume_tier"))),
                coverage_tolerance=float(cfg.get("v25_coverage_tolerance", 0.10)),
                approval_blocks_serving=bool(cfg.get("v25_approval_blocks_serving", False))),
            shadow=ShadowConfig(enabled=bool(cfg.get("shadow_enabled", True)), max_series=int(cfg.get("shadow_max_series", 100)),
                                train_ml_challenger=bool(cfg.get("shadow_train_ml_challenger", True))),
            tuning=TuningConfig(
                enabled=bool(cfg.get("optuna_enabled", False)), n_trials=int(cfg.get("optuna_n_trials", 20)),
                timeout_seconds=int(cfg.get("optuna_timeout_seconds", 120)), seed=int(cfg.get("optuna_seed", 42)),
                early_stopping_patience=int(cfg.get("optuna_early_stopping_patience", 8)), max_cpu=int(cfg.get("optuna_max_cpu", 2))))

    def policy_versions(self) -> dict[str, str]:
        return {POLICY_LEGACY: self.legacy.version, POLICY_V25: self.v25.version}

    def describe(self) -> dict[str, Any]:
        return {"governing_policy": self.governing_policy, "legacy": self.legacy.describe(),
                "v25": {**self.v25.params(), "policy_version": self.v25.version},
                "shadow": {"enabled": self.shadow.enabled, "max_series": self.shadow.max_series,
                           "train_ml_challenger": self.shadow.train_ml_challenger},
                "optuna": self.tuning.describe()}


# ─────────────────────────────────── resultado ───────────────────────────────────

@dataclass
class ScopeDecision:
    """Decisión de UNA política en UN alcance: una fila de `model_promotion_decisions`."""

    policy: str
    policy_version: str
    scope_key: str
    candidate_family: str
    champion_family: str | None
    served_family: str
    decision: str
    reason: str
    gates: dict[str, Any] = field(default_factory=dict)
    metrics: dict[str, Any] = field(default_factory=dict)
    is_effective: bool = False
    informational: bool = False      # alcances `horizon:<H>`: la familia servida no varía por horizonte

    def as_row(self) -> dict[str, Any]:
        return {"core": "demand", "scope_key": self.scope_key, "policy": self.policy, "policy_version": self.policy_version,
                "candidate_family": self.candidate_family, "champion_family": self.champion_family, "decision": self.decision,
                "is_effective": self.is_effective, "gates": self.gates,
                "metrics": {**self.metrics, "served_family": self.served_family, "informational": self.informational},
                "reason": self.reason}

    def brief(self) -> dict[str, Any]:
        return {"decision": self.decision, "candidate": self.candidate_family, "champion": self.champion_family,
                "served": self.served_family, "is_effective": self.is_effective}


@dataclass
class PromotionOutcome:
    config: PromotionConfig
    governing: str
    legacy: Selection                       # política heredada con TODAS las ventanas (producción)
    legacy_eval: Selection | None           # política heredada con las ventanas de selección (evaluación del período final)
    v25: Selection                          # política V2.5 (solo ventanas de selección)
    selection: Selection                    # la que gobierna lo servido
    eval_selection: Selection | None
    decisions: list[ScopeDecision]
    backtest: pd.DataFrame
    lgbm_params: dict[str, Any] = field(default_factory=dict)
    tuning: dict[str, Any] | None = None
    windows: dict[str, Any] = field(default_factory=dict)
    manifest_problems: list[str] = field(default_factory=list)
    champions: dict[str, str] = field(default_factory=dict)
    limitations: list[dict[str, str]] = field(default_factory=list)
    shadow: dict[str, Any] | None = None
    v25_holdout_rows: int = 0          # filas del período final que la V2.5 recibió para decidir (siempre 0: se filtran antes de decidir)
    shadow_elapsed_seconds: float | None = None    # costo del shadow de esta ejecución (V25-SEL-045); solo en el resumen: el manifiesto es determinista
    champions_source: str = "none"     # de dónde salen los champions: previous_production_run | none_historical_run | none
    fallback_applied: list[dict[str, str]] = field(default_factory=list)   # decisiones cuya familia planeada falló en el entrenamiento final

    def reconcile_served(self, failed: dict[str, str], fallback: str) -> None:
        """Tras `Selection.served(failed)`: las decisiones EFECTIVAS cuya familia planeada falló en el entrenamiento final registran la familia
        realmente servida (el respaldo), la planeada y el error; el resumen lo refleja en `fallback_applied`."""
        for d in self.decisions:
            if d.informational or not d.is_effective or d.served_family not in failed:
                continue
            planned = d.served_family
            d.metrics.update({"fallback_applied": True, "planned_family": planned, "fallback_family": fallback,
                              "final_training_error": failed[planned]})
            d.served_family = fallback
            d.reason += (f" Falló el entrenamiento final de {_name(planned)}: se sirve el respaldo ({_name(fallback)}).")
            self.fallback_applied.append({"scope_key": d.scope_key, "policy": d.policy, "planned_family": planned, "served_family": fallback})

    def selection_criterion(self) -> str:
        """Texto del criterio de selección de la ejecución, generado de la política que gobernó (con la regla exacta de ventanas)."""
        leg = self.config.legacy
        total = (self.legacy.evidence.get(POOLED) or {}).get("windows_total") or 0
        metric = (f"menor WAPE del total diario por clase de demanda (sesgo máximo ±{leg.bias_tolerance:.0%}; error serie-día a lo sumo "
                  f"{leg.series_tolerance:.0%} peor que el mejor)" if leg.by_total else "menor WAPE por clase de demanda")
        rule = (f"{metric}; LightGBM solo si mejora ≥ {leg.min_improvement:.0%} y gana en al menos ⌈n × {leg.windows_won_fraction:.4g}⌉ "
                "ventanas" + (f" (con {total} ventanas: {leg.needed_windows(total)})" if total else ""))
        if self.governing == POLICY_V25:
            return ("política de promoción V2.5 (gates calculados con las ventanas de selección; el período final no decide); la regla heredada se "
                    "registra en paralelo: " + rule)
        return rule

    def rows(self) -> list[dict[str, Any]]:
        """Filas a persistir en `model_promotion_decisions` (una por política y alcance)."""
        return [d.as_row() for d in self.decisions]

    def by_scope(self, policy: str) -> dict[str, ScopeDecision]:
        return {d.scope_key: d for d in self.decisions if d.policy == policy}

    def agreement(self) -> dict[str, Any]:
        leg, v25 = self.by_scope(POLICY_LEGACY), self.by_scope(POLICY_V25)
        scopes = [k for k in leg if k in v25 and not leg[k].informational]
        same = [k for k in scopes if leg[k].served_family == v25[k].served_family]
        return {"scopes": len(scopes), "same_served_model": len(same),
                "different": {k: {"legacy": leg[k].served_family, "v25": v25[k].served_family} for k in scopes if k not in same}}

    def summary(self) -> dict[str, Any]:
        """Bloque `forecast_runs.summary.promotion`: ambas políticas, cuál gobernó y qué decidió cada una por alcance."""
        cfg = self.config
        by = {p: self.by_scope(p) for p in (POLICY_LEGACY, POLICY_V25)}
        policies = {}
        for p, version, rule in ((POLICY_LEGACY, cfg.legacy.version, LEGACY_RULE_VERSION), (POLICY_V25, cfg.v25.version, V25_RULE_VERSION)):
            policies[p] = {"policy_version": version, "rule_version": rule, "effective": p == self.governing,
                           "role": "gobierna lo servido" if p == self.governing else "sombra de decisión (se registra, no se sirve)",
                           "decisions": {k: d.brief() for k, d in by[p].items()}}
        return {"governing_policy": self.governing, "policies": policies, "agreement": self.agreement(),
                "champions": dict(self.champions), "champions_source": self.champions_source, "fallback_applied": list(self.fallback_applied),
                "windows": self.windows, "holdout_used_for_decisions": self.v25_holdout_rows > 0,
                "manifest_problems": list(self.manifest_problems), "tuning": self.tuning,
                "shadow": None if self.shadow is None else {**self.shadow, "elapsed_seconds": self.shadow_elapsed_seconds},
                "approval": {"status": PENDING, "owner": "W4 · S06",
                             "blocks_serving": cfg.v25.approval_blocks_serving,
                             "note": "La aprobación humana separada la implementa W4 · S06; aquí queda pendiente."}}

    def manifest_block(self) -> dict[str, Any]:
        """Bloque `evaluation_manifest.promotion`: parámetros y huellas de ambas políticas, ventanas usadas y comprobaciones."""
        tuning_leak = bool(self.tuning and self.tuning.get("holdout_used"))
        legacy = {k: {n: v.get(n) for n in ("windows_total", "windows_better", "windows_needed", "windows_won_fraction")}
                  for k, v in self.legacy.evidence.items() if v.get("windows_needed") is not None}
        return {"governing_policy": self.governing, "config": self.config.describe(),
                "policy_versions": self.config.policy_versions(), "windows": self.windows,
                # Derivados de lo que realmente ocurrió (filas recibidas por la V2.5, orígenes del ajuste), no constantes
                "holdout_used_for_model_selection_v25": self.v25_holdout_rows > 0, "holdout_used_for_hyperparameters": tuning_leak,
                "legacy_windows_rule": legacy, "champions_source": self.champions_source,
                # Sin tiempos: el manifiesto es reproducible (misma configuración, datos y semilla ⇒ mismo manifiesto); los tiempos van en el resumen.
                "manifest_problems": list(self.manifest_problems),
                "tuning": None if self.tuning is None else {k: v for k, v in self.tuning.items() if k != "elapsed_seconds"},
                "shadow": self.shadow,
                "decisions": [{"scope_key": d.scope_key, "policy": d.policy, "decision": d.decision, "served": d.served_family,
                               "is_effective": d.is_effective} for d in self.decisions]}


# ─────────────────────────────────── estadísticos ───────────────────────────────────

def _name(family: str) -> str:
    """Nombre legible de la familia para las razones (la etiqueta del catálogo; LightGBM abreviado)."""
    return "LightGBM global" if family == "lightgbm_global" else (FAMILIES[family].label if family in FAMILIES else family)


def _num(v: Any) -> float | None:
    if v is None:
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    return None if (math.isnan(f) or math.isinf(f)) else f


def _wape(a: np.ndarray, y: np.ndarray) -> float | None:
    """WAPE sobre los pares finitos; None si el denominador es 0 o no queda ningún par. Un pronóstico no finito (NaN/inf) nunca envenena la métrica."""
    ok = np.isfinite(a) & np.isfinite(y)
    a, y = a[ok], y[ok]
    total = float(np.abs(a).sum())
    return None if total <= 0 else float(np.abs(a - y).sum() / total)


def family_stats(rows: pd.DataFrame) -> dict[str, Any]:
    """WAPE (con su denominador), WAPE por ventana, WAPE en dinero y tamaño de muestra de las filas de UNA familia. Las filas con real o pronóstico
    no finito se excluyen del cálculo y se cuentan en `invalid_rows`."""
    a, y = rows["actual"].to_numpy(float), rows["yhat"].to_numpy(float)
    finite = np.isfinite(a) & np.isfinite(y)
    per_window: dict[int, float | None] = {}
    for w, g in rows.groupby("window"):
        per_window[int(w)] = _wape(g["actual"].to_numpy(float), g["yhat"].to_numpy(float))
    money = None
    if "price_fc" in rows and "price_act" in rows:
        ok = (rows["price_fc"].notna() & rows["price_act"].notna()).to_numpy() & finite
        if ok.any():
            ma, mf = (a * rows["price_act"].to_numpy(float))[ok], (y * rows["price_fc"].to_numpy(float))[ok]
            money = _wape(ma, mf)
    return {"wape": _wape(a, y), "sum_actual": float(a[finite].sum()), "n_obs": int(finite.sum()), "invalid_rows": int((~finite).sum()),
            "money_wape": money, "per_window": per_window, "windows": sorted(per_window)}


# ── Comparación ALINEADA (candidato y referencia sobre las MISMAS observaciones serie × ventana × paso) ──
# backtest/rolling.py omite a LightGBM en una ventana donde no se puede entrenar o falla: comparar a cada familia sobre sus propias filas haría
# «ganar» al candidato que falta en una ventana mala. Todos los gates de la V2.5 (mejora, estabilidad, segmentos, cobertura) se calculan sobre la
# intersección de filas del candidato y de la referencia (champion o baseline), y el candidato debe cubrir TODAS las ventanas de selección.
KEY_COLUMNS = ["unique_id", "window", "step"]


@dataclass
class AlignedPair:
    candidate: str
    reference: str
    rows: pd.DataFrame                       # filas de ambas familias, solo las observaciones comunes
    cand: dict[str, Any]
    ref: dict[str, Any]
    n_aligned: int
    n_candidate: int
    n_reference: int
    required_windows: list[int]
    windows_missing: list[int]               # ventanas requeridas SIN ninguna fila del candidato
    windows_not_comparable: list[int]        # con filas del candidato pero sin observaciones comunes con la referencia

    def evidence(self) -> dict[str, Any]:
        return {"aligned_observations": self.n_aligned, "candidate_observations": self.n_candidate, "reference_observations": self.n_reference,
                "aligned_share_of_reference": None if not self.n_reference else self.n_aligned / self.n_reference,
                "required_windows": self.required_windows, "windows_missing": self.windows_missing,
                "windows_not_comparable": self.windows_not_comparable}


def with_keys(rows: pd.DataFrame) -> pd.DataFrame:
    """Agrega `_k`: un código entero por observación (serie × ventana × paso), común a todas las familias del marco."""
    if "_k" in rows:
        return rows
    return rows.assign(_k=rows.groupby(KEY_COLUMNS, sort=False).ngroup())


def align_pair(rows: pd.DataFrame, candidate: str, reference: str, required_windows: list[int]) -> AlignedPair:
    """Filas del candidato y de la referencia restringidas a las observaciones que ambos tienen. `rows` ya trae `_k` (`with_keys`)."""
    rows = with_keys(rows)
    rc, rr = rows[rows["family"] == candidate], rows[rows["family"] == reference]
    common = np.intersect1d(rc["_k"].to_numpy(), rr["_k"].to_numpy())
    ac, ar = rc[rc["_k"].isin(common)], rr[rr["_k"].isin(common)]
    have, comparable = {int(w) for w in rc["window"].unique()}, {int(w) for w in ac["window"].unique()}
    return AlignedPair(
        candidate=candidate, reference=reference, rows=pd.concat([ac, ar]), cand=family_stats(ac), ref=family_stats(ar), n_aligned=int(len(common)),
        n_candidate=int(len(rc)), n_reference=int(len(rr)), required_windows=list(required_windows),
        windows_missing=[w for w in required_windows if w not in have],
        windows_not_comparable=[w for w in required_windows if w in have and w not in comparable])


def default_segments(rows: pd.DataFrame) -> pd.DataFrame:
    """Proveedor de segmentos por defecto (solo con columnas del backtest): clase de demanda, tramo de volumen (top 20 % de unidades reales
    por serie / resto) y sucursal. Punto de integración con `demand/segments.py` de W3-A: ver el docstring del módulo."""
    out = pd.DataFrame(index=rows.index)
    if "demand_class" in rows:
        out["demand_class"] = rows["demand_class"].astype(object)
    if len(rows):
        first = rows["family"].iloc[0]
        totals = rows[rows["family"] == first].groupby("unique_id")["actual"].sum().sort_values(ascending=False, kind="stable")
        top = set(totals.index[:max(1, math.ceil(TOP_VOLUME_SHARE * len(totals)))])
        out["volume_tier"] = np.where(rows["unique_id"].isin(top), "top20", "rest")
        out["branch"] = "b" + rows["unique_id"].astype(str).str.extract(r"b(\d+)")[0].fillna("?")
    return out


def _gate(status: str, message: str, *, blocking: bool = True, **evidence: Any) -> dict[str, Any]:
    return {"status": status, "blocking": blocking, "message": message, "evidence": evidence}


# ─────────────────────────────────── gates V2.5 ───────────────────────────────────

@dataclass
class GateContext:
    """Insumos comunes de los gates de una ejecución (los mismos para todos los alcances)."""

    policy: V25Policy
    independent_holdout: bool
    manifest_problems: list[str]
    quality_outcome: str | None
    quality_reasons: list[str]
    signing_available: bool
    segment_provider: SegmentProvider = default_segments
    tuning_check: dict[str, Any] | None = None
    windows_used: list[int] = field(default_factory=list)
    fallback_family: str = BASELINE_FAMILY
    segment_source: str = DEFAULT_SEGMENT_SOURCE      # quién definió los segmentos críticos (queda en la evidencia del gate)


def gate_no_leakage(rows: pd.DataFrame, ctx: GateContext) -> dict[str, Any]:
    holdout_rows = int(rows["is_holdout"].astype(bool).sum()) if "is_holdout" in rows else 0
    tuning_leak = bool(ctx.tuning_check and ctx.tuning_check.get("holdout_used"))
    evidence = {"holdout_rows_used": holdout_rows, "independent_holdout": ctx.independent_holdout,
                "windows_used": list(ctx.windows_used), "manifest_problems": list(ctx.manifest_problems),
                "tuning": ctx.tuning_check}
    if holdout_rows or ctx.manifest_problems or tuning_leak:
        why = ("el período final reservado participó en la decisión" if holdout_rows or tuning_leak
               else "el manifiesto del protocolo tiene violaciones: " + "; ".join(ctx.manifest_problems))
        return _gate(FAIL, "Fuga detectada: " + why + ".", **evidence)
    if not ctx.independent_holdout:
        return _gate(NOT_EVALUABLE, "Sin período final independiente no se puede certificar la ausencia de fuga ni medir fuera de muestra: "
                     "las métricas provienen de las ventanas que eligieron el modelo.", **evidence)
    return _gate(PASS, "Decisión tomada solo con ventanas de selección; el período final reservado y el manifiesto del protocolo están intactos.",
                 **evidence)


def gate_valid_data(ctx: GateContext) -> dict[str, Any]:
    if ctx.quality_outcome is None:
        return _gate(NOT_EVALUABLE, "No se evaluó el gate de calidad de datos (W2-C) para esta ejecución.", outcome=None)
    if ctx.quality_outcome == "BLOCK":
        return _gate(FAIL, "El gate de calidad de datos está en BLOCK.", outcome=ctx.quality_outcome, reason_codes=ctx.quality_reasons)
    return _gate(PASS, f"Gate de calidad de datos (W2-C) = {ctx.quality_outcome}: distinto de BLOCK.", outcome=ctx.quality_outcome,
                 reason_codes=ctx.quality_reasons)


def gate_artifacts(candidate: str, ctx: GateContext) -> dict[str, Any]:
    info = FAMILIES.get(candidate)
    if info is not None and info.kind == "statistical":
        return _gate(NOT_APPLICABLE, "Modelo estadístico determinista: sin artefacto; se reajusta con la configuración y la semilla registradas.",
                     family=candidate, kind="statistical_refit")
    if ctx.signing_available:
        return _gate(PASS, "El artefacto se guarda firmado (Ed25519, ADR-V25-20) y se verifica antes de servirse.", family=candidate,
                     kind="signed_artifact", signing="available")
    return _gate(FAIL, "No hay clave de firma configurada: el artefacto quedaría sin firmar y el serving lo rechaza (ADR-V25-20).",
                 family=candidate, kind="signed_artifact", signing="unavailable")


def gate_stability(cand: dict[str, Any], ref: dict[str, Any], policy: V25Policy, windows_missing: list[int] | None = None,
                   required_windows: list[int] | None = None, windows_not_comparable: list[int] | None = None) -> dict[str, Any]:
    """El candidato no pierde frente a la referencia en más de x ventanas consecutivas, medido sobre observaciones alineadas, y cubre TODAS las
    ventanas de selección requeridas (una ventana sin filas del candidato no se puede ignorar: sería ganar por ausencia)."""
    windows = sorted(w for w in set(cand["per_window"]) & set(ref["per_window"])
                     if cand["per_window"][w] is not None and ref["per_window"][w] is not None)
    losses = [bool(cand["per_window"][w] > ref["per_window"][w] + EPS) for w in windows]
    run = best = 0
    for lost in losses:
        run = run + 1 if lost else 0
        best = max(best, run)
    missing = list(windows_missing or [])
    evidence = {"windows": windows, "candidate_wape_by_window": {str(w): cand["per_window"][w] for w in windows},
                "reference_wape_by_window": {str(w): ref["per_window"][w] for w in windows}, "losses": losses,
                "max_consecutive_losses": best, "max_allowed": policy.max_consecutive_losing_windows,
                "min_windows": policy.min_selection_windows, "required_windows": list(required_windows or []), "windows_missing": missing,
                "windows_not_comparable": list(windows_not_comparable or [])}
    if missing:
        return _gate(FAIL, f"El candidato no tiene filas en la(s) ventana(s) de selección {missing} (no se pudo entrenar o falló): su estabilidad "
                     "temporal no se puede certificar y no se le cuenta la ausencia como ventaja.", **evidence)
    if len(windows) < policy.min_selection_windows:
        return _gate(NOT_EVALUABLE, f"Solo {len(windows)} ventana(s) comparables (< {policy.min_selection_windows}): la estabilidad temporal "
                     "no se puede certificar.", **evidence)
    if best > policy.max_consecutive_losing_windows:
        return _gate(FAIL, f"El candidato pierde frente a la referencia en {best} ventanas consecutivas "
                     f"(máximo permitido {policy.max_consecutive_losing_windows}).", **evidence)
    return _gate(PASS, f"Máximo de ventanas consecutivas perdidas: {best} (permitido {policy.max_consecutive_losing_windows}).", **evidence)


def gate_segments(rows: pd.DataFrame, candidate: str, reference: str, policy: V25Policy,
                  provider: SegmentProvider, source: str = DEFAULT_SEGMENT_SOURCE) -> dict[str, Any]:
    pair = rows[rows["family"].isin([candidate, reference])]
    seg = provider(pair)
    violations, evaluated, insufficient, unavailable = [], [], [], []
    for dim in policy.critical_segments:
        if dim not in seg.columns:
            unavailable.append(dim)
            continue
        labels = seg[dim]
        for value in sorted({v for v in labels.dropna().unique()}, key=str):
            sub = pair[(labels == value).to_numpy()]
            c, r = sub[sub["family"] == candidate], sub[sub["family"] == reference]
            actual = float(r["actual"].sum())
            wc = _wape(c["actual"].to_numpy(float), c["yhat"].to_numpy(float))
            wr = _wape(r["actual"].to_numpy(float), r["yhat"].to_numpy(float))
            entry = {"dimension": dim, "segment": str(value), "sum_actual": actual, "candidate_wape": wc, "reference_wape": wr}
            if actual < policy.segment_min_actual or wr is None or wc is None:
                insufficient.append({**entry, "status": "insufficient_data"})
                continue
            regression = (wc - wr) / wr if wr > 0 else (0.0 if wc <= 0 else math.inf)
            entry["relative_regression"] = None if math.isinf(regression) else regression
            evaluated.append(entry)
            if regression > policy.segment_max_regression + EPS:
                violations.append(entry)
    evidence = {"provider": source, "critical_dimensions": list(policy.critical_segments), "evaluated": evaluated, "insufficient_data": insufficient,
                "dimensions_unavailable": unavailable, "max_regression": policy.segment_max_regression,
                "min_actual": policy.segment_min_actual, "violations": violations}
    if violations:
        v = violations[0]
        return _gate(FAIL, f"Regresión material en {len(violations)} segmento(s) crítico(s), p. ej. {v['dimension']}:{v['segment']} "
                     f"(WAPE {v['candidate_wape']:.3f} frente a {v['reference_wape']:.3f}).", **evidence)
    if not evaluated:
        return _gate(NOT_EVALUABLE, "Ningún segmento crítico alcanza la muestra mínima (insufficient_data): la ausencia de regresión no se "
                     "puede certificar.", **evidence)
    return _gate(PASS, f"Sin regresión material en {len(evaluated)} segmento(s) crítico(s) evaluado(s) "
                 f"(límite {policy.segment_max_regression:.0%}); {len(insufficient)} con insufficient_data.", **evidence)


def gate_metrics_documented(rows: pd.DataFrame, candidate: str, stats: dict[str, Any], policy: V25Policy) -> dict[str, Any]:
    cand = rows[rows["family"] == candidate]
    summary = summarize(cand.assign(_g=1), ["_g"]).iloc[0] if len(cand) else None
    cov80 = cov95 = None
    n_windows = int(cand["window"].nunique()) if len(cand) else 0
    if n_windows >= 2:
        grouped = cand.assign(group=cand["demand_class"].astype(str)) if "demand_class" in cand else cand.assign(group="all")
        cov80, cov95 = loo_coverage(grouped, "group", day_bucket)
    mase = _num(summary["mase"]) if summary is not None and "mase" in summary else None
    rmsse = _num(summary["rmsse"]) if summary is not None and "rmsse" in summary else None
    tol = policy.coverage_tolerance
    evidence = {"wape": stats["wape"], "sum_actual": stats["sum_actual"], "n_obs": stats["n_obs"], "mase": mase, "rmsse": rmsse,
                "coverage_80": cov80, "coverage_95": cov95, "nominal_80": 0.80, "nominal_95": 0.95, "tolerance": tol,
                "coverage_method": "leave_one_window_out (diagnóstico de las ventanas de selección)", "n_windows": n_windows}
    if stats["wape"] is None:
        return _gate(NOT_EVALUABLE, "WAPE no definido (denominador 0): las métricas del candidato no se pueden documentar.", **evidence)
    if cov80 is None or cov95 is None:
        return _gate(NOT_EVALUABLE, "Sin cobertura de intervalos medible (se necesitan ≥ 2 ventanas con residuos): la calibración no queda "
                     "documentada.", **evidence)
    if abs(cov80 - 0.80) > tol + EPS or abs(cov95 - 0.95) > tol + EPS:
        return _gate(FAIL, f"Cobertura fuera de tolerancia: 80 % → {cov80:.1%}, 95 % → {cov95:.1%} (±{tol:.0%} del nominal).", **evidence)
    return _gate(PASS, f"Métricas e intervalos documentados; cobertura 80 % = {cov80:.1%}, 95 % = {cov95:.1%} (±{tol:.0%}).", **evidence)


def gate_improvement(cand: dict[str, Any], ref: dict[str, Any], policy: V25Policy, reference_label: str) -> dict[str, Any]:
    cw, rw = cand["wape"], ref["wape"]
    rel = None if cw is None or rw is None or rw <= 0 else (rw - cw) / rw
    cm, rm = cand["money_wape"], ref["money_wape"]
    rel_money = None if cm is None or rm is None or rm <= 0 else (rm - cm) / rm
    units_ok = rel is not None and rel >= policy.min_relative_improvement - EPS
    operational = bool(rel_money is not None and rel_money >= policy.operational_min_money_improvement - EPS and cw is not None
                       and rw is not None and cw <= rw + EPS)
    evidence = {"reference": reference_label, "candidate_wape": cw, "reference_wape": rw, "relative_improvement": rel,
                "required_relative_improvement": policy.min_relative_improvement, "candidate_money_wape": cm,
                "reference_money_wape": rm, "money_relative_improvement": rel_money,
                "operational_required_money_improvement": policy.operational_min_money_improvement,
                "passed_via": "units" if units_ok else ("operational_money" if operational else None)}
    if cw is None or rw is None:
        return _gate(NOT_EVALUABLE, "WAPE del candidato o de la referencia no definido.", **evidence)
    if units_ok:
        return _gate(PASS, f"Mejora el WAPE frente al {reference_label} en {rel:.1%} (mínimo {policy.min_relative_improvement:.0%}).", **evidence)
    if operational:
        return _gate(PASS, f"Beneficio operacional: el WAPE en dinero mejora {rel_money:.1%} sin empeorar las unidades "
                     f"(mínimo {policy.operational_min_money_improvement:.0%}).", **evidence)
    shown = "no definida" if rel is None else f"{rel:.1%}"
    return _gate(FAIL, f"Mejora de unidades frente al {reference_label}: {shown} (mínimo {policy.min_relative_improvement:.0%}) y sin "
                 "beneficio operacional suficiente.", **evidence)


def gate_approval(policy: V25Policy, applicable: bool = True) -> dict[str, Any]:
    if not applicable:
        return _gate(NOT_APPLICABLE, "El champion ya fue promovido: la aprobación humana se pidió en su promoción.", blocking=False)
    return _gate(PENDING, "Aprobación humana separada pendiente (la implementa W4 · S06).", blocking=policy.approval_blocks_serving,
                 approved_by=None, approved_at=None, owner="W4 · S06", blocks_serving=policy.approval_blocks_serving)


def failures(gates: dict[str, dict[str, Any]]) -> list[str]:
    """Gates bloqueantes que no pasan (`fail`, `not_evaluable` y, si bloquea, `pending`)."""
    return [n for n in GATE_ORDER if n in gates and gates[n]["blocking"] and gates[n]["status"] in (FAIL, NOT_EVALUABLE, PENDING)]


# ─────────────────────────────────── decisión V2.5 por alcance ───────────────────────────────────

def _stat_dict(s: dict[str, Any]) -> dict[str, Any]:
    return {"wape": s["wape"], "money_wape": s["money_wape"], "sum_actual": s["sum_actual"], "n_obs": s["n_obs"],
            "invalid_rows": s.get("invalid_rows", 0), "wape_by_window": {str(w): v for w, v in s["per_window"].items()}}


def _gates(rows: pd.DataFrame, pair: AlignedPair, ctx: GateContext, reference_label: str, new_candidate: bool) -> dict[str, dict[str, Any]]:
    """Todos los gates de `pair.candidate` frente a `pair.reference`; los de datos (mejora, estabilidad, segmentos, cobertura) usan SOLO las
    observaciones alineadas de ambos. `rows` (todo el alcance) sirve únicamente al gate de fuga."""
    policy = ctx.policy
    return {
        "no_leakage": gate_no_leakage(rows, ctx),
        "valid_data": gate_valid_data(ctx),
        "verifiable_artifacts": gate_artifacts(pair.candidate, ctx),
        "temporal_stability": gate_stability(pair.cand, pair.ref, policy, pair.windows_missing, pair.required_windows, pair.windows_not_comparable),
        "segment_regression": gate_segments(pair.rows, pair.candidate, pair.reference, policy, ctx.segment_provider, ctx.segment_source),
        "metrics_documented": gate_metrics_documented(pair.rows, pair.candidate, pair.cand, policy),
        "improvement": gate_improvement(pair.cand, pair.ref, policy, reference_label),
        "human_approval": gate_approval(policy, applicable=new_candidate),
    }


def _split(gates: dict[str, dict[str, Any]]) -> tuple[list[str], list[str], list[str], bool]:
    """(fallidos, duros, blandos, espera aprobación) de un juego de gates."""
    failed = failures(gates)
    hard = [n for n in failed if n in HARD_GATES]
    soft = [n for n in failed if n not in HARD_GATES and n != "human_approval"]
    return failed, hard, soft, "human_approval" in failed


def _demotes(gates: dict[str, dict[str, Any]]) -> bool:
    """¿El champion (frente al baseline) debe ceder su lugar al baseline? Sí si un gate blando MIDE que no cumple (`fail`: la evidencia en su
    contra pesa aunque un gate duro tampoco se pueda certificar) o si falla uno blando y ningún gate duro impide certificarlo. Si solo falla un
    gate duro no hay evidencia en su contra: se conserva."""
    _, hard, soft, _ = _split(gates)
    measured_bad = [n for n in soft if gates[n]["status"] == FAIL]
    return bool(measured_bad) or (bool(soft) and not hard)


def decide_v25_scope(scope_key: str, rows: pd.DataFrame, candidates: list[str], champion: str | None, ctx: GateContext,
                     *, informational: bool = False) -> ScopeDecision:
    """Decisión de la política V2.5 en un alcance. `rows`: filas del backtest del alcance SIN días no evaluables; el llamador las limita a las
    ventanas de selección (el gate `no_leakage` lo verifica sobre las filas recibidas).

    Candidato, champion y baseline se comparan SIEMPRE sobre las observaciones (serie × ventana × paso) que tienen en común; el candidato debe
    cubrir todas las ventanas de selección. Las filas con real o pronóstico no finito se descartan antes (y se cuentan en la evidencia)."""
    policy, version = ctx.policy, ctx.policy.version
    rows = rows[rows["family"].isin(candidates)]
    finite = np.isfinite(rows["actual"].to_numpy(float)) & np.isfinite(rows["yhat"].to_numpy(float))
    invalid = {str(k): int(v) for k, v in rows.loc[~finite].groupby("family").size().items()} if (~finite).any() else {}
    rows = with_keys(rows[finite])
    stats = {f: family_stats(g) for f, g in rows.groupby("family")}
    base = stats.get(BASELINE_FAMILY)
    common = {"policy": POLICY_V25, "policy_version": version, "scope_key": scope_key, "informational": informational}
    scope_metrics = {"families": {f: _stat_dict(s) for f, s in sorted(stats.items())}, "windows_used": list(ctx.windows_used),
                     "holdout_used": False, "invalid_rows_dropped": invalid}
    if base is None or base["wape"] is None:
        served = ctx.fallback_family
        return ScopeDecision(**common, candidate_family=served, champion_family=champion, served_family=served, decision="reject",
                             reason="El baseline no se pudo evaluar en el alcance (sin datos o WAPE indefinido): no hay referencia con que "
                                    "certificar candidatos; se sirve el respaldo simple.",
                             gates={"beats_baseline": _gate(NOT_EVALUABLE, "Baseline sin WAPE definido.", baseline=BASELINE_FAMILY)},
                             metrics=scope_metrics)
    champion_ok = champion is not None and champion in stats and stats[champion]["wape"] is not None
    champion_source = "previous_run" if champion_ok else ("not_evaluated_now" if champion is not None else "baseline")
    champion_used = champion if champion_ok else BASELINE_FAMILY
    champion_real = champion_used != BASELINE_FAMILY
    scope_metrics.update({"champion_source": champion_source, "baseline_family": BASELINE_FAMILY})

    # Contendientes ordenados por su mejora sobre el baseline MEDIDA EN LAS OBSERVACIONES QUE AMBOS TIENEN.
    fam_rows = {f: g for f, g in rows.groupby("family")}
    base_rows = fam_rows[BASELINE_FAMILY]
    required = sorted(int(w) for w in base_rows["window"].unique())
    contenders: list[dict[str, Any]] = []
    for f, g in fam_rows.items():
        if f == BASELINE_FAMILY:
            continue
        shared = np.intersect1d(g["_k"].to_numpy(), base_rows["_k"].to_numpy())
        if not len(shared):
            continue
        gc, gb = g[g["_k"].isin(shared)], base_rows[base_rows["_k"].isin(shared)]
        wc, wb = _wape(gc["actual"].to_numpy(float), gc["yhat"].to_numpy(float)), _wape(gb["actual"].to_numpy(float), gb["yhat"].to_numpy(float))
        if wc is None or wb is None:
            continue
        covered = {int(w) for w in g["window"].unique()}
        contenders.append({"family": f, "wape": wc, "baseline_wape": wb, "ratio": wc / wb if wb > 0 else wc, "aligned": int(len(shared)),
                           "windows_missing": [w for w in required if w not in covered]})
    contenders.sort(key=lambda c: (c["ratio"], c["wape"], c["family"]))
    scope_metrics["contenders_vs_baseline"] = [{k: v for k, v in c.items() if k != "ratio"} for c in contenders]
    winners = [c for c in contenders if c["wape"] < c["baseline_wape"] - EPS]
    if not winners:
        best = contenders[0] if contenders else None
        reason = ("Ningún modelo supera al baseline en el alcance"
                  + (f" (el mejor, {_name(best['family'])}, tiene WAPE {best['wape']:.3f} frente a {best['baseline_wape']:.3f} del baseline en las "
                     "mismas observaciones" if best else " (sin otros candidatos evaluados")
                  + "): se publica el baseline.")
        gates = {"beats_baseline": _gate(FAIL, "Ningún candidato supera al baseline.", baseline_wape=base["wape"],
                                         best_other=None if best is None else {"family": best["family"], "wape": best["wape"],
                                                                               "baseline_wape_same_rows": best["baseline_wape"]})}
        return ScopeDecision(**common, candidate_family=BASELINE_FAMILY, champion_family=champion_used, served_family=BASELINE_FAMILY,
                             decision="publish_baseline", reason=reason, gates=gates, metrics=scope_metrics)
    # Se prefiere el mejor candidato que cubre TODAS las ventanas de selección; si ninguno las cubre, el mejor a secas (su gate de estabilidad falla
    # con la evidencia de las ventanas que le faltan y queda en shadow).
    chosen = next((c for c in winners if not c["windows_missing"]), winners[0])
    cand = chosen["family"]
    new_candidate = cand != champion_used
    reference = champion_used if new_candidate else BASELINE_FAMILY
    pair = align_pair(rows, cand, reference, required)
    reference_label = ("champion vigente " if reference != BASELINE_FAMILY and new_candidate else "baseline ") + f"({_name(reference)})"
    gates = _gates(rows, pair, ctx, reference_label, new_candidate)
    failed, hard, soft, waiting_approval = _split(gates)
    label = _name(cand)
    metrics = {**scope_metrics, "candidate": {"family": cand, **_stat_dict(pair.cand)}, "reference": {"family": reference, **_stat_dict(pair.ref)},
               "aligned": pair.evidence(), "failed_gates": failed}
    detail = "; ".join(f"{n}: {gates[n]['message']}" for n in failed)
    cand_wape, ref_wape = pair.cand["wape"], pair.ref["wape"]
    if not new_candidate:
        # El champion sigue siendo el mejor: se confirma frente al baseline con los mismos gates.
        if _demotes(gates):
            decision, served = "publish_baseline", BASELINE_FAMILY
            reason = f"El champion {label} ya no cumple los gates frente al baseline ({detail}): se publica el baseline."
        elif hard:
            decision, served = "keep_champion", cand
            reason = f"{label} sigue siendo el mejor modelo, pero no se puede certificar con los gates ({detail}); se conserva el champion sin cambios."
        else:
            decision, served = "keep_champion", cand
            reason = f"El champion {label} sigue siendo el mejor modelo y cumple todos los gates frente al baseline: se conserva."
        return ScopeDecision(**common, candidate_family=cand, champion_family=champion_used, served_family=served, decision=decision,
                             reason=reason, gates=gates, metrics=metrics)
    if not hard and not soft and not waiting_approval:
        reason = (f"{label} cumple todos los gates y reemplaza a {_name(reference)} (WAPE {cand_wape:.3f} frente a {ref_wape:.3f} en las mismas "
                  "observaciones); aprobación humana pendiente (W4 · S06).")
        return ScopeDecision(**common, candidate_family=cand, champion_family=champion_used, served_family=cand, decision="promote",
                             reason=reason, gates=gates, metrics=metrics)
    # El candidato NO se promueve: sigue sirviéndose el champion... salvo que el propio champion no supere al baseline (se mide igual, alineado).
    served, demoted_note = champion_used, ""
    if champion_real:
        check_pair = align_pair(rows, champion_used, BASELINE_FAMILY, required)
        check = _gates(rows, check_pair, ctx, f"baseline ({_name(BASELINE_FAMILY)})", False)
        demoted = _demotes(check)
        metrics["champion_check"] = {"demoted": demoted, "failed_gates": failures(check), "wape": check_pair.cand["wape"],
                                     "baseline_wape": check_pair.ref["wape"], "aligned": check_pair.evidence(),
                                     "gates": {n: g["status"] for n, g in check.items()}}
        if demoted:
            served = BASELINE_FAMILY
            check_detail = "; ".join(f"{n}: {check[n]['message']}" for n in failures(check) if n not in HARD_GATES and n != "human_approval")
            demoted_note = (f" Además el champion vigente {_name(champion_used)} no cumple los gates frente al baseline ({check_detail}): "
                            "se publica el baseline.")
    keep = f"Sigue sirviéndose {_name(served)}." if not demoted_note else ""
    if hard:
        decision = "publish_baseline" if demoted_note else "reject"
        reason = f"{label} se rechaza: {detail}. {keep}{demoted_note}".strip()
    elif soft:
        decision = "publish_baseline" if demoted_note else "shadow"
        reason = f"{label} no se promueve ({detail}); queda como challenger en shadow. {keep}{demoted_note}".strip()
    else:
        decision = "publish_baseline" if demoted_note else "shadow"
        reason = (f"{label} cumple todos los gates automáticos, pero la aprobación humana está pendiente (W4 · S06) y bloquea lo servido: "
                  f"queda en shadow. {keep}{demoted_note}").strip()
    return ScopeDecision(**common, candidate_family=cand, champion_family=champion_used, served_family=served, decision=decision,
                         reason=reason, gates=gates, metrics=metrics)


# ─────────────────────────────────── decisiones de la política heredada ───────────────────────────────────

def _legacy_gates(evidence: dict[str, Any]) -> dict[str, Any]:
    lg = evidence.get("lightgbm_wape")
    if lg is None:
        return {"legacy_rule": _gate(NOT_APPLICABLE, "LightGBM no se evaluó en el alcance: gana el mejor estadístico (menor WAPE).",
                                     blocking=False, **{k: v for k, v in evidence.items() if k != "scope"})}
    metric = "WAPE del total diario" if evidence.get("metric") == "total_day" else "WAPE"
    gates = {
        "min_improvement": _gate(PASS if evidence.get("passes_improvement") else FAIL,
                                 f"Mejora relativa de LightGBM frente al mejor estadístico: {evidence.get('relative_improvement') or 0:.1%} "
                                 f"(mínimo {evidence['required_relative_improvement']:.0%}).", blocking=False,
                                 relative_improvement=evidence.get("relative_improvement"),
                                 required=evidence["required_relative_improvement"], best_statistical=evidence["best_statistical"],
                                 best_statistical_wape=evidence["best_statistical_wape"], lightgbm_wape=lg),
        "windows_won": _gate(PASS if evidence.get("passes_windows") else FAIL,
                             f"LightGBM gana en {evidence['windows_better']}/{evidence['windows_total']} ventanas "
                             f"(necesita {evidence['windows_needed']}).", blocking=False,
                             windows_better=evidence["windows_better"], windows_total=evidence["windows_total"],
                             windows_needed=evidence["windows_needed"], fraction=evidence["windows_won_fraction"]),
    }
    if evidence.get("passes_guards") is not None:      # regla v2 (total diario): salvaguardas de sesgo y de error por serie
        gates["guards"] = _gate(PASS if evidence["passes_guards"] else FAIL,
                                f"Salvaguardas de LightGBM con la métrica {metric}: error serie-día {evidence.get('lightgbm_series_wape'):.3f} "
                                f"(límite {evidence.get('series_wape_limit'):.3f}) y sesgo {evidence.get('lightgbm_bias'):+.1%} "
                                f"(máximo ±{evidence.get('bias_tolerance'):.0%}).", blocking=False,
                                passes_series_guard=evidence.get("passes_series_guard"), passes_bias_guard=evidence.get("passes_bias_guard"),
                                series_wape=evidence.get("lightgbm_series_wape"), series_wape_limit=evidence.get("series_wape_limit"),
                                bias=evidence.get("lightgbm_bias"), bias_tolerance=evidence.get("bias_tolerance"))
    return gates


def _legacy_decision(scope_key: str, winner: str, reason: str, evidence: dict[str, Any], table: pd.DataFrame | None,
                     champion: str | None, policy: LegacyPolicy, informational: bool, used_pooled: bool = False) -> ScopeDecision:
    champion_family = champion if champion is not None else BASELINE_FAMILY
    if winner == BASELINE_FAMILY:
        decision = "publish_baseline"
    elif champion is not None and winner == champion:
        decision = "keep_champion"
    else:
        decision = "promote"
    wapes = {}
    if table is not None and len(table):
        wapes = {str(r["family"]): _num(r["wape"]) for r in table.to_dict("records")}
    gates = _legacy_gates(evidence) if not used_pooled else {
        "used_pooled": _gate(NOT_APPLICABLE, "Pocas series o ventas en la clase: rige la decisión agregada.", blocking=False,
                             **{k: v for k, v in evidence.items() if k != "pooled"})}
    return ScopeDecision(
        policy=POLICY_LEGACY, policy_version=policy.version, scope_key=scope_key, candidate_family=winner, champion_family=champion_family,
        served_family=winner, decision=decision, reason=reason, gates=gates, informational=informational,
        metrics={"wape_by_family": wapes, "champion_source": "previous_run" if champion is not None else "baseline",
                 "baseline_family": BASELINE_FAMILY, "windows_used": "all (selección + período final: regla heredada de producción)",
                 "evidence": evidence})


# ─────────────────────────────────── orquestación ───────────────────────────────────

def champions_from_selection(selection: dict[str, Any] | None) -> dict[str, str]:
    """Familia servida por alcance en la ejecución vigente anterior (`forecast_runs.selection`): {pooled|clase: familia}."""
    out: dict[str, str] = {}
    for key, value in (selection or {}).items():
        if key in ("insufficient", "fallback") or not isinstance(value, dict):
            continue
        if value.get("model_family") in FAMILIES:
            out[str(key)] = str(value["model_family"])
    return out


def signing_available() -> bool:
    """¿Hay clave privada de firma configurada? (un artefacto nuevo quedaría firmado). Nunca expone el material de la clave."""
    try:
        from predictily_engine.platform import signing

        return signing.load_config().private_key is not None
    except Exception:   # configuración de claves inválida: no se puede firmar
        log.warning("No se pudo leer la configuración de firma; los artefactos nuevos se consideran no verificables.")
        return False


def _prepare(bt: pd.DataFrame, profile: pd.DataFrame, only_selection: bool) -> pd.DataFrame:
    rows = bt
    if only_selection and "is_holdout" in bt:
        rows = bt[~bt["is_holdout"].astype(bool)]
    rows = rows.assign(demand_class=row_classes(rows, profile))
    return with_keys(rows[~rows["stockout"].astype(bool)])


def _v25_decisions(rows: pd.DataFrame, all_families: list[str], champions: dict[str, str], ctx: GateContext,
                   horizons: list[int], classes: tuple[str, ...] = CLASSES) -> list[ScopeDecision]:
    """`classes`: las clases que existen en el backtest COMPLETO (la heredada usa todas las ventanas); una clase que solo aparece en el período final
    no tiene filas en las ventanas de selección y recibe igualmente su fila V2.5 (sin evidencia: rige la decisión agregada), de modo que cada alcance
    efectivo tenga exactamente una fila efectiva."""
    out: list[ScopeDecision] = []
    pooled = decide_v25_scope("global", rows, all_families, champions.get(POOLED), ctx)
    out.append(pooled)
    for cls in classes:
        sub = rows[rows["demand_class"] == cls]
        key = f"class:{cls}"
        if sub["unique_id"].nunique() < MIN_CLASS_SERIES or sub["actual"].sum() < MIN_CLASS_ACTUAL:
            # R-071: la decisión agregada solo rige si su familia es candidata de la clase; si no, se publica el baseline (candidato de toda clase)
            fits = pooled.served_family in CANDIDATES_BY_CLASS[cls]
            served = pooled.served_family if fits else BASELINE_FAMILY
            decision = pooled.decision if fits else "publish_baseline"
            note = "" if fits else (f" La familia agregada ({_name(pooled.served_family)}) no es candidata de la clase {cls}: se publica el baseline.")
            if sub.empty:
                note = " La clase no tiene filas en las ventanas de selección (solo aparece en el período final reservado, que no decide)." + note
            out.append(ScopeDecision(
                policy=POLICY_V25, policy_version=ctx.policy.version, scope_key=key, candidate_family=pooled.candidate_family if fits else BASELINE_FAMILY,
                champion_family=pooled.champion_family, served_family=served, decision=decision,
                reason="Pocas series en la clase: se usa la decisión agregada. " + pooled.reason + note,
                gates={"used_pooled": _gate(NOT_APPLICABLE, "Pocas series o ventas en la clase: rige la decisión agregada.", blocking=False,
                                            series=int(sub["unique_id"].nunique()), actual_total=float(sub["actual"].sum()),
                                            min_series=MIN_CLASS_SERIES, min_actual=MIN_CLASS_ACTUAL, pooled_family=pooled.served_family,
                                            pooled_family_is_class_candidate=fits)},
                metrics={**{k: v for k, v in pooled.metrics.items() if k in ("champion_source", "baseline_family", "windows_used")},
                         "used_pooled": True, "pooled_scope": "global"}))
            continue
        cands = [f for f in CANDIDATES_BY_CLASS[cls] if f in all_families]
        out.append(decide_v25_scope(key, sub, cands, champions.get(cls, champions.get(POOLED)), ctx))
    for h in horizons:
        sub = rows[rows["step"] <= h]
        if len(sub):
            out.append(decide_v25_scope(f"horizon:{h}", sub, all_families, champions.get(POOLED), ctx, informational=True))
    return out


def _legacy_decisions(sel: Selection, bt: pd.DataFrame, profile: pd.DataFrame, champions: dict[str, str], policy: LegacyPolicy,
                      horizons: list[int]) -> list[ScopeDecision]:
    out: list[ScopeDecision] = []
    pooled_table = sel.pooled_table
    out.append(_legacy_decision("global", sel.winners[POOLED], sel.reasons[POOLED], sel.evidence.get(POOLED, {}), pooled_table,
                                champions.get(POOLED), policy, informational=False))
    for cls in CLASSES:
        if cls not in sel.winners:
            continue
        table = sel.class_table[sel.class_table["demand_class"] == cls] if len(sel.class_table) and "demand_class" in sel.class_table else None
        out.append(_legacy_decision(f"class:{cls}", sel.winners[cls], sel.reasons[cls], sel.evidence.get(cls, {}),
                                    table if table is not None and len(table) else pooled_table, champions.get(cls, champions.get(POOLED)),
                                    policy, informational=False, used_pooled=cls in sel.used_pooled))
    rows = bt.assign(demand_class=row_classes(bt, profile))
    rows = rows[~rows["stockout"].astype(bool)]
    families = sorted(rows["family"].unique())
    for h in horizons:
        sub = rows[rows["step"] <= h]
        if sub.empty:
            continue
        winner, reason, table, evidence = _decide_full(sub, families, policy, f"horizon:{h}")
        out.append(_legacy_decision(f"horizon:{h}", winner, reason, evidence, table, champions.get(POOLED), policy, informational=True))
    return out


class PromotionInvariantError(RuntimeError):
    """Se rompió un invariante del registro de decisiones (p. ej. un alcance sin exactamente una fila efectiva)."""


def assert_one_effective_per_scope(decisions: list[ScopeDecision], governing: str) -> None:
    """Invariante del registro: en cada alcance NO informativo existe exactamente una fila por política y exactamente una es efectiva (la de la política
    que gobierna); los alcances informativos no tienen filas efectivas. Se verifica sobre la lista final, no se da por construcción."""
    by_scope: dict[str, list[ScopeDecision]] = {}
    for d in decisions:
        by_scope.setdefault(d.scope_key, []).append(d)
    problems: list[str] = []
    for scope, rows in by_scope.items():
        policies = sorted(d.policy for d in rows)
        effective = [d.policy for d in rows if d.is_effective]
        if rows[0].informational:
            if effective:
                problems.append(f"{scope}: alcance informativo con filas efectivas {effective}")
            continue
        if policies != [POLICY_LEGACY, POLICY_V25]:
            problems.append(f"{scope}: debe tener una fila por política y tiene {policies}")
        if effective != [governing]:
            problems.append(f"{scope}: debe tener exactamente una fila efectiva ({governing}) y tiene {effective}")
    if problems:
        raise PromotionInvariantError("; ".join(problems))


def build_v25_selection(decisions: list[ScopeDecision], base: Selection, policy: V25Policy) -> Selection:
    """`Selection` servida por la política V2.5: la familia servida por alcance; tablas, respaldo y clases agregadas de `base` (la selección
    heredada calculada con las mismas ventanas)."""
    mine = {d.scope_key: d for d in decisions if d.policy == POLICY_V25}
    winners, reasons, evidence = {}, {}, {}
    for key, d in mine.items():
        if d.informational:
            continue
        name = POOLED if key == "global" else key.split(":", 1)[1]
        winners[name], reasons[name], evidence[name] = d.served_family, d.reason, {"gates": {n: g["status"] for n, g in d.gates.items()}}
    return Selection(winners, base.fallback, reasons, base.class_table, base.pooled_table, list(base.used_pooled), evidence, policy.version)


def select_with_policies(bt: pd.DataFrame, profile: pd.DataFrame, cfg: PromotionConfig, *, champions: dict[str, str] | None = None,
                         quality: Any = None, plan: Any = None, cutoff: date | None = None, data_from: date | None = None, seed: int = 42,
                         segment_provider: SegmentProvider | None = None, segment_source: str | None = None, can_sign: bool | None = None,
                         tuning_experiment: dict[str, Any] | None = None, lgbm_params: dict[str, Any] | None = None,
                         limitations: list[dict[str, str]] | None = None, champions_source: str | None = None) -> PromotionOutcome:
    """Calcula la política heredada y la V2.5 sobre el mismo backtest y devuelve la selección que gobierna lo servido.

    Con `governing_policy = legacy` (por defecto) `selection` y `eval_selection` son EXACTAMENTE las del selector anterior (los dos únicos
    llamados a `select_models` que hacía el pipeline); la V2.5 solo se registra. Con `v25`, ambas salen de las decisiones V2.5, que usan
    solo las ventanas de selección (la evaluación del período final y la producción coinciden: nada la contamina)."""
    champions = dict(champions or {})
    in_holdout = bt["is_holdout"].astype(bool) if "is_holdout" in bt else pd.Series(False, index=bt.index)
    has_split = bool(in_holdout.any() and (~in_holdout).any())
    legacy_prod = select_models(bt, profile, policy=cfg.legacy)
    legacy_eval = select_models(bt[~in_holdout], profile, policy=cfg.legacy) if has_split else None

    sel_windows = sorted(int(w) for w in (bt.loc[~in_holdout, "window"] if has_split else bt["window"]).unique())
    hold_windows = sorted(int(w) for w in bt.loc[in_holdout, "window"].unique()) if has_split else []
    # Comprobación del protocolo con el MISMO manifiesto que la ejecución publicará (solo lee el backtest).
    problems: list[str] = []
    if plan is not None and cutoff is not None and data_from is not None:
        probe = build_manifest(cutoff=cutoff, data_from=data_from, plan=plan, bt=bt, min_improvement=cfg.legacy.min_improvement,
                               config_version=None, coverage=None, horizon_support={}, has_eval_selection=has_split, p50=None, seed=seed)
        problems = verify(probe)
    if tuning_experiment and tuning_experiment.get("holdout_used"):
        problems = [*problems, "el ajuste de hiperparámetros (Optuna) usó el período final reservado"]
    quality_outcome = getattr(quality, "outcome", None) if quality is not None else None
    ctx = GateContext(
        policy=cfg.v25, independent_holdout=has_split, manifest_problems=problems, quality_outcome=quality_outcome,
        quality_reasons=list(quality.reason_codes()) if quality is not None else [],
        signing_available=signing_available() if can_sign is None else can_sign,
        segment_provider=segment_provider or default_segments,
        segment_source=(segment_source or "proveedor externo") if segment_provider is not None else DEFAULT_SEGMENT_SOURCE,
        tuning_check=None if not tuning_experiment else {
            "enabled": True, "holdout_used": bool(tuning_experiment.get("holdout_used")),
            "origins": tuning_experiment.get("origins_used"), "holdout_origin": tuning_experiment.get("holdout_origin")},
        windows_used=sel_windows, fallback_family=legacy_prod.fallback)

    horizon_cap = int(getattr(plan, "horizon", 0) or 0)
    horizons = [h for h in HORIZONS["demand"] if h <= horizon_cap] if horizon_cap else []
    families = sorted(bt["family"].unique())
    rows_v25 = _prepare(bt, profile, only_selection=True)
    holdout_rows = int(rows_v25["is_holdout"].astype(bool).sum()) if "is_holdout" in rows_v25 and has_split else 0
    present = set(row_classes(bt, profile)[~bt["stockout"].astype(bool)].dropna().unique())          # clases del backtest COMPLETO (las de la heredada)
    v25_decisions = _v25_decisions(rows_v25, families, champions, ctx, horizons, tuple(c for c in CLASSES if c in present))
    legacy_decisions = _legacy_decisions(legacy_prod, bt, profile, champions, cfg.legacy, horizons)
    v25_selection = build_v25_selection(v25_decisions, legacy_eval or legacy_prod, cfg.v25)

    governing = cfg.governing_policy
    for d in legacy_decisions + v25_decisions:
        d.is_effective = (d.policy == governing) and not d.informational
    assert_one_effective_per_scope(legacy_decisions + v25_decisions, governing)
    if governing == POLICY_V25:
        selection, eval_selection = v25_selection, (v25_selection if has_split else None)
    else:
        selection, eval_selection = legacy_prod, legacy_eval
    outcome = PromotionOutcome(
        config=cfg, governing=governing, legacy=legacy_prod, legacy_eval=legacy_eval, v25=v25_selection, selection=selection,
        eval_selection=eval_selection, decisions=legacy_decisions + v25_decisions, backtest=bt, lgbm_params=dict(lgbm_params or {}),
        tuning=tuning_experiment, manifest_problems=problems, champions=champions, limitations=list(limitations or []), v25_holdout_rows=holdout_rows,
        champions_source=champions_source or ("previous_production_run" if champions else "none"),
        windows={"legacy_production": "todas las ventanas (selección + período final)",
                 "legacy_evaluation": "ventanas de selección" if has_split else None,
                 "v25": "ventanas de selección" if has_split else "todas (sin período final independiente: los gates no pueden certificar)",
                 "selection_windows": sel_windows, "holdout_windows": hold_windows, "independent_holdout": has_split})
    return outcome


def run_selection_stage(*, panel: Any, features: Any, plan: Any, windows: Any, bt: pd.DataFrame, profile: pd.DataFrame, cfg: PromotionConfig,
                        seed: int, n_jobs: int, use_lgbm: bool, champions: dict[str, str] | None = None, quality: Any = None,
                        cutoff: date | None = None, data_from: date | None = None,
                        segment_provider: SegmentProvider | None = None, segment_source: str | None = None,
                        champions_source: str | None = None) -> PromotionOutcome:
    """Etapa de selección de la ejecución: ajuste opcional de LightGBM con Optuna (solo ventanas de selección) y selección con ambas políticas.

    Es el único punto que `pipeline/core.py` llama. `outcome.backtest` es el backtest a usar en adelante (con las filas de LightGBM rehechas si el
    ajuste mejoró) y `outcome.lgbm_params` los hiperparámetros del entrenamiento final."""
    limitations: list[dict[str, str]] = []
    experiment: dict[str, Any] | None = None
    params: dict[str, Any] = {}
    if cfg.tuning.enabled:
        if plan is None or not use_lgbm or bt.empty:
            experiment = {"enabled": True, "available": None, "applied": False, "holdout_used": False,
                          "message": "LightGBM no participa en esta ejecución (historia insuficiente o demasiadas series): no hay nada que ajustar."}
        else:
            from predictily_engine.selection.tuning import tune_lightgbm_stage

            result = tune_lightgbm_stage(panel, features, plan, windows, cfg.tuning, seed, n_jobs)
            experiment, limitations = result.experiment, list(result.limitations)
            if result.params and result.backtest_rows is not None and not result.backtest_rows.empty:
                bt = pd.concat([bt[bt["family"] != "lightgbm_global"], result.backtest_rows], ignore_index=True)
                params = dict(result.params)
    return select_with_policies(bt, profile, cfg, champions=champions, quality=quality, plan=plan, cutoff=cutoff, data_from=data_from,
                                seed=seed, segment_provider=segment_provider, segment_source=segment_source, tuning_experiment=experiment,
                                lgbm_params=params, champions_source=champions_source,
                                limitations=limitations)
