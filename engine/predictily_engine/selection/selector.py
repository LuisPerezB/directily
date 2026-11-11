"""Selección automática de modelos por clase de demanda.

Reglas (docs/04-estrategia-modelado.md):
1. Se evalúan en backtesting todos los candidatos de la clase, incluido siempre el baseline
   estadístico (Seasonal Naive), que se reporta como referencia.
2. Entre los estadísticos gana el de menor WAPE.
3. Un modelo más complejo (LightGBM global) solo reemplaza al mejor estadístico si mejora su
   WAPE al menos `min_improvement` (relativo) Y es mejor en ≥ 2/3 de las ventanas (estable).
4. Clases con poca evidencia (< 3 series o Σ ventas < 30) usan la decisión agregada.
5. Se registra un modelo de respaldo simple (el mejor entre Seasonal Naive y Promedio móvil).
6. Series con historia insuficiente usan el promedio de su historia disponible (sin evaluar).

Cada fila del backtest se agrupa por la clase que su serie tenía en el corte de SU ventana
(columna `demand_class` de `run_backtest`), no por la clase al corte final: así la selección
de una ventana no depende de ventas posteriores (F4). Si la columna no existe (backtests
externos o pruebas) se usa la clase del `profile` entregado.

`select_models` no sabe qué ventanas recibe: el pipeline lo llama con TODAS las ventanas
para el modelo de producción y SOLO con las ventanas de selección para la evaluación en el
período final reservado (F6).

W3-B (ADR-V25-21): esta es la política `legacy`, ahora PARAMETRIZADA y verificable (`LegacyPolicy`): mejora mínima de WAPE
(`min_improvement`, hoy 3 %) y fracción de ventanas ganadas (`windows_won_fraction`, hoy 2/3 ⇒ ⌈2n/3⌉ ventanas; en producción
3 de 4). Con los valores por defecto su resultado es IDÉNTICO al selector anterior (prueba de paridad exacta contra una copia
congelada: tests/unit/legacy_selector_reference.py). Cada decisión conserva la evidencia numérica de la regla (`Selection.evidence`)
para registrarla en `model_promotion_decisions`. La política de promoción V2.5 vive en `selection/promotion.py`.
"""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass, field

import numpy as np
import pandas as pd

from predictily_engine.backtest.metrics import summarize
from predictily_engine.models.registry import (
    BASELINE_FAMILY,
    CANDIDATES_BY_CLASS,
    FAMILIES,
    SIMPLE_FALLBACKS,
    STATISTICAL,
)

POOLED = "pooled"

LEGACY_RULE_VERSION = "legacy-selection-v1"
# Regla v2 (`selection_metric = "total_day"`, apagada por defecto): misma estructura que la v1, pero la métrica que ORDENA a los candidatos es el
# WAPE del total diario del alcance (se suman las series por ventana y día y se mide el error del total), con dos salvaguardas: sesgo acotado
# y error por serie-día no peor que el mejor candidato más una tolerancia. Motivo: el WAPE serie-día está cerca del piso de ruido de los
# conteos, así que las diferencias entre modelos buenos son de 1–2 % (por debajo del margen exigido) aunque en el total difieran 15–30 %,
# y además premia pronosticar de menos en series intermitentes. Ver docs/roadmap/cambios-2026-11/06.
LEGACY_RULE_VERSION_V2 = "legacy-selection-v2"
METRIC_SERIES_DAY = "series_day"
METRIC_TOTAL_DAY = "total_day"
SELECTION_METRICS = (METRIC_SERIES_DAY, METRIC_TOTAL_DAY)
DEFAULT_BIAS_TOLERANCE = 0.15
DEFAULT_SERIES_TOLERANCE = 0.05
BIAS_TOLERANCE_RANGE = (0.0, 5.0)
SERIES_TOLERANCE_RANGE = (0.0, 1.0)
DEFAULT_MIN_IMPROVEMENT = 0.03
DEFAULT_WINDOWS_WON_FRACTION = 2 / 3
# Rangos validados de los parámetros de la política heredada (también en `platform.config.CONFIG_CONSTRAINTS`). La fracción de
# ventanas es una MAYORÍA como mínimo: «ganar en la mitad» o menos no es estable. 0 % de mejora mínima = basta ser mejor.
MIN_IMPROVEMENT_RANGE = (0.0, 0.5)
WINDOWS_WON_FRACTION_RANGE = (0.5, 1.0)
# Constantes de la regla (no configurables; forman parte de la versión): una clase con menos series o ventas usa la decisión agregada.
MIN_CLASS_SERIES = 3
MIN_CLASS_ACTUAL = 30


@dataclass(frozen=True)
class LegacyPolicy:
    """Parámetros de la política heredada de selección (`legacy`), con rango validado y huella (`version`).

    `min_improvement`: LightGBM solo reemplaza al mejor estadístico si su WAPE es ≤ WAPE_estadístico × (1 − min_improvement).
    `windows_won_fraction`: y si gana en al menos ⌈n_ventanas × fracción⌉ ventanas (2/3 por defecto: 3 de 4 en producción).
    `selection_metric`: `series_day` (v1, por defecto: WAPE serie-día) o `total_day` (v2: WAPE del total diario del alcance). Solo con
    `total_day` rigen `bias_tolerance` (|sesgo| máximo de un candidato) y `series_tolerance` (cuánto puede superar su WAPE serie-día al
    del mejor candidato del alcance). Con `series_day` esos dos parámetros no intervienen ni forman parte de la huella: la política es
    exactamente la `legacy-selection-v1`."""

    min_improvement: float = DEFAULT_MIN_IMPROVEMENT
    windows_won_fraction: float = DEFAULT_WINDOWS_WON_FRACTION
    selection_metric: str = METRIC_SERIES_DAY
    bias_tolerance: float = DEFAULT_BIAS_TOLERANCE
    series_tolerance: float = DEFAULT_SERIES_TOLERANCE

    def __post_init__(self) -> None:
        lo, hi = MIN_IMPROVEMENT_RANGE
        if isinstance(self.min_improvement, bool) or not lo <= self.min_improvement <= hi:
            raise ValueError(f"min_improvement debe estar entre {lo} y {hi} (recibido {self.min_improvement!r}).")
        lo, hi = WINDOWS_WON_FRACTION_RANGE
        if isinstance(self.windows_won_fraction, bool) or not lo <= self.windows_won_fraction <= hi:
            raise ValueError(f"windows_won_fraction debe estar entre {lo} y {hi} (recibido {self.windows_won_fraction!r}).")
        if self.selection_metric not in SELECTION_METRICS:
            raise ValueError(f"selection_metric debe ser uno de {SELECTION_METRICS} (recibido {self.selection_metric!r}).")
        for name, (lo, hi) in (("bias_tolerance", BIAS_TOLERANCE_RANGE), ("series_tolerance", SERIES_TOLERANCE_RANGE)):
            value = getattr(self, name)
            if isinstance(value, bool) or not lo <= value <= hi:
                raise ValueError(f"{name} debe estar entre {lo} y {hi} (recibido {value!r}).")

    @property
    def by_total(self) -> bool:
        """¿Rige la regla v2 (candidatos ordenados por el WAPE del total diario, con salvaguardas de sesgo y de error por serie)?"""
        return self.selection_metric == METRIC_TOTAL_DAY

    @property
    def rule_version(self) -> str:
        return LEGACY_RULE_VERSION_V2 if self.by_total else LEGACY_RULE_VERSION

    def needed_windows(self, n_windows: int) -> int:
        """Ventanas que debe ganar LightGBM: ⌈n × fracción⌉. El redondeo a 9 decimales elimina el ruido de coma flotante
        (n × 2/3 con n múltiplo de 3 no debe subir una ventana): con la fracción por defecto coincide con ⌈2n/3⌉ para todo n."""
        return math.ceil(round(n_windows * self.windows_won_fraction, 9))

    def params(self) -> dict:
        out = {"min_improvement": self.min_improvement, "windows_won_fraction": self.windows_won_fraction,
               "min_class_series": MIN_CLASS_SERIES, "min_class_actual": MIN_CLASS_ACTUAL, "rule_version": self.rule_version}
        if self.by_total:   # la v1 conserva EXACTAMENTE sus parámetros (y por tanto su huella)
            out.update({"selection_metric": self.selection_metric, "bias_tolerance": self.bias_tolerance,
                        "series_tolerance": self.series_tolerance})
        return out

    @property
    def version(self) -> str:
        """SHA-256 de los parámetros de la política (columna `model_promotion_decisions.policy_version`)."""
        return hashlib.sha256(json.dumps({"policy": "legacy", **self.params()}, sort_keys=True).encode()).hexdigest()

    def describe(self) -> dict:
        out = {"min_improvement": self.min_improvement, "windows_won_fraction": self.windows_won_fraction}
        if self.by_total:
            out.update({"selection_metric": self.selection_metric, "bias_tolerance": self.bias_tolerance,
                        "series_tolerance": self.series_tolerance})
        return {**out, "rule_version": self.rule_version, "policy_version": self.version}


@dataclass
class Selection:
    winners: dict[str, str]  # demand_class -> family
    fallback: str
    reasons: dict[str, str]
    class_table: pd.DataFrame  # demand_class, family, wape, ... , windows_better
    pooled_table: pd.DataFrame
    used_pooled: list[str] = field(default_factory=list)
    # W3-B: evidencia numérica de la regla por alcance («pooled» o clase) y huella de la política que decidió. No forma parte de
    # `as_json` (el contrato persistido de `forecast_runs.selection` no cambia).
    evidence: dict[str, dict] = field(default_factory=dict)
    policy_version: str | None = None

    def family_for(self, demand_class: str) -> str:
        if demand_class == "insufficient":
            return "window_average"
        return self.winners.get(demand_class, self.winners.get(POOLED, self.fallback))

    def served(self, failed: set[str]) -> "Selection":
        """Selección tal como se SIRVE cuando fallan familias en el entrenamiento final (E2-19).

        Cada clase cuyo ganador falló pasa a su respaldo: las métricas, la calibración de los intervalos y las
        etiquetas del resultado deben corresponder al modelo realmente usado, no al ganador del backtest.
        Sin fallos devuelve la misma selección."""
        if not failed:
            return self
        winners = {c: (self.fallback if f in failed else f) for c, f in self.winners.items()}
        reasons = dict(self.reasons)
        for c, f in self.winners.items():
            if f in failed:
                reasons[c] = (f"Falló el entrenamiento final de {FAMILIES[f].label}: se sirve el respaldo "
                              f"({FAMILIES[self.fallback].label}). " + self.reasons.get(c, ""))
        return Selection(winners, self.fallback, reasons, self.class_table, self.pooled_table, self.used_pooled,
                         self.evidence, self.policy_version)

    def as_json(self, registry_ids: dict[str, int] | None = None, planned: "Selection | None" = None) -> dict:
        """Selección servida por clase. Con `planned` (la elegida por el backtest), las clases donde se sirve el
        respaldo en lugar del ganador lo indican (`planned_family`, `fallback_applied`)."""
        ids = registry_ids or {}
        out = {}
        for cls, fam in self.winners.items():
            row = {"model_family": fam, "model_registry_id": ids.get(fam), "reason": self.reasons.get(cls)}
            if planned is not None and planned.winners.get(cls) not in (None, fam):
                row.update({"planned_family": planned.winners[cls], "fallback_applied": True})
            out[cls] = row
        out["insufficient"] = {"model_family": "window_average", "model_registry_id": ids.get("window_average"),
                               "reason": "Historia menor a 28 días: promedio de la historia disponible (sin evaluación)."}
        out["fallback"] = {"model_family": self.fallback, "model_registry_id": ids.get(self.fallback)}
        return out


def _decide_full(sub: pd.DataFrame, candidates: list[str], policy: LegacyPolicy, label: str
                 ) -> tuple[str, str, pd.DataFrame, dict]:
    """Decisión de un alcance con su evidencia numérica (lo que `model_promotion_decisions.gates` registra)."""
    if policy.by_total:
        return _decide_by_total(sub, candidates, policy, label)
    min_improvement = policy.min_improvement
    sub = sub[sub["family"].isin(candidates)]
    table = summarize(sub, ["family"])
    per_window = summarize(sub, ["family", "window"])
    stats = table[table["family"].isin(STATISTICAL) & table["wape"].notna()].sort_values("wape")
    evidence: dict = {"scope": label, "required_relative_improvement": min_improvement,
                      "windows_won_fraction": policy.windows_won_fraction}
    if stats.empty:
        evidence.update({"best_statistical": None, "outcome": "baseline_no_evidence"})
        return BASELINE_FAMILY, "Sin evidencia suficiente: se usa el baseline.", table, evidence
    best_stat = str(stats.iloc[0]["family"])
    best_wape = float(stats.iloc[0]["wape"])
    winner, reason = best_stat, f"{FAMILIES[best_stat].label}: menor WAPE entre los estadísticos ({best_wape:.3f})."
    evidence.update({"best_statistical": best_stat, "best_statistical_wape": best_wape, "lightgbm_wape": None,
                     "windows_total": 0, "windows_better": 0, "windows_needed": None, "relative_improvement": None,
                     "passes_improvement": None, "passes_windows": None, "outcome": "best_statistical"})
    lg = table[table["family"] == "lightgbm_global"]
    table["windows_better"] = 0
    if not lg.empty and pd.notna(lg.iloc[0]["wape"]):
        lg_wape = float(lg.iloc[0]["wape"])
        pw = per_window.pivot(index="window", columns="family", values="wape")
        n_windows = len(pw)
        better = int((pw["lightgbm_global"] < pw[best_stat]).sum()) if "lightgbm_global" in pw else 0
        table.loc[table["family"] == "lightgbm_global", "windows_better"] = better
        needed = policy.needed_windows(n_windows)
        passes_improvement = lg_wape <= best_wape * (1 - min_improvement)
        passes_windows = better >= needed
        evidence.update({"lightgbm_wape": lg_wape, "windows_total": n_windows, "windows_better": better, "windows_needed": needed,
                         "relative_improvement": (best_wape - lg_wape) / best_wape if best_wape > 0 else None,
                         "passes_improvement": bool(passes_improvement), "passes_windows": bool(passes_windows)})
        if passes_improvement and passes_windows:
            winner = "lightgbm_global"
            evidence["outcome"] = "lightgbm_global"
            reason = (f"LightGBM global mejora el WAPE de {best_wape:.3f} ({FAMILIES[best_stat].label}) a "
                      f"{lg_wape:.3f} y gana en {better}/{n_windows} ventanas.")
        else:
            reason += (f" LightGBM ({lg_wape:.3f}) no superó el margen mínimo de {min_improvement:.0%} "
                       f"de forma estable ({better}/{n_windows} ventanas).")
    return winner, reason, table, evidence


def total_day_wape(rows: pd.DataFrame, by: list[str]) -> pd.DataFrame:
    """WAPE del TOTAL diario por grupo `by` (familia, o familia y ventana): las series del alcance se suman por ventana y día y se mide
    Σ|total real − total pronosticado| / Σ total real. Es el error que ve quien mira el total del negocio (o de la clase), no el de cada serie.
    Devuelve `by` + `wape_total` (NaN si el total real es 0)."""
    keys = [*by, *(["window"] if "window" not in by else []), "ds"]
    tot = rows.groupby(keys, as_index=False, dropna=False)[["actual", "yhat"]].sum()
    tot["_ae"] = (tot["yhat"] - tot["actual"]).abs()
    out = tot.groupby(by, as_index=False, dropna=False).agg(_sum_actual=("actual", "sum"), _sum_ae=("_ae", "sum"))
    ok = out["_sum_actual"] > 0
    out["wape_total"] = np.where(ok, out["_sum_ae"] / out["_sum_actual"].where(ok, 1), np.nan)
    return out[[*by, "wape_total"]]


def _decide_by_total(sub: pd.DataFrame, candidates: list[str], policy: LegacyPolicy, label: str
                     ) -> tuple[str, str, pd.DataFrame, dict]:
    """Regla v2 (`legacy-selection-v2`): la misma estructura que la v1 con el WAPE del total diario como métrica y dos salvaguardas.

    1. Estadísticos elegibles: WAPE serie-día ≤ el mejor estadístico × (1 + `series_tolerance`) Y |sesgo| ≤ `bias_tolerance`. Entre ellos
       gana el de menor WAPE del total diario. Si ninguno cumple el sesgo se elige sin esa salvaguarda y se registra (`bias_guard_relaxed`).
    2. LightGBM global reemplaza al ganador si mejora su WAPE del total diario ≥ `min_improvement`, gana en ⌈n × fracción⌉ ventanas con
       esa misma métrica y cumple las dos salvaguardas (frente al mejor estadístico por serie)."""
    min_improvement = policy.min_improvement
    sub = sub[sub["family"].isin(candidates)]
    table = summarize(sub, ["family"]).merge(total_day_wape(sub, ["family"]), on="family", how="left")
    table["windows_better"] = 0
    evidence: dict = {"scope": label, "metric": METRIC_TOTAL_DAY, "required_relative_improvement": min_improvement,
                      "windows_won_fraction": policy.windows_won_fraction, "bias_tolerance": policy.bias_tolerance,
                      "series_tolerance": policy.series_tolerance}
    stats = table[table["family"].isin(STATISTICAL) & table["wape"].notna() & table["wape_total"].notna()]
    if stats.empty:
        evidence.update({"best_statistical": None, "outcome": "baseline_no_evidence"})
        return BASELINE_FAMILY, "Sin evidencia suficiente: se usa el baseline.", table, evidence
    series_limit = float(stats["wape"].min()) * (1 + policy.series_tolerance)

    def guards(row) -> tuple[bool, bool]:
        return bool(row["wape"] <= series_limit + 1e-12), bool(abs(row["bias"]) <= policy.bias_tolerance + 1e-12)

    flags = stats.apply(guards, axis=1, result_type="expand")
    in_series, in_bias = flags[0].astype(bool), flags[1].astype(bool)
    eligible = stats[in_series & in_bias]
    relaxed = eligible.empty
    if relaxed:      # todos sesgados: se elige igual (el de mejor serie-día siempre cumple su propia tolerancia) y se deja constancia
        eligible = stats[in_series]
    best = eligible.sort_values(["wape_total", "wape", "family"]).iloc[0]
    best_stat, best_total = str(best["family"]), float(best["wape_total"])
    winner = best_stat
    reason = (f"{FAMILIES[best_stat].label}: menor WAPE del total diario entre los estadísticos ({best_total:.3f}; "
              f"serie-día {float(best['wape']):.3f}, sesgo {float(best['bias']):+.1%}).")
    if relaxed:
        reason += f" Ningún estadístico cumple el sesgo máximo de {policy.bias_tolerance:.0%}: se eligió sin esa salvaguarda."
    excluded = sorted(set(stats["family"]) - set(stats.loc[in_series & in_bias, "family"]))
    evidence.update({"best_statistical": best_stat, "best_statistical_wape": best_total,
                     "best_statistical_series_wape": float(best["wape"]), "best_statistical_bias": float(best["bias"]),
                     "series_wape_limit": series_limit, "bias_guard_relaxed": bool(relaxed), "statistical_outside_guards": excluded,
                     "lightgbm_wape": None, "windows_total": 0, "windows_better": 0, "windows_needed": None,
                     "relative_improvement": None, "passes_improvement": None, "passes_windows": None, "passes_guards": None,
                     "outcome": "best_statistical"})
    lg = table[table["family"] == "lightgbm_global"]
    if not lg.empty and pd.notna(lg.iloc[0]["wape_total"]) and pd.notna(lg.iloc[0]["wape"]):
        lg_total = float(lg.iloc[0]["wape_total"])
        pw = total_day_wape(sub, ["family", "window"]).pivot(index="window", columns="family", values="wape_total")
        n_windows = len(pw)
        better = int((pw["lightgbm_global"] < pw[best_stat]).sum()) if "lightgbm_global" in pw else 0
        table.loc[table["family"] == "lightgbm_global", "windows_better"] = better
        needed = policy.needed_windows(n_windows)
        passes_improvement = lg_total <= best_total * (1 - min_improvement)
        passes_windows = better >= needed
        ok_series, ok_bias = guards(lg.iloc[0])
        evidence.update({"lightgbm_wape": lg_total, "lightgbm_series_wape": float(lg.iloc[0]["wape"]),
                         "lightgbm_bias": float(lg.iloc[0]["bias"]), "windows_total": n_windows, "windows_better": better,
                         "windows_needed": needed,
                         "relative_improvement": (best_total - lg_total) / best_total if best_total > 0 else None,
                         "passes_improvement": bool(passes_improvement), "passes_windows": bool(passes_windows),
                         "passes_guards": bool(ok_series and ok_bias), "passes_series_guard": ok_series, "passes_bias_guard": ok_bias})
        if passes_improvement and passes_windows and ok_series and ok_bias:
            winner = "lightgbm_global"
            evidence["outcome"] = "lightgbm_global"
            reason = (f"LightGBM global mejora el WAPE del total diario de {best_total:.3f} ({FAMILIES[best_stat].label}) a "
                      f"{lg_total:.3f} y gana en {better}/{n_windows} ventanas.")
        elif passes_improvement and passes_windows:
            failed = " y ".join(n for n, ok in (("el error por serie", ok_series), ("el sesgo", ok_bias)) if not ok)
            reason += (f" LightGBM ({lg_total:.3f}) mejora el total, pero no cumple la salvaguarda de {failed} "
                       f"(serie-día {float(lg.iloc[0]['wape']):.3f}, sesgo {float(lg.iloc[0]['bias']):+.1%}).")
        else:
            reason += (f" LightGBM ({lg_total:.3f}) no superó el margen mínimo de {min_improvement:.0%} "
                       f"de forma estable ({better}/{n_windows} ventanas).")
    return winner, reason, table, evidence


def _decide(sub: pd.DataFrame, candidates: list[str], min_improvement: float, label: str) -> tuple[str, str, pd.DataFrame]:
    """Compatibilidad: decisión sin evidencia con la fracción de ventanas por defecto."""
    winner, reason, table, _ = _decide_full(sub, candidates, LegacyPolicy(min_improvement=min_improvement), label)
    return winner, reason, table


def row_classes(backtest: pd.DataFrame, profile: pd.DataFrame | None = None) -> pd.Series:
    """Clase de demanda de cada fila: la de su ventana si existe; si no, la del perfil."""
    if "demand_class" in backtest and backtest["demand_class"].notna().any():
        return backtest["demand_class"]
    if profile is None or profile.empty:
        return pd.Series(None, index=backtest.index, dtype=object)
    return backtest["unique_id"].map(profile.set_index("unique_id")["demand_class"])


def select_models(backtest: pd.DataFrame, profile: pd.DataFrame, min_improvement: float = DEFAULT_MIN_IMPROVEMENT,
                  policy: LegacyPolicy | None = None) -> Selection:
    """Selección heredada por clase de demanda. `policy` (si se entrega) fija `min_improvement` y la fracción de ventanas;
    sin ella rigen `min_improvement` y la fracción por defecto (2/3)."""
    policy = policy or LegacyPolicy(min_improvement=min_improvement)
    bt = backtest.assign(demand_class=row_classes(backtest, profile))
    bt = bt[~bt["stockout"].astype(bool)].copy()
    all_families = sorted(bt["family"].unique())

    pooled_winner, pooled_reason, pooled_table, pooled_evidence = _decide_full(bt, all_families, policy, POOLED)
    winners = {POOLED: pooled_winner}
    reasons = {POOLED: pooled_reason}
    evidence = {POOLED: pooled_evidence}
    used_pooled = []
    tables = []
    for cls in ("smooth", "erratic", "intermittent", "lumpy"):
        sub = bt[bt["demand_class"] == cls]
        if sub.empty:
            continue
        if sub["unique_id"].nunique() < MIN_CLASS_SERIES or sub["actual"].sum() < MIN_CLASS_ACTUAL:
            winners[cls] = pooled_winner
            reasons[cls] = "Pocas series en la clase: se usa la decisión agregada. " + pooled_reason
            evidence[cls] = {"scope": cls, "used_pooled": True, "series": int(sub["unique_id"].nunique()),
                             "actual_total": float(sub["actual"].sum()), "min_series": MIN_CLASS_SERIES,
                             "min_actual": MIN_CLASS_ACTUAL, "pooled": pooled_evidence}
            used_pooled.append(cls)
            continue
        cands = [f for f in CANDIDATES_BY_CLASS[cls] if f in all_families]
        winner, reason, table, class_evidence = _decide_full(sub, cands, policy, cls)
        winners[cls] = winner
        reasons[cls] = reason
        evidence[cls] = class_evidence
        tables.append(table.assign(demand_class=cls))

    # El respaldo simple se ordena con la métrica de la política (v2: total diario; un promedio plano reparte mal la semana).
    fb_metric = "wape_total" if policy.by_total and "wape_total" in pooled_table else "wape"
    fb = pooled_table[pooled_table["family"].isin(SIMPLE_FALLBACKS)].sort_values(fb_metric)
    fallback = str(fb.iloc[0]["family"]) if not fb.empty else BASELINE_FAMILY
    class_table = pd.concat(tables, ignore_index=True) if tables else pd.DataFrame()
    return Selection(winners, fallback, reasons, class_table, pooled_table, used_pooled, evidence, policy.version)


def selected_rows(backtest: pd.DataFrame, profile: pd.DataFrame, selection: Selection) -> pd.DataFrame:
    """Filas del backtest correspondientes al modelo elegido para cada serie (según la clase de
    la serie en el corte de cada ventana)."""
    if backtest.empty:
        return backtest.copy()
    classes = row_classes(backtest, profile)
    chosen = classes.map(selection.family_for)  # clase desconocida → decisión agregada
    return backtest[backtest["family"] == chosen].assign(demand_class=classes).copy()
